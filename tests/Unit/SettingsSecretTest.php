<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Tests\Unit;

use BlueFairy\AntiSpam\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Settings::class)]
final class SettingsSecretTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['bfas_test_options'] = [];
        unset($GLOBALS['bfas_test_add_options'], $GLOBALS['bfas_test_update_options'], $GLOBALS['bfas_test_options_read_only']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['bfas_test_options'], $GLOBALS['bfas_test_add_options'], $GLOBALS['bfas_test_update_options'], $GLOBALS['bfas_test_options_read_only']);
    }

    public function test_an_existing_secret_is_returned_unchanged(): void
    {
        $GLOBALS['bfas_test_options']['bfas_hmac_secret'] = str_repeat('ab', 32);
        $this->assertSame(str_repeat('ab', 32), (new Settings())->get_hmac_secret());
        $this->assertArrayNotHasKey('bfas_hmac_secret', $GLOBALS['bfas_test_add_options'] ?? []);
    }

    public function test_a_missing_secret_is_created_and_stored_without_autoload(): void
    {
        $secret = (new Settings())->get_hmac_secret();
        $this->assertSame(64, strlen($secret));
        $this->assertSame($secret, $GLOBALS['bfas_test_options']['bfas_hmac_secret']);
        $this->assertFalse($GLOBALS['bfas_test_add_options']['bfas_hmac_secret']['autoload']);
        // The next read returns the same one.
        $this->assertSame($secret, (new Settings())->get_hmac_secret());
    }

    public function test_an_empty_or_short_secret_is_replaced(): void
    {
        $GLOBALS['bfas_test_options']['bfas_hmac_secret'] = '';
        $secret = (new Settings())->get_hmac_secret();
        $this->assertSame(64, strlen($secret));
        $this->assertSame($secret, $GLOBALS['bfas_test_update_options']['bfas_hmac_secret']['value']);
        $this->assertFalse($GLOBALS['bfas_test_update_options']['bfas_hmac_secret']['autoload']);
    }

    public function test_an_unstorable_secret_comes_back_empty(): void
    {
        $GLOBALS['bfas_test_options_read_only'] = true;
        $this->assertSame('', (new Settings())->get_hmac_secret());
    }

    public function test_cloudflare_detection_is_on_by_default(): void
    {
        $this->assertSame('1', Settings::defaults()['cloudflare_detect']);
        $this->assertSame('1', (new Settings())->get('cloudflare_detect'));
    }
}
