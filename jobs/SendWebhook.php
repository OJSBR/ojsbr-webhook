<?php

/**
 * @file plugins/generic/ojsbrWebhook/jobs/SendWebhook.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SendWebhook
 *
 * @brief Delivers a webhook from the job queue, so the request that created the
 *        submission or published the article does not wait for the endpoint.
 */

namespace APP\plugins\generic\ojsbrWebhook\jobs;

use APP\plugins\generic\ojsbrWebhook\WebhookSender;
use PKP\job\exceptions\JobException;
use PKP\jobs\BaseJob;

class SendWebhook extends BaseJob
{
    protected string $url;
    protected string $secret;
    protected string $event;
    protected string $body;
    protected string $userAgent;

    public function __construct(string $url, string $secret, string $event, string $body, string $userAgent)
    {
        parent::__construct();
        // An endpoint that is down gets two more tries, a minute apart.
        $this->backoff = 60;
        $this->url = $url;
        $this->secret = $secret;
        $this->event = $event;
        $this->body = $body;
        $this->userAgent = $userAgent;
    }

    public function handle(): void
    {
        $result = WebhookSender::send($this->url, $this->secret, $this->event, $this->body, $this->userAgent);
        if (!$result['ok']) {
            // Failing the attempt makes the queue try again, and keeps the last failure.
            throw new JobException(sprintf('%s webhook to %s: status %d %s', $this->event, WebhookSender::logTarget($this->url), $result['statusCode'], $result['error']));
        }
    }
}
