<?php

namespace ProofAge\WordPress\ProofAge;

/**
 * Identifies this plugin to the ProofAge API.
 *
 * Neither value is part of the HMAC signature, which covers only the method, path and body.
 */
final class ClientIdentity
{
    public const SDK_HEADER = 'X-ProofAge-Sdk';

    /**
     * Space-separated `<name>/<version>` tokens, outermost first. The plugin has its own
     * HTTP client, so it is a single token.
     */
    public static function sdkHeaderValue(): string
    {
        return 'wordpress/' . PROOFAGE_WP_PLUGIN_VERSION;
    }

    public static function userAgent(): string
    {
        $details = [
            'WordPress ' . self::wordPressVersion(),
            'PHP ' . PHP_VERSION,
        ];

        if (defined('WC_VERSION') && is_string(WC_VERSION) && WC_VERSION !== '') {
            $details[] = 'WooCommerce/' . WC_VERSION;
        }

        return 'ProofAge-WordPress/' . PROOFAGE_WP_PLUGIN_VERSION . ' (' . implode('; ', $details) . ')';
    }

    private static function wordPressVersion(): string
    {
        $version = function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '';

        return $version !== '' ? $version : 'unknown';
    }
}
