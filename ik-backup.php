<?php
/**
 * Plugin Name:       IK Backup
 * Plugin URI:        https://github.com/IT-Kayali/backup-plugin
 * Description:       Manual and scheduled full backups (database + wp-content) stored on the server, in-admin restore, and an emergency restore script that works when wp-admin is unreachable. This plugin does not replace hosting-level or off-server backups.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            IT-Kayali
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ik-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IKB_VERSION', '0.1.0' );
define( 'IKB_PLUGIN_FILE', __FILE__ );
define( 'IKB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once IKB_PLUGIN_DIR . 'includes/class-ikb-backup.php';
require_once IKB_PLUGIN_DIR . 'includes/class-ikb-restore.php';
require_once IKB_PLUGIN_DIR . 'includes/class-ikb-admin.php';

/**
 * Return the directory where backups are stored, creating it if needed.
 *
 * @return string Absolute path without trailing slash.
 */
function ikb_backup_dir() {
	$upload = wp_upload_dir();
	$dir    = $upload['basedir'] . '/ik-backups';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	// Keep the backup directory out of direct web reach on Apache; downloads go through PHP.
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/.htaccess' , "Require all denied\nDeny from all\n" );
	}
	if ( ! file_exists( $dir . '/index.php' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
	}
	return $dir;
}

/**
 * Cron hook: run a scheduled backup.
 */
function ikb_scheduled_backup() {
	$backup = new IKB_Backup();
	$result = $backup->create_backup( array( 'triggered_by' => 'cron' ) );
	if ( is_wp_error( $result ) ) {
		error_log( 'IK Backup scheduled backup failed: ' . $result->get_error_message() );
		return;
	}
	ikb_enforce_retention();
}
add_action( 'ikb_run_scheduled_backup', 'ikb_scheduled_backup' );

/**
 * Keep only the newest N backups.
 */
function ikb_enforce_retention() {
	$keep = (int) get_option( 'ikb_retention', 5 );
	if ( $keep < 1 ) {
		return;
	}
	$backups = IKB_Backup::list_backups();
	if ( count( $backups ) <= $keep ) {
		return;
	}
	foreach ( array_slice( $backups, $keep ) as $old ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $old['path'] );
	}
}

/**
 * Reschedule (or clear) the cron event from the saved option.
 */
function ikb_refresh_schedule() {
	$timestamp = wp_next_scheduled( 'ikb_run_scheduled_backup' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'ikb_run_scheduled_backup' );
	}
	$schedule = get_option( 'ikb_schedule', 'off' );
	if ( in_array( $schedule, array( 'daily', 'weekly' ), true ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, 'ikb_run_scheduled_backup' );
	}
}

register_activation_hook( __FILE__, 'ikb_refresh_schedule' );

register_deactivation_hook( __FILE__, function () {
	$timestamp = wp_next_scheduled( 'ikb_run_scheduled_backup' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'ikb_run_scheduled_backup' );
	}
} );

IKB_Admin::init();
