<?php
// نقطة دخول الويب هوك
require_once __DIR__ . '/../vendor/autoload.php';

// تحميل المتغيرات البيئية
 $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
 $dotenv->load();

// التحقق من صحة الطلب
 $telegram_token = $_ENV['BOT_TOKEN'] ?? null;
if (!$telegram_token) {
    http_response_code(500);
    die('BOT_TOKEN not configured');
}

// التحقق من أن الطلب قادم من تيليجر
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        http_response_code(400);
        die('Invalid JSON');
    }
    
    // تسجيل الطلب للتصحيح
    file_put_contents(__DIR__ . '/../logs/requests.log', date('Y-m-d H:i:s') . " - " . $input . "\n", FILE_APPEND);
    
    // معالجة الويب هوك
    try {
        $telegram = new Longman\TelegramBot\Telegram($telegram_token, $_ENV['BOT_USERNAME'] ?? null);
        $telegram->handle();
    } catch (Longman\TelegramBot\Exception\TelegramException $e) {
        file_put_contents(__DIR__ . '/../logs/errors.log', date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n", FILE_APPEND);
    }
} else {
    // صفحة فحص صحية
    echo json_encode(['status' => 'ok', 'timestamp' => time()]);
}
