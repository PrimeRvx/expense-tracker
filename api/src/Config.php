<?php
declare(strict_types=1);

final class Config
{
    /** @var array<string,mixed> */
    private static array $values = [];
    private static string $baseDir = '';

    public static function load(): void
    {
        $api = dirname(__DIR__);
        $candidates = array_filter([
            getenv('ET_CONFIG') ?: null,
            dirname($api) . '/private/config.php',    // วางไว้ข้างแอป (ในโฟลเดอร์เดียวกับ api)
            dirname($api, 2) . '/private/config.php', // อยู่นอก public_html
        ]);
        foreach ($candidates as $file) {
            if (is_file($file)) {
                self::$values = require $file;
                self::$baseDir = dirname($file);
                return;
            }
        }
        // ยังไม่พบ config: เก็บ log ไว้ข้าง api ไม่ได้ จึงส่งเข้า error_log ของ PHP
        error_log('Expense Tracker: config.php not found');
        throw new ApiError(500, 'not_configured', 'ระบบยังไม่ได้ตั้งค่า กรุณาสร้างไฟล์ config.php ตามขั้นตอนใน README');
    }

    /** @param mixed $default @return mixed */
    public static function get(string $key, $default = null)
    {
        $v = self::$values[$key] ?? $default;
        return $v === '' ? $default : $v;
    }

    public static function path(string $key, string $relative): string
    {
        return rtrim((string) self::get($key, self::$baseDir . '/' . $relative), '/');
    }

    public static function log(Throwable $e): void
    {
        $line = sprintf("[%s] %s: %s in %s:%d\n", date('c'), get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        if (self::$baseDir === '') {
            error_log($line);
            return;
        }
        $file = self::path('LOG_FILE', 'logs/app.log');
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log($line);
        }
    }
}
