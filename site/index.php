<?php
// easypage/index.php — ทางเข้าโฟลเดอร์ (…/easypage/) → ส่งต่อไปหน้าจัดการ manage.php (คง query เดิม เช่น ?=รหัส)
// ใช้ path สัมพัทธ์ ไม่ผูกกับโดเมน/โฟลเดอร์ · ตัว PIN ตรวจที่ manage.php
header('Cache-Control: no-store');
$q = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: manage.php' . $q, true, 302);
exit;
