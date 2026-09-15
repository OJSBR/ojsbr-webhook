<?php

/**
 * @file plugins/generic/ojsbrWebhook/OjsbrWebhookPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OjsbrWebhookPlugin
 *
 * @brief Sends webhooks when submissions are created and publications are published.
 */

namespace APP\plugins\generic\ojsbrWebhook;

use APP\core\Application;
use APP\facades\Repo;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class OjsbrWebhookPlugin extends GenericPlugin
{
    public const EVENT_SUBMISSION_CREATED = 'submission.created';
    public const EVENT_PUBLICATION_CREATED = 'publication.created';

    /** The only schemes an endpoint may use: anything else would let curl read files or reach other services. */
    public const ALLOWED_SCHEMES = ['http', 'https'];

    /** @var array<string, bool> */
    private array $sentEvents = [];

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && !Application::isUnderMaintenance() && $this->getEnabled($mainContextId)) {
            Hook::add('Publication::publish', [$this, 'handlePublicationPublish']);
            Hook::add('Submission::add', [$this, 'handleSubmissionAdd']);
        }

        return $success;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.ojsbrWebhook.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.ojsbrWebhook.description');
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);

        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        $settingsUrl = $router->url(
            $request,
            null,
            null,
            'manage',
            null,
            array_merge($actionArgs, [
                'verb' => 'settings',
                'plugin' => $this->getName(),
                'category' => 'generic',
            ])
        );

        $actions[] = new LinkAction(
            'settings',
            new AjaxModal($settingsUrl, __('manager.plugins.settings'), 'modal_manage'),
            __('manager.plugins.settings')
        );

        return $actions;
    }

    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }

        $context = $request->getContext();
        $contextId = $context ? (int) $context->getId() : 0;

        // Saving and testing change the endpoints or send data out, so they must
        // come from the settings form itself: the plugin grid does not check the
        // CSRF token of manage() requests, and a forged request could otherwise
        // point the journal's submissions at a third party.
        if ($request->getUserVar('testEndpoint') || $request->getUserVar('save')) {
            if (!$request->isPost() || !$request->checkCSRF()) {
                return new JSONMessage(false, __('form.csrfInvalid'));
            }
        }

        if ($request->getUserVar('testEndpoint')) {
            return $this->testEndpoint($request, $contextId);
        }

        if ($request->getUserVar('save')) {
            foreach ($this->endpointsFromRequest($request) as $endpoint) {
                if (!self::isAllowedUrl($endpoint['url'])) {
                    return new JSONMessage(false, __('plugins.generic.ojsbrWebhook.settings.invalidUrl'));
                }
            }
            $this->updateSetting(
                $contextId,
                'webhookEndpoints',
                json_encode($this->endpointsFromRequest($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'string'
            );

            return new JSONMessage(true, __('plugins.generic.ojsbrWebhook.settings.saved'));
        }

        $templateMgr = TemplateManager::getManager($request);
        $router = $request->getRouter();
        $templateMgr->assign([
            'pluginName' => $this->getName(),
            'category' => 'generic',
            'settingsFormAction' => $router->url($request, null, null, 'manage', null, [
                'plugin' => $this->getName(),
                'category' => 'generic',
                'verb' => 'settings',
                'save' => 1,
            ]),
            'testEndpointAction' => $router->url($request, null, null, 'manage', null, [
                'plugin' => $this->getName(),
                'category' => 'generic',
                'verb' => 'settings',
                'testEndpoint' => 1,
            ]),
            'endpoints' => $this->endpointsForContext($contextId, true),
            'eventSubmissionCreated' => self::EVENT_SUBMISSION_CREATED,
            'eventPublicationCreated' => self::EVENT_PUBLICATION_CREATED,
        ]);

        return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('settings.tpl')));
    }

    protected function testEndpoint($request, $contextId)
    {
        $index = $request->getUserVar('testEndpointIndex');
        if ($index !== null) {
            $urls = (array) $request->getUserVar('endpointUrl');
            $secrets = (array) $request->getUserVar('endpointSecret');
            $submissionEvents = (array) $request->getUserVar('endpointSubmission');
            $publicationEvents = (array) $request->getUserVar('endpointPublication');
            $url = trim((string) ($urls[$index] ?? ''));
            $secret = trim((string) ($secrets[$index] ?? ''));
            $event = isset($submissionEvents[$index]) ? self::EVENT_SUBMISSION_CREATED : self::EVENT_PUBLICATION_CREATED;
            if (!isset($submissionEvents[$index]) && !isset($publicationEvents[$index])) {
                $event = self::EVENT_SUBMISSION_CREATED;
            }
        } else {
            $url = trim((string) $request->getUserVar('url'));
            $secret = trim((string) $request->getUserVar('secret'));
            $event = (string) ($request->getUserVar('event') ?: self::EVENT_SUBMISSION_CREATED);
        }

        if ($url === '') {
            return new JSONMessage(false, __('plugins.generic.ojsbrWebhook.settings.testMissingUrl'));
        }
        if (!self::isAllowedUrl($url)) {
            return new JSONMessage(false, __('plugins.generic.ojsbrWebhook.settings.invalidUrl'));
        }

        if (!in_array($event, [self::EVENT_SUBMISSION_CREATED, self::EVENT_PUBLICATION_CREATED], true)) {
            $event = self::EVENT_SUBMISSION_CREATED;
        }

        $payload = [
            'event' => $event,
            'occurredAt' => gmdate('c'),
            'contextId' => $contextId ?: null,
            'baseUrl' => Application::get()->getRequest()->getBaseUrl(),
            'test' => true,
            'object' => [
                'id' => 123,
                'class' => 'OJSBR\\Webhook\\Test',
                'submissionId' => $event === self::EVENT_PUBLICATION_CREATED ? 123 : null,
                'contextId' => $contextId ?: null,
                'data' => [
                    'message' => __('plugins.generic.ojsbrWebhook.settings.testPayloadMessage'),
                ],
            ],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return new JSONMessage(false, __('plugins.generic.ojsbrWebhook.settings.testEncodeFailed'));
        }

        $result = $this->sendWebhookToEndpoint($event, [
            'url' => $url,
            'secret' => $secret,
            'events' => [$event],
        ], $body);

        if ($result['ok']) {
            return new JSONMessage(true, __('plugins.generic.ojsbrWebhook.settings.testSentStatus', ['status' => $result['statusCode']]));
        }

        return new JSONMessage(false, __('plugins.generic.ojsbrWebhook.settings.testFailedStatus', ['status' => $result['statusCode'], 'error' => $result['error']]));
    }

    public function handleSubmissionAdd($hookName, $args)
    {
        $submission = $this->firstMatchingObject($args, 'isSubmission');
        if ($submission) {
            $this->sendWebhook(self::EVENT_SUBMISSION_CREATED, $submission);
        }

        return false;
    }

    public function handlePublicationPublish($hookName, $args)
    {
        $publication = $this->firstMatchingObject($args, 'isPublication');
        if (!$publication) {
            return false;
        }

        // Scheduled in a future issue: not public yet, so nothing to announce.
        if (!$this->isPublishedPublication($publication)) {
            return false;
        }

        $this->sendWebhook(self::EVENT_PUBLICATION_CREATED, $publication);

        return false;
    }

    protected function sendWebhook($event, $object)
    {
        if ($this->wasSent($event, $object)) {
            return;
        }

        $contextId = $this->resolveContextId($object);
        $endpoints = $this->endpointsForContext($contextId);
        $endpoints = array_values(array_filter($endpoints, fn ($endpoint) => in_array($event, $endpoint['events'], true)));
        if (empty($endpoints)) {
            return;
        }

        $payload = $this->payload($event, $object, $contextId);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            error_log('[ojsbrWebhook] Failed to encode webhook payload.');
            return;
        }

        foreach ($endpoints as $endpoint) {
            $this->sendWebhookToEndpoint($event, $endpoint, $body);
        }

        $this->markSent($event, $object);
    }

    protected function wasSent($event, $object)
    {
        return isset($this->sentEvents[$this->sentEventKey($event, $object)]);
    }

    protected function markSent($event, $object)
    {
        $this->sentEvents[$this->sentEventKey($event, $object)] = true;
    }

    protected function sentEventKey($event, $object)
    {
        $id = method_exists($object, 'getId') ? (string) $object->getId() : spl_object_hash($object);

        return $event . ':' . get_class($object) . ':' . $id;
    }

    /**
     * Whether a URL may receive webhooks: an absolute http(s) URL with a host.
     */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url(trim($url));

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), self::ALLOWED_SCHEMES, true)
            && !empty($parts['host']);
    }

    /**
     * The host of an endpoint, for the log: a full URL can carry a token.
     */
    public static function logTarget(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: 'invalid URL');
    }

    protected function sendWebhookToEndpoint($event, $endpoint, $body)
    {
        if (!self::isAllowedUrl($endpoint['url'])) {
            error_log(sprintf('[ojsbrWebhook] Refused to send %s webhook to a non-http(s) endpoint.', $event));
            return [
                'ok' => false,
                'statusCode' => 0,
                'error' => 'Only http and https endpoints are accepted.',
            ];
        }

        $headers = [
            'Content-Type: application/json',
            'User-Agent: OJSBR-Webhook/' . ($this->getCurrentVersion() ? $this->getCurrentVersion()->getVersionString() : '1'),
            'X-OJSBR-Webhook-Event: ' . $event,
        ];

        if ($endpoint['secret'] !== '') {
            $headers[] = 'X-OJSBR-Webhook-Signature: sha256=' . hash_hmac('sha256', $body, $endpoint['secret']);
        }

        $ch = curl_init($endpoint['url']);
        if ($ch === false) {
            error_log('[ojsbrWebhook] Failed to initialize curl.');
            return [
                'ok' => false,
                'statusCode' => 0,
                'error' => 'Failed to initialize curl.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            // Redirects are not followed, and no other protocol is ever used.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($error !== '' || $statusCode < 200 || $statusCode >= 300) {
            error_log(sprintf('[ojsbrWebhook] Failed to send %s webhook to %s. Status: %d Error: %s', $event, self::logTarget($endpoint['url']), $statusCode, $error));
        }

        return [
            'ok' => $error === '' && $statusCode >= 200 && $statusCode < 300,
            'statusCode' => $statusCode,
            'error' => $error,
        ];
    }

    protected function endpointsFromRequest($request)
    {
        $urls = (array) $request->getUserVar('endpointUrl');
        $secrets = (array) $request->getUserVar('endpointSecret');
        $submissionEvents = (array) $request->getUserVar('endpointSubmission');
        $publicationEvents = (array) $request->getUserVar('endpointPublication');
        $endpoints = [];

        foreach ($urls as $index => $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }

            $events = [];
            if (isset($submissionEvents[$index])) {
                $events[] = self::EVENT_SUBMISSION_CREATED;
            }
            if (isset($publicationEvents[$index])) {
                $events[] = self::EVENT_PUBLICATION_CREATED;
            }

            if (empty($events)) {
                continue;
            }

            $endpoints[] = [
                'url' => $url,
                'secret' => trim((string) ($secrets[$index] ?? '')),
                'events' => $events,
            ];
        }

        return $endpoints;
    }

    protected function endpointsForContext($contextId, $includeEmptyRow = false)
    {
        $json = (string) ($this->getSetting($contextId, 'webhookEndpoints') ?: $this->getSetting(0, 'webhookEndpoints'));
        $endpoints = $this->normalizeEndpoints($json);

        if (empty($endpoints)) {
            $legacyUrl = trim((string) ($this->getSetting($contextId, 'webhookUrl') ?: $this->getSetting(0, 'webhookUrl')));
            if ($legacyUrl !== '') {
                $endpoints[] = [
                    'url' => $legacyUrl,
                    'secret' => (string) ($this->getSetting($contextId, 'webhookSecret') ?: $this->getSetting(0, 'webhookSecret')),
                    'events' => [self::EVENT_SUBMISSION_CREATED, self::EVENT_PUBLICATION_CREATED],
                ];
            }
        }

        if ($includeEmptyRow) {
            $endpoints[] = [
                'url' => '',
                'secret' => '',
                'events' => [self::EVENT_SUBMISSION_CREATED, self::EVENT_PUBLICATION_CREATED],
            ];
        }

        return $endpoints;
    }

    protected function normalizeEndpoints($json)
    {
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $endpoints = [];
        foreach ($decoded as $endpoint) {
            if (!is_array($endpoint) || empty($endpoint['url']) || empty($endpoint['events']) || !is_array($endpoint['events'])) {
                continue;
            }

            $events = array_values(array_intersect($endpoint['events'], [
                self::EVENT_SUBMISSION_CREATED,
                self::EVENT_PUBLICATION_CREATED,
            ]));
            if (empty($events)) {
                continue;
            }

            $endpoints[] = [
                'url' => (string) $endpoint['url'],
                'secret' => (string) ($endpoint['secret'] ?? ''),
                'events' => $events,
            ];
        }

        return $endpoints;
    }

    protected function payload($event, $object, $contextId)
    {
        $request = Application::get()->getRequest();

        return [
            'event' => $event,
            'occurredAt' => gmdate('c'),
            'contextId' => $contextId ?: null,
            'baseUrl' => $request ? $request->getBaseUrl() : null,
            'object' => $this->objectPayload($object),
        ];
    }

    protected function objectPayload($object)
    {
        $data = method_exists($object, 'getAllData') ? $object->getAllData() : [];

        return [
            'id' => method_exists($object, 'getId') ? $object->getId() : null,
            'class' => get_class($object),
            'submissionId' => method_exists($object, 'getData') ? ($object->getData('submissionId') ?: $object->getData('submission_id')) : null,
            'contextId' => $this->resolveContextId($object) ?: null,
            'data' => $data,
        ];
    }

    protected function resolveContextId($object)
    {
        if (!is_object($object) || !method_exists($object, 'getData')) {
            return 0;
        }

        $contextId = (int) ($object->getData('contextId') ?: $object->getData('journalId') ?: 0);
        if ($contextId > 0) {
            return $contextId;
        }

        if ($this->isPublication($object)) {
            $submissionId = (int) ($object->getData('submissionId') ?: $object->getData('submission_id') ?: 0);
            if ($submissionId > 0) {
                $submission = Repo::submission()->get($submissionId);
                if ($submission) {
                    return (int) ($submission->getData('contextId') ?: 0);
                }
            }
        }

        $request = Application::get()->getRequest();
        if ($request && $request->getContext()) {
            return (int) $request->getContext()->getId();
        }

        return 0;
    }

    protected function isSubmission($object)
    {
        return is_object($object) && (
            is_a($object, '\APP\submission\Submission')
            || is_a($object, '\PKP\submission\PKPSubmission')
            || str_ends_with(get_class($object), '\Submission')
        );
    }

    protected function isPublication($object)
    {
        return is_object($object) && (
            is_a($object, '\APP\publication\Publication')
            || is_a($object, '\PKP\publication\PKPPublication')
            || str_ends_with(get_class($object), '\Publication')
        );
    }

    protected function isPublishedPublication($publication)
    {
        return $this->isPublication($publication)
            && method_exists($publication, 'getData')
            && (int) $publication->getData('status') === Submission::STATUS_PUBLISHED;
    }

    protected function firstMatchingObject($args, $method)
    {
        foreach ((array) $args as $arg) {
            if (is_object($arg) && $this->{$method}($arg)) {
                return $arg;
            }

            if (is_array($arg)) {
                $found = $this->firstMatchingObject($arg, $method);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }
}
