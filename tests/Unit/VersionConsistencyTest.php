<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Every place the release version lives must agree: the plugin header,
 * BFAS_VERSION, readme.txt's Stable tag and the newest entry in both changelogs.
 */
#[CoversNothing]
final class VersionConsistencyTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2) . '/';
    }

    private function first(string $pattern, string $file): string
    {
        $this->assertSame(1, preg_match($pattern, (string) file_get_contents($this->root() . $file), $m), "$pattern in $file");
        return $m[1];
    }

    public function test_every_version_string_agrees_with_the_plugin_header(): void
    {
        $version = $this->first('/^\s*\*\s*Version:\s*(\S+)/m', 'bluefairy-antispam.php');

        $this->assertSame($version, $this->first("/define\('BFAS_VERSION', '([^']+)'\)/", 'bluefairy-antispam.php'), 'BFAS_VERSION');
        $this->assertSame($version, $this->first('/^Stable tag:\s*(\S+)/m', 'readme.txt'), 'readme.txt Stable tag');
        $this->assertSame($version, $this->first('/== Changelog ==\s*= (\S+) =/', 'readme.txt'), 'newest readme.txt changelog entry');
        $this->assertSame($version, $this->first('/== Upgrade Notice ==\s*= (\S+) =/', 'readme.txt'), 'newest readme.txt upgrade notice');
        $this->assertSame($version, $this->first('/^## \[([^\]]+)\]/m', 'CHANGELOG.md'), 'newest CHANGELOG.md heading');
    }
}
