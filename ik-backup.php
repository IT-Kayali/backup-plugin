<?php
/**
 * Plugin Name:       IK Backup
 * Plugin URI:        https://github.com/IT-Kayali/backup-plugin
 * Description:       Manual and scheduled full backups (database + wp-content) stored outside the web root, in-admin restore, and an emergency restore script that works when wp-admin is unreachable. This plugin does not replace hosting-level or off-server backups.
 * Version:           0.3.1
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

define( 'IKB_VERSION', '0.3.1' );
define( 'IKB_PLUGIN_FILE', __FILE__ );
define( 'IKB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once IKB_PLUGIN_DIR . 'includes/class-ikb-backup.php';
require_once IKB_PLUGIN_DIR . 'includes/class-ikb-restore.php';
require_once IKB_PLUGIN_DIR . 'includes/class-ikb-admin.php';

/**
 * Site-specific random path component, stable once generated.
 *
 * @return string
 */
function ikb_site_token() {
	$token = get_option( 'ikb_site_token' );
	if ( ! $token ) {
		$token = wp_generate_password( 16, false, false );
		update_option( 'ikb_site_token', $token, false );
	}
	return $token;
}

/**
 * Return the directory where backups are stored, creating it if needed.
 *
 * Storage is fail-closed: the first writable location outside the web root
 * wins. Only if nothing outside the web root is writable does the plugin
 * fall back to a protected, randomized directory under uploads - and even
 * then, backups are refused unless a live probe proves the directory cannot
 * be fetched over HTTP (see ikb_storage_is_safe()).
 *
 * A location can be forced with the IKB_BACKUP_DIR constant in wp-config.php.
 *
 * @return string Absolute path without trailing slash.
 */
function ikb_backup_dir() {
	static $dir = null;
	if ( null !== $dir ) {
		return $dir;
	}

	$candidates = array();
	if ( defined( 'IKB_BACKUP_DIR' ) && IKB_BACKUP_DIR ) {
		$candidates[] = rtrim( IKB_BACKUP_DIR, '/' );
	}
	// Preferred: a hidden directory next to the WordPress root, not inside it.
	$candidates[] = rtrim( dirname( ABSPATH ), '/' ) . '/.ik-backups-' . ikb_site_token();

	$upload = wp_upload_dir();
	// Fallback: randomized directory under uploads (requires the probe to pass).
	$candidates[] = $upload['basedir'] . '/ik-backups-' . ikb_site_token();

	foreach ( $candidates as $candidate ) {
		if ( is_dir( $candidate ) || wp_mkdir_p( $candidate ) ) {
			if ( is_writable( $candidate ) ) {
				$dir = $candidate;
				break;
			}
		}
	}
	if ( null === $dir ) {
		// Last resort: report the preferred path; writes will fail loudly.
		$dir = $candidates[0];
	}

	// Harmless hardening for any web server that does reach the directory.
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
	}
	if ( ! file_exists( $dir . '/index.php' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
	}
	return $dir;
}

/**
 * Whether the backup directory sits inside the public web root.
 *
 * @param string|null $dir Optional directory; defaults to the active backup dir.
 * @return bool
 */
function ikb_dir_is_public( $dir = null ) {
	$dir  = $dir ? $dir : ikb_backup_dir();
	$root = realpath( ABSPATH );
	$real = realpath( $dir );
	if ( ! $root || ! $real ) {
		return true; // Unknown: assume public (fail closed).
	}
	$roots = array( $root );
    if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
        $document_root = realpath( $_SERVER['DOCUMENT_ROOT'] );
        if ( ! $document_root ) { return true; }
        $roots[] = $document_root;
    }
    foreach ( $roots as $public_root ) {
        if ( $real === $public_root || 0 === strpos( $real, rtrim( $public_root, '/' ) . '/' ) ) { return true; }
    }
    return false;
}

/**
 * Probe whether the backup directory can be fetched over HTTP.
 *
 * Only relevant for directories inside the web root. Writes a random probe
 * file and requests its public URL. The result is cached per directory.
 *
 * @param string|null $dir Optional directory; defaults to the active backup dir.
 * @return bool True when the probe could NOT be fetched (storage not exposed).
 */
function ikb_storage_probe_passes( $dir = null ) {
	$dir = $dir ? $dir : ikb_backup_dir();
	if ( ! ikb_dir_is_public( $dir ) ) {
		return true;
	}

	$cache_key = 'ikb_probe_' . md5( $dir );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return 'yes' === $cached;
	}

	$probe  = 'probe-' . wp_generate_password( 12, false, false ) . '.txt';
	$secret = wp_generate_password( 24, false, false );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	@file_put_contents( $dir . '/' . $probe, $secret );

	$upload = wp_upload_dir();
	if ( 0 !== strpos( $dir, rtrim( $upload['basedir'], '/' ) . '/' ) ) { @unlink( $dir . '/' . $probe ); return false; }
    $url    = str_replace( $upload['basedir'], $upload['baseurl'], $dir ) . '/' . $probe;

	// Fail closed: only a verified denial counts. A definitive 401/403/404/410
	// response without the probe body proves the file is not served. Anything
	// else - network error, timeout, redirect, soft-404, 5xx, a 200 without the
	// body - is inconclusive and therefore unsafe.
	$passes   = false;
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 10,
			'sslverify'   => true,
			'redirection' => 0,
		)
	);
	if ( ! is_wp_error( $response ) ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( in_array( $code, array( 401, 403, 404, 410 ), true ) && false === strpos( $body, $secret ) ) {
			$passes = true;
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	@unlink( $dir . '/' . $probe );
	set_transient( $cache_key, $passes ? 'yes' : 'no', DAY_IN_SECONDS );
	return $passes;
}

/**
 * Fail-closed storage check used before writing any backup.
 *
 * @return bool
 */
function ikb_storage_is_safe() {
	if ( defined( 'IKB_BACKUP_DIR' ) && IKB_BACKUP_DIR && ikb_dir_is_public() ) {
		// An explicitly configured public directory must still pass the probe.
		return ikb_storage_probe_passes();
	}
	if ( ! ikb_dir_is_public() ) {
		return true;
	}
	return ikb_storage_probe_passes();
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
