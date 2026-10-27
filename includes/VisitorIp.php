<?php
/**
 * The visitor's IP address as the plugin uses it everywhere.
 *
 * @package BlueFairy\AntiSpam
 */

declare(strict_types=1);

namespace BlueFairy\AntiSpam;

/**
 * The visitor's IP address as the plugin uses it everywhere: the traps, the
 * Stop Forum Spam lookup and the block log.
 */
final class VisitorIp {

	/**
	 * Filter that lets a site behind another proxy supply the visitor's address.
	 */
	public const FILTER = 'bluefairy_antispam_client_ip';

	/**
	 * The visitor's address for this request, or an empty string.
	 *
	 * Filter: bluefairy_antispam_client_ip( string $ip, string $remote_addr ).
	 * The filter must return a plain IP address. Anything else is ignored and
	 * the address worked out by the plugin is used instead. A filter that
	 * returns X-Forwarded-For trusts a header any client can set.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public static function get( Settings $settings ): string {
		$remote   = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip       = ( new ClientIp( (bool) $settings->get( 'cloudflare_detect', '1' ) ) )->resolve( $_SERVER );
		$filtered = apply_filters( 'bluefairy_antispam_client_ip', $ip, $remote );

		if ( is_string( $filtered ) ) {
			$filtered = trim( $filtered );
			if ( false !== filter_var( $filtered, FILTER_VALIDATE_IP ) ) {
				return $filtered;
			}
		}

		return $ip;
	}
}
