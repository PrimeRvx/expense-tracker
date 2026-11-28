<?php
declare(strict_types=1);

/**
 * ลบข้อมูลแฝงในรูปสลิป (EXIF/XMP เช่นพิกัด GPS วันเวลา รุ่นโทรศัพท์) ก่อนเก็บลงเซิร์ฟเวอร์
 * ทำแบบไม่บีบอัดภาพใหม่ ภาพจึงคมเท่าเดิม ยกเว้นรูปถ่ายที่ต้องหมุนตาม EXIF จะหมุนแล้วบันทึกใหม่ (ต้องมี GD)
 * ถ้าอ่านไฟล์ไม่ออกจะปล่อยไฟล์เดิมไว้ ไม่ทำให้การอัปโหลดล้ม
 */
final class ImageMeta
{
    /** คืน true เมื่อเขียนไฟล์ใหม่ที่ไม่มีข้อมูลแฝงแล้ว */
    public static function strip(string $path, string $mime): bool
    {
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            return false;
        }
        try {
            if ($mime === 'image/jpeg') {
                $out = self::rotatedJpeg($data) ?? self::jpeg($data);
            } elseif ($mime === 'image/png') {
                $out = self::png($data);
            } elseif ($mime === 'image/webp') {
                $out = self::webp($data);
            } else {
                return false; // HEIC/HEIF: ยังไม่รองรับ เก็บไฟล์เดิม
            }
        } catch (Throwable $e) {
            return false;
        }
        if ($out === null || $out === $data) {
            return false;
        }
        return @file_put_contents($path, $out, LOCK_EX) !== false;
    }

    /**
     * รูปถ่ายที่ EXIF บอกให้หมุน: หมุนให้ตั้งตรงแล้วบันทึกใหม่ (ผลลัพธ์ไม่มี EXIF)
     * ถ้าต้องหมุนแต่เครื่องไม่มี GD จะคืนไฟล์เดิม เพื่อไม่ให้รูปตะแคงหลังตัด EXIF ทิ้ง
     */
    private static function rotatedJpeg(string $data): ?string
    {
        $angle = [3 => 180, 6 => -90, 8 => 90][self::jpegOrientation($data)] ?? 0;
        if ($angle === 0) {
            return null;
        }
        if (!function_exists('imagecreatefromstring')) {
            return $data;
        }
        $img = @imagecreatefromstring($data);
        $rotated = $img === false ? false : imagerotate($img, $angle, 0);
        if ($rotated === false) {
            return $data;
        }
        ob_start();
        imagejpeg($rotated, null, 90);
        $out = (string) ob_get_clean();
        return $out === '' ? $data : $out;
    }

    /** อ่านค่า Orientation (tag 0x0112) จาก EXIF เองโดยไม่ต้องเปิด extension exif ของ PHP */
    private static function jpegOrientation(string $d): int
    {
        $pos = strpos($d, "Exif\x00\x00");
        if ($pos === false || $pos > 65536) {
            return 1;
        }
        $t = $pos + 6; // ต้น TIFF header
        $order = substr($d, $t, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return 1;
        }
        $u16 = static fn (int $o): int => unpack($order === 'II' ? 'v' : 'n', substr($d, $o, 2))[1];
        $u32 = static fn (int $o): int => unpack($order === 'II' ? 'V' : 'N', substr($d, $o, 4))[1];
        $ifd = $t + $u32($t + 4);
        if ($ifd + 2 > strlen($d)) {
            return 1;
        }
        $count = $u16($ifd);
        for ($k = 0; $k < $count && $ifd + 2 + 12 * ($k + 1) <= strlen($d); $k++) {
            $e = $ifd + 2 + 12 * $k;
            if ($u16($e) === 0x0112) {
                return $u16($e + 8);
            }
        }
        return 1;
    }

    /** JPEG: ตัด segment APP1 (EXIF/XMP), APP13 (IPTC) และ COM ออก ส่วนอื่นคงเดิม */
    private static function jpeg(string $d): ?string
    {
        if (strncmp($d, "\xFF\xD8", 2) !== 0) {
            return null;
        }
        $out = "\xFF\xD8";
        $i = 2;
        $n = strlen($d);
        while ($i + 4 <= $n) {
            if ($d[$i] !== "\xFF") {
                return null;
            }
            $marker = ord($d[$i + 1]);
            if ($marker === 0xFF) { // fill byte
                $i++;
                continue;
            }
            if ($marker === 0xDA) { // Start of Scan: ที่เหลือคือข้อมูลภาพ
                return $out . substr($d, $i);
            }
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $out .= substr($d, $i, 2);
                $i += 2;
                continue;
            }
            $len = unpack('n', substr($d, $i + 2, 2))[1];
            if ($len < 2 || $i + 2 + $len > $n) {
                return null;
            }
            if (!in_array($marker, [0xE1, 0xED, 0xFE], true)) {
                $out .= substr($d, $i, 2 + $len);
            }
            $i += 2 + $len;
        }
        return null;
    }

    /** PNG: ตัด chunk eXIf, tEXt, zTXt, iTXt, tIME ออก */
    private static function png(string $d): ?string
    {
        if (strncmp($d, "\x89PNG\r\n\x1a\n", 8) !== 0) {
            return null;
        }
        $out = substr($d, 0, 8);
        $i = 8;
        $n = strlen($d);
        while ($i + 12 <= $n) {
            $len = unpack('N', substr($d, $i, 4))[1];
            $type = substr($d, $i + 4, 4);
            if ($i + 12 + $len > $n) {
                return null;
            }
            if (!in_array($type, ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'], true)) {
                $out .= substr($d, $i, 12 + $len);
            }
            $i += 12 + $len;
            if ($type === 'IEND') {
                return $out;
            }
        }
        return null;
    }

    /** WEBP: ตัด chunk EXIF และ XMP แล้วปิด flag ใน VP8X และคำนวณขนาด RIFF ใหม่ */
    private static function webp(string $d): ?string
    {
        if (strncmp($d, 'RIFF', 4) !== 0 || substr($d, 8, 4) !== 'WEBP') {
            return null;
        }
        $body = '';
        $i = 12;
        $n = strlen($d);
        while ($i + 8 <= $n) {
            $type = substr($d, $i, 4);
            $len = unpack('V', substr($d, $i + 4, 4))[1];
            if ($i + 8 + $len > $n) {
                return null;
            }
            $total = 8 + $len + ($len & 1);
            $chunk = substr($d, $i, min($total, $n - $i));
            if ($type === 'VP8X' && $len >= 10) {
                $chunk[8] = chr(ord($chunk[8]) & ~0x0C); // ปิด flag EXIF (0x08) และ XMP (0x04)
            }
            if ($type !== 'EXIF' && $type !== 'XMP ') {
                $body .= $chunk;
            }
            $i += $total;
        }
        return 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;
    }
}
