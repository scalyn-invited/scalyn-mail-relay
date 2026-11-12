<?php
// Explicit fourth-argument marker writer uses the mocked test add_option backend, not native wpdb.

/**
 * Offline competing PHP initializers through actual no-replace publication.
 * Usage: php tests/integration/local-key-concurrency.php SCRATCH_PARENT
 * Reference scan and marker persistence are explicit synthetic shared-file seams;
 * no WordPress bootstrap, database, network, provider or ciphertext persistence.
 */
define('ABSPATH','/nonexistent/synthetic/');
require dirname(__DIR__,2).'/includes/Core/Capabilities.php';
require dirname(__DIR__,2).'/includes/Core/LocalCredentialKeyStore.php';
function get_current_blog_id() {return 1;}
function current_user_can($capability) {return true;}
function read_synthetic_marker() {
    $path=$GLOBALS['concurrency_root'].'/marker';
    if(!file_exists($path))return null;
    $raw=file_get_contents($path);
    $value=@unserialize($raw,['allowed_classes'=>false]);
    return is_array($value)?$value:false;
}
function add_option($name,$value,$unused='',$autoload=false) {
    $handle=@fopen($GLOBALS['concurrency_root'].'/marker','x+b');
    if(!$handle)return false;
    try {$raw=serialize($value);return fwrite($handle,$raw)===strlen($raw) && fflush($handle) && fsync($handle);}
    finally {fclose($handle);}
}
function store() {return new Scalyn\MailRelay\Core\LocalCredentialKeyStore($GLOBALS['concurrency_root'],static fn()=>false,static fn()=>read_synthetic_marker(), static fn( $marker ) => \add_option( \Scalyn\MailRelay\Core\LocalCredentialKeyStore::STATE_OPTION, $marker, '', false ));}
if(($argv[1]??'')==='--child') {
    $GLOBALS['concurrency_root']=$argv[2];
    $deadline=microtime(true)+10;
    while(!file_exists($argv[2].'/start')) {if(microtime(true)>$deadline)exit(2);usleep(1000);}
    try {store()->initialize_for_write();print "ok";}
    catch(RuntimeException $error) {if($error->getPrevious()!==null)exit(3);print "closed";}
    exit(0);
}
$parent=$argv[1]??'';
if(!is_dir($parent))exit(2);
$failures=[];$rounds=8;$successful=0;$closed=0;
for($round=0;$round<$rounds;++$round) {
    $root=$parent.'/scalyn-concurrent-'.bin2hex(random_bytes(8));mkdir($root,0700);$GLOBALS['concurrency_root']=$root;
    $processes=[];
    for($i=0;$i<4;++$i) {
        $process=proc_open([PHP_BINARY,'-d','sys_temp_dir='.sys_get_temp_dir(),__FILE__,'--child',$root],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Cannot start synthetic worker');
        $processes[]=[$process,$pipes];
    }
    touch($root.'/start');$wins=0;
    foreach($processes as [$process,$pipes]) {
        $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        if($exit!==0 || $errors!=='' || !in_array($output,['ok','closed'],true))$failures[]='worker:'.$round;
        if($output==='ok'){++$wins;++$successful;}else{++$closed;}
    }
    $valid=$wins>=1;
    try {
        $store=store();$key=$store->read();$path=$store->path();$inode=fileinode($path);
        $marker=read_synthetic_marker();
        $valid=$valid && $marker===['version'=>1,'blog_id'=>1,'fingerprint'=>hash('sha256',$key)];
        $store->initialize_for_write();
        $valid=$valid && hash_equals($key,$store->read()) && fileinode($path)===$inode;
        $valid=$valid && scandir(dirname($path))===['.','..','credentials-key-blog-1-lock.php','credentials-key-blog-1.php'];
    } catch(Throwable $error) {$valid=false;}
    if(!$valid)$failures[]='publication:'.$round;
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($root);
}
print json_encode(['rounds'=>$rounds,'processes_per_round'=>4,'successful'=>$successful,'failed_closed'=>$closed,'failures'=>$failures,'qualification'=>'Real competing PHP processes and key filesystem; synthetic shared marker/add_option and reference scan; no SQL or ciphertext'],JSON_PRETTY_PRINT)."\n";
exit($failures?1:0);
