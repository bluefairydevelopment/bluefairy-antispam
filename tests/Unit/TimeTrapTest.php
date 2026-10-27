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
    // Bound to the visitor's network (review M1)
    // ------------------------------------------------------------------ //

    public function test_a_token_only_works_from_the_network_it_was_issued_to(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $token = $this->build_valid_token('comment', elapsed: 10);
        $this->assertFalse($this->trap->is_spam(['bfas_tk_comment' => $token], 'comment'));

        // Same /24 (a phone changing its last octet) still passes.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.200';
        $this->assertFalse($this->trap->is_spam(['bfas_tk_comment' => $token], 'comment'));

        // Replayed from another network: refused.
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $this->assertTrue($this->trap->is_spam(['bfas_tk_comment' => $token], 'comment'));
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_a_1_1_4_token_without_the_network_is_refused(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $nonce = 'n';
        $ts    = time() - 10;
        $old   = base64_encode($nonce . '|' . $ts . '|' . hash_hmac('sha256', $nonce . ':' . $ts . ':comment', $this->hmac_secret));
        $this->assertTrue($this->trap->is_spam(['bfas_tk_comment' => $old], 'comment'));
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_minted_and_rest_tokens_verify_for_the_same_visitor(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $token = $this->trap->mint('comment');
        $this->assertNotSame('', $token);
        // Too fast straight away, which proves it is a live token timed from now.
        $this->assertTrue($this->trap->is_spam(['bfas_tk_comment' => $token], 'comment'));

        $rest = $this->trap->rest_token(new \WP_REST_Request(['form_type' => 'comment']));
        $this->assertInstanceOf(\WP_REST_Response::class, $rest);
        $this->assertNotSame('', $rest->get_data()['token']);
        // A cached answer would hand one visitor's token to everyone.
        $this->assertStringContainsString('no-store', $rest->get_headers()['Cache-Control']);

        $bad = $this->trap->rest_token(new \WP_REST_Request(['form_type' => '<script>']));
        $this->assertInstanceOf(\WP_Error::class, $bad);
        $this->assertSame(['status' => 400], $bad->get_error_data());
        $this->assertInstanceOf(\WP_Error::class, $this->trap->rest_token(new \WP_REST_Request(['form_type' => ['a']])));
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_render_prints_the_refresh_address_and_enqueues_the_script(): void
    {
        $GLOBALS['bfas_test_enqueued_scripts'] = [];
        ob_start();
        $this->trap->render_fields('comment');
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('data-bfas-refresh="https://example.test/wp-json/bluefairy-antispam/v1/token"', $html);
        $this->assertStringContainsString('data-bfas-form="comment"', $html);
        $this->assertContains(TimeTrap::SCRIPT_HANDLE, $GLOBALS['bfas_test_enqueued_scripts']);
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
        $forged = base64_encode('n|' . $ts . '|' . hash_hmac('sha256', 'n:' . $ts . ':comment:', ''));
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
        $network   = \BlueFairy\AntiSpam\ClientIp::network((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $hmac      = hash_hmac('sha256', $nonce . ':' . $timestamp . ':' . $form_type . ':' . $network, $this->hmac_secret);
        return base64_encode($nonce . '|' . $timestamp . '|' . $hmac);
    }
}
