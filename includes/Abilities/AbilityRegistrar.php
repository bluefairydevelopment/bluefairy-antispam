<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Abilities;

use BlueFairy\AntiSpam\Logger;
use BlueFairy\AntiSpam\Settings;

/**
 * Registers WordPress 6.9+ Abilities for the plugin.
 *
 * All three abilities require manage_options and are read-only.
 * Guards with function_exists() so the plugin works on older WP versions.
 */
class AbilityRegistrar
{
    private Settings $settings;
    private Logger   $logger;

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger   = $logger;
    }

    /**
     * Register the ability category and abilities on init.
     */
    public function register(): void
    {
        if (! function_exists('wp_register_ability_category') || ! function_exists('wp_register_ability')) {
            return;
        }

        add_action('init', [$this, 'do_register']);
    }

    /**
     * Perform the actual registration.
     */
    public function do_register(): void
    {
        if (! function_exists('wp_register_ability_category') || ! function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability_category(
            'bluefairy-antispam',
            [
                'label'       => __('BlueFairy Anti-Spam', 'bluefairy-antispam'),
                'description' => __('Read-only access to anti-spam logs, stats, and settings.', 'bluefairy-antispam'),
            ]
        );

        wp_register_ability(
            'bluefairy-antispam/get-log-entries',
            [
                'label'       => __('Get log entries', 'bluefairy-antispam'),
                'description' => __('Read blocked-submission log entries.', 'bluefairy-antispam'),
                'category'    => 'bluefairy-antispam',
                'readonly'    => true,
                'capability'  => 'manage_options',
            ]
        );

        wp_register_ability(
            'bluefairy-antispam/get-stats',
            [
                'label'       => __('Get stats', 'bluefairy-antispam'),
                'description' => __('Read aggregated spam-block statistics.', 'bluefairy-antispam'),
                'category'    => 'bluefairy-antispam',
                'readonly'    => true,
                'capability'  => 'manage_options',
            ]
        );

        wp_register_ability(
            'bluefairy-antispam/get-settings',
            [
                'label'       => __('Get settings', 'bluefairy-antispam'),
                'description' => __('Read anti-spam plugin settings (SFS API key excluded).', 'bluefairy-antispam'),
                'category'    => 'bluefairy-antispam',
                'readonly'    => true,
                'capability'  => 'manage_options',
            ]
        );
    }
}
