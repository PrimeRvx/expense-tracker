<?php
declare(strict_types=1);

/**
 * ส่งอีเมลข้อความล้วนผ่าน SMTP โดยไม่พึ่งไลบรารีภายนอก
 * MAIL_DRIVER: 'smtp' ส่งจริง, 'log' เขียนลงไฟล์ private/logs/mail.log (สำหรับพัฒนาในเครื่องเท่านั้น), ว่าง = ปิดระบบอีเมล
 */
final class Mailer
{
    public static function driver(): string
    {
        $driver = strtolower((string) Config::get('MAIL_DRIVER', ''));
        return in_array($driver, ['smtp', 'log'], true) ? $driver : '';
    }

    public static function enabled(): bool
    {
        return self::driver() !== '';
    }

    public static function send(string $to, string $subject, string $body): void
    {
        if (self::driver() === 'log') {
            self::writeLog($to, $subject, $body);
            return;
        }
        if (self::driver() !== 'smtp') {
            throw new RuntimeException('Mail is not configured');
        }
        $from = (string) Config::get('MAIL_FROM', Config::get('SMTP_USER', ''));
        $name = (string) Config::get('MAIL_FROM_NAME', 'Expense Tracker');
        self::smtp($from, $to, self::message($from, $name, $to, $subject, $body));
    }

    public static function message(string $from, string $name, string $to, string $subject, string $body): string
    {
        // หัวจดหมายภาษาไทยต้องเข้ารหัสและตัดบรรทัดตามมาตรฐาน (RFC 2047)
        $encode = static fn (string $s): string => mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n", 9);
        $domain = substr((string) strrchr($from, '@'), 1) ?: 'localhost';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $encode($name) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encode($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
    }

    private static function smtp(string $from, string $to, string $message): void
    {
        $host = (string) Config::get('SMTP_HOST', '');
        $port = (int) Config::get('SMTP_PORT', 465);
        $secure = strtolower((string) Config::get('SMTP_SECURE', 'ssl')); // ssl (465), tls (587 STARTTLS) หรือ none
        if ($host === '' || $from === '') {
            throw new RuntimeException('SMTP_HOST / MAIL_FROM is not set');
        }
        $socket = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15);
        if ($socket === false) {
            throw new RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($socket, 15);
        try {
            self::expect($socket, 220);
            $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
            self::command($socket, $ehlo, 250);
            if ($secure === 'tls') {
                self::command($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP STARTTLS failed');
                }
                self::command($socket, $ehlo, 250);
            }
            $user = (string) Config::get('SMTP_USER', '');
            if ($user !== '') {
                self::command($socket, 'AUTH LOGIN', 334);
                self::command($socket, base64_encode($user), 334);
                // Gmail แสดง App Password เป็นกลุ่มคั่นด้วยช่องว่าง ตัดออกให้เอง
                self::command($socket, base64_encode(str_replace(' ', '', (string) Config::get('SMTP_PASS', ''))), 235);
            }
            self::command($socket, 'MAIL FROM:<' . $from . '>', 250);
            self::command($socket, 'RCPT TO:<' . $to . '>', 250);
            self::command($socket, 'DATA', 354);
            // บรรทัดที่ขึ้นต้นด้วยจุดต้องเติมจุดอีกตัวตามมาตรฐาน SMTP
            self::command($socket, preg_replace('/^\./m', '..', $message) . "\r\n.", 250);
            fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private static function command($socket, string $line, int $expect): void
    {
        fwrite($socket, $line . "\r\n");
        self::expect($socket, $expect);
    }

    /** @param resource $socket */
    private static function expect($socket, int $code): void
    {
        $reply = '';
        while (($line = fgets($socket, 600)) !== false) {
            $reply = $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break; // บรรทัดสุดท้ายของคำตอบ (รูปแบบ "250 ..." ไม่ใช่ "250-...")
            }
        }
        if ((int) substr($reply, 0, 3) !== $code) {
            // ไม่ใส่คำสั่งที่ส่งไปใน log เพราะอาจมีรหัสผ่าน
            throw new RuntimeException('SMTP expected ' . $code . ', got: ' . trim($reply === '' ? 'no reply' : $reply));
        }
    }

    private static function writeLog(string $to, string $subject, string $body): void
    {
        $file = dirname(Config::path('LOG_FILE', 'logs/app.log')) . '/mail.log';
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0750, true);
        }
        file_put_contents($file, sprintf("[%s] To: %s | %s\n%s\n\n", date('c'), $to, $subject, $body), FILE_APPEND | LOCK_EX);
    }
}
