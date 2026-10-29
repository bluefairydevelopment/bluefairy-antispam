<?php
/**
 * Time-trap — blocks submissions that arrive faster than a human can type.
 *
 * @package BlueFairy\AntiSpam
 */

declare(strict_types=1);

namespace BlueFairy\AntiSpam\Traps;

use BlueFairy\AntiSpam\Settings;

/**
 * Time-trap — blocks submissions that arrive faster than a human can type.
 *
 * Token format (base64-encoded): nonce|timestamp|HMAC-SHA256
 * The HMAC covers nonce:timestamp:form_type, so the token is tamper-evident
 * and form-scoped. Tokens expire after 24 hours. The signed text is the same as
 * in 1.1.4, so tokens in pages opened or cached before an upgrade still verify.
 *
 * Each token is single-use (WPMAP-219). consume() records a token the first
 * time a form passes with it; a second submission with the same token is a
 * replay, which the caller holds for moderation or logs, never refuses: a
 * visitor who presses Back after core refused a comment sends the same token
 * again. The nonce is random per mint, and the front-end script swaps the
 * token in a cached page for a fresh one once, as the page loads, so visitors
 * of one cached page do not share a token. The clock is never restarted by
 * anything the visitor does.
 */
class TimeTrap {

	/**
	 * How long a token is accepted, in seconds.
	 */
	public const LIFETIME = 86400;

	/**
	 * Option-name prefix of a used-token record.
	 */
	public const USED_PREFIX = 'bfas_tt_used_';

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
	 * Render a hidden token field into the form.
	 *
	 * @param string $form_type Form identifier, such as wp_comment or wc_review.
	 */
	public function render_fields( string $form_type ): void {
		if ( ! (bool) $this->settings->get( 'timetrap_enabled', true ) ) {
			return;
		}

		$token = $this->mint( $form_type );
		if ( '' === $token ) {
			return;
		}

		echo '<input type="hidden" name="bfas_tk_' . esc_attr( $form_type ) . '" value="' . esc_attr( $token ) . '">';
	}

