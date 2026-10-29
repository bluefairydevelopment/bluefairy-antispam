<?php
/**
 * Uninstall routine for BlueFairy Anti-Spam.
 *
 * Fired when the plugin is deleted via WP Admin. Removes all plugin data.
 *
 * @package BlueFairy\AntiSpam
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the log table.
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}bfas_log`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete options.
delete_option( 'bfas_settings' );
delete_option( 'bfas_hmac_secret' );
delete_option( 'bfas_sfs_api_key' );

// Delete the used-token records (WPMAP-219).
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE 'bfas\\_tt\\_used\\_%'"
);

// Delete all transients with the bfas_ prefix.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '_transient_bfas_%' OR `option_name` LIKE '_transient_timeout_bfas_%'"
);

// Unschedule all cron events.
$bfas_timestamp = wp_next_scheduled( 'bfas_log_cleanup' );
if ( $bfas_timestamp ) {
	wp_unschedule_event( $bfas_timestamp, 'bfas_log_cleanup' );
}
wp_clear_scheduled_hook( 'bfas_log_cleanup' );
wp_clear_scheduled_hook( 'bfas_sfs_report_event' );
