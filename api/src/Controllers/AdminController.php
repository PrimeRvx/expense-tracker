<?php
declare(strict_types=1);

/**
 * เครื่องมือผู้ดูแลระบบ: ช่วยเหลือผู้ใช้และแก้ไขข้อมูลแทน
 * ทุก endpoint เรียก guard() ก่อน และทุกการแก้ไขถูกบันทึกลง admin_audit
 */
final class AdminController
{
    private const TEMP_PASSWORD_CHARS = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    // ---------------------------------------------------------------- สิทธิ์

    /** ใช้ใน /auth/me: ฐานข้อมูลที่ยังไม่ได้อัปเดตถือว่าไม่ใช่ผู้ดูแล */
    public static function isAdmin(int $userId): bool
    {
        try {
            $row = Db::one('SELECT role FROM users WHERE id = ?', [$userId]);
        } catch (PDOException $e) {
            return false;
        }
        return $row !== null && $row['role'] === 'admin';
    }

    /** @return array{id:int,email:string} */
    private static function guard(int $adminId): array
    {
        try {
            $row = Db::one('SELECT id, email, role FROM users WHERE id = ?', [$adminId]);
        } catch (PDOException $e) {
            if ($e->getCode() === '42S22') {
                throw new ApiError(500, 'admin_not_installed', 'ฐานข้อมูลยังไม่รองรับระบบผู้ดูแล กรุณา import sql/migrate_admin.sql ก่อน');
            }
            throw $e;
        }
        if ($row === null || $row['role'] !== 'admin') {
            throw new ApiError(403, 'forbidden', 'เฉพาะผู้ดูแลระบบเท่านั้น');
        }
        return ['id' => (int) $row['id'], 'email' => (string) $row['email']];
    }

    /** @param array{id:int,email:string} $admin @param array<string,mixed> $detail */
    private static function audit(array $admin, ?int $targetUserId, string $action, array $detail = []): void
    {
        Db::run(
            'INSERT INTO admin_audit (admin_id, admin_email, target_user_id, action, detail, ip) VALUES (?, ?, ?, ?, ?, ?)',
            [$admin['id'], $admin['email'], $targetUserId, $action, json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ClientIp::binary()]
        );
    }

    /** เจ้าของระบบ = ผู้ดูแลที่มี id ต่ำสุด (บัญชีแรกที่สมัคร) */
    private static function isOwner(int $adminId): bool
    {
        $row = Db::one("SELECT MIN(id) AS id FROM users WHERE role = 'admin'");
        return (int) ($row['id'] ?? 0) === $adminId;
    }

    /**
     * กันผู้ดูแลยึดบัญชีกันเอง: แก้ไข รีเซ็ตรหัสผ่าน หรือลบบัญชีผู้ดูแลคนอื่นได้เฉพาะเจ้าของระบบ
     * @param array{id:int,email:string} $admin @param array<string,mixed> $target
     */
    private static function protectOtherAdmins(array $admin, array $target): void
    {
        if ((int) $target['id'] === $admin['id'] || $target['role'] !== 'admin' || self::isOwner($admin['id'])) {
            return;
        }
        throw new ApiError(403, 'admin_protected', 'แก้ไขบัญชีผู้ดูแลคนอื่นได้เฉพาะเจ้าของระบบ (ผู้ดูแลคนแรก)');
    }

    /** @return array<string,mixed> */
    private static function findUser(int $id): array
    {
        $row = Db::one('SELECT id, email, display_name, role, created_at FROM users WHERE id = ?', [$id]);
        if ($row === null) {
            throw new ApiError(404, 'user_not_found', 'ไม่พบผู้ใช้นี้');
        }
        return $row;
    }

    // ---------------------------------------------------------------- ภาพรวม

