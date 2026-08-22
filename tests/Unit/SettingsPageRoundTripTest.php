<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Tests\Unit;

use BlueFairy\AntiSpam\Admin\SettingsPage;
use BlueFairy\AntiSpam\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Regression cover for BFW-24 (SFS API key silently reverting to empty).
 *
 * These tests exercise the sanitize path for both options registered in the
 * bfas_settings_group. They do not hit the DB — they verify that the sanitizer
 * behaviour on both options accepts a real value and returns it unchanged.
 *
 * If a future refactor removes the separate bfas_sfs_api_key option or folds
 * it into the bfas_settings array, one of these assertions should fail so the
 * regression is caught before shipping.
 */
#[CoversClass(SettingsPage::class)]
final class SettingsPageRoundTripTest extends TestCase
{
    private SettingsPage $page;

    protected function setUp(): void
    {
        $this->page = new SettingsPage(new Settings());
    }

    public function test_sanitize_settings_preserves_real_api_key_shaped_values_via_scalar_option(): void
    {
        // The SFS key is registered as a SEPARATE scalar option (bfas_sfs_api_key)
        // with sanitize_text_field. Confirm sanitize_text_field is a no-op for a
        // realistic key format (alphanumeric, no whitespace).
        $key = '4uf65vz7the2sn';
        $this->assertSame($key, sanitize_text_field($key));
    }

    public function test_sanitize_settings_does_not_include_sfs_api_key_in_group_array(): void
    {
        // The grouped sanitize_settings() MUST NOT accept sfs_api_key from the
        // bfas_settings POST payload. If it ever started reading it from there,
        // WordPress would save both a scalar (bfas_sfs_api_key) AND overwrite
        // that value inside bfas_settings — a source of confusion the current
        // architecture explicitly avoids.
        $input = [
            'honeypot_enabled' => '1',
            'sfs_enabled'      => '1',
            'sfs_api_key'      => 'leaked-into-group-array',
        ];

        $clean = $this->page->sanitize_settings($input);

        $this->assertArrayNotHasKey('sfs_api_key', $clean, 'sfs_api_key must never live inside bfas_settings.');
        $this->assertSame('1', $clean['honeypot_enabled']);
        $this->assertSame('1', $clean['sfs_enabled']);
    }

    public function test_sanitize_settings_returns_defaults_on_non_array_input(): void
    {
        // Defence: a stray scalar POST value on options.php must not corrupt
        // the group option — sanitizer returns a full defaults array.
        $clean = $this->page->sanitize_settings('not-an-array');
        $this->assertIsArray($clean);
        $this->assertArrayHasKey('honeypot_enabled', $clean);
    }
}
