<?php

namespace ProofAge\WordPress\Admin;

use ProofAge\WordPress\Connect\DeviceFlow;
use ProofAge\WordPress\Connect\WebhookSubscriber;
use ProofAge\WordPress\Support\Connection;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Connection card on the settings page, the admin notices and the handlers behind them.
 *
 * Start, Poll and Cancel are admin-ajax POSTs the card's script makes; Try again and Disconnect are
 * plain form posts. Each has its own nonce action and requires `manage_options`.
 */
final class ConnectionCard
{
    private const NONCE_START = 'proofage_connect_start';

    private const NONCE_POLL = 'proofage_connect_poll';

    private const NONCE_CANCEL = 'proofage_connect_cancel';

    private const NONCE_DISCONNECT = 'proofage_disconnect';

    private const NONCE_RETRY = 'proofage_retry_webhook';

    private const NOTICE_ARGUMENT = 'proofage-notice';

    public function __construct(
        private readonly DeviceFlow $deviceFlow,
        private readonly WebhookSubscriber $webhookSubscriber,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('admin_notices', [$this, 'renderNotice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_proofage_connect_start', [$this, 'handleStart']);
        add_action('wp_ajax_proofage_connect_poll', [$this, 'handlePoll']);
        add_action('wp_ajax_proofage_connect_cancel', [$this, 'handleCancel']);
        add_action('admin_post_proofage_disconnect', [$this, 'handleDisconnect']);
        add_action('admin_post_proofage_retry_webhook', [$this, 'handleRetryWebhook']);
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'settings_page_' . SettingsRegistrar::PAGE_SLUG) {
            return;
        }

        wp_enqueue_script(
            'proofage-age-verification-connect',
            PROOFAGE_WP_PLUGIN_URL . 'assets/js/connect.js',
            [],
            PROOFAGE_WP_PLUGIN_VERSION,
            true
        );

        wp_localize_script('proofage-age-verification-connect', 'ProofAgeConnect', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'settingsUrl' => $this->settingsUrl(),
            'nonces' => [
                'start' => wp_create_nonce(self::NONCE_START),
                'poll' => wp_create_nonce(self::NONCE_POLL),
                'cancel' => wp_create_nonce(self::NONCE_CANCEL),
            ],
            'messages' => [
                'expired' => __('The code expired. Click Connect ProofAge to get a new one.', 'proofage-age-verification'),
                'failed' => __('Could not reach this site. Reload the page and try again.', 'proofage-age-verification'),
                'copy' => __('Copy code', 'proofage-age-verification'),
                'copied' => __('Copied', 'proofage-age-verification'),
                /* translators: %s is the time left, such as 9:41. */
                'countdown' => __('The code expires in %s.', 'proofage-age-verification'),
                'confirmDisconnect' => __('Disconnect this site from ProofAge? Protected pages stay locked until you connect again.', 'proofage-age-verification'),
            ],
        ]);
    }

    public function renderNotice(): void
    {
        if (! current_user_can('manage_options') || Connection::isConnected()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (! $screen || ! in_array($screen->id, ['plugins', 'settings_page_' . SettingsRegistrar::PAGE_SLUG], true)) {
            return;
        }

        $link = '';

        if ($screen->id === 'plugins') {
            $link = sprintf(
                ' <a href="%1$s">%2$s</a>',
                esc_url($this->settingsUrl()),
                esc_html__('Open the ProofAge settings', 'proofage-age-verification')
            );
        }

        if (Connection::isLost()) {
            $message = __('ProofAge no longer accepts this site\'s key; it was probably deleted in the ProofAge console. Connect again. Until then, protected pages stay locked.', 'proofage-age-verification');
            $class = 'notice-error';
        } else {
            $message = __('ProofAge is not connected, so age checks are not running. Protected pages stay locked until you connect.', 'proofage-age-verification');
            $class = 'notice-warning';
        }

        printf('<div class="notice %1$s"><p>%2$s%3$s</p></div>', esc_attr($class), esc_html($message), wp_kses($link, ['a' => ['href' => []]]));
    }

    public function render(): void
    {
        echo '<div class="card proofage-connection" style="max-width: 960px; margin: 16px 0; padding: 16px;" data-proofage-connect>';
        echo '<h2>' . esc_html__('Connection', 'proofage-age-verification') . '</h2>';

        $this->renderFlash();

        if (Connection::isConnected()) {
            $this->renderConnected();
        } else {
            $this->renderDisconnected();
        }

        echo '</div>';
    }

    public function handleStart(): void
    {
        $this->authorizeAjax(self::NONCE_START);
        wp_send_json_success($this->deviceFlow->start(get_current_user_id()));
    }

    public function handlePoll(): void
    {
        $this->authorizeAjax(self::NONCE_POLL);
        wp_send_json_success($this->deviceFlow->poll(get_current_user_id()));
    }

    public function handleCancel(): void
    {
        $this->authorizeAjax(self::NONCE_CANCEL);
        $this->deviceFlow->cancel(get_current_user_id());
        wp_send_json_success(['status' => 'cancelled']);
    }

    public function handleDisconnect(): void
    {
        $this->authorizePost(self::NONCE_DISCONNECT);

        $this->webhookSubscriber->remove();
        Connection::clear();

        $this->redirectBack('disconnected');
    }

    public function handleRetryWebhook(): void
    {
        $this->authorizePost(self::NONCE_RETRY);

        if (Connection::isConnected()) {
            $this->webhookSubscriber->ensure();
        }

        $this->redirectBack('');
    }

    private function renderConnected(): void
    {
        $connection = Connection::all();
        $workspace = $connection['workspace'];
        $isTest = $workspace['mode'] !== 'live';
        $name = $workspace['name'] !== '' ? $workspace['name'] : __('(unnamed workspace)', 'proofage-age-verification');

        echo '<p>';
        printf(
            /* translators: %s is the name of the connected ProofAge workspace. */
            esc_html__('Connected to workspace %s', 'proofage-age-verification'),
            '<strong>' . esc_html($name) . '</strong>'
        );
        printf(
            ' <span class="proofage-badge proofage-badge--%1$s" style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;font-weight:600;background:%2$s;color:%3$s;">%4$s</span>',
            esc_attr($isTest ? 'test' : 'live'),
            $isTest ? '#fcf0c3' : '#d7f0dd',
            $isTest ? '#7a5b00' : '#14532d',
            esc_html($isTest ? __('Test', 'proofage-age-verification') : __('Live', 'proofage-age-verification'))
        );
        echo '</p>';

        if ($isTest) {
            echo '<p class="description">' . esc_html__('Verifications in a test workspace are not real.', 'proofage-age-verification') . '</p>';
        }

        if ($connection['suspended']) {
            echo '<p style="color:#b32d2e;"><strong>' . esc_html__('The workspace is suspended in ProofAge.', 'proofage-age-verification') . '</strong> ' . esc_html__('Protected pages stay locked until it is active again.', 'proofage-age-verification') . '</p>';
        }

        $this->renderWebhookState($connection);

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-proofage-confirm style="margin-top: 12px;">';
        echo '<input type="hidden" name="action" value="proofage_disconnect" />';
        wp_nonce_field(self::NONCE_DISCONNECT);
        submit_button(__('Disconnect', 'proofage-age-verification'), 'secondary', 'submit', false);
        echo '</form>';
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function renderWebhookState(array $connection): void
    {
        $subscriptionId = (string) $connection['subscription_id'];
        $addressChanged = $subscriptionId !== '' && $connection['subscription_url'] !== WebhookSubscriber::webhookUrl();

        if ($subscriptionId !== '' && ! $addressChanged) {
            echo '<p>' . esc_html__('Results: ProofAge sends each decision to this site.', 'proofage-age-verification') . '</p>';

            return;
        }

        if ($addressChanged) {
            $message = __('The address of this site changed, so ProofAge still sends results to the old one. Click Try again to move the subscription.', 'proofage-age-verification');
        } elseif ($connection['webhook_error'] === Connection::WEBHOOK_ERROR_LIMIT) {
            $message = __('The workspace has 50 webhook subscriptions; delete one in the console, then try again.', 'proofage-age-verification');
        } else {
            $message = __('ProofAge cannot reach this site, so results are checked when the visitor comes back.', 'proofage-age-verification');
        }

        echo '<p>' . esc_html($message) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="proofage_retry_webhook" />';
        wp_nonce_field(self::NONCE_RETRY);
        submit_button(__('Try again', 'proofage-age-verification'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private function renderDisconnected(): void
    {
        $waiting = $this->deviceFlow->current(get_current_user_id());

        if (Connection::isLost()) {
            echo '<p><strong>' . esc_html__('ProofAge no longer accepts this site\'s key; it was probably deleted in the ProofAge console. Connect again.', 'proofage-age-verification') . '</strong></p>';
        } else {
            echo '<p>' . esc_html__('Connect this site to your ProofAge workspace. You approve the connection in ProofAge; nothing is copied between the two.', 'proofage-age-verification') . '</p>';
        }

        echo '<p class="description">' . esc_html__('Until the site is connected, protected pages stay locked and visitors see "Age verification is temporarily unavailable."', 'proofage-age-verification') . '</p>';

        echo '<div data-proofage-connect-message role="alert" style="margin: 8px 0;" hidden></div>';

        $this->renderWaitingPanel($waiting);

        printf(
            '<p data-proofage-connect-actions%1$s><button type="button" class="button button-primary" data-proofage-connect-start>%2$s</button></p>',
            $waiting === null ? '' : ' hidden',
            esc_html__('Connect ProofAge', 'proofage-age-verification')
        );
    }

    /**
     * @param  array<string, mixed>|null  $waiting
     */
    private function renderWaitingPanel(?array $waiting): void
    {
        printf(
            '<div data-proofage-connect-waiting style="margin: 12px 0; padding: 12px 16px; border: 1px solid #c3c4c7; border-radius: 6px; background: #f6f7f7;"%1$s%2$s%3$s>',
            $waiting === null ? ' hidden' : '',
            $waiting === null ? '' : ' data-expires-in="' . esc_attr((string) $waiting['expires_in']) . '"',
            $waiting === null ? '' : ' data-interval="' . esc_attr((string) $waiting['interval']) . '"'
        );
        echo '<p><strong>' . esc_html__('Open this page, sign in, and enter the code. Only enter a code you see here.', 'proofage-age-verification') . '</strong></p>';
        printf(
            '<p><code data-proofage-code style="font-size: 28px; letter-spacing: 4px; user-select: all; padding: 4px 12px;">%s</code> <button type="button" class="button button-secondary" data-proofage-copy>%s</button></p>',
            esc_html($waiting === null ? '' : (string) $waiting['user_code']),
            esc_html__('Copy code', 'proofage-age-verification')
        );
        printf(
            '<p><a class="button button-secondary" data-proofage-link target="_blank" rel="noopener noreferrer" href="%1$s">%2$s</a></p>',
            esc_url($waiting === null ? '' : (string) $waiting['verification_uri']),
            esc_html__('Open ProofAge', 'proofage-age-verification')
        );
        echo '<p class="description" data-proofage-countdown></p>';
        printf(
            '<p><button type="button" class="button-link" data-proofage-cancel>%s</button></p>',
            esc_html__('Cancel', 'proofage-age-verification')
        );
        echo '</div>';
    }

    private function renderFlash(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which notice to show.
        $notice = isset($_GET[self::NOTICE_ARGUMENT]) ? sanitize_key(wp_unslash($_GET[self::NOTICE_ARGUMENT])) : '';

        if ($notice === 'disconnected' && ! Connection::isConnected()) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('Disconnected. The key still exists in ProofAge. To revoke it, delete it in the console under the workspace\'s API keys.', 'proofage-age-verification') . '</p></div>';
        }
    }

    private function settingsUrl(): string
    {
        return admin_url('options-general.php?page=' . SettingsRegistrar::PAGE_SLUG);
    }

    private function redirectBack(string $notice): void
    {
        $url = $notice === '' ? $this->settingsUrl() : add_query_arg(self::NOTICE_ARGUMENT, $notice, $this->settingsUrl());

        wp_safe_redirect($url);
        exit;
    }

    private function authorizeAjax(string $nonceAction): void
    {
        check_ajax_referer($nonceAction, 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to connect ProofAge.', 'proofage-age-verification')], 403);
        }
    }

    private function authorizePost(string $nonceAction): void
    {
        check_admin_referer($nonceAction);

        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to change the ProofAge connection.', 'proofage-age-verification'), '', ['response' => 403]);
        }
    }
}
