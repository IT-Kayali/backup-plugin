<?php
/**
 * Admin UI: backup list, actions, settings.
 *
 * @package IK_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin pages and form handlers.
 */
class IKB_Admin {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ikb_create', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_ikb_restore', array( __CLASS__, 'handle_restore' ) );
		add_action( 'admin_post_ikb_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_ikb_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_ikb_deploy_emergency', array( __CLASS__, 'handle_deploy_emergency' ) );
		add_action( 'admin_post_ikb_test_emergency', array( __CLASS__, 'handle_test_emergency' ) );
        add_action( 'admin_post_ikb_test_swap', array( __CLASS__, 'handle_test_swap' ) );
		add_action( 'admin_post_ikb_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Add the top-level menu.
	 */
	public static function menu() {
		add_menu_page(
			'IK Backup',
			'IK Backup',
			'manage_options',
			'ik-backup',
			array( __CLASS__, 'render_page' ),
			'dashicons-database',
			80
		);
	}

	/**
	 * Check capability + nonce for an action.
	 *
	 * @param string $action Nonce action name.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the plugin page with a status message.
	 *
	 * @param string $code Message code.
	 * @param string $detail Optional detail (translated text is added at render).
	 */
	private static function back( $code, $detail = '' ) {
		$url = admin_url( 'admin.php?page=ik-backup&ikb_msg=' . rawurlencode( $code ) );
		if ( '' !== $detail ) {
			$url .= '&ikb_detail=' . rawurlencode( $detail );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handle: create backup.
	 */
	public static function handle_create() {
		self::guard( 'ikb_create' );
		$backup = new IKB_Backup();
		$scope = isset( $_POST['scope'] ) && 'translate-plugin' === $_POST['scope'] ? 'translate-plugin' : 'full';
        $result = $backup->create_backup( array( 'scope' => $scope ) );
		if ( is_wp_error( $result ) ) {
			self::back( 'error', $result->get_error_message() );
		}
		ikb_enforce_retention();
		self::back( 'created', $result['file'] . ' (' . size_format( $result['size'] ) . ', ' . $result['db_tables'] . ' tables, ' . $result['file_count'] . ' files)' );
	}

	/**
	 * Handle: restore backup.
	 */
	public static function handle_restore() {
		self::guard( 'ikb_restore' );
        if ( 'RESTORE' !== ( $_POST['confirmation'] ?? '' ) ) { self::back( 'error', 'Typed RESTORE confirmation required. Nothing changed.' ); }
		$file = isset( $_POST['backup_file'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_file'] ) ) : '';
		$path = ikb_backup_dir() . '/' . $file;
		if ( '' === $file || 0 !== strpos( $file, 'ikb-backup-' ) || ! is_readable( $path ) ) {
			self::back( 'error', 'Unknown backup file.' );
		}
		$restore = new IKB_Restore();
        $m = IKB_Backup::read_manifest( $path );
        if ( is_wp_error( $m ) ) { self::back( 'error', $m->get_error_message() ); }
        if ( 'translate-plugin' === ( $m['scope'] ?? '' ) ) {
            $result = $restore->restore_translate_plugin( $path );
            if ( is_wp_error( $result ) ) { self::back( 'error', $result->get_error_message() ); }
            self::back( 'restored', 'Translate plugin files rolled back; no database statements were run.' );
        }
		$result  = $restore->restore_backup(
			$path,
			array(
				'safety_backup' => ! empty( $_POST['safety_backup'] ),
				'keep_files'    => ! empty( $_POST['keep_files'] ),
			)
		);
		if ( is_wp_error( $result ) ) {
			self::back( 'error', $result->get_error_message() );
		}
		self::back( 'restored', $file . ' - ' . $result['files_restored'] . ' files, ' . $result['db_statements'] . ' DB statements' );
	}

	/**
	 * Handle: delete backup.
	 */
	public static function handle_delete() {
		self::guard( 'ikb_delete' );
		$file = isset( $_POST['backup_file'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_file'] ) ) : '';
		$path = ikb_backup_dir() . '/' . $file;
		if ( '' !== $file && 0 === strpos( $file, 'ikb-backup-' ) && is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $path );
			self::back( 'deleted', $file );
		}
		self::back( 'error', 'Unknown backup file.' );
	}

	/**
	 * Handle: download backup through PHP (directory is web-denied).
	 */
	public static function handle_download() {
		self::guard( 'ikb_download' );
		$file = isset( $_GET['backup_file'] ) ? sanitize_file_name( wp_unslash( $_GET['backup_file'] ) ) : '';
		$path = ikb_backup_dir() . '/' . $file;
		if ( '' === $file || 0 !== strpos( $file, 'ikb-backup-' ) || ! is_readable( $path ) ) {
			wp_die( 'Unknown backup file.' );
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
	}

	/**
	 * Handle: deploy the standalone emergency restore script to the WordPress root.
	 */
	public static function handle_deploy_emergency() {
		self::guard( 'ikb_deploy_emergency' );

		$source = IKB_PLUGIN_DIR . 'emergency-restore/ikb-emergency-restore.php';
		$target = ABSPATH . 'ikb-emergency-restore.php';

		$token   = wp_generate_password( 32, false, false );
		$script  = file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$script  = str_replace( '%%IKB_TOKEN%%', $token, $script );
		$script  = str_replace( "'%%IKB_BACKUP_DIR%%'", var_export( ikb_backup_dir(), true ), $script );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $target, $script ) ) {
			self::back( 'error', 'Could not write ' . $target );
		}

		update_option( 'ikb_emergency_deployed', array( 'path' => $target, 'token' => $token ) );
		self::back( 'deployed', home_url( '/ikb-emergency-restore.php' ) . ' (token stored below)' );
	}

    /** Exercise standalone verification without exposing its token or restoring anything. */
    public static function handle_test_emergency() {
        self::guard( 'ikb_test_emergency' );
        $deployed = get_option( 'ikb_emergency_deployed', array() );
        if ( empty( $deployed['token'] ) ) { self::back( 'error', 'Deploy the emergency script first.' ); }
        $snapshot = null;
        foreach ( IKB_Backup::list_backups() as $b ) {
            $m = IKB_Backup::read_manifest( $b['path'] );
            if ( ! is_wp_error( $m ) && 'translate-plugin' === ( $m['scope'] ?? '' ) ) { $snapshot = $b; break; }
        }
        if ( ! $snapshot ) { self::back( 'error', 'Create a Translate-plugin snapshot first.' ); }
        $url = home_url( '/ikb-emergency-restore.php' );
        $denied = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 0 ) );
        if ( is_wp_error( $denied ) || 403 !== wp_remote_retrieve_response_code( $denied ) ) { self::back( 'error', 'Unauthenticated recovery-endpoint check was inconclusive. Nothing restored.' ); }
        $verified = wp_remote_post( $url, array( 'timeout' => 30, 'redirection' => 0, 'headers' => array( 'Origin' => home_url() ), 'body' => array( 'token' => $deployed['token'], 'backup' => $snapshot['file'], 'mode' => 'verify' ) ) );
        if ( is_wp_error( $verified ) || 200 !== wp_remote_retrieve_response_code( $verified ) || false === strpos( wp_remote_retrieve_body( $verified ), 'VERIFIED:' ) || false === strpos( wp_remote_retrieve_body( $verified ), 'Scope: translate-plugin' ) || false === strpos( wp_remote_retrieve_body( $verified ), 'Plugin target writable: yes' ) ) {
            self::back( 'error', 'Standalone verification did not pass. No live restore was attempted.' );
        }
        self::back( 'probe_ok', 'Standalone recovery HTTP verified: unauthenticated access denied, plugin snapshot checksums valid, target writable. No plugin files or database were restored.' );
    }

    /** Test the same directory-swap route using disposable marker directories only. */
    public static function handle_test_swap() {
        self::guard( 'ikb_test_swap' );
        if ( ! ikb_storage_is_safe() ) { self::back( 'error', 'Storage safety check failed.' ); }
        $id = wp_generate_password( 16, false, false );
        $private = ikb_backup_dir() . '/swap-probe-' . $id;
        $target = WP_CONTENT_DIR . '/plugins/.ikb-swap-probe-' . $id;
        $ok = mkdir( $private, 0700 ) && false !== file_put_contents( $private . '/marker.txt', 'synthetic-probe-only' );
        $ok = $ok && rename( $private, $target );
        $ok = $ok && is_file( $target . '/marker.txt' ) && 'synthetic-probe-only' === file_get_contents( $target . '/marker.txt' );
        $ok = $ok && rename( $target, $private );
        foreach ( array( $private, $target ) as $dir ) { if ( is_file( $dir . '/marker.txt' ) ) { unlink( $dir . '/marker.txt' ); } if ( is_dir( $dir ) ) { rmdir( $dir ); } }
        self::back( $ok ? 'probe_ok' : 'error', $ok ? 'Directory swap route verified using disposable marker directories. Live plugin and database were not changed.' : 'Directory swap probe failed. Do not rely on plugin rollback yet.' );
    }

	/**
	 * Handle: settings (schedule, retention).
	 */
	public static function handle_settings() {
		self::guard( 'ikb_settings' );
		$schedule  = isset( $_POST['ikb_schedule'] ) ? sanitize_key( wp_unslash( $_POST['ikb_schedule'] ) ) : 'off';
		$retention = isset( $_POST['ikb_retention'] ) ? absint( $_POST['ikb_retention'] ) : 5;
		update_option( 'ikb_schedule', in_array( $schedule, array( 'off', 'daily', 'weekly' ), true ) ? $schedule : 'off' );
		update_option( 'ikb_retention', max( 1, min( 50, $retention ) ) );
		ikb_refresh_schedule();
		self::back( 'settings_saved' );
	}

	/**
	 * Show action result notices.
	 */
	public static function notice() {
		if ( ! isset( $_GET['page'] ) || 'ik-backup' !== $_GET['page'] || empty( $_GET['ikb_msg'] ) ) {
			return;
		}
		$msg    = sanitize_key( wp_unslash( $_GET['ikb_msg'] ) );
		$detail = isset( $_GET['ikb_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['ikb_detail'] ) ) : '';

		$labels = array(
			'created'        => 'Backup created',
			'restored'       => 'Backup restored',
			'deleted'        => 'Backup deleted',
			'deployed'       => 'Emergency restore script deployed',
			'settings_saved' => 'Settings saved',
            'probe_ok' => 'Recovery route verified',
			'error'          => 'Error',
		);
		$label = isset( $labels[ $msg ] ) ? $labels[ $msg ] : $msg;
		$class = ( 'error' === $msg ) ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $detail ) . '</p></div>';
	}

	/**
	 * Render the main admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$backups  = IKB_Backup::list_backups();
		$schedule = get_option( 'ikb_schedule', 'off' );
		$retention= (int) get_option( 'ikb_retention', 5 );
		$deployed = get_option( 'ikb_emergency_deployed', array() );
		$next_cron= wp_next_scheduled( 'ikb_run_scheduled_backup' );

		$dir        = ikb_backup_dir();
		$is_public  = ikb_dir_is_public( $dir );
		$probe_ok   = ikb_storage_probe_passes( $dir );
		$storage_ok = ikb_storage_is_safe();

		echo '<div class="wrap">';
		echo '<h1>IK Backup</h1>';

		// Storage status.
		echo '<h2>Storage</h2>';
		echo '<p>Location: <code>' . esc_html( $dir ) . '</code><br>';
		if ( ! $is_public ) {
			echo 'Status: <strong style="color:green">outside the web root</strong> - backup files cannot be fetched over HTTP.</p>';
		} elseif ( $probe_ok ) {
			echo 'Status: <strong style="color:#996800">inside the web root; a live probe verified the server denies direct access</strong> (definitive 401/403/404/410). Consider setting <code>IKB_BACKUP_DIR</code> in wp-config.php to a directory outside the web root.</p>';
		} else {
			echo 'Status: <strong style="color:#b00">REACHABLE OVER HTTP or the probe was inconclusive - backup creation is refused.</strong> The plugin only stores backups here when denial is verified. Set <code>IKB_BACKUP_DIR</code> in wp-config.php to a directory outside the web root (for example <code>' . esc_html( rtrim( dirname( ABSPATH ), '/' ) . '/.ik-backups' ) . '</code>) or fix the web server configuration.</p>';
		}

		echo '<p>Backups contain the full database and <code>wp-content</code>. They are stored on this server. Download copies regularly: a backup on the same server does not protect you from hosting or server failure.</p>';

		// Create.
		echo '<h2>Create backup</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ikb_create' );
		echo '<input type="hidden" name="action" value="ikb_create">';
		submit_button( 'Create backup now', 'primary', 'submit', false );
		echo '</form>';

		echo '<p>Full backup excludes known backup archives/directories, caches and symlinks. It uses a 120-second work budget and publishes only after checksum verification. Very large sites may need a host backup. Automatic backups are off unless you enable them.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'ikb_create' );
        echo '<input type="hidden" name="action" value="ikb_create"><input type="hidden" name="scope" value="translate-plugin">';
        submit_button( 'Snapshot Translate plugin files only', 'secondary', 'submit', false );
        echo '<p>Small, verified rollback snapshot. Contains only it-kayali-translate files. Restore never touches the database or orders.</p></form>';
        // List.
		echo '<h2>Backups (' . count( $backups ) . ')</h2>';
		echo '<p><strong>Warning:</strong> restoring a backup overwrites the database, including orders and users created after the backup was taken. Use the safety backup option and restore promptly after an incident.</p>';
		if ( empty( $backups ) ) {
			echo '<p>No backups yet.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>File</th><th>Created (GMT)</th><th>Size</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $backups as $b ) {
                $meta = IKB_Backup::read_manifest( $b['path'] );
                $plugin_only = ! is_wp_error( $meta ) && 'translate-plugin' === ( $meta['scope'] ?? '' );
				$download_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=ikb_download&backup_file=' . rawurlencode( $b['file'] ) ),
					'ikb_download'
				);
				echo '<tr><td><code>' . esc_html( $b['file'] ) . '</code></td><td>' . esc_html( $b['created'] ) . '</td><td>' . esc_html( size_format( $b['size'] ) ) . '</td><td>';

				echo '<a class="button" href="' . esc_url( $download_url ) . '">Download</a> ';

				echo '<strong>' . ( $plugin_only ? 'Translate files only, no DB' : 'Full backup' ) . '</strong> ';
                echo '<form method="post" style="display:inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'ikb_restore' );
				echo '<input type="hidden" name="action" value="ikb_restore"><input type="hidden" name="backup_file" value="' . esc_attr( $b['file'] ) . '">';
				if ( ! $plugin_only ) { echo '<label><input type="checkbox" name="safety_backup" value="1" checked> safety backup first</label> '; }
				if ( ! $plugin_only ) { echo '<label><input type="checkbox" name="keep_files" value="1"> database only</label> '; }
				echo '<label>Type RESTORE <input name="confirmation" size="9" required pattern="RESTORE" autocomplete="off"></label> ';
                submit_button( $plugin_only ? 'Roll back Translate files' : 'Restore full backup', 'small', 'submit', false );
				echo '</form> ';

				echo '<form method="post" style="display:inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Delete this backup?\')">';
				wp_nonce_field( 'ikb_delete' );
				echo '<input type="hidden" name="action" value="ikb_delete"><input type="hidden" name="backup_file" value="' . esc_attr( $b['file'] ) . '">';
				submit_button( 'Delete', 'small delete', 'submit', false );
				echo '</form>';

				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// Emergency restore.
		echo '<h2>Emergency restore (when wp-admin is unreachable)</h2>';
		echo '<p>Deploys a standalone restore script to <code>' . esc_html( ABSPATH . 'ikb-emergency-restore.php' ) . '</code>. If WordPress itself breaks but PHP still runs, you can restore a backup through that script in the browser or on the command line. It needs the token shown after deployment. This helps only while the web server and PHP still work; it cannot replace hosting-level or off-server backups.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ikb_deploy_emergency' );
		echo '<input type="hidden" name="action" value="ikb_deploy_emergency">';
		submit_button( 'Deploy / rotate emergency script', 'secondary', 'submit', false );
		echo '</form>';
		if ( ! empty( $deployed['path'] ) && file_exists( $deployed['path'] ) ) {
			echo '<p>Currently deployed: <code>' . esc_html( home_url( '/ikb-emergency-restore.php' ) ) . '</code><br>';
			echo 'Token: <input type="password" readonly autocomplete="off" value="' . esc_attr( $deployed['token'] ) . '"> (keep this private; never send it in chat)</p>';
		}

        echo '<h2>Non-destructive recovery-route test</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'ikb_test_swap' );
        echo '<input type="hidden" name="action" value="ikb_test_swap">';
        submit_button( 'Test plugin-directory swap route', 'secondary', 'submit', false );
        echo '<p>Creates and removes disposable marker directories. Does not change the Translate plugin, database, orders or settings.</p></form>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'ikb_test_emergency' );
        echo '<input type="hidden" name="action" value="ikb_test_emergency">';
        submit_button( 'Verify standalone recovery, no restore', 'secondary', 'submit', false );
        echo '<p>Checks denied access without a token and runs the standalone script in verify-only mode against the latest Translate snapshot. Does not restore anything or reveal the token.</p></form>';

		// Settings.
		echo '<h2>Settings</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ikb_settings' );
		echo '<input type="hidden" name="action" value="ikb_settings">';
		echo '<table class="form-table"><tr><th scope="row"><label for="ikb_schedule">Automatic backups</label></th><td>';
		echo '<select id="ikb_schedule" name="ikb_schedule">';
		foreach ( array( 'off' => 'Off', 'daily' => 'Daily', 'weekly' => 'Weekly' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $schedule, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		if ( $next_cron ) {
			echo '<p class="description">Next scheduled run: ' . esc_html( gmdate( 'c', $next_cron ) ) . ' GMT</p>';
		}
		echo '</td></tr><tr><th scope="row"><label for="ikb_retention">Keep newest</label></th><td>';
		echo '<input type="number" id="ikb_retention" name="ikb_retention" value="' . esc_attr( $retention ) . '" min="1" max="50"> backups';
		echo '</td></tr></table>';
		submit_button( 'Save settings' );
		echo '</form>';

		echo '</div>';
	}
}
