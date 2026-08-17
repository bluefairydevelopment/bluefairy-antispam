<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam;

/**
 * Handles plugin activation, deactivation, and database installation.
 */
class Installer
{
    /**
     * Run on plugin activation.
     */
    public function activate(): void
    {
        $this->install_table();
        $this->maybe_generate_hmac_secret();
        $this->schedule_cron();
    }

    /**
     * Run on plugin deactivation.
     */
    public function deactivate(): void
    {
        $timestamp = wp_next_scheduled('bfas_log_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'bfas_log_cleanup');
        }
        wp_clear_scheduled_hook('bfas_log_cleanup');
    }

    /**
     * Create or upgrade the log table using dbDelta.
     */
    private function install_table(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table           = $wpdb->prefix . 'bfas_log';

        $sql = "CREATE TABLE `{$table}` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `blocked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `trap` varchar(20) NOT NULL,
  `form` varchar(40) NOT NULL,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `email_hash` varchar(64) NOT NULL DEFAULT '',
  `reported_sfs` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY idx_blocked_at (blocked_at),
  KEY idx_ip (ip(45))
) ENGINE=InnoDB {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Store DB version inside the main settings option.
        $settings = get_option('bfas_settings', []);
        if (! is_array($settings)) {
            $settings = [];
        }
        $settings['db_version'] = '1.0';
        update_option('bfas_settings', $settings);
    }

    /**
     * Generate the HMAC secret on first activation (non-autoloaded).
     */
    private function maybe_generate_hmac_secret(): void
    {
        if (get_option('bfas_hmac_secret')) {
            return;
        }

        $secret = bin2hex(random_bytes(32));
        add_option('bfas_hmac_secret', $secret, '', 'no'); // non-autoloaded
    }

    /**
     * Schedule the daily log-cleanup event if not already scheduled.
     */
    private function schedule_cron(): void
    {
        if (! wp_next_scheduled('bfas_log_cleanup')) {
            wp_schedule_event(time(), 'daily', 'bfas_log_cleanup');
        }
    }
}
