<?php
declare(strict_types=1);

final class AuthController
{
    private const MAX_ATTEMPTS = 5;        // อีเมลเดียวจากเครื่องเดียว
    private const MAX_PER_EMAIL = 10;      // อีเมลเดียวจากทุกเครื่องรวมกัน (กันการรุมเดาจากหลาย IP)
    private const MAX_PER_IP = 20;         // เครื่องเดียวกับทุกอีเมลรวมกัน (กันการไล่เดาหลายบัญชี)
    private const WINDOW_SECONDS = 900;
    private const MAX_PASSWORD_CHANGE_FAILS = 5; // ใส่รหัสเดิมผิดในหน้าเปลี่ยนรหัสผ่านได้กี่ครั้งต่อ 15 นาที

    private static function canRegister(): bool
    {
        if (Config::get('ALLOW_REGISTER', false) === true) {
            return true;
        }
        return Db::one('SELECT id FROM users LIMIT 1') === null;
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    private static function publicUser(array $user): array
    {
        return ['id' => (int) $user['id'], 'email' => $user['email'], 'displayName' => $user['display_name']];
    }

    public static function me(): void
    {
        $user = Auth::currentUser();
        Response::ok([
            'user' => $user === null ? null : self::publicUser($user),
            'csrfToken' => Auth::csrfToken(),
            'canRegister' => $user === null ? self::canRegister() : false,
            'isAdmin' => $user !== null && AdminController::isAdmin((int) $user['id']),
            'otpEnabled' => Mailer::enabled(),
        ]);
    }

    public static function register(): void
    {
        if (!self::canRegister()) {
            throw new ApiError(403, 'register_closed', 'ระบบปิดการสมัครสมาชิกอยู่');
        }
        $body = Validator::jsonBody();
        $email = self::email($body['email'] ?? null);
        $password = self::password($body['password'] ?? null, 'password');
        $name = trim(Validator::str($body['display_name'] ?? null, 'display_name'));
        if (mb_strlen($name) > 100) {
            throw Validator::fail('display_name', 'ชื่อที่แสดงยาวได้ไม่เกิน 100 ตัวอักษร');
        }
        if (Db::one('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            throw new ApiError(409, 'email_taken', 'อีเมลนี้ถูกใช้แล้ว');
        }
        // เมื่อเปิดระบบอีเมล ต้องยืนยันว่าเป็นเจ้าของอีเมลก่อนจึงสร้างบัญชี
        if (Mailer::enabled()) {
            Otp::verify($email, 'register', $body['code'] ?? null);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        // ล็อกการสมัครทีละคน: กันสองคนสมัครพร้อมกันตอนติดตั้งใหม่แล้วได้ admin ทั้งคู่ และกันอีเมลซ้ำชนกัน
        $locked = (int) (Db::one("SELECT GET_LOCK('expense_tracker_register', 10) AS ok")['ok'] ?? 0) === 1;
        if (!$locked) {
            throw new ApiError(503, 'busy', 'ระบบกำลังสมัครสมาชิกให้ผู้ใช้อื่นอยู่ กรุณาลองใหม่อีกครั้ง');
        }
        try {
            $userId = Db::transaction(static function () use ($email, $hash, $name): int {
                if (Db::one('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
                    throw new ApiError(409, 'email_taken', 'อีเมลนี้ถูกใช้แล้ว');
                }
                $isFirst = Db::one('SELECT id FROM users LIMIT 1') === null;
                try {
                    Db::run('INSERT INTO users (email, password_hash, display_name) VALUES (?, ?, ?)', [$email, $hash, $name]);
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        throw new ApiError(409, 'email_taken', 'อีเมลนี้ถูกใช้แล้ว');
                    }
                    throw $e;
                }
                $id = Db::lastId();
                if ($isFirst) {
                    // บัญชีแรกของระบบคือเจ้าของเว็บ ได้สิทธิ์ผู้ดูแล (ข้ามไปถ้าฐานข้อมูลยังไม่มีคอลัมน์ role)
                    try {
                        Db::run("UPDATE users SET role = 'admin' WHERE id = ?", [$id]);
                    } catch (PDOException $e) {
                        if ($e->getCode() !== '42S22') {
                            throw $e;
                        }
                    }
                }
                Seed::categories($id);
                return $id;
            });
        } finally {
            Db::run("SELECT RELEASE_LOCK('expense_tracker_register')");
        }
        Auth::signIn($userId, $hash);
        Response::ok([
            'user' => ['id' => $userId, 'email' => $email, 'displayName' => $name],
            'csrfToken' => Auth::csrfToken(),
        ], 201);
    }

    public static function login(): void
    {
        $body = Validator::jsonBody();
        $email = strtolower(trim(Validator::str($body['email'] ?? null, 'email')));
        $password = Validator::str($body['password'] ?? null, 'password');
        if ($email === '' || $password === '' || strlen($email) > 190) {
            throw Validator::fail('email', 'กรุณากรอกอีเมลและรหัสผ่าน');
        }
        $ip = self::clientIp();

        $row = Db::one(
            'SELECT COALESCE(SUM(ip = ? AND email = ?), 0) AS pair, COALESCE(SUM(email = ?), 0) AS by_email, COALESCE(SUM(ip = ?), 0) AS by_ip
               FROM login_attempts
              WHERE attempted_at >= (NOW() - INTERVAL 15 MINUTE) AND (ip = ? OR email = ?)',
            [$ip, $email, $email, $ip, $ip, $email]
        );
        if (self::isLocked((int) $row['pair'], (int) $row['by_email'], (int) $row['by_ip'])) {
            header('Retry-After: ' . self::WINDOW_SECONDS);
            throw new ApiError(429, 'too_many_attempts', 'ใส่รหัสผ่านผิดหลายครั้งเกินไป กรุณารอ 15 นาทีแล้วลองใหม่');
        }

        $user = Db::one('SELECT id, email, display_name, password_hash FROM users WHERE email = ?', [$email]);
        // ตรวจ hash เสมอแม้ไม่พบอีเมล เพื่อไม่ให้เวลาตอบบอกได้ว่าอีเมลมีอยู่จริง
        $hash = $user['password_hash'] ?? password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        if (!password_verify($password, $hash) || $user === null) {
            Db::run('INSERT INTO login_attempts (email, ip) VALUES (?, ?)', [$email, $ip]);
            Db::run('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
            throw new ApiError(401, 'invalid_credentials', 'อีเมลหรือรหัสผ่านไม่ถูกต้อง');
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, (int) $user['id']]);
        }
        Db::run('DELETE FROM login_attempts WHERE ip = ? AND email = ?', [$ip, $email]);
        Auth::signIn((int) $user['id'], $hash);
        Response::ok(['user' => self::publicUser($user), 'csrfToken' => Auth::csrfToken()]);
    }

    private static function clientIp(): string
    {
        return ClientIp::binary();
    }

    /** ขอรหัสยืนยันทางอีเมล body: {"email": "...", "purpose": "register|login|reset"} */
    public static function requestOtp(): void
    {
        if (!Mailer::enabled()) {
            throw new ApiError(404, 'otp_disabled', 'ระบบยังไม่ได้เปิดใช้การส่งอีเมล');
        }
        $body = Validator::jsonBody();
        $email = self::email($body['email'] ?? null);
        $purpose = Otp::purpose($body['purpose'] ?? null);
        $exists = Db::one('SELECT id FROM users WHERE email = ?', [$email]) !== null;
        if ($purpose === 'register') {
            if (!self::canRegister()) {
                throw new ApiError(403, 'register_closed', 'ระบบปิดการสมัครสมาชิกอยู่');
            }
            if ($exists) {
                throw new ApiError(409, 'email_taken', 'อีเมลนี้ถูกใช้แล้ว');
            }
        }
        $ip = self::clientIp();
        Otp::reserve($email, $purpose, $ip);
        // เข้าสู่ระบบ/ลืมรหัสผ่าน: ตอบเหมือนกันไม่ว่าอีเมลจะมีบัญชีหรือไม่ เพื่อไม่บอกว่าใครเป็นสมาชิก
        Otp::issue($email, $purpose, $ip, $purpose === 'register' || $exists);
        Response::ok(['expiresIn' => Otp::TTL_MINUTES * 60, 'resendIn' => 60]);
    }

    /** เข้าสู่ระบบด้วยรหัสจากอีเมล body: {"email": "...", "code": "123456"} */
    public static function loginOtp(): void
    {
        $body = Validator::jsonBody();
        $email = self::email($body['email'] ?? null);
        Otp::verify($email, 'login', $body['code'] ?? null);
        $user = Db::one('SELECT id, email, display_name, password_hash FROM users WHERE email = ?', [$email]);
        if ($user === null) {
            throw new ApiError(422, 'otp_invalid', 'รหัสยืนยันไม่ถูกต้องหรือหมดอายุแล้ว กรุณาขอรหัสใหม่', ['field' => 'code']);
        }
        Auth::signIn((int) $user['id'], (string) $user['password_hash']);
        Response::ok(['user' => self::publicUser($user), 'csrfToken' => Auth::csrfToken()]);
    }

    /** ตั้งรหัสผ่านใหม่ด้วยรหัสจากอีเมล body: {"email": "...", "code": "123456", "new_password": "..."} */
    public static function resetPassword(): void
    {
        $body = Validator::jsonBody();
        $email = self::email($body['email'] ?? null);
        // ตรวจรหัสผ่านใหม่ก่อน รหัสยืนยันจะได้ไม่ถูกใช้ทิ้งเพราะรหัสผ่านสั้นเกินไป
        $new = self::password($body['new_password'] ?? null, 'new_password');
        Otp::verify($email, 'reset', $body['code'] ?? null);
        $user = Db::one('SELECT id, email, display_name FROM users WHERE email = ?', [$email]);
        if ($user === null) {
            throw new ApiError(422, 'otp_invalid', 'รหัสยืนยันไม่ถูกต้องหรือหมดอายุแล้ว กรุณาขอรหัสใหม่', ['field' => 'code']);
        }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, (int) $user['id']]);
        Db::run('DELETE FROM login_attempts WHERE email = ?', [$email]);
        // session เดิมทุกเครื่องหลุดเอง เพราะผูกกับรหัสผ่านเก่า
        Auth::signIn((int) $user['id'], $hash);
        Response::ok(['user' => self::publicUser($user), 'csrfToken' => Auth::csrfToken()]);
    }

