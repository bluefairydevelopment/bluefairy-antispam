<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap — stubs WordPress functions used by the plugin classes.
 *
 * Only functions exercised by the unit-test suite are defined here.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
if (! defined('BFAS_URL')) {
    define('BFAS_URL', 'https://example.test/wp-content/plugins/bluefairy-antispam/');
}
if (! defined('BFAS_VERSION')) {
    define('BFAS_VERSION', 'test');
}

// WordPress function stubs
// ---------------------------------------------------------------------------

if (! function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return (bool) ($GLOBALS['bfas_test_logged_in'] ?? false);
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        $options = $GLOBALS['bfas_test_options'] ?? [];
        return array_key_exists($option, $options) ? $options[$option] : $default;
    }
}

if (! function_exists('add_option')) {
    // Records every call, $autoload as given (core accepts bool|null; 'yes'/'no' are legacy).
    function add_option(string $option, mixed $value = '', string $deprecated = '', bool|string|null $autoload = null): bool
    {
        $GLOBALS['bfas_test_add_options'][$option] = ['value' => $value, 'autoload' => $autoload];
        if (! empty($GLOBALS['bfas_test_options_read_only'])) {
            return false;
        }
        // Like core: an existing option is never overwritten, and the call fails.
        if (array_key_exists($option, $GLOBALS['bfas_test_options'] ?? [])) {
            return false;
        }
        $GLOBALS['bfas_test_options'][$option] = $value;
        return true;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value, bool|string|null $autoload = null): bool
    {
        if (! empty($GLOBALS['bfas_test_options_read_only'])) {
            return false;
        }
        $GLOBALS['bfas_test_update_options'][$option] = ['value' => $value, 'autoload' => $autoload];
        $GLOBALS['bfas_test_options'][$option]        = $value;
        return true;
    }
}

if (! function_exists('apply_filters')) {
    /** @param mixed ...$args */
    function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
    {
        $filter = $GLOBALS['bfas_test_filters'][$tag] ?? null;
        return is_callable($filter) ? $filter($value, ...$args) : $value;
    }
}

if (! function_exists('do_action')) {
    /** @param mixed ...$args */
    function do_action(string $tag, mixed ...$args): void {}
}

if (! function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $special = true, bool $extra_special = false): string
    {
        return bin2hex(random_bytes((int) ceil($length / 2)));
    }
}

if (! function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = ''): string
    {
        return substr(md5($action . time()), 0, 10);
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string { return $text; }
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string { return $text; }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string { return trim($str); }
}

if (! function_exists('absint')) {
    function absint(mixed $maybeint): int { return abs((int) $maybeint); }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $cap): bool
    {
        return (bool) ($GLOBALS['bfas_test_user_can'] ?? false);
    }
}

if (! function_exists('wp_enqueue_style')) {
    /** @param array<int, string> $deps */
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, string $media = 'all'): void
    {
        $GLOBALS['bfas_test_enqueued_styles'][] = compact('handle', 'src', 'ver');
    }
}

if (! function_exists('wp_register_ability')) {
    /** @param array<string, mixed> $args */
    function wp_register_ability(string $name, array $args): void
    {
        $GLOBALS['bfas_test_registered_abilities'][$name] = $args;
    }
}

if (! function_exists('wp_register_ability_category')) {
    /** @param array<string, mixed> $args */
    function wp_register_ability_category(string $name, array $args): void
    {
        $GLOBALS['bfas_test_registered_ability_categories'][$name] = $args;
    }
}

// ---------------------------------------------------------------------------
// Hooks, posts, REST and comment refusal (WordPressIntegrationTest)
// ---------------------------------------------------------------------------

if (! function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['bfas_test_hooks'][$hook][] = $callback;
        return true;
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['bfas_test_hooks'][$hook][] = $callback;
        return true;
    }
}

if (! function_exists('get_post')) {
    function get_post(mixed $post = null): ?object
    {
        return $GLOBALS['bfas_test_posts'][(int) $post] ?? null;
    }
}

if (! function_exists('wp_is_serving_rest_request')) {
    function wp_is_serving_rest_request(): bool
    {
        return (bool) ($GLOBALS['bfas_test_rest'] ?? false);
    }
}

if (! class_exists('BfasTestDied')) {
    /** Thrown by the wp_die() stub so a test can see the request was stopped. */
    final class BfasTestDied extends \RuntimeException {}
}

if (! function_exists('wp_die')) {
    /** @param array<string, mixed> $args */
    function wp_die(string $message = '', string $title = '', array $args = []): never
    {
        throw new BfasTestDied($message);
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        /** @param mixed $data */
        public function __construct(public string $code = '', public string $message = '', public mixed $data = null) {}

        public function get_error_code(): string { return $this->code; }

        public function get_error_message(): string { return $this->message; }

        public function get_error_data(): mixed { return $this->data; }

        public function add(string $code, string $message): void
        {
            if ('' === $this->code) {
                $this->code    = $code;
                $this->message = $message;
            }
        }

        public function has_errors(): bool { return '' !== $this->code; }
    }
}

if (! class_exists('WP_REST_Request')) {
    /**
     * Minimal stand-in for core's request: params as one merged array, as
     * WP_REST_Request::get_params() returns body, JSON and query values.
     *
     * @template T of array
     */
    class WP_REST_Request
    {
        /** @param array<string, mixed> $params */
        public function __construct(private array $params = []) {}

        /** @return array<string, mixed> */
        public function get_params(): array { return $this->params; }

        public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        return $GLOBALS['bfas_test_transients'][$transient] ?? false;
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed { return $value; }
}

if (! function_exists('sanitize_email')) {
    function sanitize_email(string $email): string { return trim($email); }
}

if (! function_exists('wp_enqueue_script')) {
    /** @param string[] $deps */
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, array|bool $args = []): void
    {
        $GLOBALS['bfas_test_enqueued_scripts'][] = $handle;
    }
}

if (! function_exists('rest_url')) {
    function rest_url(string $path = ''): string { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
}

if (! function_exists('esc_url')) {
    function esc_url(string $url): string { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool { return $thing instanceof WP_Error; }
}

if (! class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /** @param array<string, string> $headers */
        public function __construct(public mixed $data = null, public int $status = 200, public array $headers = []) {}

        public function get_data(): mixed { return $this->data; }

        /** @return array<string, string> */
        public function get_headers(): array { return $this->headers; }

        public function header(string $key, string $value): void { $this->headers[$key] = $value; }
    }
}

if (! function_exists('register_rest_route')) {
    /** @param array<string, mixed> $args */
    function register_rest_route(string $namespace, string $route, array $args = []): bool
    {
        $GLOBALS['bfas_test_routes'][$namespace . $route] = $args;
        return true;
    }
}

if (! function_exists('wp_add_inline_script')) {
    function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
    {
        $GLOBALS['bfas_test_inline_scripts'][$handle][] = $data;
        return true;
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data): string|false { return json_encode($data); }
}
