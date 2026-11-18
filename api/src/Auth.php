<?php
declare(strict_types=1);

final class Auth
{
    private const LIFETIME = 2592000; // 30 วันแบบ sliding
    private const COOKIE = 'et_sid';

    public static function boot(): void
    {
        $dir = Config::path('SESSION_DIR', 'sessions');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        // ใช้โฟลเดอร์ session ของแอปเอง เพราะโฟลเดอร์รวมบน shared hosting อาจถูกล้างก่อน 30 วัน
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '200');
        }
        ini_set('session.gc_maxlifetime', (string) self::LIFETIME);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cache_limiter', '');
        session_name(self::COOKIE);
        session_set_cookie_params([
            'lifetime' => self::LIFETIME,
            'path' => '/',
            'secure' => (bool) Config::get('COOKIE_SECURE', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $last = (int) ($_SESSION['last_seen'] ?? 0);
        if (isset($_SESSION['uid']) && $last > 0 && $last + self::LIFETIME < time()) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        if (isset($_SESSION['uid'])) {
            $_SESSION['last_seen'] = time();
            // ต่ออายุ cookie ทุกครั้งที่ใช้งาน (PHP ไม่ส่ง cookie ซ้ำให้เอง)
            setcookie(self::COOKIE, session_id(), self::cookieParams(time() + self::LIFETIME));
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
        // เปลี่ยนรหัสผ่านแล้ว session บนอุปกรณ์อื่นจะใช้ต่อไม่ได้
        if ($user === null || !hash_equals((string) ($_SESSION['pv'] ?? ''), self::passwordVersion((string) $user['password_hash']))) {
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
        $_SESSION['uid'] = $userId;
        $_SESSION['pv'] = self::passwordVersion($passwordHash);
        $_SESSION['last_seen'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    private static function passwordVersion(string $hash): string
    {
        return hash('sha256', $hash);
    }
}
