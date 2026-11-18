<?php

/**
 * Manual user verification for MirzaBot.
 *
 * This module intentionally keeps the verification workflow separate from the
 * existing user table and purchase code. The user_verification table has one
 * row per Telegram user, so retries update the same request instead of creating
 * duplicate records.
 */

const VERIFICATION_STATUS_NOT_STARTED = 'not_started';
const VERIFICATION_STATUS_PENDING = 'pending';
const VERIFICATION_STATUS_APPROVED = 'approved';
const VERIFICATION_STATUS_REJECTED = 'rejected';

const VERIFICATION_USER_BUTTON = '🪪 احراز هویت';
const VERIFICATION_ADMIN_BUTTON = '🪪 احراز هویت کاربران';

function migrateUserVerification(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_verification (
            user_id VARCHAR(64) NOT NULL PRIMARY KEY,
            status VARCHAR(20) NOT NULL DEFAULT 'not_started',
            full_name VARCHAR(150) NULL,
            submitted_at DATETIME NULL,
            reviewed_at DATETIME NULL,
            reviewed_by VARCHAR(64) NULL,
            admin_note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_verification_status (status),
            INDEX idx_user_verification_submitted (submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'setting'
           AND COLUMN_NAME = 'verification_required'"
    );
    $stmt->execute();
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE setting ADD verification_required VARCHAR(20) NOT NULL DEFAULT '0'");
    }
}

function verificationIsRequired(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SELECT verification_required FROM setting LIMIT 1");
        $value = $stmt ? $stmt->fetchColumn() : '0';
        return in_array((string) $value, ['1', 'on', 'enabled'], true);
    } catch (Throwable $e) {
        error_log('Unable to read verification setting: ' . $e->getMessage());
        return false;
    }
}

function verificationStatus(PDO $pdo, $userId): string
{
    try {
        $stmt = $pdo->prepare('SELECT status FROM user_verification WHERE user_id = ? LIMIT 1');
        $stmt->execute([(string) $userId]);
        $status = $stmt->fetchColumn();
        $allowed = [
            VERIFICATION_STATUS_NOT_STARTED,
            VERIFICATION_STATUS_PENDING,
            VERIFICATION_STATUS_APPROVED,
            VERIFICATION_STATUS_REJECTED,
        ];
        return in_array($status, $allowed, true) ? $status : VERIFICATION_STATUS_NOT_STARTED;
    } catch (Throwable $e) {
        error_log('Unable to read user verification status: ' . $e->getMessage());
        return VERIFICATION_STATUS_NOT_STARTED;
    }
}

/** Re-reads both the feature flag and status from the database. */
function isUserVerified(PDO $pdo, $userId): bool
{
    if (!verificationIsRequired($pdo)) {
        return true;
    }
    return verificationStatus($pdo, $userId) === VERIFICATION_STATUS_APPROVED;
}

function verificationIsAdministrator(PDO $pdo, $userId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM admin WHERE id_admin = ? AND rule = 'administrator' LIMIT 1");
    $stmt->execute([(string) $userId]);
    return (bool) $stmt->fetchColumn();
}

function verificationEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function verificationGateKeyboard(): string
{
    return json_encode([
        'keyboard' => [
            [['text' => VERIFICATION_USER_BUTTON]],
        ],
        'resize_keyboard' => true,
    ], JSON_UNESCAPED_UNICODE);
}

