<?php
// ส่งหน้าเว็บพร้อมบอกตำแหน่งของแอป จึงวางไว้ที่รากของเว็บหรือโฟลเดอร์ย่อยชื่ออะไรก็ได้
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/') . '/';
$html = (string) file_get_contents(__DIR__ . '/index.html');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo str_replace('<head>', '<head><base href="' . htmlspecialchars($base, ENT_QUOTES) . '">', $html);
