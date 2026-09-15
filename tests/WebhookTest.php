<?php

/**
 * @file plugins/generic/ojsbrWebhook/tests/WebhookTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class WebhookTest
 *
 * @brief Where webhooks may go and how they are signed, delivered, queued and
 *        configured.
 */

namespace APP\plugins\generic\ojsbrWebhook\tests;

use APP\plugins\generic\ojsbrWebhook\jobs\SendWebhook;
use APP\plugins\generic\ojsbrWebhook\OjsbrWebhookPlugin;
use APP\plugins\generic\ojsbrWebhook\OjsbrWebhookSettingsForm;
use APP\plugins\generic\ojsbrWebhook\WebhookSender;
use APP\publication\Publication;
use APP\submission\Submission;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Bus;
use PKP\core\Registry;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;
use PKP\job\exceptions\JobException;
use PKP\tests\PKPTestCase;

class WebhookTest extends PKPTestCase
{
    /** @var array<int, array> Requests the mocked HTTP client received. */
    protected array $sent = [];

    /**
     * Answers the next requests of the application's HTTP client with the given responses.
     */
    protected function mockHttp(array $responses): void
    {
        $this->sent = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));
        $client = new Client(['handler' => $stack]);
        Registry::set(PKPTestCase::MOCKED_GUZZLE_CLIENT_NAME, $client);
    }

    public function testOnlyAbsoluteHttpUrlsReceiveWebhooks(): void
    {
        $this->assertTrue(WebhookSender::isAllowedUrl('https://hooks.example.org/ojs?token=abc'));
        $this->assertTrue(WebhookSender::isAllowedUrl(' http://10.0.0.5:3333/webhook/ojs '));
        $this->assertFalse(WebhookSender::isAllowedUrl('file:///etc/passwd'));
        $this->assertFalse(WebhookSender::isAllowedUrl('gopher://example.org/'));
        $this->assertFalse(WebhookSender::isAllowedUrl('//example.org/hook'));
        $this->assertFalse(WebhookSender::isAllowedUrl('example.org/hook'));
        $this->assertFalse(WebhookSender::isAllowedUrl(''));
    }

    public function testADeliveryIsSignedAndPostedWithoutFollowingRedirects(): void
    {
        $this->mockHttp([new Response(204)]);
        $body = '{"event":"submission.created"}';

        $result = WebhookSender::send('https://hooks.example.org/ojs', 's3cret', 'submission.created', $body, 'OJSBR-Webhook/1.1.0.0');

        $this->assertSame(['ok' => true, 'statusCode' => 204, 'error' => ''], $result);
        $this->assertCount(1, $this->sent);
        /** @var PsrRequest $request */
        $request = $this->sent[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://hooks.example.org/ojs', (string) $request->getUri());
        $this->assertSame($body, (string) $request->getBody());
        $this->assertSame('sha256=' . hash_hmac('sha256', $body, 's3cret'), $request->getHeaderLine('X-OJSBR-Webhook-Signature'));
        $this->assertSame('submission.created', $request->getHeaderLine('X-OJSBR-Webhook-Event'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertFalse($this->sent[0]['options']['allow_redirects']);
    }

    public function testWithoutASecretNothingIsSigned(): void
    {
        $this->assertArrayNotHasKey('X-OJSBR-Webhook-Signature', WebhookSender::headers('', 'publication.created', '{}', 'UA'));
    }

    public function testNonHttpEndpointsAreNeverRequested(): void
    {
        $this->mockHttp([new Response(200)]);
        $this->assertFalse(WebhookSender::send('file:///etc/passwd', '', 'submission.created', '{}', 'UA')['ok']);
        $this->assertCount(0, $this->sent);
    }

    public function testErrorsNameTheHostOnly(): void
    {
        // An endpoint URL can carry a token in its path or query string.
        $this->assertSame('hooks.example.org', WebhookSender::logTarget('https://hooks.example.org/ojs/SECRET?token=abc'));
        $this->mockHttp([new ConnectException('Could not reach https://hooks.example.org/ojs/SECRET', new PsrRequest('POST', 'https://hooks.example.org/ojs/SECRET'))]);

        $result = WebhookSender::send('https://hooks.example.org/ojs/SECRET', '', 'submission.created', '{}', 'UA');

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('SECRET', $result['error']);
    }

    public function testAFailedDeliveryFailsTheJobSoTheQueueTriesAgain(): void
    {
        $this->mockHttp([new Response(500)]);
        $job = new SendWebhook('https://hooks.example.org/ojs?token=SECRET', '', 'submission.created', '{}', 'UA');

        try {
            $job->handle();
            $this->fail('A 500 answer did not fail the job.');
        } catch (JobException $e) {
            $this->assertStringContainsString('status 500', $e->getMessage());
            $this->assertStringNotContainsString('SECRET', $e->getMessage());
        }
    }

    public function testEndpointsWithoutHttpUrlOrKnownEventsAreDropped(): void
    {
        $json = json_encode([
            ['url' => 'https://a.example.org', 'secret' => 's', 'events' => ['publication.created', 'unknown', 'submission.created']],
            ['url' => '', 'events' => ['submission.created']],
            ['url' => 'ftp://c.example.org', 'events' => ['submission.created']],
            ['url' => 'https://b.example.org', 'events' => ['unknown']],
            'not an endpoint',
        ]);

        $this->assertSame([
            ['url' => 'https://a.example.org', 'secret' => 's', 'events' => ['submission.created', 'publication.created']],
        ], OjsbrWebhookPlugin::normalizeEndpoints($json));
        $this->assertSame([], OjsbrWebhookPlugin::normalizeEndpoints('not json'));
    }

    public function testTheSettingsFormChecksThePostTheTokenAndTheUrls(): void
    {
        $form = new OjsbrWebhookSettingsForm($this->plugin(), 1);
        $checks = array_map('get_class', $form->_checks);
        $this->assertContains(FormValidatorPost::class, $checks);
        $this->assertContains(FormValidatorCSRF::class, $checks);

        $this->assertTrue(OjsbrWebhookSettingsForm::allUrlsAllowed(['https://a.example.org', '', '  ']));
        $this->assertFalse(OjsbrWebhookSettingsForm::allUrlsAllowed(['https://a.example.org', 'file:///etc/passwd']));
    }

    public function testTheSettingsFormKeepsRowsWithAUrlAndAnEvent(): void
    {
        $form = new OjsbrWebhookSettingsForm($this->plugin(), 1);
        $form->setData('endpointUrl', ['0' => ' https://a.example.org ', '1' => 'https://b.example.org', 'new1' => '']);
        $form->setData('endpointSecret', ['0' => ' s ', '1' => '']);
        $form->setData('endpointSubmission', ['0' => '1', 'new1' => '1']);
        $form->setData('endpointPublication', ['0' => '1']);

        $this->assertSame([
            ['url' => 'https://a.example.org', 'secret' => 's', 'events' => ['submission.created', 'publication.created']],
        ], $form->endpointsFromInput());
    }

    public function testEventsAreQueuedOnlyWhereThePluginIsOn(): void
    {
        Bus::fake();
        $plugin = $this->plugin([7 => true, 8 => false]);
        $submission = new Submission();
        $submission->setId(10);
        $submission->setData('contextId', 7);

        $plugin->handleSubmissionAdd('Submission::add', [$submission]);
        // Once per request, whatever calls the hook again.
        $plugin->handleSubmissionAdd('Submission::add', [$submission]);
        Bus::assertDispatched(SendWebhook::class, 2);

        $elsewhere = new Submission();
        $elsewhere->setId(11);
        $elsewhere->setData('contextId', 8);
        $plugin->handleSubmissionAdd('Submission::add', [$elsewhere]);
        Bus::assertDispatched(SendWebhook::class, 2);
    }

    public function testOnlyPublicationsThatBecomePublicAreAnnounced(): void
    {
        Bus::fake();
        $plugin = $this->plugin([7 => true]);
        $submission = new Submission();
        $submission->setData('contextId', 7);
        $scheduled = new Publication();
        $scheduled->setId(20);
        $scheduled->setData('status', Submission::STATUS_SCHEDULED);

        $plugin->handlePublicationPublish('Publication::publish', [$scheduled, $scheduled, $submission]);
        Bus::assertNotDispatched(SendWebhook::class);

        $published = new Publication();
        $published->setId(21);
        $published->setData('status', Submission::STATUS_PUBLISHED);
        $plugin->handlePublicationPublish('Publication::publish', [$published, $scheduled, $submission]);
        // Only the endpoint that receives publication.created.
        Bus::assertDispatched(SendWebhook::class, 1);
    }

    public function testOnlyHooksThatExistInOjs34AreUsed(): void
    {
        preg_match_all("/Hook::add\\('([^']+)'/", (string) file_get_contents(dirname(__DIR__) . '/OjsbrWebhookPlugin.php'), $m);
        $this->assertSame(['Submission::add', 'Publication::publish'], $m[1]);
    }

    /**
     * A plugin on in the given journals, whose endpoints are one per event plus one for both.
     */
    protected function plugin(array $enabled = []): OjsbrWebhookPlugin
    {
        return new class ($enabled) extends OjsbrWebhookPlugin {
            public function __construct(private array $enabledIn)
            {
                parent::__construct();
            }

            public function getEnabled($contextId = null)
            {
                return $this->enabledIn[$contextId] ?? false;
            }

            public function getEndpoints(int $contextId): array
            {
                return [
                    ['url' => 'https://a.example.org', 'secret' => '', 'events' => [OjsbrWebhookPlugin::EVENT_SUBMISSION_CREATED]],
                    ['url' => 'https://b.example.org', 'secret' => 's', 'events' => OjsbrWebhookPlugin::EVENTS],
                ];
            }

            public function getTemplateResource($template = null, $inCore = false)
            {
                return (string) $template;
            }

            public function getCurrentVersion()
            {
                return null;
            }
        };
    }
}
