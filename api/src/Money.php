<?php
declare(strict_types=1);

/** คำนวณเงินเป็นหน่วยสตางค์ (จำนวนเต็ม) เพื่อเลี่ยงการใช้ float */
final class Money
{
    /** @param string|int|null $value */
    public static function toSatang($value): int
    {
        $s = trim((string) ($value ?? '0'));
        $negative = strpos($s, '-') === 0;
        $s = ltrim($s, '-+');
        $parts = explode('.', $s, 2);
        $baht = $parts[0] === '' ? 0 : (int) $parts[0];
        $dec = substr(str_pad($parts[1] ?? '', 2, '0'), 0, 2);
        $total = $baht * 100 + (int) $dec;
        return $negative ? -$total : $total;
    }

    public static function fromSatang(int $satang): string
    {
        $sign = $satang < 0 ? '-' : '';
        $abs = abs($satang);
        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    public static function savingsRate(int $income, int $expense): float
    {
        return $income === 0 ? 0.0 : round(($income - $expense) / $income * 100, 1);
    }

    /** เปอร์เซ็นต์เปลี่ยนแปลงเทียบช่วงก่อนหน้า คืน null เมื่อไม่มีข้อมูลให้เทียบ */
    public static function change(int $current, int $previous): ?float
    {
        return $previous === 0 ? null : round(($current - $previous) / abs($previous) * 100, 1);
    }
}
