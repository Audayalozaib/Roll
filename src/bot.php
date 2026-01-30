<?php
// تحويل سكربت بايثون إلى PHP لربوت تيليجرام للسحوبات

// تحميل المكتبات المطلوبة
require_once 'vendor/autoload.php';

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Exception\TelegramException;

// تحميل الإعدادات من ملف .env
 $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
 $dotenv->load();

 $BOT_TOKEN = $_ENV['BOT_TOKEN'] ?? null;
 $ADMIN_ID = (int)($_ENV['ADMIN_ID'] ?? 0);
 $MANAGED_CHANNELS = array_filter(explode(',', $_ENV['MANAGED_CHANNELS'] ?? ''));
 $MAX_PARTICIPANTS_PER_GIVEAWAY = (int)($_ENV['MAX_PARTICIPANTS_PER_GIVEAWAY'] ?? 10000);
 $CAPTCHA_FONT_PATH = $_ENV['CAPTCHA_FONT_PATH'] ?? 'arial.ttf';
 $BOT_USERNAME = $_ENV['BOT_USERNAME'] ?? null;

// التحقق من وجود الإعدادات الأساسية
if (!$BOT_TOKEN || !$ADMIN_ID) {
    die("FATAL: BOT_TOKEN or ADMIN_ID not found in .env file. Please configure it.");
}

// إعداد السجلات
error_log("Starting bot...");

// حالات المحادثة
define('SELECTING_CHANNEL', 0);
define('ENTERING_TITLE', 1);
define('SELECTING_WINNER_COUNT', 2);
define('ADDING_CONDITIONS', 3);
define('SELECTING_END_CONDITION', 4);
define('CONFIRMATION', 5);

// إعداد قاعدة البيانات
function init_db() {
    try {
        $db = new SQLite3('giveaway.db');
        
        // إنشاء الجداول الأساسية إذا لم تكن موجودة
        $db->exec("
            CREATE TABLE IF NOT EXISTS giveaways (
                giveaway_id TEXT PRIMARY KEY,
                channel_id INTEGER NOT NULL,
                message_id INTEGER,
                creator_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                description TEXT DEFAULT '',
                winner_count INTEGER NOT NULL,
                conditions TEXT,
                end_type TEXT,
                end_value TEXT,
                status TEXT NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS participants (
                giveaway_id TEXT NOT NULL,
                user_id INTEGER NOT NULL,
                username TEXT,
                joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (giveaway_id, user_id)
            )
        ");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS winners (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                giveaway_id TEXT NOT NULL,
                user_id INTEGER NOT NULL,
                username TEXT,
                notified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS captcha_attempts (
                giveaway_id TEXT NOT NULL,
                user_id INTEGER NOT NULL,
                attempts INTEGER DEFAULT 0,
                last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (giveaway_id, user_id)
            )
        ");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS giveaway_stats (
                giveaway_id TEXT PRIMARY KEY,
                views INTEGER DEFAULT 0,
                clicks INTEGER DEFAULT 0,
                FOREIGN KEY (giveaway_id) REFERENCES giveaways (giveaway_id)
            )
        ");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS banned_users (
                user_id INTEGER PRIMARY KEY,
                reason TEXT,
                banned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                banned_by INTEGER
            )
        ");
        
        // التحقق من وجود الأعمدة الجديدة وإضافتها إذا لم تكن موجودة
        $result = $db->query("PRAGMA table_info(giveaways)");
        $giveaways_columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $giveaways_columns[] = $row['name'];
        }
        
        if (!in_array('description', $giveaways_columns)) {
            $db->exec("ALTER TABLE giveaways ADD COLUMN description TEXT DEFAULT ''");
        }
        
        $result = $db->query("PRAGMA table_info(participants)");
        $participants_columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $participants_columns[] = $row['name'];
        }
        
        if (!in_array('username', $participants_columns)) {
            $db->exec("ALTER TABLE participants ADD COLUMN username TEXT");
        }
        
        $result = $db->query("PRAGMA table_info(winners)");
        $winners_columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $winners_columns[] = $row['name'];
        }
        
        if (!in_array('username', $winners_columns)) {
            $db->exec("ALTER TABLE winners ADD COLUMN username TEXT");
        }
        
        $result = $db->query("PRAGMA table_info(captcha_attempts)");
        $captcha_columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $captcha_columns[] = $row['name'];
        }
        
        if (!in_array('last_attempt', $captcha_columns)) {
            $db->exec("ALTER TABLE captcha_attempts ADD COLUMN last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        }
        
        $db->close();
        error_log("Database initialized successfully.");
    } catch (Exception $e) {
        die("FATAL: Could not initialize database. Error: " . $e->getMessage());
    }
}

// دوال مساعدة للقاعدة البيانات
function get_db_connection() {
    return new SQLite3('giveaway.db');
}

function execute_db_query($query, $params = [], $fetch = null) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare($query);
        
        if (!empty($params)) {
            $i = 1;
            foreach ($params as $param) {
                $stmt->bindValue($i, $param);
                $i++;
            }
        }
        
        $result = $stmt->execute();
        
        $return_value = null;
        if ($fetch === 'one') {
            $return_value = $result->fetchArray(SQLITE3_ASSOC);
        } elseif ($fetch === 'all') {
            $return_value = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $return_value[] = $row;
            }
        }
        
        $db->close();
        return $return_value;
    } catch (Exception $e) {
        error_log("Database error: " . $e->getMessage() . "\nQuery: $query\nParams: " . implode(', ', $params));
        return null;
    }
}

function create_giveaway($data) {
    execute_db_query("
        INSERT INTO giveaways (giveaway_id, channel_id, creator_id, title, description, winner_count, conditions, end_type, end_value, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ", [
        $data['giveaway_id'], $data['channel_id'], $data['creator_id'], $data['title'],
        $data['description'] ?? '', $data['winner_count'], $data['conditions'] ?? '', $data['end_type'],
        $data['end_value'] ?? '', $data['status']
    ]);
    
    // Initialize stats for the new giveaway
    execute_db_query("INSERT INTO giveaway_stats (giveaway_id) VALUES (?)", [$data['giveaway_id']]);
}

function get_giveaway($giveaway_id) {
    return execute_db_query("SELECT * FROM giveaways WHERE giveaway_id = ?", [$giveaway_id], 'one');
}

function get_all_giveaways($status = null) {
    if ($status) {
        $rows = execute_db_query("SELECT * FROM giveaways WHERE status = ? ORDER BY created_at DESC", [$status], 'all');
    } else {
        $rows = execute_db_query("SELECT * FROM giveaways ORDER BY created_at DESC", [], 'all');
    }
    return $rows ?: [];
}

function delete_giveaway($giveaway_id) {
    execute_db_query("DELETE FROM giveaways WHERE giveaway_id = ?", [$giveaway_id]);
    execute_db_query("DELETE FROM participants WHERE giveaway_id = ?", [$giveaway_id]);
    execute_db_query("DELETE FROM winners WHERE giveaway_id = ?", [$giveaway_id]);
    execute_db_query("DELETE FROM captcha_attempts WHERE giveaway_id = ?", [$giveaway_id]);
    execute_db_query("DELETE FROM giveaway_stats WHERE giveaway_id = ?", [$giveaway_id]);
}

function update_giveaway_status($giveaway_id, $status) {
    execute_db_query("UPDATE giveaways SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE giveaway_id = ?", [$status, $giveaway_id]);
}

function update_giveaway_message($giveaway_id, $message_id) {
    execute_db_query("UPDATE giveaways SET message_id = ?, updated_at = CURRENT_TIMESTAMP WHERE giveaway_id = ?", [$message_id, $giveaway_id]);
}

