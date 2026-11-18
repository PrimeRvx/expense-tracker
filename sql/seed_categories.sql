-- หมวดเริ่มต้น ระบบใส่ให้เองตอนสมัครสมาชิก (ดู api/src/Seed.php) จึงไม่ต้อง import ไฟล์นี้ตอนติดตั้ง
-- ใช้เฉพาะเมื่อเพิ่มผู้ใช้ด้วยมือในฐานข้อมูล: แก้เลข @uid ให้ตรงกับ users.id แล้วรันไฟล์นี้
SET NAMES utf8mb4;
SET @uid = 1;
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
