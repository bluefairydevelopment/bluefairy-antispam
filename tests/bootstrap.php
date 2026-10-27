<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap — stubs WordPress functions used by the plugin classes.
 *
 * Only functions exercised by the unit-test suite are defined here.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// WordPress function stubs
// ---------------------------------------------------------------------------

if (! function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return false;
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value, bool|string $autoload = true): bool
    {
        return true;
    }
}

if (! function_exists('apply_filters')) {
    /** @param mixed ...$args */
    function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
    {
        return $value;
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
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        return false;
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed { return $value; }
}

if (! function_exists('sanitize_email')) {
    function sanitize_email(string $email): string { return trim($email); }
}