function add_participant($giveaway_id, $user_id, $username = null) {
    execute_db_query("INSERT OR IGNORE INTO participants (giveaway_id, user_id, username) VALUES (?, ?, ?)", [$giveaway_id, $user_id, $username]);
    // Increment click stat
    execute_db_query("UPDATE giveaway_stats SET clicks = clicks + 1 WHERE giveaway_id = ?", [$giveaway_id]);
}

function remove_participant($giveaway_id, $user_id) {
    execute_db_query("DELETE FROM participants WHERE giveaway_id = ? AND user_id = ?", [$giveaway_id, $user_id]);
}

function get_participants_count($giveaway_id) {
    $row = execute_db_query("SELECT COUNT(*) as count FROM participants WHERE giveaway_id = ?", [$giveaway_id], 'one');
    return $row ? $row['count'] : 0;
}

function get_participants($giveaway_id) {
    try {
        $rows = execute_db_query("SELECT user_id, username, joined_at FROM participants WHERE giveaway_id = ? ORDER BY joined_at DESC", [$giveaway_id], 'all');
    } catch (Exception $e) {
        // If it fails, use the query without username
        $rows = execute_db_query("SELECT user_id, NULL as username, joined_at FROM participants WHERE giveaway_id = ? ORDER BY joined_at DESC", [$giveaway_id], 'all');
    }
    return $rows ?: [];
}

function save_winners($giveaway_id, $winner_ids, $usernames = []) {
    foreach ($winner_ids as $i => $user_id) {
        $username = isset($usernames[$i]) ? $usernames[$i] : null;
        execute_db_query("INSERT OR IGNORE INTO winners (giveaway_id, user_id, username) VALUES (?, ?, ?)", [$giveaway_id, $user_id, $username]);
    }
}

function get_winners($giveaway_id) {
    try {
        $rows = execute_db_query("SELECT user_id, username, notified_at FROM winners WHERE giveaway_id = ?", [$giveaway_id], 'all');
    } catch (Exception $e) {
        // If it fails, use the query without username
        $rows = execute_db_query("SELECT user_id, NULL as username, notified_at FROM winners WHERE giveaway_id = ?", [$giveaway_id], 'all');
    }
    return $rows ?: [];
}

function get_captcha_attempts($giveaway_id, $user_id) {
    $row = execute_db_query("SELECT attempts FROM captcha_attempts WHERE giveaway_id = ? AND user_id = ?", [$giveaway_id, $user_id], 'one');
    return $row ? $row['attempts'] : 0;
}

