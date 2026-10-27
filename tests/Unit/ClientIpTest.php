<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Tests\Unit;

use BlueFairy\AntiSpam\ClientIp;
use BlueFairy\AntiSpam\Settings;
use BlueFairy\AntiSpam\VisitorIp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClientIp::class)]
#[CoversClass(VisitorIp::class)]
final class ClientIpTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $GLOBALS['bfas_test_filters']);
    }

    public function test_cloudflare_header_is_believed_only_from_cloudflare(): void
    {
        $on = new ClientIp(true);
        // 104.16.0.1 is inside 104.16.0.0/13.
        $this->assertSame('198.51.100.7', $on->resolve(['REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7']));
        // The same header from anywhere else is a spoof and is ignored.
        $this->assertSame('203.0.113.9', $on->resolve(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7']));
    }

    public function test_cloudflare_ipv6_ranges_are_recognised(): void
    {
        $on = new ClientIp(true);
        $this->assertSame('2001:db8::1', $on->resolve(['REMOTE_ADDR' => '2606:4700:10::6814:1', 'HTTP_CF_CONNECTING_IP' => '2001:db8::1']));
        // 2a06:98c0::/29 covers 2a06:98c0:: to 2a06:98c7:ffff:...; 2a06:98c8:: is outside.
        $this->assertTrue(ClientIp::in_ranges('2a06:98c7::1', ClientIp::CLOUDFLARE_RANGES));
        $this->assertFalse(ClientIp::in_ranges('2a06:98c8::1', ClientIp::CLOUDFLARE_RANGES));
    }

    public function test_partial_byte_masks_are_exact(): void
    {
        // 104.16.0.0/13 = 104.16.0.0 to 104.23.255.255.
        $this->assertTrue(ClientIp::in_ranges('104.23.255.255', ['104.16.0.0/13']));
        $this->assertFalse(ClientIp::in_ranges('104.24.0.0', ['104.16.0.0/13']));
        $this->assertFalse(ClientIp::in_ranges('104.15.255.255', ['104.16.0.0/13']));
        // Whole-byte prefix.
        $this->assertTrue(ClientIp::in_ranges('2400:cb00:1::1', ['2400:cb00::/32']));
        // An IPv4 address never matches an IPv6 range, and junk matches nothing.
        $this->assertFalse(ClientIp::in_ranges('36.0.203.0', ['2400:cb00::/32']));
        $this->assertFalse(ClientIp::in_ranges('not-an-ip', ClientIp::CLOUDFLARE_RANGES));
        // A range without a prefix length matches nothing, rather than everything.
        $this->assertFalse(ClientIp::in_ranges('203.0.113.9', ['104.16.0.0']));
        $this->assertFalse(ClientIp::in_ranges('203.0.113.9', ['104.16.0.0/x']));
    }

    public function test_detection_off_or_a_bad_header_falls_back_to_remote_addr(): void
    {
        $server = ['REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'];
        $this->assertSame('104.16.0.1', (new ClientIp(false))->resolve($server));
        $this->assertSame('104.16.0.1', (new ClientIp(true))->resolve(['REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4, 5.6.7.8']));
        $this->assertSame('', (new ClientIp(true))->resolve(['REMOTE_ADDR' => 'garbage']));
        $this->assertSame('', (new ClientIp(true))->resolve(['REMOTE_ADDR' => ['1.2.3.4']]));
    }

    public function test_from_cloudflare(): void
    {
        $this->assertTrue(ClientIp::from_cloudflare(['REMOTE_ADDR' => '162.159.1.1']));
        $this->assertFalse(ClientIp::from_cloudflare(['REMOTE_ADDR' => '203.0.113.9']));
        $this->assertFalse(ClientIp::from_cloudflare([]));
    }

    /**
     * Some dual-stack servers report an IPv4 visitor as ::ffff:a.b.c.d.
     */
    public function test_ipv4_mapped_ipv6_is_treated_as_ipv4(): void
    {
        $this->assertTrue(ClientIp::in_ranges('::ffff:104.16.0.1', ClientIp::CLOUDFLARE_RANGES));
        $this->assertTrue(ClientIp::from_cloudflare(['REMOTE_ADDR' => '::ffff:162.159.1.1']));
        $this->assertFalse(ClientIp::from_cloudflare(['REMOTE_ADDR' => '::ffff:203.0.113.9']));
        // A mapped Cloudflare address passes the visitor's header on.
        $this->assertSame('198.51.100.7', (new ClientIp(true))->resolve(['REMOTE_ADDR' => '::ffff:104.16.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7']));
        // The address itself comes back in its IPv4 form, header or not.
        $this->assertSame('203.0.113.9', (new ClientIp(true))->resolve(['REMOTE_ADDR' => '::ffff:203.0.113.9']));
        $this->assertSame('198.51.100.7', (new ClientIp(true))->resolve(['REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_CONNECTING_IP' => '::ffff:198.51.100.7']));
        // An ordinary IPv6 address is left alone, and is not mistaken for IPv4.
        $this->assertSame('2001:db8::1', (new ClientIp(true))->resolve(['REMOTE_ADDR' => '2001:db8::1']));
        $this->assertFalse(ClientIp::in_ranges('::104.16.0.1', ClientIp::CLOUDFLARE_RANGES));
    }

    public function test_the_bundled_ranges_are_all_valid_cidrs(): void
    {
        $this->assertCount(22, ClientIp::CLOUDFLARE_RANGES);
        foreach (ClientIp::CLOUDFLARE_RANGES as $range) {
            [$net, $bits] = explode('/', $range);
            $this->assertNotFalse(@inet_pton($net), $range);
            $this->assertTrue(ClientIp::in_ranges($net, [$range]), $range);
        }
    }

    public function test_visitor_ip_uses_the_setting_and_the_filter(): void
    {
        $_SERVER['REMOTE_ADDR']           = '104.16.0.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
        $on  = $this->settings('1');
        $off = $this->settings('0');
        $this->assertSame('198.51.100.7', VisitorIp::get($on));
        $this->assertSame('104.16.0.1', VisitorIp::get($off));

        $seen = [];
        $GLOBALS['bfas_test_filters'][VisitorIp::FILTER] = function (string $ip, string $remote) use (&$seen): string {
            $seen = [$ip, $remote];
            return '192.0.2.44';
        };
        $this->assertSame('192.0.2.44', VisitorIp::get($off));
        $this->assertSame(['104.16.0.1', '104.16.0.1'], $seen);

        // A filter that returns something that is not an address is ignored,
        // and the address the plugin worked out is used instead.
        foreach (['x', '', null, ['1.2.3.4'], 42] as $bad) {
            $GLOBALS['bfas_test_filters'][VisitorIp::FILTER] = fn () => $bad;
            $this->assertSame('104.16.0.1', VisitorIp::get($off), var_export($bad, true));
            $this->assertSame('198.51.100.7', VisitorIp::get($on), var_export($bad, true));
        }
        // Surrounding whitespace from a filter is forgiven.
        $GLOBALS['bfas_test_filters'][VisitorIp::FILTER] = fn (): string => " 192.0.2.44\n";
        $this->assertSame('192.0.2.44', VisitorIp::get($off));
    }

    private function settings(string $detect): Settings
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(fn (string $key, mixed $default = null): mixed => 'cloudflare_detect' === $key ? $detect : $default);
        return $settings;
    }
}
