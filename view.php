<?php
// ========================================
// easypage/view.php?=xxxxx — หน้าอ่านที่แชร์ให้คนอื่น
// อ่านไฟล์ p/xxxxx.md (รหัส 5 ตัว a-z 0-9 เท่านั้น → ไม่มี path traversal) แล้วแปลงด้วย Parsedown
//
// สถานะของหน้า:
//   open    มี p/xxxxx.md และไม่มี p/xxxxx.closed → แสดงบทความ (200)
//   closed  มี p/xxxxx.closed (ผู้เขียนกด “ปิด” ในหน้าจัดการ) → หน้าแจ้งว่าปิดอยู่ ไม่แสดงบทความ (403)
//   missing ไม่มีไฟล์ → หน้าแจ้งว่าไม่พบ (404)
// ?=xxxxx&check=1 → ตอบเฉพาะสถานะ (header X-Easypage-State) ไม่ render — หน้าจัดการ/หน้าเขียนใช้ตรวจ
// ========================================
require_once __DIR__ . '/render.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$id = null;
if (preg_match('/^(?:id)?=([A-Za-z0-9]{5})(?:&|$)/', isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '', $m)) {
    $id = strtolower($m[1]);
}

$file   = $id ? __DIR__ . '/p/' . $id . '.md' : null;
$marker = $id ? __DIR__ . '/p/' . $id . '.closed' : null;

if (!$id)                     { $state = 'invalid'; $code = 400; }
elseif (is_file($marker))     { $state = 'closed';  $code = 403; }
elseif (is_file($file))       { $state = 'open';    $code = 200; }
else                          { $state = 'missing'; $code = 404; }

if (isset($_GET['check'])) {
    header('X-Easypage-State: ' . $state);
    http_response_code($code);
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
echo ep_page($html, $math, $title);
