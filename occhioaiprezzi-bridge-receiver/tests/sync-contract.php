<?php
/** Disposable in-memory storage harness; real receiver code and real WP KSES. */
if (PHP_SAPI !== 'cli' || $argc !== 2) { exit(2); }
define('ABSPATH', rtrim($argv[1], '/').'/'); define('WPINC', 'wp-includes');
define('OAP_BRIDGE_WRITES_ENABLED', true);
define('OAP_BRIDGE_PUBLIC_BASE', 'https://receiver.test/prodotti');
foreach (['compat.php','plugin.php','formatting.php','functions.php','kses.php','http.php',
'class-wp-token-map.php','html-api/html5-named-character-references.php',
'html-api/class-wp-html-attribute-token.php','html-api/class-wp-html-span.php',
'html-api/class-wp-html-text-replacement.php','html-api/class-wp-html-decoder.php',
'html-api/class-wp-html-tag-processor.php'] as $file) { require_once ABSPATH.WPINC.'/'.$file; }
foreach (['support','product-pages','deletion','rest-api'] as $part) { require_once dirname(__DIR__).'/includes/class-'.$part.'.php'; }
function home_url($path = '') { return 'https://receiver.test'.$path; }
final class OAP_Storage {
    public string $pages='pages', $tombstones='tombstones', $slugs='slugs', $affiliates='affiliates';
    public static ?array $row=null, $receipt=null;
    public static array $artifacts=[], $claims=[], $native=[];
    public static int $writes=0;
    public function locked($fn) { return $fn($this); }
    public function page($gtin) { return self::$row; }
    public function tombstone($gtin) { return self::$receipt; }
    public static function directory() { return sys_get_temp_dir().'/oap-nonexistent-test-directory'; }
    public static function artifact($p) { $h=hash('sha256',json_encode($p)); self::$artifacts[$h]=$p; return $h; }
    public static function read($h) { return self::$artifacts[$h]; }
    public function rows($sql) { return []; }
    public function one($sql) {
        if (str_contains($sql, 'COUNT(*)')) { return ['n'=>self::$row ? 1 : 0]; }
        [$q,$a]=json_decode($sql,true);
        if (str_contains($q,'FROM `slugs`')) { return isset(self::$claims[$a[0]]) ? ['gtin'=>self::$claims[$a[0]]] : null; }
        if (str_contains($q,"post_type NOT IN")) { return in_array($a[0],self::$native,true) ? ['ID'=>999] : null; }
        throw new RuntimeException('unexpected_read');
    }
    public function query($sql) {
        [$q,$a]=json_decode($sql,true); ++self::$writes;
        if (str_starts_with($q,'INSERT INTO `tombstones`')) { self::$receipt=['gtin'=>$a[0],'version'=>$a[1],'slug'=>$a[2],'installed_hash'=>$a[3],'source_version'=>$a[4]]; }
        elseif (str_starts_with($q,'DELETE FROM `pages`')) { self::$row=null; }
        elseif (str_starts_with($q,'DELETE FROM `tombstones`')) { self::$receipt=null; }
        elseif (str_starts_with($q,'INSERT INTO `pages`')) { self::$row=array_combine(['gtin','version','installed_hash','slug','artifact','state','indexable','modified_at'],$a); self::$row['owner']='oap-wp-1'; }
        elseif (str_starts_with($q,'INSERT INTO `slugs`')) { self::$claims[$a[0]]=$a[1]; }
        elseif (str_starts_with($q,'DELETE FROM `affiliates`')) { /* isolated empty table */ }
        else { throw new RuntimeException('unexpected_write'); }
    }
}
$wpdb=new class {
    public string $posts='wp_posts';
    public function prepare($sql,...$args) { return json_encode([$sql,$args]); }
};
$input=json_decode(stream_get_contents(STDIN),true);
OAP_Storage::$native=$input['native'] ?? [];
$results=[];
foreach ($input['requests'] as $request) {
    $p=$request['payload']; $before=OAP_Storage::$writes;
    try {
        $ack=($request['method']==='DELETE')
            ? OAP_Deletion::request($p,$p['gtin'],$request['key'] ?? $p['content_hash'].':'.$p['version'])
            : OAP_Product_Pages::put($p,$p['gtin']);
        $results[]=['ack'=>$ack,'writes'=>OAP_Storage::$writes-$before];
    } catch (OAP_Failure $e) {
        $results[]=['error'=>$e->getMessage(),'status'=>$e->status,'details'=>$e->diagnostics,'writes'=>OAP_Storage::$writes-$before];
    }
}
echo json_encode(['results'=>$results,'row'=>OAP_Storage::$row,'receipt'=>OAP_Storage::$receipt]);