function increment_captcha_attempt($giveaway_id, $user_id) {
    try {
        execute_db_query("
            INSERT INTO captcha_attempts (giveaway_id, user_id, attempts) VALUES (?, ?, 1)
            ON CONFLICT(giveaway_id, user_id) DO UPDATE SET attempts = attempts + 1, last_attempt = CURRENT_TIMESTAMP
        ", [$giveaway_id, $user_id]);
    } catch (Exception $e) {
        // If it fails, use the query without last_attempt
        execute_db_query("
            INSERT INTO captcha_attempts (giveaway_id, user_id, attempts) VALUES (?, ?, 1)
            ON CONFLICT(giveaway_id, user_id) DO UPDATE SET attempts = attempts + 1
        ", [$giveaway_id, $user_id]);
    }
}

function reset_captcha_attempts($giveaway_id, $user_id) {
    execute_db_query("DELETE FROM captcha_attempts WHERE giveaway_id = ? AND user_id = ?", [$giveaway_id, $user_id]);
}

function increment_view_count($giveaway_id) {
    execute_db_query("UPDATE giveaway_stats SET views = views + 1 WHERE giveaway_id = ?", [$giveaway_id]);
}

function get_giveaway_stats($giveaway_id) {
    $row = execute_db_query("SELECT * FROM giveaway_stats WHERE giveaway_id = ?", [$giveaway_id], 'one');
    return $row ?: ['views' => 0, 'clicks' => 0];
}

function is_user_banned($user_id) {
    $row = execute_db_query("SELECT user_id FROM banned_users WHERE user_id = ?", [$user_id], 'one');
    return $row !== false;
}

function ban_user($user_id, $reason = '', $banned_by = null) {
    execute_db_query("INSERT OR REPLACE INTO banned_users (user_id, reason, banned_by) VALUES (?, ?, ?)", [$user_id, $reason, $banned_by]);
}

function unban_user($user_id) {
    execute_db_query("DELETE FROM banned_users WHERE user_id = ?", [$user_id]);
}

function get_banned_users() {
    $rows = execute_db_query("SELECT * FROM banned_users ORDER BY banned_at DESC", [], 'all');
    return $rows ?: [];
}

// دوال مساعدة
function is_potentially_a_hash($text) {
    return strlen($text) > 30 && strpos($text, '-') !== false;
}

function generate_captcha_image_and_code() {
    global $CAPTCHA_FONT_PATH;
    
    $width = 300;
    $height = 120;
    $image = imagecreatetruecolor($width, $height);
    
    // Random background color
    $bg_color = imagecolorallocate($image, rand(220, 255), rand(220, 255), rand(220, 255));
    imagefill($image, 0, 0, $bg_color);
    
    // Load font
    $font = $CAPTCHA_FONT_PATH;
    if (!file_exists($font)) {
        // Use default font if specified font doesn't exist
        $font = 5;
    }
    
    // Generate random code
    $code = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 5);
    
    // Calculate text position
    $font_size = 60;
    if (is_int($font)) {
        // For built-in fonts
        $text_width = imagefontwidth($font) * strlen($code);
        $text_height = imagefontheight($font);
    } else {
        // For TTF fonts
        $bbox = imagettfbbox($font_size, 0, $font, $code);
        $text_width = $bbox[2] - $bbox[0];
        $text_height = $bbox[3] - $bbox[1];
    }
    
    $x = ($width - $text_width) / 2;
    $y = ($height - $text_height) / 2;
    
    // Draw text
    $text_color = imagecolorallocate($image, 0, 0, 0);
    if (is_int($font)) {
        imagestring($image, $font, $x, $y, $code, $text_color);
    } else {
        imagettftext($image, $font_size, 0, $x, $y + $text_height, $text_color, $font, $code);
    }
    
    // Add noise
    for ($i = 0; $i < 10; $i++) {
        $x1 = rand(0, $width);
        $y1 = rand(0, $height);
        $x2 = rand(0, $width);
        $y2 = rand(0, $height);
        $line_color = imagecolorallocate($image, rand(0, 150), rand(0, 150), rand(0, 150));
        imageline($image, $x1, $y1, $x2, $y2, $line_color);
    }
    
    for ($i = 0; $i < 70; $i++) {
        $x = rand(0, $width);
        $y = rand(0, $height);
        $pixel_color = imagecolorallocate($image, 0, 0, 0);
        imagesetpixel($image, $x, $y, $pixel_color);
    }
    
    // Capture image
    ob_start();
    imagepng($image);
    $image_data = ob_get_contents();
    ob_end_clean();
    
    // Clean up
    imagedestroy($image);
    
    return [$image_data, $code];
}

function is_user_admin($telegram, $user_id, $chat_id) {
    try {
        $response = Request::getChatMember([
            'chat_id' => $chat_id,
            'user_id' => $user_id
        ]);
        
        if ($response->isOk()) {
            $result = $response->getResult();
            $status = $result->getStatus();
            return in_array($status, ['creator', 'administrator']);
        }
        return false;
    } catch (Exception $e) {
        error_log("Error checking admin status for $user_id in $chat_id: " . $e->getMessage());
        return false;
    }
}

function check_subscription($telegram, $user_id, $channel_usernames) {
    foreach ($channel_usernames as $username) {
        try {
            $response = Request::getChatMember([
                'chat_id' => '@' . $username,
                'user_id' => $user_id
            ]);
            
            if ($response->isOk()) {
                $result = $response->getResult();
                $status = $result->getStatus();
                if (!in_array($status, ['member', 'creator', 'administrator'])) {
                    return [false, "أنت غير مشترك في @$username"];
                }
            } else {
                $error_msg = strtolower($response->getDescription());
                if (strpos($error_msg, 'chat not found') !== false) {
                    return [false, "القناة @$username غير موجودة أو البوت ليس فيها."];
                }
                return [false, "لا يمكن التحقق من اشتراكك في @$username."];
            }
        } catch (Exception $e) {
            error_log("Could not check subscription for @$username for user $user_id: " . $e->getMessage());
            return [false, "حدث خطأ أثناء التحقق من @$username."];
        }
    }
    return [true, ''];
}

function generate_giveaway_text($giveaway, $participants_count) {
    $text = "🎉 **سحب على: {$giveaway['title']}** 🎉\n\n";
    
    // Add description if exists
    if (!empty($giveaway['description'])) {
        $text .= "📝 {$giveaway['description']}\n\n";
    }
    
    $text .= "للمشاركة، اضغط على زر 'المشاركة' أدناه.\n\n";
    
    if (!empty($giveaway['conditions'])) {
        $channels = explode(',', $giveaway['conditions']);
        $text .= "📌 **شروط المشاركة:**\n";
        foreach ($channels as $ch) {
            $text .= "• الاشتراك في قناة [$ch](https://t.me/$ch)\n";
        }
        $text .= "\n";
    }

    if ($giveaway['end_type'] == 'time') {
        $end_time = new DateTime($giveaway['end_value']);
        $text .= "⏰ **ينتهي السحب في:** {$end_time->format('Y-m-d H:i:s')}\n";
    } elseif ($giveaway['end_type'] == 'participants') {
        $text .= "👥 **سيتم السحب عند وصول المشاركين إلى:** {$giveaway['end_value']}\n";
    }
    
    $text .= "🎯 **عدد الفائزين:** " . ($giveaway['winner_count'] == 0 ? 'الكل' : $giveaway['winner_count']) . "\n\n";
    $text .= "🔍 **رمز التحقق (للمصداقية):** `{$giveaway['giveaway_id']}`\n\n";
    $text .= "👥 **عدد المشاركين الحالي:** $participants_count";

    return $text;
}

function get_giveaway_keyboard($giveaway, $participants_count) {
    global $BOT_USERNAME;
    
    $start_link = "https://t.me/$BOT_USERNAME?start={$giveaway['giveaway_id']}";
    
    $keyboard = [
        [new InlineKeyboardButton(["text" => "🎉 المشاركة ($participants_count)", "url" => $start_link])],
        [new InlineKeyboardButton(["text" => "🔍 نسخ رمز التحقق", "callback_data" => "copy_hash|{$giveaway['giveaway_id']}"])],
        [new InlineKeyboardButton(["text" => "📊 إحصائيات السحب", "callback_data" => "stats|{$giveaway['giveaway_id']}"])],
    ];
    
    return new InlineKeyboard($keyboard);
}

function get_admin_keyboard($giveaway) {
    $status = $giveaway['status'];
    $pause_resume_text = $status == 'paused' ? '▶️ استئناف' : '⏸️ إيقاف مؤقت';
    $pause_resume_data = $status == 'paused' ? "resume|{$giveaway['giveaway_id']}" : "pause|{$giveaway['giveaway_id']}";
    
    $keyboard = [
        [new InlineKeyboardButton(["text" => $pause_resume_text, "callback_data" => $pause_resume_data])],
        [new InlineKeyboardButton(["text" => "🎲 سحب النتائج الآن", "callback_data" => "draw_now|{$giveaway['giveaway_id']}"])],
        [new InlineKeyboardButton(["text" => "📊 عرض المشاركين", "callback_data" => "view_participants|{$giveaway['giveaway_id']}"])],
        [new InlineKeyboardButton(["text" => "🔧 تعديل السحب", "callback_data" => "edit_giveaway|{$giveaway['giveaway_id']}"])],
    ];
    
    return new InlineKeyboard($keyboard);
}

function update_giveaway_message($telegram, $giveaway_id) {
    $giveaway = get_giveaway($giveaway_id);
    if (!$giveaway) return;

    $participants_count = get_participants_count($giveaway_id);
    $new_text = generate_giveaway_text($giveaway, $participants_count);
    
    $user_keyboard = get_giveaway_keyboard($giveaway, $participants_count);
    $admin_keyboard = get_admin_keyboard($giveaway);
    
    // Merge keyboards
    $full_keyboard = new InlineKeyboard(array_merge($user_keyboard->getInlineKeyboard(), $admin_keyboard->getInlineKeyboard()));

    try {
        Request::editMessageText([
            'chat_id' => $giveaway['channel_id'],
            'message_id' => $giveaway['message_id'],
            'text' => $new_text,
            'reply_markup' => $full_keyboard,
            'parse_mode' => 'Markdown'
        ]);
    } catch (Exception $e) {
        $error_msg = strtolower($e->getMessage());
        if (strpos($error_msg, 'message to edit not found') !== false) {
            error_log("Message {$giveaway['message_id']} not found in channel {$giveaway['channel_id']}");
        } elseif (strpos($error_msg, 'message is not modified') !== false) {
            // Ignore this error
        } else {
            error_log("Failed to update giveaway message $giveaway_id: " . $e->getMessage());
        }
    }
}

function perform_giveaway_draw($telegram, $giveaway_id) {
    $giveaway = get_giveaway($giveaway_id);
    if (!$giveaway || $giveaway['status'] == 'finished') {
        return;
    }

    $participants = get_participants($giveaway_id);
    if (empty($participants)) {
        try {
            Request::sendMessage([
                'chat_id' => $giveaway['channel_id'],
                'text' => "لم يشارك أحد في السحب! 😔"
            ]);
        } catch (Exception $e) {
            error_log("Could not send 'no participants' message: " . $e->getMessage());
        }
        update_giveaway_status($giveaway_id, 'finished');
        return;
    }

    $winner_count = $giveaway['winner_count'];
    if ($winner_count == 0) {
        $winner_ids = array_column($participants, 'user_id');
        $usernames = array_column($participants, 'username');
    } else {
        // Shuffle participants and select winners
        shuffle($participants);
        $selected = array_slice($participants, 0, min($winner_count, count($participants)));
        $winner_ids = array_column($selected, 'user_id');
        $usernames = array_column($selected, 'username');
    }

    save_winners($giveaway_id, $winner_ids, $usernames);
    update_giveaway_status($giveaway_id, 'finished');
    
    $winners_text = "🎊 **انتهى السحب: {$giveaway['title']}** 🎊\n\n";
    $winners_text .= "**🏆 الفائزون:**\n";
    
    foreach ($winner_ids as $i => $user_id) {
        try {
            $response = Request::getChat(['chat_id' => $user_id]);
            if ($response->isOk()) {
                $user = $response->getResult();
                $username = $user->getUsername() ? "@{$user->getUsername()}" : $user->getFirstName();
                $winners_text .= ($i + 1) . ". [$username](tg://user?id=$user_id)\n";
                
                try {
                    Request::sendMessage([
                        'chat_id' => $user_id,
                        'text' => "🎉 **مبروك!** 🎉\n\n" .
                                 "لقد فزت في سحب '{$giveaway['title']}' الذي أُقيم في القناة.\n" .
                                 "تواصل مع مدير القناة لاستلام جائزتك."
                    ]);
                    sleep(1); // Small delay to avoid flood limits
                } catch (Exception $e) {
                    error_log("Could not notify winner $user_id: " . $e->getMessage());
                }
            } else {
                $winners_text .= ($i + 1) . ". مستخدم غير متوفر (ID: $user_id)\n";
            }
        } catch (Exception $e) {
            $winners_text .= ($i + 1) . ". مستخدم غير متوفر (ID: $user_id)\n";
        }
    }

    // Create winners file
    $file_content = "قائمة الفائزين في سحب: {$giveaway['title']}\n";
    $file_content .= str_repeat("=", 40) . "\n";
    foreach ($winner_ids as $i => $user_id) {
        try {
            $response = Request::getChat(['chat_id' => $user_id]);
            if ($response->isOk()) {
                $user = $response->getResult();
                $file_content .= ($i + 1) . ". {$user->getFirstName()} | ID: $user_id | @{$user->getUsername()}\n";
            } else {
                $file_content .= ($i + 1) . ". N/A | ID: $user_id | N/A\n";
            }
        } catch (Exception $e) {
            $file_content .= ($i + 1) . ". N/A | ID: $user_id | N/A\n";
        }
    }
    
    // Send winners file
    $temp_file = tempnam(sys_get_temp_dir(), 'winners_');
    file_put_contents($temp_file, $file_content);
    
    Request::sendDocument([
        'chat_id' => $giveaway['channel_id'],
        'document' => $temp_file,
        'caption' => "📄 قائمة الفائزين الرسمية في سحب '{$giveaway['title']}' (عددهم " . count($winner_ids) . ")"
    ]);
    
    unlink($temp_file); // Remove temp file
    
    // Send winners text
    Request::sendMessage([
        'chat_id' => $giveaway['channel_id'],
        'text' => $winners_text,
        'parse_mode' => 'Markdown'
    ]);

    // Update giveaway message
    try {
        $participants_count = get_participants_count($giveaway_id);
        $original_text = generate_giveaway_text($giveaway, $participants_count);
        Request::editMessageText([
            'chat_id' => $giveaway['channel_id'],
            'message_id' => $giveaway['message_id'],
            'text' => "~~$original_text~~\n\n**✅ تم إجراء السحب والانتهاء منه.**",
            'parse_mode' => 'Markdown'
        ]);
    } catch (Exception $e) {
        error_log("Could not edit final giveaway message: " . $e->getMessage());
    }
}

// Initialize database
init_db();

// Create Telegram API object
try {
    $telegram = new Telegram($BOT_TOKEN, $BOT_USERNAME);
    $telegram->enableAdmins([$ADMIN_ID]);
    
    // Handle commands
    $telegram->addCommandClass(\Longman\TelegramBot\Commands\UserCommands\StartCommand::class);
    
    // Handle callback queries
    $telegram->addCallbackQueryCallback(function ($callback_query) use ($telegram) {
        $data = $callback_query->getData();
        $user_id = $callback_query->getFrom()->getId();
        
        if ($data == 'create_giveaway') {
            return create_giveaway_start($telegram, $callback_query);
        } elseif ($data == 'admin_panel' && $user_id == $ADMIN_ID) {
            return show_admin_panel($telegram, $callback_query);
        }
        
        $parts = explode('|', $data);
        $action = $parts[0];
        
        if ($action == 'participate') {
            // This case is no longer used since we're using direct links now
            // But we keep it for compatibility with old giveaways
            $giveaway_id = $parts[1];
            $giveaway = get_giveaway($giveaway_id);
            if (!$giveaway || $giveaway['status'] != 'active') {
                return Request::answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'هذا السحب غير نشط.',
                    'show_alert' => true
                ]);
            }

            if (!$callback_query->getFrom()->getUsername()) {
                Request::answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'يجب أن يكون لديك اسم مستخدم (Username).',
                    'show_alert' => true
                ]);
                
                try {
                    Request::sendMessage([
                        'chat_id' => $user_id,
                        'text' => 'عذراً، يجب أن يكون لديك اسم مستخدم للمشاركة في السحب.'
                    ]);
                } catch (Exception $e) {
                    // Ignore errors when trying to send messages
                }
                return;
            }
            
            // Check if user is banned
            if (is_user_banned($user_id)) {
                return Request::answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'تم حظرك من المشاركة في السحوبات.',
                    'show_alert' => true
                ]);
            }
            
            // Check max participants
            $participants_count = get_participants_count($giveaway_id);
            if ($participants_count >= $MAX_PARTICIPANTS_PER_GIVEAWAY) {
                return Request::answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'وصل السحب إلى الحد الأقصى للمشاركين.',
                    'show_alert' => true
                ]);
            }
            
            $attempts = get_captcha_attempts($giveaway_id, $user_id);
            if ($attempts >= 3) {
                return Request::answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'لقد استهلكت كل محاولاتك لحل الكبتشا في هذا السحب.',
                    'show_alert' => true
                ]);
            }

            $required_channels = [];
            if (!empty($giveaway['conditions'])) {
                $required_channels = explode(',', $giveaway['conditions']);
            }
            
            if (!empty($required_channels)) {
                list($is_subscribed, $error_msg) = check_subscription($telegram, $user_id, $required_channels);
                if (!$is_subscribed) {
                    return Request::answerCallbackQuery([
                        'callback_query_id' => $callback_query->getId(),
                        'text' => $error_msg,
                        'show_alert' => true
                    ]);
                }
            }
            
            // Check if bot is activated
            try {
                // Try to send a message to check if the user has started a conversation with the bot
                Request::sendMessage([
                    'chat_id' => $user_id,
                    'text' => 'جاري التحقق...'
                ]);
                // If successful, delete the verification message and send captcha
                Request::deleteMessage([
                    'chat_id' => $user_id,
                    'message_id' => $callback_query->getMessage()->getMessageId() + 1
                ]);
            } catch (Exception $e) {
                // If failed, it means the user hasn't started the bot
                global $BOT_USERNAME;
                $start_link = "https://t.me/$BOT_USERNAME?start=$giveaway_id";
                $keyboard = [[new InlineKeyboardButton(["text" => "ابدأ البوت", "url" => $start_link])]];
                
                Request::sendMessage([
                    'chat_id' => $callback_query->getMessage()->getChat()->getId(),
                    'text' => "❌ يجب عليك تفعيل البوت أولاً حتى أتمكن من إرسال لك رمز التحقق.\n\n" .
                             "اضغط على الزر أدناه لبدء البوت، ثم عد واضغط على 'المشاركة' مرة أخرى.",
                    'reply_markup' => new InlineKeyboard($keyboard)
                ]);
                
                return Request::answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'يرجى تفعيل البوت أولاً.',
                    'show_alert' => true
                ]);
            }
            
            list($image_bytes, $correct_code) = generate_captcha_image_and_code();
            
            // Store captcha data in session or database
            $_SESSION['captcha_code'] = $correct_code;
            $_SESSION['captcha_giveaway_id'] = $giveaway_id;
            
            Request::sendPhoto([
                'chat_id' => $user_id,
                'photo' => $image_bytes,
                'caption' => "أدخل الرمز الظاهر في الصورة (لديك 3 محاولات فقط)."
            ]);
            
            return Request::answerCallbackQuery([
                'callback_query_id' => $callback_query->getId(),
                'text' => 'تم إرسال الكابتشا لك في الخاص.',
                'show_alert' => true
            ]);
        } elseif (in_array($action, ['pause', 'resume', 'draw_now'])) {
            $giveaway_id = $parts[1];
            $giveaway = get_giveaway($giveaway_id);
            if (!$giveaway) {
                return Request::answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'السحب غير موجود.',
                    'show_alert' => true
                ]);
            }

            $is_creator = ($giveaway['creator_id'] == $user_id);
            $is_channel_admin = is_user_admin($telegram, $user_id, $giveaway['channel_id']);
            
            if (!($is_creator || $is_channel_admin)) {
                return Request::answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'ليس لديك صلاحية للتحكم بهذا السحب.',
                    'show_alert' => true
                ]);
            }

            if ($action == 'pause') {
                update_giveaway_status($giveaway_id, 'paused');
                // Cancel any scheduled jobs for this giveaway
                // This would need to be implemented based on your job scheduling system
                return Request::editMessageReplyMarkup([
                    'chat_id' => $callback_query->getMessage()->getChat()->getId(),
                    'message_id' => $callback_query->getMessage()->getMessageId(),
                    'reply_markup' => get_admin_keyboard($giveaway)
                ]);
            } elseif ($action == 'resume') {
                update_giveaway_status($giveaway_id, 'active');
                if ($giveaway['end_type'] == 'time') {
                    $end_time = new DateTime($giveaway['end_value']);
                    // Schedule the giveaway to end at the specified time
                    // This would need to be implemented based on your job scheduling system
                }
                return Request::editMessageReplyMarkup([
                    'chat_id' => $callback_query->getMessage()->getChat()->getId(),
                    'message_id' => $callback_query->getMessage()->getMessageId(),
                    'reply_markup' => get_admin_keyboard($giveaway)
                ]);
            } elseif ($action == 'draw_now') {
                perform_giveaway_draw($telegram, $giveaway_id);
                return Request::answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'جاري إجراء السحب الآن...'
                ]);
            }
        } elseif ($action == 'copy_hash') {
            $giveaway_id = $parts[1];
            try {
                Request::sendMessage([
                    'chat_id' => $callback_query->getMessage()->getChat()->getId(),
                    'text' => "تم نسخ الرمز بنجاح:\n\n`$giveaway_id`",
                    'parse_mode' => 'Markdown'
                ]);
                return Request::answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => "تم نسخ الرمز: $giveaway_id",
                    'show_alert' => false
                ]);
            } catch (Exception $e) {
                error_log("Could not send copy message: " . $e->getMessage());
                return Request::answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => "الرمز هو: $giveaway_id",
                    'show_alert' => true
                ]);
            }
        } elseif ($action == 'stats') {
            $giveaway_id = $parts[1];
            return show_giveaway_stats($telegram, $callback_query, $giveaway_id);
        } elseif ($action == 'view_participants') {
            $giveaway_id = $parts[1];
            return show_participants($telegram, $callback_query, $giveaway_id);
        } elseif ($action == 'edit_giveaway') {
            $giveaway_id = $parts[1];
            return edit_giveaway($telegram, $callback_query, $giveaway_id);
        } elseif ($user_id == $ADMIN_ID) {
            if ($action == 'admin_list_active') {
                return show_admin_list($telegram, $callback_query, 'active');
            } elseif ($action == 'admin_list_paused') {
                return show_admin_list($telegram, $callback_query, 'paused');
            } elseif ($action == 'admin_list_finished') {
                return show_admin_list($telegram, $callback_query, 'finished');
            } elseif ($action == 'admin_delete') {
                $giveaway_id = $parts[1];
                delete_giveaway($giveaway_id);
                Request::answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'تم حذف السحب وجميع بياناته.',
                    'show_alert' => true
                ]);
                // Extract status from the message text
                $message_text = $callback_query->getMessage()->getText();
                $status = trim(explode(':', $message_text)[1]);
                return show_admin_list($telegram, $callback_query, $status);
            } elseif ($action == 'admin_back') {
                return show_admin_panel($telegram, $callback_query);
            } elseif ($action == 'admin_ban_user') {
                $user_to_ban = (int)$parts[1];
                ban_user($user_to_ban, 'حظر من قبل المالك', $ADMIN_ID);
                Request::answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => "تم حظر المستخدم $user_to_ban.",
                    'show_alert' => true
                ]);
                return show_banned_users($telegram, $callback_query);
            } elseif ($action == 'admin_unban_user') {
                $user_to_unban = (int)$parts[1];
                unban_user($user_to_unban);
                Request::answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => "تم إلغاء حظر المستخدم $user_to_unban.",
                    'show_alert' => true
                ]);
                return show_banned_users($telegram, $callback_query);
            } elseif ($action == 'admin_view_banned') {
                return show_banned_users($telegram, $callback_query);
            }
        }
    });
    
    // Handle messages
    $telegram->addMessageCallback(function ($message) use ($telegram) {
        $chat_type = $message->getChat()->getType();
        if ($chat_type != 'private') {
            return;
        }

        $user_message = $message->getText();
        
        // Check if it's a captcha answer
        if (isset($_SESSION['captcha_code'])) {
            return handle_captcha_answer($telegram, $message);
        }
        
        // Check if it's an unparticipate request
        if (strpos($user_message, 'إلغاء') !== false && is_potentially_a_hash(trim(str_replace('إلغاء', '', $user_message)))) {
            $giveaway_id = trim(str_replace('إلغاء', '', $user_message));
            return handle_unparticipate($telegram, $message, $giveaway_id);
        }
        
        // Check if it's a hash verification
        if (is_potentially_a_hash($user_message)) {
            return handle_hash_verification($telegram, $message);
        }
    });
    
    // Start the bot
    $telegram->handle();
    
} catch (TelegramException $e) {
    echo $e->getMessage();
}