function verificationContactKeyboard(): string
{
    return json_encode([
        'keyboard' => [
            [['text' => '📱 ارسال شماره موبایل', 'request_contact' => true]],
            [['text' => '❌ انصراف']],
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => true,
    ], JSON_UNESCAPED_UNICODE);
}

function verificationSummaryKeyboard(): string
{
    return json_encode([
        'inline_keyboard' => [
            [['text' => '✅ ارسال برای بررسی', 'callback_data' => 'verification:submit']],
            [['text' => '✏️ ویرایش اطلاعات', 'callback_data' => 'verification:edit']],
            [['text' => '❌ انصراف', 'callback_data' => 'verification:cancel']],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function verificationShowGate($userId, string $status): void
{
    if ($status === VERIFICATION_STATUS_PENDING) {
        $message = '⏳ درخواست احراز هویت شما ثبت شده و در انتظار بررسی مدیریت است.';
    } elseif ($status === VERIFICATION_STATUS_REJECTED) {
        $message = "❌ درخواست قبلی شما تأیید نشد.\nبرای ثبت دوباره درخواست، دکمه زیر را بزنید.";
    } else {
        $message = "برای استفاده از امکانات خرید و ساخت سرویس، ابتدا باید توسط مدیریت تأیید شوید.";
    }
    sendmessage($userId, $message, verificationGateKeyboard(), 'HTML');
}

function verificationNormalizePhone($phone): ?string
{
    $phone = trim((string) $phone);
    $phone = str_replace([' ', '-', '(', ')'], '', $phone);
    if (str_starts_with($phone, '+98')) {
        $phone = '0' . substr($phone, 3);
    } elseif (str_starts_with($phone, '0098')) {
        $phone = '0' . substr($phone, 4);
    } elseif (preg_match('/^98(9\d{9})$/', $phone, $match)) {
        $phone = '0' . $match[1];
    }
    return preg_match('/^09\d{9}$/', $phone) ? $phone : null;
}

function verificationEnsureDraft(PDO $pdo, $userId): array
{
    $stmt = $pdo->prepare(
        "INSERT INTO user_verification (user_id, status)
         VALUES (?, 'not_started')
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)"
    );
    $stmt->execute([(string) $userId]);
    $stmt = $pdo->prepare('SELECT * FROM user_verification WHERE user_id = ? LIMIT 1');
    $stmt->execute([(string) $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function verificationBegin(PDO $pdo, $userId): void
{
    $request = verificationEnsureDraft($pdo, $userId);
    if (($request['status'] ?? '') === VERIFICATION_STATUS_PENDING) {
        verificationShowGate($userId, VERIFICATION_STATUS_PENDING);
        return;
    }
    if (($request['status'] ?? '') === VERIFICATION_STATUS_APPROVED) {
        sendmessage($userId, '✅ احراز هویت شما قبلاً تأیید شده است.', null, 'HTML');
        return;
    }
    step('verification_full_name', $userId);
    sendmessage(
        $userId,
        'لطفاً نام و نام خانوادگی خود را ارسال کنید.',
        json_encode([
            'keyboard' => [[['text' => '❌ انصراف']]],
            'resize_keyboard' => true,
        ], JSON_UNESCAPED_UNICODE),
        'HTML'
    );
}

function verificationShowSummary(PDO $pdo, $userId): void
{
    $stmt = $pdo->prepare(
        'SELECT v.full_name, u.number AS phone FROM user_verification v LEFT JOIN user u ON u.id = v.user_id WHERE v.user_id = ? LIMIT 1'
    );
    $stmt->execute([(string) $userId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request || empty($request['full_name']) || verificationNormalizePhone($request['phone'] ?? '') === null) {
        verificationBegin($pdo, $userId);
        return;
    }
    $message = "نام و نام خانوادگی:\n<b>" . verificationEscape($request['full_name']) .
        "</b>\n\nشماره موبایل:\n<code>" . verificationEscape($request['phone']) .
        "</code>\n\nآیا اطلاعات بالا صحیح است؟";
    sendmessage($userId, $message, verificationSummaryKeyboard(), 'HTML');
}

function verificationBuildMainKeyboard(PDO $pdo, $userId): string
{
    $setting = select('setting', '*', null, null, 'select');
    $target = select('user', '*', 'id', $userId, 'select');
    $lang = is_array($target) ? ($target['lang'] ?? 'fa') : 'fa';
    if (!in_array($lang, ['fa', 'en', 'ru', 'zh'], true)) {
        $lang = 'fa';
    }
    $texts = require __DIR__ . '/lang/' . $lang . '.php';
    if (is_array($texts)) {
        bottext_apply_overrides($texts, $lang);
    }
    $replacements = [
        'text_usertest' => $texts['textbot']['userTest'],
        'text_Purchased_services' => $texts['textbot']['purchasedServices'],
        'text_support' => $texts['textbot']['support'],
        'text_help' => $texts['textbot']['help'],
        'accountwallet' => $texts['textbot']['accountWallet'],
        'text_sell' => $texts['textbot']['sell'],
        'text_Tariff_list' => $texts['textbot']['tariffList'],
        'text_affiliates' => $texts['textbot']['affiliates'],
        'text_wheel_luck' => $texts['textbot']['wheelLuck'],
        'text_extend' => $texts['textbot']['extend'],
    ];
    $layout = json_decode($setting['keyboardmain'] ?? '', true);
    $rows = is_array($layout['keyboard'] ?? null) ? $layout['keyboard'] : [];
    $extra = [];
    if (($target['agent'] ?? 'f') !== 'f') {
        $extra[] = ['text' => $texts['textbot']['agentPanel']];
    } elseif (($setting['statusagentrequest'] ?? '') === 'onrequestagent') {
        $extra[] = ['text' => $texts['textbot']['requestAgent']];
    }
    if (($setting['inlinebtnmain'] ?? '') === 'oninline') {
        $callbacks = [
            'text_sell' => 'buy', 'accountwallet' => 'account', 'text_Tariff_list' => 'Tariff_list',
            'text_wheel_luck' => 'wheel_luck', 'text_affiliates' => 'affiliatesbtn',
            'text_extend' => 'extendbtn', 'text_support' => 'supportbtns',
            'text_Purchased_services' => 'backorder', 'text_help' => 'helpbtns',
            'text_usertest' => 'usertestbtn',
        ];
        foreach ($rows as &$row) {
            foreach ($row as &$button) {
                if (isset($callbacks[$button['text'] ?? ''])) {
                    $button['callback_data'] = $callbacks[$button['text']];
                }
            }
        }
        unset($row, $button);
        $rows[] = $extra;
        return json_encode([
            'inline_keyboard' => json_decode(strtr(json_encode($rows), $replacements), true),
        ], JSON_UNESCAPED_UNICODE);
    }
    $rows[] = $extra;
    return json_encode([
        'keyboard' => json_decode(strtr(json_encode($rows), $replacements), true),
        'resize_keyboard' => true,
    ], JSON_UNESCAPED_UNICODE);
}

function verificationNotifyAdministrators(PDO $pdo, array $request, string $username): void
{
    $stmt = $pdo->query("SELECT id_admin FROM admin WHERE rule = 'administrator'");
    $admins = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $usernameText = $username !== '' && $username !== 'NOT_USERNAME'
        ? '@' . ltrim(verificationEscape($username), '@')
        : 'ندارد';
    $submitted = !empty($request['submitted_at']) ? $request['submitted_at'] : date('Y-m-d H:i:s');
    $message = "<b>درخواست جدید احراز هویت</b>\n\n" .
        "نام: " . verificationEscape($request['full_name'] ?? '') . "\n" .
        "شماره: <code>" . verificationEscape($request['phone'] ?? '') . "</code>\n" .
        "Telegram ID: <code>" . verificationEscape($request['user_id'] ?? '') . "</code>\n" .
        "Username: " . $usernameText . "\n" .
        "تاریخ: " . verificationEscape($submitted);
    $keyboard = json_encode([
        'inline_keyboard' => [[
            ['text' => '✅ تأیید کاربر', 'callback_data' => 'vact:approve:' . $request['user_id']],
            ['text' => '❌ رد درخواست', 'callback_data' => 'vact:reject:' . $request['user_id']],
        ]],
    ], JSON_UNESCAPED_UNICODE);
    foreach ($admins as $adminId) {
        sendmessage($adminId, $message, $keyboard, 'HTML');
    }
}

function verificationSubmit(PDO $pdo, $userId, string $username): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT v.*, u.number AS phone FROM user_verification v LEFT JOIN user u ON u.id = v.user_id WHERE v.user_id = ? FOR UPDATE'
        );
        $stmt->execute([(string) $userId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request || empty($request['full_name']) || verificationNormalizePhone($request['phone'] ?? '') === null) {
            $pdo->rollBack();
            verificationBegin($pdo, $userId);
            return;
        }
        if ($request['status'] === VERIFICATION_STATUS_PENDING) {
            $pdo->rollBack();
            verificationShowGate($userId, VERIFICATION_STATUS_PENDING);
            return;
        }
        if ($request['status'] === VERIFICATION_STATUS_APPROVED) {
            $pdo->rollBack();
            sendmessage($userId, '✅ احراز هویت شما قبلاً تأیید شده است.', null, 'HTML');
            return;
        }
        $stmt = $pdo->prepare(
            "UPDATE user_verification
             SET status = 'pending', submitted_at = NOW(), reviewed_at = NULL,
                 reviewed_by = NULL, admin_note = NULL
             WHERE user_id = ? AND status IN ('not_started', 'rejected')"
        );
        $stmt->execute([(string) $userId]);
        $stmt = $pdo->prepare(
            'SELECT v.*, u.number AS phone FROM user_verification v LEFT JOIN user u ON u.id = v.user_id WHERE v.user_id = ?'
        );
        $stmt->execute([(string) $userId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    step('home', $userId);
    sendmessage(
        $userId,
        '⏳ درخواست احراز هویت شما ثبت شد و در انتظار بررسی مدیریت است.',
        verificationGateKeyboard(),
        'HTML'
    );
    verificationNotifyAdministrators($pdo, $request, $username);
}

function verificationAdminHome(PDO $pdo, $adminId): void
{
    $counts = [VERIFICATION_STATUS_PENDING => 0, VERIFICATION_STATUS_APPROVED => 0, VERIFICATION_STATUS_REJECTED => 0];
    $stmt = $pdo->query('SELECT status, COUNT(*) AS total FROM user_verification GROUP BY status');
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        if (array_key_exists($row['status'], $counts)) {
            $counts[$row['status']] = (int) $row['total'];
        }
    }
    $required = verificationIsRequired($pdo);
    $message = "<b>🪪 احراز هویت کاربران</b>\n\n" .
        "⏳ در انتظار: {$counts[VERIFICATION_STATUS_PENDING]}\n" .
        "✅ تأیید شده: {$counts[VERIFICATION_STATUS_APPROVED]}\n" .
        "❌ رد شده: {$counts[VERIFICATION_STATUS_REJECTED]}";
    $keyboard = json_encode([
        'inline_keyboard' => [
            [['text' => '⏳ درخواست‌های در انتظار', 'callback_data' => 'vlist:pending:0']],
            [['text' => '✅ کاربران تأیید شده', 'callback_data' => 'vlist:approved:0']],
            [['text' => '❌ کاربران رد شده', 'callback_data' => 'vlist:rejected:0']],
            [[
                'text' => 'احراز هویت اجباری: ' . ($required ? '✅ فعال' : '❌ غیرفعال'),
                'callback_data' => 'vtoggle',
            ]],
        ],
    ], JSON_UNESCAPED_UNICODE);
    sendmessage($adminId, $message, $keyboard, 'HTML');
}

function verificationAdminList(PDO $pdo, $adminId, string $status, int $page, int $messageId = 0): void
{
    $allowed = [VERIFICATION_STATUS_PENDING, VERIFICATION_STATUS_APPROVED, VERIFICATION_STATUS_REJECTED];
    if (!in_array($status, $allowed, true)) {
        return;
    }
    $page = max(0, $page);
    $limit = 8;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_verification WHERE status = ?');
    $stmt->execute([$status]);
    $total = (int) $stmt->fetchColumn();
    $maxPage = max(0, (int) ceil($total / $limit) - 1);
    $page = min($page, $maxPage);
    $stmt = $pdo->prepare(
        'SELECT v.user_id, v.full_name, u.number AS phone FROM user_verification v LEFT JOIN user u ON u.id = v.user_id WHERE v.status = ? ORDER BY COALESCE(v.submitted_at, v.updated_at) DESC LIMIT ? OFFSET ?'
    );
    $stmt->bindValue(1, $status);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->bindValue(3, $page * $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $buttons = [];
    foreach ($rows as $row) {
        $label = trim((string) $row['full_name']) ?: (string) $row['user_id'];
        $buttons[] = [[
            'text' => mb_substr($label, 0, 32) . ' | ' . $row['user_id'],
            'callback_data' => 'vshow:' . $status . ':' . $row['user_id'] . ':' . $page,
        ]];
    }
    if (!$rows) {
        $buttons[] = [['text' => 'موردی وجود ندارد', 'callback_data' => 'vnoop']];
    }
    $nav = [];
    if ($page > 0) {
        $nav[] = ['text' => '⬅️ قبلی', 'callback_data' => 'vlist:' . $status . ':' . ($page - 1)];
    }
    if ($page < $maxPage) {
        $nav[] = ['text' => 'بعدی ➡️', 'callback_data' => 'vlist:' . $status . ':' . ($page + 1)];
    }
    if ($nav) {
        $buttons[] = $nav;
    }
    $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'vadmin']];
    $titleMap = [
        VERIFICATION_STATUS_PENDING => 'درخواست‌های در انتظار',
        VERIFICATION_STATUS_APPROVED => 'کاربران تأیید شده',
        VERIFICATION_STATUS_REJECTED => 'کاربران رد شده',
    ];
    $message = '<b>' . $titleMap[$status] . '</b> — ' . $total . ' مورد';
    $keyboard = json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_UNICODE);
    if ($messageId > 0) {
        Editmessagetext($adminId, $messageId, $message, $keyboard, 'HTML');
    } else {
        sendmessage($adminId, $message, $keyboard, 'HTML');
    }
}

function verificationAdminDetail(PDO $pdo, $adminId, string $status, $targetId, int $page, int $messageId): void
{
    $stmt = $pdo->prepare(
        'SELECT v.*, u.username, u.number AS phone FROM user_verification v LEFT JOIN user u ON u.id = v.user_id WHERE v.user_id = ? LIMIT 1'
    );
    $stmt->execute([(string) $targetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        telegram('answerCallbackQuery', ['callback_query_id' => $GLOBALS['callback_query_id'] ?? '', 'text' => 'درخواست پیدا نشد.']);
        return;
    }
    $usernameText = !empty($row['username']) && $row['username'] !== 'NOT_USERNAME'
        ? '@' . ltrim(verificationEscape($row['username']), '@')
        : 'ندارد';
    $message = "<b>جزئیات احراز هویت</b>\n\n" .
        "نام: " . verificationEscape($row['full_name']) . "\n" .
        "شماره: <code>" . verificationEscape($row['phone']) . "</code>\n" .
        "Telegram ID: <code>" . verificationEscape($row['user_id']) . "</code>\n" .
        "Username: " . $usernameText . "\n" .
        "وضعیت: " . verificationEscape($row['status']) . "\n" .
        "ارسال: " . verificationEscape($row['submitted_at'] ?: '—') . "\n" .
        "بررسی: " . verificationEscape($row['reviewed_at'] ?: '—') . "\n" .
        "ادمین: " . verificationEscape($row['reviewed_by'] ?: '—') . "\n" .
        "توضیح: " . verificationEscape($row['admin_note'] ?: '—');
    $buttons = [];
    if ($row['status'] === VERIFICATION_STATUS_PENDING) {
        $buttons[] = [
            ['text' => '✅ تأیید', 'callback_data' => 'vact:approve:' . $row['user_id']],
            ['text' => '❌ رد', 'callback_data' => 'vact:reject:' . $row['user_id']],
        ];
    } elseif ($row['status'] === VERIFICATION_STATUS_APPROVED) {
        $buttons[] = [[
            'text' => '🚫 لغو احراز هویت',
            'callback_data' => 'vact:revoke:' . $row['user_id'],
        ]];
    }
    $buttons[] = [[
        'text' => '🔙 بازگشت',
        'callback_data' => 'vlist:' . $status . ':' . max(0, $page),
    ]];
    Editmessagetext($adminId, $messageId, $message, json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_UNICODE), 'HTML');
}

function verificationAdminAction(PDO $pdo, $adminId, string $action, $targetId): bool
{
    $expected = $action === 'revoke' ? VERIFICATION_STATUS_APPROVED : VERIFICATION_STATUS_PENDING;
    $next = $action === 'approve' ? VERIFICATION_STATUS_APPROVED : VERIFICATION_STATUS_REJECTED;
    $note = $action === 'approve' ? null : ($action === 'revoke' ? 'لغو احراز هویت توسط مدیریت' : 'رد درخواست توسط مدیریت');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT status FROM user_verification WHERE user_id = ? FOR UPDATE');
        $stmt->execute([(string) $targetId]);
        $current = $stmt->fetchColumn();
        if ($current !== $expected) {
            $pdo->rollBack();
            return false;
        }
        $stmt = $pdo->prepare(
            'UPDATE user_verification SET status = ?, reviewed_at = NOW(), reviewed_by = ?, admin_note = ? WHERE user_id = ? AND status = ?'
        );
        $stmt->execute([$next, (string) $adminId, $note, (string) $targetId, $expected]);
        $changed = $stmt->rowCount() === 1;
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    if (!$changed) {
        return false;
    }
    if ($action === 'approve') {
        sendmessage(
            $targetId,
            '✅ احراز هویت شما با موفقیت تأیید شد.',
            verificationBuildMainKeyboard($pdo, $targetId),
            'HTML'
        );
        step('home', $targetId);
    } elseif ($action === 'reject') {
        sendmessage(
            $targetId,
            "❌ درخواست احراز هویت شما توسط مدیریت تأیید نشد.\nمی‌توانید دوباره درخواست ارسال کنید.",
            verificationGateKeyboard(),
            'HTML'
        );
        step('home', $targetId);
    } else {
        sendmessage(
            $targetId,
            "🚫 تأیید احراز هویت شما توسط مدیریت لغو شد.\nسرویس‌های قبلی شما حذف نشده‌اند، اما عملیات خرید جدید تا تأیید مجدد مسدود است.",
            verificationGateKeyboard(),
            'HTML'
        );
        step('home', $targetId);
    }
    return true;
}

/**
 * Handles verification user/admin updates before MirzaBot's existing router.
 * Returns true when the update was fully handled and the caller must return.
 */
function handleVerificationUpdate(PDO $pdo, array $adminIds = [], ?string $mainKeyboard = null): bool
{
    global $from_id, $text, $datain, $user, $user_phone, $contact_id, $username, $message_id, $callback_query_id, $textbotlang;

    $isAdmin = in_array((string) $from_id, array_map('strval', $adminIds), true);
    $isAdministrator = $isAdmin && verificationIsAdministrator($pdo, $from_id);

    if ($text === VERIFICATION_ADMIN_BUTTON || $datain === 'vadmin') {
        if (!$isAdministrator) {
            return false;
        }
        if ($datain === 'vadmin' && $message_id) {
            deletemessage($from_id, $message_id);
        }
        verificationAdminHome($pdo, $from_id);
        return true;
    }
    if (preg_match('/^vlist:(pending|approved|rejected):(\d+)$/', $datain, $match)) {
        if (!$isAdministrator) {
            return true;
        }
        verificationAdminList($pdo, $from_id, $match[1], (int) $match[2], (int) $message_id);
        return true;
    }
    if (preg_match('/^vshow:(pending|approved|rejected):([1-9]\d{0,19}):(\d+)$/', $datain, $match)) {
        if (!$isAdministrator) {
            return true;
        }
        verificationAdminDetail($pdo, $from_id, $match[1], $match[2], (int) $match[3], (int) $message_id);
        return true;
    }
    if (preg_match('/^vact:(approve|reject|revoke):([1-9]\d{0,19})$/', $datain, $match)) {
        if (!$isAdministrator) {
            return true;
        }
        // Acknowledge the button immediately; sending notifications can otherwise
        // make Telegram expire the callback query before the response is returned.
        if ($callback_query_id) {
            telegram('answerCallbackQuery', [
                'callback_query_id' => $callback_query_id,
                'text' => 'در حال انجام عملیات...',
            ]);
        }
        $changed = verificationAdminAction($pdo, $from_id, $match[1], $match[2]);
        if ($message_id) {
            Editmessagetext(
                $from_id,
                $message_id,
                $changed
                    ? ($match[1] === 'approve' ? '✅ کاربر تأیید شد.' : ($match[1] === 'reject' ? '❌ درخواست رد شد.' : '🚫 احراز هویت لغو شد.'))
                    : '⚠️ این درخواست قبلاً بررسی شده یا دیگر در وضعیت انتظار نیست.',
                json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'vadmin']]]], JSON_UNESCAPED_UNICODE),
                'HTML'
            );
        }
        return true;
    }
    if ($datain === 'vtoggle') {
        if (!$isAdministrator) {
            return true;
        }
        $newValue = verificationIsRequired($pdo) ? '0' : '1';
        $stmt = $pdo->prepare('UPDATE setting SET verification_required = ?');
        $stmt->execute([$newValue]);
        clearSelectCache('setting');
        telegram('answerCallbackQuery', [
            'callback_query_id' => $callback_query_id,
            'text' => $newValue === '1' ? 'احراز هویت اجباری فعال شد.' : 'احراز هویت اجباری غیرفعال شد.',
        ]);
        if ($message_id) {
            deletemessage($from_id, $message_id);
        }
        verificationAdminHome($pdo, $from_id);
        return true;
    }
    if ($datain === 'vnoop') {
        if ($callback_query_id) {
            telegram('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
        }
        return true;
    }

    if (!verificationIsRequired($pdo) || $isAdmin) {
        if ($text === VERIFICATION_USER_BUTTON || str_starts_with($datain, 'verification:')) {
            sendmessage($from_id, 'احراز هویت اجباری در حال حاضر غیرفعال است.', $mainKeyboard, 'HTML');
            return true;
        }
        return false;
    }

    $status = verificationStatus($pdo, $from_id);
    $isStart = $text === '/start' || $text === 'start' || $datain === 'start' || str_starts_with($text, '/start ');
    if ($isStart && $status !== VERIFICATION_STATUS_APPROVED) {
        step('home', $from_id);
        verificationShowGate($from_id, $status);
        return true;
    }
    if ($text === VERIFICATION_USER_BUTTON || $datain === 'verification:start') {
        verificationBegin($pdo, $from_id);
        return true;
    }
    if ($text === '❌ انصراف' || $datain === 'verification:cancel') {
        if (str_starts_with((string) ($user['step'] ?? ''), 'verification_') || str_starts_with($datain, 'verification:')) {
            step('home', $from_id);
            verificationShowGate($from_id, verificationStatus($pdo, $from_id));
            return true;
        }
    }
    if (($user['step'] ?? '') === 'verification_full_name') {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $text));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 100 || !preg_match('/^[\p{L}\p{M} .\'\x{200C}-]+$/u', $name)) {
            sendmessage($from_id, 'نام و نام خانوادگی معتبر (۳ تا ۱۰۰ حرف) ارسال کنید.', null, 'HTML');
            return true;
        }
        verificationEnsureDraft($pdo, $from_id);
        $stmt = $pdo->prepare("UPDATE user_verification SET full_name = ? WHERE user_id = ? AND status IN ('not_started', 'rejected')");
        $stmt->execute([$name, (string) $from_id]);
        step('verification_phone', $from_id);
        sendmessage(
            $from_id,
            'برای حفظ امنیت، شماره موبایل خودتان را فقط با دکمه زیر ارسال کنید.',
            verificationContactKeyboard(),
            'HTML'
        );
        return true;
    }
    if (($user['step'] ?? '') === 'verification_phone') {
        if (!$user_phone) {
            sendmessage($from_id, 'لطفاً از دکمه «📱 ارسال شماره موبایل» استفاده کنید.', verificationContactKeyboard(), 'HTML');
            return true;
        }
        if ((string) $contact_id !== (string) $from_id) {
            sendmessage($from_id, '❌ این Contact متعلق به حساب Telegram شما نیست.', verificationContactKeyboard(), 'HTML');
            return true;
        }
        $phone = verificationNormalizePhone($user_phone);
        if ($phone === null) {
            sendmessage($from_id, '❌ شماره موبایل معتبر ایرانی ارسال کنید.', verificationContactKeyboard(), 'HTML');
            return true;
        }
        $stmt = $pdo->prepare('UPDATE user SET number = ? WHERE id = ?');
        $stmt->execute([$phone, (string) $from_id]);
        clearSelectCache('user');
        step('verification_review', $from_id);
        verificationShowSummary($pdo, $from_id);
        return true;
    }
    if ($datain === 'verification:edit') {
        verificationBegin($pdo, $from_id);
        return true;
    }
    if ($datain === 'verification:submit') {
        verificationSubmit($pdo, $from_id, (string) $username);
        return true;
    }

    // Rules remain available before approval, without exposing the main menu.
    $acceptRules = $datain === 'acceptrule'
        || (isset($textbotlang['keyboard']['acceptRules']) && $text === $textbotlang['keyboard']['acceptRules']);
    if ($acceptRules) {
        $stmt = $pdo->prepare('UPDATE user SET roll_Status = 1 WHERE id = ?');
        $stmt->execute([(string) $from_id]);
        clearSelectCache('user');
        verificationShowGate($from_id, $status);
        return true;
    }
    return false;
}

