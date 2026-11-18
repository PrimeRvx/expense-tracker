<?php
declare(strict_types=1);

final class SummaryController
{
    private const INCOME = 'รายรับ';
    private const EXPENSE = 'รายจ่าย';

    public static function day(int $userId): void
    {
        $date = Validator::date($_GET['date'] ?? date('Y-m-d'));
        $start = $date . ' 00:00:00';
        $next = date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00';
        [$income, $expense] = self::totals($userId, $start, $next);
        $rows = Db::all(
            'SELECT t.id, t.type, t.amount, t.occurred_at, t.note, t.source,
                    c.id AS cat_id, c.name AS cat_name, c.icon AS cat_icon, c.color AS cat_color,
                    (s.id IS NOT NULL) AS has_slip
             FROM transactions t
             JOIN categories c ON c.id = t.category_id
             LEFT JOIN slips s ON s.transaction_id = t.id
             WHERE t.user_id = ? AND t.occurred_at >= ? AND t.occurred_at < ?
             ORDER BY t.occurred_at DESC, t.id DESC',
            [$userId, $start, $next]
        );
        Response::ok(array_merge(['date' => $date], self::figures($income, $expense), [
            'transactions' => array_map([TransactionController::class, 'present'], $rows),
        ]));
    }

    public static function month(int $userId): void
    {
        $month = Validator::month($_GET['month'] ?? date('Y-m'));
        $start = $month . '-01 00:00:00';
        $next = date('Y-m-01 00:00:00', strtotime($start . ' +1 month'));
        $prevStart = date('Y-m-01 00:00:00', strtotime($start . ' -1 month'));

        [$income, $expense] = self::totals($userId, $start, $next);
        [$prevIncome, $prevExpense] = self::totals($userId, $prevStart, $start);
        // ยอดสะสมตั้งแต่เริ่มบันทึกจนถึงสิ้นเดือนที่เลือก ใช้เป็นยอดเงินคงเหลือรวม
        [$allIncome, $allExpense] = self::totals($userId, '1970-01-01 00:00:00', $next);

        $daily = [];
        $days = (int) date('t', strtotime($start));
        for ($d = 1; $d <= $days; $d++) {
            $daily[sprintf('%s-%02d', $month, $d)] = [self::INCOME => 0, self::EXPENSE => 0];
        }
        $rows = Db::all(
            "SELECT DATE_FORMAT(occurred_at, '%Y-%m-%d') AS d, type, SUM(amount) AS total
             FROM transactions WHERE user_id = ? AND occurred_at >= ? AND occurred_at < ? GROUP BY d, type",
            [$userId, $start, $next]
        );
        foreach ($rows as $row) {
            $daily[$row['d']][$row['type']] = Money::toSatang($row['total']);
        }
        $series = [];
        foreach ($daily as $date => $v) {
            $series[] = self::point('date', $date, $v[self::INCOME], $v[self::EXPENSE]);
        }

        Response::ok(array_merge(['month' => $month], self::figures($income, $expense), [
            'change' => [
                'income' => Money::change($income, $prevIncome),
                'expense' => Money::change($expense, $prevExpense),
                'balance' => Money::change($income - $expense, $prevIncome - $prevExpense),
            ],
            'previous' => array_merge(['month' => substr($prevStart, 0, 7)], self::figures($prevIncome, $prevExpense)),
            'overall' => self::figures($allIncome, $allExpense),
            'categories' => self::categories($userId, $start, $next),
            'daily' => $series,
        ]));
    }

