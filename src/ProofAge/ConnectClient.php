<?php

namespace ProofAge\WordPress\ProofAge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Calls the three connect endpoints on the ProofAge app host.
 *
 * Every method answers `status` (0 when no answer came), `body` (the decoded JSON, or an empty
 * array) and `error` (the OAuth error string, or the error code of the credentials answer). It never
 * logs or returns a token to anything but its caller.
 */
final class ConnectClient
{
    private const SCOPE = 'workspace:connect';

    private const DEVICE_GRANT = 'urn:ietf:params:oauth:grant-type:device_code';

    /**
     * @return array{status: int, body: array<string, mixed>, error: string}
     */
    public function requestDeviceCode(): array
    {
        return $this->post('/oauth/device/code', [
            'client_id' => Endpoints::connectClientId(),
            'scope' => self::SCOPE,
        ]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>, error: string}
     */
    public function pollToken(string $deviceCode): array
    {
        return $this->post('/oauth/token', [
            'grant_type' => self::DEVICE_GRANT,
            'client_id' => Endpoints::connectClientId(),
            'device_code' => $deviceCode,
        ]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>, error: string}
     */
    public function fetchCredentials(string $accessToken): array
    {
        return $this->post('/oauth/connect/credentials', [], ['Authorization' => 'Bearer ' . $accessToken]);
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $extraHeaders
     *
     * @return array{status: int, body: array<string, mixed>, error: string}
     */
    private function post(string $path, array $fields, array $extraHeaders = []): array
    {
        $response = wp_remote_post(Endpoints::appUrl() . $path, [
            'timeout' => 15,
            'user-agent' => ClientIdentity::userAgent(),
            'headers' => array_merge([
                'Accept' => 'application/json',
                ClientIdentity::SDK_HEADER => ClientIdentity::sdkHeaderValue(),
            ], $extraHeaders),
            'body' => $fields === [] ? null : $fields,
        ]);

        if (is_wp_error($response)) {
            return ['status' => 0, 'body' => [], 'error' => ''];
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $body = is_array($decoded) ? $decoded : [];

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body' => $body,
            'error' => $this->errorCode($body),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function errorCode(array $body): string
    {
        $error = $body['error'] ?? '';

        if (is_array($error)) {
            return (string) ($error['code'] ?? '');
        }

        return is_string($error) ? $error : '';
    }
}