function verificationPublicActionAllowed(PDO $pdo, array $user, string $text, string $datain, array $texts): bool
{
    $publicTexts = ['/help', 'help', '/support'];
    foreach (['help', 'support'] as $key) {
        if (isset($texts['textbot'][$key])) {
            $publicTexts[] = $texts['textbot'][$key];
        }
    }
    if (in_array($text, $publicTexts, true)) {
        return true;
    }
    if (in_array($datain, ['helpbtn', 'helpbtns', 'supportbtns', 'support', 'fqQuestions', 'linkappdownlod'], true)
        || preg_match('/^(helpctg_|helpctgoryـ|helpos_|departman_)/u', $datain)) {
        return true;
    }
    $step = (string) ($user['step'] ?? '');
    if ($step === 'gettextticket') {
        return true;
    }
    if ($step === 'getextuserfors') {
        $tracking = (string) ($user['Processing_value'] ?? '');
        $stmt = $pdo->prepare('SELECT 1 FROM support_message WHERE Tracking = ? AND iduser = ? LIMIT 1');
        $stmt->execute([$tracking, (string) ($user['id'] ?? '')]);
        return (bool) $stmt->fetchColumn();
    }
    if (preg_match('/^Responsesusera_(\w+)$/', $datain, $match)) {
        $stmt = $pdo->prepare('SELECT 1 FROM support_message WHERE Tracking = ? AND iduser = ? LIMIT 1');
        $stmt->execute([$match[1], (string) ($user['id'] ?? '')]);
        return (bool) $stmt->fetchColumn();
    }
    return false;
}