    public static function overview(int $adminId): void
    {
        self::guard($adminId);
        $counts = Db::one(
            "SELECT (SELECT COUNT(*) FROM users) AS users,
                    (SELECT COUNT(*) FROM users WHERE role = 'admin') AS admins,
                    (SELECT COUNT(*) FROM transactions) AS transactions,
                    (SELECT COUNT(*) FROM slips) AS slips,
                    (SELECT COUNT(DISTINCT email) FROM login_attempts WHERE attempted_at >= (NOW() - INTERVAL 15 MINUTE)) AS failing_logins"
        );
        Response::ok([
            'users' => (int) $counts['users'],
            'admins' => (int) $counts['admins'],
            'transactions' => (int) $counts['transactions'],
            'slips' => (int) $counts['slips'],
            'failingLogins' => (int) $counts['failing_logins'],
            'recentAudit' => self::auditRows(null, 10, 0),
        ]);
    }

    // ---------------------------------------------------------------- ผู้ใช้

    public static function users(int $adminId): void
    {
        self::guard($adminId);
        $where = '1 = 1';
        $params = [];
        $q = trim(Validator::query('q'));
        if ($q !== '') {
            $like = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], mb_substr($q, 0, 100)) . '%';
            $where = "(u.email LIKE ? ESCAPE '|' OR u.display_name LIKE ? ESCAPE '|')";
            $params = [$like, $like];
            if (ctype_digit($q)) {
                $where = "({$where} OR u.id = ?)";
                $params[] = (int) $q;
            }
        }
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $total = (int) Db::one("SELECT COUNT(*) AS n FROM users u WHERE {$where}", $params)['n'];
        $rows = Db::all(
            "SELECT u.id, u.email, u.display_name, u.role, u.created_at,
                    (SELECT COUNT(*) FROM transactions t WHERE t.user_id = u.id) AS tx_count,
                    (SELECT MAX(t.created_at) FROM transactions t WHERE t.user_id = u.id) AS last_activity,
                    (SELECT COUNT(*) FROM login_attempts a WHERE a.email = u.email AND a.attempted_at >= (NOW() - INTERVAL 15 MINUTE)) AS failures
               FROM users u WHERE {$where} ORDER BY u.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, ($page - 1) * $limit])
        );
        Response::ok([
            'items' => array_map(static fn (array $r): array => self::presentUser($r) + [
                'txCount' => (int) $r['tx_count'],
                'lastActivity' => $r['last_activity'],
                'loginFailures' => (int) $r['failures'],
            ], $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function presentUser(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'email' => $r['email'],
            'displayName' => $r['display_name'],
            'role' => $r['role'],
            'createdAt' => $r['created_at'],
        ];
    }

    public static function user(int $adminId, int $id): void
    {
        self::guard($adminId);
        $user = self::findUser($id);
        $stats = Db::one(
            "SELECT COUNT(*) AS tx_count,
                    COALESCE(SUM(CASE WHEN type = 'รายรับ' THEN amount END), 0) AS income,
                    COALESCE(SUM(CASE WHEN type = 'รายจ่าย' THEN amount END), 0) AS expense,
                    MIN(occurred_at) AS first_tx, MAX(occurred_at) AS last_tx, MAX(created_at) AS last_activity
               FROM transactions WHERE user_id = ?",
            [$id]
        );
        $categories = Db::all(
            'SELECT c.*, (SELECT COUNT(*) FROM transactions t WHERE t.category_id = c.id) AS tx_count
               FROM categories c WHERE c.user_id = ? ORDER BY c.type, c.sort_order, c.id',
            [$id]
        );
        $budgets = Db::all(
            'SELECT b.category_id, c.name, b.amount FROM budgets b JOIN categories c ON c.id = b.category_id WHERE b.user_id = ? ORDER BY c.sort_order',
            [$id]
        );
        // ล็อกเมื่ออีเมลนี้ผิดครบตามเกณฑ์จากเครื่องใดเครื่องหนึ่ง หรือจากทุกเครื่องรวมกัน
        $attempts = Db::one(
            'SELECT COALESCE(SUM(n), 0) AS total, COALESCE(MAX(n), 0) AS worst_ip FROM (
                SELECT COUNT(*) AS n FROM login_attempts WHERE email = ? AND attempted_at >= (NOW() - INTERVAL 15 MINUTE) GROUP BY ip
             ) x',
            [$user['email']]
        );
        $failures = (int) $attempts['total'];

        Response::ok([
            'user' => self::presentUser($user),
            'stats' => [
                'txCount' => (int) $stats['tx_count'],
                'income' => Money::fromSatang(Money::toSatang($stats['income'])),
                'expense' => Money::fromSatang(Money::toSatang($stats['expense'])),
                'firstTransaction' => $stats['first_tx'],
                'lastTransaction' => $stats['last_tx'],
                'lastActivity' => $stats['last_activity'],
                'slips' => (int) Db::one('SELECT COUNT(*) AS n FROM slips WHERE user_id = ?', [$id])['n'],
                'parserRules' => (int) Db::one('SELECT COUNT(*) AS n FROM parser_rules WHERE user_id = ?', [$id])['n'],
            ],
            'login' => [
                'failures15m' => $failures,
                'locked' => AuthController::isLocked((int) $attempts['worst_ip'], $failures, 0),
            ],
            'categories' => array_map([CategoryController::class, 'present'], $categories),
            'budgets' => array_map(static fn (array $b): array => [
                'categoryId' => (int) $b['category_id'],
                'name' => $b['name'],
                'amount' => Money::fromSatang(Money::toSatang($b['amount'])),
            ], $budgets),
            'issues' => self::findIssues($id),
        ]);
    }

    /** ตรวจหาข้อมูลที่ผิดปกติของผู้ใช้ @return array<string,mixed> */
    private static function findIssues(int $userId): array
    {
        $mismatch = Db::all(
            'SELECT t.id FROM transactions t JOIN categories c ON c.id = t.category_id
              WHERE t.user_id = ? AND (t.type <> c.type OR c.user_id <> t.user_id) ORDER BY t.id LIMIT 200',
            [$userId]
        );
        $missing = [];
        foreach (Db::all('SELECT transaction_id, stored_name FROM slips WHERE user_id = ?', [$userId]) as $s) {
            if (!is_file(Config::path('UPLOAD_DIR', 'uploads/slips') . '/' . basename((string) $s['stored_name']))) {
                $missing[] = (int) $s['transaction_id'];
            }
        }
        $typesPresent = array_column(Db::all('SELECT DISTINCT type FROM categories WHERE user_id = ?', [$userId]), 'type');
        return [
            'categoryMismatch' => array_map(static fn (array $r): int => (int) $r['id'], $mismatch),
            'missingSlipFiles' => $missing,
            'missingCategoryTypes' => array_values(array_diff(Validator::TYPES, $typesPresent)),
        ];
    }

    /** body: {"display_name"?, "email"?, "role"?} */
    public static function updateUser(int $adminId, int $id): void
    {
        $admin = self::guard($adminId);
        $before = self::findUser($id);
        self::protectOtherAdmins($admin, $before);
        $body = Validator::jsonBody();

        $name = array_key_exists('display_name', $body) ? trim(Validator::str($body['display_name'], 'display_name')) : (string) $before['display_name'];
        if (mb_strlen($name) > 100) {
            throw Validator::fail('display_name', 'ชื่อที่แสดงยาวได้ไม่เกิน 100 ตัวอักษร');
        }
        $email = (string) $before['email'];
        if (array_key_exists('email', $body)) {
            $email = strtolower(trim(Validator::str($body['email'], 'email')));
            if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw Validator::fail('email', 'รูปแบบอีเมลไม่ถูกต้อง');
            }
            if ($email !== $before['email'] && Db::one('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id]) !== null) {
                throw new ApiError(409, 'email_taken', 'อีเมลนี้ถูกใช้แล้ว', ['field' => 'email']);
            }
        }
        $role = Validator::str($body['role'] ?? null, 'role', (string) $before['role']);
        if (!in_array($role, ['user', 'admin'], true)) {
            throw Validator::fail('role', 'สิทธิ์ต้องเป็น user หรือ admin');
        }
        // ห้ามลดสิทธิ์ตัวเอง จึงเหลือผู้ดูแลอย่างน้อยหนึ่งคนเสมอ
        if ($id === $admin['id'] && $role !== 'admin') {
            throw new ApiError(409, 'self_demote', 'ลดสิทธิ์ตัวเองไม่ได้ ให้ผู้ดูแลคนอื่นทำแทน');
        }

        Db::run('UPDATE users SET display_name = ?, email = ?, role = ? WHERE id = ?', [$name, $email, $role, $id]);
        // เปลี่ยนอีเมลแล้ว session เดิมของผู้ใช้ทุกเครื่องหลุดเอง (Auth ผูก session กับอีเมล) ต้องเข้าสู่ระบบใหม่ด้วยอีเมลใหม่
        $after = self::findUser($id);
        $changes = [];
        foreach (['display_name', 'email', 'role'] as $f) {
            if ($before[$f] !== $after[$f]) {
                $changes[$f] = ['from' => $before[$f], 'to' => $after[$f]];
            }
        }
        if ($changes !== []) {
            self::audit($admin, $id, 'update_user', $changes);
        }
        Response::ok(self::presentUser($after));
    }

    /** ปลดล็อกบัญชีที่ใส่รหัสผ่านผิดเกินจำนวน */
    public static function unlock(int $adminId, int $id): void
    {
        $admin = self::guard($adminId);
        $user = self::findUser($id);
        $n = Db::run('DELETE FROM login_attempts WHERE email = ?', [$user['email']])->rowCount();
        self::audit($admin, $id, 'unlock_login', ['cleared' => $n]);
        Response::ok(['cleared' => $n]);
    }

    /** ตั้งรหัสผ่านชั่วคราว แสดงครั้งเดียว ผู้ใช้ทุกเครื่องหลุดจากระบบทันที */
    public static function resetPassword(int $adminId, int $id): void
    {
        $admin = self::guard($adminId);
        $user = self::findUser($id);
        if ($id === $admin['id']) {
            throw new ApiError(409, 'self_reset', 'เปลี่ยนรหัสผ่านของตัวเองที่หน้าตั้งค่าในแอป');
        }
        self::protectOtherAdmins($admin, $user);
        $chars = self::TEMP_PASSWORD_CHARS;
        $temp = '';
        for ($i = 0; $i < 12; $i++) {
            $temp .= $chars[random_int(0, strlen($chars) - 1)];
        }
        Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($temp, PASSWORD_DEFAULT), $id]);
        Db::run('DELETE FROM login_attempts WHERE email = ?', [$user['email']]);
        self::audit($admin, $id, 'reset_password'); // ไม่เก็บรหัสผ่านลง log
        Response::ok(['tempPassword' => $temp]);
    }

    /** เติมหมวดเริ่มต้นที่ผู้ใช้ลบหรือแก้ชื่อไป */
    public static function restoreCategories(int $adminId, int $id): void
    {
        $admin = self::guard($adminId);
        self::findUser($id);
        $added = Seed::restoreCategories($id);
        if ($added !== []) {
            self::audit($admin, $id, 'restore_categories', ['added' => $added]);
        }
        Response::ok(['added' => $added]);
    }

    /** ลบข้อมูลสลิปที่ไฟล์รูปหายไปแล้ว รายการรับจ่ายยังอยู่ครบ */
    public static function repairSlips(int $adminId, int $id): void
    {
        $admin = self::guard($adminId);
        self::findUser($id);
        $missing = self::findIssues($id)['missingSlipFiles'];
        foreach ($missing as $txId) {
            Db::run('DELETE FROM slips WHERE transaction_id = ? AND user_id = ?', [$txId, $id]);
        }
        if ($missing !== []) {
            self::audit($admin, $id, 'repair_slips', ['transactions' => $missing]);
        }
        Response::ok(['removed' => count($missing)]);
    }

    /** ลบบัญชีและข้อมูลทั้งหมด body: {"confirm_email": "..."} ต้องตรงกับอีเมลของบัญชี */
    public static function deleteUser(int $adminId, int $id): void
    {
        $admin = self::guard($adminId);
        $user = self::findUser($id);
        if ($id === $admin['id']) {
            throw new ApiError(409, 'self_delete', 'ลบบัญชีของตัวเองไม่ได้');
        }
        self::protectOtherAdmins($admin, $user);
        $confirm = strtolower(trim(Validator::str(Validator::jsonBody()['confirm_email'] ?? null, 'confirm_email')));
        if ($confirm !== $user['email']) {
            throw Validator::fail('confirm_email', 'พิมพ์อีเมลของบัญชีให้ตรงเพื่อยืนยันการลบ');
        }
        $files = array_column(Db::all('SELECT stored_name FROM slips WHERE user_id = ?', [$id]), 'stored_name');
        $tx = (int) Db::one('SELECT COUNT(*) AS n FROM transactions WHERE user_id = ?', [$id])['n'];
        Db::transaction(static function () use ($admin, $id, $user, $tx, $files): void {
            Db::run('DELETE FROM login_attempts WHERE email = ?', [$user['email']]);
            // ต้องลบรายการก่อน เพราะ transactions → categories เป็น RESTRICT ลบ users ตรงๆ จะติด
            Db::run('DELETE FROM transactions WHERE user_id = ?', [$id]);
            Db::run('DELETE FROM users WHERE id = ?', [$id]); // ตารางที่เหลือลบตามด้วย ON DELETE CASCADE
            self::audit($admin, $id, 'delete_user', ['email' => $user['email'], 'transactions' => $tx, 'slips' => count($files)]);
        });
        foreach ($files as $f) {
            SlipController::removeFile((string) $f);
        }
        Response::ok(['deleted' => $id]);
    }

    // ---------------------------------------------------------------- รายการรับจ่าย

    public static function transactions(int $adminId, int $id): void
    {
        self::guard($adminId);
        self::findUser($id);
        $where = ['t.user_id = ?'];
        $params = [$id];
        $q = trim(Validator::query('q'));
        if ($q !== '') {
            $like = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], mb_substr($q, 0, 100)) . '%';
            $where[] = "(t.note LIKE ? ESCAPE '|' OR c.name LIKE ? ESCAPE '|')";
            array_push($params, $like, $like);
        }
        if (($_GET['type'] ?? '') !== '') {
            $where[] = 't.type = ?';
            $params[] = Validator::type($_GET['type']);
        }
        if (($_GET['from'] ?? '') !== '') {
            $where[] = 't.occurred_at >= ?';
            $params[] = Validator::date($_GET['from'], 'from') . ' 00:00:00';
        }
        if (($_GET['to'] ?? '') !== '') {
            $where[] = 't.occurred_at < ?';
            $params[] = date('Y-m-d', strtotime(Validator::date($_GET['to'], 'to') . ' +1 day')) . ' 00:00:00';
        }
        if (($_GET['issues'] ?? '') === '1') {
            $where[] = 't.type <> c.type';
        }
        $whereSql = implode(' AND ', $where);
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 25)));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $total = (int) Db::one("SELECT COUNT(*) AS n FROM transactions t JOIN categories c ON c.id = t.category_id WHERE {$whereSql}", $params)['n'];
        $rows = Db::all(
            self::txSelect() . " WHERE {$whereSql} ORDER BY t.occurred_at DESC, t.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, ($page - 1) * $limit])
        );
        Response::ok(['items' => array_map([self::class, 'presentTx'], $rows), 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    private static function txSelect(): string
    {
        return 'SELECT t.id, t.user_id, t.type, t.amount, t.occurred_at, t.note, t.source, t.created_at, t.updated_at,
                       c.id AS cat_id, c.name AS cat_name, c.icon AS cat_icon, c.color AS cat_color, c.type AS cat_type,
                       (s.id IS NOT NULL) AS has_slip
                  FROM transactions t
                  JOIN categories c ON c.id = t.category_id
                  LEFT JOIN slips s ON s.transaction_id = t.id';
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function presentTx(array $r): array
    {
        return TransactionController::present($r) + [
            'userId' => (int) $r['user_id'],
            'createdAt' => $r['created_at'],
            'updatedAt' => $r['updated_at'],
            'categoryMismatch' => $r['type'] !== $r['cat_type'],
        ];
    }

    /** @return array<string,mixed> */
    private static function findTx(int $id): array
    {
        $row = Db::one(self::txSelect() . ' WHERE t.id = ?', [$id]);
        if ($row === null) {
            throw TransactionController::notFound();
        }
        return $row;
    }

    /** แก้รายการของผู้ใช้คนใดก็ได้ body: type, category_id, amount, occurred_at, note (ส่งเฉพาะที่จะแก้) */
    public static function updateTransaction(int $adminId, int $id): void
    {
        $admin = self::guard($adminId);
        $row = self::findTx($id);
        $owner = (int) $row['user_id'];
        $body = array_merge([
            'type' => $row['type'],
            'category_id' => (int) $row['cat_id'],
            'amount' => (string) $row['amount'],
            'occurred_at' => (string) $row['occurred_at'],
            'note' => (string) $row['note'],
        ], Validator::jsonBody());

        $type = Validator::type($body['type']);
        $amount = Validator::amount($body['amount']);
        $occurredAt = Validator::dateTime($body['occurred_at']);
        $note = Validator::note($body['note']);
        $categoryId = Validator::positiveInt($body['category_id'], 'category_id');
        $category = Db::one('SELECT type FROM categories WHERE id = ? AND user_id = ?', [$categoryId, $owner]);
        if ($category === null) {
            throw Validator::fail('category_id', 'ไม่พบหมวดหมู่นี้ในบัญชีของผู้ใช้');
        }
        if ($category['type'] !== $type) {
            throw Validator::fail('category_id', 'ชนิดรายการไม่ตรงกับชนิดของหมวดหมู่');
        }

        Db::run(
            'UPDATE transactions SET category_id = ?, type = ?, amount = ?, occurred_at = ?, note = ? WHERE id = ?',
            [$categoryId, $type, $amount, $occurredAt, $note, $id]
        );
        $after = self::findTx($id);
        $changes = [];
        foreach (['type' => 'type', 'category' => 'cat_name', 'amount' => 'amount', 'occurred_at' => 'occurred_at', 'note' => 'note'] as $label => $col) {
            if ((string) $row[$col] !== (string) $after[$col]) {
                $changes[$label] = ['from' => $row[$col], 'to' => $after[$col]];
            }
        }
        if ($changes !== []) {
            self::audit($admin, $owner, 'update_transaction', ['transaction_id' => $id] + $changes);
        }
        Response::ok(self::presentTx($after));
    }

    public static function deleteTransaction(int $adminId, int $id): void
    {
        $admin = self::guard($adminId);
        $row = self::findTx($id);
        $slip = Db::one('SELECT stored_name FROM slips WHERE transaction_id = ?', [$id]);
        // ลบและบันทึก log ใน transaction เดียว ถ้าเขียน log ไม่สำเร็จ รายการจะไม่ถูกลบ
        Db::transaction(static function () use ($admin, $row, $slip, $id): void {
            Db::run('DELETE FROM transactions WHERE id = ?', [$id]);
            // เก็บสำเนาไว้ใน log เผื่อต้องเพิ่มคืนเอง
            self::audit($admin, (int) $row['user_id'], 'delete_transaction', [
                'transaction_id' => $id,
                'type' => $row['type'],
                'category' => $row['cat_name'],
                'amount' => $row['amount'],
                'occurred_at' => $row['occurred_at'],
                'note' => $row['note'],
                'had_slip' => $slip !== null,
            ]);
        });
        if ($slip !== null) {
            SlipController::removeFile((string) $slip['stored_name']);
        }
        Response::ok(['deleted' => $id]);
    }

    /** ดูรูปสลิปของรายการใดก็ได้ (บันทึกว่าใครเปิดดู) */
    public static function slip(int $adminId, int $id): void
    {
        $admin = self::guard($adminId);
        $row = self::findTx($id);
        $slip = Db::one('SELECT stored_name, mime FROM slips WHERE transaction_id = ?', [$id]);
        $path = $slip === null ? '' : Config::path('UPLOAD_DIR', 'uploads/slips') . '/' . basename((string) $slip['stored_name']);
        if ($slip === null || !is_file($path)) {
            throw new ApiError(404, 'slip_not_found', 'ไม่พบรูปสลิปของรายการนี้');
        }
        self::audit($admin, (int) $row['user_id'], 'view_slip', ['transaction_id' => $id]);
        header('Content-Type: ' . $slip['mime']);
        header('Content-Length: ' . (int) filesize($path));
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    // ---------------------------------------------------------------- หมวดหมู่

    /** แก้ชื่อ ไอคอน สีของหมวด body: name?, icon?, color? */
    public static function updateCategory(int $adminId, int $id): void
    {
        $admin = self::guard($adminId);
        $before = Db::one('SELECT * FROM categories WHERE id = ?', [$id]);
        if ($before === null) {
            throw new ApiError(404, 'category_not_found', 'ไม่พบหมวดหมู่นี้');
        }
        $body = array_merge($before, Validator::jsonBody());
        $name = trim(Validator::str($body['name'], 'name'));
        if ($name === '' || mb_strlen($name) > 60) {
            throw Validator::fail('name', 'ชื่อหมวดต้องมี 1-60 ตัวอักษร');
        }
        $icon = Validator::str($body['icon'], 'icon');
        if (!preg_match('/^[a-z0-9-]{1,40}$/', $icon)) {
            throw Validator::fail('icon', 'ไอคอนไม่ถูกต้อง');
        }
        $color = Validator::str($body['color'], 'color');
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            throw Validator::fail('color', 'สีไม่ถูกต้อง');
        }
        try {
            Db::run('UPDATE categories SET name = ?, icon = ?, color = ? WHERE id = ?', [$name, $icon, strtoupper($color), $id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ApiError(409, 'category_duplicate', 'ผู้ใช้นี้มีหมวดชื่อนี้อยู่แล้ว');
            }
            throw $e;
        }
        $after = Db::one('SELECT * FROM categories WHERE id = ?', [$id]);
        $changes = [];
        foreach (['name', 'icon', 'color'] as $f) {
            if ($before[$f] !== $after[$f]) {
                $changes[$f] = ['from' => $before[$f], 'to' => $after[$f]];
            }
        }
        if ($changes !== []) {
            self::audit($admin, (int) $before['user_id'], 'update_category', ['category_id' => $id] + $changes);
        }
        Response::ok(CategoryController::present($after));
    }

    // ---------------------------------------------------------------- ประวัติ

    public static function auditLog(int $adminId): void
    {
        self::guard($adminId);
        $userId = ($_GET['user_id'] ?? '') !== '' ? Validator::positiveInt($_GET['user_id'], 'user_id') : null;
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 30)));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        Response::ok(['items' => self::auditRows($userId, $limit, ($page - 1) * $limit), 'page' => $page, 'limit' => $limit]);
    }

    /** @return list<array<string,mixed>> */
    private static function auditRows(?int $userId, int $limit, int $offset): array
    {
        $where = $userId === null ? '' : 'WHERE a.target_user_id = ?';
        $params = $userId === null ? [] : [$userId];
        $rows = Db::all(
            "SELECT a.id, a.admin_email, a.target_user_id, u.email AS target_email, a.action, a.detail, a.created_at
               FROM admin_audit a LEFT JOIN users u ON u.id = a.target_user_id
               {$where} ORDER BY a.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'adminEmail' => $r['admin_email'],
            'targetUserId' => $r['target_user_id'] === null ? null : (int) $r['target_user_id'],
            'targetEmail' => $r['target_email'],
            'action' => $r['action'],
            'detail' => json_decode((string) $r['detail'], true),
            'createdAt' => $r['created_at'],
        ], $rows);
    }
}
