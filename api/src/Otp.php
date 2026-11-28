<?php
declare(strict_types=1);

/** รหัสยืนยัน 6 หลักทางอีเมล: ใช้ได้ครั้งเดียว หมดอายุใน 10 นาที ใส่ผิดได้ไม่เกิน 5 ครั้ง */
final class Otp
{
    public const PURPOSES = ['register', 'login', 'reset'];
    public const TTL_MINUTES = 10;
    private const MAX_TRIES = 5;
    private const RESEND_SECONDS = 60;
    private const MAX_PER_EMAIL_HOUR = 5;
    private const MAX_PER_IP_HOUR = 20;

    private const LABELS = ['register' => 'สมัครสมาชิก', 'login' => 'เข้าสู่ระบบ', 'reset' => 'ตั้งรหัสผ่านใหม่'];

    /** ติดตั้งตารางให้เองเมื่อพบว่ายังไม่มี (เรียกผ่าน Db::withTable) เว็บที่ติดตั้งไว้ก่อนแล้วจึงไม่ต้อง import SQL เพิ่ม */
    public static function ensureTable(): void
    {
        Db::run(
            "CREATE TABLE IF NOT EXISTS email_otps (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               email VARCHAR(190) NOT NULL,
               purpose ENUM('register','login','reset') NOT NULL,
               code_hash VARCHAR(255) NOT NULL,
               tries TINYINT UNSIGNED NOT NULL DEFAULT 0,
               used TINYINT(1) NOT NULL DEFAULT 0,
               ip VARBINARY(16) NOT NULL,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               expires_at DATETIME NOT NULL,
               KEY idx_email_purpose (email, purpose, created_at),
               KEY idx_ip_time (ip, created_at)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function purpose($value): string
    {
        if (!is_string($value) || !in_array($value, self::PURPOSES, true)) {
            throw Validator::fail('purpose', 'ข้อมูลไม่ถูกต้อง');
        }
        return $value;
    }

    /** ตรวจโควตาการขอรหัส แล้วคืน true เมื่อขอได้ (แยกไว้ให้ทดสอบได้โดยไม่ต้องมีฐานข้อมูล) */
    public static function quotaError(int $secondsSinceLast, int $emailLastHour, int $ipLastHour): ?string
    {
        if ($secondsSinceLast >= 0 && $secondsSinceLast < self::RESEND_SECONDS) {
            return 'เพิ่งส่งรหัสไป กรุณารอ ' . (self::RESEND_SECONDS - $secondsSinceLast) . ' วินาทีแล้วขอใหม่';
        }
        if ($emailLastHour >= self::MAX_PER_EMAIL_HOUR || $ipLastHour >= self::MAX_PER_IP_HOUR) {
            return 'ขอรหัสบ่อยเกินไป กรุณาลองใหม่ในอีก 1 ชั่วโมง';
        }
        return null;
    }

    /** ตรวจโควตาและจดการขอไว้ (เรียกเสมอ แม้สุดท้ายจะไม่ส่งอีเมล เพื่อให้คำตอบเหมือนกันทุกกรณี) */
    public static function reserve(string $email, string $purpose, string $ip): void
    {
        Db::withTable([self::class, 'ensureTable'], static fn () => Db::run('DELETE FROM email_otps WHERE created_at < (NOW() - INTERVAL 1 DAY)'));
        $row = Db::one(
            'SELECT COALESCE(SUM(email = ?), 0) AS by_email, COALESCE(SUM(ip = ?), 0) AS by_ip,
                    COALESCE(MIN(CASE WHEN email = ? AND purpose = ? THEN TIMESTAMPDIFF(SECOND, created_at, NOW()) END), -1) AS since
               FROM email_otps WHERE created_at >= (NOW() - INTERVAL 1 HOUR) AND (email = ? OR ip = ?)',
            [$email, $ip, $email, $purpose, $email, $ip]
        );
        $error = self::quotaError((int) $row['since'], (int) $row['by_email'], (int) $row['by_ip']);
        if ($error !== null) {
            throw new ApiError(429, 'otp_too_many', $error);
        }
    }

    /** สร้างรหัสใหม่ (รหัสเก่าของอีเมล+จุดประสงค์เดียวกันใช้ไม่ได้อีก) แล้วส่งอีเมล; $deliver=false = จดโควตาอย่างเดียว */
    public static function issue(string $email, string $purpose, string $ip, bool $deliver): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Db::run('UPDATE email_otps SET used = 1 WHERE email = ? AND purpose = ?', [$email, $purpose]);
        Db::run(
            'INSERT INTO email_otps (email, purpose, code_hash, used, ip, expires_at) VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ' . self::TTL_MINUTES . ' MINUTE)',
            [$email, $purpose, $deliver ? password_hash($code, PASSWORD_DEFAULT) : '', $deliver ? 0 : 1, $ip]
        );
        if (!$deliver) {
            return;
        }
        $label = self::LABELS[$purpose];
        try {
            Mailer::send(
                $email,
                "รหัสยืนยันสำหรับ{$label}: {$code}",
                "รหัสยืนยันสำหรับ{$label}ใน Expense Tracker คือ\n\n    {$code}\n\nรหัสนี้ใช้ได้ครั้งเดียวและหมดอายุใน " . self::TTL_MINUTES . " นาที\nถ้าคุณไม่ได้เป็นผู้ขอ ไม่ต้องทำอะไร และอย่าบอกรหัสนี้กับใคร\n"
            );
        } catch (Throwable $e) {
            Config::log($e);
            throw new ApiError(503, 'mail_failed', 'ส่งอีเมลไม่สำเร็จ กรุณาลองใหม่ภายหลัง');
        }
    }

    /** ตรวจรหัส ผ่านแล้วใช้ซ้ำไม่ได้ ไม่ผ่านให้โยนข้อผิดพลาดที่บอกผู้ใช้ได้ */
    public static function verify(string $email, string $purpose, $code): void
    {
        $invalid = new ApiError(422, 'otp_invalid', 'รหัสยืนยันไม่ถูกต้องหรือหมดอายุแล้ว กรุณาขอรหัสใหม่', ['field' => 'code']);
        if (!is_string($code) || !preg_match('/^\d{6}$/', $code)) {
            throw $invalid;
        }
        $row = Db::withTable([self::class, 'ensureTable'], static fn () => Db::one(
            'SELECT id, code_hash, tries FROM email_otps
              WHERE email = ? AND purpose = ? AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1',
            [$email, $purpose]
        ));
        if ($row === null || (int) $row['tries'] >= self::MAX_TRIES) {
            throw $invalid;
        }
        if (!password_verify($code, (string) $row['code_hash'])) {
            Db::run('UPDATE email_otps SET tries = tries + 1 WHERE id = ?', [(int) $row['id']]);
            $left = self::MAX_TRIES - (int) $row['tries'] - 1;
            throw new ApiError(422, 'otp_invalid', $left > 0 ? "รหัสยืนยันไม่ถูกต้อง ลองได้อีก {$left} ครั้ง" : 'ใส่รหัสผิดหลายครั้งเกินไป กรุณาขอรหัสใหม่', ['field' => 'code']);
        }
        Db::run('UPDATE email_otps SET used = 1 WHERE id = ?', [(int) $row['id']]);
    }
}
