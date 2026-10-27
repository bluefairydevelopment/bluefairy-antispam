<?php
/**
 * Works out the visitor's IP address, trusting Cloudflare's header only from Cloudflare.
 *
 * @package BlueFairy\AntiSpam
 */

declare(strict_types=1);

namespace BlueFairy\AntiSpam;

/**
 * Works out the visitor's IP address.
 *
 * Behind Cloudflare, REMOTE_ADDR is one of Cloudflare's servers unless the web
 * server restores the visitor's address (Apache mod_remoteip, nginx real_ip,
 * LiteSpeed's "Use Client IP in Header"). Cloudflare sends the visitor's
 * address in the CF-Connecting-IP header, but any client can send that header,
 * so it is believed only when REMOTE_ADDR is inside Cloudflare's published
 * ranges. Everything else uses REMOTE_ADDR.
 *
 * This class has no dependency on the rest of the plugin, so the same logic can
 * be copied into another plugin unchanged.
 */
final class ClientIp {

	/**
	 * Cloudflare's published ranges.
	 *
	 * Source: https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6,
	 * fetched 2026-10-26. Check them at each release; the plugin never fetches them.
	 *
	 * @var string[]
	 */
	public const CLOUDFLARE_RANGES = [
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	];

	/**
	 * Whether CF-Connecting-IP may be believed when the request comes from Cloudflare.
	 *
	 * @var bool
	 */
	private bool $detect_cloudflare;

	/**
	 * Constructor.
	 *
	 * @param bool $detect_cloudflare Believe CF-Connecting-IP from Cloudflare's ranges.
	 */
	public function __construct( bool $detect_cloudflare ) {
		$this->detect_cloudflare = $detect_cloudflare;
	}

	/**
	 * The visitor's address: CF-Connecting-IP when the request came from Cloudflare
	 * (and detection is on), otherwise REMOTE_ADDR. An empty string when neither
	 * is a valid IP address.
	 *
	 * @param array<string, mixed> $server The $_SERVER array.
	 */
	public function resolve( array $server ): string {
		$remote = self::valid( $server['REMOTE_ADDR'] ?? '' );

		if ( $this->detect_cloudflare && '' !== $remote && self::in_ranges( $remote, self::CLOUDFLARE_RANGES ) ) {
			$visitor = self::valid( $server['HTTP_CF_CONNECTING_IP'] ?? '' );
			if ( '' !== $visitor ) {
				return $visitor;
			}
		}

		return $remote;
	}

	/**
	 * Whether the request itself arrived from one of Cloudflare's servers.
	 *
	 * @param array<string, mixed> $server The $_SERVER array.
	 */
	public static function from_cloudflare( array $server ): bool {
		$remote = self::valid( $server['REMOTE_ADDR'] ?? '' );

		return '' !== $remote && self::in_ranges( $remote, self::CLOUDFLARE_RANGES );
	}

	/**
	 * Whether an address is inside any of the given CIDR ranges.
	 *
	 * @param string   $ip     An IP address.
	 * @param string[] $ranges CIDR ranges.
	 */
	public static function in_ranges( string $ip, array $ranges ): bool {
		$packed = self::pack( $ip );
		if ( '' === $packed ) {
			return false;
		}

		foreach ( $ranges as $range ) {
			[ $subnet, $bits ] = array_pad( explode( '/', $range, 2 ), 2, '' );
			$net               = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton warns on invalid input; false is handled.
			if ( false === $net || strlen( $net ) !== strlen( $packed ) || ! ctype_digit( $bits ) ) {
				continue;
			}

			$bits  = min( (int) $bits, 8 * strlen( $net ) );
			$bytes = intdiv( $bits, 8 );
			if ( substr( $packed, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
				continue;
			}

			$rest = $bits % 8;
			if ( 0 === $rest ) {
				return true;
			}

			$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
			if ( ( ord( $packed[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An address in binary form, with an IPv4-mapped IPv6 address
	 * (::ffff:a.b.c.d, as some dual-stack servers report IPv4 visitors)
	 * reduced to its four IPv4 bytes. Empty for an invalid address.
	 *
	 * @param string $ip An IP address.
	 */
	private static function pack( string $ip ): string {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton warns on invalid input; false is handled.
		if ( false === $packed ) {
			return '';
		}

		if ( 16 === strlen( $packed ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			return substr( $packed, 12 );
		}

		return $packed;
	}

	/**
	 * A valid IP address from a server value, or an empty string.
	 * An IPv4-mapped IPv6 address is returned in its IPv4 form.
	 *
	 * @param mixed $value A $_SERVER value.
	 */
	private static function valid( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );
		if ( false === filter_var( $value, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$packed = self::pack( $value );

		return 4 === strlen( $packed ) ? (string) inet_ntop( $packed ) : $value;
	}
}
