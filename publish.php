<?php
// ========================================
// easypage/publish.php?id=xxxxx[&action=publish|close|open]
//   publish (ค่าเริ่มต้น)  เขียน p/xxxxx.md บนเซิร์ฟเวอร์ (POST body = markdown ดิบ) + เปิดให้อ่านได้
//   close                  ปิดการเผยแพร่ (ไฟล์ธง p/xxxxx.closed → view.php แจ้งว่าปิดอยู่ ไม่แสดงบทความ)
//   open                   เปิดกลับ (ลบไฟล์ธง)
//   publish รับ &exp=YYYY-MM-DD[&exptime=HH:MM] = วันหมดอายุ (เวลาไทย; ไม่ใส่เวลา = หมดสิ้นวันนั้น 23:59:59)
//            &exp= (ว่าง) = ไม่หมดอายุ  — เก็บเป็นไฟล์ p/xxxxx.exp  · หมดอายุแล้ว view.php แจ้งว่าหมดอายุ (เนื้อหายังอยู่ ขยายวันแล้วเปิดกลับได้)
//
// ใครเรียกได้ — ตั้งที่ config.php คีย์ 'publish' (ดู config.sample.php):
//   'local'    (ค่าเริ่มต้นถ้าไม่มี config.php) เฉพาะเครื่องตัวเอง (localhost) — เซิร์ฟเวอร์จริงจะปฏิเสธทุกคำขอ
//   'open'     ใครก็เผยแพร่ได้ ไม่ต้องล็อกอิน (ช่วงนี้ใช้งานแบบนี้ก่อน) — มีตัวกันดังนี้
//                · เจ้าของหน้า: เบราว์เซอร์ที่เผยแพร่ครั้งแรกถือกุญแจ (สุ่ม) หน้านั้น แก้/ปิด/เปิดได้เฉพาะเครื่องที่ถือกุญแจ
//                  (เซิร์ฟเวอร์เก็บแค่ hash ใน p/xxxxx.key)
//                · จำกัดจำนวนครั้งต่อ IP และจำนวนหน้าทั้งหมด (max_pages)
//                · view.php แสดงผลแบบ safe mode (ไม่รัน HTML/JS ที่แทรกมาใน markdown)
//   'password' ต้องมีรหัสผ่าน (password_hash) — ผู้ถือรหัสผ่านทำได้ทุกหน้า
//
// ทุกโหมด: ต้องมี header X-Easypage: 1 (บังคับ CORS preflight → เว็บอื่นยิงข้ามโดเมนมาไม่ได้) และ Origin ต้องตรงกับ Host
//          เขียนได้เฉพาะ p/<รหัส 5 ตัว a-z 0-9>.md เท่านั้น (ไม่มี path traversal) ขนาดไม่เกิน 2MB
// ========================================
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function ep_out($code, $arr)
{
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

/** สร้างโฟลเดอร์ p/ (ถ้ายังไม่มี) พร้อม .htaccess กันเปิดไฟล์ตรงๆ — ผู้อ่านต้องเข้าผ่าน view.php เท่านั้น */
function ep_prepare_dir($dir)
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        ep_out(500, array('ok' => false, 'error' => 'สร้างโฟลเดอร์ p/ ไม่ได้ — ตรวจสิทธิ์การเขียนของโฟลเดอร์ easypage'));
    }
    if (!is_writable($dir)) {
        ep_out(500, array('ok' => false, 'error' => 'เขียนโฟลเดอร์ p/ ไม่ได้ — ตั้งสิทธิ์ให้ผู้ใช้ของเว็บเซิร์ฟเวอร์เขียนได้'));
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "# ผู้อ่านเข้าผ่าน view.php เท่านั้น — ไม่ให้เปิดไฟล์ในโฟลเดอร์นี้ตรงๆ (Apache)\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
}

