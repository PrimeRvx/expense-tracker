<?php
declare(strict_types=1);

/** ข้อผิดพลาดที่ตั้งใจแจ้งผู้ใช้ ข้อความต้องเป็นภาษาไทยและไม่เปิดเผยรายละเอียดระบบ */
final class ApiError extends RuntimeException
{
    public int $status;
    public string $errorCode;
    /** @var array<string,mixed> */
    public array $extra;

    /** @param array<string,mixed> $extra */
    public function __construct(int $status, string $errorCode, string $message, array $extra = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->extra = $extra;
    }
}
