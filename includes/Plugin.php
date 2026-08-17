<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam;

use BlueFairy\AntiSpam\Admin\DashboardWidget;
use BlueFairy\AntiSpam\Admin\LogPage;
use BlueFairy\AntiSpam\Admin\SettingsPage;
use BlueFairy\AntiSpam\Abilities\AbilityRegistrar;
use BlueFairy\AntiSpam\Integration\WooCommerceIntegration;
use BlueFairy\AntiSpam\Integration\WordPressIntegration;

/**
 * Central plugin bootstrap — wires every component together.
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    private Settings $settings;
    private Logger $logger;

    private function __construct()
    {
        $this->settings = new Settings();
        $this->logger   = new Logger($this->settings);
    }

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Boot all plugin components.
     */
    public function boot(): void
    {
        $this->load_textdomain();
        $this->register_cron_handler();
        $this->register_assets();

        // Front-end integrations.
        $wp = new WordPressIntegration($this->settings, $this->logger);
        $wp->register();

        if ($this->is_woocommerce_active()) {
            $wc = new WooCommerceIntegration($this->settings, $this->logger);
            $wc->register();
        }

        // Admin.
        if (is_admin()) {
            $this->boot_admin();
        }

        // WP 6.9+ Abilities.
        ( new AbilityRegistrar($this->settings, $this->logger) )->register();
    }

    /**
     * Load plugin translations.
     */
    private function load_textdomain(): void
    {
        load_plugin_textdomain(
            'bluefairy-antispam',
            false,
            dirname(plugin_basename(BFAS_FILE)) . '/languages'
        );
    }

    /**
     * Register the CSS asset (honeypot field hiding).
     */
    private function register_assets(): void
    {
        add_action('wp_enqueue_scripts', function (): void {
            wp_enqueue_style(
                'bfas-honeypot',
                BFAS_URL . 'assets/css/honeypot.css',
                [],
                BFAS_VERSION
            );
        });
    }

    /**
     * Register the daily log-cleanup cron handler.
     */
    private function register_cron_handler(): void
    {
        add_action('bfas_log_cleanup', function (): void {
            $this->logger->cleanup_old_logs();
        });
    }

    /**
     * Boot admin-only components.
     */
    private function boot_admin(): void
    {
        ( new SettingsPage($this->settings) )->register();
        ( new LogPage($this->settings, $this->logger) )->register();
        ( new DashboardWidget($this->logger) )->register();
    }

    /**
     * Check whether WooCommerce is active.
     */
    private function is_woocommerce_active(): bool
    {
        return class_exists('WooCommerce');
    }

    /**
     * Expose the shared Settings instance.
     */
    public function get_settings(): Settings
    {
        return $this->settings;
    }

    /**
     * Expose the shared Logger instance.
     */
    public function get_logger(): Logger
    {
        return $this->logger;
    }
}
