<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Tests\Unit;

use BlueFairy\AntiSpam\Settings;
use BlueFairy\AntiSpam\Traps\TimeTrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimeTrap::class)]
final class TimeTrapTest extends TestCase
{
    /** @var Settings&MockObject */
    private Settings $settings;
    private TimeTrap $trap;
    private string $hmac_secret;

    protected function setUp(): void
    {
        $this->hmac_secret = bin2hex(random_bytes(32));

        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('get')->willReturnMap([
            ['timetrap_enabled', true, true],
            ['min_time_seconds', 3,    3],
        ]);
        $this->settings->method('get_hmac_secret')->willReturn($this->hmac_secret);

        $this->trap = new TimeTrap($this->settings);
    }

    // ------------------------------------------------------------------ //
    // Missing token
    // ------------------------------------------------------------------ //

    public function test_missing_token_is_spam(): void
    {
        $this->assertTrue($this->trap->is_spam([], 'comment'));
    }

    public function test_empty_token_is_spam(): void
    {
        $post = ['bfas_tk_comment' => ''];
        $this->assertTrue($this->trap->is_spam($post, 'comment'));
    }

    // ------------------------------------------------------------------ //
    // Tampered / malformed tokens
    // ------------------------------------------------------------------ //

    public function test_tampered_hmac_is_spam(): void
    {
        $post = ['bfas_tk_comment' => base64_encode('nonce|12345|badhmacsignature')];
        $this->assertTrue($this->trap->is_spam($post, 'comment'));
    }

    public function test_malformed_base64_is_spam(): void
    {
        // base64_decode returns false on invalid input.
        $post = ['bfas_tk_comment' => '!!!not-base64!!!'];
        $this->assertTrue($this->trap->is_spam($post, 'comment'));
    }

    public function test_incomplete_token_parts_is_spam(): void
    {
        // Only two pipe-separated parts — verify_token expects 3.
        $post = ['bfas_tk_comment' => base64_encode('nonce|12345')];
        $this->assertTrue($this->trap->is_spam($post, 'comment'));
    }

    // ------------------------------------------------------------------ //
    // Time window violations
    // ------------------------------------------------------------------ //

    public function test_submission_below_min_time_is_spam(): void
    {
        $token = $this->build_valid_token('comment', elapsed: 1); // under 3 s min
        $post  = ['bfas_tk_comment' => $token];
        $this->assertTrue($this->trap->is_spam($post, 'comment'));
    }

    public function test_expired_token_is_spam(): void
    {
        $token = $this->build_valid_token('comment', elapsed: 90000); // > 86400 s
        $post  = ['bfas_tk_comment' => $token];
        $this->assertTrue($this->trap->is_spam($post, 'comment'));
    }

    // ------------------------------------------------------------------ //
    // Valid token passes
    // ------------------------------------------------------------------ //

    public function test_valid_token_is_not_spam(): void
    {
        $token = $this->build_valid_token('comment', elapsed: 5);
        $post  = ['bfas_tk_comment' => $token];
        $this->assertFalse($this->trap->is_spam($post, 'comment'));
    }

    public function test_token_at_minimum_time_exactly_passes(): void
    {
        // elapsed == min_time_seconds (3) — boundary: 3 >= 3 → not spam.
        $token = $this->build_valid_token('comment', elapsed: 3);
        $post  = ['bfas_tk_comment' => $token];
        $this->assertFalse($this->trap->is_spam($post, 'comment'));
    }

    public function test_token_is_form_scoped(): void
    {
        // Token built for 'comment' fails validation for 'contact' (different form_type in HMAC).
        $token = $this->build_valid_token('comment', elapsed: 5);
        $post  = ['bfas_tk_contact' => $token];
        $this->assertTrue($this->trap->is_spam($post, 'contact'));
    }

    // ------------------------------------------------------------------ //
    // Disabled
    // ------------------------------------------------------------------ //

    public function test_timetrap_disabled_returns_not_spam(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnMap([
            ['timetrap_enabled', true, false],
            ['min_time_seconds', 3,    3],
        ]);
        $settings->method('get_hmac_secret')->willReturn($this->hmac_secret);

        $trap = new TimeTrap($settings);
        // No token at all — but trap is disabled so not spam.
        $this->assertFalse($trap->is_spam([], 'comment'));
    }


