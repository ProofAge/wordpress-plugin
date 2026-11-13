<?php

namespace ProofAge\WordPress\ProofAge;

if (! defined('ABSPATH')) {
    exit;
}

final class WebhookSignatureVerifier
{
    public function __construct(private readonly int $replayWindowSeconds = 300)
    {
    }

    public function isValid(
        string $timestamp,
        string $rawBody,
        string $providedSignature,
        string $secretKey,
        ?int $currentTime = null,
    ): bool {
        return $this->isFresh($timestamp, $currentTime)
            && $this->matches($timestamp, $rawBody, $providedSignature, $secretKey);
    }

    /**
     * A timestamp that is missing, not a number or outside the replay window.
     */
    public function isFresh(string $timestamp, ?int $currentTime = null): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        $currentTime ??= time();

        return abs($currentTime - (int) $timestamp) <= $this->replayWindowSeconds;
    }

    public function matches(string $timestamp, string $rawBody, string $providedSignature, string $secretKey): bool
    {
        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secretKey);

        return hash_equals($expected, $providedSignature);
    }
}
