<?php
// ========================================
// easypage/view.php?=xxxxx — หน้าอ่านที่แชร์ให้คนอื่น
// อ่านไฟล์ p/xxxxx.md (รหัส 5 ตัว a-z 0-9 เท่านั้น → ไม่มี path traversal) แล้วแปลงด้วย Parsedown
// ========================================
require_once __DIR__ . '/render.php';

header('X-Robots-Tag: noindex');

$id = null;
if (preg_match('/^(?:id)?=([A-Za-z0-9]{5})(?:&|$)/', isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '', $m)) {
    $id = strtolower($m[1]);
}

$file = $id ? __DIR__ . '/p/' . $id . '.md' : null;

if (!$file || !is_file($file)) {
    http_response_code(404);
    $msg = $id
        ? "# ไม่พบหน้านี้\n\nรหัส `$id` ยังไม่ถูกเผยแพร่ หรือถูกลบไปแล้ว"
        : "# ลิงก์ไม่ถูกต้อง\n\nต้องมีรหัส 5 ตัวอักษร เช่น `view.php?=ab12c`";
    list($html, $math, $title) = ep_render($msg);
    echo ep_page($html, false, $title);
    exit;
}

$content = file_get_contents($file);
if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);   // ตัด BOM
list($html, $math, $title) = ep_render($content);
echo ep_page($html, $math, $title);
