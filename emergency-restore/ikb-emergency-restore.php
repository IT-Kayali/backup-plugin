<?php
/**
 * IK Backup - standalone emergency restore script.
 *
 * Deployed to the WordPress root by the IK Backup admin page. Restores a
 * backup archive without loading WordPress, for the case where wp-admin or
 * the whole site is broken but PHP still runs (browser mode) or you have
 * command-line access (CLI mode).
 *
 * CLI:     php ikb-emergency-restore.php <backup-file.zip> [--wp-root=/path] [--db-only] [--yes]
 * Browser: https://example.com/ikb-emergency-restore.php?token=TOKEN
 *          then pick a backup and type RESTORE to confirm.
 *
 * Limits:  this script cannot help when the web server or PHP itself is
 *          down, or when the server storage is lost. Keep off-server copies.
 *          A restore overwrites the whole database, including orders and
 *          users created after the backup was taken.
 */

define( 'IKB_ER_TOKEN', '%%IKB_TOKEN%%' );
define( 'IKB_ER_BACKUP_DIR', '%%IKB_BACKUP_DIR%%' );

/**
 * Minimal esc_html for standalone use.
 *
 * @param string $s Input.
 * @return string
 */
function ikb_er_esc( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

/**
 * Send a plain-text/HTML line depending on mode.
 *
 * @param string $msg Message.
 */
function ikb_er_out( $msg ) {
	if ( 'cli' === PHP_SAPI ) {
		echo $msg . "\n";
	} else {
		echo ikb_er_esc( $msg ) . "<br>\n";
		flush();
	}
}

set_time_limit( 0 ); // phpcs:ignore

$is_cli  = ( 'cli' === PHP_SAPI );
$db_only = false;
$assume_yes = false;

// --- Locate WordPress root and backup -------------------------------------
$wp_root = __DIR__;
$backup  = '';

if ( $is_cli ) {
	$args = $argv;
	array_shift( $args );
	foreach ( $args as $arg ) {
		if ( 0 === strpos( $arg, '--wp-root=' ) ) {
			$wp_root = rtrim( substr( $arg, 10 ), '/' );
		} elseif ( '--db-only' === $arg ) {
			$db_only = true;
		} elseif ( '--yes' === $arg ) {
			$assume_yes = true;
		} elseif ( '' === $backup ) {
			$backup = $arg;
		}
	}
	if ( '' === $backup ) {
		echo "Usage: php ikb-emergency-restore.php <backup-file.zip> [--wp-root=/path] [--db-only] [--yes]\n";
		echo "Backups in " . IKB_ER_BACKUP_DIR . ":\n";
		foreach ( glob( IKB_ER_BACKUP_DIR . '/ikb-backup-*.zip' ) ?: array() as $f ) {
			echo '  ' . basename( $f ) . "\n";
		}
		exit( 1 );
	}
} else {
	$token = isset( $_POST['token'] ) ? $_POST['token'] : ( isset( $_GET['token'] ) ? $_GET['token'] : '' );
	if ( ! is_string( $token ) || ! hash_equals( IKB_ER_TOKEN, $token ) || false !== strpos( IKB_ER_TOKEN, '%IKB_' ) ) {
		http_response_code( 403 );
		exit( 'Forbidden' );
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	echo '<!doctype html><title>IK Backup emergency restore</title><h1>IK Backup emergency restore</h1>';

	$db_only = ! empty( $_POST['db_only'] );
	if ( isset( $_POST['backup'] ) ) {
		$backup = basename( (string) $_POST['backup'] );
		// Typed confirmation: the word RESTORE must be entered.
		$confirm = isset( $_POST['confirm'] ) ? (string) $_POST['confirm'] : '';
		if ( 'RESTORE' !== $confirm ) {
			echo '<p><strong>Confirmation missing.</strong> Type RESTORE to confirm.</p>';
			$backup = '';
		}
	}
	if ( '' === $backup ) {
		echo '<p style="color:#b00"><strong>Warning:</strong> a restore overwrites files and the whole database, including orders and users created after the backup was taken.</p>';
		echo '<form method="post"><input type="hidden" name="token" value="' . ikb_er_esc( $token ) . '">';
		echo '<p>Backup: <select name="backup">';
		foreach ( glob( IKB_ER_BACKUP_DIR . '/ikb-backup-*.zip' ) ?: array() as $f ) {
			echo '<option value="' . ikb_er_esc( basename( $f ) ) . '">' . ikb_er_esc( basename( $f ) . ' (' . round( filesize( $f ) / 1048576, 1 ) . ' MB)' ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label><input type="checkbox" name="db_only" value="1"> database only (keep current files)</label></p>';
		echo '<p>Type RESTORE to confirm: <input type="text" name="confirm" autocomplete="off"> ';
		echo '<button type="submit">Restore now</button></p></form>';
		exit;
	}
}

// Resolve the archive path safely (basename only, no traversal).
$zip_path = IKB_ER_BACKUP_DIR . '/' . basename( $backup );
if ( ! is_readable( $zip_path ) ) {
	// Also allow a full path in CLI mode.
	if ( $is_cli && is_readable( $backup ) ) {
		$zip_path = $backup;
	} else {
		ikb_er_out( 'ERROR: backup not found: ' . basename( $backup ) );
		exit( 1 );
	}
}

// CLI confirmation.
if ( $is_cli && ! $assume_yes ) {
	echo "About to restore " . basename( $zip_path ) . " over " . $wp_root . ( $db_only ? ' (database only)' : ' (files + database)' ) . ".\n";
	echo "This OVERWRITES the database, including orders and users created after the backup.\n";
	echo "Type RESTORE to continue: ";
	$line = trim( (string) fgets( STDIN ) );
	if ( 'RESTORE' !== $line ) {
		echo "Aborted.\n";
		exit( 1 );
	}
}

// --- Read wp-config.php for DB credentials --------------------------------
$config_file = $wp_root . '/wp-config.php';
if ( ! is_readable( $config_file ) ) {
	ikb_er_out( 'ERROR: wp-config.php not found at the WordPress root' );
	exit( 1 );
}
$config = file_get_contents( $config_file ); // phpcs:ignore

/**
 * Pull a constant definition out of wp-config.php.
 *
 * @param string $name Constant name.
 * @param string $src  wp-config.php contents.
 * @return string
 */
function ikb_er_config( $name, $src ) {
	if ( preg_match( "/define\s*\(\s*['\"]" . $name . "['\"]\s*,\s*'((?:[^'\\\\]|\\\\.)*)'\s*\)/", $src, $m ) ) {
		return stripcslashes( $m[1] );
	}
	return '';
}

$db_name = ikb_er_config( 'DB_NAME', $config );
$db_user = ikb_er_config( 'DB_USER', $config );
$db_pass = ikb_er_config( 'DB_PASSWORD', $config );
$db_host = ikb_er_config( 'DB_HOST', $config );

if ( '' === $db_name || '' === $db_host ) {
	ikb_er_out( 'ERROR: could not read DB credentials from wp-config.php' );
	exit( 1 );
}

// Support host:port and host:/socket notations.
$host = $db_host;
$port = null;
$sock = null;
if ( preg_match( '/^([^:]+):(\d+)$/', $db_host, $m ) ) {
	$host = $m[1];
	$port = (int) $m[2];
} elseif ( preg_match( '/^([^:]+):(\/.+)$/', $db_host, $m ) ) {
	$host = $m[1];
	$sock = $m[2];
}

// --- Open and validate archive ---------------------------------------------
$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path ) ) {
	ikb_er_out( 'ERROR: could not open archive' );
	exit( 1 );
}
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$name = $zip->getNameIndex( $i );
	if ( preg_match( '#(^|[/\\\\])\.\.([/\\\\]|$)#', $name ) || preg_match( '#^([/\\\\]|[a-zA-Z]:[/\\\\])#', $name ) ) {
		ikb_er_out( 'ERROR: archive contains unsafe paths and was rejected: ' . $name );
		$zip->close();
		exit( 1 );
	}
}
$manifest_json = $zip->getFromName( 'manifest.json' );
$sql           = $zip->getFromName( 'db.sql' );
if ( ! $manifest_json || ! $sql ) {
	ikb_er_out( 'ERROR: archive is missing manifest.json or db.sql' );
	$zip->close();
	exit( 1 );
}
$manifest = json_decode( $manifest_json, true );
ikb_er_out( 'Backup: ' . basename( $zip_path ) . ' from ' . ( $manifest['created_gmt'] ?? 'unknown date' ) . ' (site ' . ( $manifest['site_url'] ?? '?' ) . ')' );

// --- Restore files ---------------------------------------------------------
$tmp = sys_get_temp_dir() . '/ikb-er-' . getmypid() . '-' . substr( md5( uniqid() ), 0, 6 );
if ( ! is_dir( $tmp ) ) {
	mkdir( $tmp, 0700, true );
}
if ( ! $zip->extractTo( $tmp ) ) {
	ikb_er_out( 'ERROR: could not extract archive' );
	$zip->close();
	exit( 1 );
}
$zip->close();

/**
 * Recursively copy a directory tree.
 *
 * @param string $src Source.
 * @param string $dst Destination.
 * @return int Files copied.
 */
function ikb_er_copy( $src, $dst ) {
	$count = 0;
	$src   = rtrim( $src, '/' );
	$dst   = rtrim( $dst, '/' );
	$it    = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $it as $file ) {
		$target = $dst . substr( $file->getPathname(), strlen( $src ) );
		if ( $file->isDir() ) {
			if ( ! is_dir( $target ) ) {
				mkdir( $target, 0755, true );
			}
		} else {
			if ( ! is_dir( dirname( $target ) ) ) {
				mkdir( dirname( $target ), 0755, true );
			}
			copy( $file->getPathname(), $target );
			$count++;
		}
	}
	return $count;
}

