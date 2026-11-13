<?php

namespace ProofAge\WordPress\Connect;

use ProofAge\WordPress\ProofAge\ConnectClient;
use ProofAge\WordPress\ProofAge\Endpoints;
use ProofAge\WordPress\Support\Connection;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The server side of the OAuth device flow: asks ProofAge for a code, polls for the approval,
 * collects the keys once and discards the tokens.
 *
 * One flow per WordPress user lives in a transient. It holds the device code, which never goes to
 * the browser. Every method answers an array whose `status` is one of: `waiting`, `pending`,
 * `connected`, `expired`, `denied` or `error`; `message` says why for the last three.
 */
final class DeviceFlow
{
    public const TRANSIENT_PREFIX = 'proofage_device_';

    public const LOCK_PREFIX = 'proofage_device_lock_';

    private const LOCK_SECONDS = 30;

    private const MIN_INTERVAL = 5;

    public function __construct(
        private readonly ConnectClient $client,
        private readonly WebhookSubscriber $subscriber,
    ) {
    }

    /**
     * Asks for a device code. A code that is still alive is shown again instead of asking for a
     * new one, so a second tab shows the same code and the first one keeps working.
     *
     * @return array<string, mixed>
     */
    public function start(int $userId): array
    {
        if (Connection::isConnected()) {
            return ['status' => 'connected'];
        }

        if (! Endpoints::hasConnectClientId()) {
            return $this->error(__('This build of the plugin cannot connect to ProofAge yet.', 'proofage-age-verification'));
        }

        $flow = $this->load($userId);

        if ($flow !== null) {
            return $this->waiting($flow);
        }

        $response = $this->client->requestDeviceCode();
        $body = $response['body'];

        if ($response['status'] === 429) {
            return $this->error(__('Too many attempts; wait a minute and try again.', 'proofage-age-verification'));
        }

        if (
            $response['status'] !== 200
            || ! is_string($body['device_code'] ?? null)
            || ! is_string($body['user_code'] ?? null)
            || $body['device_code'] === ''
            || $body['user_code'] === ''
        ) {
            return $this->error(__('Could not reach ProofAge. Try again in a moment.', 'proofage-age-verification'));
        }

        $expiresIn = max(1, (int) ($body['expires_in'] ?? 600));
        $flow = [
            'device_code' => $body['device_code'],
            'user_code' => $body['user_code'],
            'verification_uri' => $this->verificationUri((string) ($body['verification_uri'] ?? '')),
            'interval' => max(self::MIN_INTERVAL, (int) ($body['interval'] ?? self::MIN_INTERVAL)),
            'expires_at' => time() + $expiresIn,
            'next_poll_at' => time() + max(self::MIN_INTERVAL, (int) ($body['interval'] ?? self::MIN_INTERVAL)),
        ];

        $this->save($userId, $flow);

        return $this->waiting($flow);
    }

    /**
     * What the settings page shows when it is opened while a code is waiting.
     *
     * @return array<string, mixed>|null
     */
    public function current(int $userId): ?array
    {
        if (Connection::isConnected()) {
            return null;
        }

        $flow = $this->load($userId);

        return $flow === null ? null : $this->waiting($flow);
    }

    /**
     * One poll from the browser. It makes at most one token request, and none before the
     * interval has passed.
     *
     * @return array<string, mixed>
     */
    public function poll(int $userId): array
    {
        if (Connection::isConnected()) {
            return ['status' => 'connected'];
        }

        if (! $this->acquireLock($userId)) {
            return $this->pendingFor($userId);
        }

        try {
            // A poll from another tab may have finished while this one waited for the lock.
            if (Connection::isConnected()) {
                return ['status' => 'connected'];
            }

            $flow = $this->load($userId);

            if ($flow === null) {
                return $this->expired();
            }

            if (time() < $flow['next_poll_at']) {
                return $this->pending($flow);
            }

            return $this->exchange($userId, $flow);
        } finally {
            $this->releaseLock($userId);
        }
    }

    public function cancel(int $userId): void
    {
        delete_transient(self::TRANSIENT_PREFIX . $userId);
    }

    /**
     * @param  array<string, mixed>  $flow
     *
     * @return array<string, mixed>
     */
    private function exchange(int $userId, array $flow): array
    {
        $flow['next_poll_at'] = time() + $flow['interval'];
        $response = $this->client->pollToken($flow['device_code']);
        $status = $response['status'];

        if ($status === 200 && is_string($response['body']['access_token'] ?? null) && $response['body']['access_token'] !== '') {
            // The device code is spent whatever happens next, so the flow ends here.
            $this->cancel($userId);

            return $this->collect($response['body']['access_token']);
        }

        switch ($response['error']) {
            case 'authorization_pending':
                break;

            case 'slow_down':
                $flow['interval'] += 5;
                $flow['next_poll_at'] = time() + $flow['interval'];
                break;

            case 'expired_token':
            case 'invalid_grant':
                $this->cancel($userId);

                return $this->expired();

            case 'access_denied':
                $this->cancel($userId);

                return [
                    'status' => 'denied',
                    'message' => __('The connection was cancelled in ProofAge.', 'proofage-age-verification'),
                ];

            default:
                // No answer, a server error or a throttle: keep trying until the code expires.
                if ($status === 0 || $status === 408 || $status === 429 || $status >= 500) {
                    break;
                }

                $this->cancel($userId);

                return $this->error(__('Could not connect to ProofAge. Click Connect ProofAge to try again.', 'proofage-age-verification'));
        }

        $this->save($userId, $flow);

        return $this->pending($flow);
    }

