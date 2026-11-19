<?php
// ========================================
// easypage/api.php — ทุกอย่างทำบนเซิร์ฟเวอร์: รายการ / ฉบับร่าง / เผยแพร่ / ปิด-เปิด / ลบ
//
// โครงสร้างข้อมูล (บนเซิร์ฟเวอร์ ใช้ไฟล์ ไม่ต้องมี DB):
//   d/<id>.md      ฉบับร่าง (ข้อความที่กำลังแก้ — บันทึกจากหน้าเขียนอัตโนมัติ)
//   d/<id>.json    ข้อมูลประกอบ: created, updated (ms), pubv (ค่า updated ตอนเผยแพร่ล่าสุด), pubAt, expDate, expTime
//   p/<id>.md      ฉบับที่เผยแพร่ (ที่ view.php แสดงให้ผู้อ่าน) · p/<id>.exp วันหมดอายุ · p/<id>.closed ปิดอยู่
//   trash/<id>-<เวลา>/  หน้าที่ลบ (ย้ายมาเก็บ กู้คืนด้วยมือได้)
//   หน้าเก่าที่มีแต่ p/<id>.md (ยังไม่มี d/) ใช้ได้ — ฉบับร่างจะถูกสร้างตอนแก้ครั้งแรก
//
// การเรียก:  GET  api.php?a=list | a=get&id=
//            POST api.php?a=save&id=[&new=1][&base=<updated>][&exp=YYYY-MM-DD&exptime=HH:MM]   body = markdown
//            POST api.php?a=publish|close|open|delete&id=
// ช่วงนี้ “ทุกคนใช้ได้” (ยังไม่มีระบบสิทธิ์): ใครก็เห็นและแก้ได้ทุกหน้า — ตั้งใน config.php (ดู config.sample.php)
// ทุกคำขอเขียน: ต้องมี header X-Easypage: 1 และ Origin ตรง Host (กัน cross-site) · จำกัดจำนวนครั้งต่อ IP
// ========================================
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('X-Content-Type-Options: nosniff');

define('EP_D', __DIR__ . '/d');
define('EP_P', __DIR__ . '/p');
define('EP_T', __DIR__ . '/trash');
define('EP_MAX_BYTES', 2 * 1024 * 1024);

function ep_out($code, $arr)
{
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

/** สร้างโฟลเดอร์ (ถ้ายังไม่มี) พร้อม .htaccess กันเปิดไฟล์ตรงๆ — ทุกอย่างต้องผ่าน api.php / view.php */
function ep_prepare($dir)
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        ep_out(500, array('ok' => false, 'error' => 'สร้างโฟลเดอร์ ' . basename($dir) . '/ ไม่ได้ — ตรวจสิทธิ์การเขียนของโฟลเดอร์ easypage'));
    }
    if (!is_writable($dir)) {
        ep_out(500, array('ok' => false, 'error' => 'เขียนโฟลเดอร์ ' . basename($dir) . '/ ไม่ได้ — ตั้งสิทธิ์ให้ผู้ใช้ของเว็บเซิร์ฟเวอร์เขียนได้'));
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "# ไม่ให้เปิดไฟล์ในโฟลเดอร์นี้ตรงๆ (Apache) — ผู้อ่านเข้าผ่าน view.php / api.php เท่านั้น\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
}

function ep_now_ms()
{
    return (int)round(microtime(true) * 1000);
}

function ep_read_json($f)
{
    if (!is_file($f)) return null;
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

/** เขียนไฟล์แบบ atomic (เขียนไฟล์ชั่วคราวแล้ว rename) */
function ep_write($dir, $dest, $data)
{
    $tmp = tempnam($dir, '.ep');
    if ($tmp === false || file_put_contents($tmp, $data, LOCK_EX) === false) {
        if ($tmp) @unlink($tmp);
        ep_out(500, array('ok' => false, 'error' => 'เขียนไฟล์ไม่สำเร็จ'));
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        ep_out(500, array('ok' => false, 'error' => 'บันทึกไฟล์ ' . basename($dest) . ' ไม่สำเร็จ'));
    }
}

/** จำกัดจำนวนครั้งต่อ IP (แยกตามกลุ่ม) */
function ep_throttle($bucket, $limit, $window)
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $fh = @fopen(sys_get_temp_dir() . '/easypage-rate-' . $bucket . '-' . sha1($ip), 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $t    = json_decode(stream_get_contents($fh), true);
    $now  = time();
    $keep = array();
    if (is_array($t)) foreach ($t as $x) if ($x > $now - $window) $keep[] = $x;
    if (count($keep) >= $limit) {
        ep_out(429, array('ok' => false, 'error' => 'ทำรายการถี่เกินไป — รอสักครู่แล้วลองใหม่'));
    }
    $keep[] = $now;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($keep));
    flock($fh, LOCK_UN);
    fclose($fh);
}

