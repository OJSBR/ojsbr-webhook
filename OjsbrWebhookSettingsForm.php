<?php

/**
 * @file plugins/generic/ojsbrWebhook/OjsbrWebhookSettingsForm.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OjsbrWebhookSettingsForm
 *
 * @brief The webhook endpoints of a journal: URL, secret and events of each one.
 */

namespace APP\plugins\generic\ojsbrWebhook;

use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class OjsbrWebhookSettingsForm extends Form
{
    protected OjsbrWebhookPlugin $plugin;
    protected int $contextId;

    public function __construct(OjsbrWebhookPlugin $plugin, int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->plugin = $plugin;
        $this->contextId = $contextId;

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->addCheck(new FormValidatorCustom(
            $this,
            'endpointUrl',
            FormValidatorCustom::FORM_VALIDATOR_OPTIONAL_VALUE,
            'plugins.generic.ojsbrWebhook.settings.invalidUrl',
            [self::class, 'allUrlsAllowed']
        ));
    }

    /**
     * Whether every URL filled in is an absolute http(s) URL.
     */
    public static function allUrlsAllowed($urls): bool
    {
        foreach ((array) $urls as $url) {
            if (trim((string) $url) !== '' && !WebhookSender::isAllowedUrl((string) $url)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData()
    {
        $this->setData('endpoints', $this->plugin->getEndpoints($this->contextId));
        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars(['endpointUrl', 'endpointSecret', 'endpointSubmission', 'endpointPublication']);
        $this->setData('endpoints', $this->endpointsFromInput());
    }

    /**
     * The endpoints of the submitted rows; a row without URL or without events is dropped.
     *
     * @return array<int, array{url: string, secret: string, events: string[]}>
     */
    public function endpointsFromInput(): array
    {
        $secrets = (array) $this->getData('endpointSecret');
        $submission = (array) $this->getData('endpointSubmission');
        $publication = (array) $this->getData('endpointPublication');
        $endpoints = [];
        foreach ((array) $this->getData('endpointUrl') as $row => $url) {
            $events = array_keys(array_filter([
                OjsbrWebhookPlugin::EVENT_SUBMISSION_CREATED => isset($submission[$row]),
                OjsbrWebhookPlugin::EVENT_PUBLICATION_CREATED => isset($publication[$row]),
            ]));
            if (trim((string) $url) !== '' && $events) {
                $endpoints[] = ['url' => trim((string) $url), 'secret' => trim((string) ($secrets[$row] ?? '')), 'events' => $events];
            }
        }

        return $endpoints;
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            // A blank row to fill in, after the saved endpoints.
            'endpointRows' => array_merge((array) $this->getData('endpoints'), [['url' => '', 'secret' => '', 'events' => OjsbrWebhookPlugin::EVENTS]]),
            'eventSubmissionCreated' => OjsbrWebhookPlugin::EVENT_SUBMISSION_CREATED,
            'eventPublicationCreated' => OjsbrWebhookPlugin::EVENT_PUBLICATION_CREATED,
            'settingsScriptUrl' => $request->getBaseUrl() . '/' . $this->plugin->getPluginPath() . '/js/settings.js',
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting(
            $this->contextId,
            OjsbrWebhookPlugin::SETTING_ENDPOINTS,
            json_encode($this->endpointsFromInput(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'string'
        );

        parent::execute(...$functionArgs);
    }
}
