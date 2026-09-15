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
use APP\notification\NotificationManager;
use APP\plugins\generic\ojsbrWebhook\jobs\SendWebhook;
use APP\publication\Publication;
use APP\submission\Submission;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class OjsbrWebhookPlugin extends GenericPlugin
{
    public const EVENT_SUBMISSION_CREATED = 'submission.created';
    public const EVENT_PUBLICATION_CREATED = 'publication.created';
    public const EVENTS = [self::EVENT_SUBMISSION_CREATED, self::EVENT_PUBLICATION_CREATED];

    public const SETTING_ENDPOINTS = 'webhookEndpoints';

    /** @var array<string, bool> Events already queued in this request. */
    private array $queuedEvents = [];

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        // Submissions and publications are also created by imports, the API and scheduled
        // tasks, where there is no journal in the request: the hooks are always added and
        // each callback checks whether the plugin is on in the journal of the entity.
        if ($success && !Application::isUnderMaintenance()) {
            Hook::add('Submission::add', [$this, 'handleSubmissionAdd']);
            Hook::add('Publication::publish', [$this, 'handlePublicationPublish']);
        }

        return $success;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.ojsbrWebhook.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.ojsbrWebhook.description');
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings')
        ));

        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        $context = $request->getContext();
        $contextId = $context ? (int) $context->getId() : Application::CONTEXT_SITE;

        switch ($request->getUserVar('verb')) {
            case 'settings':
                $form = new OjsbrWebhookSettingsForm($this, $contextId);
                if (!$request->getUserVar('save')) {
                    $form->initData();
                    return new JSONMessage(true, $form->fetch($request));
                }

                $form->readInputData();
                if (!$form->validate()) {
                    return new JSONMessage(true, $form->fetch($request));
                }

                $form->execute();
                (new NotificationManager())->createTrivialNotification($request->getUser()->getId());
                return new JSONMessage(true);

            case 'test':
                // Sends data out, so it must come from the settings form: a POST with its token.
                if (!$request->isPost() || !$request->checkCSRF()) {
                    return new JSONMessage(false, __('form.csrfInvalid'));
                }
                return $this->testEndpoint($request, $contextId);
        }

        return parent::manage($args, $request);
    }

    /**
     * Sends a sample payload to one endpoint of the settings form and reports the answer.
     */
    protected function testEndpoint($request, int $contextId): JSONMessage
    {
        $url = trim((string) $request->getUserVar('url'));
        $event = (string) $request->getUserVar('event');
        if ($url === '') {
            return new JSONMessage(false, __('plugins.generic.ojsbrWebhook.settings.testMissingUrl'));
        }
        if (!WebhookSender::isAllowedUrl($url)) {
            return new JSONMessage(false, __('plugins.generic.ojsbrWebhook.settings.invalidUrl'));
        }
        if (!in_array($event, self::EVENTS, true)) {
            $event = self::EVENT_SUBMISSION_CREATED;
        }

        $body = self::encode([
            'event' => $event,
            'occurredAt' => gmdate('c'),
            'contextId' => $contextId ?: null,
            'baseUrl' => $request->getBaseUrl(),
            'test' => true,
            'object' => [
                'id' => 123,
                'class' => 'OJSBR\\Webhook\\Test',
                'submissionId' => $event === self::EVENT_PUBLICATION_CREATED ? 123 : null,
                'contextId' => $contextId ?: null,
                'data' => ['message' => __('plugins.generic.ojsbrWebhook.settings.testPayloadMessage')],
            ],
        ]);
        if ($body === null) {
            return new JSONMessage(false, __('plugins.generic.ojsbrWebhook.settings.testEncodeFailed'));
        }

        $result = WebhookSender::send($url, trim((string) $request->getUserVar('secret')), $event, $body, $this->userAgent());
        if ($result['ok']) {
            return new JSONMessage(true, __('plugins.generic.ojsbrWebhook.settings.testSentStatus', ['status' => $result['statusCode']]));
        }

        return new JSONMessage(false, __('plugins.generic.ojsbrWebhook.settings.testFailedStatus', ['status' => $result['statusCode'], 'error' => $result['error']]));
    }

    /**
     * Hook callback: Submission::add
     *
     * @param array $args [Submission]
     */
    public function handleSubmissionAdd($hookName, $args)
    {
        $submission = $args[0] ?? null;
        if ($submission instanceof Submission) {
            $this->queueWebhooks(self::EVENT_SUBMISSION_CREATED, $submission, (int) $submission->getData('contextId'));
        }

        return Hook::CONTINUE;
    }

    /**
     * Hook callback: Publication::publish
     *
     * @param array $args [Publication $newPublication, Publication $publication, Submission $submission]
     */
    public function handlePublicationPublish($hookName, $args)
    {
        $publication = $args[0] ?? null;
        $submission = $args[2] ?? null;
        // Scheduled in a future issue: not public yet, so nothing to announce.
        if (!$publication instanceof Publication || (int) $publication->getData('status') !== Submission::STATUS_PUBLISHED) {
            return Hook::CONTINUE;
        }

        $contextId = $submission instanceof Submission
            ? (int) $submission->getData('contextId')
            : (int) Repo::submission()->get((int) $publication->getData('submissionId'))?->getData('contextId');
        $this->queueWebhooks(self::EVENT_PUBLICATION_CREATED, $publication, $contextId);

        return Hook::CONTINUE;
    }

    /**
     * Queues one delivery per endpoint of the journal that receives the event.
     */
    protected function queueWebhooks(string $event, $object, int $contextId): void
    {
        $key = $event . ':' . get_class($object) . ':' . $object->getId();
        if (!$contextId || isset($this->queuedEvents[$key]) || !$this->getEnabled($contextId)) {
            return;
        }

        $endpoints = array_filter($this->getEndpoints($contextId), fn (array $endpoint) => in_array($event, $endpoint['events'], true));
        if (!$endpoints) {
            return;
        }

        $body = self::encode($this->payload($event, $object, $contextId));
        if ($body === null) {
            error_log('[ojsbrWebhook] Failed to encode the payload of ' . $key . '.');
            return;
        }

        foreach ($endpoints as $endpoint) {
            dispatch(new SendWebhook($endpoint['url'], $endpoint['secret'], $event, $body, $this->userAgent()));
        }
        $this->queuedEvents[$key] = true;
    }

    /**
     * The endpoints of a journal, or the site-wide ones when the journal has none.
     *
     * @return array<int, array{url: string, secret: string, events: string[]}>
     */
    public function getEndpoints(int $contextId): array
    {
        $endpoints = self::normalizeEndpoints((string) ($this->getSetting($contextId, self::SETTING_ENDPOINTS) ?: $this->getSetting(Application::CONTEXT_SITE, self::SETTING_ENDPOINTS)));
        if ($endpoints) {
            return $endpoints;
        }

        // Settings of 1.0.0.0: one URL and secret, for both events.
        $url = trim((string) ($this->getSetting($contextId, 'webhookUrl') ?: $this->getSetting(Application::CONTEXT_SITE, 'webhookUrl')));
        if ($url === '' || !WebhookSender::isAllowedUrl($url)) {
            return [];
        }

        return [[
            'url' => $url,
            'secret' => (string) ($this->getSetting($contextId, 'webhookSecret') ?: $this->getSetting(Application::CONTEXT_SITE, 'webhookSecret')),
            'events' => self::EVENTS,
        ]];
    }

    /**
     * The stored endpoints, without entries that have no http(s) URL or no known event.
     *
     * @return array<int, array{url: string, secret: string, events: string[]}>
     */
    public static function normalizeEndpoints(string $json): array
    {
        $decoded = json_decode($json, true);
        $endpoints = [];
        foreach (is_array($decoded) ? $decoded : [] as $endpoint) {
            if (!is_array($endpoint) || !is_array($endpoint['events'] ?? null) || !WebhookSender::isAllowedUrl((string) ($endpoint['url'] ?? ''))) {
                continue;
            }
            $events = array_values(array_intersect(self::EVENTS, $endpoint['events']));
            if ($events) {
                $endpoints[] = [
                    'url' => trim((string) $endpoint['url']),
                    'secret' => (string) ($endpoint['secret'] ?? ''),
                    'events' => $events,
                ];
            }
        }

        return $endpoints;
    }

    /**
     * The JSON document sent for an event.
     */
    protected function payload(string $event, $object, int $contextId): array
    {
        $request = Application::get()->getRequest();

        return [
            'event' => $event,
            'occurredAt' => gmdate('c'),
            'contextId' => $contextId,
            'baseUrl' => $request->getBaseUrl(),
            'object' => [
                'id' => $object->getId(),
                'class' => get_class($object),
                'submissionId' => $object instanceof Publication ? (int) $object->getData('submissionId') : null,
                'contextId' => $contextId,
                'data' => $object->getAllData(),
            ],
        ];
    }

    protected function userAgent(): string
    {
        $version = $this->getCurrentVersion();

        return 'OJSBR-Webhook/' . ($version ? $version->getVersionString() : '1');
    }

    protected static function encode(array $payload): ?string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $body === false ? null : $body;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\ojsbrWebhook\OjsbrWebhookPlugin', '\OjsbrWebhookPlugin');
}
