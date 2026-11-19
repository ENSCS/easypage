<?php
// ========================================
// easypage/preview.php — ตัวอย่างสดสำหรับหน้า edit
// รับ markdown ดิบทาง POST (body) → ส่งหน้า HTML เต็ม เหมือน view.php ทุกประการ (ไม่เก็บอะไรไว้ที่เซิร์ฟเวอร์)
// ค่าเริ่มต้น = แสดงเฉพาะเนื้อหา (ใส่ใน iframe ของหน้า edit) · ?full=1 = มีปุ่มธีม/ขนาดตัวอักษรเหมือนหน้าอ่านจริง (ปุ่ม "เปิดดู")
// ========================================
$_SERVER['HTTP_ACCEPT'] = 'application/json';
require __DIR__ . '/pin.php';   // ล็อกด้วย PIN (view.php ยังเปิดสาธารณะ)
require_once __DIR__ . '/render.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$md = file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024 + 1);   // ไม่เกิน 2MB
if (strlen($md) > 2 * 1024 * 1024) {
    http_response_code(413);
    exit;
}

list($html, $math, $title) = ep_render($md);
header('Content-Type: text/html; charset=UTF-8');
echo ep_page($html !== '' ? $html : '<p class="empty">พิมพ์ข้อความทางซ้าย แล้วตัวอย่างจะแสดงที่นี่</p>', $math, $title, !isset($_GET['full']));
