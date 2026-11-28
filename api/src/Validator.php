<?php
declare(strict_types=1);

final class Validator
{
    public const TYPES = ['รายรับ', 'รายจ่าย'];

    /** @return array<string,mixed> */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true, 16, JSON_BIGINT_AS_STRING);
        if (!is_array($data)) {
            throw new ApiError(400, 'bad_json', 'รูปแบบข้อมูลไม่ถูกต้อง');
        }
        return $data;
    }

    public static function fail(string $field, string $message): ApiError
    {
        return new ApiError(422, 'validation', $message, ['field' => $field]);
    }

    /** คืนจำนวนเงินเป็นสตริงทศนิยม 2 ตำแหน่ง ไม่ผ่าน float เพื่อกันการปัดเศษ @param mixed $value */
    public static function amount($value): string
    {
        if (is_int($value) || is_float($value)) {
            $value = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }
        if (!is_string($value) || !preg_match('/^\d{1,9}(\.\d{1,2})?$/', trim($value))) {
            throw self::fail('amount', 'จำนวนเงินต้องมากกว่า 0 ไม่เกิน 999,999,999.99 และมีทศนิยมไม่เกิน 2 ตำแหน่ง');
        }
        $value = trim($value);
        if (Money::toSatang($value) <= 0) {
            throw self::fail('amount', 'จำนวนเงินต้องมากกว่า 0 ไม่เกิน 999,999,999.99 และมีทศนิยมไม่เกิน 2 ตำแหน่ง');
        }
        return Money::fromSatang(Money::toSatang($value));
    }

    /** @param mixed $value */
    public static function type($value): string
    {
        if (!is_string($value) || !in_array($value, self::TYPES, true)) {
            throw self::fail('type', 'ชนิดรายการต้องเป็นรายรับหรือรายจ่าย');
        }
        return $value;
    }

    /** @param mixed $value */
    public static function note($value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) || mb_strlen($value) > 500) {
            throw self::fail('note', 'หมายเหตุยาวได้ไม่เกิน 500 ตัวอักษร');
        }
        return trim($value);
    }

    /** @param mixed $value */
    public static function dateTime($value): string
    {
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', trim($value), $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) $m[1] >= 1970 && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) ($m[6] ?? 0) < 60) {
            return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? '00');
        }
        throw self::fail('occurred_at', 'วันที่และเวลาไม่ถูกต้อง');
    }

    /** @param mixed $value */
    public static function date($value, string $field = 'date'): string
    {
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[1] >= 1970) {
            return $value;
        }
        throw self::fail($field, 'วันที่ไม่ถูกต้อง');
    }

    /** @param mixed $value */
    public static function month($value): string
    {
        if (is_string($value) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value) && (int) substr($value, 0, 4) >= 1970) {
            return $value;
        }
        throw self::fail('month', 'เดือนไม่ถูกต้อง');
    }

    /** @param mixed $value */
    public static function year($value): int
    {
        if ((is_string($value) && preg_match('/^\d{4}$/', $value)) && (int) $value >= 1970 && (int) $value <= 2200) {
            return (int) $value;
        }
        throw self::fail('year', 'ปีไม่ถูกต้อง');
    }

    /**
     * รับค่าข้อความจาก JSON: null/ไม่ส่ง = ค่าเริ่มต้น, ตัวเลขแปลงเป็นข้อความ
     * array/object/boolean ตอบ 422 แทนการปล่อยให้ (string) เกิด warning แล้วกลายเป็น 500
     * @param mixed $value
     */
    public static function str($value, string $field, string $default = ''): string
    {
        if ($value === null) {
            return $default;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        throw self::fail($field, 'รูปแบบข้อมูลไม่ถูกต้อง');
    }

    /** อ่านค่าข้อความจาก query string (?q[]=x จะได้ array ซึ่งตอบ 422) */
    public static function query(string $key, string $default = ''): string
    {
        return self::str($_GET[$key] ?? null, $key, $default);
    }

    /** @param mixed $value */
    public static function positiveInt($value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9]\d{0,17}$/', $value)) {
            return (int) $value;
        }
        throw self::fail($field, 'ข้อมูลไม่ถูกต้อง');
    }
}
