<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Abilities;

use BlueFairy\AntiSpam\Logger;
use BlueFairy\AntiSpam\Settings;

/**
 * Registers WordPress 6.9+ Abilities for the plugin.
 *
 * All three abilities are read-only and gated on manage_options via
 * permission_callback. execute_callback is required by WP_Ability on WP 6.9+.
 * Guards with function_exists() so the plugin still loads on older WP.
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
     * Register the ability category and abilities on the correct WP 6.9 hooks.
     */
    public function register(): void
    {
        if (! function_exists('wp_register_ability_category') || ! function_exists('wp_register_ability')) {
            return;
        }

        add_action('wp_abilities_api_categories_init', [$this, 'do_register_category']);
        add_action('wp_abilities_api_init', [$this, 'do_register_abilities']);
    }

    public function do_register_category(): void
    {
        wp_register_ability_category(
            'bluefairy-antispam',
            [
                'label'       => __('BlueFairy Anti-Spam', 'bluefairy-antispam'),
                'description' => __('Read-only access to anti-spam logs, stats, and settings.', 'bluefairy-antispam'),
            ]
        );
    }

    public function do_register_abilities(): void
    {
        wp_register_ability(
            'bluefairy-antispam/get-log-entries',
            [
                'label'               => __('Get log entries', 'bluefairy-antispam'),
                'description'         => __('Read blocked-submission log entries.', 'bluefairy-antispam'),
                'category'            => 'bluefairy-antispam',
                'readonly'            => true,
                'capability'          => 'manage_options',
                'permission_callback' => [$this, 'user_can_access_abilities'],
                'execute_callback'    => [$this, 'execute_get_log_entries'],
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'limit'  => [
                            'type'    => 'integer',
                            'minimum' => 1,
                            'maximum' => 500,
                            'default' => 50,
                        ],
                        'offset' => [
                            'type'    => 'integer',
                            'minimum' => 0,
                            'default' => 0,
                        ],
                    ],
                ],
            ]
        );

        wp_register_ability(
            'bluefairy-antispam/get-stats',
            [
                'label'               => __('Get stats', 'bluefairy-antispam'),
                'description'         => __('Read aggregated spam-block statistics.', 'bluefairy-antispam'),
                'category'            => 'bluefairy-antispam',
                'readonly'            => true,
                'capability'          => 'manage_options',
                'permission_callback' => [$this, 'user_can_access_abilities'],
                'execute_callback'    => [$this, 'execute_get_stats'],
            ]
        );

        wp_register_ability(
            'bluefairy-antispam/get-settings',
            [
                'label'               => __('Get settings', 'bluefairy-antispam'),
                'description'         => __('Read anti-spam plugin settings (SFS API key excluded).', 'bluefairy-antispam'),
                'category'            => 'bluefairy-antispam',
                'readonly'            => true,
                'capability'          => 'manage_options',
                'permission_callback' => [$this, 'user_can_access_abilities'],
                'execute_callback'    => [$this, 'execute_get_settings'],
            ]
        );
    }

    /**
     * Runtime authorization gate for every BlueFairy Anti-Spam ability.
     *
     * Matches the plugin's admin surface (Settings + Log pages both require
     * manage_options). Do NOT weaken this without deliberately re-scoping.
     */
    public function user_can_access_abilities(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * @param mixed $input
     * @return array{entries: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function execute_get_log_entries($input = null): array
    {
        $args   = is_array($input) ? $input : [];
        $limit  = isset($args['limit'])  ? absint($args['limit'])  : 50;
        $offset = isset($args['offset']) ? absint($args['offset']) : 0;
        $limit  = max(1, min(500, $limit));

        return [
            'entries' => $this->logger->get_recent_entries($limit, $offset),
            'total'   => $this->logger->get_stats()['total'],
            'limit'   => $limit,
            'offset'  => $offset,
        ];
    }

    /**
     * @param mixed $input
     * @return array{totals: array{total:int,week:int,month:int}, trap_breakdown: array<int, array{trap:string,count:int}>}
     */
    public function execute_get_stats($input = null): array
    {
        return [
            'totals'         => $this->logger->get_stats(),
            'trap_breakdown' => $this->logger->get_trap_breakdown(),
        ];
    }

    /**
     * Returns the bfas_settings option contents.
     *
     * SECURITY: Settings::get_all() covers ONLY the bfas_settings option array.
     * bfas_sfs_api_key and bfas_hmac_secret are stored as SEPARATE options and
     * are naturally excluded. The unset() below is defence in depth in case a
     * future storage-layout change ever puts them into bfas_settings — the
     * corresponding BFW-10 test enforces this.
     *
     * @param mixed $input
     * @return array<string, mixed>
     */
    public function execute_get_settings($input = null): array
    {
        $out = $this->settings->get_all();
        unset($out['sfs_api_key'], $out['hmac_secret']);

        return $out;
    }
}
