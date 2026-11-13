<?php
/**
 * Asynchronously reports confirmed spammers to Stop Forum Spam.
 *
 * @package BlueFairy\AntiSpam
 */

declare(strict_types=1);

namespace BlueFairy\AntiSpam\Sfs;

use BlueFairy\AntiSpam\Logger;
use BlueFairy\AntiSpam\Settings;

/**
 * Asynchronously reports confirmed spammers to Stop Forum Spam.
 *
 * Reporting is deduped via a 24-hour transient so the same IP+email is
 * never reported twice within a day. Reports are scheduled via WP-Cron
 * to avoid adding latency to the blocking response.
 */
class SfsReporter {

	/**
	 * Stop Forum Spam add endpoint (https://www.stopforumspam.com/usage).
	 */
	private const ADD_URL = 'https://www.stopforumspam.com/add';

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Block-event logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 * @param Logger   $logger   Block-event logger.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Register the async action hook for scheduled reporting.
	 */
	public function register(): void {
		add_action( 'bfas_sfs_report_event', [ $this, 'do_report' ], 10, 3 );
	}

	/**
	 * Schedule a report if SFS is enabled and reporting has not been done recently.
	 *
	 * @param string $ip         Visitor IP address.
	 * @param string $email      Raw email address.
	 * @param int    $log_id     DB row ID for marking as reported.
	 */
	public function maybe_schedule_report( string $ip, string $email, int $log_id ): void {
		if ( ! (bool) $this->settings->get( 'sfs_enabled', false ) ) {
			return;
		}

		if ( $this->settings->get_sfs_api_key() === '' ) {
			return;
		}

		$email_hash = hash_hmac( 'sha256', strtolower( trim( $email ) ), $this->settings->get_hmac_secret() );
		$dedup_key  = 'bfas_sfs_rep_' . substr( md5( $ip . $email_hash ), 0, 12 );

		if ( get_transient( $dedup_key ) ) {
			return; // Already reported recently.
		}

		set_transient( $dedup_key, 1, 86400 ); // 24-hour dedup window

		wp_schedule_single_event( time(), 'bfas_sfs_report_event', [ $ip, $email, $log_id ] );
	}

	/**
	 * Execute the HTTP report to Stop Forum Spam.
	 *
	 * Follows https://www.stopforumspam.com/usage ("Adding to the Database"):
	 * POST to /add with username, ip_addr, email, evidence and api_key. SFS needs
	 * all of username, email and IP, so a row without them is not sent. The
	 * username is the e-mail's local part, the one name every blocked form has.
	 * A 200 is success; anything else fires `bfas_sfs_report_refused` with the
	 * status (0 for a transport error) and SFS's reason, and the row stays unmarked.
	 *
	 * @param string $ip     Visitor IP address.
	 * @param string $email  Raw email address.
	 * @param int    $log_id DB row ID to mark as reported on success.
	 */
	public function do_report( string $ip, string $email, int $log_id ): void {
		$api_key  = $this->settings->get_sfs_api_key();
		$username = (string) strstr( $email, '@', true );
		if ( '' === $api_key || '' === $ip || '' === $username ) {
			return;
		}

		$response = wp_remote_post(
			self::ADD_URL,
			[
				'timeout' => 10,
				'body'    => [
					'username' => $username,
					'ip_addr'  => $ip,
					'email'    => $email,
					'evidence' => 'Blocked by BlueFairy Anti-Spam',
					'api_key'  => $api_key,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			do_action( 'bfas_sfs_report_refused', 0, $response->get_error_message(), $log_id );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			$this->logger->mark_reported( $log_id );
			return;
		}

		do_action( 'bfas_sfs_report_refused', $code, self::refusal_reason( wp_remote_retrieve_body( $response ) ), $log_id );
	}

	/**
	 * SFS gives its reason for a refusal inside a paragraph tag; fall back to the body.
	 *
	 * @param string $body Response body.
	 */
	private static function refusal_reason( string $body ): string {
		if ( preg_match( '#<p[^>]*>(.*?)</p>#is', $body, $m ) ) {
			$body = $m[1];
		}

		return trim( wp_strip_all_tags( $body ) );
	}
}
