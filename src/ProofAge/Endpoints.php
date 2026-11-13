<?php

namespace ProofAge\WordPress\ProofAge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Where the plugin talks to ProofAge, and as which app.
 *
 * A local setup can override each value with a constant in wp-config.php. That is for development
 * and is not documented to users.
 */
final class Endpoints
{
    private const APP_URL = 'https://app.proofage.net';

    private const API_URL = 'https://api.proofage.net';

    /**
     * The public client ID of the connect app "ProofAge for WordPress" on production. It is public
     * by design: the device flow has no client secret.
     */
    private const CONNECT_CLIENT_ID = '01a1d5fb-8571-72a0-bea4-f1b24d102064';

    /**
     * What CONNECT_CLIENT_ID holds until the real ID is put in. Never change it: it is how a build
     * without a registered ID is recognised.
     */
    private const PLACEHOLDER_CLIENT_ID = 'proofage-for-wordpress-not-registered';

    /**
     * Host for /oauth/device/code, /oauth/token and /oauth/connect/credentials.
     */
    public static function appUrl(): string
    {
        return self::constant('PROOFAGE_APP_URL', self::APP_URL);
    }

    /**
     * Host for /v1.
     */
    public static function apiUrl(): string
    {
        return self::constant('PROOFAGE_API_URL', self::API_URL);
    }

    public static function connectClientId(): string
    {
        return self::constant('PROOFAGE_CONNECT_CLIENT_ID', self::CONNECT_CLIENT_ID);
    }

    public static function hasConnectClientId(): bool
    {
        return self::connectClientId() !== self::PLACEHOLDER_CLIENT_ID;
    }

    private static function constant(string $name, string $default): string
    {
        $value = defined($name) ? constant($name) : '';

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return rtrim(trim($value), '/');
    }
}
