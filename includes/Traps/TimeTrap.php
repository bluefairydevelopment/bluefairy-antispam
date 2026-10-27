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
 * The token is not bound to the visitor and can be reused until it expires
 * (WPMAP-219 tracks replay protection).
 */
class TimeTrap {

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

		$nonce     = wp_create_nonce( 'bfas_tt_' . $form_type );
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

		if ( $elapsed > 86400 ) {
			return true; // Token expired = spam.
		}

		return false; // Passes.
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