// Helper functions for callback handlers
function create_giveaway_start($telegram, $callback_query) {
    $user_id = $callback_query->getFrom()->getId();
    
    Request::answerCallbackQuery([
        'callback_query_id' => $callback_query->getId()
    ]);
    
    Request::editMessageText([
        'chat_id' => $callback_query->getMessage()->getChat()->getId(),
        'message_id' => $callback_query->getMessage()->getMessageId(),
        'text' => 'جاري البحث عن القنوات المشتركة بينك وبيني... ⏳'
    ]);
    
    global $MANAGED_CHANNELS;
    $common_channels = [];
    
    foreach ($MANAGED_CHANNELS as $chat_identifier) {
        try {
            if (!is_user_admin($telegram, $telegram->getBot()->getId(), $chat_identifier)) {
                continue;
            }
            
            if (is_user_admin($telegram, $user_id, $chat_identifier)) {
                $response = Request::getChat(['chat_id' => $chat_identifier]);
                if ($response->isOk()) {
                    $common_channels[] = $response->getResult();
                }
            }
        } catch (Exception $e) {
            error_log("Could not check channel $chat_identifier: " . $e->getMessage());
            continue;
        }
    }
    
    $keyboard = [];
    foreach ($common_channels as $chat) {
        $keyboard[] = [new InlineKeyboardButton(["text" => $chat->getTitle(), "callback_data" => "select_channel|{$chat->getId()}"])];
    }
    
    $keyboard[] = [new InlineKeyboardButton(["text" => "➕ إضافة قناة أخرى", "callback_data" => "add_new_channel"])];
    
    if (empty($common_channels)) {
        $text = "لم أجد أي قنوات مشتركة بيننا من القائمة المعتمدة.\n\nيمكنك استخدام الخيار أدناه لإضافة قناة أنت والبوت مسؤولان فيها.";
    } else {
        $text = "الخطوة 1/6: اختر القناة التي تريد إقامة السحب فيها، أو أضف قناة جديدة:";
    }
    
    return Request::editMessageText([
        'chat_id' => $callback_query->getMessage()->getChat()->getId(),
        'message_id' => $callback_query->getMessage()->getMessageId(),
        'text' => $text,
        'reply_markup' => new InlineKeyboard($keyboard)
    ]);
}

