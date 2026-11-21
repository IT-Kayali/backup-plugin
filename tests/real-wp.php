<?php
// Run only against the disposable local WordPress configured by real-wp-run.py.
define('WP_INSTALLING',true);
require getenv('IKB_TEST_WP') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail', '__return_false');
if (!is_blog_installed()) { wp_install('Synthetic IK Backup Test', 'synthetic-admin', 'test@example.invalid', false); }
require WP_CONTENT_DIR . '/plugins/ik-backup/ik-backup.php';
$n=0;function verify_test($v,$m){global $n;if(!$v){throw new Exception('FAIL: '.$m);}echo 'PASS '.$m."\n";$n++;}
global $wpdb;
$table=$wpdb->prefix.'ikb_fixture';
$wpdb->query("CREATE TABLE `$table` (id int primary key, content longtext, bits blob) ENGINE=InnoDB");
$wpdb->insert($table,['id'=>1,'content'=>"Synthetic original العربية \n 'quote'",'bits'=>'']);
$original=$wpdb->get_row("SELECT * FROM `$table` WHERE id=1",ARRAY_A);
wp_mkdir_p(WP_CONTENT_DIR.'/uploads');file_put_contents(WP_CONTENT_DIR.'/uploads/restore-marker.txt','original synthetic media');
$b=(new IKB_Backup())->create_backup();verify_test(!is_wp_error($b),'real WordPress/MariaDB full backup created');
verify_test(!is_wp_error(IKB_Restore::verify_archive($b['path'])),'real archive database/file hashes verify');
$wpdb->update($table,['content'=>'mutated'],['id'=>1]);unlink(WP_CONTENT_DIR.'/uploads/restore-marker.txt');
$r=(new IKB_Restore())->restore_backup($b['path'],['safety_backup'=>false]);verify_test(!is_wp_error($r),'full files+database restore completed in isolated WP');
verify_test($wpdb->get_row("SELECT * FROM `$table` WHERE id=1",ARRAY_A)===$original,'Unicode multiline SQL and empty binary round-trip exactly');
verify_test(file_get_contents(WP_CONTENT_DIR.'/uploads/restore-marker.txt')==='original synthetic media','deleted media restored');
$s=(new IKB_Backup())->create_backup(['scope'=>'translate-plugin']);verify_test(!is_wp_error($s),'real WP Translate-only snapshot created');
file_put_contents(WP_CONTENT_DIR.'/plugins/it-kayali-translate/marker.js','broken');$wpdb->update($table,['content'=>'newer order-like marker'],['id'=>1]);
$r=(new IKB_Restore())->restore_translate_plugin($s['path']);verify_test(!is_wp_error($r)&&$r['db_statements']===0,'real WP plugin-only rollback reports zero SQL');
verify_test($wpdb->get_var("SELECT content FROM `$table` WHERE id=1")==='newer order-like marker','newer database marker survives plugin-only rollback');
verify_test(file_get_contents(WP_CONTENT_DIR.'/plugins/it-kayali-translate/marker.js')==='synthetic original','real WP original plugin file restored');

$script=file_get_contents(WP_CONTENT_DIR.'/plugins/ik-backup/emergency-restore/ikb-emergency-restore.php');
$script=str_replace('%%IKB_BACKUP_DIR%%',ikb_backup_dir(),$script);
file_put_contents(ABSPATH.'ikb-emergency-restore.php',$script);
$wpdb->update($table,['content'=>'mutated before standalone'],['id'=>1]);unlink(WP_CONTENT_DIR.'/uploads/restore-marker.txt');
$cmd=escapeshellarg(PHP_BINARY).' -n -d extension_dir='.escapeshellarg(ini_get('extension_dir')).' -d extension=mysqlnd.so -d extension=mysqli.so -d extension=zip.so '.escapeshellarg(ABSPATH.'ikb-emergency-restore.php').' '.escapeshellarg($b['path']).' --yes 2>&1';
exec($cmd,$out,$status);
verify_test($status===0&&strpos(implode("\n",$out),'DONE. Restore complete')!==false,'standalone full restore succeeds against disposable MariaDB');
verify_test($wpdb->get_row("SELECT * FROM `$table` WHERE id=1",ARRAY_A)===$original,'standalone database round-trip restores original marker');
verify_test(file_get_contents(WP_CONTENT_DIR.'/uploads/restore-marker.txt')==='original synthetic media','standalone full restore recovers deleted media');
echo 'REAL WP ASSERTIONS '.$n."\n";
echo 'ENV WordPress '.get_bloginfo('version').' PHP '.PHP_VERSION.' MariaDB '.$wpdb->db_server_info()."\n";