/** วันหมดอายุ (เวลาไทย) → array(at, timed) | null (ว่าง) — ผิดรูปแบบ/อดีต = error */
function ep_parse_exp($d, $t)
{
    if ($d === '') return null;
    $okD = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $dm) && checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1]);
    $okT = $t === '' || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    if (!$okD || !$okT) ep_out(400, array('ok' => false, 'error' => 'รูปแบบวันหมดอายุไม่ถูกต้อง'));
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $d . ' ' . ($t !== '' ? $t . ':00' : '23:59:59'), new DateTimeZone('Asia/Bangkok'));
    $at = $dt ? $dt->getTimestamp() : 0;
    if ($at <= time()) ep_out(400, array('ok' => false, 'error' => 'วันหมดอายุต้องเป็นเวลาในอนาคต'));
    return array('at' => $at, 'timed' => $t !== '');
}

// ---------- อ่านข้อมูลหน้า ----------
function ep_has_d($id) { return is_file(EP_D . '/' . $id . '.md'); }
function ep_has_p($id) { return is_file(EP_P . '/' . $id . '.md'); }

function ep_meta($id)
{
    $m = ep_read_json(EP_D . '/' . $id . '.json');
    if (is_array($m)) {
        $m += array('created' => 0, 'updated' => 0, 'pubv' => 0, 'pubAt' => 0, 'expDate' => '', 'expTime' => '');
        return $m;
    }
    // หน้าเก่าที่ยังไม่มีไฟล์ประกอบ (มีแต่ p/) — สร้างค่าจากไฟล์ที่เผยแพร่
    $src = ep_has_d($id) ? EP_D . '/' . $id . '.md' : EP_P . '/' . $id . '.md';
    $t   = (int)@filemtime($src) * 1000;
    $m   = array('created' => $t, 'updated' => $t, 'pubv' => ep_has_p($id) ? $t : 0, 'pubAt' => ep_has_p($id) ? (int)@filemtime(EP_P . '/' . $id . '.md') * 1000 : 0,
        'expDate' => '', 'expTime' => '');
    $e = ep_read_json(EP_P . '/' . $id . '.exp');
    if ($e && isset($e['at']) && $e['at'] > 0) {
        $d = new DateTime('@' . $e['at']);
        $d->setTimezone(new DateTimeZone('Asia/Bangkok'));
        $m['expDate'] = $d->format('Y-m-d');
        $m['expTime'] = !empty($e['time']) ? $d->format('H:i') : '';
    }
    return $m;
}

function ep_text_of($id)
{
    $f = ep_has_d($id) ? EP_D . '/' . $id . '.md' : EP_P . '/' . $id . '.md';
    $s = is_file($f) ? (string)file_get_contents($f) : '';
    return preg_replace('/^\xEF\xBB\xBF/', '', $s);
}

/** ชื่อเรื่อง = บรรทัดแรกที่ไม่ว่าง · snippet = ข้อความต่อจากนั้นแบบสั้น */
function ep_title_snippet($id)
{
    $f = ep_has_d($id) ? EP_D . '/' . $id . '.md' : EP_P . '/' . $id . '.md';
    $fh = @fopen($f, 'rb');
    $chunk = $fh ? (string)fread($fh, 1500) : '';
    if ($fh) fclose($fh);
    $chunk = preg_replace('/^\xEF\xBB\xBF/', '', $chunk);
    $title = '';
    $rest  = array();
    foreach (explode("\n", $chunk) as $line) {
        $line = trim($line);
        if ($title === '') {
            if ($line !== '') $title = trim(ltrim($line, '#'));
        } elseif ($line !== '') {
            $rest[] = $line;
        }
    }
    $snip = trim(preg_replace('/\s+/u', ' ', preg_replace('/[#>*_~`|\-]+/', ' ', implode(' ', $rest))));
    if (!mb_check_encoding($title, 'UTF-8')) $title = mb_convert_encoding($title, 'UTF-8', 'UTF-8');
    if (!mb_check_encoding($snip, 'UTF-8')) $snip = mb_convert_encoding($snip, 'UTF-8', 'UTF-8');
    if (mb_strlen($snip, 'UTF-8') > 110) $snip = mb_substr($snip, 0, 110, 'UTF-8') . '…';
    return array(mb_substr($title, 0, 200, 'UTF-8'), $snip);
}

