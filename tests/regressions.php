<?php
// Synthetic filesystem + real ZipArchive tests. No customer data or network.
$base = sys_get_temp_dir() . '/ikb-check-' . bin2hex(random_bytes(6));
mkdir($base . '/site/wp-content/plugins/it-kayali-translate', 0700, true);
define('ABSPATH', $base . '/site/'); define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('IKB_BACKUP_DIR', $base . '/private'); define('HOUR_IN_SECONDS',3600); define('DAY_IN_SECONDS',86400);
define('ARRAY_N','ARRAY_N'); define('ARRAY_A','ARRAY_A');
class WP_Error { private $code; private $message; function __construct($c,$m){$this->code=$c;$this->message=$m;} function get_error_message(){return $this->message;} function get_error_code(){return $this->code;} }
function is_wp_error($v){return $v instanceof WP_Error;} function get_option($n,$d=false){global $test_options;return $test_options[$n]??$d;} function update_option(...$a){} function add_action(...$a){} function register_activation_hook(...$a){} function register_deactivation_hook(...$a){} function plugin_dir_path($p){return dirname($p).'/';} function wp_parse_args($a,$b){return array_merge($b,$a);} function wp_mkdir_p($p){return is_dir($p)||mkdir($p,0700,true);} function wp_upload_dir(){return ['basedir'=>WP_CONTENT_DIR.'/uploads','baseurl'=>'https://example.test/wp-content/uploads'];} function wp_generate_password($n,...$a){return substr(bin2hex(random_bytes($n)),0,$n);} function wp_raise_memory_limit(...$a){} function wp_json_encode(...$a){return json_encode(...$a);} function home_url(){return 'https://example.test';} function get_bloginfo($v){return 'test';} function esc_html($v){return $v;} function untrailingslashit($v){return rtrim($v,'/');}
class FakeDB { public $prefix='wp_'; public $dbname='synthetic'; public $last_error=''; public $queries=[]; public $fail=false; function db_server_info(){return 'synthetic';} function query($q){$this->queries[]=$q;return true;} function get_col($q){return ['wp_test'];} function get_row($q,$mode){return ['wp_test','CREATE TABLE `wp_test` (`name` text, `blob` blob)'];} function get_results($q,$mode=null){ if(strpos($q,'SHOW COLUMNS')===0)return [(object)['Field'=>'name','Type'=>'text'],(object)['Field'=>'blob','Type'=>'blob']]; if($this->fail){$this->last_error='synthetic read failure';return null;}return [['name'=>"line\nquote'",'blob'=>'']];} function _real_escape($v){return addcslashes($v,"\\\n\r\0'\"");} }
$wpdb=new FakeDB();
require dirname(__DIR__).'/ik-backup.php';
$assertions=0; function check($v,$m){global $assertions;if(!$v){throw new Exception('FAIL: '.$m);} $assertions++; echo 'PASS '.$m."\n";}
file_put_contents(WP_CONTENT_DIR.'/plugins/it-kayali-translate/it-kayali-translate.php','<?php /* original plugin */');
file_put_contents(WP_CONTENT_DIR.'/plugins/it-kayali-translate/extra.js','original');
mkdir(WP_CONTENT_DIR.'/uploads/wp-staging/backups',0700,true);file_put_contents(WP_CONTENT_DIR.'/uploads/wp-staging/backups/huge.wpstg','exclude');
mkdir(WP_CONTENT_DIR.'/cache',0700,true);file_put_contents(WP_CONTENT_DIR.'/cache/cache.bin','exclude');
file_put_contents(WP_CONTENT_DIR.'/uploads/synthetic.jpg','image');
$backup=new IKB_Backup(); $snapshot=$backup->create_backup(['scope'=>'translate-plugin']);
check(!is_wp_error($snapshot),'plugin snapshot completes');
$m=IKB_Restore::verify_archive($snapshot['path']);check(!is_wp_error($m)&&$m['scope']==='translate-plugin','plugin snapshot passes per-file verification');
$z=new ZipArchive();$z->open($snapshot['path']);check(false===$z->getFromName('db.sql'),'plugin snapshot contains no database');$z->close();
file_put_contents(WP_CONTENT_DIR.'/plugins/it-kayali-translate/extra.js','changed');$wpdb->queries=[];
$r=(new IKB_Restore())->restore_translate_plugin($snapshot['path']);check(!is_wp_error($r)&&$r['db_statements']===0,'plugin-only rollback succeeds');
check(file_get_contents(WP_CONTENT_DIR.'/plugins/it-kayali-translate/extra.js')==='original','original plugin bytes restored');check($wpdb->queries===[],'plugin-only rollback executes no SQL');
// Corrupt one file while leaving manifest unchanged.
$corrupt=IKB_BACKUP_DIR.'/corrupt.zip';copy($snapshot['path'],$corrupt);$z=new ZipArchive();$z->open($corrupt);$z->addFromString('wp-content/plugins/it-kayali-translate/extra.js','tampered');$z->close();
check(is_wp_error(IKB_Restore::verify_archive($corrupt)),'corrupted plugin file rejected');
$r=(new IKB_Restore())->restore_translate_plugin($corrupt);check(is_wp_error($r)&&file_get_contents(WP_CONTENT_DIR.'/plugins/it-kayali-translate/extra.js')==='original','bad snapshot leaves target unchanged');
$bad=IKB_BACKUP_DIR.'/traversal.zip';copy($snapshot['path'],$bad);$z=new ZipArchive();$z->open($bad);$z->addFromString('../escape.php','bad');$z->close();check(is_wp_error(IKB_Restore::verify_archive($bad)),'path traversal rejected');
$full=$backup->create_backup();check(!is_wp_error($full),'full backup completes against synthetic DB adapter');
$z=new ZipArchive();$z->open($full['path']);check(false===$z->getFromName('wp-content/uploads/wp-staging/backups/huge.wpstg'),'existing WP STAGING archives excluded');check(false===$z->getFromName('wp-content/cache/cache.bin'),'cache excluded');check($z->getFromName('wp-content/uploads/synthetic.jpg')==='image','ordinary media retained');check(strpos($z->getFromName('db.sql'),"X''")!==false,'empty binary column encoded as valid empty SQL hex literal');$z->close();
check(!is_wp_error(IKB_Restore::verify_archive($full['path'])),'full database and file hashes verify');
$count=count(IKB_Backup::list_backups());$wpdb->fail=true;$failed=$backup->create_backup();check(is_wp_error($failed)&&count(IKB_Backup::list_backups())===$count,'DB read failure cannot publish completed backup');$wpdb->fail=false;$wpdb->last_error='';
$lock=fopen(IKB_BACKUP_DIR.'/operation.lock','c');flock($lock,LOCK_EX);$busy=$backup->create_backup(['scope'=>'translate-plugin']);check(is_wp_error($busy)&&$busy->get_error_code()==='ikb_busy','concurrent backup rejected');flock($lock,LOCK_UN);fclose($lock);
check(!glob(IKB_BACKUP_DIR.'/*.partial'),'no partial archives presented as backups');
// Standalone emergency plugin rollback works without wp-config or WordPress.
$script=file_get_contents(dirname(__DIR__).'/emergency-restore/ikb-emergency-restore.php');$script=str_replace('%%IKB_BACKUP_DIR%%',IKB_BACKUP_DIR,$script);$script=str_replace('%%IKB_TOKEN%%','synthetic-test-only',$script);file_put_contents(ABSPATH.'ikb-emergency-restore.php',$script);
file_put_contents(WP_CONTENT_DIR.'/plugins/it-kayali-translate/extra.js','broken');
$cmd=escapeshellarg(PHP_BINARY).' -n -d extension='.escapeshellarg(ini_get('extension_dir').'/zip.so').' '.escapeshellarg(ABSPATH.'ikb-emergency-restore.php').' '.escapeshellarg($snapshot['path']).' --yes 2>&1';
exec($cmd,$out,$status);check($status===0&&strpos(implode("\n",$out),'Database statements: 0')!==false,'standalone plugin rollback works without wp-config / WordPress');check(file_get_contents(WP_CONTENT_DIR.'/plugins/it-kayali-translate/extra.js')==='original','standalone path restores original bytes');
// Administrative read-only recovery probe, simulated HTTP outcomes.
function current_user_can($c){return true;} function check_admin_referer($a){} function admin_url($p=''){return 'https://example.test/wp-admin/'.$p;} function wp_safe_redirect($url){throw new RuntimeException($url);}
function wp_remote_get($u,$a){global $http_denied;return $http_denied;} function wp_remote_post($u,$a){global $http_verified;return $http_verified;} function wp_remote_retrieve_response_code($r){return $r['code']??0;} function wp_remote_retrieve_body($r){return $r['body']??'';}
$test_options=['ikb_emergency_deployed'=>['token'=>'synthetic-only']];
foreach ([
 ['denied'=>['code'=>403],'verified'=>['code'=>200,'body'=>'VERIFIED: Scope: translate-plugin Plugin target writable: yes'],'pass'=>true,'label'=>'admin probe accepts authenticated read-only success'],
 ['denied'=>new WP_Error('network','synthetic timeout'),'verified'=>[],'pass'=>false,'label'=>'admin probe refuses inconclusive network error'],
 ['denied'=>['code'=>200],'verified'=>[],'pass'=>false,'label'=>'admin probe refuses public emergency endpoint'],
 ['denied'=>['code'=>403],'verified'=>['code'=>200,'body'=>'unexpected'],'pass'=>false,'label'=>'admin probe refuses unexpected verify response']
] as $case){$http_denied=$case['denied'];$http_verified=$case['verified'];try{IKB_Admin::handle_test_emergency();throw new Exception('No result');}catch(RuntimeException $e){check((strpos($e->getMessage(),'ikb_msg=probe_ok')!==false)===$case['pass'],$case['label']);}}
echo "ASSERTIONS $assertions\n";
file_put_contents($base.'/fixture.json',json_encode(['base'=>$base,'snapshot'=>$snapshot['path'],'full'=>$full['path']]));
echo 'FIXTURE '.$base."\n";
