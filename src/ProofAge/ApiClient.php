<?php

namespace ProofAge\WordPress\ProofAge;

use ProofAge\WordPress\Support\Connection;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

final class ApiClient
{
    public const CLOCK_OFFSET_OPTION = 'proofage_age_verification_clock_offset';

    /**
     * @var (callable(): int)|null
     */
    private $clock;

    /**
     * @var (callable(int): void)|null
     */
    private $sleep;

    /**
     * @param  (callable(): int)|null  $clock  Unix time in seconds; tests inject a fixed one.
     * @param  (callable(int): void)|null  $sleep  Waits the given number of seconds; tests inject a no-op.
     */
    public function __construct(?callable $clock = null, ?callable $sleep = null)
    {
        $this->clock = $clock;
        $this->sleep = $sleep;
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

        $idempotencyHeaders = $this->idempotencyHeaders($idempotencyKey);

        if (is_wp_error($idempotencyHeaders)) {
            return $idempotencyHeaders;
        }

        return $this->request('POST', '/v1/verifications', $payload, 15, $idempotencyHeaders);
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
        $idempotencyHeaders = $this->idempotencyHeaders($idempotencyKey);

        if (is_wp_error($idempotencyHeaders)) {
            return $idempotencyHeaders;
        }

        return $this->request('POST', '/v1/webhook-subscriptions', [
            'url' => $url,
            'include_document_data' => false,
        ], 15, $idempotencyHeaders);
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

        if ($status === 401 && ! $this->isNotAKeyProblem($errorCode)) {
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

        if (
            ! is_wp_error($confirmation)
            || $this->errorStatus($confirmation) !== 401
            || $this->isNotAKeyProblem($this->errorCode($confirmation))
        ) {
            return $original;
        }

        if ($refusedSecretKey !== '') {
            Connection::markLost($refusedSecretKey);
        }

        return new WP_Error('proofage_connection_lost', $this->unavailableMessage(), ['status' => 503]);
    }

    /**
     * The signature covers whole seconds, so two identical requests signed in the same second share
     * one, and the server refuses the second with 401 `REPLAYED_REQUEST` before processing it. For a
     * non-GET request that is safe to send again: wait for the next second and resend once, re-signed,
     * with the same Idempotency-Key. Any other 401 is left to request().
     *
     * @param  array<string, string>  $extraHeaders
     */
    private function send(string $method, string $path, ?array $payload, int $timeout, array $extraHeaders = []): array|WP_Error
    {
        $response = $this->sendOnce($method, $path, $payload, $timeout, $extraHeaders);

        if (
            is_wp_error($response)
            && $this->errorStatus($response) === 401
            && $this->errorCode($response) === 'TIMESTAMP_OUT_OF_RANGE'
            && $this->rememberServerOffset($response)
        ) {
            $response = $this->sendOnce($method, $path, $payload, $timeout, $extraHeaders);
        }

        if (
            is_wp_error($response)
            && strtoupper($method) !== 'GET'
            && $this->errorStatus($response) === 401
            && $this->errorCode($response) === 'REPLAYED_REQUEST'
        ) {
            $this->sleepOneSecond();

            return $this->sendOnce($method, $path, $payload, $timeout, $extraHeaders);
        }

        return $response;
    }

    /**
     * A 401 about the timestamp or a replay says nothing about the keys, so it must never mark the
     * connection lost.
     */
    private function isNotAKeyProblem(string $errorCode): bool
    {
        return in_array($errorCode, ['TIMESTAMP_OUT_OF_RANGE', 'TIMESTAMP_REQUIRED', 'REPLAYED_REQUEST'], true);
    }

    /**
     * Learns how far this site's clock is from the server's, from the `Date` header of the refusal.
     * The offset is kept for a day and applied to every later timestamp.
     *
     * @return bool  Whether an offset was stored, so the request is worth sending again.
     */
    private function rememberServerOffset(WP_Error $error): bool
    {
        $data = $error->get_error_data();
        $serverTime = is_array($data) && is_string($data['date'] ?? null) ? strtotime($data['date']) : false;

        if ($serverTime === false) {
            return false;
        }

        $now = $this->localTime();

        update_option(self::CLOCK_OFFSET_OPTION, [
            'offset' => $serverTime - $now,
            'expires' => $now + DAY_IN_SECONDS,
        ], false);

        return true;
    }

    private function localTime(): int
    {
        return (int) ($this->clock !== null ? ($this->clock)() : time());
    }

    private function requestTime(): int
    {
        $now = $this->localTime();
        $stored = get_option(self::CLOCK_OFFSET_OPTION, []);

        if (! is_array($stored) || (int) ($stored['expires'] ?? 0) <= $now) {
            return $now;
        }

        return $now + (int) ($stored['offset'] ?? 0);
    }

    private function sleepOneSecond(): void
    {
        if ($this->sleep !== null) {
            ($this->sleep)(1);

            return;
        }

        sleep(1);
    }

    /**
     * Every call builds its own `X-Timestamp` and signature: the server accepts a signed request
     * once, so headers are never reused.
     *
     * @param  array<string, string>  $extraHeaders
     */
    private function sendOnce(string $method, string $path, ?array $payload, int $timeout, array $extraHeaders = []): array|WP_Error
    {
        $apiKey = Connection::apiKey();
        $secretKey = Connection::secretKey();

        if ($apiKey === '' || $secretKey === '') {
            return new WP_Error('proofage_not_connected', $this->unavailableMessage(), ['status' => 503]);
        }

        $body = $payload === null ? '' : wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) $this->requestTime();
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
                'date' => (string) wp_remote_retrieve_header($response, 'date'),
            ]);
        }

        return [
            'data' => is_array($decoded) ? $decoded : [],
        ];
    }

    /**
     * A caller's key must be 1 to 255 printable ASCII characters; without one a UUID is made.
     *
     * @return array<string, string>|WP_Error
     */
    private function idempotencyHeaders(?string $idempotencyKey): array|WP_Error
    {
        if ($idempotencyKey === null) {
            return ['Idempotency-Key' => wp_generate_uuid4()];
        }

        if (preg_match('/\A[\x21-\x7E]{1,255}\z/', $idempotencyKey) !== 1) {
            return new WP_Error(
                'proofage_invalid_idempotency_key',
                __('The idempotency key must be 1 to 255 printable ASCII characters.', 'proofage-age-verification'),
                ['status' => 400]
            );
        }

        return ['Idempotency-Key' => $idempotencyKey];
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
