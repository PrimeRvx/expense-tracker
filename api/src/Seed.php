<?php
declare(strict_types=1);

final class Seed
{
    private const DEFAULTS = [
        ['เงินเดือน', 'รายรับ', 'briefcase', '#27AE60', 1],
        ['โบนัส', 'รายรับ', 'star', '#E5A50A', 2],
        ['งานรับจ้างทั่วไป', 'รายรับ', 'shopping-bag', '#14A3A8', 3],
        ['รายรับอื่นๆ', 'รายรับ', 'more-horizontal', '#B8BFD0', 4],
        ['ค่าอาหาร', 'รายจ่าย', 'utensils', '#F59A4B', 1],
        ['ค่าเช่า', 'รายจ่าย', 'home', '#3B8EEA', 2],
        ['ค่าสาธารณูปโภค', 'รายจ่าย', 'zap', '#A06BD8', 3],
        ['ความบันเทิง', 'รายจ่าย', 'gamepad-2', '#6CC04A', 4],
        ['รายจ่ายอื่นๆ', 'รายจ่าย', 'more-horizontal', '#B8BFD0', 5],
    ];

    public static function categories(int $userId): void
    {
        foreach (self::DEFAULTS as [$name, $type, $icon, $color, $order]) {
            Db::run(
                'INSERT INTO categories (user_id, name, type, icon, color, sort_order, is_default) VALUES (?, ?, ?, ?, ?, ?, 1)',
                [$userId, $name, $type, $icon, $color, $order]
            );
        }
    }
}
