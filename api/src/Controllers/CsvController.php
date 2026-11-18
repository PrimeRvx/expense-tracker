<?php
declare(strict_types=1);

final class CsvController
{
    private const MAX_ROWS = 5000;
    private const HEADER = ['วันที่', 'จำนวนเงิน', 'รายรับ/รายจ่าย', 'กลุ่ม', 'หมายเหตุ'];

    public static function export(int $userId): void
    {
        $where = 't.user_id = ?';
        $params = [$userId];
        $suffix = 'all';
        if (($_GET['from'] ?? '') !== '') {
            $from = Validator::date($_GET['from'], 'from');
            $where .= ' AND t.occurred_at >= ?';
            $params[] = $from . ' 00:00:00';
            $suffix = $from;
        }
        if (($_GET['to'] ?? '') !== '') {
            $to = Validator::date($_GET['to'], 'to');
            $where .= ' AND t.occurred_at < ?';
            $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
            $suffix .= '_' . $to;
        }
        $stmt = Db::run(
            "SELECT t.occurred_at, t.amount, t.type, c.name, t.note
             FROM transactions t JOIN categories c ON c.id = t.category_id
             WHERE {$where} ORDER BY t.occurred_at, t.id",
            $params
        );
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="expense-tracker-' . $suffix . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::HEADER, ',', '"', '');
        while ($row = $stmt->fetch()) {
            fputcsv($out, [
                substr((string) $row['occurred_at'], 0, 16),
                Money::fromSatang(Money::toSatang($row['amount'])),
                $row['type'],
                self::protectFormula((string) $row['name']),
                self::protectFormula((string) $row['note']),
            ], ',', '"', '');
        }
        fclose($out);
        exit;
    }

