<?php

/**
 * Runs the plugin's classes without WordPress: defines the plugin constants and the few WordPress
 * functions the API client, the signer and the connection store call.
 */

define('ABSPATH', __DIR__ . '/');
define('PROOFAGE_WP_PLUGIN_VERSION', '0.4.0');

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Autoloader.php';

ProofAge\WordPress\Autoloader::register();

class WP_Error
{
    /**
     * @param  mixed  $data
     */
    public function __construct(private string $code = '', private string $message = '', private $data = '')
    {
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    /**
     * @return mixed
     */
    public function get_error_data()
    {
        return $this->data;
    }
}

/**
 * What the stubs record and answer; tests reset it in setUp().
 */
final class WpStub
{
    /** @var array<string, mixed> */
    public static array $options = [];

    /** @var array<int, array{url: string, args: array<string, mixed>}> */
    public static array $requests = [];

    /** @var array<int, array<string, mixed>|WP_Error> */
    public static array $responses = [];

    public static int $uuidCounter = 0;

    public static function reset(): void
    {
        self::$options = [];
        self::$requests = [];
        self::$responses = [];
        self::$uuidCounter = 0;
    }
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function get_option(string $name, mixed $default = false): mixed
{
    return WpStub::$options[$name] ?? $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    WpStub::$options[$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    unset(WpStub::$options[$name]);

    return true;
}

function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false
{
    return json_encode($data, $flags, $depth);
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

/**
 * @param  array<string, mixed>  $args
 *
 * @return array<string, mixed>|WP_Error
 */
function wp_remote_request(string $url, array $args = []): array|WP_Error
{
    WpStub::$requests[] = ['url' => $url, 'args' => $args];

    return array_shift(WpStub::$responses) ?? ['response' => ['code' => 200], 'body' => '{}'];
}

/**
 * @param  array<string, mixed>  $response
 */
function wp_remote_retrieve_response_code(array $response): int
{
    return (int) ($response['response']['code'] ?? 0);
}

/**
 * @param  array<string, mixed>  $response
 */
function wp_remote_retrieve_body(array $response): string
{
    return (string) ($response['body'] ?? '');
}

function wp_generate_uuid4(): string
{
    return sprintf('00000000-0000-4000-8000-%012d', ++WpStub::$uuidCounter);
}

define('DAY_IN_SECONDS', 86400);

/**
 * @param  array<string, mixed>  $response
 */
function wp_remote_retrieve_header(array $response, string $header): string
{
    return (string) ($response['headers'][strtolower($header)] ?? '');
}
