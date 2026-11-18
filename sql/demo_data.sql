-- ข้อมูลตัวอย่าง 12 เดือนย้อนหลังจากวันที่ import สำหรับทดลองดูกราฟ
-- เข้าสู่ระบบด้วย demo@example.com / demo1234
-- ห้ามใช้บนเว็บจริง: ถ้าเผลอ import ให้รัน  DELETE FROM users WHERE email='demo@example.com';
SET NAMES utf8mb4;
SET time_zone = '+07:00';

INSERT INTO users (email, password_hash, display_name)
VALUES ('demo@example.com', '$2y$10$dfI/SVS84QrBsXTez7tRx.1IMdUzPPtoMSp6aAPHH27fhAgpxs6Bm', 'ผู้ใช้ทดลอง');
SET @uid = LAST_INSERT_ID();

INSERT INTO categories (user_id, name, type, icon, color, sort_order, is_default) VALUES
(@uid,'เงินเดือน','รายรับ','briefcase','#27AE60',1,1),
(@uid,'โบนัส','รายรับ','star','#E5A50A',2,1),
(@uid,'งานรับจ้างทั่วไป','รายรับ','shopping-bag','#14A3A8',3,1),
(@uid,'รายรับอื่นๆ','รายรับ','more-horizontal','#B8BFD0',4,1),
(@uid,'ค่าอาหาร','รายจ่าย','utensils','#F59A4B',1,1),
(@uid,'ค่าเช่า','รายจ่าย','home','#3B8EEA',2,1),
(@uid,'ค่าสาธารณูปโภค','รายจ่าย','zap','#A06BD8',3,1),
(@uid,'ความบันเทิง','รายจ่าย','gamepad-2','#6CC04A',4,1),
(@uid,'รายจ่ายอื่นๆ','รายจ่าย','more-horizontal','#B8BFD0',5,1);

-- ตารางตัวเลข 0..11 สำหรับเดือน
-- เงินเดือน ทุกวันที่ 1
INSERT INTO transactions (user_id, category_id, type, amount, occurred_at, note, source)
SELECT @uid, c.id, 'รายรับ', 16500 + (MOD(m.n * 7, 5) * 400),
       TIMESTAMP(DATE_FORMAT(CURDATE() - INTERVAL m.n MONTH, '%Y-%m-01'), '09:00:00'), 'รับเงินเดือน', 'manual'
FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
      UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11) m
JOIN categories c ON c.user_id = @uid AND c.name = 'เงินเดือน';

-- งานรับจ้าง เดือนเว้นเดือน
INSERT INTO transactions (user_id, category_id, type, amount, occurred_at, note, source)
SELECT @uid, c.id, 'รายรับ', 2500 + (MOD(m.n * 3, 4) * 750),
       TIMESTAMP(DATE_FORMAT(CURDATE() - INTERVAL m.n MONTH, '%Y-%m-02'), '16:30:00'), 'ค่าจ้างออกแบบโปสเตอร์', 'text'
FROM (SELECT 0 n UNION ALL SELECT 2 UNION ALL SELECT 4 UNION ALL SELECT 6 UNION ALL SELECT 8 UNION ALL SELECT 10) m
JOIN categories c ON c.user_id = @uid AND c.name = 'งานรับจ้างทั่วไป'
WHERE TIMESTAMP(DATE_FORMAT(CURDATE() - INTERVAL m.n MONTH, '%Y-%m-02'), '16:30:00') <= NOW();

-- โบนัสกลางปี
INSERT INTO transactions (user_id, category_id, type, amount, occurred_at, note, source)
SELECT @uid, c.id, 'รายรับ', 12000,
       TIMESTAMP(DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH, '%Y-%m-15'), '10:00:00'), 'โบนัสครึ่งปี', 'manual'
FROM categories c WHERE c.user_id = @uid AND c.name = 'โบนัส';

-- ค่าเช่า ทุกวันที่ 1
INSERT INTO transactions (user_id, category_id, type, amount, occurred_at, note, source)
SELECT @uid, c.id, 'รายจ่าย', 3500,
       TIMESTAMP(DATE_FORMAT(CURDATE() - INTERVAL m.n MONTH, '%Y-%m-01'), '10:15:00'), 'ค่าเช่าห้อง', 'manual'
FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
      UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11) m
JOIN categories c ON c.user_id = @uid AND c.name = 'ค่าเช่า'
WHERE TIMESTAMP(DATE_FORMAT(CURDATE() - INTERVAL m.n MONTH, '%Y-%m-01'), '10:15:00') <= NOW();

-- ค่าน้ำค่าไฟ ทุกวันที่ 3
INSERT INTO transactions (user_id, category_id, type, amount, occurred_at, note, source)
SELECT @uid, c.id, 'รายจ่าย', 780 + (MOD(m.n * 11, 7) * 95),
       TIMESTAMP(DATE_FORMAT(CURDATE() - INTERVAL m.n MONTH, '%Y-%m-03'), '18:40:00'), 'ค่าไฟและค่าน้ำ', 'slip'
FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
      UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11) m
JOIN categories c ON c.user_id = @uid AND c.name = 'ค่าสาธารณูปโภค'
WHERE TIMESTAMP(DATE_FORMAT(CURDATE() - INTERVAL m.n MONTH, '%Y-%m-03'), '18:40:00') <= NOW();

-- ตัวเลขรายวันย้อนหลัง 0..364 ใช้ร่วมกันใน 3 คำสั่งด้านล่าง
-- ค่าอาหารมื้อกลางวัน ทุกวัน
INSERT INTO transactions (user_id, category_id, type, amount, occurred_at, note, source)
SELECT @uid, c.id, 'รายจ่าย', 45 + MOD(d.i * 13, 8) * 10,
       TIMESTAMP(CURDATE() - INTERVAL d.i DAY, '12:20:00'),
       ELT(MOD(d.i, 6) + 1, 'ข้าวกะเพราไก่', 'ก๋วยเตี๋ยวเรือ', 'ข้าวมันไก่', 'ส้มตำไก่ย่าง', 'ข้าวผัดหมู', 'สุกี้น้ำ'), 'text'
FROM (SELECT a.v + b.v * 10 + c2.v * 100 AS i
      FROM (SELECT 0 v UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
      CROSS JOIN (SELECT 0 v UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b
      CROSS JOIN (SELECT 0 v UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) c2) d
JOIN categories c ON c.user_id = @uid AND c.name = 'ค่าอาหาร'
WHERE d.i < 365 AND TIMESTAMP(CURDATE() - INTERVAL d.i DAY, '12:20:00') <= NOW();

-- กาแฟตอนเช้า วันเว้นวัน
INSERT INTO transactions (user_id, category_id, type, amount, occurred_at, note, source)
SELECT @uid, c.id, 'รายจ่าย', 55 + MOD(d.i, 3) * 10,
       TIMESTAMP(CURDATE() - INTERVAL d.i DAY, '08:10:00'), 'กาแฟเย็น', 'text'
FROM (SELECT a.v + b.v * 10 + c2.v * 100 AS i
      FROM (SELECT 0 v UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
      CROSS JOIN (SELECT 0 v UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b
      CROSS JOIN (SELECT 0 v UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) c2) d
JOIN categories c ON c.user_id = @uid AND c.name = 'ค่าอาหาร'
WHERE d.i < 365 AND MOD(d.i, 2) = 0 AND TIMESTAMP(CURDATE() - INTERVAL d.i DAY, '08:10:00') <= NOW();

-- ความบันเทิงและรายจ่ายอื่นๆ ทุก 6 วัน
INSERT INTO transactions (user_id, category_id, type, amount, occurred_at, note, source)
SELECT @uid, IF(MOD(d.i, 12) = 0, c1.id, c2x.id), 'รายจ่าย', 160 + MOD(d.i * 17, 9) * 45,
       TIMESTAMP(CURDATE() - INTERVAL d.i DAY, '19:20:00'),
       IF(MOD(d.i, 12) = 0, ELT(MOD(d.i, 3) + 1, 'ดูหนังกับเพื่อน', 'เติมเกม', 'คาราโอเกะ'),
                            ELT(MOD(d.i, 4) + 1, 'ค่าเดินทาง', 'ซื้อของใช้ในบ้าน', 'เติมน้ำมัน', 'ค่าโทรศัพท์')), 'manual'
FROM (SELECT a.v + b.v * 10 + c2.v * 100 AS i
      FROM (SELECT 0 v UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
      CROSS JOIN (SELECT 0 v UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b
      CROSS JOIN (SELECT 0 v UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) c2) d
JOIN categories c1 ON c1.user_id = @uid AND c1.name = 'ความบันเทิง'
JOIN categories c2x ON c2x.user_id = @uid AND c2x.name = 'รายจ่ายอื่นๆ'
WHERE d.i < 365 AND MOD(d.i, 6) = 0 AND TIMESTAMP(CURDATE() - INTERVAL d.i DAY, '19:20:00') <= NOW();

-- งบประมาณตัวอย่าง
INSERT INTO budgets (user_id, category_id, amount)
SELECT @uid, id, CASE name WHEN 'ค่าอาหาร' THEN 9000 WHEN 'ความบันเทิง' THEN 1500 WHEN 'ค่าสาธารณูปโภค' THEN 2500 WHEN 'รายจ่ายอื่นๆ' THEN 2000 END
FROM categories WHERE user_id = @uid AND name IN ('ค่าอาหาร', 'ความบันเทิง', 'ค่าสาธารณูปโภค', 'รายจ่ายอื่นๆ');