    public static function import(int $userId): void
    {
        if (empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new ApiError(422, 'file_too_large', 'ไฟล์ใหญ่เกินไป');
        }
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || (int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw Validator::fail('file', 'กรุณาเลือกไฟล์ CSV');
        }
        $content = (string) file_get_contents($file['tmp_name']);
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            // ไฟล์จาก Excel ภาษาไทยรุ่นเก่ามักเป็น Windows-874
            $converted = @iconv('CP874', 'UTF-8//IGNORE', $content);
            if ($converted === false) {
                throw Validator::fail('file', 'อ่านภาษาในไฟล์ไม่ได้ กรุณาบันทึกไฟล์เป็น CSV UTF-8');
            }
            $content = $converted;
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = fgetcsv($stream, 0, ',', '"', '');
        if (!is_array($header) || array_map('trim', array_slice($header, 0, 5)) !== self::HEADER) {
            throw Validator::fail('file', 'หัวตารางต้องเป็น: ' . implode(', ', self::HEADER));
        }

        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue; // แถวว่าง
            }
            $rows[] = $row;
            if (count($rows) > self::MAX_ROWS) {
                throw new ApiError(422, 'too_many_rows', 'นำเข้าได้ไม่เกิน 5,000 แถวต่อครั้ง กรุณาแบ่งไฟล์');
            }
        }
        fclose($stream);

        $categories = [];
        foreach (Db::all('SELECT id, name, type FROM categories WHERE user_id = ?', [$userId]) as $cat) {
            $categories[$cat['type'] . '|' . $cat['name']] = (int) $cat['id'];
        }

        $imported = 0;
        $errors = [];
        $skipped = 0;
        $duplicates = 0;
        $created = [];
        $existing = []; // จำนวนรายการหน้าตาเดียวกันที่มีอยู่ก่อนนำเข้า
        $seen = [];     // จำนวนที่พบในไฟล์นี้แล้ว
        Db::transaction(static function () use ($rows, $userId, &$categories, &$imported, &$errors, &$skipped, &$duplicates, &$created, &$existing, &$seen): void {
            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;
                try {
                    if (count($row) < 4) {
                        throw Validator::fail('row', 'จำนวนคอลัมน์ไม่ครบ');
                    }
                    $occurredAt = self::parseDate(trim((string) $row[0]));
                    $amount = Validator::amount(str_replace([',', ' '], '', (string) $row[1]));
                    $type = Validator::type(trim((string) $row[2]));
                    $name = self::unprotectFormula(trim((string) $row[3]));
                    $note = Validator::note(self::unprotectFormula((string) ($row[4] ?? '')));
                    if ($name === '' || mb_strlen($name) > 60) {
                        throw Validator::fail('category', 'ชื่อกลุ่มต้องมี 1-60 ตัวอักษร');
                    }
                    $key = $type . '|' . $name;
                    $isNewCategory = false;
                    if (!isset($categories[$key])) {
                        $next = Db::one('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM categories WHERE user_id = ? AND type = ?', [$userId, $type]);
                        Db::run('INSERT INTO categories (user_id, name, type, sort_order) VALUES (?, ?, ?, ?)', [$userId, $name, $type, (int) $next['n']]);
                        $categories[$key] = Db::lastId();
                        $created[] = $name;
                        $isNewCategory = true;
                    }
                    // นำเข้าไฟล์เดิมซ้ำต้องไม่ทำให้ยอดเบิ้ล: รายการที่ตรงกันทุกช่องและมีอยู่แล้วจะถูกข้าม
                    // เทียบเวลาระดับนาที เพราะไฟล์ที่ส่งออกจากระบบนี้ไม่มีวินาที
                    $minute = substr($occurredAt, 0, 16);
                    $rowKey = $categories[$key] . '|' . $amount . '|' . $minute . '|' . $note;
                    if (!isset($existing[$rowKey])) {
                        $existing[$rowKey] = !empty($isNewCategory) ? 0 : (int) Db::one(
                            'SELECT COUNT(*) AS n FROM transactions WHERE user_id = ? AND category_id = ? AND amount = ? AND occurred_at >= ? AND occurred_at <= ? AND note = ?',
                            [$userId, $categories[$key], $amount, $minute . ':00', $minute . ':59', $note]
                        )['n'];
                    }
                    if (self::isDuplicate($existing, $seen, $rowKey)) {
                        $duplicates++;
                        continue;
                    }
                    Db::run(
                        "INSERT INTO transactions (user_id, category_id, type, amount, occurred_at, note, source) VALUES (?, ?, ?, ?, ?, ?, 'import')",
                        [$userId, $categories[$key], $type, $amount, $occurredAt, $note]
                    );
                    $imported++;
                } catch (ApiError $e) {
                    $skipped++;
                    if (count($errors) < 50) {
                        $errors[] = ['row' => $rowNumber, 'reason' => $e->getMessage()];
                    }
                }
            }
        });
        Response::ok(['imported' => $imported, 'skipped' => $skipped, 'duplicates' => $duplicates, 'errors' => $errors, 'createdCategories' => $created]);
    }

    /**
     * แถวที่ n ของหน้าตาเดียวกันในไฟล์ ถือว่าซ้ำเมื่อฐานข้อมูลมีอยู่แล้วอย่างน้อย n รายการ
     * (รายการเหมือนกันสองแถวในไฟล์เดียวจึงยังนำเข้าได้ครบในครั้งแรก)
     * @param array<string,int> $existing @param array<string,int> $seen
     */
    public static function isDuplicate(array $existing, array &$seen, string $rowKey): bool
    {
        $seen[$rowKey] = ($seen[$rowKey] ?? 0) + 1;
        return $seen[$rowKey] <= ($existing[$rowKey] ?? 0);
    }

    /** รับ YYYY-MM-DD[ HH:MM[:SS]] หรือ D/M/YYYY[ HH:MM] โดยแปลงปี พ.ศ. เป็น ค.ศ. ให้ */
    private static function parseDate(string $value): string
    {
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:[ T](\d{1,2}):(\d{2}))?$#', $value, $m)) {
            $value = sprintf('%04d-%02d-%02d %02d:%02d', (int) $m[3], (int) $m[2], (int) $m[1], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0));
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= ' 00:00';
        }
        if (preg_match('/^(\d{4})(-.*)$/', $value, $m) && (int) $m[1] >= 2400) {
            $value = ((int) $m[1] - 543) . $m[2];
        }
        return Validator::dateTime($value);
    }

    /** กันสูตรทำงานเมื่อเปิดไฟล์ใน Excel โดยเติม ' หน้าค่าที่ขึ้นต้นด้วยอักขระสูตร */
    private static function protectFormula(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    private static function unprotectFormula(string $value): string
    {
        return preg_match("/^'[=+\\-@\\t\\r]/", $value) ? substr($value, 1) : $value;
    }
}
