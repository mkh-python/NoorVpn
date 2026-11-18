<?php
/** Report destination setup, independent of database and Telegram transport. */
function noorVpnReportLink(string $input): ?array
{
    $input = trim($input);
    if (preg_match('/^@[A-Za-z][A-Za-z0-9_]{3,31}$/D', $input)) {
        $input = 'https://t.me/' . substr($input, 1);
    } elseif (str_starts_with(strtolower($input), 't.me/')) {
        $input = 'https://' . $input;
    }
    $url = parse_url($input);
    if (!$url || !in_array(strtolower($url['scheme'] ?? ''), ['https','http'], true)
        || !in_array(strtolower($url['host'] ?? ''), ['t.me','telegram.me'], true)
        || isset($url['user']) || isset($url['pass']) || isset($url['port']) || isset($url['query']) || isset($url['fragment'])) { return null; }
    $path = trim($url['path'] ?? '', '/');
    if (preg_match('~^(?:\+|joinchat/)[A-Za-z0-9_-]{6,128}$~D', $path)) {
        return ['kind'=>'private','url'=>'https://t.me/'.$path,'username'=>null];
    }
    if (preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/D', $path)
        && !in_array(strtolower($path), ['joinchat','share','proxy','socks','login','addstickers','addlist'], true)) {
        return ['kind'=>'public','url'=>'https://t.me/'.$path,'username'=>'@'.$path];
    }
    return null;
}

function noorVpnPrepareReportDestination(string $link, string $chatId, string $botId, callable $api, array $topicNames, array $existingTopics = []): array
{
    $parsed = noorVpnReportLink($link);
    if (!$parsed) { return ['ok'=>false,'error'=>'invalid_link']; }
    $chatId = trim($chatId);
    if (!preg_match('/^-[1-9][0-9]{4,19}$/D', $chatId)) { return ['ok'=>false,'error'=>'invalid_id']; }
    $response = $api('getChat', ['chat_id'=>$chatId]);
    if (empty($response['ok'])) { return ['ok'=>false,'error'=>'chat_not_found']; }
    $chat = $response['result'] ?? [];
    if (!in_array($chat['type'] ?? '', ['channel','group','supergroup'], true)) { return ['ok'=>false,'error'=>'invalid_type']; }
    if ((string)($chat['id'] ?? '') !== $chatId) { return ['ok'=>false,'error'=>'invalid_id']; }
    if ($parsed['kind'] === 'public') {
        $names = array_merge([$chat['username'] ?? ''], $chat['active_usernames'] ?? []);
        $names = array_map('strtolower', $names);
        if (!in_array(strtolower(substr($parsed['username'], 1)), $names, true)) {
            $public = $api('getChat', ['chat_id'=>$parsed['username']]);
            if (empty($public['ok']) || (string)($public['result']['id'] ?? '') !== $chatId) { return ['ok'=>false,'error'=>'link_mismatch']; }
        }
    }
    $membership = $api('getChatMember', ['chat_id'=>$chatId,'user_id'=>$botId]);
    $member = $membership['result'] ?? [];
    if (empty($membership['ok']) || !in_array($member['status'] ?? '', ['creator','administrator'], true)) { return ['ok'=>false,'error'=>'not_admin']; }
    if ($chat['type'] === 'channel' && ($member['status'] ?? '') !== 'creator' && empty($member['can_post_messages'])) { return ['ok'=>false,'error'=>'cannot_post']; }
    $topics = array_fill_keys(array_keys($topicNames), 0);
    $created = [];
    if (!empty($chat['is_forum'])) {
        if (($member['status'] ?? '') !== 'creator' && empty($member['can_manage_topics'])) { return ['ok'=>false,'error'=>'cannot_manage_topics']; }
        $created = [];
        foreach ($topicNames as $name => $title) {
            if ((int)($existingTopics[$name] ?? 0) > 0) { $topics[$name] = (int)$existingTopics[$name]; continue; }
            $topic = $api('createForumTopic', ['chat_id'=>$chatId,'name'=>$title]);
            if (empty($topic['ok']) || empty($topic['result']['message_thread_id'])) {
                foreach ($created as $id) { $api('deleteForumTopic', ['chat_id'=>$chatId,'message_thread_id'=>$id]); }
                return ['ok'=>false,'error'=>'topic_failed'];
            }
            $topics[$name] = (int)$topic['result']['message_thread_id'];
            $created[] = $topics[$name];
        }
    }
    return ['ok'=>true,'chat_id'=>$chatId,'link'=>$parsed['url'],'topics'=>$topics,'title'=>(string)($chat['title'] ?? ''),'created_topics'=>$created];
}

/** Delete only topics created by this setup attempt, never reused/existing topics. */
function noorVpnDiscardReportTopics(array $result, callable $api): void
{
    foreach ($result['created_topics'] ?? [] as $id) {
        $api('deleteForumTopic', ['chat_id'=>$result['chat_id'],'message_thread_id'=>$id]);
    }
}

