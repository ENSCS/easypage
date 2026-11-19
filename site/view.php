<?php
// ========================================
// easypage/view.php?=xxxxx — หน้าอ่านที่แชร์ให้คนอื่น
// อ่านไฟล์ p/xxxxx.md (ชื่อลิงก์ A-Za-z0-9 - _ เท่านั้น → ไม่มี path traversal) แล้วแปลงด้วย Parsedown
//
// สถานะของหน้า:
//   open    มี p/xxxxx.md และไม่มี p/xxxxx.closed → แสดงบทความ (200)
//   closed  มี p/xxxxx.closed (ผู้เขียนกด “ปิด” ในหน้าจัดการ) → หน้าแจ้งว่าปิดอยู่ ไม่แสดงบทความ (403)
//   expired มี p/xxxxx.exp และเลยเวลาแล้ว → หน้าแจ้งว่าหมดอายุ ไม่แสดงบทความ (410)
//   missing ไม่มีไฟล์ → หน้าแจ้งว่าไม่พบ (404)
// ?=xxxxx&check=1 → ตอบเฉพาะสถานะ (header X-Easypage-State) ไม่ render — หน้าจัดการ/หน้าเขียนใช้ตรวจ
// ========================================
require_once __DIR__ . '/render.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$id = null;
if (preg_match('/^(?:id)?=([A-Za-z0-9][A-Za-z0-9_-]{1,28}[A-Za-z0-9])(?:&|$)/', isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '', $m)) {
    $id = $m[1];
    // ชื่อลิงก์ไม่สนตัวพิมพ์ใหญ่/เล็ก: ถ้าไม่เจอตรงตัว ลองหาชื่อที่ต่างกันแค่ตัวพิมพ์ (ลิงก์เก่าที่เคยแปลงเป็นตัวเล็กก็ยังเปิดได้)
    if (!is_file(__DIR__ . '/p/' . $id . '.md') && !is_file(__DIR__ . '/p/' . $id . '.closed')) {
        foreach ((array)glob(__DIR__ . '/p/*.md') as $f) {
            if (strcasecmp(basename($f, '.md'), $id) === 0) { $id = basename($f, '.md'); break; }
        }
    }
}

$file   = $id ? __DIR__ . '/p/' . $id . '.md' : null;
$marker = $id ? __DIR__ . '/p/' . $id . '.closed' : null;

// วันหมดอายุ (เวลาไทย) — p/xxxxx.exp = {"at":epoch,"time":true|false}
$exp = null;
if ($id && is_file(__DIR__ . '/p/' . $id . '.exp')) {
    $e = json_decode((string)@file_get_contents(__DIR__ . '/p/' . $id . '.exp'), true);
    if (is_array($e) && isset($e['at']) && $e['at'] > 0) $exp = $e;
}

if (!$id)                     { $state = 'invalid'; $code = 400; }
elseif (is_file($marker))     { $state = 'closed';  $code = 403; }
elseif (!is_file($file))      { $state = 'missing'; $code = 404; }
elseif ($exp && time() > $exp['at']) { $state = 'expired'; $code = 410; }
else                          { $state = 'open';    $code = 200; }

if (isset($_GET['check'])) {
    header('X-Easypage-State: ' . $state);
    http_response_code($code);
    exit;
}

// ?=xxxxx&raw=1 → ส่ง markdown ดิบ (เฉพาะหน้าที่เปิดอยู่ — ปิด/หมดอายุ/ไม่พบ ตอบสถานะเฉยๆ ไม่ให้เนื้อหา)
// ใช้ให้หน้าจัดการ/หน้าเขียน “ดึงหน้าที่เผยแพร่แล้วกลับเข้ารายการ” ได้ เมื่อเปลี่ยนเบราว์เซอร์/โดเมน (เนื้อหาเดียวกับที่ผู้อ่านเห็นอยู่แล้ว)
if (isset($_GET['raw'])) {
    header('X-Easypage-State: ' . $state);
    if ($state !== 'open') {
        http_response_code($code);
        exit;
    }
    if ($exp) {
        $d = new DateTime('@' . $exp['at']);
        $d->setTimezone(new DateTimeZone('Asia/Bangkok'));
        header('X-Easypage-ExpDate: ' . $d->format('Y-m-d'));
        header('X-Easypage-ExpTime: ' . (!empty($exp['time']) ? $d->format('H:i') : ''));
    }
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

if ($state === 'invalid') {
    http_response_code(400);
    echo ep_notice('⚠️', 'ลิงก์ไม่ถูกต้อง',
        'ลิงก์นี้ไม่มีรหัสบทความ หรือรหัสไม่ครบ',
        'ตรวจสอบลิงก์ที่ได้รับอีกครั้ง หรือขอลิงก์ใหม่จากผู้ที่ส่งให้คุณ');
    exit;
}
if ($state === 'closed') {
    http_response_code(403);
    echo ep_notice('🔒', 'บทความนี้ปิดการเผยแพร่อยู่',
        'ผู้เขียนปิดบทความนี้ไว้ ขณะนี้จึงยังไม่สามารถอ่านได้',
        'หากต้องการอ่าน กรุณาติดต่อผู้ที่ส่งลิงก์นี้ให้คุณ หรือลองกลับมาใหม่ภายหลัง', $id);
    exit;
}
if ($state === 'expired') {
    $mon = array('', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.');
    $dt   = new DateTime('@' . $exp['at']);
    $dt->setTimezone(new DateTimeZone('Asia/Bangkok'));
    $when = (int)$dt->format('j') . ' ' . $mon[(int)$dt->format('n')] . ' ' . ((int)$dt->format('Y') + 543)
          . (!empty($exp['time']) ? ' เวลา ' . $dt->format('H:i') . ' น.' : '');
    http_response_code(410);
    echo ep_notice('⏳', 'ลิงก์นี้หมดอายุแล้ว',
        'บทความนี้หมดอายุเมื่อ ' . $when . ' จึงไม่สามารถอ่านได้อีก',
        'หากต้องการอ่านต่อ กรุณาติดต่อผู้ที่ส่งลิงก์นี้ให้คุณ เพื่อขอลิงก์ใหม่หรือขอขยายเวลา', $id);
    exit;
}
if ($state === 'missing') {
    http_response_code(404);
    echo ep_notice('🔍', 'ไม่พบบทความนี้',
        'อาจพิมพ์ลิงก์ผิด บทความยังไม่ได้เผยแพร่ หรือถูกลบไปแล้ว',
        'ลองตรวจสอบลิงก์อีกครั้ง หรือขอลิงก์ใหม่จากผู้ที่ส่งให้คุณ', $id);
    exit;
}

$content = file_get_contents($file);
if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);   // ตัด BOM
list($html, $math, $title) = ep_render($content);
echo ep_page($html, $math, $title, false, $id);