function show_giveaway_stats($telegram, $callback_query, $giveaway_id) {
    Request::answerCallbackQuery([
        'callback_query_id' => $callback_query->getId()
    ]);
    
    $giveaway = get_giveaway($giveaway_id);
    if (!$giveaway) {
        return Request::answerCallbackQuery([
            'callback_query_id' => $callback_query->getId(),
            'text' => 'السحب غير موجود.',
            'show_alert' => true
        ]);
    }
    
    $stats = get_giveaway_stats($giveaway_id);
    $participants_count = get_participants_count($giveaway_id);
    
    $text = "📊 **إحصائيات السحب: {$giveaway['title']}** 📊\n\n" .
            "🆔 **الرمز:** `$giveaway_id`\n" .
            "👀 **المشاهدات:** {$stats['views']}\n" .
            "👆 **النقرات على زر المشاركة:** {$stats['clicks']}\n" .
            "👥 **المشاركون الفعليون:** $participants_count\n" .
            "📈 **نسبة التحويل:** " . ($stats['clicks'] > 0 ? round($participants_count / $stats['clicks'] * 100, 2) : 0) . "%\n\n";
    
    if ($giveaway['status'] == 'finished') {
        $winners = get_winners($giveaway_id);
        $text .= "🏆 **عدد الفائزين:** " . count($winners) . "\n";
    }
    
    $keyboard = [[new InlineKeyboardButton(["text" => "🔙 رجوع", "callback_data" => "back_to_giveaway|$giveaway_id"])]];
    
    return Request::editMessageText([
        'chat_id' => $callback_query->getMessage()->getChat()->getId(),
        'message_id' => $callback_query->getMessage()->getMessageId(),
        'text' => $text,
        'reply_markup' => new InlineKeyboard($keyboard),
        'parse_mode' => 'Markdown'
    ]);
}

