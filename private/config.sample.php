<?php
// คัดลอกไฟล์นี้เป็น config.php แล้วกรอกข้อมูลเชื่อมต่อฐานข้อมูล
// config.php อยู่ใน .gitignore ห้ามนำขึ้น git
return [
    'DB_HOST' => 'localhost',
    'DB_PORT' => 3306,
    'DB_NAME' => 'expense_tracker',
    'DB_USER' => 'ชื่อผู้ใช้ฐานข้อมูล',
    'DB_PASS' => 'รหัสผ่านฐานข้อมูล',

    // สมัครบัญชีแรกได้เสมอ หลังจากนั้นเปิดสมัครเพิ่มเฉพาะเมื่อค่านี้เป็น true
    'ALLOW_REGISTER' => false,

    // ส่ง session cookie เฉพาะผ่าน HTTPS (ตั้ง false เฉพาะตอนพัฒนาในเครื่องด้วย http เท่านั้น)
    'COOKIE_SECURE' => true,

    // อีเมลสำหรับรหัสยืนยัน (OTP): สมัครสมาชิก เข้าสู่ระบบด้วยรหัส และลืมรหัสผ่าน
    // MAIL_DRIVER: 'smtp' = ส่งจริง, 'log' = เขียนรหัสลง private/logs/mail.log (พัฒนาในเครื่องเท่านั้น), '' = ปิด
    // Gmail: เปิดการยืนยันสองขั้นตอน แล้วสร้าง App Password ที่ https://myaccount.google.com/apppasswords มาใส่ใน SMTP_PASS
    'MAIL_DRIVER' => '',
    'SMTP_HOST' => 'smtp.gmail.com',
    'SMTP_PORT' => 465,
    'SMTP_SECURE' => 'ssl',            // ssl (พอร์ต 465) หรือ tls (พอร์ต 587)
    'SMTP_USER' => 'you@gmail.com',
    'SMTP_PASS' => '',
    'MAIL_FROM' => 'you@gmail.com',    // Gmail ต้องตรงกับ SMTP_USER
    'MAIL_FROM_NAME' => 'Expense Tracker',

    // ที่เก็บไฟล์ เว้นว่างไว้เพื่อใช้โฟลเดอร์ย่อยข้างไฟล์ config นี้
    'UPLOAD_DIR' => '',   // ค่าเริ่มต้น: private/uploads/slips
    'SESSION_DIR' => '',  // ค่าเริ่มต้น: private/sessions
    'LOG_FILE' => '',     // ค่าเริ่มต้น: private/logs/app.log
];
