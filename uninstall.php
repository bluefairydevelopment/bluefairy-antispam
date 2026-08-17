<?php
declare(strict_types=1);

/**
 * Uninstall routine for BlueFairy Anti-Spam.
 *
 * Fired when the plugin is deleted via WP Admin. Removes all plugin data.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Drop the log table.
$wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}bfas_log`"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete options.
delete_option('bfas_settings');
delete_option('bfas_hmac_secret');
delete_option('bfas_sfs_api_key');

// Delete all transients with the bfas_ prefix.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '_transient_bfas_%' OR `option_name` LIKE '_transient_timeout_bfas_%'"
);

// Unschedule the log cleanup cron event.
$timestamp = wp_next_scheduled('bfas_log_cleanup');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'bfas_log_cleanup');
}
wp_clear_scheduled_hook('bfas_log_cleanup');