    /** จำนวนครั้งที่ใส่ผิดใน 15 นาที: คู่อีเมล+เครื่อง, อีเมลนี้จากทุกเครื่อง, เครื่องนี้กับทุกอีเมล */
    public static function isLocked(int $pair, int $byEmail, int $byIp): bool
    {
        return $pair >= self::MAX_ATTEMPTS || $byEmail >= self::MAX_PER_EMAIL || $byIp >= self::MAX_PER_IP;
    }

    public static function logout(): void
    {
        Auth::destroy();
        Response::ok(['csrfToken' => Auth::csrfToken()]);
    }

    public static function changePassword(int $userId): void
    {
        $body = Validator::jsonBody();
        $current = Validator::str($body['current_password'] ?? null, 'current_password');
        // นับการใส่รหัสเดิมผิดแยกจากหน้าเข้าสู่ระบบ (คีย์ chpw:<id>) กันคนที่ได้เครื่องที่ลืมออกจากระบบไปไล่เดารหัส
        $key = 'chpw:' . $userId;
        $fails = (int) Db::one(
            'SELECT COUNT(*) AS n FROM login_attempts WHERE email = ? AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)',
            [$key]
        )['n'];
        if ($fails >= self::MAX_PASSWORD_CHANGE_FAILS) {
            header('Retry-After: ' . self::WINDOW_SECONDS);
            throw new ApiError(429, 'too_many_attempts', 'ใส่รหัสผ่านเดิมผิดหลายครั้งเกินไป กรุณารอ 15 นาทีแล้วลองใหม่');
        }
        $user = Db::one('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if ($user === null || !password_verify($current, (string) $user['password_hash'])) {
            Db::run('INSERT INTO login_attempts (email, ip) VALUES (?, ?)', [$key, self::clientIp()]);
            throw new ApiError(422, 'wrong_password', 'รหัสผ่านเดิมไม่ถูกต้อง', ['field' => 'current_password']);
        }
        Db::run('DELETE FROM login_attempts WHERE email = ?', [$key]);
        $new = self::password($body['new_password'] ?? null, 'new_password');
        $hash = password_hash($new, PASSWORD_DEFAULT);
        Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $userId]);
        // requireUserId ปิด session ไปแล้ว ต้องเปิดใหม่เพื่อเขียนค่าเวอร์ชันรหัสผ่าน
        session_start();
        Auth::signIn($userId, $hash);
        Response::ok(['csrfToken' => Auth::csrfToken()]);
    }

    /** @param mixed $value */
    private static function email($value): string
    {
        $email = strtolower(trim(Validator::str($value, 'email')));
        if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw Validator::fail('email', 'รูปแบบอีเมลไม่ถูกต้อง');
        }
        return $email;
    }

    /** @param mixed $value */
    private static function password($value, string $field): string
    {
        if (!is_string($value) || mb_strlen($value) < 8) {
            throw Validator::fail($field, 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
        }
        if (strlen($value) > 72) {
            throw Validator::fail($field, 'รหัสผ่านยาวเกินไป ใช้ได้ไม่เกิน 72 ไบต์');
        }
        return $value;
    }
}
