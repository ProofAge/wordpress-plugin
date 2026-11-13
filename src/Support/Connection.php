<?php

namespace ProofAge\WordPress\Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The connection to a ProofAge workspace: its keys, the workspace they belong to and the webhook
 * subscription this site made.
 *
 * It lives in its own option, which is not registered with the Settings API. Saving the settings
 * form cannot touch it, and it is never part of a form, a REST schema or an AJAX answer.
 *
 * Only the admin flow (connect, disconnect, try again) writes that option. What a visitor's request
 * learns from ProofAge, that the key is lost or the workspace suspended, goes into a small option of
 * its own, so a request that read the keys before a disconnect cannot write them back.
 */
final class Connection
{
    public const OPTION_KEY = 'proofage_age_verification_connection';

    public const STATE_OPTION_KEY = 'proofage_age_verification_connection_state';

    public const WEBHOOK_ERROR_UNREACHABLE = 'unreachable';

    public const WEBHOOK_ERROR_LIMIT = 'limit';

    /**
     * The stored connection. A key that ProofAge refused reads as empty.
     *
     * @return array{api_key: string, secret_key: string, workspace: array<string, string>, connected_at: int, subscription_id: string, subscription_url: string, webhook_error: string, lost: bool, suspended: bool}
     */
    public static function all(): array
    {
        $stored = self::stored();
        $state = self::state();
        $workspace = is_array($stored['workspace'] ?? null) ? $stored['workspace'] : [];
        $lostKeyInUse = self::isLostKeyInUse($stored, $state);

        return [
            'api_key' => $lostKeyInUse ? '' : (string) ($stored['api_key'] ?? ''),
            'secret_key' => $lostKeyInUse ? '' : (string) ($stored['secret_key'] ?? ''),
            'workspace' => [
                'id' => (string) ($workspace['id'] ?? ''),
                'name' => (string) ($workspace['name'] ?? ''),
                'mode' => (string) ($workspace['mode'] ?? ''),
                'flow_type' => (string) ($workspace['flow_type'] ?? ''),
            ],
            'connected_at' => (int) ($stored['connected_at'] ?? 0),
            'subscription_id' => (string) ($stored['subscription_id'] ?? ''),
            'subscription_url' => (string) ($stored['subscription_url'] ?? ''),
            'webhook_error' => (string) ($stored['webhook_error'] ?? ''),
            'lost' => self::isLostState($stored, $state),
            'suspended' => (bool) ($state['suspended'] ?? false),
        ];
    }

    public static function isConnected(): bool
    {
        $connection = self::all();

        return $connection['api_key'] !== '' && $connection['secret_key'] !== '';
    }

    public static function apiKey(): string
    {
        return self::all()['api_key'];
    }

    public static function secretKey(): string
    {
        return self::all()['secret_key'];
    }

    public static function isLost(): bool
    {
        return self::all()['lost'];
    }

    /**
     * Wipes a refused key from the keys option. Called from the admin screen, never from a visitor's
     * request.
     */
    public static function wipeLostKeys(): void
    {
        if (self::isLostKeyInUse(self::stored(), self::state())) {
            self::save([]);
        }
    }

    /**
     * Stores freshly collected keys. This also clears the lost and suspended flags and every sign of
     * an earlier connection.
     *
     * @param  array<string, mixed>  $workspace
     */
    public static function store(string $apiKey, string $secretKey, array $workspace): void
    {
        self::save([
            'api_key' => $apiKey,
            'secret_key' => $secretKey,
            'workspace' => [
                'id' => (string) ($workspace['id'] ?? ''),
                'name' => (string) ($workspace['name'] ?? ''),
                'mode' => (string) ($workspace['mode'] ?? ''),
                'flow_type' => (string) ($workspace['flow_type'] ?? ''),
            ],
            'connected_at' => time(),
        ]);
        delete_option(self::STATE_OPTION_KEY);
    }

    public static function setSubscription(string $id, string $url): void
    {
        self::update(['subscription_id' => $id, 'subscription_url' => $url, 'webhook_error' => '']);
    }

    public static function clearSubscription(): void
    {
        self::update(['subscription_id' => '', 'subscription_url' => '']);
    }

    public static function setWebhookError(string $error): void
    {
        self::update(['webhook_error' => $error]);
    }

    public static function setSuspended(bool $suspended): void
    {
        $state = self::state();

        if ((bool) ($state['suspended'] ?? false) === $suspended) {
            return;
        }

        $state['suspended'] = $suspended;
        self::saveState($state);
    }

    /**
     * Wipes the keys, the workspace and the subscription, after the user disconnected. This also
     * clears the lost flag.
     */
    public static function clear(): void
    {
        self::save([]);
        delete_option(self::STATE_OPTION_KEY);
    }

    /**
     * Remembers that ProofAge no longer accepts the key that was in use. The keys option is not
     * touched: the key reads as empty from now on and the admin screen wipes it. A key stored later
     * by a new connection has another hash, so it is not affected.
     */
    public static function markLost(string $refusedSecretKey): void
    {
        $state = self::state();
        $state['lost'] = true;
        $state['lost_key_hash'] = hash('sha256', $refusedSecretKey);
        self::saveState($state);
    }

    public static function delete(): void
    {
        delete_option(self::OPTION_KEY);
        delete_option(self::STATE_OPTION_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private static function stored(): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function state(): array
    {
        $state = get_option(self::STATE_OPTION_KEY, []);

        return is_array($state) ? $state : [];
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $state
     */
    private static function isLostKeyInUse(array $stored, array $state): bool
    {
        $secret = (string) ($stored['secret_key'] ?? '');

        return $secret !== '' && ($state['lost'] ?? false) && hash_equals((string) ($state['lost_key_hash'] ?? ''), hash('sha256', $secret));
    }

    /**
     * Lost: the stored key was refused, or it was wiped after it was refused.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $state
     */
    private static function isLostState(array $stored, array $state): bool
    {
        if (! ($state['lost'] ?? false)) {
            return false;
        }

        return (string) ($stored['secret_key'] ?? '') === '' || self::isLostKeyInUse($stored, $state);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private static function update(array $changes): void
    {
        if (! self::isConnected()) {
            return;
        }

        self::save(array_replace(self::stored(), $changes));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function save(array $data): void
    {
        // The third argument keeps the secret key out of the autoloaded options.
        update_option(self::OPTION_KEY, $data, false);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function saveState(array $state): void
    {
        update_option(self::STATE_OPTION_KEY, $state, false);
    }
}
