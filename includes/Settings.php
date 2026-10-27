<?php
/**
 * Manages plugin settings — wraps the single autoloaded `bfas_settings` option.
 *
 * @package BlueFairy\AntiSpam
 */

declare(strict_types=1);

namespace BlueFairy\AntiSpam;

/**
 * Manages plugin settings — wraps the single autoloaded `bfas_settings` option.
 */
class Settings {

	/**
	 * Saved settings merged over the defaults.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Default value of every setting.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = [
		'honeypot_enabled'    => '1',
		'timetrap_enabled'    => '1',
		'sfs_enabled'         => '0',
		'min_time_seconds'    => 3,
		'log_retention_days'  => 90,
		'sfs_check_threshold' => 1,
		'cloudflare_detect'   => '1',
		'db_version'          => '1.0',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$saved      = get_option( 'bfas_settings', [] );
		$this->data = array_merge( self::DEFAULTS, is_array( $saved ) ? $saved : [] );
	}

	/**
	 * Get a setting value by key, with optional fallback default.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value returned when the key is not set.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->data[ $key ] ?? ( self::DEFAULTS[ $key ] ?? $fallback );
	}

	/**
	 * Persist a full settings array.
	 *
	 * @param array<string, mixed> $data Settings to save.
	 */
	public function save( array $data ): void {
		// Preserve db_version.
		$data['db_version'] = $this->data['db_version'] ?? '1.0';
		update_option( 'bfas_settings', $data );
		$this->data = array_merge( self::DEFAULTS, $data );
	}

	/**
	 * Get the HMAC secret (non-autoloaded option), creating it when it is missing.
	 *
	 * The secret is first made on activation, but an automatic update does not
	 * re-run activation, and a database restore, a migration or a clean-up tool
	 * can lose the option. An empty secret would let anyone sign a valid token,
	 * so a missing one is made here. Returns an empty string only when it cannot
	 * be stored; the time trap then refuses every guest submission.
	 */
	public function get_hmac_secret(): string {
		$secret = get_option( 'bfas_hmac_secret', '' );
		if ( is_string( $secret ) && strlen( $secret ) >= 32 ) {
			return $secret;
		}

		$fresh = bin2hex( random_bytes( 32 ) );
		if ( false === get_option( 'bfas_hmac_secret' ) ) {
			add_option( 'bfas_hmac_secret', $fresh, '', false );
		} else {
			update_option( 'bfas_hmac_secret', $fresh, false );
		}

		$stored = get_option( 'bfas_hmac_secret', '' );

		// Another request may have stored its own secret first; either is fine.
		return is_string( $stored ) && strlen( $stored ) >= 32 ? $stored : '';
	}

	/**
	 * Get the SFS API key (non-autoloaded option).
	 */
	public function get_sfs_api_key(): string {
		$key = get_option( 'bfas_sfs_api_key', '' );
		if ( ! is_string( $key ) ) {
			return '';
		}

		return $key;
	}

	/**
	 * Return a copy of all defaults for use in registration.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return self::DEFAULTS;
	}

	/**
	 * Return the full settings data array (bfas_settings option).
	 *
	 * SECURITY: bfas_sfs_api_key and bfas_hmac_secret are stored as SEPARATE
	 * options and are NOT included here. Do NOT add them — the get-settings
	 * ability MUST NEVER expose the API key or HMAC secret.
	 *
	 * @return array<string, mixed>
	 */
	public function get_all(): array {
		return $this->data;
	}
}
