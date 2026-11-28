<?php
declare(strict_types=1);

final class Auth
{
    private const DEFAULT_DAYS = 7; // อายุ session แบบ sliding (ตั้งใน config ได้ด้วย SESSION_DAYS)
    private const COOKIE = 'et_sid';

    /** อายุ session เป็นวินาที: นับจากการใช้งานครั้งล่าสุด ค่าเริ่มต้น 7 วัน (1-30 วัน) */
    private static function lifetime(): int
    {
        $days = (int) Config::get('SESSION_DAYS', self::DEFAULT_DAYS);
        return max(1, min(30, $days)) * 86400;
    }

    public static function boot(): void
    {
        $dir = Config::path('SESSION_DIR', 'sessions');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        // ใช้โฟลเดอร์ session ของแอปเอง เพราะโฟลเดอร์รวมบน shared hosting อาจถูกล้างก่อนหมดอายุ
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '200');
        }
        ini_set('session.gc_maxlifetime', (string) self::lifetime());
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cache_limiter', '');
        session_name(self::COOKIE);
        session_set_cookie_params([
            'lifetime' => self::lifetime(),
            'path' => '/',
            'secure' => (bool) Config::get('COOKIE_SECURE', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $last = (int) ($_SESSION['last_seen'] ?? 0);
        if (isset($_SESSION['uid']) && $last > 0 && $last + self::lifetime() < time()) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        if (isset($_SESSION['uid'])) {
            $_SESSION['last_seen'] = time();
            // ต่ออายุ cookie ทุกครั้งที่ใช้งาน (PHP ไม่ส่ง cookie ซ้ำให้เอง)
            setcookie(self::COOKIE, session_id(), self::cookieParams(time() + self::lifetime()));
        }
    }

    /** @return array<string,mixed> */
    private static function cookieParams(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => (bool) Config::get('COOKIE_SECURE', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    public static function verifyCsrf(): void
    {
        $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($sent === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
            throw new ApiError(403, 'csrf_invalid', 'หน้าเว็บหมดอายุ กรุณาลองใหม่อีกครั้ง');
        }
    }

    public static function csrfToken(): string
    {
        return (string) $_SESSION['csrf'];
    }

    public static function currentUser(): ?array
    {
        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid === 0) {
            return null;
        }
        $user = Db::one('SELECT id, email, display_name, password_hash FROM users WHERE id = ?', [$uid]);
        // เปลี่ยนรหัสผ่านหรืออีเมลแล้ว session บนอุปกรณ์อื่นจะใช้ต่อไม่ได้
        if ($user === null || !hash_equals((string) ($_SESSION['pv'] ?? ''), self::passwordVersion((string) $user['password_hash'], (string) $user['email']))) {
            self::destroy();
            return null;
        }
        unset($user['password_hash']);
        return $user;
    }

    public static function requireUserId(): int
    {
        $user = self::currentUser();
        if ($user === null) {
            throw new ApiError(401, 'unauthorized', 'กรุณาเข้าสู่ระบบ');
        }
        // ปล่อย session lock เพื่อให้ request พร้อมกันของหน้าเดียวกันไม่ต้องรอคิว
        session_write_close();
        return (int) $user['id'];
    }

    public static function signIn(int $userId, string $passwordHash): void
    {
        session_regenerate_id(true);
        $email = (string) (Db::one('SELECT email FROM users WHERE id = ?', [$userId])['email'] ?? '');
        $_SESSION['uid'] = $userId;
        $_SESSION['pv'] = self::passwordVersion($passwordHash, $email);
        $_SESSION['last_seen'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    /** ลายนิ้วมือของบัญชี: เปลี่ยนเมื่อรหัสผ่านหรืออีเมลเปลี่ยน ทำให้ session เก่าทุกเครื่องหลุด */
    private static function passwordVersion(string $hash, string $email): string
    {
        return hash('sha256', $hash . "\n" . strtolower($email));
    }
}