$copied = 0;
if ( ! $db_only && is_dir( $tmp . '/wp-content' ) ) {
	$copied = ikb_er_copy( $tmp . '/wp-content', $wp_root . '/wp-content' );
}
ikb_er_out( 'Files restored: ' . $copied . ( $db_only ? ' (database-only mode)' : '' ) );

// --- Restore database ------------------------------------------------------
mysqli_report( MYSQLI_REPORT_OFF );
$db = mysqli_init();
$ok = $sock
	? mysqli_real_connect( $db, $host, $db_user, $db_pass, $db_name, null, $sock )
	: mysqli_real_connect( $db, $host, $db_user, $db_pass, $db_name, $port );
if ( ! $ok ) {
	ikb_er_out( 'ERROR: database connection failed (check DB credentials in wp-config.php)' );
	exit( 1 );
}
mysqli_set_charset( $db, 'utf8mb4' );

$sql_file = $tmp . '/db.sql';
$in       = fopen( $sql_file, 'rb' ); // phpcs:ignore
$count    = 0;
$buffer   = '';
while ( false !== ( $line = fgets( $in ) ) ) {
	$line = trim( $line );
	if ( '' === $line || 0 === strpos( $line, '--' ) ) {
		continue;
	}
	$buffer .= $line;
	if ( ';' !== substr( $buffer, -1 ) ) {
		continue;
	}
	if ( ! mysqli_query( $db, $buffer ) ) {
		ikb_er_out( 'ERROR: database restore failed at statement ' . ( $count + 1 ) . ': ' . mysqli_error( $db ) );
		fclose( $in ); // phpcs:ignore
		exit( 1 );
	}
	$count++;
	$buffer = '';
}
fclose( $in ); // phpcs:ignore
ikb_er_out( 'Database statements executed: ' . $count );

