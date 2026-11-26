# Expense Tracker

แอปบันทึกรายรับรายจ่าย ภาษาไทย ใช้ได้ทั้งเดสก์ท็อปและมือถือ ติดตั้งบน shared hosting/cPanel ทั่วไปได้โดยไม่ต้องมี Composer, Node หรือ SSH บน server

- Frontend: React, Vite, TypeScript, Bootstrap 5.3, Recharts
- Backend: PHP 8.1+ แบบไม่ใช้ framework, PDO
- Database: MySQL 5.7+ หรือ MariaDB 10.4+
- ไม่เรียกบริการภายนอก: ฟอนต์ ตัวอ่านข้อความไทย และ OCR สลิปอยู่บนเว็บของคุณเองทั้งหมด

## สิ่งที่ hosting ต้องมี

- PHP 8.1 ขึ้นไป พร้อม extension: `pdo_mysql`, `mbstring`, `fileinfo`
- Apache ที่เปิด `mod_rewrite` (แนะนำ `mod_headers`) และอนุญาตให้ใช้ `.htaccess`
- HTTPS (ระบบบังคับใช้ HTTPS และส่ง cookie เฉพาะผ่าน HTTPS)

## ติดตั้ง

ใช้ไฟล์ `expense-tracker-install.zip` ข้างในมีโฟลเดอร์ `expense-tracker` ที่พร้อมใช้งาน แอปวางที่รากของเว็บหรือโฟลเดอร์ย่อยชื่ออะไรก็ได้

1. **สร้างฐานข้อมูล** ชื่อ `expense_tracker` เลือก collation `utf8mb4_unicode_ci` คลิกเลือกฐานข้อมูลนั้นก่อน แล้ว import `sql/schema.sql` ผ่าน phpMyAdmin
2. **วางไฟล์**
   - XAMPP: แตก zip ลงใน `C:\xampp\htdocs` จะได้ `C:\xampp\htdocs\expense-tracker\` แล้วเปิด `http://localhost/expense-tracker/`
   - Hosting: อัปโหลดเนื้อหาในโฟลเดอร์ `expense-tracker` ไปไว้ใน `public_html` รวมไฟล์ที่ขึ้นต้นด้วยจุด เช่น `.htaccess` (เปิดให้แสดงไฟล์เหล่านี้ใน File Manager ก่อน)
3. **ตั้งค่าการเชื่อมต่อ** ไฟล์ `private/config.php` ตั้งค่าสำหรับ XAMPP มาให้แล้ว (ฐานข้อมูล `expense_tracker`, ผู้ใช้ `root`, ไม่มีรหัสผ่าน) ถ้าค่าของคุณต่างออกไปหรือขึ้นเว็บจริง ให้แก้ค่าในไฟล์นี้
4. **ให้สิทธิ์เขียน** โฟลเดอร์ `private` ต้องให้ PHP เขียนได้ เพราะเก็บ `uploads/slips`, `sessions` และ `logs` (ปกติใช้ 750 หรือ 755)
5. **สมัครบัญชีแรก** เปิดเว็บแล้วสมัครได้ทันที เมื่อมีผู้ใช้แล้วระบบจะปิดการสมัครเองตราบใดที่ `ALLOW_REGISTER` เป็น `false` (ค่าเริ่มต้น) ถ้าต้องการเพิ่มผู้ใช้ ให้เปลี่ยนเป็น `true` ชั่วคราวแล้วเปลี่ยนกลับ

โฟลเดอร์ `private` มี `.htaccess` ปิดการเข้าถึงตรงอยู่แล้ว ถ้า hosting มีที่นอก `public_html` จะย้าย `private` ไปไว้ข้าง `public_html` เพื่อความปลอดภัยที่มากขึ้นก็ได้ ระบบหาเจอทั้งสองตำแหน่ง

### แก้โค้ดแล้ว build เอง

ใช้ไฟล์ `expense-tracker-source.zip` รัน `npm install && npm run build` ผลลัพธ์อยู่ในโฟลเดอร์ `public_html` (ไม่แตะโฟลเดอร์ `api`) แล้วนำเนื้อหาในนั้นไปวางแทนของเดิม

### ขนาดไฟล์อัปโหลด

ตั้ง `upload_max_filesize` และ `post_max_size` ให้ไม่ต่ำกว่า `12M` ระบบตั้งให้ผ่าน `api/.htaccess` และ `api/.user.ini` แล้ว ถ้า hosting ไม่ยอมรับ ให้ตั้งใน cPanel ที่ MultiPHP INI Editor

