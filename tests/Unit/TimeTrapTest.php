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
