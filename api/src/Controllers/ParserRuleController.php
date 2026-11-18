<?php
declare(strict_types=1);

final class ParserRuleController
{
    public static function index(int $userId): void
    {
        $rows = Db::all(
            'SELECT r.id, r.keyword, r.category_id, c.name AS category_name, c.type AS category_type
             FROM parser_rules r JOIN categories c ON c.id = r.category_id
             WHERE r.user_id = ? ORDER BY r.keyword',
            [$userId]
        );
        Response::ok(array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'keyword' => $r['keyword'],
                'categoryId' => (int) $r['category_id'],
                'categoryName' => $r['category_name'],
                'categoryType' => $r['category_type'],
            ];
        }, $rows));
    }

    public static function store(int $userId): void
    {
        $body = Validator::jsonBody();
        $keyword = mb_strtolower(trim((string) ($body['keyword'] ?? '')));
        if ($keyword === '' || mb_strlen($keyword) > 60) {
            throw Validator::fail('keyword', 'คำสำคัญต้องมี 1-60 ตัวอักษร');
        }
        $categoryId = Validator::positiveInt($body['category_id'] ?? null, 'category_id');
        CategoryController::find($categoryId, $userId);
        Db::run(
            'INSERT INTO parser_rules (user_id, keyword, category_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE category_id = VALUES(category_id)',
            [$userId, $keyword, $categoryId]
        );
        $row = Db::one('SELECT id FROM parser_rules WHERE user_id = ? AND keyword = ?', [$userId, $keyword]);
        Response::ok(['id' => (int) $row['id'], 'keyword' => $keyword, 'categoryId' => $categoryId], 201);
    }

    public static function destroy(int $userId, int $id): void
    {
        $result = Db::run('DELETE FROM parser_rules WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($result->rowCount() === 0) {
            throw new ApiError(404, 'rule_not_found', 'ไม่พบคำสำคัญนี้');
        }
        Response::ok(['deleted' => $id]);
    }
}