### ปัญหาที่พบบ่อย

| อาการ | สาเหตุและวิธีแก้ |
|---|---|
| เข้าหน้าอื่นนอกจากหน้าแรกแล้วขึ้น 404 | `.htaccess` ไม่ได้อัปโหลด หรือ hosting ไม่เปิด `mod_rewrite` |
| ขึ้นว่า "ระบบยังไม่ได้ตั้งค่า" | ไม่พบ `private/config.php` ตรวจตามขั้นตอนที่ 3 |
| หน้าขาวไม่มีอะไรขึ้น | เปิดโฟลเดอร์ซอร์สโค้ดแทนโฟลเดอร์ติดตั้ง ใช้ `expense-tracker-install.zip` และลบโฟลเดอร์เดิมก่อนแตกไฟล์ |
| ขึ้นว่า "ระบบขัดข้องชั่วคราว" | ดูรายละเอียดใน `private/logs/app.log` มักเป็นรหัสผ่านฐานข้อมูลผิด |
| ล็อกอินแล้วเด้งกลับหน้าเดิม | ยังไม่ได้ใช้ HTTPS ติดตั้ง SSL ก่อน (ห้ามปิด `COOKIE_SECURE` บนเว็บจริง) ถ้าทดสอบในเครื่องให้ตั้งเป็น `false` |
| บันทึกหรือลบแล้ว error 403/405 | hosting บางเจ้าบล็อก PUT/DELETE หรือเปิด ModSecurity เข้มงวด ให้แจ้ง hosting เปิดให้ใช้งาน |

## สำรองข้อมูล

ต้องสำรองสองส่วน:

1. **ฐานข้อมูล** ใช้ phpMyAdmin เมนู Export หรือคำสั่ง
   ```bash
   mysqldump --single-transaction --default-character-set=utf8mb4 -u USER -p expense_tracker > backup.sql
   ```
2. **รูปสลิป** ดาวน์โหลดโฟลเดอร์ `private/uploads/slips` ทั้งหมด

ถ้าต้องการเฉพาะตัวเลข ใช้เมนู เพิ่มเติม > ข้อมูลและสำรองข้อมูล > ส่งออก CSV (ไม่รวมรูปสลิป) ไฟล์นี้เปิดใน Excel ได้และนำเข้ากลับได้

## ลองดูด้วยข้อมูลตัวอย่าง

Import `sql/demo_data.sql` เข้าฐานข้อมูลทดลอง แล้วเข้าสู่ระบบด้วย `demo@example.com` / `demo1234` จะได้ข้อมูล 12 เดือนย้อนหลังจากวันที่ import

**ห้าม import ไฟล์นี้บนเว็บจริง** เพราะรหัสผ่านเป็นที่รู้กัน และจะทำให้ระบบเข้าใจว่ามีผู้ใช้แล้ว ถ้าเผลอ import ให้รัน `DELETE FROM users WHERE email='demo@example.com';`

## อัปเดตจากเวอร์ชันก่อน

แตกไฟล์ทับของเดิมได้เลย (ไม่ทับ `private/config.php` ถ้าไม่ได้คัดลอกไปด้วย) ตารางงบประมาณ `budgets` ระบบสร้างให้เองครั้งแรกที่เปิดหน้าหลัก ถ้า user ฐานข้อมูลไม่มีสิทธิ์ CREATE ให้ import ส่วน `CREATE TABLE budgets` จาก `sql/schema.sql` ผ่าน phpMyAdmin แทน

## ปรับหน้าตา

รายละเอียดระบบการออกแบบและการเคลื่อนไหวอยู่ใน `DESIGN.md`


ทุกอย่างอยู่ใน `src/styles/app.scss` แล้วรัน `npm run build`

1. **เปลี่ยนสีหลัก ฟอนต์ มุมโค้ง** แก้ตัวแปร Bootstrap ในส่วนที่ 1 ของไฟล์ เช่น `$primary`, `$card-border-radius`, `$btn-border-radius`
2. **ระบบการออกแบบ** ส่วนที่ 2 มีตัวแปร CSS กลาง ได้แก่ `--space-*` ระยะห่าง, `--radius-*` มุมโค้ง, `--shadow-1` ถึง `--shadow-4` เงา, `--gradient-*` ไล่สี และ `--dur-*` ความเร็วแอนิเมชัน แก้ที่เดียวมีผลทั้งแอป
3. **เปลี่ยนสีแต่ละโทน** แก้ตัวแปรในส่วนที่ 2 ค่าใน `[data-bs-theme='light']` คือโทนสว่าง และ `[data-bs-theme='dark']` คือโทนมืด กราฟใช้สีชุดนี้ด้วย
4. **ปรับความเร็วหรือความเด้งของการเคลื่อนไหว** แก้ค่าสปริงใน `src/lib/motion.ts` และ `--ease-spring` ในส่วนที่ 2 ลูกเล่นทั้งหมดปิดเองเมื่อเครื่องผู้ใช้ตั้งลดการเคลื่อนไหว