function show_participants($telegram, $callback_query, $giveaway_id) {
    Request::answerCallbackQuery([
        'callback_query_id' => $callback_query->getId()
    ]);
    
    $giveaway = get_giveaway($giveaway_id);
    if (!$giveaway) {
        return Request::answerCallbackQuery([
            'callback_query_id' => $callback_query->getId(),
            'text' => 'السحب غير موجود.',
            'show_alert' => true
        ]);
    }
    
    $user_id = $callback_query->getFrom()->getId();
    $is_creator = ($giveaway['creator_id'] == $user_id);
    $is_channel_admin = is_user_admin($telegram, $user_id, $giveaway['channel_id']);
    
    if (!($is_creator || $is_channel_admin)) {
        return Request::answerCallbackQuery([
            'callback_query_id' => $callback_query->getId(),
            'text' => 'ليس لديك صلاحية لعرض المشاركين.',
            'show_alert' => true
        ]);
    }
    
    $participants = get_participants($giveaway_id);
    if (empty($participants)) {
        $text = "لا يوجد مشاركون في هذا السحب.";
    } else {
        $text = "👥 **المشاركون في السحب: {$giveaway['title']}** 👥\n\n" .
                "**العدد الإجمالي:** " . count($participants) . "\n\n";
        
        // Show only first 20 participants to avoid very long messages
        for ($i = 0; $i < min(20, count($participants)); $i++) {
            $participant = $participants[$i];
            $username = $participant['username'] ? $participant['username'] : "ID: {$participant['user_id']}";
            $joined_at = (new DateTime($participant['joined_at']))->format('Y-m-d H:i');
            $text .= ($i + 1) . ". $username - انضم في $joined_at\n";
        }
        
        if (count($participants) > 20) {
            $text .= "\n... و " . (count($participants) - 20) . " مشارك آخر.";
        }
    }
    
    $keyboard = [
        [new InlineKeyboardButton(["text" => "📥 تصدير قائمة المشاركين", "callback_data" => "export_participants|$giveaway_id"])],
        [new InlineKeyboardButton(["text" => "🔙 رجوع", "callback_data" => "back_to_giveaway|$giveaway_id"])]
    ];
    
    return Request::editMessageText([
        'chat_id' => $callback_query->getMessage()->getChat()->getId(),
        'message_id' => $callback_query->getMessage()->getMessageId(),
        'text' => $text,
        'reply_markup' => new InlineKeyboard($keyboard),
        'parse_mode' => 'Markdown'
    ]);
}

function edit_giveaway($telegram, $callback_query, $giveaway_id) {
    Request::answerCallbackQuery([
        'callback_query_id' => $callback_query->getId()
    ]);
    
    $giveaway = get_giveaway($giveaway_id);
    if (!$giveaway) {
        return Request::answerCallbackQuery([
            'callback_query_id' => $callback_query->getId(),
            'text' => 'السحب غير موجود.',
            'show_alert' => true
        ]);
    }
    
    $user_id = $callback_query->getFrom()->getId();
    $is_creator = ($giveaway['creator_id'] == $user_id);
    $is_channel_admin = is_user_admin($telegram, $user_id, $giveaway['channel_id']);
    
    if (!($is_creator || $is_channel_admin)) {
        return Request::answerCallbackQuery([
            'callback_query_id' => $callback_query->getId(),
            'text' => 'ليس لديك صلاحية لتعديل هذا السحب.',
            'show_alert' => true
        ]);
    }
    
    $keyboard = [
        [new InlineKeyboardButton(["text" => "✏️ تعديل العنوان", "callback_data" => "edit_title|$giveaway_id"])],
        [new InlineKeyboardButton(["text" => "📝 تعديل الوصف", "callback_data" => "edit_description|$giveaway_id"])],
        [new InlineKeyboardButton(["text" => "🎯 تعديل عدد الفائزين", "callback_data" => "edit_winners|$giveaway_id"])],
        [new InlineKeyboardButton(["text" => "📌 تعديل الشروط", "callback_data" => "edit_conditions|$giveaway_id"])],
        [new InlineKeyboardButton(["text" => "⏰ تعديل وقت الانتهاء", "callback_data" => "edit_end_time|$giveaway_id"])],
        [new InlineKeyboardButton(["text" => "🔙 رجوع", "callback_data" => "back_to_giveaway|$giveaway_id"])]
    ];
    
    $text = "🔧 **تعديل السحب: {$giveaway['title']}** 🔧\n\n" .
            "🆔 **الرمز:** `$giveaway_id`\n" .
            "📊 **الحالة:** {$giveaway['status']}\n\n" .
            "اختر ما تريد تعديله:";
    
    return Request::editMessageText([
        'chat_id' => $callback_query->getMessage()->getChat()->getId(),
        'message_id' => $callback_query->getMessage()->getMessageId(),
        'text' => $text,
        'reply_markup' => new InlineKeyboard($keyboard),
        'parse_mode' => 'Markdown'
    ]);
}

function show_admin_panel($telegram, $callback_query) {
    Request::answerCallbackQuery([
        'callback_query_id' => $callback_query->getId()
    ]);
    
    $total_giveaways = count(get_all_giveaways());
    $active_giveaways = count(get_all_giveaways('active'));
    $finished_giveaways = count(get_all_giveaways('finished'));
    $banned_users_count = count(get_banned_users());
    
    $text = "🔧 **لوحة تحكم المالك** 🔧\n\n" .
            "📊 **إحصائيات سريعة:**\n" .
            "• إجمالي السحوبات: $total_giveaways\n" .
            "• سحوبات نشطة: $active_giveaways\n" .
            "• سحوبات منتهية: $finished_giveaways\n" .
            "• مستخدمون محظورون: $banned_users_count\n\n" .
            "اختر قائمة لعرضها:";
    
    $keyboard = [
        [new InlineKeyboardButton(["text" => "🟢 السحوبات النشطة", "callback_data" => "admin_list_active"])],
        [new InlineKeyboardButton(["text" => "🟡 السحوبات الموقوفة", "callback_data" => "admin_list_paused"])],
        [new InlineKeyboardButton(["text" => "🔴 السحوبات المنتهية", "callback_data" => "admin_list_finished"])],
        [new InlineKeyboardButton(["text" => "🚫 المستخدمون المحظورون", "callback_data" => "admin_view_banned"])],
    ];
    
    return Request::editMessageText([
        'chat_id' => $callback_query->getMessage()->getChat()->getId(),
        'message_id' => $callback_query->getMessage()->getMessageId(),
        'text' => $text,
        'reply_markup' => new InlineKeyboard($keyboard)
    ]);
}

