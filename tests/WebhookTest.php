<?php

/**
 * @file plugins/generic/ojsbrWebhook/tests/WebhookTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class WebhookTest
 *
 * @brief Where webhooks may go, what reaches the log, and the protection of the
 *        settings requests.
 */

namespace APP\plugins\generic\ojsbrWebhook\tests;

use APP\plugins\generic\ojsbrWebhook\OjsbrWebhookPlugin;
use ReflectionMethod;

class WebhookTest extends TestCase
{
    protected function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/OjsbrWebhookPlugin.php');
    }

    public function testOnlyAbsoluteHttpUrlsReceiveWebhooks(): void
    {
        $this->assertTrue(OjsbrWebhookPlugin::isAllowedUrl('https://hooks.example.org/ojs?token=abc'));
        $this->assertTrue(OjsbrWebhookPlugin::isAllowedUrl(' http://10.0.0.5:3333/webhook/ojs '));
        $this->assertFalse(OjsbrWebhookPlugin::isAllowedUrl('file:///etc/passwd'));
        $this->assertFalse(OjsbrWebhookPlugin::isAllowedUrl('gopher://example.org/'));
        $this->assertFalse(OjsbrWebhookPlugin::isAllowedUrl('//example.org/hook'));
        $this->assertFalse(OjsbrWebhookPlugin::isAllowedUrl('example.org/hook'));
        $this->assertFalse(OjsbrWebhookPlugin::isAllowedUrl(''));
    }

    public function testTheLogNamesTheHostOnly(): void
    {
        // An endpoint URL can carry a token in its path or query string.
        $this->assertSame('hooks.example.org', OjsbrWebhookPlugin::logTarget('https://hooks.example.org/ojs/SECRET?token=abc'));
        foreach (explode("\n", $this->source()) as $number => $line) {
            if (str_contains($line, 'error_log(')) {
                $this->assertStringNotContainsString("\$endpoint['url'])", str_replace('logTarget($endpoint[\'url\'])', '', $line), 'Line ' . ($number + 1) . ' logs an endpoint URL.');
                $this->assertStringNotContainsString('$url', $line, 'Line ' . ($number + 1) . ' logs an endpoint URL.');
            }
        }
    }

    public function testCurlIsLimitedToHttpWithoutRedirects(): void
    {
        $this->assertStringContainsString('CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS', $this->source());
        $this->assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $this->source());
    }

    public function testSavingAndTestingRequireAPostWithTheCsrfToken(): void
    {
        $source = $this->source();
        $gate = strpos($source, "if (!\$request->isPost() || !\$request->checkCSRF())");
        $this->assertTrue($gate !== false, 'manage() does not check the CSRF token.');
        $this->assertTrue($gate < strpos($source, 'return $this->testEndpoint($request, $contextId);'));
        $this->assertTrue($gate < strpos($source, "'webhookEndpoints',"));
    }

    public function testEndpointsWithoutUrlOrKnownEventsAreDropped(): void
    {
        $plugin = new OjsbrWebhookPlugin();
        $normalize = new ReflectionMethod($plugin, 'normalizeEndpoints');
        $normalize->setAccessible(true);
        $json = json_encode([
            ['url' => 'https://a.example.org', 'secret' => 's', 'events' => ['submission.created', 'unknown']],
            ['url' => '', 'events' => ['submission.created']],
            ['url' => 'https://b.example.org', 'events' => ['unknown']],
            'not an endpoint',
        ]);

        $this->assertSame([
            ['url' => 'https://a.example.org', 'secret' => 's', 'events' => ['submission.created']],
        ], $normalize->invoke($plugin, $json));
        $this->assertSame([], $normalize->invoke($plugin, 'not json'));
    }

    public function testOnlyHooksThatExistInOjs34AreUsed(): void
    {
        preg_match_all("/Hook::add\\('([^']+)'/", $this->source(), $m);
        $this->assertSame(['Publication::publish', 'Submission::add'], $m[1]);
    }
}