/** จำกัดจำนวนครั้งต่อ IP (โหมด open) — เก็บเวลาไว้ในไฟล์ชั่วคราวของระบบ */
function ep_throttle($limit, $window)
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $fh = @fopen(sys_get_temp_dir() . '/easypage-rate-' . sha1($ip), 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $t   = json_decode(stream_get_contents($fh), true);
    $now = time();
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

// ---------- ใครเรียกได้ ----------
$cfg  = is_file(__DIR__ . '/config.php') ? include __DIR__ . '/config.php' : array();
if (!is_array($cfg)) $cfg = array();
$hash = isset($cfg['password_hash']) ? (string)$cfg['password_hash'] : '';
$mode = isset($cfg['publish']) ? (string)$cfg['publish'] : ($hash !== '' ? 'password' : 'local');
if (!in_array($mode, array('local', 'open', 'password'), true)) $mode = 'local';
$maxPages = isset($cfg['max_pages']) ? (int)$cfg['max_pages'] : 2000;

if ($mode === 'password') {
    if ($hash === '') {
        ep_out(500, array('ok' => false, 'error' => 'config.php ตั้ง publish เป็น password แต่ยังไม่ได้ใส่ password_hash'));
    }
    $pw = isset($_SERVER['HTTP_X_EASYPAGE_PASSWORD']) ? rawurldecode($_SERVER['HTTP_X_EASYPAGE_PASSWORD']) : '';
    if ($pw === '' || !password_verify($pw, $hash)) {
        usleep(800000);   // หน่วงกันเดารหัสรัว ๆ
        ep_out(401, array('ok' => false, 'needPassword' => true,
            'error' => $pw === '' ? 'ใส่รหัสผ่านเพื่อดำเนินการ' : 'รหัสผ่านไม่ถูกต้อง'));
    }
} elseif ($mode === 'local') {
    $ip      = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $isLocal = in_array($ip, array('127.0.0.1', '::1', '::ffff:127.0.0.1'), true);
    foreach (array('HTTP_X_FORWARDED_FOR', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP') as $h) {
        if (!empty($_SERVER[$h])) $isLocal = false;   // อยู่หลัง proxy → ไม่ถือว่าเป็นเครื่องตัวเอง
    }
    if (!$isLocal) {
        ep_out(403, array('ok' => false,
            'error' => 'เซิร์ฟเวอร์นี้ยังไม่ได้เปิดให้เผยแพร่ — คัดลอก config.sample.php เป็น config.php แล้วตั้งค่า publish'));
    }
} else {   // open
    ep_throttle(30, 300);   // 30 ครั้ง / 5 นาที / IP
}

// ---------- ข้อมูล ----------
$id = isset($_GET['id']) ? $_GET['id'] : '';
if (!preg_match('/^[a-z0-9]{5}$/', $id)) {
    ep_out(400, array('ok' => false, 'error' => 'รหัสไม่ถูกต้อง (ต้องเป็น a-z 0-9 จำนวน 5 ตัว)'));
}
$action = isset($_GET['action']) ? $_GET['action'] : 'publish';
if (!in_array($action, array('publish', 'close', 'open'), true)) {
    ep_out(400, array('ok' => false, 'error' => 'action ไม่ถูกต้อง'));
}
$dir     = __DIR__ . '/p';
$file    = $dir . '/' . $id . '.md';
$marker  = $dir . '/' . $id . '.closed';
$keyfile = $dir . '/' . $id . '.key';
$expfile = $dir . '/' . $id . '.exp';

// ---------- เจ้าของหน้า (โหมด open): กุญแจสุ่มที่เบราว์เซอร์ผู้เผยแพร่ครั้งแรกถือไว้ ----------
$claimHash = null;
if ($mode === 'open') {
    $tok = isset($_SERVER['HTTP_X_EASYPAGE_KEY']) ? $_SERVER['HTTP_X_EASYPAGE_KEY'] : '';
    if (!preg_match('/^[a-f0-9]{32}$/', $tok)) {
        ep_out(400, array('ok' => false, 'error' => 'คำขอไม่มีกุญแจเจ้าของหน้า — รีเฟรชหน้านี้แล้วลองใหม่'));
    }
    $h = hash('sha256', $tok);
    if (is_file($keyfile)) {
        if (trim((string)@file_get_contents($keyfile)) !== $h) {
            ep_out(403, array('ok' => false,
                'error' => 'หน้านี้เผยแพร่จากเบราว์เซอร์/เครื่องอื่น — เครื่องนี้ไม่มีสิทธิ์แก้ไขหรือปิด-เปิด'));
        }
    } else {
        $claimHash = $h;   // ยังไม่มีเจ้าของ → ผู้เรียกครั้งนี้เป็นเจ้าของ (บันทึกเมื่อทำงานสำเร็จ)
    }
    if ($action !== 'publish' && !is_file($file)) {
        ep_out(404, array('ok' => false, 'error' => 'ยังไม่มีหน้านี้บนเซิร์ฟเวอร์'));
    }
    if ($action === 'publish' && !is_file($file) && $maxPages > 0 && count((array)glob($dir . '/*.md')) >= $maxPages) {
        ep_out(507, array('ok' => false, 'error' => 'พื้นที่เผยแพร่เต็มแล้ว — ติดต่อผู้ดูแลเว็บ'));
    }
}
function ep_claim($keyfile, $claimHash)
{
    if ($claimHash !== null) @file_put_contents($keyfile, $claimHash, LOCK_EX);
}

// ---------- ปิด / เปิดการเผยแพร่ (ไม่แตะเนื้อหา) ----------
if ($action === 'close' || $action === 'open') {
    if ($action === 'close') {
        ep_prepare_dir($dir);
        if (file_put_contents($marker, json_encode(array('closedAt' => date('c')))) === false) {
            ep_out(500, array('ok' => false, 'error' => 'ปิดการเผยแพร่ไม่สำเร็จ (เขียนไฟล์ p/' . $id . '.closed ไม่ได้)'));
        }
    } elseif (is_file($marker) && !@unlink($marker)) {
        ep_out(500, array('ok' => false, 'error' => 'เปิดการเผยแพร่ไม่สำเร็จ (ลบไฟล์ p/' . $id . '.closed ไม่ได้)'));
    }
    ep_claim($keyfile, $claimHash);
    ep_out(200, array('ok' => true, 'id' => $id, 'state' => $action === 'close' ? 'closed' : 'open'));
}

// ---------- เผยแพร่ ----------
$md = file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024 + 1);
if ($md === false || strlen($md) > 2 * 1024 * 1024) {
    ep_out(413, array('ok' => false, 'error' => 'ข้อความใหญ่เกิน 2MB'));
}
if (trim($md) === '') {
    ep_out(400, array('ok' => false, 'error' => 'ไม่มีเนื้อหาให้เผยแพร่'));
}
if (!mb_check_encoding($md, 'UTF-8')) {
    ep_out(400, array('ok' => false, 'error' => 'ข้อความต้องเป็น UTF-8'));
}

// ---------- วันหมดอายุ (ตรวจก่อนเขียนอะไรทั้งหมด) ----------
$expAt = null;      // null = ไม่แตะ · 0 = ลบ (ไม่หมดอายุ) · >0 = ตั้งเวลาหมดอายุ (epoch)
$expTimed = false;
if (isset($_GET['exp'])) {
    $d = (string)$_GET['exp'];
    $t = isset($_GET['exptime']) ? (string)$_GET['exptime'] : '';
    if ($d === '') {
        $expAt = 0;
    } else {
        $okD = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $dm) && checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1]);
        $okT = $t === '' || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
        if (!$okD || !$okT) {
            ep_out(400, array('ok' => false, 'error' => 'รูปแบบวันหมดอายุไม่ถูกต้อง'));
        }
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $d . ' ' . ($t !== '' ? $t . ':00' : '23:59:59'), new DateTimeZone('Asia/Bangkok'));
        $expAt = $dt ? $dt->getTimestamp() : 0;
        if ($expAt <= time()) {
            ep_out(400, array('ok' => false, 'error' => 'วันหมดอายุต้องเป็นเวลาในอนาคต'));
        }
        $expTimed = $t !== '';
    }
}

