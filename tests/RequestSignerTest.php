<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProofAge\WordPress\ProofAge\RequestSigner;

final class RequestSignerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/hmac-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function timestampedVectors(): array
    {
        $cases = [];

        foreach (self::vectors()['timestamped'] as $vector) {
            // The plugin never sends a query string, so its signer takes only a path.
            if (isset($vector['query'])) {
                continue;
            }

            $cases[$vector['form'] . ': ' . $vector['name']] = [$vector];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function legacyJsonVectors(): array
    {
        $cases = [];

        foreach (self::vectors()['json'] as $vector) {
            if (isset($vector['query'])) {
                continue;
            }

            $cases[$vector['name']] = [$vector];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function legacyMultipartVectors(): array
    {
        $cases = [];

        foreach (self::vectors()['multipart'] as $vector) {
            $cases[$vector['name']] = [$vector];
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $vector
     */
    #[DataProvider('timestampedVectors')]
    public function testTimestampedVectors(array $vector): void
    {
        $signer = new RequestSigner(self::vectors()['secret']);

        if ($vector['form'] === 'json') {
            $this->assertSame($vector['canonical'], $vector['timestamp'] . '.' . strtoupper($vector['method']) . $vector['path'] . $vector['body']);
            $signature = $signer->signTimestamped($vector['timestamp'], $vector['method'], $vector['path'], $vector['body']);
        } else {
            $hashes = array_map(static fn (array $file): string => $file['sha256'], $vector['files']);
            $signature = $signer->signMultipartTimestamped($vector['timestamp'], $vector['method'], $vector['path'], $vector['fields'], $hashes);
        }

        $this->assertSame($vector['expected'], $signature);
    }

    /**
     * @param  array<string, mixed>  $vector
     */
    #[DataProvider('legacyJsonVectors')]
    public function testLegacyJsonSigningStillWorks(array $vector): void
    {
        $signer = new RequestSigner(self::vectors()['secret']);

        $this->assertSame($vector['expected'], $signer->sign($vector['method'], $vector['path'], $vector['body']));
    }

    /**
     * @param  array<string, mixed>  $vector
     */
    #[DataProvider('legacyMultipartVectors')]
    public function testLegacyMultipartSigningStillWorks(array $vector): void
    {
        $signer = new RequestSigner(self::vectors()['secret']);
        $hashes = array_map(static fn (array $file): string => $file['sha256'], $vector['files']);

        $this->assertSame($vector['expected'], $signer->signMultipart($vector['method'], $vector['path'], $vector['fields'], $hashes));
    }
}
