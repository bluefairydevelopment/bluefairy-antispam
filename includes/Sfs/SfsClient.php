<?php
/**
 * Stop Forum Spam API client.
 *
 * @package BlueFairy\AntiSpam
 */

declare(strict_types=1);

namespace BlueFairy\AntiSpam\Sfs;

use BlueFairy\AntiSpam\Settings;

/**
 * Stop Forum Spam API client.
 *
 * Disabled by default — only queries the API when sfs_enabled = true.
 * Implements:
 *   - Transient cache (7-day hit / 1-hour miss)
 *   - Circuit breaker: skip after 5 consecutive HTTP failures (10-min reset)
 *   - Reset circuit breaker on success
 */
class SfsClient {

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Check whether an IP / email combination is a known spammer.
	 *
	 * Returns false (not spam) if SFS is disabled or the circuit breaker is open.
	 *
	 * @param string $ip    Visitor IP address.
	 * @param string $email Visitor email address (raw — hashed only for cache key).
	 */
	public function is_spammer( string $ip, string $email ): bool {
		if ( ! (bool) $this->settings->get( 'sfs_enabled', false ) ) {
			return false;
		}

		$transient_key = 'bfas_sfs_' . substr( md5( $ip . $email ), 0, 12 );
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		// Circuit breaker — skip if >= 5 consecutive failures.
		if ( (int) get_transient( 'bfas_sfs_failures' ) >= 5 ) {
			return false;
		}

		$url = add_query_arg(
			[
				'ip'    => $ip,
				'email' => $email,
				'f'     => 'json',
			],
			'https://api.stopforumspam.org/api'
		);

		$response = wp_remote_get( $url, [ 'timeout' => 5 ] );

		if ( is_wp_error( $response ) ) {
			$failures = (int) get_transient( 'bfas_sfs_failures' );
			set_transient( 'bfas_sfs_failures', $failures + 1, 600 );

			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$failures = (int) get_transient( 'bfas_sfs_failures' );
			set_transient( 'bfas_sfs_failures', $failures + 1, 600 );

			return false;
		}

		$threshold  = (int) $this->settings->get( 'sfs_check_threshold', 1 );
		$ip_conf    = (float) ( $body['ip']['confidence'] ?? 0 );
		$email_conf = (float) ( $body['email']['confidence'] ?? 0 );
		$is_spammer = $ip_conf >= $threshold || $email_conf >= $threshold;

		set_transient( $transient_key, $is_spammer ? 1 : 0, $is_spammer ? 604800 : 3600 );

		// Reset circuit breaker on any successful, parseable API response.
		delete_transient( 'bfas_sfs_failures' );

		return $is_spammer;
	}
}