function noorVpnMigrateReportSetup(PDO $pdo): void
{
    $query = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='setting' AND COLUMN_NAME='report_channel_link'");
    if (!(int)$query->fetchColumn()) {
        $pdo->exec('ALTER TABLE setting ADD COLUMN report_channel_link VARCHAR(512) NULL');
    }
}

function noorVpnSaveReportSetup(PDO $pdo, array $result): void
{
    if (empty($result['ok'])) { throw new InvalidArgumentException('Cannot save an unverified report destination'); }
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('UPDATE topicid SET idreport=? WHERE report=?');
        foreach ($result['topics'] as $name => $id) { $statement->execute([(string)$id,$name]); }
        $statement = $pdo->prepare('UPDATE setting SET Channel_Report=?, report_channel_link=?');
        $statement->execute([$result['chat_id'],$result['link']]);
        $pdo->commit();
    } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
}

function noorVpnReportText(string $key, string $language = 'fa'): string
{
    $fa = [
        'link'=>'📣 ابتدا لینک کانال یا گروه گزارش‌ها را بفرستید.\n\nعمومی: https://t.me/channel_name\nخصوصی: https://t.me/+invite_link\n\nربات را در مقصد ادمین کنید؛ برای کانال، اجازهٔ ارسال پیام هم لازم است.',
        'id'=>'✅ لینک دریافت شد.\nاکنون آیدی عددی همان کانال یا گروه را بفرستید، مانند: <code>-1001234567890</code>\n\nلینک دعوت خصوصی باعث عضویت خودکار ربات نمی‌شود؛ ابتدا ربات را در همان مقصد ادمین کنید.',
        'invalid_link'=>'❌ لینک معتبر کانال یا گروه تلگرام را بفرستید؛ لینک یک پیام پذیرفته نمی‌شود.',
        'invalid_id'=>'❌ آیدی عددی معتبر مقصد را با علامت منفی بفرستید، مانند <code>-1001234567890</code>.',
        'chat_not_found'=>'❌ ربات به این آیدی دسترسی ندارد یا آیدی اشتباه است. ربات را در همان کانال/گروه اضافه و ادمین کنید، آیدی را بررسی کنید و دوباره بفرستید. لینک دعوت به‌تنهایی برای دسترسی ربات کافی نیست.',
        'link_mismatch'=>'❌ لینک عمومی و آیدی عددی مربوط به یک مقصد نیستند. دوباره «گزارشات ربات» را بزنید و لینک و آیدی همان مقصد را وارد کنید.',
        'not_admin'=>'❌ ربات هنوز در این کانال یا گروه ادمین نیست. آن را ادمین کنید و همین آیدی را دوباره بفرستید.',
        'cannot_post'=>'❌ اجازهٔ ارسال پیام برای ربات در کانال فعال نیست. این دسترسی را فعال کنید و آیدی را دوباره بفرستید.',
        'cannot_manage_topics'=>'❌ این گروه انجمن دارد؛ دسترسی مدیریت موضوع‌ها را برای ربات فعال کنید و آیدی را دوباره بفرستید.',
        'topic_failed'=>'❌ ساخت موضوع‌های گزارش کامل نشد. دسترسی مدیریت موضوع‌ها را بررسی و دوباره تلاش کنید. تنظیم قبلی گزارش‌ها حفظ شد.',
        'invalid_type'=>'❌ مقصد باید کانال یا گروه باشد؛ چت شخصی پذیرفته نمی‌شود.',
        'saved'=>'✅ مقصد گزارش‌ها ثبت شد. گزارش‌ها به همین کانال یا گروه ارسال می‌شوند.',
        'save_failed'=>'❌ ذخیرهٔ تنظیمات انجام نشد؛ تنظیم قبلی حفظ شد. دوباره تلاش کنید.',
    ];
    $en = [
        'link'=>'Send the public or private Telegram report channel/group link first. Add the bot as administrator; channels also require permission to post messages.',
        'id'=>'Link received. Now send the numeric ID of the same destination, e.g. <code>-1001234567890</code>. A private invite link does not automatically add the bot; add it as administrator first.',
        'invalid_link'=>'Send a valid Telegram channel/group link, not a message link.',
        'invalid_id'=>'Send a negative numeric chat ID, e.g. <code>-1001234567890</code>.',
        'chat_not_found'=>'The bot cannot access that chat or the ID is incorrect. Add the bot as administrator, check the ID and retry. An invite link alone is insufficient.',
        'link_mismatch'=>'The public link and numeric ID refer to different chats. Start report setup again.',
        'not_admin'=>'Make the bot an administrator, then resend the chat ID.',
        'cannot_post'=>'Enable the bot permission to post in this channel, then resend the ID.',
        'cannot_manage_topics'=>'Enable permission to manage forum topics, then resend the ID.',
        'topic_failed'=>'Topic creation failed. Check permissions and retry. Previous settings were preserved.',
        'invalid_type'=>'The destination must be a channel or group, not a private user chat.',
        'saved'=>'Report destination saved successfully.',
        'save_failed'=>'Unable to save. Previous settings were preserved; retry.',
    ];
    return str_replace('\\n', "\n", ($language === 'fa' ? $fa : $en)[$key] ?? $en['save_failed']);
}