function show_admin_list($telegram, $callback_query, $status) {
    Request::answerCallbackQuery([
        'callback_query_id' => $callback_query->getId()
    ]);
    
    $giveaways = get_all_giveaways($status);
    $status_ar = ['active' => 'النشطة', 'paused' => 'الموقوفة', 'finished' => 'المنتهية'];
    
    if (empty($giveaways)) {
        $text = "لا توجد سحوبات {$status_ar[$status]}.";
        $keyboard = [[new InlineKeyboardButton(["text" => "🔙 رجوع", "callback_data" => "admin_back"])]];
        return Request::editMessageText([
            'chat_id' => $callback_query->getMessage()->getChat()->getId(),
            'message_id' => $callback_query->getMessage()->getMessageId(),
            'text' => $text,
            'reply_markup' => new InlineKeyboard($keyboard)
        ]);
    }
    
    $text = "📋 **قائمة السحوبات {$status_ar[$status]}:**\n\n";
    $keyboard = [];
    
    foreach ($giveaways as $gw) {
        $participants_count = get_participants_count($gw['giveaway_id']);
        $text .= "• {$gw['title']}\n" .
                 "  🔹 الرمز: `{$gw['giveaway_id']}`\n" .
                 "  🔹 المشاركون: $participants_count\n\n";
        
        if ($status == 'finished') {
            $keyboard[] = [new InlineKeyboardButton(["text" => "🗑️ حذف '{$gw['title']}'", "callback_data" => "admin_delete|{$gw['giveaway_id']}"])];
        }
    }
    
    $keyboard[] = [new InlineKeyboardButton(["text" => "🔙 رجوع", "callback_data" => "admin_back"])];
    
    if (strlen($text) > 4096) {
        $text = substr($text, 0, 4090) . "...";
    }
    
    return Request::editMessageText([
        'chat_id' => $callback_query->getMessage()->getChat()->getId(),
        'message_id' => $callback_query->getMessage()->getMessageId(),
        'text' => $text,
        'reply_markup' => new InlineKeyboard($keyboard)
    ]);
}

function show_banned_users($telegram, $callback_query) {
    Request::answerCallbackQuery([
        'callback_query_id' => $callback_query->getId()
    ]);
    
    $banned_users = get_banned_users();
    
    if (empty($banned_users)) {
        $text = "لا يوجد مستخدمون محظورون.";
        $keyboard = [[new InlineKeyboardButton(["text" => "🔙 رجوع", "callback_data" => "admin_back"])]];
        return Request::editMessageText([
            'chat_id' => $callback_query->getMessage()->getChat()->getId(),
            'message_id' => $callback_query->getMessage()->getMessageId(),
            'text' => $text,
            'reply_markup' => new InlineKeyboard($keyboard)
        ]);
    }
    
    $text = "🚫 **قائمة المستخدمين المحظورين:** 🚫\n\n";
    $keyboard = [];
    
    foreach ($banned_users as $user) {
        $user_id = $user['user_id'];
        $reason = $user['reason'] ?: "لم يتم تحديد سبب";
        $banned_at = (new DateTime($user['banned_at']))->format('Y-m-d H:i');
        $text .= "• المستخدم: $user_id\n" .
                 "  🔹 السبب: $reason\n" .
                 "  🔹 تاريخ الحظر: $banned_at\n\n";
        
        $keyboard[] = [new InlineKeyboardButton(["text" => "🔓 إلغاء حظر $user_id", "callback_data" => "admin_unban_user|$user_id"])];
    }
    
    $keyboard[] = [new InlineKeyboardButton(["text" => "🔙 رجوع", "callback_data" => "admin_back"])];
    
    if (strlen($text) > 4096) {
        $text = substr($text, 0, 4090) . "...";
    }
    
    return Request::editMessageText([
        'chat_id' => $callback_query->getMessage()->getChat()->getId(),
        'message_id' => $callback_query->getMessage()->getMessageId(),
        'text' => $text,
        'reply_markup' => new InlineKeyboard($keyboard)
    ]);
}

function handle_captcha_answer($telegram, $message) {
    $user_answer = $message->getText();
    $correct_code = $_SESSION['captcha_code'];
    $giveaway_id = $_SESSION['captcha_giveaway_id'];
    $user_id = $message->getFrom()->getId();
    $username = $message->getFrom()->getUsername();
    
    if (!$giveaway_id || !$correct_code) {
        return Request::sendMessage([
            'chat_id' => $message->getChat()->getId(),
            'text' => 'حدث خطأ، يرجى المحاولة مرة أخرى.'
        ]);
    }
    
    // Make comparison case insensitive
    if (strtoupper(trim($user_answer)) == strtoupper($correct_code)) {
        add_participant($giveaway_id, $user_id, $username);
        reset_captcha_attempts($giveaway_id, $user_id);
        
        Request::sendMessage([
            'chat_id' => $message->getChat()->getId(),
            'text' => '✅ تمت مشاركتك بنجاح! حظاً موفقاً 🍀'
        ]);
        
        update_giveaway_message($telegram, $giveaway_id);
        
        $giveaway = get_giveaway($giveaway_id);
        if ($giveaway['end_type'] == 'participants' && get_participants_count($giveaway_id) >= (int)$giveaway['end_value']) {
            perform_giveaway_draw($telegram, $giveaway_id);
        }
        
        unset($_SESSION['captcha_code']);
        unset($_SESSION['captcha_giveaway_id']);
    } else {
        increment_captcha_attempt($giveaway_id, $user_id);
        $attempts = get_captcha_attempts($giveaway_id, $user_id);
        $remaining_attempts = 3 - $attempts;
        
        if ($remaining_attempts > 0) {
            Request::sendMessage([
                'chat_id' => $message->getChat()->getId(),
                'text' => "❌ رمز خاطئ. لديك $remaining_attempts محاولات متبقية."
            ]);
        } else {
            Request::sendMessage([
                'chat_id' => $message->getChat()->getId(),
                'text' => '❌ لقد فشلت في حل الكابتشا 3 مرات. تم استبعادك من هذا السحب.'
            ]);
            unset($_SESSION['captcha_code']);
            unset($_SESSION['captcha_giveaway_id']);
        }
    }
}

function handle_unparticipate($telegram, $message, $giveaway_id) {
    $giveaway = get_giveaway($giveaway_id);
    $user_id = $message->getFrom()->getId();
    
    if (!$giveaway) {
        return Request::sendMessage([
            'chat_id' => $message->getChat()->getId(),
            'text' => 'لم يتم العثور على سحب بهذا الرمز.'
        ]);
    }
    
    if ($giveaway['status'] != 'active') {
        return Request::sendMessage([
            'chat_id' => $message->getChat()->getId(),
            'text' => 'لا يمكن إلغاء المشاركة في سحب غير نشط.'
        ]);
    }
    
    $participants = get_participants($giveaway_id);
    $is_participant = false;
    foreach ($participants as $participant) {
        if ($participant['user_id'] == $user_id) {
            $is_participant = true;
            break;
        }
    }
    
    if (!$is_participant) {
        return Request::sendMessage([
            'chat_id' => $message->getChat()->getId(),
            'text' => 'أنت لم تكن مشاركًا في هذا السحب أصلاً.'
        ]);
    }
    
    remove_participant($giveaway_id, $user_id);
    
    Request::sendMessage([
        'chat_id' => $message->getChat()->getId(),
        'text' => '✅ تم إلغاء مشاركتك بنجاح.'
    ]);
    
    update_giveaway_message($telegram, $giveaway_id);
}

