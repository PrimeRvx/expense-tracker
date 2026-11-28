-- อัปเดตฐานข้อมูลเดิมตามผล Audit (27 พ.ย. 2569)
-- import ไฟล์นี้ผ่าน phpMyAdmin (เลือกฐานข้อมูล expense_tracker ก่อน) รันซ้ำได้ไม่เสียหาย
-- ติดตั้งใหม่ไม่ต้องใช้ไฟล์นี้ เพราะ schema.sql มีให้แล้ว
-- ต้องใช้ MariaDB 10.3 ขึ้นไป (XAMPP และ Docker ของโปรเจกต์ใช้อยู่แล้ว)
SET NAMES utf8mb4;

-- 1) index สำหรับนับรายการต่อหมวดและงบประมาณ (หน้าหมวดหมู่เร็วขึ้นมากเมื่อข้อมูลเยอะ)
ALTER TABLE transactions ADD INDEX IF NOT EXISTS idx_user_cat_date (user_id, category_id, occurred_at);

-- 2) ให้ฐานข้อมูลบังคับว่าหมวดของรายการต้องเป็นของผู้ใช้คนเดียวกันและชนิดเดียวกัน
--    ถ้าขั้นนี้ error #1452 แปลว่ามีรายการที่หมวดไม่ตรงอยู่แล้ว ให้ดูรายการด้วยคำสั่งนี้:
--      SELECT t.id, t.user_id, t.type, c.user_id AS cat_user, c.type AS cat_type
--        FROM transactions t JOIN categories c ON c.id = t.category_id
--       WHERE c.user_id <> t.user_id OR c.type <> t.type;
--    แก้ได้จากหน้า admin (แท็บรายการรับจ่าย ตัวกรอง "มีปัญหา") แล้ว import ไฟล์นี้ใหม่อีกครั้ง
ALTER TABLE categories ADD UNIQUE INDEX IF NOT EXISTS uq_cat_owner (id, user_id, type);
ALTER TABLE transactions
  ADD CONSTRAINT fk_tx_cat_owner FOREIGN KEY IF NOT EXISTS (category_id, user_id, type)
  REFERENCES categories (id, user_id, type);

-- 3) อัปเดตสถิติของตารางให้ฐานข้อมูลเลือก index ได้ถูกต้องหลังเพิ่ม index ใหม่
ANALYZE TABLE transactions, categories;