    /**
     * Fetches the keys with the access token, which works once, and stores them.
     *
     * @return array<string, mixed>
     */
    private function collect(string $accessToken): array
    {
        $response = $this->client->fetchCredentials($accessToken);
        unset($accessToken);

        $body = $response['body'];

        if ($response['status'] === 200) {
            $workspace = is_array($body['workspace'] ?? null) ? $body['workspace'] : [];

            if (
                is_string($body['api_key'] ?? null) && $body['api_key'] !== ''
                && is_string($body['secret_key'] ?? null) && $body['secret_key'] !== ''
            ) {
                Connection::store($body['api_key'], $body['secret_key'], $workspace);
                $this->subscriber->ensure();

                return ['status' => 'connected'];
            }
        }

        return $this->error($this->credentialsMessage($response['status'], $response['error']));
    }

    private function credentialsMessage(int $status, string $code): string
    {
        if ($code === 'CLIENT_NOT_ALLOWED') {
            return __('This version of the plugin can no longer connect; update it.', 'proofage-age-verification');
        }

        if ($code === 'CONNECT_NOT_PERMITTED') {
            return __('Your ProofAge role cannot connect this workspace.', 'proofage-age-verification');
        }

        if ($code === 'KEY_LIMIT_REACHED') {
            return __('The workspace already has 5 secret keys. Delete one in the ProofAge console, then connect again.', 'proofage-age-verification');
        }

        if ($status === 401 || $code === 'GRANT_NOT_FOUND') {
            return __('The approval in ProofAge is no longer valid. Click Connect ProofAge to start again.', 'proofage-age-verification');
        }

        return __('Could not finish connecting. Click Connect ProofAge again.', 'proofage-age-verification');
    }

    /**
     * Shows ProofAge's own link only when it points at the app host; otherwise the device page of
     * the app host. The link never carries the code: the person types it.
     */
    private function verificationUri(string $uri): string
    {
        $appUrl = Endpoints::appUrl();
        $appHost = strtolower((string) wp_parse_url($appUrl, PHP_URL_HOST));
        $host = strtolower((string) wp_parse_url($uri, PHP_URL_HOST));
        $scheme = strtolower((string) wp_parse_url($uri, PHP_URL_SCHEME));

        if ($uri !== '' && $host === $appHost && $scheme === strtolower((string) wp_parse_url($appUrl, PHP_URL_SCHEME))) {
            return $uri;
        }

        return $appUrl . '/oauth/device';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function load(int $userId): ?array
    {
        $flow = get_transient(self::TRANSIENT_PREFIX . $userId);

        if (! is_array($flow) || ! is_string($flow['device_code'] ?? null)) {
            return null;
        }

        if (time() >= (int) ($flow['expires_at'] ?? 0)) {
            $this->cancel($userId);

            return null;
        }

        return [
            'device_code' => $flow['device_code'],
            'user_code' => (string) ($flow['user_code'] ?? ''),
            'verification_uri' => (string) ($flow['verification_uri'] ?? ''),
            'interval' => max(self::MIN_INTERVAL, (int) ($flow['interval'] ?? self::MIN_INTERVAL)),
            'expires_at' => (int) $flow['expires_at'],
            'next_poll_at' => (int) ($flow['next_poll_at'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $flow
     */
    private function save(int $userId, array $flow): void
    {
        set_transient(self::TRANSIENT_PREFIX . $userId, $flow, max(1, $flow['expires_at'] - time()));
    }

    /**
     * What the browser may know: never the device code.
     *
     * @param  array<string, mixed>  $flow
     *
     * @return array<string, mixed>
     */
    private function waiting(array $flow): array
    {
        return [
            'status' => 'waiting',
            'user_code' => $flow['user_code'],
            'verification_uri' => $flow['verification_uri'],
            'expires_in' => max(0, $flow['expires_at'] - time()),
            'interval' => $flow['interval'],
        ];
    }

    /**
     * @param  array<string, mixed>  $flow
     *
     * @return array<string, mixed>
     */
    private function pending(array $flow): array
    {
        return ['status' => 'pending', 'interval' => $flow['interval']];
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingFor(int $userId): array
    {
        $flow = $this->load($userId);

        return ['status' => 'pending', 'interval' => $flow['interval'] ?? self::MIN_INTERVAL];
    }

    /**
     * @return array<string, mixed>
     */
    private function expired(): array
    {
        return [
            'status' => 'expired',
            'message' => __('The code expired. Click Connect ProofAge to get a new one.', 'proofage-age-verification'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(string $message): array
    {
        return ['status' => 'error', 'message' => $message];
    }

    /**
     * add_option() fails when the option exists, which makes the lock atomic on every host. A
     * transient would not be on a site without an object cache. A lock older than the limit is
     * a crashed request and is taken over.
     */
    private function acquireLock(int $userId): bool
    {
        $name = self::LOCK_PREFIX . $userId;

        if (add_option($name, time(), '', false)) {
            return true;
        }

        if (time() - (int) get_option($name, 0) < self::LOCK_SECONDS) {
            return false;
        }

        delete_option($name);

        return add_option($name, time(), '', false);
    }

    private function releaseLock(int $userId): void
    {
        delete_option(self::LOCK_PREFIX . $userId);
    }
}