	/**
	 * A new token for this form, timed from now. Empty when no
	 * secret can be had (the trap then refuses every guest; see is_spam()).
	 *
	 * @param string $form_type Form identifier, such as wp_comment or wc_review.
	 */
	public function mint( string $form_type ): string {
		$secret = $this->get_secret();
		if ( '' === $secret ) {
			return '';
		}

		$nonce     = wp_generate_password( 20, false );
		$timestamp = time();
		$hmac      = hash_hmac( 'sha256', $this->signed( $nonce, $timestamp, $form_type ), $secret );

		return base64_encode( $nonce . '|' . $timestamp . '|' . $hmac ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transport encoding of the signed token, not obfuscation.
	}

	/**
	 * Verify the time-trap token from POST data.
	 *
	 * Returns true when spam is detected (too fast or expired / tampered).
	 * Returns false when the submission is clean.
	 *
	 * @param array<string, mixed> $post_data Submitted form data.
	 * @param string               $form_type The form identifier.
	 */
	public function is_spam( array $post_data, string $form_type ): bool {
		if ( is_user_logged_in() ) {
			return false;
		}

		if ( ! (bool) $this->settings->get( 'timetrap_enabled', true ) ) {
			return false;
		}

		// No secret could be read or stored: no token can be trusted. Fail closed.
		if ( '' === $this->get_secret() ) {
			return true;
		}

		$field = 'bfas_tk_' . $form_type;
		if ( isset( $post_data[ $field ] ) && ! is_string( $post_data[ $field ] ) ) {
			return true; // An array where a token belongs: not our form.
		}
		$token = isset( $post_data[ $field ] ) ? (string) $post_data[ $field ] : '';

		if ( '' === $token ) {
			// No token present — treat as spam (field was removed by bot).
			return true;
		}

		return $this->verify_token( $token, $form_type );
	}

	/**
	 * Verify a raw token string.
	 *
	 * Returns true when spam (invalid, too fast, or expired).
	 * Returns false when clean.
	 *
	 * @param string $token     Raw token string.
	 * @param string $form_type Form identifier, such as wp_comment or wc_review.
	 */
	private function verify_token( string $token, string $form_type ): bool {
		$decoded = base64_decode( $token, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the signed token minted above.
		if ( false === $decoded ) {
			return true; // Malformed = spam.
		}

		$parts = explode( '|', $decoded, 3 );
		if ( count( $parts ) !== 3 ) {
			return true; // Malformed = spam.
		}

		[$nonce, $timestamp_str, $hmac] = $parts;
		$timestamp                      = (int) $timestamp_str;

		$expected = hash_hmac( 'sha256', $this->signed( $nonce, $timestamp, $form_type ), $this->get_secret() );

		if ( ! hash_equals( $expected, $hmac ) ) {
			return true; // Invalid signature = spam.
		}

		$elapsed = time() - $timestamp;
		$min     = (int) $this->settings->get( 'min_time_seconds', 3 );

		if ( $elapsed < $min ) {
			return true; // Too fast = spam.
		}

		if ( $elapsed > self::LIFETIME ) {
			return true; // Token expired = spam.
		}

		return false; // Passes.
	}

	/**
	 * Register GET /bfas/v1/token, which the front-end script calls once as a
	 * page loads to swap a cached page's token for a fresh one.
	 */
	public function register_route(): void {
		register_rest_route(
			'bfas/v1',
			'/token',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_token' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'form' => [
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-z0-9_]{1,40}$',
					],
				],
			]
		);
	}

	/**
	 * A fresh token for the requested form, never cached. Anyone may ask: a
	 * bot gets the same by loading the page, and the token still has to wait
	 * out the time trap and is accepted once.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function rest_token( \WP_REST_Request $request ): \WP_REST_Response {
		$enabled  = (bool) $this->settings->get( 'timetrap_enabled', true );
		$response = new \WP_REST_Response( [ 'token' => $enabled ? $this->mint( (string) $request->get_param( 'form' ) ) : '' ] );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}

	/**
	 * Enqueue the token refresh script on the front end.
	 */
	public function enqueue_script(): void {
		if ( ! (bool) $this->settings->get( 'timetrap_enabled', true ) ) {
			return;
		}

		wp_enqueue_script(
			'bfas-timetrap',
			BFAS_URL . 'assets/js/timetrap.js',
			[],
			BFAS_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
		wp_add_inline_script( 'bfas-timetrap', 'var bfasTimeTrap = ' . (string) wp_json_encode( [ 'url' => rest_url( 'bfas/v1/token' ) ] ) . ';', 'before' );
	}

	/**
	 * Record the submitted token as used. True the first time (or when there
	 * is nothing to record); false when the token was used before: a replay.
	 *
	 * Call only after is_spam() passed, so the token is genuine and unexpired.
	 * add_option() inserts the row in one statement and fails when the name
	 * exists, so of two submissions racing with one token only one is first.
	 *
	 * @param array<string, mixed> $post_data Submitted form data.
	 * @param string               $form_type The form identifier.
	 */
	public function consume( array $post_data, string $form_type ): bool {
		if ( is_user_logged_in() || ! (bool) $this->settings->get( 'timetrap_enabled', true ) ) {
			return true;
		}

		$field = 'bfas_tk_' . $form_type;
		$token = isset( $post_data[ $field ] ) && is_string( $post_data[ $field ] ) ? $post_data[ $field ] : '';
		if ( '' === $token ) {
			return true;
		}

		$decoded = (string) base64_decode( $token, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the signed token minted above.
		$parts   = explode( '|', $decoded, 3 );
		if ( 3 !== count( $parts ) ) {
			return true;
		}

		$name = self::USED_PREFIX . substr( hash( 'sha256', $form_type . '|' . $parts[2] ), 0, 40 );

		return add_option( $name, (string) ( (int) $parts[1] + self::LIFETIME ), '', false );
	}

	/**
	 * Delete the used-token records whose token has expired. Run by the daily
	 * log clean-up.
	 */
	public static function purge_used(): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE %s AND CAST(`option_value` AS UNSIGNED) < %d LIMIT 5000",
				$wpdb->esc_like( self::USED_PREFIX ) . '%',
				time()
			)
		);
	}

	/**
	 * The text a token signs: nonce, time and form. Unchanged since 1.1.4.
	 *
	 * @param string $nonce     The token nonce.
	 * @param int    $timestamp When the token was minted.
	 * @param string $form_type Form identifier.
	 */
	private function signed( string $nonce, int $timestamp, string $form_type ): string {
		return $nonce . ':' . $timestamp . ':' . $form_type;
	}

	/**
	 * Retrieve the HMAC secret from the non-autoloaded option.
	 */
	private function get_secret(): string {
		return $this->settings->get_hmac_secret();
	}
}