function handle_hash_verification($telegram, $message) {
    $giveaway_id = $message->getText();
    $giveaway = get_giveaway($giveaway_id);
    
    if (!$giveaway) {
        return Request::sendMessage([
            'chat_id' => $message->getChat()->getId(),
            'text' => 'لم يتم العثور على سحب بهذا الرمز.'
        ]);
    }
    
    // Increment view count
    increment_view_count($giveaway_id);
    
    $status_text = ['active' => 'نشط', 'paused' => 'موقف مؤقتًا', 'finished' => 'منتهي'];
    $text = "🔍 **حالة السحب:**\n\n" .
            "🆔 **الرمز:** `$giveaway_id`\n" .
            "📢 **الجائزة:** {$giveaway['title']}\n";
    
    if (!empty($giveaway['description'])) {
        $text .= "📝 **الوصف:** {$giveaway['description']}\n";
    }
    
    $text .= "📊 **الحالة:** {$status_text[$giveaway['status']]}\n" .
             "👥 **المشاركون:** " . get_participants_count($giveaway_id) . "\n";
    
    // Add view and click stats
    $stats = get_giveaway_stats($giveaway_id);
    $text .= "👀 **المشاهدات:** {$stats['views']}\n" .
             "👆 **النقرات على زر المشاركة:** {$stats['clicks']}\n";
    
    if ($giveaway['status'] == 'finished') {
        $winner_ids = get_winners($giveaway_id);
        if (!empty($winner_ids)) {
            $text .= "\n**🏆 قائمة الفائزين:**\n";
            foreach ($winner_ids as $i => $winner) {
                try {
                    $response = Request::getChat(['chat_id' => $winner['user_id']]);
                    if ($response->isOk()) {
                        $user = $response->getResult();
                        $username = $user->getUsername() ? "@{$user->getUsername()}" : $user->getFirstName();
                        $text .= ($i + 1) . ". [$username](tg://user?id={$winner['user_id']})\n";
                    } else {
                        $text .= ($i + 1) . ". مستخدم غير متوفر (ID: {$winner['user_id']})\n";
                    }
                } catch (Exception $e) {
                    $text .= ($i + 1) . ". مستخدم غير متوفر (ID: {$winner['user_id']})\n";
                }
            }
        } else {
            $text .= "\nلم يتم اختيار أي فائزين.";
        }
    } elseif ($giveaway['status'] == 'active') {
        // Add participation link
        global $BOT_USERNAME;
        $start_link = "https://t.me/$BOT_USERNAME?start=$giveaway_id";
        $text .= "\n[🎉 للمشاركة اضغط هنا]($start_link)";
        $text .= "\n\nلإلغاء مشاركتك، أرسل:\n`إلغاء $giveaway_id`";
    }
    
    return Request::sendMessage([
        'chat_id' => $message->getChat()->getId(),
        'text' => $text,
        'parse_mode' => 'Markdown'
    ]);
}

// Start command handler
class StartCommand extends UserCommand {
    protected $name = 'start';
    protected $description = 'Start command';
    protected $usage = '/start';
    protected $version = '1.0.0';

    public function execute() {
        $message = $this->getMessage();
        $chat_id = $message->getChat()->getId();
        $user = $message->getFrom();
        $args = $this->getArguments();
        
        // Check if there are parameters in the start command
        if (!empty($args)) {
            $giveaway_id = $args;
            $giveaway = get_giveaway($giveaway_id);
            
            if ($giveaway && $giveaway['status'] == 'active') {
                // Check if user is banned
                if (is_user_banned($user->getId())) {
                    return Request::sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "❌ تم حظرك من المشاركة في السحوبات."
                    ]);
                }
                
                // Check if user has a username
                if (!$user->getUsername()) {
                    return Request::sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "❌ يجب أن يكون لديك اسم مستخدم (Username) للمشاركة في السحب.\n\n" .
                                 "يرجى تعيين اسم مستخدم في إعدادات تيليجرام ثم العودة للمحاولة."
                    ]);
                }
                
                // Check max participants
                $participants_count = get_participants_count($giveaway_id);
                if ($participants_count >= $MAX_PARTICIPANTS_PER_GIVEAWAY) {
                    return Request::sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "❌ وصل السحب إلى الحد الأقصى للمشاركين."
                    ]);
                }
                
                // Check captcha attempts
                $attempts = get_captcha_attempts($giveaway_id, $user->getId());
                if ($attempts >= 3) {
                    return Request::sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "❌ لقد استهلكت كل محاولاتك لحل الكابتشا في هذا السحب."
                    ]);
                }
                
                // Check subscription to required channels
                $required_channels = [];
                if (!empty($giveaway['conditions'])) {
                    $required_channels = explode(',', $giveaway['conditions']);
                }
                
                if (!empty($required_channels)) {
                    list($is_subscribed, $error_msg) = check_subscription($this->telegram, $user->getId(), $required_channels);
                    if (!$is_subscribed) {
                        $keyboard = [];
                        foreach ($required_channels as $channel) {
                            $keyboard[] = [new InlineKeyboardButton(["text" => "الاشتراك في @$channel", "url" => "https://t.me/$channel"])];
                        }
                        
                        return Request::sendMessage([
                            'chat_id' => $chat_id,
                            'text' => "❌ $error_msg\n\n" .
                                     "يرجى الاشتراك في جميع القنوات المطلوبة ثم العودة للمحاولة.",
                            'reply_markup' => new InlineKeyboard($keyboard)
                        ]);
                    }
                }
                
                // Send captcha
                list($image_bytes, $correct_code) = generate_captcha_image_and_code();
                
                // Store captcha data in session or database
                $_SESSION['captcha_code'] = $correct_code;
                $_SESSION['captcha_giveaway_id'] = $giveaway_id;
                
                return Request::sendPhoto([
                    'chat_id' => $chat_id,
                    'photo' => $image_bytes,
                    'caption' => "أدخل الرمز الظاهر في الصورة (لديك 3 محاولات فقط).\n\n" .
                                "ملاحظة: الرمز غير حساس لحالة الأحرف (ABC = abc)."
                ]);
            } else {
                return Request::sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ هذا السحب غير نشط أو غير موجود.\n\n" .
                             "يمكنك البحث عن سحوبات أخرى نشطة."
                ]);
            }
        }
        
        // If no parameter or invalid giveaway - show main menu
        $keyboard = [
            [new InlineKeyboardButton(["text" => "🎉 إنشاء سحب جديد", "callback_data" => "create_giveaway"])]
        ];
        
        if ($user->getId() == $ADMIN_ID) {
            $keyboard[] = [new InlineKeyboardButton(["text" => "🔧 لوحة التحكم (للمالك)", "callback_data" => "admin_panel"])];
        }

        return Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => "أهلاً بك {$user->getFirstName()}! 👋\n\n" .
                     "للتحقق من أي سحب، أرسل رمز التحقق (Hash) الخاص به.\n" .
                     "لإلغاء مشاركتك، أرسل رمز السحب مع كلمة `إلغاء`.\n\n" .
                     "اختر ما تريد القيام به:",
            'reply_markup' => new InlineKeyboard($keyboard)
        ]);
    }
}

// Make sure to add session_start() at the beginning of your script if you're using sessions
session_start();
?>
