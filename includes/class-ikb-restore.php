<?php
/**
 * Restore logic: files + database from an IK Backup archive.
 *
 * @package IK_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restores a backup archive created by IKB_Backup.
 */
class IKB_Restore {

	/**
	 * Restore a backup.
	 *
	 * @param string $zip_path Absolute path of the backup archive.
	 * @param array  $args     Optional. 'safety_backup' => bool, 'keep_files' => bool (only restore DB).
	 * @return array|WP_Error Restore stats.
	 */
	public function restore_backup( $zip_path, $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'safety_backup' => true,
				'keep_files'    => false,
			)
		);

		if ( ! is_readable( $zip_path ) ) {
			return new WP_Error( 'ikb_no_backup', 'Backup archive not found.' );
		}

		$manifest = IKB_Backup::read_manifest( $zip_path );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		@set_time_limit( 0 ); // phpcs:ignore
		wp_raise_memory_limit( 'ikb' );

		// Capture before the import overwrites the options table.
		$current_home = home_url();

		$safety = null;
		if ( $args['safety_backup'] ) {
			$backup = new IKB_Backup();
			$safety = $backup->create_backup( array( 'triggered_by' => 'prerestore' ) );
			if ( is_wp_error( $safety ) ) {
				return new WP_Error( 'ikb_safety_failed', 'Safety backup before restore failed, restore aborted: ' . $safety->get_error_message() );
			}
		}

		$tmp = ikb_backup_dir() . '/restore-' . gmdate( 'Ymd-His' );
		if ( ! wp_mkdir_p( $tmp ) ) {
			return new WP_Error( 'ikb_tmp', 'Could not create temporary restore directory.' );
		}

		$stats = array(
			'safety_backup' => $safety ? $safety['file'] : null,
			'files_restored'=> 0,
			'db_statements' => 0,
		);

		// 1. Files.
		if ( ! $args['keep_files'] ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $zip_path ) ) {
				return new WP_Error( 'ikb_zip_read', 'Could not open backup archive.' );
			}
			if ( ! $zip->extractTo( $tmp ) ) {
				$zip->close();
				return new WP_Error( 'ikb_extract', 'Could not extract backup archive.' );
			}
			$zip->close();

			$src = $tmp . '/wp-content';
			if ( is_dir( $src ) ) {
				$stats['files_restored'] = $this->copy_tree( $src, WP_CONTENT_DIR );
			}
		}

		// 2. Database. db.sql uses one statement per line (see IKB_Backup::dump_database).
		$sql = $tmp . '/db.sql';
		if ( ! is_file( $sql ) ) {
			// Not extracted above (database-only mode): pull it straight from the archive.
			$zip = new ZipArchive();
			if ( true === $zip->open( $zip_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $sql, $zip->getFromName( 'db.sql' ) );
				$zip->close();
			}
		}
		if ( ! is_file( $sql ) ) {
			$this->rmdir( $tmp );
			return new WP_Error( 'ikb_no_sql', 'Backup archive has no db.sql.' );
		}

		$import = $this->import_sql( $sql );
		if ( is_wp_error( $import ) ) {
			$this->rmdir( $tmp );
			return $import;
		}
		$stats['db_statements'] = $import;

		// 3. Finish: clear caches and rewrite rules.
		wp_cache_flush();
		flush_rewrite_rules( false );

		$this->rmdir( $tmp );

		// If the backup was taken at a different URL, keep this site's address working.
		if ( ! empty( $manifest['site_url'] ) && untrailingslashit( $manifest['site_url'] ) !== untrailingslashit( $current_home ) ) {
			update_option( 'siteurl', $current_home );
			update_option( 'home', $current_home );
			$stats['url_fixed'] = true;
		}

		return $stats;
	}

	/**
	 * Import a one-statement-per-line SQL dump through the WordPress database connection.
	 *
	 * @param string $path SQL file path.
	 * @return int|WP_Error Number of executed statements.
	 */
	private function import_sql( $path ) {
		global $wpdb;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$in = fopen( $path, 'rb' );
		if ( ! $in ) {
			return new WP_Error( 'ikb_sql_read', 'Could not read database dump from archive.' );
		}

		$count  = 0;
		$buffer = '';
		while ( false !== ( $line = fgets( $in ) ) ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '--' ) ) {
				continue;
			}
			// Statements end with a semicolon at line end; semicolons inside data
			// always sit within quoted strings, which end with a quote.
			$buffer .= $line;
			if ( ';' !== substr( $buffer, -1 ) ) {
				continue;
			}
			$result = $wpdb->query( $buffer ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $result ) {
				fclose( $in ); // phpcs:ignore
				return new WP_Error(
					'ikb_sql_import',
					'Database restore failed at statement ' . ( $count + 1 ) . ': ' . esc_html( $wpdb->last_error )
				);
			}
			$count++;
			$buffer = '';
		}
		fclose( $in ); // phpcs:ignore

		return $count;
	}

	/**
	 * Copy a directory tree over a destination, returning the number of files copied.
	 *
	 * @param string $src Source directory.
	 * @param string $dst Destination directory.
	 * @return int
	 */
	private function copy_tree( $src, $dst ) {
		$count = 0;
		$src   = rtrim( $src, '/' );
		$dst   = rtrim( $dst, '/' );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $file ) {
			$target = $dst . substr( $file->getPathname(), strlen( $src ) );
			if ( $file->isDir() ) {
				if ( ! is_dir( $target ) ) {
					wp_mkdir_p( $target );
				}
			} else {
				if ( ! is_dir( dirname( $target ) ) ) {
					wp_mkdir_p( dirname( $target ) );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.copy_copy
				copy( $file->getPathname(), $target );
				$count++;
			}
		}
		return $count;
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