## พัฒนาในเครื่อง

ต้องมี Docker และ Node 20 ขึ้นไป

```bash
docker compose up -d          # เว็บ+API http://localhost:8080, phpMyAdmin http://localhost:8081
npm install
npm run dev                   # หน้าเว็บ http://localhost:5173 เรียก /api ผ่าน proxy ไปที่ container
```

## ทดสอบ

```bash
npm test                                                  # unit test ของ parser
php tests/api/run_tests.php http://localhost:8080         # ทดสอบ API ผ่าน HTTP จริง
node tests/e2e/flows.cjs http://localhost:8080 /tmp/e2e   # ทดสอบหน้าเว็บจริง (ต้องมี Playwright)
```

สคริปต์ทดสอบ API และหน้าเว็บจะสมัครผู้ใช้ทดสอบ จึงต้องตั้ง `ALLOW_REGISTER=true` และใช้กับฐานข้อมูลสำหรับทดสอบเท่านั้น

## โครงสร้างโปรเจกต์

```
public_html/        ไฟล์ที่ขึ้นเว็บ: หน้าเว็บที่ build แล้ว + api/ (PHP)
private/            config, รูปสลิป, session, log (อยู่นอก web root)
sql/                schema.sql, seed_categories.sql, demo_data.sql
src/                ซอร์สโค้ด React
  lib/parser.ts     อ่านข้อความบันทึกด่วนและข้อความจากสลิป
  config/tips.ts    เคล็ดลับการออม แก้ไขเพิ่มได้
  styles/app.scss   สี ธีม และลูกเล่นทั้งหมด
public/             ไฟล์ static ที่คัดลอกไปตอน build
tests/              ชุดทดสอบ
docker/             ไฟล์สำหรับโหมดพัฒนา
```

## ผู้ดูแลระบบ (admin)

หน้าสำหรับช่วยเหลือผู้ใช้และแก้ข้อมูลแทน เปิดที่ `/admin/` เช่น `http://localhost/expense-tracker/admin/`

**ติดตั้งบนฐานข้อมูลเดิม** import `sql/migrate_admin.sql` ผ่าน phpMyAdmin ครั้งเดียว บัญชีแรกของระบบ (id น้อยที่สุด) จะเป็นผู้ดูแล ติดตั้งใหม่ด้วย `schema.sql` ไม่ต้องทำขั้นนี้ บัญชีแรกที่สมัครเป็นผู้ดูแลเอง

ทำอะไรได้บ้าง

- ค้นหาผู้ใช้ ดูยอดรวม จำนวนรายการ และการใช้งานล่าสุด
- แก้ชื่อ อีเมล และตั้งหรือถอนสิทธิ์ผู้ดูแล (ลดสิทธิ์ตัวเองไม่ได้ จึงมีผู้ดูแลเหลืออย่างน้อยหนึ่งคนเสมอ)
- ปลดล็อกบัญชีที่ใส่รหัสผิดเกินจำนวน และตั้งรหัสผ่านชั่วคราวให้คนที่ลืมรหัส (ทุกเครื่องของผู้ใช้จะออกจากระบบ)
- แก้หรือลบรายการรับจ่าย เปิดดูรูปสลิป แก้ชื่อและสีหมวด กู้หมวดเริ่มต้นที่หายไป
- ตรวจหาข้อมูลผิดปกติ: รายการที่ชนิดไม่ตรงกับหมวด และสลิปที่ไฟล์รูปหาย
- ลบบัญชีพร้อมข้อมูลทั้งหมด (ต้องพิมพ์อีเมลยืนยัน)

ทุกการแก้ไข รวมถึงการเปิดดูสลิป ถูกบันทึกในตาราง `admin_audit` ว่าใครทำอะไร ค่าก่อนและหลังแก้ ดูได้ที่แท็บ "ประวัติการแก้ไข" ของผู้ใช้แต่ละคน

ตั้งผู้ดูแลเพิ่มได้จากหน้า admin หรือรันใน phpMyAdmin: `UPDATE users SET role = 'admin' WHERE email = 'someone@example.com';`
