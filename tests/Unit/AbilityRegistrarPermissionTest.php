<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Tests\Unit;

use BlueFairy\AntiSpam\Abilities\AbilityRegistrar;
use BlueFairy\AntiSpam\Logger;
use BlueFairy\AntiSpam\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbilityRegistrar::class)]
final class AbilityRegistrarPermissionTest extends TestCase
{
    /** @var Settings&MockObject */
    private Settings $settings;
    /** @var Logger&MockObject */
    private Logger $logger;
    private AbilityRegistrar $registrar;

    protected function setUp(): void
    {
        $this->settings  = $this->createMock(Settings::class);
        $this->logger    = $this->createMock(Logger::class);
        $this->registrar = new AbilityRegistrar($this->settings, $this->logger);

        $GLOBALS['bfas_test_registered_abilities'] = [];
        $GLOBALS['bfas_test_user_can']             = false;
    }

    // ---------------- permission_callback helper ----------------

    public function test_helper_returns_false_for_non_admin(): void
    {
        $GLOBALS['bfas_test_user_can'] = false;
        $this->assertFalse($this->registrar->user_can_access_abilities());
    }

    public function test_helper_returns_true_for_admin(): void
    {
        $GLOBALS['bfas_test_user_can'] = true;
        $this->assertTrue($this->registrar->user_can_access_abilities());
    }

    // ---------------- registration wiring ----------------

    public function test_all_three_abilities_register_both_callbacks(): void
    {
        $this->registrar->do_register_abilities();

        $registered = $GLOBALS['bfas_test_registered_abilities'];
        $expected   = [
            'bluefairy-antispam/get-log-entries',
            'bluefairy-antispam/get-stats',
            'bluefairy-antispam/get-settings',
        ];

        foreach ($expected as $name) {
            $this->assertArrayHasKey($name, $registered, "Ability {$name} must be registered.");
            $this->assertArrayHasKey('permission_callback', $registered[$name], "Ability {$name} must have permission_callback.");
            $this->assertArrayHasKey('execute_callback', $registered[$name], "Ability {$name} must have execute_callback.");
            $this->assertTrue(is_callable($registered[$name]['permission_callback']), "permission_callback for {$name} must be callable.");
            $this->assertTrue(is_callable($registered[$name]['execute_callback']), "execute_callback for {$name} must be callable.");
            $this->assertSame('manage_options', $registered[$name]['capability'], "capability metadata for {$name} must be preserved as descriptive.");
        }
    }

    // ---------------- execute_* payloads ----------------

    public function test_execute_get_stats_returns_totals_and_breakdown(): void
    {
        $this->logger->method('get_stats')->willReturn(['total' => 42, 'week' => 7, 'month' => 30]);
        $this->logger->method('get_trap_breakdown')->willReturn([
            ['trap' => 'honeypot', 'count' => 20],
            ['trap' => 'timetrap', 'count' => 22],
        ]);

        $out = $this->registrar->execute_get_stats();

        $this->assertArrayHasKey('totals', $out);
        $this->assertArrayHasKey('trap_breakdown', $out);
        $this->assertSame(42, $out['totals']['total']);
        $this->assertCount(2, $out['trap_breakdown']);
    }

    public function test_execute_get_log_entries_applies_pagination(): void
    {
        $this->logger->method('get_stats')->willReturn(['total' => 999, 'week' => 0, 'month' => 0]);
        $this->logger->method('get_recent_entries')->willReturn([]);

        // Under-min clamps limit up to 1; negative offset is normalised by absint().
        $under = $this->registrar->execute_get_log_entries(['limit' => 0, 'offset' => -5]);
        $this->assertSame(1, $under['limit']);
        $this->assertGreaterThanOrEqual(0, $under['offset'], 'Offset must never be negative.');

        // Over-max clamps down.
        $over = $this->registrar->execute_get_log_entries(['limit' => 5000, 'offset' => 50]);
        $this->assertSame(500, $over['limit']);
        $this->assertSame(50, $over['offset']);

        // Defaults when no input.
        $default = $this->registrar->execute_get_log_entries();
        $this->assertSame(50, $default['limit']);
        $this->assertSame(0, $default['offset']);
        $this->assertSame(999, $default['total']);
    }

    // ---------------- SECURITY regression guard ----------------

    public function test_execute_get_settings_never_leaks_sfs_key_or_hmac_secret(): void
    {
        // Simulate a storage-layout regression: someone puts sensitive keys
        // into bfas_settings. Settings::get_all() would return them — the
        // ability MUST still strip them before responding.
        $this->settings->method('get_all')->willReturn([
            'honeypot_enabled' => '1',
            'sfs_enabled'      => '1',
            'sfs_api_key'      => 'LEAKED-SFS-KEY',
            'hmac_secret'      => 'LEAKED-HMAC-SECRET',
        ]);

        $out = $this->registrar->execute_get_settings();

        $this->assertArrayNotHasKey('sfs_api_key', $out, 'SFS API key MUST NEVER be returned by the get-settings ability.');
        $this->assertArrayNotHasKey('hmac_secret', $out, 'HMAC secret MUST NEVER be returned by the get-settings ability.');
        $this->assertSame('1', $out['honeypot_enabled'], 'Non-sensitive keys must pass through unchanged.');
        $this->assertSame('1', $out['sfs_enabled']);
    }
}
