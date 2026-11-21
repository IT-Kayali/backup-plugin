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
		global $wpdb;

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ikb_no_zip', 'PHP ZipArchive extension is not available on this server.' );
		}

		$args = wp_parse_args( $args, array( 'triggered_by' => 'manual' ) );

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
		$tmp      = $dir . '/tmp-' . $stamp;
		if ( ! wp_mkdir_p( $tmp ) ) {
			return new WP_Error( 'ikb_tmp', 'Could not create temporary backup directory.' );
		}

		// 1. Database dump. One statement per line so the restore can stream line by line.
		$sql_file = $tmp . '/db.sql';
		$db_stats = $this->dump_database( $sql_file );
		if ( is_wp_error( $db_stats ) ) {
			$this->rmdir( $tmp );
			return $db_stats;
		}

		// 2. Manifest.
		$manifest = array(
			'format'        => 'ik-backup/1',
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
			'db_sha256'     => hash_file( 'sha256', $sql_file ),
			'abspath'       => ABSPATH,
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

		// 3. Zip: manifest + db.sql + wp-content files.
		$zip_path = $dir . '/' . $basename;
		$zip      = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$this->rmdir( $tmp );
			return new WP_Error( 'ikb_zip_open', 'Could not create backup archive.' );
		}
		$zip->addFile( $tmp . '/manifest.json', 'manifest.json' );
		$zip->addFile( $sql_file, 'db.sql' );

		$file_stats = $this->add_directory_to_zip( $zip, WP_CONTENT_DIR, 'wp-content', array( $dir ) );
		if ( is_wp_error( $file_stats ) ) {
			$zip->close();
			$this->rmdir( $tmp );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $zip_path );
			return $file_stats;
		}
		$zip->close();
		$this->rmdir( $tmp );

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

		fwrite( $out, "-- IK Backup database dump\n" );
		fwrite( $out, '-- Created (GMT): ' . gmdate( 'c' ) . "\n" );
		fwrite( $out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET sql_mode='NO_AUTO_VALUE_ON_ZERO';\n" );

		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows   = 0;

		foreach ( $tables as $table ) {
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $create || empty( $create[1] ) ) {
				fclose( $out ); // phpcs:ignore
				return new WP_Error( 'ikb_show_create', 'Could not read schema for table ' . esc_html( $table ) );
			}
			fwrite( $out, "\n-- Table: {$table}\nDROP TABLE IF EXISTS `{$table}`;\n" );
			// Normalize to a single line: the restore streams statement-by-statement.
			$create_sql = preg_replace( '/\s*\R\s*/', ' ', $create[1] );
			fwrite( $out, $create_sql . ";\n" );

			// Column types, to hex-encode binary data.
			$binary_cols = array();
			$cols        = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $cols as $col ) {
				if ( preg_match( '/blob|binary/i', $col->Type ) ) {
					$binary_cols[] = $col->Field;
				}
			}

			$offset  = 0;
			$chunk   = 500;
			do {
				$data = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$offset}, {$chunk}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				foreach ( (array) $data as $row ) {
					$values = array();
					foreach ( $row as $field => $value ) {
						if ( null === $value ) {
							$values[] = 'NULL';
						} elseif ( in_array( $field, $binary_cols, true ) ) {
							$values[] = "0x" . bin2hex( (string) $value );
						} else {
							// mysqli_real_escape_string keeps newlines escaped, so each INSERT stays on one line.
							$values[] = "'" . $wpdb->_real_escape( (string) $value ) . "'";
						}
					}
					fwrite( $out, "INSERT INTO `{$table}` VALUES (" . implode( ',', $values ) . ");\n" );
					$rows++;
				}
				$offset += $chunk;
			} while ( is_array( $data ) && count( $data ) === $chunk );
		}

		fwrite( $out, "SET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $out ); // phpcs:ignore

		return array(
			'tables' => count( $tables ),
			'rows'   => $rows,
		);
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
