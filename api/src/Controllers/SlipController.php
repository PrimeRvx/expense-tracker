<?php
declare(strict_types=1);

final class SlipController
{
    private const MAX_BYTES = 10485760; // 10 MB
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    public static function store(int $userId, int $id): void
    {
        self::requireTransaction($id, $userId);

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if (empty($_FILES) && $contentLength > 0) {
            throw self::tooLarge();
        }
        $file = $_FILES['slip'] ?? null;
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null)) {
            throw Validator::fail('slip', 'กรุณาเลือกไฟล์รูปสลิป');
        }
        $error = (int) $file['error'];
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw self::tooLarge();
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw Validator::fail('slip', 'อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่');
        }
        $size = (int) filesize($file['tmp_name']);
        if ($size > self::MAX_BYTES) {
            throw self::tooLarge();
        }
        if ($size === 0) {
            throw Validator::fail('slip', 'ไฟล์ว่างเปล่า');
        }
        $mime = self::detectMime($file['tmp_name']);
        if ($mime === null) {
            throw Validator::fail('slip', 'รองรับเฉพาะรูปภาพ JPG, PNG, WEBP, HEIC และ HEIF');
        }

        $dir = Config::path('UPLOAD_DIR', 'uploads/slips');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $stored = bin2hex(random_bytes(16)) . '.' . self::ALLOWED[$mime];
        $target = $dir . '/' . $stored;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new RuntimeException('Cannot store uploaded slip');
        }
        chmod($target, 0640);

        $old = Db::one('SELECT stored_name FROM slips WHERE transaction_id = ? AND user_id = ?', [$id, $userId]);
        try {
            Db::run(
                'INSERT INTO slips (user_id, transaction_id, stored_name, mime, size_bytes) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE stored_name = VALUES(stored_name), mime = VALUES(mime), size_bytes = VALUES(size_bytes), created_at = NOW()',
                [$userId, $id, $stored, $mime, $size]
            );
        } catch (Throwable $e) {
            @unlink($target);
            throw $e;
        }
        if ($old !== null) {
            self::removeFile((string) $old['stored_name']);
        }
        Response::ok(['transactionId' => $id, 'mime' => $mime, 'size' => $size], 201);
    }

    public static function show(int $userId, int $id): void
    {
        $slip = Db::one('SELECT stored_name, mime, size_bytes FROM slips WHERE transaction_id = ? AND user_id = ?', [$id, $userId]);
        if ($slip === null) {
            throw new ApiError(404, 'slip_not_found', 'ไม่พบรูปสลิปของรายการนี้');
        }
        $path = Config::path('UPLOAD_DIR', 'uploads/slips') . '/' . basename((string) $slip['stored_name']);
        if (!is_file($path)) {
            throw new ApiError(404, 'slip_not_found', 'ไม่พบไฟล์รูปสลิป');
        }
        header('Content-Type: ' . $slip['mime']);
        header('Content-Length: ' . (int) filesize($path));
        header('Content-Disposition: inline; filename="slip-' . $id . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    }

    public static function destroy(int $userId, int $id): void
    {
        self::requireTransaction($id, $userId);
        $slip = Db::one('SELECT stored_name FROM slips WHERE transaction_id = ? AND user_id = ?', [$id, $userId]);
        if ($slip === null) {
            throw new ApiError(404, 'slip_not_found', 'รายการนี้ไม่มีรูปสลิป');
        }
        Db::run('DELETE FROM slips WHERE transaction_id = ? AND user_id = ?', [$id, $userId]);
        self::removeFile((string) $slip['stored_name']);
        Response::ok(['deleted' => $id]);
    }

    public static function removeFile(string $storedName): void
    {
        $path = Config::path('UPLOAD_DIR', 'uploads/slips') . '/' . basename($storedName);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function requireTransaction(int $id, int $userId): void
    {
        if (Db::one('SELECT id FROM transactions WHERE id = ? AND user_id = ?', [$id, $userId]) === null) {
            throw TransactionController::notFound();
        }
    }

    /** ตรวจชนิดไฟล์จากเนื้อไฟล์จริง ไม่เชื่อชื่อไฟล์หรือ Content-Type ที่ client ส่งมา */
    private static function detectMime(string $path): ?string
    {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (isset(self::ALLOWED[$mime])) {
            return $mime;
        }
        // libmagic รุ่นเก่าบาง hosting ยังไม่รู้จัก HEIC จึงตรวจ brand ในหัวไฟล์ ISO-BMFF ต่อ
        $head = (string) file_get_contents($path, false, null, 0, 12);
        if (strlen($head) === 12 && substr($head, 4, 4) === 'ftyp') {
            $brand = substr($head, 8, 4);
            if (in_array($brand, ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis'], true)) {
                return 'image/heic';
            }
            if (in_array($brand, ['mif1', 'msf1'], true)) {
                return 'image/heif';
            }
        }
        return null;
    }

    private static function tooLarge(): ApiError
    {
        return new ApiError(422, 'file_too_large', 'ไฟล์ใหญ่เกินไป รองรับไม่เกิน 10 MB', ['field' => 'slip']);
    }
}
