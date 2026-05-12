<?php

namespace ProofAge\WordPress\Http;

use ProofAge\WordPress\ProofAge\ApiClient;
use ProofAge\WordPress\Verification\ApprovalMatcher;
use ProofAge\WordPress\Verification\SessionManager;

if (! defined('ABSPATH')) {
    exit;
}

final class ReturnController
{
    private const RETURN_SCRIPT_HANDLE = 'proofage-age-verification-return';

    public function __construct(
        private readonly SessionManager $sessionManager,
        private readonly ApiClient $apiClient,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('template_redirect', [$this, 'maybeHandle']);
    }

    public function maybeHandle(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external return requests cannot carry a WordPress nonce.
        if (! isset($_GET['proofage-return'])) {
            return;
        }

        $state = $this->sessionManager->getState();
        $verificationId = (string) ($state['verification_id'] ?? '');
        $origin = '';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external return requests cannot carry a WordPress nonce.
        if (isset($_GET['origin'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- external return requests cannot carry a WordPress nonce and the value is unslashed and sanitized before use.
            $origin = esc_url_raw(rawurldecode((string) wp_unslash($_GET['origin'])));
        }

        $originReturnUrl = $this->sessionManager->resolveReturnUrl(
            $origin !== '' ? $origin : (string) ($state['return_url'] ?? '')
        );

        if ($verificationId !== '' && ! $this->sessionManager->isVerified()) {
            $response = $this->apiClient->getVerification($verificationId);

            if (
                ! is_wp_error($response)
                && (($response['data']['status'] ?? '') === 'approved')
                && ApprovalMatcher::matches($state, $verificationId, (string) ($response['data']['external_id'] ?? ''))
            ) {
                $this->sessionManager->markApproved($verificationId, [
                    'external_id' => (string) ($response['data']['external_id'] ?? ''),
                    'session_token' => (string) ($state['session_token'] ?? ''),
                    'return_url' => $originReturnUrl,
                ]);
            } elseif (! is_wp_error($response) && $this->isTerminalFailureStatus((string) ($response['data']['status'] ?? ''))) {
                $this->sessionManager->markFailed(
                    $verificationId,
                    (string) ($response['data']['status'] ?? 'failed'),
                    $originReturnUrl
                );
            }
        }

        $finalState = $this->sessionManager->getState();
        $status = $this->sessionManager->isVerified()
            ? 'approved'
            : $this->normalizeBrowserStatus((string) ($finalState['status'] ?? 'pending'));
        $redirectUrl = add_query_arg('proofage_status', $status, $originReturnUrl);

        nocache_headers();
        status_header(200);
        $this->enqueueReturnScript($status, $redirectUrl);

        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php esc_html_e('Verification complete', 'proofage-age-verification'); ?></title>
        </head>
        <body>
            <p><?php esc_html_e('Returning to the store…', 'proofage-age-verification'); ?></p>
            <?php wp_print_scripts(self::RETURN_SCRIPT_HANDLE); ?>
        </body>
        </html>
        <?php

        exit;
    }

    private function enqueueReturnScript(string $status, string $redirectUrl): void
    {
        wp_register_script(
            self::RETURN_SCRIPT_HANDLE,
            PROOFAGE_WP_PLUGIN_URL . 'assets/js/return-handler.js',
            [],
            PROOFAGE_WP_PLUGIN_VERSION,
            false
        );

        wp_add_inline_script(
            self::RETURN_SCRIPT_HANDLE,
            'window.ProofAgeReturnPage = ' . wp_json_encode([
                'status' => $status,
                'redirectUrl' => $redirectUrl,
            ]) . ';',
            'before'
        );

        wp_enqueue_script(self::RETURN_SCRIPT_HANDLE);
    }

    private function isTerminalFailureStatus(string $status): bool
    {
        return in_array($status, ['declined', 'resubmission_requested', 'abandoned', 'expired', 'failed'], true);
    }

    private function normalizeBrowserStatus(string $status): string
    {
        return $status === 'review' ? 'pending' : $status;
    }
}