    // ------------------------------------------------------------------ //
    // Same signature as 1.1.4: pages opened or cached before an upgrade
    // ------------------------------------------------------------------ //

    /**
     * A token built exactly as 1.1.4's render_fields() built it (the code is
     * copied from the v1.1.4 tag) still verifies after the upgrade.
     */
    public function test_a_token_minted_by_1_1_4_verifies(): void
    {
        $form_type = 'comment';
        $nonce     = 'abc123def0';
        $timestamp = time() - 10;
        $hmac      = hash_hmac(
            'sha256',
            $nonce . ':' . $timestamp . ':' . $form_type,
            $this->hmac_secret
        );
        $token = base64_encode($nonce . '|' . $timestamp . '|' . $hmac);

        $this->assertFalse($this->trap->is_spam(['bfas_tk_comment' => $token], 'comment'));
    }

    /**
     * Tokens are not tied to the visitor (WPMAP-219 tracks replay protection),
     * so a cached page keeps working for every visitor, wherever they are.
     */
    public function test_a_token_is_accepted_from_any_address(): void
    {
        $token = $this->build_valid_token('comment', elapsed: 10);
        foreach (['203.0.113.9', '198.51.100.9', '2001:db8::1', ''] as $addr) {
            $_SERVER['REMOTE_ADDR'] = $addr;
            $this->assertFalse($this->trap->is_spam(['bfas_tk_comment' => $token], 'comment'), $addr);
        }
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_a_minted_token_is_timed_from_now(): void
    {
        $token = $this->trap->mint('comment');
        $this->assertNotSame('', $token);
        // Too fast straight away, which proves it is a live token timed from now.
        $this->assertTrue($this->trap->is_spam(['bfas_tk_comment' => $token], 'comment'));
    }

    public function test_render_prints_only_the_token_field(): void
    {
        $GLOBALS['bfas_test_enqueued_scripts'] = [];
        ob_start();
        $this->trap->render_fields('comment');
        $html = (string) ob_get_clean();
        $this->assertMatchesRegularExpression('/^<input type="hidden" name="bfas_tk_comment" value="[A-Za-z0-9+\/=]+">$/', $html);
        $this->assertSame([], $GLOBALS['bfas_test_enqueued_scripts']);
        unset($GLOBALS['bfas_test_enqueued_scripts']);
    }

    // ------------------------------------------------------------------ //
    // No secret (review M2) and array input (review L3)
    // ------------------------------------------------------------------ //

    public function test_with_no_secret_every_guest_is_refused_and_nothing_is_minted(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnMap([
            ['timetrap_enabled', true, true],
            ['min_time_seconds', 3,    3],
        ]);
        $settings->method('get_hmac_secret')->willReturn('');
        $trap = new TimeTrap($settings);

        // A token "signed" with the empty key would otherwise verify.
        $ts    = time() - 10;
        $forged = base64_encode('n|' . $ts . '|' . hash_hmac('sha256', 'n:' . $ts . ':comment', ''));
        $this->assertTrue($trap->is_spam(['bfas_tk_comment' => $forged], 'comment'));
        $this->assertSame('', $trap->mint('comment'));
    }

    public function test_an_array_token_is_spam_without_a_warning(): void
    {
        set_error_handler(static function (int $no, string $msg): never { throw new \ErrorException($msg, 0, $no); });
        try {
            $this->assertTrue($this->trap->is_spam(['bfas_tk_comment' => ['x']], 'comment'));
        } finally {
            restore_error_handler();
        }
    }


    // ------------------------------------------------------------------ //
    // Single use (WPMAP-219)
    // ------------------------------------------------------------------ //

    private function reset_state(): void
    {
        $GLOBALS['bfas_test_options'] = array_filter(
            $GLOBALS['bfas_test_options'] ?? [],
            static fn (string $name): bool => ! str_starts_with($name, TimeTrap::USED_PREFIX),
            ARRAY_FILTER_USE_KEY
        );
        unset($GLOBALS['bfas_test_logged_in'], $GLOBALS['bfas_test_routes'], $GLOBALS['bfas_test_inline_scripts'], $GLOBALS['bfas_test_enqueued_scripts']);
    }

    public function test_two_tokens_minted_in_the_same_second_differ(): void
    {
        // A guest's wp_create_nonce() is the same for every visitor for 12 hours,
        // so two pages rendered in one second carried one token.
        $this->assertNotSame($this->trap->mint('comment'), $this->trap->mint('comment'));
    }

    public function test_a_token_is_accepted_once_and_its_second_use_is_a_replay(): void
    {
        $this->reset_state();
        $post = ['bfas_tk_comment' => $this->build_valid_token('comment', 10)];

        $this->assertFalse($this->trap->is_spam($post, 'comment'));
        $this->assertTrue($this->trap->consume($post, 'comment'), 'first use');
        $this->assertFalse($this->trap->is_spam($post, 'comment'), 'a replay is not refused by is_spam()');
        $this->assertFalse((new TimeTrap($this->settings))->consume($post, 'comment'), 'second use, in a later request');
    }

    public function test_one_submission_checked_twice_in_one_request_stays_first(): void
    {
        // Core runs pre_comment_approved in wp_allow_comment() and again in
        // wp_check_comment_data() for the same comment.
        $this->reset_state();
        $post = ['bfas_tk_comment' => $this->build_valid_token('comment', 10)];
        $this->assertTrue($this->trap->consume($post, 'comment'));
        $this->assertTrue($this->trap->consume($post, 'comment'), 'same request');

        $next_request = new TimeTrap($this->settings);
        $this->assertFalse($next_request->consume($post, 'comment'), 'a later request is a replay');
    }

    public function test_the_used_record_expires_with_the_token_and_is_not_autoloaded(): void
    {
        $this->reset_state();
        $token = $this->build_valid_token('comment', 10);
        $this->trap->consume(['bfas_tk_comment' => $token], 'comment');

        $records = array_filter(
            $GLOBALS['bfas_test_add_options'],
            static fn (string $name): bool => str_starts_with($name, TimeTrap::USED_PREFIX),
            ARRAY_FILTER_USE_KEY
        );
        $record = end($records);
        $this->assertSame(false, $record['autoload']);
        $ts = (int) explode('|', (string) base64_decode($token))[1];
        $this->assertSame((string) ($ts + TimeTrap::LIFETIME), $record['value']);
    }

    public function test_the_same_token_on_another_form_is_recorded_separately(): void
    {
        $this->reset_state();
        $token = $this->build_valid_token('comment', 10);
        $this->assertTrue($this->trap->consume(['bfas_tk_comment' => $token], 'comment'));
        $this->assertTrue($this->trap->consume(['bfas_tk_wc_review' => $token], 'wc_review'));
    }

    public function test_nothing_is_recorded_without_a_token_when_logged_in_or_when_disabled(): void
    {
        $this->reset_state();
        $this->assertTrue($this->trap->consume([], 'comment'));
        $this->assertTrue($this->trap->consume(['bfas_tk_comment' => ['x']], 'comment'));
        $this->assertTrue($this->trap->consume(['bfas_tk_comment' => base64_encode('only|two')], 'comment'));

        $post = ['bfas_tk_comment' => $this->build_valid_token('comment', 10)];
        $GLOBALS['bfas_test_logged_in'] = true;
        $this->assertTrue($this->trap->consume($post, 'comment'));
        $this->assertTrue($this->trap->consume($post, 'comment'));
        unset($GLOBALS['bfas_test_logged_in']);

        $off = $this->createMock(Settings::class);
        $off->method('get')->willReturnMap([['timetrap_enabled', true, false]]);
        $off->method('get_hmac_secret')->willReturn($this->hmac_secret);
        $trap = new TimeTrap($off);
        $this->assertTrue($trap->consume($post, 'comment'));
        $this->assertTrue($trap->consume($post, 'comment'));
    }

    public function test_purge_deletes_only_expired_used_records(): void
    {
        $wpdb = new class {
            public string $options = 'wp_options';
            /** @var list<string> */
            public array $queries = [];
            public function esc_like(string $text): string { return addcslashes($text, '_%\\'); }
            public function prepare(string $query, mixed ...$args): string
            {
                return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $query), $args);
            }
            public function query(string $query): int { $this->queries[] = $query; return 0; }
        };
        $GLOBALS['wpdb'] = $wpdb;
        try {
            TimeTrap::purge_used();
        } finally {
            unset($GLOBALS['wpdb']);
        }
        $this->assertCount(1, $wpdb->queries);
        $this->assertStringContainsString("`wp_options`", $wpdb->queries[0]);
        $this->assertStringContainsString("LIKE 'bfas\\_tt\\_used\\_%'", $wpdb->queries[0]);
        $this->assertMatchesRegularExpression('/CAST\(`option_value` AS UNSIGNED\) < \d{10}/', $wpdb->queries[0]);
    }

    // ------------------------------------------------------------------ //
    // Fresh token on page load (WPMAP-219)
    // ------------------------------------------------------------------ //

    public function test_the_token_route_is_public_get_and_limits_the_form_name(): void
    {
        $this->reset_state();
        $this->trap->register_route();
        $route = $GLOBALS['bfas_test_routes']['bfas/v1/token'];
        $this->assertSame('GET', $route['methods']);
        $this->assertSame('__return_true', $route['permission_callback']);
        $this->assertSame('^[a-z0-9_]{1,40}$', $route['args']['form']['pattern']);
        $this->assertSame([$this->trap, 'rest_token'], $route['callback']);
    }

    public function test_the_route_answers_a_fresh_verifiable_token_that_is_never_cached(): void
    {
        $response = $this->trap->rest_token(new \WP_REST_Request(['form' => 'comment']));
        $token    = $response->get_data()['token'];
        $this->assertNotSame('', $token);
        $this->assertSame('no-store, max-age=0', $response->get_headers()['Cache-Control']);
        $this->assertTrue($this->trap->is_spam(['bfas_tk_comment' => $token], 'comment'), 'fresh: too fast');
        $this->assertTrue($this->trap->is_spam(['bfas_tk_wp_register' => $token], 'wp_register'), 'scoped to its form');
    }

    public function test_the_route_answers_no_token_when_the_trap_is_off(): void
    {
        $off = $this->createMock(Settings::class);
        $off->method('get')->willReturnMap([['timetrap_enabled', true, false]]);
        $off->method('get_hmac_secret')->willReturn($this->hmac_secret);
        $response = (new TimeTrap($off))->rest_token(new \WP_REST_Request(['form' => 'comment']));
        $this->assertSame(['token' => ''], $response->get_data());
    }

    public function test_the_refresh_script_is_enqueued_with_the_route_url_only_when_the_trap_is_on(): void
    {
        $this->reset_state();
        $this->trap->enqueue_script();
        $this->assertSame(['bfas-timetrap'], $GLOBALS['bfas_test_enqueued_scripts']);
        $this->assertSame(['var bfasTimeTrap = {"url":"https:\/\/example.test\/wp-json\/bfas\/v1\/token"};'], $GLOBALS['bfas_test_inline_scripts']['bfas-timetrap']);

        $this->reset_state();
        $off = $this->createMock(Settings::class);
        $off->method('get')->willReturnMap([['timetrap_enabled', true, false]]);
        (new TimeTrap($off))->enqueue_script();
        $this->assertArrayNotHasKey('bfas_test_enqueued_scripts', $GLOBALS);
    }

    public function test_the_refresh_script_exists_and_fetches_once_per_form_without_cookies(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/timetrap.js');
        $this->assertStringContainsString("credentials: 'omit'", $js);
        $this->assertStringContainsString("cache: 'no-store'", $js);
        $this->assertStringContainsString('if ( ! requests[ form ] )', $js);
        $this->assertStringNotContainsString('addEventListener', $js, 'nothing the visitor does fetches a token again');
    }

    // ------------------------------------------------------------------ //
    // Helper
    // ------------------------------------------------------------------ //

    /**
     * Build a syntactically valid timetrap token with a backdated timestamp.
     * elapsed = how many seconds ago it was "generated".
     */
    private function build_valid_token(string $form_type, int $elapsed): string
    {
        $nonce     = substr(md5(uniqid((string) mt_rand(), true)), 0, 10);
        $timestamp = time() - $elapsed;
        $hmac      = hash_hmac('sha256', $nonce . ':' . $timestamp . ':' . $form_type, $this->hmac_secret);
        return base64_encode($nonce . '|' . $timestamp . '|' . $hmac);
    }
}
