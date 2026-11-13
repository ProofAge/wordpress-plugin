<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// WordPress does not load the plugin when it deletes it. Load what the cleanup needs.
if (! defined('PROOFAGE_WP_PLUGIN_VERSION')) {
    require_once __DIR__ . '/proofage-age-verification.php';
}

// Best effort: an uninstalled site must not leave a subscription that fails every delivery.
// A short timeout keeps the uninstall from hanging when ProofAge cannot be reached.
(new \ProofAge\WordPress\Connect\WebhookSubscriber(new \ProofAge\WordPress\ProofAge\ApiClient()))->remove(5);

delete_option('proofage_age_verification_settings');
\ProofAge\WordPress\Support\Connection::delete();

global $wpdb;

// The device-flow transients and locks of every user.
$proofage_patterns = [
    $wpdb->esc_like('_transient_proofage_device_') . '%',
    $wpdb->esc_like('_transient_timeout_proofage_device_') . '%',
    $wpdb->esc_like('proofage_device_lock_') . '%',
];

foreach ($proofage_patterns as $proofage_pattern) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of this plugin's own options.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $proofage_pattern));
}
