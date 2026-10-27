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
