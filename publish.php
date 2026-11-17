<?php
// ========================================
// easypage/publish.php?id=xxxxx — เผยแพร่: เขียน p/xxxxx.md บนเซิร์ฟเวอร์ (POST body = markdown ดิบ)
//
// ความปลอดภัย (ค่าเริ่มต้นปลอดภัยไว้ก่อน):
//  - ถ้ามี config.php ที่ตั้ง 'password_hash' → ต้องส่งรหัสผ่านถูกต้อง (header X-Easypage-Password)
//  - ถ้าไม่มี config.php → ยอมเฉพาะเครื่องตัวเอง (localhost) เท่านั้น เซิร์ฟเวอร์จริงจะปฏิเสธทุกคำขอจนกว่าจะตั้งรหัสผ่าน
//  - ต้องมี header X-Easypage: 1 (บังคับ CORS preflight → เว็บอื่นยิงข้ามโดเมนมาไม่ได้) และ Origin ต้องตรงกับ Host
//  - เขียนได้เฉพาะ p/<รหัส 5 ตัว a-z 0-9>.md เท่านั้น (ไม่มี path traversal) ขนาดไม่เกิน 2MB
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

// ---------- สิทธิ์ ----------
$cfg  = is_file(__DIR__ . '/config.php') ? include __DIR__ . '/config.php' : array();
$hash = (is_array($cfg) && isset($cfg['password_hash'])) ? (string)$cfg['password_hash'] : '';

if ($hash !== '') {
    $pw = isset($_SERVER['HTTP_X_EASYPAGE_PASSWORD']) ? rawurldecode($_SERVER['HTTP_X_EASYPAGE_PASSWORD']) : '';
    if ($pw === '' || !password_verify($pw, $hash)) {
        usleep(800000);   // หน่วงกันเดารหัสรัว ๆ
        ep_out(401, array('ok' => false, 'needPassword' => true,
            'error' => $pw === '' ? 'ใส่รหัสผ่านเพื่อเผยแพร่' : 'รหัสผ่านไม่ถูกต้อง'));
    }
} else {
    $ip      = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $isLocal = in_array($ip, array('127.0.0.1', '::1', '::ffff:127.0.0.1'), true);
    foreach (array('HTTP_X_FORWARDED_FOR', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP') as $h) {
        if (!empty($_SERVER[$h])) $isLocal = false;   // อยู่หลัง proxy → ไม่ถือว่าเป็นเครื่องตัวเอง
    }
    if (!$isLocal) {
        ep_out(403, array('ok' => false,
            'error' => 'เซิร์ฟเวอร์นี้ยังไม่ได้ตั้งรหัสผ่านเผยแพร่ — คัดลอก config.sample.php เป็น config.php แล้วตั้งค่า password_hash'));
    }
}

// ---------- ข้อมูล ----------
$id = isset($_GET['id']) ? $_GET['id'] : '';
if (!preg_match('/^[a-z0-9]{5}$/', $id)) {
    ep_out(400, array('ok' => false, 'error' => 'รหัสไม่ถูกต้อง (ต้องเป็น a-z 0-9 จำนวน 5 ตัว)'));
}
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

// ---------- เขียนไฟล์ (เขียนไฟล์ชั่วคราวแล้ว rename → ผู้อ่านไม่เห็นไฟล์ที่เขียนค้างครึ่งเดียว) ----------
$dir = __DIR__ . '/p';
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    ep_out(500, array('ok' => false, 'error' => 'สร้างโฟลเดอร์ p/ ไม่ได้ — ตรวจสิทธิ์การเขียนของโฟลเดอร์ easypage'));
}
if (!is_writable($dir)) {
    ep_out(500, array('ok' => false, 'error' => 'เขียนโฟลเดอร์ p/ ไม่ได้ — ตั้งสิทธิ์ให้ผู้ใช้ของเว็บเซิร์ฟเวอร์เขียนได้'));
}
$tmp = tempnam($dir, '.ep');
if ($tmp === false || file_put_contents($tmp, $md, LOCK_EX) === false) {
    if ($tmp) @unlink($tmp);
    ep_out(500, array('ok' => false, 'error' => 'เขียนไฟล์ไม่สำเร็จ'));
}
@chmod($tmp, 0644);
if (!@rename($tmp, $dir . '/' . $id . '.md')) {
    @unlink($tmp);
    ep_out(500, array('ok' => false, 'error' => 'บันทึกไฟล์ p/' . $id . '.md ไม่สำเร็จ'));
}

ep_out(200, array('ok' => true, 'id' => $id, 'bytes' => strlen($md), 'url' => 'view.php?=' . $id));
