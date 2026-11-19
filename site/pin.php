<?php
// ========================================
// easypage/pin.php — เรียกระบบ PIN กลาง (โฟลเดอร์ pin/ ที่ cboard ใช้) ก่อนทำงานทุกหน้าที่ต้องล็อก
// ใช้:  require __DIR__ . '/pin.php';   (บนสุดของไฟล์ ก่อน output ใดๆ)
//
// ไม่ fix path/URL: หา pin/gate.php แบบสัมพัทธ์จากโฟลเดอร์นี้ ขึ้นไปทีละชั้น
//   <โฟลเดอร์นี้>/../pin  → ../../pin → ../../../pin   (โครงสร้างปกติ: tool/pin + tool/easypage)
// ย้ายไปโฮสอื่นแล้วโครงสร้างต่างไป → ตั้ง 'pin_gate' => '/path/ถึง/pin/gate.php' ใน config.php
// หาไม่เจอ = ล็อกปิดไว้ก่อน (ไม่ปล่อยให้ใครก็เข้าได้)
// ========================================
(function () {
    $cands = array();
    $cfg = @is_file(__DIR__ . '/config.php') ? @include __DIR__ . '/config.php' : null;
    if (is_array($cfg) && !empty($cfg['pin_gate'])) $cands[] = (string)$cfg['pin_gate'];
    $up = '';
    for ($i = 0; $i < 3; $i++) { $up .= '/..'; $cands[] = __DIR__ . $up . '/pin/gate.php'; }
    foreach ($cands as $c) {
        if (is_file($c)) { require_once $c; return; }
    }
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "ไม่พบระบบ PIN (pin/gate.php) — วางโฟลเดอร์ pin/ ไว้ข้างโฟลเดอร์นี้ หรือตั้ง 'pin_gate' ใน config.php\n";
    exit;
})();
