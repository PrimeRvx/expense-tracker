<?php
declare(strict_types=1);

final class TransactionController
{
    private const SELECT = 'SELECT t.id, t.type, t.amount, t.occurred_at, t.note, t.source,
              c.id AS cat_id, c.name AS cat_name, c.icon AS cat_icon, c.color AS cat_color,
              (s.id IS NOT NULL) AS has_slip
         FROM transactions t
         JOIN categories c ON c.id = t.category_id
         LEFT JOIN slips s ON s.transaction_id = t.id';

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'type' => $r['type'],
            'amount' => Money::fromSatang(Money::toSatang($r['amount'])),
            'occurredAt' => $r['occurred_at'],
            'note' => $r['note'],
            'source' => $r['source'],
            'hasSlip' => (bool) $r['has_slip'],
            'category' => [
                'id' => (int) $r['cat_id'],
                'name' => $r['cat_name'],
                'icon' => $r['cat_icon'],
                'color' => $r['cat_color'],
            ],
        ];
    }

    public static function index(int $userId): void
    {
        $where = ['t.user_id = ?'];
        $params = [$userId];

        $q = trim(Validator::query('q'));
        if ($q !== '') {
            $like = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], mb_substr($q, 0, 100)) . '%';
            $where[] = "(t.note LIKE ? ESCAPE '|' OR c.name LIKE ? ESCAPE '|')";
            $params[] = $like;
            $params[] = $like;
        }
        if (($_GET['type'] ?? '') !== '') {
            $where[] = 't.type = ?';
            $params[] = Validator::type($_GET['type']);
        }
        if (($_GET['category_id'] ?? '') !== '') {
            $where[] = 't.category_id = ?';
            $params[] = Validator::positiveInt($_GET['category_id'], 'category_id');
        }
        if (($_GET['from'] ?? '') !== '') {
            $where[] = 't.occurred_at >= ?';
            $params[] = Validator::date($_GET['from'], 'from') . ' 00:00:00';
        }
        if (($_GET['to'] ?? '') !== '') {
            $to = Validator::date($_GET['to'], 'to');
            $where[] = 't.occurred_at < ?';
            $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
        }
        $whereSql = implode(' AND ', $where);

        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $from = 'FROM transactions t JOIN categories c ON c.id = t.category_id WHERE ' . $whereSql;
        $total = (int) Db::one("SELECT COUNT(*) AS n {$from}", $params)['n'];

        // ยอดรวมรายเดือนของผลการกรองทั้งหมด (ไม่ใช่แค่หน้าปัจจุบัน) สำหรับหัวกลุ่มเดือน
        $monthRows = Db::all(
            "SELECT DATE_FORMAT(t.occurred_at, '%Y-%m') AS m, t.type, SUM(t.amount) AS total {$from} GROUP BY m, t.type ORDER BY m DESC",
            $params
        );
        $monthTotals = [];
        foreach ($monthRows as $row) {
            $key = $row['m'];
            $monthTotals[$key] = $monthTotals[$key] ?? ['income' => '0.00', 'expense' => '0.00'];
            $monthTotals[$key][$row['type'] === 'รายรับ' ? 'income' : 'expense'] = Money::fromSatang(Money::toSatang($row['total']));
        }

        $rows = Db::all(
            self::SELECT . " WHERE {$whereSql} ORDER BY t.occurred_at DESC, t.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, ($page - 1) * $limit])
        );
        Response::ok([
            'items' => array_map([self::class, 'present'], $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'monthTotals' => (object) $monthTotals,
        ]);
    }

    public static function store(int $userId): void
    {
        $body = Validator::jsonBody();
        $source = Validator::str($body['source'] ?? null, 'source', 'manual');
        if (!in_array($source, ['text', 'slip', 'manual', 'import'], true)) {
            $source = 'manual';
        }
        [$type, $categoryId, $amount, $occurredAt, $note] = self::fields($body, $userId);
        Db::run(
            'INSERT INTO transactions (user_id, category_id, type, amount, occurred_at, note, source) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $categoryId, $type, $amount, $occurredAt, $note, $source]
        );
        Response::ok(self::find(Db::lastId(), $userId), 201);
    }

    public static function update(int $userId, int $id): void
    {
        $current = Db::one('SELECT type, category_id, amount, occurred_at, note FROM transactions WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($current === null) {
            throw self::notFound();
        }
        [$type, $categoryId, $amount, $occurredAt, $note] = self::fields(array_merge($current, Validator::jsonBody()), $userId);
        Db::run(
            'UPDATE transactions SET category_id = ?, type = ?, amount = ?, occurred_at = ?, note = ? WHERE id = ? AND user_id = ?',
            [$categoryId, $type, $amount, $occurredAt, $note, $id, $userId]
        );
        Response::ok(self::find($id, $userId));
    }

    public static function destroy(int $userId, int $id): void
    {
        if (Db::one('SELECT id FROM transactions WHERE id = ? AND user_id = ?', [$id, $userId]) === null) {
            throw self::notFound();
        }
        $slip = Db::one('SELECT stored_name FROM slips WHERE transaction_id = ? AND user_id = ?', [$id, $userId]);
        Db::run('DELETE FROM transactions WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($slip !== null) {
            SlipController::removeFile((string) $slip['stored_name']);
        }
        Response::ok(['deleted' => $id]);
    }

    /** @return array<string,mixed> */
    private static function find(int $id, int $userId): array
    {
        $row = Db::one(self::SELECT . ' WHERE t.id = ? AND t.user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw self::notFound();
        }
        return self::present($row);
    }

    public static function notFound(): ApiError
    {
        return new ApiError(404, 'transaction_not_found', 'ไม่พบรายการนี้');
    }

    /** @param array<string,mixed> $body @return array{0:string,1:int,2:string,3:string,4:string} */
    private static function fields(array $body, int $userId): array
    {
        $type = Validator::type($body['type'] ?? null);
        $amount = Validator::amount($body['amount'] ?? null);
        $occurredAt = Validator::dateTime($body['occurred_at'] ?? null);
        $note = Validator::note($body['note'] ?? '');
        $categoryId = Validator::positiveInt($body['category_id'] ?? null, 'category_id');
        $category = Db::one('SELECT type FROM categories WHERE id = ? AND user_id = ?', [$categoryId, $userId]);
        if ($category === null) {
            throw Validator::fail('category_id', 'ไม่พบหมวดหมู่นี้');
        }
        if ($category['type'] !== $type) {
            throw Validator::fail('category_id', 'ชนิดรายการไม่ตรงกับชนิดของหมวดหมู่');
        }
        return [$type, $categoryId, $amount, $occurredAt, $note];
    }
}
