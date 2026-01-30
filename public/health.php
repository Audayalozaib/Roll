<?php
// فحص صحة التطبيق
header('Content-Type: application/json');

 $checks = [
    'database' => file_exists(__DIR__ . '/../data/giveaway.db'),
    'logs' => is_writable(__DIR__ . '/../logs'),
    'bot_token' => !empty($_ENV['BOT_TOKEN']),
    'memory' => memory_get_usage(true) < 100 * 1024 * 1024, // أقل من 100MB
];

echo json_encode([
    'status' => array_reduce($checks, fn($carry, $check) => $carry && $check, true) ? 'healthy' : 'unhealthy',
    'checks' => $checks,
    'timestamp' => time()
]);
