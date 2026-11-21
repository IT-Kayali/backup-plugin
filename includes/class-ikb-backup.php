<?php
/**
 * Backup creation: database dump + wp-content archive.
 *
 * @package IK_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates full backups (database dump plus wp-content files) as a single zip.
 */
class IKB_Backup {

	/**
	 * Create a full backup.
	 *
	 * @param array $args Optional. 'triggered_by' => 'manual'|'cron'|'prerestore'.
	 * @return array|WP_Error Backup info on success.
	 */
	public function create_backup( $args = array() ) {
        $dir = ikb_backup_dir();
        $lock = fopen( $dir . '/operation.lock', 'c' );
        if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
            if ( $lock ) { fclose( $lock ); }
            return new WP_Error( 'ikb_busy', 'Another backup or restore is running. No second operation was started.' );
        }
        try { return $this->create_locked( $args ); }
        catch ( Throwable $e ) { global $wpdb; if ( isset( $wpdb ) ) { $wpdb->query( 'ROLLBACK' ); } return new WP_Error( 'ikb_failed', 'Backup failed. No completed backup was published.' ); }
        finally { foreach ( $this->cleanup as $path ) { is_dir( $path ) ? $this->rmdir( $path ) : ( is_file( $path ) ? unlink( $path ) : null ); } flock( $lock, LOCK_UN ); fclose( $lock ); }
    }

    private function create_locked( $args = array() ) {
		global $wpdb;

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ikb_no_zip', 'PHP ZipArchive extension is not available on this server.' );
		}

		$args = wp_parse_args( $args, array( 'triggered_by' => 'manual', 'scope' => 'full' ) );
        $plugin_only = 'translate-plugin' === $args['scope'];
        $this->deadline = microtime( true ) + 120;
        if ( ! in_array( $args['scope'], array( 'full', 'translate-plugin' ), true ) ) {
            return new WP_Error( 'ikb_scope', 'Unsupported backup scope.' );
        }

		if ( ! ikb_storage_is_safe() ) {
			return new WP_Error(
				'ikb_storage_public',
				'Backup storage is reachable over HTTP, so creating backups there is refused. Set IKB_BACKUP_DIR in wp-config.php to a directory outside the web root, or fix the web server configuration.'
			);
		}

		@set_time_limit( 0 ); // phpcs:ignore
		wp_raise_memory_limit( 'ikb' );

		$dir      = ikb_backup_dir();
		$stamp    = gmdate( 'Ymd-His' );
		$basename = 'ikb-backup-' . $stamp . '-' . wp_generate_password( 6, false, false ) . '.zip';
		$tmp      = $dir . '/tmp-' . $stamp . '-' . wp_generate_password( 8, false, false );
		$this->cleanup[] = $tmp;
        if ( ! wp_mkdir_p( $tmp ) ) {
			return new WP_Error( 'ikb_tmp', 'Could not create temporary backup directory.' );
		}

		// 1. Database dump. One statement per line so the restore can stream line by line.
		$sql_file = $tmp . '/db.sql';
		$db_stats = $plugin_only ? array( 'tables' => 0, 'rows' => 0 ) : $this->dump_database( $sql_file );
		if ( is_wp_error( $db_stats ) ) {
			$this->rmdir( $tmp );
			return $db_stats;
		}

		// 2. Manifest.
		$manifest = array(
			'format'        => 'ik-backup/1',
            'scope'         => $args['scope'],
            'excluded'      => array( 'known backup archives/directories', 'cache directories', 'symbolic links' ),
			'plugin_version'=> IKB_VERSION,
			'created_gmt'   => gmdate( 'c' ),
			'triggered_by'  => $args['triggered_by'],
			'site_url'      => home_url(),
			'wp_version'    => get_bloginfo( 'version' ),
			'php_version'   => phpversion(),
			'db_server'     => $wpdb->db_server_info(),
			'table_prefix'  => $wpdb->prefix,
			'db_name'       => $wpdb->dbname,
			'db_tables'     => $db_stats['tables'],
			'db_rows'       => $db_stats['rows'],
			'db_sha256'     => $plugin_only ? null : hash_file( 'sha256', $sql_file ),
			'abspath'       => ABSPATH,
		);
        $manifest['file_sha256'] = array();
        $source = $plugin_only ? WP_CONTENT_DIR . '/plugins/it-kayali-translate' : WP_CONTENT_DIR;
        if ( ! is_dir( $source ) ) { $this->rmdir( $tmp ); return new WP_Error( 'ikb_source', 'Translate plugin directory was not found.' ); }
        $entries = $this->collect_files( $source, $dir );
        if ( is_wp_error( $entries ) ) { $this->rmdir( $tmp ); return $entries; }
        $required = ( $plugin_only ? 0 : filesize( $sql_file ) ) + 16777216;
        foreach ( $entries as $entry ) { $required += $entry['size']; }
        $free = disk_free_space( $dir );
        if ( false === $free || $free < $required * 1.2 ) {
            $this->rmdir( $tmp ); return new WP_Error( 'ikb_space', 'Not enough verified free disk space for this backup. Nothing was published.' );
        }
        $basename = 'ikb-backup-' . $stamp . '-' . wp_generate_password( 10, false, false ) . '.zip';
        $zip_path = $dir . '/' . $basename;
        $partial = $zip_path . '.partial';
        $this->cleanup[] = $partial;
        $zip = new ZipArchive();
        if ( true !== $zip->open( $partial, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            $this->rmdir( $tmp ); return new WP_Error( 'ikb_zip_open', 'Could not create backup archive.' );
        }
        if ( method_exists( $zip, 'registerCancelCallback' ) ) {
            $zip->registerCancelCallback( function () { return microtime( true ) > $this->deadline ? 1 : 0; } );
        }
        $bytes = 0;
        $ok = true;
        foreach ( $entries as $entry ) {
            if ( microtime( true ) > $this->deadline ) { $ok = false; break; }
            $hash = hash_file( 'sha256', $entry['path'] );
            if ( false === $hash || ! $zip->addFile( $entry['path'], $entry['local'] ) ) { $ok = false; break; }
            $manifest['file_sha256'][$entry['local']] = $hash;
            $bytes += $entry['size'];
        }
        if ( ! $plugin_only && ! $zip->addFile( $sql_file, 'db.sql' ) ) { $ok = false; }
        if ( ! $zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) ) ) { $ok = false; }
        $closed = $zip->close();
        if ( ! $ok || ! $closed || microtime( true ) > $this->deadline ) {
            @unlink( $partial ); $this->rmdir( $tmp );
            return new WP_Error( 'ikb_incomplete', 'Backup did not finish within its 120-second work budget, or a file/archive write failed. No completed backup was published. Use a smaller plugin snapshot or a host backup for a larger site.' );
        }
        $verified = IKB_Restore::verify_archive( $partial );
        if ( is_wp_error( $verified ) || ! rename( $partial, $zip_path ) ) {
            @unlink( $partial ); $this->rmdir( $tmp );
            return new WP_Error( 'ikb_verify', 'Backup archive verification failed. No completed backup was published.' );
        }
        $this->rmdir( $tmp );
        $file_stats = array( 'files' => count( $entries ), 'bytes' => $bytes );

		return array(
			'file'       => $basename,
			'path'       => $zip_path,
			'size'       => filesize( $zip_path ),
			'created'    => $manifest['created_gmt'],
			'db_tables'  => $db_stats['tables'],
			'db_rows'    => $db_stats['rows'],
			'file_count' => $file_stats['files'],
			'file_bytes' => $file_stats['bytes'],
		);
	}

	/**
	 * Dump every table in the WordPress database to a SQL file, one statement per line.
	 *
	 * @param string $path Target file path.
	 * @return array|WP_Error Stats: tables, rows.
	 */
	private function dump_database( $path ) {
		global $wpdb;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( $path, 'wb' );
		if ( ! $out ) {
			return new WP_Error( 'ikb_sql_open', 'Could not write database dump file.' );
		}

		$this->write_sql( $out, "-- IK Backup database dump\n" );
		$this->write_sql( $out, '-- Created (GMT): ' . gmdate( 'c' ) . "\n" );
		$this->write_sql( $out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET sql_mode='NO_AUTO_VALUE_ON_ZERO';\n" );

		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) || false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ) ) {
            fclose( $out ); return new WP_Error( 'ikb_db_snapshot', 'Could not start a consistent database snapshot.' );
        }
        $tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( $wpdb->last_error || ! $tables ) { $wpdb->query( 'ROLLBACK' ); fclose( $out ); return new WP_Error( 'ikb_db_tables', 'Could not enumerate database tables.' ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows   = 0;

		foreach ( $tables as $table ) {
            if ( microtime( true ) > $this->deadline ) { $wpdb->query( 'ROLLBACK' ); fclose( $out ); return new WP_Error( 'ikb_db_timeout', 'Database backup exceeded its work budget.' ); }
            $table = str_replace( '`', '``', $table );
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $create || empty( $create[1] ) ) {
				$wpdb->query( 'ROLLBACK' ); fclose( $out ); // phpcs:ignore
				return new WP_Error( 'ikb_show_create', 'Could not read schema for table ' . esc_html( $table ) );
			}
			$this->write_sql( $out, "\n-- Table: {$table}\nDROP TABLE IF EXISTS `{$table}`;\n" );
			// Normalize to a single line: the restore streams statement-by-statement.
			$create_sql = preg_replace( '/\s*\R\s*/', ' ', $create[1] );
			$this->write_sql( $out, $create_sql . ";\n" );

			// Column types, to hex-encode binary data.
			$binary_cols = array();
			$cols        = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $wpdb->last_error || ! $cols ) { $wpdb->query( 'ROLLBACK' ); fclose( $out ); return new WP_Error( 'ikb_db_columns', 'Could not read database columns.' ); }
            foreach ( (array) $cols as $col ) {
				if ( preg_match( '/blob|binary/i', $col->Type ) ) {
					$binary_cols[] = $col->Field;
				}
			}

			$offset  = 0;
			$chunk   = 500;
			do {
				$data = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$offset}, {$chunk}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( $wpdb->last_error || ! is_array( $data ) || microtime( true ) > $this->deadline ) { $wpdb->query( 'ROLLBACK' ); fclose( $out ); return new WP_Error( 'ikb_db_read', 'Database read failed or exceeded its work budget.' ); }
                foreach ( (array) $data as $row ) {
					$values = array();
					foreach ( $row as $field => $value ) {
						if ( null === $value ) {
							$values[] = 'NULL';
						} elseif ( in_array( $field, $binary_cols, true ) ) {
							$values[] = "X'" . bin2hex( (string) $value ) . "'";
						} else {
							// mysqli_real_escape_string keeps newlines escaped, so each INSERT stays on one line.
							$values[] = "'" . $wpdb->_real_escape( (string) $value ) . "'";
						}
					}
					$this->write_sql( $out, "INSERT INTO `{$table}` VALUES (" . implode( ',', $values ) . ");\n" );
					$rows++;
				}
				$offset += $chunk;
			} while ( is_array( $data ) && count( $data ) === $chunk );
		}

		$this->write_sql( $out, "SET FOREIGN_KEY_CHECKS=1;\n" );
		$position = ftell( $out );
        $flushed = fflush( $out );
        $closed = fclose( $out );
        $wpdb->query( 'ROLLBACK' );
        clearstatcache( true, $path );
        if ( ! $flushed || ! $closed || false === $position || $position !== filesize( $path ) ) { return new WP_Error( 'ikb_db_write', 'Database dump write failed.' ); }

        return array(
			'tables' => count( $tables ),
			'rows'   => $rows,
		);
	}

    private $deadline;
    private $cleanup = array();
    private function write_sql( $stream, $text ) { if ( strlen( $text ) !== fwrite( $stream, $text ) ) { throw new RuntimeException( 'Database dump write failed.' ); } }

    private function collect_files( $source, $dir ) {
        $items = array();
        $excluded_dirs = array( 'cache', 'wpcache', 'wp-super-cache', 'ai1wm-backups', 'updraft', 'backups', 'backup', 'wpvividbackups', 'backupwordpress', 'wp-clone' );
        $it = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
            function ( $file ) use ( $dir, $excluded_dirs ) {
                $path = $file->getPathname();
                if ( $file->isLink() || $path === $dir || 0 === strpos( $path, $dir . '/' ) ) { return false; }
                if ( $file->isDir() && ( in_array( strtolower( $file->getFilename() ), $excluded_dirs, true ) || preg_match( '/^(ik-backups-|wpstg|staging-backup)/i', $file->getFilename() ) ) ) { return false; }
                if ( preg_match( '/\.(wpstg|wpress|sql|sql\.gz|zip\.partial)$/i', $path ) || preg_match( '/(backup|updraft|duplicator).*\.(zip|gz|tar|bak)$/i', $file->getFilename() ) ) { return false; }
                return true;
            }
        ) );
        foreach ( $it as $file ) {
            if ( microtime( true ) > $this->deadline || count( $items ) >= 100000 ) { return new WP_Error( 'ikb_budget', 'Backup scan exceeded its work budget. Use a smaller snapshot or host backup.' ); }
            if ( ! $file->isFile() ) { continue; }
            if ( ! $file->isReadable() ) { return new WP_Error( 'ikb_unreadable', 'An included source file is unreadable. Backup aborted.' ); }
            $path = $file->getPathname();
            $items[] = array( 'path' => $path, 'local' => 'wp-content' . substr( $path, strlen( rtrim( WP_CONTENT_DIR, '/' ) ) ), 'size' => $file->getSize() );
        }
        return $items;
    }

	/**
	 * Recursively add a directory to the zip.
	 *
	 * @param ZipArchive $zip     Open archive.
	 * @param string     $source  Absolute source directory.
	 * @param string     $prefix  Path prefix inside the archive.
	 * @param array      $exclude Absolute directories to skip.
	 * @return array|WP_Error Stats: files, bytes.
	 */
	private function add_directory_to_zip( $zip, $source, $prefix, $exclude = array() ) {
		$source = rtrim( $source, '/' );
		if ( ! is_dir( $source ) ) {
			return new WP_Error( 'ikb_no_source', 'Directory not found: ' . esc_html( $source ) );
		}
		$files = 0;
		$bytes = 0;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
				function ( $file ) use ( $exclude ) {
					$path = $file->getPathname();
					foreach ( $exclude as $ex ) {
						if ( 0 === strpos( $path, rtrim( $ex, '/' ) . '/' ) || $path === rtrim( $ex, '/' ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || ! $file->isReadable() ) {
				continue;
			}
			$path  = $file->getPathname();
			$local = $prefix . substr( $path, strlen( $source ) );
			if ( ! $zip->addFile( $path, $local ) ) {
				return new WP_Error( 'ikb_zip_add', 'Could not add file to archive: ' . esc_html( $path ) );
			}
			$files++;
			$bytes += $file->getSize();
		}

		return array( 'files' => $files, 'bytes' => $bytes );
	}

	/**
	 * List existing backups, newest first.
	 *
	 * @return array[] Each: file, path, size, created, manifest (when readable).
	 */
	public static function list_backups() {
		$dir     = ikb_backup_dir();
		$backups = array();
		foreach ( glob( $dir . '/ikb-backup-*.zip' ) ?: array() as $path ) {
			$backups[] = array(
				'file'    => basename( $path ),
				'path'    => $path,
				'size'    => filesize( $path ),
				'created' => gmdate( 'c', filemtime( $path ) ),
			);
		}
		usort(
			$backups,
			function ( $a, $b ) {
				return strcmp( $b['file'], $a['file'] );
			}
		);
		return $backups;
	}

	/**
	 * Read the manifest from a backup archive.
	 *
	 * @param string $zip_path Archive path.
	 * @return array|WP_Error
	 */
	public static function read_manifest( $zip_path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'ikb_zip_read', 'Could not open backup archive.' );
		}
		$json = $zip->getFromName( 'manifest.json' );
		$zip->close();
		if ( ! $json ) {
			return new WP_Error( 'ikb_no_manifest', 'Backup archive has no manifest.json.' );
		}
		$manifest = json_decode( $json, true );
		if ( ! is_array( $manifest ) || empty( $manifest['format'] ) || 'ik-backup/1' !== $manifest['format'] ) {
			return new WP_Error( 'ikb_bad_manifest', 'Backup manifest is missing or has an unsupported format.' );
		}
		return $manifest;
	}

	/**
	 * Remove a temporary directory tree.
	 *
	 * @param string $dir Path.
	 */
	private function rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		rmdir( $dir );
	}
}
