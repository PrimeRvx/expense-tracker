<?php
declare(strict_types=1);

/**
 * IP ของผู้ใช้จริง ใช้กับการจำกัดจำนวนครั้งล็อกอินและขอรหัส OTP
 * เมื่อมี reverse proxy (เช่น Caddy ใน Docker) อยู่หน้าเว็บ REMOTE_ADDR จะเป็น IP ของ proxy
 * จึงอ่านจาก X-Forwarded-For แทน แต่เชื่อเฉพาะเมื่อคำขอมาจาก proxy ที่ระบุใน TRUSTED_PROXIES
 * (ถ้าไม่จำกัด ใครก็ปลอม header นี้เพื่อหลบการจำกัดจำนวนครั้งได้)
 */
final class ClientIp
{
    public static function binary(): string
    {
        return inet_pton(self::resolve(
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            self::trusted()
        )) ?: "\0\0\0\0";
    }

    /** @return list<string> */
    private static function trusted(): array
    {
        $raw = Config::get('TRUSTED_PROXIES', '');
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);
        return array_values(array_filter(array_map('trim', $list), static fn ($s) => $s !== ''));
    }

    /**
     * ไล่ X-Forwarded-For จากขวาไปซ้าย ข้าม proxy ที่เชื่อถือ แล้วคืน IP แรกที่ไม่ใช่ proxy
     * @param list<string> $trusted
     */
    public static function resolve(string $remote, string $forwarded, array $trusted): string
    {
        if ($trusted === [] || !self::inAny($remote, $trusted)) {
            return $remote;
        }
        $hops = array_reverse(array_filter(array_map('trim', explode(',', $forwarded)), static fn ($s) => $s !== ''));
        foreach ($hops as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return $remote; // header ผิดรูปแบบ ไม่เชื่อ
            }
            if (!self::inAny($ip, $trusted)) {
                return $ip;
            }
        }
        return $remote;
    }

    /** @param list<string> $ranges */
    private static function inAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    private static function inRange(string $ip, string $range): bool
    {
        [$net, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $a = @inet_pton($ip);
        $b = @inet_pton((string) $net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
