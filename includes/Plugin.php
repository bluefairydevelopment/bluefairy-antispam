<?php
/**
 * Central plugin bootstrap — wires every component together.
 *
 * @package BlueFairy\AntiSpam
 */

declare(strict_types=1);

namespace BlueFairy\AntiSpam;

use BlueFairy\AntiSpam\Admin\DashboardWidget;
use BlueFairy\AntiSpam\Admin\LogPage;
use BlueFairy\AntiSpam\Admin\SettingsPage;
use BlueFairy\AntiSpam\Abilities\AbilityRegistrar;
use BlueFairy\AntiSpam\Integration\WooCommerceIntegration;
use BlueFairy\AntiSpam\Integration\WordPressIntegration;
use BlueFairy\AntiSpam\Sfs\SfsReporter;
use BlueFairy\AntiSpam\Traps\Honeypot;
use BlueFairy\AntiSpam\Traps\TimeTrap;

/**
 * Central plugin bootstrap — wires every component together.
 */
final class Plugin {

	/**
	 * The single plugin instance.
	 *
	 * @var ?Plugin
	 */
	private static ?Plugin $instance = null;

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
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->logger   = new Logger( $this->settings );
	}

	/**
	 * The single plugin instance, created on first use.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot all plugin components.
	 */
	public function boot(): void {
		$this->register_cron_handler();
		$this->register_assets();

		// Register the SFS reporter cron handler once — prevents double-firing when
		// both WordPress and WooCommerce integrations are active.
		( new SfsReporter( $this->settings, $this->logger ) )->register();

		// Front-end integrations.
		$wp = new WordPressIntegration( $this->settings, $this->logger );
		$wp->register();

		if ( $this->is_woocommerce_active() ) {
			$wc = new WooCommerceIntegration( $this->settings, $this->logger );
			$wc->register();
		}

		// Admin.
		if ( is_admin() ) {
			$this->boot_admin();
			$wp->register_admin_hooks();
		}

		// WP 6.9+ Abilities.
		( new AbilityRegistrar( $this->settings, $this->logger ) )->register();

		// Third-party integration hooks (e.g. BlueFairy Forms).
		$this->register_integration_hooks();
	}

	/**
	 * Wire public action/filter hooks for third-party plugin integration.
	 *
	 * Action: bluefairy_antispam_render_fields( string $form_type )
	 *   Renders honeypot + timetrap fields for the given form type.
	 *
	 * Filter: bluefairy_antispam_check( bool $is_spam, array $post_data, string $form_type, string $ip, string $email )
	 *   Runs honeypot + timetrap checks. Returns true when spam detected.
	 */
	private function register_integration_hooks(): void {
		$honeypot = new Honeypot( $this->settings );
		$timetrap = new TimeTrap( $this->settings );

		add_action(
			'bluefairy_antispam_render_fields',
			function ( string $form_type ) use ( $honeypot, $timetrap ): void {
				$honeypot->render_field( $form_type );
				$timetrap->render_fields( $form_type );
			}
		);

		add_filter(
			'bluefairy_antispam_check',
			function ( bool $is_spam, array $post_data, string $form_type, string $ip, string $email ) use ( $honeypot, $timetrap ): bool {
				if ( $is_spam ) {
					return true;
				}
				if ( $honeypot->is_spam( $post_data, $form_type ) ) {
					$this->logger->log( 'honeypot', $form_type, $ip, $email );
					return true;
				}
				if ( $timetrap->is_spam( $post_data, $form_type ) ) {
					$this->logger->log( 'timetrap', $form_type, $ip, $email );
					return true;
				}
				return false;
			},
			10,
			5
		);
	}

	/**
	 * Register the CSS asset (honeypot field hiding).
	 */
	private function register_assets(): void {
		add_action(
			'init',
			function (): void {
				wp_register_script( TimeTrap::SCRIPT_HANDLE, BFAS_URL . 'assets/js/token-refresh.js', [], BFAS_VERSION, true );
			}
		);

		add_action(
			'rest_api_init',
			function (): void {
				$timetrap = new TimeTrap( $this->settings );
				register_rest_route(
					TimeTrap::REST_NAMESPACE,
					TimeTrap::REST_ROUTE,
					[
						'methods'             => 'GET',
						'callback'            => [ $timetrap, 'rest_token' ],
						'permission_callback' => '__return_true',
						'args'                => [
							'form_type' => [
								'type'     => 'string',
								'required' => true,
							],
						],
					]
				);
			}
		);

		add_filter( 'site_status_tests', [ new SiteHealth( $this->settings ), 'register' ] );

		add_action(
			'wp_enqueue_scripts',
			function (): void {
				wp_enqueue_style(
					'bfas-honeypot',
					BFAS_URL . 'assets/css/honeypot.css',
					[],
					BFAS_VERSION
				);
			}
		);
	}

	/**
	 * Register the daily log-cleanup cron handler.
	 */
	private function register_cron_handler(): void {
		add_action(
			'bfas_log_cleanup',
			function (): void {
				$this->logger->cleanup_old_logs();
			}
		);
	}

	/**
	 * Boot admin-only components.
	 */
	private function boot_admin(): void {
		( new SettingsPage( $this->settings ) )->register();
		( new LogPage( $this->logger ) )->register();
		( new DashboardWidget( $this->logger ) )->register();
	}

	/**
	 * Check whether WooCommerce is active.
	 */
	private function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Expose the shared Settings instance.
	 */
	public function get_settings(): Settings {
		return $this->settings;
	}

	/**
	 * Expose the shared Logger instance.
	 */
	public function get_logger(): Logger {
		return $this->logger;
	}
}
