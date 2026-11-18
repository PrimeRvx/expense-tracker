<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Bangkok');

spl_autoload_register(static function (string $class): void {
    foreach ([__DIR__ . "/src/{$class}.php", __DIR__ . "/src/Controllers/{$class}.php"] as $file) {
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof ApiError) {
        Response::error($e->status, $e->errorCode, $e->getMessage(), $e->extra);
    }
    Config::log($e);
    Response::error(500, 'server_error', 'ระบบขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง');
});

Response::securityHeaders();
Config::load();
Auth::boot();

$router = new Router();

// เข้าสู่ระบบ
$router->add('POST', '/auth/register', [AuthController::class, 'register'], false);
$router->add('POST', '/auth/login', [AuthController::class, 'login'], false);
$router->add('POST', '/auth/otp', [AuthController::class, 'requestOtp'], false);
$router->add('POST', '/auth/login-otp', [AuthController::class, 'loginOtp'], false);
$router->add('POST', '/auth/reset-password', [AuthController::class, 'resetPassword'], false);
$router->add('POST', '/auth/logout', [AuthController::class, 'logout'], false);
$router->add('GET', '/auth/me', [AuthController::class, 'me'], false);
$router->add('POST', '/auth/change-password', [AuthController::class, 'changePassword']);

// หมวดหมู่
$router->add('GET', '/categories', [CategoryController::class, 'index']);
$router->add('POST', '/categories', [CategoryController::class, 'store']);
$router->add('PUT', '/categories/{id}', [CategoryController::class, 'update']);
$router->add('DELETE', '/categories/{id}', [CategoryController::class, 'destroy']);

// รายการและสลิป
$router->add('GET', '/transactions', [TransactionController::class, 'index']);
$router->add('POST', '/transactions', [TransactionController::class, 'store']);
$router->add('PUT', '/transactions/{id}', [TransactionController::class, 'update']);
$router->add('DELETE', '/transactions/{id}', [TransactionController::class, 'destroy']);
$router->add('POST', '/transactions/{id}/slip', [SlipController::class, 'store']);
$router->add('DELETE', '/transactions/{id}/slip', [SlipController::class, 'destroy']);
$router->add('GET', '/slips/{id}', [SlipController::class, 'show']);

// สรุป
$router->add('GET', '/summary/day', [SummaryController::class, 'day']);
$router->add('GET', '/summary/month', [SummaryController::class, 'month']);
$router->add('GET', '/summary/year', [SummaryController::class, 'year']);
$router->add('GET', '/summary/trend', [SummaryController::class, 'trend']);

// งบประมาณรายเดือน
$router->add('GET', '/budgets', [BudgetController::class, 'index']);
$router->add('PUT', '/budgets', [BudgetController::class, 'save']);

// นำเข้า/ส่งออก
$router->add('GET', '/export/csv', [CsvController::class, 'export']);
$router->add('POST', '/import/csv', [CsvController::class, 'import']);

// keyword ที่เรียนรู้จากผู้ใช้
$router->add('GET', '/parser-rules', [ParserRuleController::class, 'index']);
$router->add('POST', '/parser-rules', [ParserRuleController::class, 'store']);
$router->add('DELETE', '/parser-rules/{id}', [ParserRuleController::class, 'destroy']);

$router->dispatch();
