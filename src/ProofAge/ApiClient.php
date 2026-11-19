<?php

namespace ProofAge\WordPress\ProofAge;

use ProofAge\WordPress\Support\Connection;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

final class ApiClient
{
    /**
     * @var (callable(): int)|null
     */
    private $clock;

    /**
     * @param  (callable(): int)|null  $clock  Unix time in seconds; tests inject a fixed one.
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock;
    }

    /**
     * Creates a verification. The call carries an `Idempotency-Key` (a fresh UUID unless the caller
     * passes one), so a repeat of the same request returns the first response instead of a second
     * verification.
     */
    public function createVerification(
        string $externalId,
        string $returnUrl,
        array $externalMetadata = [],
        array $metadata = [],
        ?string $idempotencyKey = null
    ): array|WP_Error
    {
        $payload = [
            'external_id' => $externalId,
            'callback_url' => $returnUrl,
        ];

        if ($externalMetadata !== []) {
            $payload['external_metadata'] = $externalMetadata;
        }

        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        return $this->request('POST', '/v1/verifications', $payload, 15, $this->idempotencyHeaders($idempotencyKey));
    }

    public function getVerification(string $verificationId): array|WP_Error
    {
        return $this->request('GET', '/v1/verifications/' . rawurlencode($verificationId));
    }

    public function getConsent(): array|WP_Error
    {
        return $this->request('GET', '/v1/consent');
    }

    /**
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public function listWebhookSubscriptions(): array|WP_Error
    {
        $response = $this->request('GET', '/v1/webhook-subscriptions');

        if (is_wp_error($response)) {
            return $response;
        }

        $subscriptions = $response['data']['data'] ?? [];

        return is_array($subscriptions) ? array_values(array_filter($subscriptions, 'is_array')) : [];
    }

    /**
     * Subscribes a URL to every decision, without document data.
     */
    public function createWebhookSubscription(string $url, ?string $idempotencyKey = null): array|WP_Error
    {
        return $this->request('POST', '/v1/webhook-subscriptions', [
            'url' => $url,
            'include_document_data' => false,
        ], 15, $this->idempotencyHeaders($idempotencyKey));
    }

    public function deleteWebhookSubscription(string $subscriptionId, int $timeout = 15): array|WP_Error
    {
        return $this->request('DELETE', '/v1/webhook-subscriptions/' . rawurlencode($subscriptionId), null, $timeout);
    }

    public function testConnectivity(): array
    {
        if (! Connection::isConnected()) {
            return [
                'success' => false,
                'message' => __('ProofAge is not connected. Click Connect ProofAge first.', 'proofage-age-verification'),
            ];
        }

        $response = $this->getConsent();

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        return [
            'success' => true,
            'message' => __('ProofAge connectivity check succeeded.', 'proofage-age-verification'),
        ];
    }

    /**
     * Sends a signed request. A `401` is checked once more with a second call; if that also answers
     * `401`, the key is gone and the connection is marked lost.
     */
    private function request(string $method, string $path, ?array $payload = null, int $timeout = 15, array $extraHeaders = []): array|WP_Error
    {
        $response = $this->send($method, $path, $payload, $timeout, $extraHeaders);

        if (! is_wp_error($response)) {
            Connection::setSuspended(false);

            return $response;
        }

        $status = $this->errorStatus($response);
        $errorCode = $this->errorCode($response);

        if ($status === 401) {
            return $this->handleUnauthorized($response, $timeout);
        }

        if ($errorCode === 'WORKSPACE_SUSPENDED') {
            Connection::setSuspended(true);

            return new WP_Error('proofage_workspace_suspended', $this->unavailableMessage(), ['status' => 503]);
        }

        return $response;
    }

    /**
     * The confirming call goes through send(), not request(), so it cannot trigger this check again.
     */
    private function handleUnauthorized(WP_Error $original, int $timeout): WP_Error
    {
        $refusedSecretKey = Connection::secretKey();
        $confirmation = $this->send('GET', '/v1/consent', null, $timeout);

        if (! is_wp_error($confirmation) || $this->errorStatus($confirmation) !== 401) {
            return $original;
        }

        if ($refusedSecretKey !== '') {
            Connection::markLost($refusedSecretKey);
        }

        return new WP_Error('proofage_connection_lost', $this->unavailableMessage(), ['status' => 503]);
    }

    /**
     * Every call builds its own `X-Timestamp` and signature: the server accepts a signed request
     * once, so headers are never reused.
     *
     * @param  array<string, string>  $extraHeaders
     */
    private function send(string $method, string $path, ?array $payload, int $timeout, array $extraHeaders = []): array|WP_Error
    {
        $apiKey = Connection::apiKey();
        $secretKey = Connection::secretKey();

        if ($apiKey === '' || $secretKey === '') {
            return new WP_Error('proofage_not_connected', $this->unavailableMessage(), ['status' => 503]);
        }

        $body = $payload === null ? '' : wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) ($this->clock !== null ? ($this->clock)() : time());
        $headers = [
            'Accept' => 'application/json',
            'X-API-Key' => $apiKey,
            'X-Timestamp' => $timestamp,
            'X-HMAC-Signature' => (new RequestSigner($secretKey))->signTimestamped($timestamp, $method, $path, $body),
            ClientIdentity::SDK_HEADER => ClientIdentity::sdkHeaderValue(),
        ] + $extraHeaders;

        if ($body !== '') {
            $headers['Content-Type'] = 'application/json';
        }

        $response = wp_remote_request(Endpoints::apiUrl() . $path, [
            'method' => $method,
            'timeout' => $timeout,
            'user-agent' => ClientIdentity::userAgent(),
            'headers' => $headers,
            'body' => $body === '' ? null : $body,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($rawBody, true);

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = is_array($decoded)
                ? (string) ($decoded['error']['message'] ?? $decoded['message'] ?? __('Unknown ProofAge API error.', 'proofage-age-verification'))
                : __('Unknown ProofAge API error.', 'proofage-age-verification');

            return new WP_Error('proofage_api_error', $message, [
                'status' => $statusCode,
                'response' => $decoded,
            ]);
        }

        return [
            'data' => is_array($decoded) ? $decoded : [],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function idempotencyHeaders(?string $idempotencyKey): array
    {
        $key = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : wp_generate_uuid4();

        return ['Idempotency-Key' => $key];
    }

    private function errorStatus(WP_Error $error): int
    {
        $data = $error->get_error_data();

        return is_array($data) ? (int) ($data['status'] ?? 0) : 0;
    }

    private function errorCode(WP_Error $error): string
    {
        $data = $error->get_error_data();
        $response = is_array($data) ? ($data['response'] ?? null) : null;

        return is_array($response) && is_array($response['error'] ?? null) ? (string) ($response['error']['code'] ?? '') : '';
    }

    /**
     * What a visitor sees when the site is not connected, the key is lost or the workspace is suspended.
     */
    private function unavailableMessage(): string
    {
        return __('Age verification is temporarily unavailable.', 'proofage-age-verification');
    }
}