/** Returns true when the caller must stop the existing Telegram router. */
function enforceUserVerificationGuard(PDO $pdo, array $user, array $adminIds, array $texts): bool
{
    global $from_id, $text, $datain, $update;
    if (!verificationIsRequired($pdo)
        || in_array((string) $from_id, array_map('strval', $adminIds), true)
        || verificationStatus($pdo, $from_id) === VERIFICATION_STATUS_APPROVED) {
        return false;
    }

    // A payment already captured by Telegram must still be settled. Creation
    // and pre-checkout are blocked separately; this only prevents lost funds.
    if (isset($update['message']['successful_payment'])) {
        return false;
    }
    if (isset($update['pre_checkout_query'])) {
        telegram('answerPreCheckoutQuery', [
            'pre_checkout_query_id' => $update['pre_checkout_query']['id'],
            'ok' => false,
            'error_message' => 'ابتدا احراز هویت خود را تکمیل کنید.',
        ]);
        return true;
    }
    if (verificationPublicActionAllowed($pdo, $user, (string) $text, (string) $datain, $texts)) {
        return false;
    }
    verificationShowGate($from_id, verificationStatus($pdo, $from_id));
    return true;
}

/** API guard for authenticated Mini App actions that create a service/order. */
function enforceUserVerificationApi(PDO $pdo, $userId): bool
{
    if (isUserVerified($pdo, $userId)) {
        return true;
    }
    http_response_code(403);
    echo json_encode([
        'status' => false,
        'success' => false,
        'msg' => 'verification_required',
        'message' => 'ابتدا احراز هویت خود را تکمیل کنید.',
    ], JSON_UNESCAPED_UNICODE);
    return false;
}

/** Guard used by generated agent bots; verification is completed in main bot. */
function enforceAgentBotVerificationGuard(PDO $pdo, array $user, array $agentAdminIds, string $mainBotUsername): bool
{
    global $from_id;
    if (!verificationIsRequired($pdo)
        || in_array((string) $from_id, array_map('strval', $agentAdminIds), true)
        || verificationStatus($pdo, $from_id) === VERIFICATION_STATUS_APPROVED) {
        return false;
    }
    $username = ltrim(trim($mainBotUsername), '@');
    $keyboard = $username !== ''
        ? json_encode([
            'inline_keyboard' => [[[
                'text' => VERIFICATION_USER_BUTTON,
                'url' => 'https://t.me/' . $username . '?start=verify',
            ]]],
        ], JSON_UNESCAPED_UNICODE)
        : null;
    sendmessage(
        $from_id,
        'برای استفاده از خرید و ساخت سرویس، ابتدا احراز هویت خود را در ربات اصلی تکمیل کنید.',
        $keyboard,
        'HTML'
    );
    return true;
}

?>
