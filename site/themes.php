<?php
// easypage/themes.php — รายการธีมสีของหน้าอ่าน (ใช้ร่วมกันโดย render.php / api.php / edit.php)
// สีจริงอยู่ใน mdview.css ([data-ctheme="id"]) — เพิ่มธีม: เพิ่มที่นี่ + เพิ่มบล็อกสีใน mdview.css

/** ธีมสีของหน้า: id => [ชื่อ, สีหลัก, สีเน้น] — สีจริงอยู่ใน mdview.css ([data-ctheme="id"]) · ทุกธีมมีสีทั้งโหมดสว่าง/มืด — ผู้อ่านสลับเองได้ (ปุ่ม Dark/Light ในหน้าอ่าน) */
function ep_themes()
{
    return array(
        'default' => array('ค่าเริ่มต้น', '#cc785c', '#17437d'),
        't1'      => array('เขียว–ส้ม', '#0B8A74', '#E8890C'),
        't2'      => array('ฟ้า–เหลืองอำพัน', '#0088CC', '#FFB100'),
        'h2'      => array('น้ำเงินเข้ม–ส้มสด (คอนทราสต์สูง)', '#0033CC', '#FF6A00'),
    );
}
/** กรองค่าธีมที่รับมา → id ที่มีจริงเท่านั้น (ไม่รู้จัก = default) */
function ep_theme_id($t)
{
    $t = strtolower(trim((string)$t));
    $all = ep_themes();
    return isset($all[$t]) ? $t : 'default';
}
