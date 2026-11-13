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
 */
final class Connection
{
    public const OPTION_KEY = 'proofage_age_verification_connection';

    public const WEBHOOK_ERROR_UNREACHABLE = 'unreachable';

    public const WEBHOOK_ERROR_LIMIT = 'limit';

    /**
     * @return array{api_key: string, secret_key: string, workspace: array<string, string>, connected_at: int, subscription_id: string, subscription_url: string, webhook_error: string, lost: bool, suspended: bool}
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        if (! is_array($stored)) {
            $stored = [];
        }

        $workspace = is_array($stored['workspace'] ?? null) ? $stored['workspace'] : [];

        return [
            'api_key' => (string) ($stored['api_key'] ?? ''),
            'secret_key' => (string) ($stored['secret_key'] ?? ''),
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
            'lost' => (bool) ($stored['lost'] ?? false),
            'suspended' => (bool) ($stored['suspended'] ?? false),
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
     * Stores freshly collected keys. This also clears the lost flag and every sign of an earlier
     * connection.
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
        if (self::all()['suspended'] === $suspended) {
            return;
        }

        self::update(['suspended' => $suspended]);
    }

    /**
     * Wipes the keys, the workspace and the subscription, after the user disconnected.
     */
    public static function clear(): void
    {
        self::save([]);
    }

    /**
     * Wipes the keys because ProofAge no longer accepts them, and remembers why.
     */
    public static function markLost(): void
    {
        self::save(['lost' => true]);
    }

    public static function delete(): void
    {
        delete_option(self::OPTION_KEY);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private static function update(array $changes): void
    {
        if (! self::isConnected()) {
            return;
        }

        self::save(array_replace(self::all(), $changes));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function save(array $data): void
    {
        // The third argument keeps the secret key out of the autoloaded options.
        update_option(self::OPTION_KEY, $data, false);
    }
}
