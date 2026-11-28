<?php
declare(strict_types=1);

final class CategoryController
{
    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'type' => $r['type'],
            'icon' => $r['icon'],
            'color' => $r['color'],
            'sortOrder' => (int) $r['sort_order'],
            'isDefault' => (bool) $r['is_default'],
            'txCount' => (int) ($r['tx_count'] ?? 0),
        ];
    }

    public static function index(int $userId): void
    {
        $rows = Db::all(
            'SELECT c.*, (SELECT COUNT(*) FROM transactions t WHERE t.category_id = c.id AND t.user_id = c.user_id) AS tx_count
             FROM categories c WHERE c.user_id = ? ORDER BY c.type, c.sort_order, c.id',
            [$userId]
        );
        Response::ok(array_map([self::class, 'present'], $rows));
    }

    public static function store(int $userId): void
    {
        $body = Validator::jsonBody();
        [$name, $icon, $color] = self::fields($body);
        $type = Validator::type($body['type'] ?? null);
        $next = Db::one('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM categories WHERE user_id = ? AND type = ?', [$userId, $type]);
        try {
            Db::run(
                'INSERT INTO categories (user_id, name, type, icon, color, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $name, $type, $icon, $color, (int) $next['n']]
            );
        } catch (PDOException $e) {
            throw self::duplicateOr($e);
        }
        Response::ok(self::present(self::find(Db::lastId(), $userId)), 201);
    }

    public static function update(int $userId, int $id): void
    {
        $current = self::find($id, $userId);
        $body = Validator::jsonBody();
        [$name, $icon, $color] = self::fields(array_merge($current, $body));
        $type = Validator::type($body['type'] ?? $current['type']);
        if ($type !== $current['type'] && ((int) $current['is_default'] === 1 || self::txCount($id, $userId) > 0)) {
            throw new ApiError(409, 'category_type_locked', 'เปลี่ยนชนิดของหมวดนี้ไม่ได้ เพราะเป็นหมวดเริ่มต้นหรือมีรายการอยู่');
        }
        try {
            Db::run(
                'UPDATE categories SET name = ?, type = ?, icon = ?, color = ? WHERE id = ? AND user_id = ?',
                [$name, $type, $icon, $color, $id, $userId]
            );
        } catch (PDOException $e) {
            throw self::duplicateOr($e);
        }
        Response::ok(self::present(self::find($id, $userId)));
    }

    public static function destroy(int $userId, int $id): void
    {
        $category = self::find($id, $userId);
        if ((int) $category['is_default'] === 1) {
            throw new ApiError(403, 'category_default', 'หมวดเริ่มต้นลบไม่ได้');
        }
        $count = self::txCount($id, $userId);
        $moveTo = $_GET['move_to'] ?? null;
        if ($count > 0 && $moveTo === null) {
            throw new ApiError(409, 'category_in_use', "หมวดนี้มีรายการอยู่ {$count} รายการ กรุณาเลือกหมวดที่จะย้ายรายการไปก่อน", ['count' => $count]);
        }
        Db::transaction(static function () use ($id, $userId, $count, $moveTo, $category): void {
            if ($count > 0) {
                $target = self::find(Validator::positiveInt($moveTo, 'move_to'), $userId);
                if ((int) $target['id'] === $id || $target['type'] !== $category['type']) {
                    throw Validator::fail('move_to', 'ต้องย้ายไปหมวดอื่นที่เป็นชนิดเดียวกัน');
                }
                Db::run('UPDATE transactions SET category_id = ? WHERE category_id = ? AND user_id = ?', [(int) $target['id'], $id, $userId]);
            }
            Db::run('DELETE FROM categories WHERE id = ? AND user_id = ?', [$id, $userId]);
        });
        Response::ok(['moved' => $count]);
    }

    /** @return array<string,mixed> */
    public static function find(int $id, int $userId): array
    {
        $row = Db::one('SELECT * FROM categories WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new ApiError(404, 'category_not_found', 'ไม่พบหมวดหมู่นี้');
        }
        return $row;
    }

    private static function txCount(int $id, int $userId): int
    {
        return (int) Db::one('SELECT COUNT(*) AS n FROM transactions WHERE category_id = ? AND user_id = ?', [$id, $userId])['n'];
    }

    /** @param array<string,mixed> $body @return array{0:string,1:string,2:string} */
    private static function fields(array $body): array
    {
        $name = trim(Validator::str($body['name'] ?? null, 'name'));
        if ($name === '' || mb_strlen($name) > 60) {
            throw Validator::fail('name', 'ชื่อหมวดต้องมี 1-60 ตัวอักษร');
        }
        $icon = Validator::str($body['icon'] ?? null, 'icon', 'more-horizontal');
        if (!preg_match('/^[a-z0-9-]{1,40}$/', $icon)) {
            throw Validator::fail('icon', 'ไอคอนไม่ถูกต้อง');
        }
        $color = Validator::str($body['color'] ?? null, 'color', '#B8BFD0');
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            throw Validator::fail('color', 'สีไม่ถูกต้อง');
        }
        return [$name, $icon, strtoupper($color)];
    }

    private static function duplicateOr(PDOException $e): Throwable
    {
        if ($e->getCode() === '23000') {
            return new ApiError(409, 'category_duplicate', 'มีหมวดชื่อนี้อยู่แล้ว');
        }
        return $e;
    }
}