// Keep this install reachable if the backup came from another URL.
$current_url = '';
if ( ! $is_cli ) {
	$scheme      = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) ? 'https' : 'http';
	$current_url = $scheme . '://' . $_SERVER['HTTP_HOST'];
	if ( $current_url && isset( $manifest['site_url'] ) && rtrim( (string) $manifest['site_url'], '/' ) !== rtrim( $current_url, '/' ) ) {
		$prefix = isset( $manifest['table_prefix'] ) && preg_match( '/^[A-Za-z0-9_]+$/', $manifest['table_prefix'] ) ? $manifest['table_prefix'] : 'wp_';
		mysqli_query( $db, "UPDATE `{$prefix}options` SET option_value='" . mysqli_real_escape_string( $db, $current_url ) . "' WHERE option_name IN ('siteurl','home')" );
		ikb_er_out( 'Adjusted siteurl/home to ' . $current_url );
	}
}

// --- Cleanup ---------------------------------------------------------------
$it = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::CHILD_FIRST
);
foreach ( $it as $file ) {
	$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); // phpcs:ignore
}
rmdir( $tmp ); // phpcs:ignore

ikb_er_out( 'DONE. Restore complete. Log in and verify the site. Consider rotating this script: redeploy it from the IK Backup admin page to get a new token.' );
if ( ! $is_cli ) {
	echo '<p><a href="/wp-admin/">Go to wp-admin</a></p>';
}
