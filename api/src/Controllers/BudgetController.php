<?php
declare(strict_types=1);

/** งบประมาณรายเดือนต่อหมวดรายจ่าย ใช้ค่าเดียวกันทุกเดือน */
final class BudgetController
{
    /** ติดตั้งตารางให้เองถ้ายังไม่มี (ผู้ที่อัปเดตจากเวอร์ชันก่อนจะไม่ต้อง import SQL เพิ่ม) */
    private static function ensureTable(): void
    {
        Db::run(
            'CREATE TABLE IF NOT EXISTS budgets (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               user_id INT UNSIGNED NOT NULL,
               category_id INT UNSIGNED NOT NULL,
               amount DECIMAL(12,2) NOT NULL,
               updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               UNIQUE KEY uq_budget (user_id, category_id),
               FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
               FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function index(int $userId): void
    {
        self::ensureTable();
        $month = Validator::month($_GET['month'] ?? date('Y-m'));
        $start = $month . '-01 00:00:00';
        $next = date('Y-m-01 00:00:00', strtotime($start . ' +1 month'));
        Response::ok(self::listFor($userId, $start, $next));
    }

    /** body: {"items":[{"category_id":5,"amount":"10000"}, ...]} จำนวน 0 หรือว่าง = ยกเลิกงบของหมวดนั้น */
    public static function save(int $userId): void
    {
        self::ensureTable();
        $body = Validator::jsonBody();
        $items = $body['items'] ?? null;
        if (!is_array($items) || count($items) > 200) {
            throw Validator::fail('items', 'ข้อมูลงบประมาณไม่ถูกต้อง');
        }
        $categories = [];
        foreach (Db::all("SELECT id FROM categories WHERE user_id = ? AND type = 'รายจ่าย'", [$userId]) as $c) {
            $categories[(int) $c['id']] = true;
        }
        Db::transaction(static function () use ($items, $userId, $categories): void {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw Validator::fail('items', 'ข้อมูลงบประมาณไม่ถูกต้อง');
                }
                $categoryId = Validator::positiveInt($item['category_id'] ?? null, 'category_id');
                if (!isset($categories[$categoryId])) {
                    throw Validator::fail('category_id', 'ตั้งงบได้เฉพาะหมวดรายจ่ายของคุณ');
                }
                $raw = $item['amount'] ?? '';
                if ($raw === '' || $raw === null || $raw === 0 || $raw === '0' || $raw === '0.00') {
                    Db::run('DELETE FROM budgets WHERE user_id = ? AND category_id = ?', [$userId, $categoryId]);
                    continue;
                }
                $amount = Validator::amount($raw);
                Db::run(
                    'INSERT INTO budgets (user_id, category_id, amount) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)',
                    [$userId, $categoryId, $amount]
                );
            }
        });
        $month = date('Y-m');
        $start = $month . '-01 00:00:00';
        Response::ok(self::listFor($userId, $start, date('Y-m-01 00:00:00', strtotime($start . ' +1 month'))));
    }

    /** @return array<int,array<string,mixed>> */
    private static function listFor(int $userId, string $start, string $next): array
    {
        $rows = Db::all(
            "SELECT c.id, c.name, c.icon, c.color, b.amount,
                    (SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
                      WHERE t.user_id = b.user_id AND t.category_id = c.id AND t.occurred_at >= ? AND t.occurred_at < ?) AS spent
             FROM budgets b JOIN categories c ON c.id = b.category_id AND c.user_id = b.user_id
             WHERE b.user_id = ? AND c.type = 'รายจ่าย'
             ORDER BY c.sort_order, c.id",
            [$start, $next, $userId]
        );
        return array_map(static function (array $r): array {
            return [
                'categoryId' => (int) $r['id'],
                'name' => $r['name'],
                'icon' => $r['icon'],
                'color' => $r['color'],
                'amount' => Money::fromSatang(Money::toSatang($r['amount'])),
                'spent' => Money::fromSatang(Money::toSatang($r['spent'])),
            ];
        }, $rows);
    }
}
