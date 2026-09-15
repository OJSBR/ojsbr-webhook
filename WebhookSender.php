<?php

/**
 * @file plugins/generic/ojsbrWebhook/WebhookSender.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class WebhookSender
 *
 * @brief Delivers one webhook through the HTTP client of the application.
 */

namespace APP\plugins\generic\ojsbrWebhook;

use APP\core\Application;
use GuzzleHttp\Exception\GuzzleException;

class WebhookSender
{
    /** The only schemes an endpoint may use. */
    public const ALLOWED_SCHEMES = ['http', 'https'];

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
     * The host of an endpoint, for the log and for errors: a full URL can carry a token.
     */
    public static function logTarget(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: 'invalid URL');
    }

    /**
     * The headers of a delivery; with a secret, the body is signed with HMAC-SHA256.
     *
     * @return array<string, string>
     */
    public static function headers(string $secret, string $event, string $body, string $userAgent): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => $userAgent,
            'X-OJSBR-Webhook-Event' => $event,
        ];
        if ($secret !== '') {
            $headers['X-OJSBR-Webhook-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        return $headers;
    }

    /**
     * Posts the body to the endpoint. The HTTP client of the application carries the proxy of
     * the configuration; redirects are not followed.
     *
     * @return array{ok: bool, statusCode: int, error: string}
     */
    public static function send(string $url, string $secret, string $event, string $body, string $userAgent): array
    {
        if (!self::isAllowedUrl($url)) {
            return ['ok' => false, 'statusCode' => 0, 'error' => 'Only http and https endpoints are accepted.'];
        }

        try {
            $response = Application::get()->getHttpClient()->request('POST', trim($url), [
                'headers' => self::headers($secret, $event, $body, $userAgent),
                'body' => $body,
                'allow_redirects' => false,
                'connect_timeout' => 5,
                'timeout' => 15,
                'http_errors' => false,
            ]);
            $statusCode = $response->getStatusCode();
            $error = '';
        } catch (GuzzleException $e) {
            $statusCode = 0;
            // The message of a connection error repeats the URL, which can carry a token.
            $error = substr((string) strrchr(get_class($e), '\\'), 1);
        }

        $ok = $error === '' && $statusCode >= 200 && $statusCode < 300;
        if (!$ok) {
            error_log(sprintf('[ojsbrWebhook] Failed to send %s webhook to %s. Status: %d %s', $event, self::logTarget($url), $statusCode, $error));
        }

        return ['ok' => $ok, 'statusCode' => $statusCode, 'error' => $error];
    }
}