/** สถานะของหน้า: draft | published | closed | expired  + pending (ยังไม่เผยแพร่ / แก้ไขหลังเผยแพร่) */
function ep_info($id)
{
    $m      = ep_meta($id);
    $hasP   = ep_has_p($id);
    $closed = is_file(EP_P . '/' . $id . '.closed');
    $e      = ep_read_json(EP_P . '/' . $id . '.exp');
    $expired = $e && isset($e['at']) && $e['at'] > 0 && time() > $e['at'];
    $state  = !$hasP ? 'draft' : ($closed ? 'closed' : ($expired ? 'expired' : 'published'));
    list($title, $snip) = ep_title_snippet($id);
    $f = ep_has_d($id) ? EP_D . '/' . $id . '.md' : EP_P . '/' . $id . '.md';
    return array('id' => $id, 'title' => $title, 'snippet' => $snip, 'state' => $state,
        'pending' => !$hasP || (int)$m['updated'] !== (int)$m['pubv'],
        'created' => (int)$m['created'], 'updated' => (int)$m['updated'], 'publishedAt' => (int)$m['pubAt'],
        'expDate' => (string)$m['expDate'], 'expTime' => (string)$m['expTime'], 'bytes' => (int)@filesize($f));
}

function ep_write_meta($id, $m)
{
    ep_prepare(EP_D);
    ep_write(EP_D, EP_D . '/' . $id . '.json', json_encode($m));
}

function ep_count_pages()
{
    $ids = array();
    foreach ((array)glob(EP_D . '/*.md') as $f) $ids[basename($f, '.md')] = 1;
    foreach ((array)glob(EP_P . '/*.md') as $f) $ids[basename($f, '.md')] = 1;
    return count($ids);
}

// ---------- ตั้งค่า ----------
$cfg = is_file(__DIR__ . '/config.php') ? include __DIR__ . '/config.php' : array();
if (!is_array($cfg)) $cfg = array();
$hash = isset($cfg['password_hash']) ? (string)$cfg['password_hash'] : '';
$mode = isset($cfg['publish']) ? (string)$cfg['publish'] : ($hash !== '' ? 'password' : 'open');
if (!in_array($mode, array('local', 'open', 'password'), true)) $mode = 'open';
$maxPages = isset($cfg['max_pages']) ? (int)$cfg['max_pages'] : 2000;

$a  = isset($_GET['a']) ? (string)$_GET['a'] : '';
$id = isset($_GET['id']) ? (string)$_GET['id'] : '';
$validIdNeeded = in_array($a, array('get', 'save', 'publish', 'close', 'open', 'delete'), true);
if ($validIdNeeded && !preg_match('/^[a-z0-9]{5}$/', $id)) {
    ep_out(400, array('ok' => false, 'error' => 'รหัสไม่ถูกต้อง (ต้องเป็น a-z 0-9 จำนวน 5 ตัว)'));
}

// ========== อ่าน (GET) ==========
if ($a === 'list') {
    if (isset($cfg['list']) && $cfg['list'] === 'off') {
        ep_out(403, array('ok' => false, 'error' => 'เซิร์ฟเวอร์นี้ปิดรายการรวมไว้'));
    }
    $ids = array();
    foreach ((array)glob(EP_D . '/*.md') as $f) $ids[basename($f, '.md')] = 1;
    foreach ((array)glob(EP_P . '/*.md') as $f) $ids[basename($f, '.md')] = 1;
    $items = array();
    foreach (array_keys($ids) as $k) {
        if (!preg_match('/^[a-z0-9]{5}$/', $k)) continue;
        $info = ep_info($k);
        if ($info['state'] === 'draft' && $info['bytes'] === 0) continue;   // ฉบับร่างว่างเปล่า ไม่ต้องแสดง
        $items[] = $info;
    }
    usort($items, function ($x, $y) { return $y['updated'] - $x['updated']; });
    ep_out(200, array('ok' => true, 'items' => array_slice($items, 0, 5000)));
}
if ($a === 'get') {
    if (!ep_has_d($id) && !ep_has_p($id)) {
        ep_out(404, array('ok' => false, 'error' => 'ไม่พบหน้านี้บนเซิร์ฟเวอร์'));
    }
    ep_out(200, array('ok' => true, 'md' => ep_text_of($id), 'info' => ep_info($id)));
}