    public static function year(int $userId): void
    {
        $year = Validator::year($_GET['year'] ?? date('Y'));
        $start = $year . '-01-01 00:00:00';
        $next = ($year + 1) . '-01-01 00:00:00';
        [$income, $expense] = self::totals($userId, $start, $next);

        $byMonth = self::monthly($userId, $start, $next);
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $key = sprintf('%d-%02d', $year, $m);
            $months[] = self::point('month', $key, $byMonth[$key][self::INCOME] ?? 0, $byMonth[$key][self::EXPENSE] ?? 0);
        }
        Response::ok(array_merge(['year' => $year], self::figures($income, $expense), [
            'months' => $months,
            'categories' => self::categories($userId, $start, $next),
        ]));
    }

    public static function trend(int $userId): void
    {
        $count = (int) ($_GET['months'] ?? 5);
        if (!in_array($count, [3, 5, 6, 12], true)) {
            throw Validator::fail('months', 'เลือกได้เฉพาะ 3, 5, 6 หรือ 12 เดือน');
        }
        $end = Validator::month($_GET['end'] ?? date('Y-m'));
        $next = date('Y-m-01 00:00:00', strtotime($end . '-01 +1 month'));
        $start = date('Y-m-01 00:00:00', strtotime($end . '-01 -' . ($count - 1) . ' month'));
        $byMonth = self::monthly($userId, $start, $next);
        $months = [];
        for ($i = 0; $i < $count; $i++) {
            $key = date('Y-m', strtotime($start . " +{$i} month"));
            $months[] = self::point('month', $key, $byMonth[$key][self::INCOME] ?? 0, $byMonth[$key][self::EXPENSE] ?? 0);
        }
        Response::ok(['months' => $months]);
    }

    /** @return array{0:int,1:int} รายรับและรายจ่ายหน่วยสตางค์ */
    private static function totals(int $userId, string $start, string $next): array
    {
        $rows = Db::all(
            'SELECT type, SUM(amount) AS total FROM transactions WHERE user_id = ? AND occurred_at >= ? AND occurred_at < ? GROUP BY type',
            [$userId, $start, $next]
        );
        $result = [self::INCOME => 0, self::EXPENSE => 0];
        foreach ($rows as $row) {
            $result[$row['type']] = Money::toSatang($row['total']);
        }
        return [$result[self::INCOME], $result[self::EXPENSE]];
    }

    /** @return array<string,array<string,int>> */
    private static function monthly(int $userId, string $start, string $next): array
    {
        $rows = Db::all(
            "SELECT DATE_FORMAT(occurred_at, '%Y-%m') AS m, type, SUM(amount) AS total
             FROM transactions WHERE user_id = ? AND occurred_at >= ? AND occurred_at < ? GROUP BY m, type",
            [$userId, $start, $next]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['m']][$row['type']] = Money::toSatang($row['total']);
        }
        return $result;
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private static function categories(int $userId, string $start, string $next): array
    {
        $rows = Db::all(
            'SELECT c.id, c.name, c.icon, c.color, t.type, SUM(t.amount) AS total
             FROM transactions t JOIN categories c ON c.id = t.category_id
             WHERE t.user_id = ? AND t.occurred_at >= ? AND t.occurred_at < ?
             GROUP BY c.id, c.name, c.icon, c.color, t.type
             ORDER BY total DESC',
            [$userId, $start, $next]
        );
        $sums = [self::INCOME => 0, self::EXPENSE => 0];
        foreach ($rows as $row) {
            $sums[$row['type']] += Money::toSatang($row['total']);
        }
        $result = ['income' => [], 'expense' => []];
        foreach ($rows as $row) {
            $satang = Money::toSatang($row['total']);
            $result[$row['type'] === self::INCOME ? 'income' : 'expense'][] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'icon' => $row['icon'],
                'color' => $row['color'],
                'amount' => Money::fromSatang($satang),
                'percent' => $sums[$row['type']] > 0 ? round($satang / $sums[$row['type']] * 100, 1) : 0.0,
            ];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private static function figures(int $income, int $expense): array
    {
        return [
            'income' => Money::fromSatang($income),
            'expense' => Money::fromSatang($expense),
            'balance' => Money::fromSatang($income - $expense),
            'savingsRate' => Money::savingsRate($income, $expense),
        ];
    }

    /** @return array<string,mixed> */
    private static function point(string $key, string $label, int $income, int $expense): array
    {
        return [
            $key => $label,
            'income' => Money::fromSatang($income),
            'expense' => Money::fromSatang($expense),
            'balance' => Money::fromSatang($income - $expense),
        ];
    }
}
