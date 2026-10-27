<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Tests\Unit;

use BlueFairy\AntiSpam\Installer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The HMAC secret and the SFS API key are secrets: they must be created
 * non-autoloaded, so they are not loaded into memory on every request.
 * add_option()'s $autoload is a bool since WordPress 6.6.
 */
#[CoversClass(Installer::class)]
final class InstallerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['bfas_test_options']     = [];
        $GLOBALS['bfas_test_add_options'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['bfas_test_options'], $GLOBALS['bfas_test_add_options']);
    }

    private function call(string $method): void
    {
        $m = new \ReflectionMethod(Installer::class, $method);
        $m->invoke(new Installer());
    }

    public function test_hmac_secret_is_created_not_autoloaded(): void
    {
        $this->call('maybe_generate_hmac_secret');

        $this->assertArrayHasKey('bfas_hmac_secret', $GLOBALS['bfas_test_add_options']);
        $this->assertSame(64, strlen((string) $GLOBALS['bfas_test_add_options']['bfas_hmac_secret']['value']));
        $this->assertFalse($GLOBALS['bfas_test_add_options']['bfas_hmac_secret']['autoload']);
    }

    public function test_existing_hmac_secret_is_left_alone(): void
    {
        $GLOBALS['bfas_test_options']['bfas_hmac_secret'] = 'already-there';

        $this->call('maybe_generate_hmac_secret');

        $this->assertSame([], $GLOBALS['bfas_test_add_options']);
    }

    public function test_sfs_api_key_option_is_created_not_autoloaded(): void
    {
        $this->call('maybe_seed_sfs_api_key_option');

        $this->assertSame('', $GLOBALS['bfas_test_add_options']['bfas_sfs_api_key']['value']);
        $this->assertFalse($GLOBALS['bfas_test_add_options']['bfas_sfs_api_key']['autoload']);
    }
}
