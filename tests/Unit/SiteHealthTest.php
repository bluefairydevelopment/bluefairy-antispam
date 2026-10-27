<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Tests\Unit;

use BlueFairy\AntiSpam\Settings;
use BlueFairy\AntiSpam\SiteHealth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SiteHealth::class)]
final class SiteHealthTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
    }

    private function health(string $detect): SiteHealth
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(fn (string $key, mixed $default = null): mixed => 'cloudflare_detect' === $key ? $detect : $default);
        return new SiteHealth($settings);
    }

    public function test_it_registers_a_direct_test(): void
    {
        $health = $this->health('1');
        $tests  = $health->register(['async' => []]);
        $this->assertSame([$health, 'run'], $tests['direct']['bfas_visitor_ip']['test']);
        $this->assertArrayHasKey('async', $tests);
    }

    public function test_good_when_requests_do_not_come_from_cloudflare(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->assertSame('good', $this->health('1')->run()['status']);
    }

    public function test_recommended_when_behind_cloudflare_with_detection_on(): void
    {
        $_SERVER['REMOTE_ADDR'] = '172.64.1.1';
        $result = $this->health('1')->run();
        $this->assertSame('recommended', $result['status']);
        $this->assertStringContainsString('mod_remoteip', $result['description']);
        $this->assertStringContainsString('compensates', $result['description']);
    }

    public function test_critical_when_behind_cloudflare_with_detection_off(): void
    {
        $_SERVER['REMOTE_ADDR'] = '2606:4700::1';
        $result = $this->health('0')->run();
        $this->assertSame('critical', $result['status']);
        $this->assertStringContainsString('turned off', $result['description']);
    }

    /**
     * Dual-stack servers can report an IPv4 client as ::ffff:a.b.c.d. PHP counts
     * that form as a reserved range, so it must be normalised before the
     * local/private check or a Cloudflare request reads as a local one.
     */
    public function test_an_ipv4_mapped_cloudflare_address_reports_the_cloudflare_status(): void
    {
        $_SERVER['REMOTE_ADDR'] = '::ffff:104.16.0.1';
        $on = $this->health('1')->run();
        $this->assertSame('recommended', $on['status']);
        $this->assertStringContainsString('compensates', $on['description']);
        $this->assertStringNotContainsString('Cloudflare Tunnel', $on['description']);

        $this->assertSame('critical', $this->health('0')->run()['status']);

        $_SERVER['REMOTE_ADDR'] = '::ffff:203.0.113.9';
        $this->assertSame('good', $this->health('1')->run()['status']);

        $_SERVER['REMOTE_ADDR'] = '::ffff:10.0.0.5';
        $this->assertStringContainsString('Cloudflare Tunnel', $this->health('1')->run()['description']);
    }

    /**
     * Cloudflare Tunnel or a proxy on the same network: every visitor arrives
     * from one local address, which must not be reported as "good".
     */
    public function test_recommended_when_requests_come_from_a_local_or_private_address(): void
    {
        foreach (['127.0.0.1', '::1', '10.0.0.5', '192.168.1.20', '172.16.0.3', 'fd00::1'] as $addr) {
            $_SERVER['REMOTE_ADDR'] = $addr;
            $result = $this->health('1')->run();
            $this->assertSame('recommended', $result['status'], $addr);
            $this->assertStringContainsString('Cloudflare Tunnel', $result['description'], $addr);
        }
    }
}