// ========== เขียน (POST) ==========
if (!in_array($a, array('save', 'publish', 'close', 'open', 'delete'), true)) {
    ep_out(400, array('ok' => false, 'error' => 'ไม่รู้จักคำสั่ง'));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    ep_out(405, array('ok' => false, 'error' => 'ต้องเรียกด้วย POST'));
}
if (!isset($_SERVER['HTTP_X_EASYPAGE']) || $_SERVER['HTTP_X_EASYPAGE'] !== '1') {
    ep_out(403, array('ok' => false, 'error' => 'คำขอไม่ถูกต้อง'));
}
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    $o  = parse_url($_SERVER['HTTP_ORIGIN']);
    $oh = (isset($o['host']) ? $o['host'] : '') . (isset($o['port']) ? ':' . $o['port'] : '');
    if (!isset($_SERVER['HTTP_HOST']) || strcasecmp($oh, $_SERVER['HTTP_HOST']) !== 0) {
        ep_out(403, array('ok' => false, 'error' => 'คำขอข้ามโดเมนไม่ได้รับอนุญาต'));
    }
}

// ใครเขียนได้
if ($mode === 'password') {
    if ($hash === '') ep_out(500, array('ok' => false, 'error' => 'config.php ตั้ง publish เป็น password แต่ยังไม่ได้ใส่ password_hash'));
    $pw = isset($_SERVER['HTTP_X_EASYPAGE_PASSWORD']) ? rawurldecode($_SERVER['HTTP_X_EASYPAGE_PASSWORD']) : '';
    if ($pw === '' || !password_verify($pw, $hash)) {
        usleep(800000);
        ep_out(401, array('ok' => false, 'needPassword' => true, 'error' => $pw === '' ? 'ใส่รหัสผ่านเพื่อดำเนินการ' : 'รหัสผ่านไม่ถูกต้อง'));
    }
} elseif ($mode === 'local') {
    $ip      = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $isLocal = in_array($ip, array('127.0.0.1', '::1', '::ffff:127.0.0.1'), true);
    foreach (array('HTTP_X_FORWARDED_FOR', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP') as $h) {
        if (!empty($_SERVER[$h])) $isLocal = false;
    }
    if (!$isLocal) ep_out(403, array('ok' => false, 'error' => 'เซิร์ฟเวอร์นี้ยังไม่ได้เปิดให้แก้ไข — ตั้ง config.php: publish'));
} else {
    if ($a === 'save') ep_throttle('save', 600, 300);   // บันทึกอัตโนมัติ: 600 ครั้ง/5 นาที/IP
    else ep_throttle('op', 60, 300);                    // เผยแพร่/ปิด/เปิด/ลบ: 60 ครั้ง/5 นาที/IP
}

ep_prepare(EP_D);
ep_prepare(EP_P);
$hasD = ep_has_d($id);
$hasP = ep_has_p($id);
$exists = $hasD || $hasP;

// ---------- บันทึกฉบับร่าง ----------
if ($a === 'save') {
    $isNew = isset($_GET['new']);
    $md = file_get_contents('php://input', false, null, 0, EP_MAX_BYTES + 1);
    if ($md === false || strlen($md) > EP_MAX_BYTES) ep_out(413, array('ok' => false, 'error' => 'ข้อความใหญ่เกิน 2MB'));
    if (!mb_check_encoding($md, 'UTF-8')) ep_out(400, array('ok' => false, 'error' => 'ข้อความต้องเป็น UTF-8'));

    if ($isNew && $exists) ep_out(409, array('ok' => false, 'exists' => true, 'error' => 'รหัสนี้มีอยู่แล้ว'));
    if (!$isNew && !$exists) ep_out(404, array('ok' => false, 'error' => 'ไม่พบหน้านี้บนเซิร์ฟเวอร์ (อาจถูกลบไปแล้ว)'));
    if ($isNew && trim($md) === '') ep_out(400, array('ok' => false, 'error' => 'ไม่มีเนื้อหา'));
    if (!$exists && $maxPages > 0 && ep_count_pages() >= $maxPages) {
        ep_out(507, array('ok' => false, 'error' => 'พื้นที่เต็มแล้ว — ติดต่อผู้ดูแลเว็บ'));
    }

    $m = ep_meta($id);
    if (!$isNew && isset($_GET['base']) && $_GET['base'] !== '' && (float)$_GET['base'] < (float)$m['updated']) {
        ep_out(409, array('ok' => false, 'conflict' => true, 'error' => 'มีการแก้หน้านี้จากที่อื่นไปแล้ว', 'info' => ep_info($id)));
    }
    if (isset($_GET['exp'])) {
        $d = (string)$_GET['exp'];
        $t = isset($_GET['exptime']) ? (string)$_GET['exptime'] : '';
        ep_parse_exp($d, $t);   // ตรวจรูปแบบ/อนาคต
        $m['expDate'] = $d;
        $m['expTime'] = $d === '' ? '' : $t;
    }
    $now = ep_now_ms();
    if (!$hasD && $hasP) {   // หน้าเก่าที่มีแต่ฉบับเผยแพร่: แก้ครั้งแรก สร้างฉบับร่างจากฉบับเผยแพร่ (pubv = ฉบับเดิม)
        $m['pubv'] = (int)$m['updated'];
    }
    $m['created'] = $m['created'] ?: $now;
    $m['updated'] = max($now, (int)$m['updated'] + 1);
    ep_write(EP_D, EP_D . '/' . $id . '.md', $md);
    ep_write_meta($id, $m);
    ep_out(200, array('ok' => true, 'info' => ep_info($id)));
}

if (!$exists) ep_out(404, array('ok' => false, 'error' => 'ไม่พบหน้านี้บนเซิร์ฟเวอร์'));

// ---------- เผยแพร่: คัดลอกฉบับร่าง → ฉบับเผยแพร่ ----------
if ($a === 'publish') {
    $md = ep_text_of($id);
    if (trim($md) === '') ep_out(400, array('ok' => false, 'error' => 'ไม่มีเนื้อหาให้เผยแพร่'));
    $m   = ep_meta($id);
    $exp = ep_parse_exp((string)$m['expDate'], (string)$m['expTime']);   // อดีต = ปฏิเสธ
    if (ep_has_d($id)) ep_write(EP_P, EP_P . '/' . $id . '.md', $md);
    if ($exp) {
        ep_write(EP_P, EP_P . '/' . $id . '.exp', json_encode(array('at' => $exp['at'], 'time' => $exp['timed'])));
    } else {
        @unlink(EP_P . '/' . $id . '.exp');
    }
    @unlink(EP_P . '/' . $id . '.closed');   // เผยแพร่ = เปิดให้อ่านได้
    $m['pubv']  = (int)$m['updated'];
    $m['pubAt'] = ep_now_ms();
    if (!$m['created']) $m['created'] = $m['pubAt'];
    ep_write_meta($id, $m);
    ep_out(200, array('ok' => true, 'info' => ep_info($id)));
}

// ---------- ปิด / เปิด ----------
if ($a === 'close' || $a === 'open') {
    if (!$hasP) ep_out(400, array('ok' => false, 'error' => 'หน้านี้ยังไม่ได้เผยแพร่'));
    if ($a === 'close') {
        if (file_put_contents(EP_P . '/' . $id . '.closed', json_encode(array('closedAt' => date('c')))) === false) {
            ep_out(500, array('ok' => false, 'error' => 'ปิดการเผยแพร่ไม่สำเร็จ'));
        }
    } elseif (is_file(EP_P . '/' . $id . '.closed') && !@unlink(EP_P . '/' . $id . '.closed')) {
        ep_out(500, array('ok' => false, 'error' => 'เปิดการเผยแพร่ไม่สำเร็จ'));
    }
    ep_out(200, array('ok' => true, 'info' => ep_info($id)));
}

// ---------- ลบ: ย้ายไปเก็บใน trash/ (กู้คืนด้วยมือได้) ----------
if ($a === 'delete') {
    ep_prepare(EP_T);
    $dest = EP_T . '/' . $id . '-' . date('Ymd-His');
    if (!@mkdir($dest, 0755, true)) ep_out(500, array('ok' => false, 'error' => 'ลบไม่สำเร็จ (สร้างโฟลเดอร์ trash ไม่ได้)'));
    foreach (array(EP_D . '/' . $id . '.md', EP_D . '/' . $id . '.json', EP_P . '/' . $id . '.md', EP_P . '/' . $id . '.exp',
                   EP_P . '/' . $id . '.closed', EP_P . '/' . $id . '.key') as $f) {
        if (is_file($f)) @rename($f, $dest . '/' . basename($f));
    }
    ep_out(200, array('ok' => true, 'id' => $id));
}
