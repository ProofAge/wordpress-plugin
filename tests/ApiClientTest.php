<?php

use PHPUnit\Framework\TestCase;
use ProofAge\WordPress\ProofAge\ApiClient;
use ProofAge\WordPress\ProofAge\RequestSigner;
use ProofAge\WordPress\Support\Connection;

final class ApiClientTest extends TestCase
{
    private const SECRET = 'sk_test_secret';

    protected function setUp(): void
    {
        WpStub::reset();
        WpStub::$options[Connection::OPTION_KEY] = ['api_key' => 'pk_test_key', 'secret_key' => self::SECRET];
    }

    private function client(int $time = 1795049078): ApiClient
    {
        return new ApiClient(static fn (): int => $time);
    }

    /**
     * @return array<string, string>
     */
    private function headersOf(int $index): array
    {
        return WpStub::$requests[$index]['args']['headers'];
    }

    public function testEveryRequestCarriesATimestampAndAMatchingSignature(): void
    {
        $this->client()->getVerification('abc');

        $headers = $this->headersOf(0);
        $signer = new RequestSigner(self::SECRET);

        $this->assertSame('1795049078', $headers['X-Timestamp']);
        $this->assertSame($signer->signTimestamped('1795049078', 'GET', '/v1/verifications/abc'), $headers['X-HMAC-Signature']);
    }

    public function testSignatureCoversTheJsonBody(): void
    {
        $this->client()->createVerification('order-1', 'https://shop.test/return');

        $request = WpStub::$requests[0];
        $expected = (new RequestSigner(self::SECRET))->signTimestamped('1795049078', 'POST', '/v1/verifications', $request['args']['body']);

        $this->assertSame($expected, $request['args']['headers']['X-HMAC-Signature']);
    }

    public function testEachRequestGetsItsOwnTimestamp(): void
    {
        $time = 100;
        $client = new ApiClient(static function () use (&$time): int {
            return $time++;
        });

        $client->getConsent();
        $client->getConsent();

        $this->assertSame('100', $this->headersOf(0)['X-Timestamp']);
        $this->assertSame('101', $this->headersOf(1)['X-Timestamp']);
        $this->assertNotSame($this->headersOf(0)['X-HMAC-Signature'], $this->headersOf(1)['X-HMAC-Signature']);
    }

    public function testCreatesCarryAnIdempotencyKey(): void
    {
        $client = $this->client();
        $client->createVerification('order-1', 'https://shop.test/return');
        $client->createWebhookSubscription('https://shop.test/webhook');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->headersOf(0)['Idempotency-Key']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->headersOf(1)['Idempotency-Key']);
        $this->assertNotSame($this->headersOf(0)['Idempotency-Key'], $this->headersOf(1)['Idempotency-Key']);
    }

    public function testCallerKeyIsUsedAsGiven(): void
    {
        $this->client()->createVerification('order-1', 'https://shop.test/return', [], [], 'my-key-1');

        $this->assertSame('my-key-1', $this->headersOf(0)['Idempotency-Key']);
    }

    public function testOtherCallsDoNotCarryAnIdempotencyKey(): void
    {
        $client = $this->client();
        $client->getVerification('abc');
        $client->getConsent();
        $client->listWebhookSubscriptions();
        $client->deleteWebhookSubscription('sub-1');

        foreach (WpStub::$requests as $index => $request) {
            $this->assertArrayNotHasKey('Idempotency-Key', $this->headersOf($index));
        }
    }

    public function testTheConfirmingCallAfterA401IsSignedAgain(): void
    {
        $unauthorized = ['response' => ['code' => 401], 'body' => '{"error":{"code":"INVALID_SIGNATURE","message":"no"}}'];
        WpStub::$responses = [$unauthorized, $unauthorized];
        $time = 500;
        $client = new ApiClient(static function () use (&$time): int {
            return $time++;
        });

        $client->getVerification('abc');

        $this->assertCount(2, WpStub::$requests);
        $this->assertSame('500', $this->headersOf(0)['X-Timestamp']);
        $this->assertSame('501', $this->headersOf(1)['X-Timestamp']);
        $this->assertSame(
            (new RequestSigner(self::SECRET))->signTimestamped('501', 'GET', '/v1/consent'),
            $this->headersOf(1)['X-HMAC-Signature']
        );
    }

    public function testAReplayedCreateIsResentOnceWithANewSignatureAndTheSameKey(): void
    {
        WpStub::$responses = [
            ['response' => ['code' => 401], 'body' => '{"error":{"code":"REPLAYED_REQUEST","message":"replayed"}}'],
            ['response' => ['code' => 201], 'body' => '{"id":"v1"}'],
        ];
        $time = 700;
        $slept = [];
        $client = new ApiClient(
            static function () use (&$time): int {
                return $time++;
            },
            static function (int $seconds) use (&$slept): void {
                $slept[] = $seconds;
            }
        );

        $response = $client->createVerification('order-1', 'https://shop.test/return');

        $this->assertFalse(is_wp_error($response));
        $this->assertCount(2, WpStub::$requests);
        $this->assertSame([1], $slept);
        $this->assertNotSame($this->headersOf(0)['X-Timestamp'], $this->headersOf(1)['X-Timestamp']);
        $this->assertNotSame($this->headersOf(0)['X-HMAC-Signature'], $this->headersOf(1)['X-HMAC-Signature']);
        $this->assertSame($this->headersOf(0)['Idempotency-Key'], $this->headersOf(1)['Idempotency-Key']);
    }

    public function testASecondReplayedAnswerSurfacesAsAnError(): void
    {
        $replayed = ['response' => ['code' => 401], 'body' => '{"error":{"code":"REPLAYED_REQUEST","message":"replayed"}}'];
        WpStub::$responses = [$replayed, $replayed];
        $client = new ApiClient(static fn (): int => 900, static function (int $seconds): void {
        });

        $response = $client->createVerification('order-1', 'https://shop.test/return');

        $this->assertTrue(is_wp_error($response));
        $this->assertSame('proofage_api_error', $response->get_error_code());
        // Two sends of the create, then the existing 401 confirmation (a GET) which answers 200.
        $this->assertSame('POST', WpStub::$requests[0]['args']['method']);
        $this->assertSame('POST', WpStub::$requests[1]['args']['method']);
        $this->assertCount(3, WpStub::$requests);
    }

    public function testAReplayedGetIsNotResent(): void
    {
        WpStub::$responses = [['response' => ['code' => 401], 'body' => '{"error":{"code":"REPLAYED_REQUEST","message":"replayed"}}']];
        $slept = 0;
        $client = new ApiClient(static fn (): int => 900, static function (int $seconds) use (&$slept): void {
            $slept++;
        });

        $client->getVerification('abc');

        $this->assertSame(0, $slept);
        $this->assertSame('/v1/consent', substr(WpStub::$requests[1]['url'], -11));
    }
}
