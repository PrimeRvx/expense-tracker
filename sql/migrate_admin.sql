-- อัปเดตฐานข้อมูลเดิมให้รองรับระบบผู้ดูแล (admin)
-- import ไฟล์นี้ครั้งเดียวผ่าน phpMyAdmin (เลือกฐานข้อมูล expense_tracker ก่อน)
-- ติดตั้งใหม่ไม่ต้องใช้ไฟล์นี้ เพราะ schema.sql มีให้แล้ว
-- รันซ้ำได้ (ต้องใช้ MariaDB 10.3 ขึ้นไป ซึ่ง XAMPP และ Docker ของโปรเจกต์ใช้อยู่แล้ว)
SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS role ENUM('user','admin') NOT NULL DEFAULT 'user' AFTER display_name;

-- บันทึกทุกการกระทำของผู้ดูแล ไม่ผูก foreign key เพื่อให้ประวัติยังอยู่แม้ลบบัญชีไปแล้ว
CREATE TABLE IF NOT EXISTS admin_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NOT NULL,
  admin_email VARCHAR(190) NOT NULL,
  target_user_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  detail TEXT NOT NULL,                -- JSON: ค่าก่อน/หลังแก้
  ip VARBINARY(16) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_target (target_user_id, created_at),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- บัญชีแรกของระบบ (เจ้าของเว็บ) เป็นผู้ดูแล เฉพาะเมื่อยังไม่มีผู้ดูแลเลย (รันไฟล์นี้ซ้ำได้)
UPDATE users SET role = 'admin'
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM users WHERE role = 'admin') AS a)
 ORDER BY id LIMIT 1;

-- ถ้าต้องการตั้งคนอื่นเป็นผู้ดูแลเพิ่ม ให้แก้อีเมลแล้วรันบรรทัดนี้
-- UPDATE users SET role = 'admin' WHERE email = 'someone@example.com';