// เขียนไฟล์ชั่วคราวแล้ว rename → ผู้อ่านไม่เห็นไฟล์ที่เขียนค้างครึ่งเดียว
ep_prepare_dir($dir);
$tmp = tempnam($dir, '.ep');
if ($tmp === false || file_put_contents($tmp, $md, LOCK_EX) === false) {
    if ($tmp) @unlink($tmp);
    ep_out(500, array('ok' => false, 'error' => 'เขียนไฟล์ไม่สำเร็จ'));
}
@chmod($tmp, 0644);
if (!@rename($tmp, $file)) {
    @unlink($tmp);
    ep_out(500, array('ok' => false, 'error' => 'บันทึกไฟล์ p/' . $id . '.md ไม่สำเร็จ'));
}

if ($expAt === 0) {
    @unlink($expfile);
} elseif ($expAt > 0) {
    if (file_put_contents($expfile, json_encode(array('at' => $expAt, 'time' => $expTimed)), LOCK_EX) === false) {
        ep_out(500, array('ok' => false, 'error' => 'เผยแพร่แล้ว แต่บันทึกวันหมดอายุไม่สำเร็จ'));
    }
}
ep_claim($keyfile, $claimHash);
@unlink($marker);   // เผยแพร่ = เปิดให้อ่านได้ (ถ้าเคยปิดไว้จะเปิดกลับ)
ep_out(200, array('ok' => true, 'id' => $id, 'bytes' => strlen($md), 'state' => 'open', 'url' => 'view.php?=' . $id));
