<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Admin;

use BlueFairy\AntiSpam\Settings;

/**
 * Settings page under Tools > Anti-Spam.
 *
 * Uses the WordPress Settings API throughout — no custom nonce handling needed.
 */
class SettingsPage
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Register admin menu item, Settings API hooks, and page-scoped assets.
     */
    public function register(): void
    {
        add_action('admin_menu',            [$this, 'add_menu_page']);
        add_action('admin_init',            [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Enqueue the settings-page stylesheet only on this plugin's Tools page.
     */
    public function enqueue_assets(string $hook): void
    {
        if ('tools_page_bfas-settings' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'bfas-settings-page',
            BFAS_URL . 'assets/admin/css/settings-page.css',
            [],
            BFAS_VERSION
        );
    }

    /**
     * Add the menu item under Tools.
     */
    public function add_menu_page(): void
    {
        add_management_page(
            __('Anti-Spam Settings', 'bluefairy-antispam'),
            __('Anti-Spam', 'bluefairy-antispam'),
            'manage_options',
            'bfas-settings',
            [$this, 'render']
        );
    }

    /**
     * Register settings, sections, and fields via the Settings API.
     */
    public function register_settings(): void
    {
        register_setting(
            'bfas_settings_group',
            'bfas_settings',
            [
                'sanitize_callback' => [$this, 'sanitize_settings'],
            ]
        );

        register_setting(
            'bfas_settings_group',
            'bfas_sfs_api_key',
            [
                'sanitize_callback' => 'sanitize_text_field',
                'autoload'          => 'no',
            ]
        );

        // --- Traps section ---
        add_settings_section(
            'bfas_section_traps',
            __('Spam Traps', 'bluefairy-antispam'),
            [$this, 'render_traps_section'],
            'bfas-settings'
        );

        add_settings_field(
            'bfas_honeypot_enabled',
            __('Honeypot', 'bluefairy-antispam'),
            [$this, 'render_honeypot_field'],
            'bfas-settings',
            'bfas_section_traps'
        );

        add_settings_field(
            'bfas_timetrap_enabled',
            __('Time trap', 'bluefairy-antispam'),
            [$this, 'render_timetrap_field'],
            'bfas-settings',
            'bfas_section_traps'
        );

        add_settings_field(
            'bfas_min_time_seconds',
            __('Minimum submit time (seconds)', 'bluefairy-antispam'),
            [$this, 'render_min_time_field'],
            'bfas-settings',
            'bfas_section_traps'
        );

        // --- Stop Forum Spam section ---
        add_settings_section(
            'bfas_section_sfs',
            __('Stop Forum Spam (optional)', 'bluefairy-antispam'),
            [$this, 'render_sfs_section'],
            'bfas-settings'
        );

        add_settings_field(
            'bfas_sfs_enabled',
            __('Enable SFS lookup', 'bluefairy-antispam'),
            [$this, 'render_sfs_enabled_field'],
            'bfas-settings',
            'bfas_section_sfs'
        );

        add_settings_field(
            'bfas_sfs_api_key',
            __('SFS API key', 'bluefairy-antispam'),
            [$this, 'render_sfs_api_key_field'],
            'bfas-settings',
            'bfas_section_sfs'
        );

        add_settings_field(
            'bfas_sfs_check_threshold',
            __('Confidence threshold (0–100)', 'bluefairy-antispam'),
            [$this, 'render_sfs_threshold_field'],
            'bfas-settings',
            'bfas_section_sfs'
        );

        // --- Log section ---
        add_settings_section(
            'bfas_section_log',
            __('Log', 'bluefairy-antispam'),
            '__return_null',
            'bfas-settings'
        );

        add_settings_field(
            'bfas_log_retention_days',
            __('Retain log entries (days)', 'bluefairy-antispam'),
            [$this, 'render_log_retention_field'],
            'bfas-settings',
            'bfas_section_log'
        );
    }

    // -------------------------------------------------------------------------
    // Section callbacks
    // -------------------------------------------------------------------------

    /** @return void */
    public function render_traps_section(): void
    {
        echo '<p>' . esc_html__('These invisible traps catch bots without inconveniencing real visitors. We recommend keeping both enabled.', 'bluefairy-antispam') . '</p>';
    }

    /** @return void */
    public function render_sfs_section(): void
    {
        echo '<div class="notice notice-info inline"><p>';
        echo wp_kses(
            __('<strong>Third-party service notice:</strong> When enabled, visitor IP addresses and email addresses are sent to <a href="https://www.stopforumspam.com/" target="_blank" rel="noopener noreferrer">Stop Forum Spam</a> for cross-referencing against their database of known spammers. This feature is <strong>disabled by default</strong>. No data is ever sent without your explicit opt-in here.', 'bluefairy-antispam'),
            ['strong' => [], 'a' => ['href' => [], 'target' => [], 'rel' => []]]
        );
        echo '</p></div>';
    }

    // -------------------------------------------------------------------------
    // Field callbacks
    // -------------------------------------------------------------------------

    /** @return void */
    public function render_honeypot_field(): void
    {
        $value = (bool) $this->settings->get('honeypot_enabled', true);
        echo '<label>';
        echo '<input type="checkbox" name="bfas_settings[honeypot_enabled]" value="1"' . checked($value, true, false) . '>';
        echo ' ' . esc_html__('Add an invisible honeypot field to all supported forms', 'bluefairy-antispam');
        echo '</label>';
    }

    /** @return void */
    public function render_timetrap_field(): void
    {
        $value = (bool) $this->settings->get('timetrap_enabled', true);
        echo '<label>';
        echo '<input type="checkbox" name="bfas_settings[timetrap_enabled]" value="1"' . checked($value, true, false) . '>';
        echo ' ' . esc_html__('Block submissions that arrive faster than a human can fill in a form', 'bluefairy-antispam');
        echo '</label>';
    }

    /** @return void */
    public function render_min_time_field(): void
    {
        $value = absint($this->settings->get('min_time_seconds', 3));
        echo '<input type="number" name="bfas_settings[min_time_seconds]" value="' . esc_attr((string) $value) . '" min="1" max="120" step="1" class="small-text">';
        echo '<p class="description">' . esc_html__('Submissions arriving before this many seconds have elapsed are blocked. Default: 3.', 'bluefairy-antispam') . '</p>';
    }

    /** @return void */
    public function render_sfs_enabled_field(): void
    {
        $value = (bool) $this->settings->get('sfs_enabled', false);
        echo '<label>';
        echo '<input type="checkbox" name="bfas_settings[sfs_enabled]" value="1"' . checked($value, true, false) . '>';
        echo ' ' . esc_html__('Check visitor IP and email against Stop Forum Spam (requires sending data to an external service — see notice above)', 'bluefairy-antispam');
        echo '</label>';
    }

    /** @return void */
    public function render_sfs_api_key_field(): void
    {
        $value = $this->settings->get_sfs_api_key();
        echo '<input type="text" name="bfas_sfs_api_key" value="' . esc_attr($value) . '" class="regular-text">';
        echo '<p class="description">';
        echo wp_kses(
            __('Required only for <em>reporting</em> confirmed spammers back to Stop Forum Spam. Leave blank to use the lookup check only. <a href="https://www.stopforumspam.com/signup" target="_blank" rel="noopener noreferrer">Get a free API key at stopforumspam.com</a>.', 'bluefairy-antispam'),
            ['em' => [], 'a' => ['href' => [], 'target' => [], 'rel' => []]]
        );
        echo '</p>';
    }

    /** @return void */
    public function render_sfs_threshold_field(): void
    {
        $value = absint($this->settings->get('sfs_check_threshold', 1));
        echo '<input type="number" name="bfas_settings[sfs_check_threshold]" value="' . esc_attr((string) $value) . '" min="0" max="100" step="1" class="small-text">';
        echo '<p class="description">' . esc_html__('Block if confidence score is at or above this value. 0 = block any match. 50 = block only high-confidence spammers.', 'bluefairy-antispam') . '</p>';
    }

    /** @return void */
    public function render_log_retention_field(): void
    {
        $value = absint($this->settings->get('log_retention_days', 90));
        echo '<input type="number" name="bfas_settings[log_retention_days]" value="' . esc_attr((string) $value) . '" min="1" max="3650" step="1" class="small-text">';
        echo '<p class="description">' . esc_html__('Log entries older than this many days are deleted automatically. Default: 90.', 'bluefairy-antispam') . '</p>';
    }

    // -------------------------------------------------------------------------
    // Sanitization
    // -------------------------------------------------------------------------

    /**
     * Sanitize the incoming settings array.
     *
     * @param  mixed $input
     * @return array<string, mixed>
     */
    public function sanitize_settings(mixed $input): array
    {
        if (! is_array($input)) {
            return Settings::defaults();
        }

        $clean = Settings::defaults();

        $clean['honeypot_enabled']    = ! empty($input['honeypot_enabled']) ? '1' : '0';
        $clean['timetrap_enabled']    = ! empty($input['timetrap_enabled']) ? '1' : '0';
        $clean['sfs_enabled']         = ! empty($input['sfs_enabled']) ? '1' : '0';
        $clean['min_time_seconds']    = max(1, absint($input['min_time_seconds'] ?? 3));
        $clean['log_retention_days']  = max(1, absint($input['log_retention_days'] ?? 90));
        $clean['sfs_check_threshold'] = min(100, max(0, absint($input['sfs_check_threshold'] ?? 1)));

        // Preserve db_version — never overwrite from user input.
        $saved = get_option('bfas_settings', []);
        $clean['db_version'] = is_array($saved) ? ($saved['db_version'] ?? '1.0') : '1.0';

        return $clean;
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    /**
     * Render the Managed Support promo block shown above the settings form.
     */
    public function render_managed_support_promo(): void
    {
        ?>
        <div class="bfas-promo" role="complementary" aria-label="<?php esc_attr_e('BlueFairy Development managed support', 'bluefairy-antispam'); ?>">
            <h2 class="bfas-promo__title"><?php esc_html_e('Enterprise-grade support for your business.', 'bluefairy-antispam'); ?></h2>
            <p class="bfas-promo__body"><?php esc_html_e('Blue Fairy Development brings the best practices of enterprise software teams to small and mid-sized businesses. Security minded, performance focused, tested update workflows, and a real person on the other end — without the overhead of an in-house team.', 'bluefairy-antispam'); ?></p>
            <p class="bfas-promo__cta">
                <a class="button button-primary"
                   href="<?php echo esc_url('https://wordpress.bluefairydevelopment.com/managed-support/'); ?>"
                   target="_blank"
                   rel="noopener noreferrer">
                    <?php esc_html_e('Learn more about managed support →', 'bluefairy-antispam'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Render the settings page.
     */
    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('BlueFairy Anti-Spam Settings', 'bluefairy-antispam'); ?></h1>
            <?php $this->render_managed_support_promo(); ?>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('bfas_settings_group');
                do_settings_sections('bfas-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
