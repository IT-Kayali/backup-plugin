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
        $manifest = self::verify_archive( $zip_path );
        if ( is_wp_error( $manifest ) ) { return $manifest; }
        if ( 'translate-plugin' === ( $manifest['scope'] ?? '' ) ) { return $this->restore_translate_plugin( $zip_path ); }
        if ( untrailingslashit( $manifest['site_url'] ?? '' ) !== untrailingslashit( home_url() ) ) { return new WP_Error( 'ikb_site', 'Restore is limited to this site.' ); }
        $args = wp_parse_args( $args, array( 'safety_backup' => true, 'keep_files' => false ) );
        if ( $args['safety_backup'] ) {
            $safety = ( new IKB_Backup() )->create_backup( array( 'triggered_by' => 'prerestore' ) );
            if ( is_wp_error( $safety ) ) { return $safety; }
            $args['safety_backup'] = false;
        }
        $lock = fopen( ikb_backup_dir() . '/operation.lock', 'c' );
        if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) { if ( $lock ) { fclose( $lock ); } return new WP_Error( 'ikb_busy', 'Another operation is running.' ); }
        try { return $this->restore_full_locked( $zip_path, $args ); }
        catch ( Throwable $e ) { return new WP_Error( 'ikb_restore_incomplete', 'Restore did not complete. Retain the backup and inspect the target before retrying.' ); }
        finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
    }

    private function restore_full_locked( $zip_path, $args = array() ) {
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

		$manifest = self::verify_archive( $zip_path );
		if ( is_wp_error( $manifest ) ) { return $manifest; }
        if ( 'translate-plugin' === ( $manifest['scope'] ?? '' ) ) { return $this->restore_translate_plugin( $zip_path ); }

		$safe = self::validate_archive_paths( $zip_path );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$integrity = self::verify_db_integrity( $zip_path, $manifest );
		if ( is_wp_error( $integrity ) ) {
			return $integrity;
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

    /** Verify all included files and the database before any mutation. */
    public static function verify_archive( $path ) {
        $safe = self::validate_archive_paths( $path );
        if ( is_wp_error( $safe ) ) { return $safe; }
        $m = IKB_Backup::read_manifest( $path );
        if ( is_wp_error( $m ) ) { return $m; }
        $scope = $m['scope'] ?? 'full';
        if ( ! in_array( $scope, array( 'full', 'translate-plugin' ), true ) ) { return new WP_Error( 'ikb_scope', 'Unsupported backup scope.' ); }
        if ( 'full' === $scope ) {
            $db = self::verify_db_integrity( $path, $m );
            if ( is_wp_error( $db ) ) { return $db; }
        }
        $zip = new ZipArchive();
        if ( true !== $zip->open( $path, ZipArchive::CHECKCONS ) ) { return new WP_Error( 'ikb_zip', 'Archive consistency check failed.' ); }
        $hashes = $m['file_sha256'] ?? array();
        if ( empty( $hashes ) ) { $zip->close(); return new WP_Error( 'ikb_hashes', 'This archive lacks per-file checksums. Create a fresh verified backup.' ); }
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = $zip->getNameIndex( $i );
            if ( 'manifest.json' === $name || ( 'full' === $scope && 'db.sql' === $name ) ) { continue; }
            $prefix = 'translate-plugin' === $scope ? 'wp-content/plugins/it-kayali-translate/' : 'wp-content/';
            if ( 0 !== strpos( $name, $prefix ) || ! isset( $hashes[$name] ) ) { $zip->close(); return new WP_Error( 'ikb_entry', 'Archive contains unexpected or unchecked files.' ); }
        }
        foreach ( $hashes as $name => $hash ) {
            $stream = $zip->getStream( $name );
            if ( ! $stream ) { $zip->close(); return new WP_Error( 'ikb_file_missing', 'An included backup file is missing.' ); }
            $ctx = hash_init( 'sha256' ); hash_update_stream( $ctx, $stream ); fclose( $stream );
            if ( ! is_string( $hash ) || ! hash_equals( $hash, hash_final( $ctx ) ) ) { $zip->close(); return new WP_Error( 'ikb_file_checksum', 'Backup file checksum mismatch. Nothing was changed.' ); }
        }
        $zip->close();
        if ( 'translate-plugin' === $scope && ! isset( $hashes['wp-content/plugins/it-kayali-translate/it-kayali-translate.php'] ) ) { return new WP_Error( 'ikb_plugin_missing', 'Translate plugin entry point is missing.' ); }
        return $m;
    }

    /** Replace only Translate plugin files. Never executes SQL or changes options. */
    public function restore_translate_plugin( $path ) {
        $lock = fopen( ikb_backup_dir() . '/operation.lock', 'c' );
        if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) { if ( $lock ) { fclose( $lock ); } return new WP_Error( 'ikb_busy', 'Another operation is running.' ); }
        $tmp = ikb_backup_dir() . '/plugin-restore-' . wp_generate_password( 12, false, false );
        try {
            $m = self::verify_archive( $path );
            if ( is_wp_error( $m ) ) { return $m; }
            if ( 'translate-plugin' !== ( $m['scope'] ?? '' ) || untrailingslashit( $m['site_url'] ?? '' ) !== untrailingslashit( home_url() ) ) { return new WP_Error( 'ikb_site', 'Only a Translate snapshot from this site may be used for plugin rollback.' ); }
            if ( ! wp_mkdir_p( $tmp ) ) { return new WP_Error( 'ikb_tmp', 'Could not stage plugin rollback.' ); }
            $z = new ZipArchive();
            if ( true !== $z->open( $path ) || ! $z->extractTo( $tmp ) ) { $z->close(); return new WP_Error( 'ikb_extract', 'Could not stage plugin files.' ); }
            $z->close();
            $src = $tmp . '/wp-content/plugins/it-kayali-translate';
            $dst = WP_CONTENT_DIR . '/plugins/it-kayali-translate';
            $old = ikb_backup_dir() . '/previous-translate-' . wp_generate_password( 12, false, false );
            if ( is_link( $dst ) || ! is_dir( $dst ) || ! rename( $dst, $old ) ) { return new WP_Error( 'ikb_move', 'Could not retain current plugin files. Nothing replaced.' ); }
            if ( ! rename( $src, $dst ) ) {
                if ( ! rename( $old, $dst ) ) { return new WP_Error( 'ikb_recovery', 'Plugin swap failed and needs manual recovery from the retained previous plugin directory.' ); }
                return new WP_Error( 'ikb_swap', 'Plugin swap failed; original plugin was restored.' );
            }
            if ( function_exists( 'opcache_reset' ) ) { opcache_reset(); }
            return array( 'files_restored' => count( $m['file_sha256'] ), 'db_statements' => 0 );
        } catch ( Throwable $e ) { return new WP_Error( 'ikb_plugin_restore', 'Plugin rollback failed; inspect retained backup before retrying.' ); }
        finally { $this->rmdir( $tmp ); flock( $lock, LOCK_UN ); fclose( $lock ); }
    }

	/**
	 * Verify the database dump against the manifest checksum before anything
	 * destructive happens.
	 *
	 * @param string $zip_path Archive path.
	 * @param array  $manifest Parsed manifest.
	 * @return true|WP_Error
	 */
	public static function verify_db_integrity( $zip_path, $manifest ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'ikb_zip_read', 'Could not open backup archive.' );
		}
        $stream = $zip->getStream( 'db.sql' );
        if ( ! $stream || empty( $manifest['db_sha256'] ) ) { $zip->close(); return new WP_Error( 'ikb_checksum', 'A database dump and checksum are required.' ); }
        $ctx = hash_init( 'sha256' ); hash_update_stream( $ctx, $stream ); fclose( $stream );
        $hash = hash_final( $ctx );
        $stream = $zip->getStream( 'db.sql' ); $found = false;
        while ( $stream && false !== ( $line = fgets( $stream ) ) ) { if ( 0 === strpos( $line, 'CREATE TABLE' ) ) { $found = true; break; } }
        if ( $stream ) { fclose( $stream ); } $zip->close();
        if ( ! $found || ! hash_equals( (string) $manifest['db_sha256'], $hash ) ) { return new WP_Error( 'ikb_checksum', 'Database checksum or schema validation failed. Nothing changed.' ); }
		return true;
	}

	/**
	 * Reject archives whose entries would escape the extraction directory
	 * (path traversal / absolute paths).
	 *
	 * @param string $zip_path Archive path.
	 * @return true|WP_Error
	 */
	public static function validate_archive_paths( $zip_path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'ikb_zip_read', 'Could not open backup archive.' );
		}
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( preg_match( '#(^|[/\\\\])\\.\\.([/\\\\]|$)#', $name ) || preg_match( '#^([/\\\\]|[a-zA-Z]:[/\\\\])#', $name ) ) {
				$zip->close();
				return new WP_Error( 'ikb_unsafe_archive', 'Backup archive contains unsafe paths and was rejected: ' . esc_html( $name ) );
			}
		}
		$zip->close();
		return true;
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
				if ( ! copy( $file->getPathname(), $target ) ) { throw new RuntimeException( 'A restore file copy failed.' ); }
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
