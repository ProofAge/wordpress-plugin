<?php

namespace ProofAge\WordPress\Connect;

use ProofAge\WordPress\ProofAge\ApiClient;
use ProofAge\WordPress\Support\Connection;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Makes sure ProofAge delivers decisions to this site, with a subscription the site made itself.
 */
final class WebhookSubscriber
{
    public function __construct(private readonly ApiClient $apiClient)
    {
    }

    public static function webhookUrl(): string
    {
        return rest_url('proofage/v1/webhook');
    }

    /**
     * Replaces any subscription to this site's webhook URL with a new one, signed with the stored
     * key. The list does not say which key made a subscription, so one this site did not just
     * create may be signed with a key it does not hold.
     *
     * The connection counts as made whatever happens here. The outcome is stored: the id of the
     * subscription, or the reason there is none.
     */
    public function ensure(): bool
    {
        $url = self::webhookUrl();
        $storedId = Connection::all()['subscription_id'];

        // The site address changed: the old subscription points at an address that is not ours any more.
        if ($storedId !== '' && Connection::all()['subscription_url'] !== $url) {
            $this->deleteById($storedId);
            Connection::clearSubscription();
        }

        $subscriptions = $this->apiClient->listWebhookSubscriptions();

        if (is_wp_error($subscriptions)) {
            return $this->fail(Connection::WEBHOOK_ERROR_UNREACHABLE);
        }

        foreach ($subscriptions as $subscription) {
            if (($subscription['url'] ?? null) !== $url) {
                continue;
            }

            $deleted = $this->deleteById((string) ($subscription['id'] ?? ''));

            if (is_wp_error($deleted) && $this->statusOf($deleted) !== 404) {
                return $this->fail(Connection::WEBHOOK_ERROR_UNREACHABLE);
            }
        }

        $created = $this->apiClient->createWebhookSubscription($url);

        if (is_wp_error($created)) {
            return $this->fail($this->isLimit($created) ? Connection::WEBHOOK_ERROR_LIMIT : Connection::WEBHOOK_ERROR_UNREACHABLE);
        }

        $id = (string) ($created['data']['id'] ?? '');

        if ($id === '') {
            return $this->fail(Connection::WEBHOOK_ERROR_UNREACHABLE);
        }

        Connection::setSubscription($id, $url);

        return true;
    }

    /**
     * Deletes the stored subscription, if any. A `404` means it is already gone; any other
     * failure is ignored, because the caller is disconnecting anyway.
     */
    public function remove(int $timeout = 15): void
    {
        $id = Connection::all()['subscription_id'];

        if ($id !== '') {
            $this->apiClient->deleteWebhookSubscription($id, $timeout);
        }
    }

    private function deleteById(string $id): array|WP_Error
    {
        if ($id === '') {
            return ['data' => []];
        }

        return $this->apiClient->deleteWebhookSubscription($id);
    }

    /**
     * The stored subscription may have been deleted on the way here, so none is kept.
     */
    private function fail(string $error): bool
    {
        Connection::clearSubscription();
        Connection::setWebhookError($error);

        return false;
    }

    private function isLimit(WP_Error $error): bool
    {
        $data = $error->get_error_data();
        $response = is_array($data) ? ($data['response'] ?? null) : null;

        return is_array($response) && is_array($response['error'] ?? null)
            && ($response['error']['code'] ?? '') === 'WEBHOOK_SUBSCRIPTION_LIMIT';
    }

    private function statusOf(WP_Error $error): int
    {
        $data = $error->get_error_data();

        return is_array($data) ? (int) ($data['status'] ?? 0) : 0;
    }
}
