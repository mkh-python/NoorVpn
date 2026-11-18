# Report destination setup — unreleased fix

The report wizard now asks for a Telegram link first, then the numeric ID of the same chat. Public/private channels and ordinary groups are accepted. Existing forum groups retain topic creation and reuse.

The old wizard attempted a test message immediately and then rejected every chat without is_forum. It also treated zero topic IDs as an unconfigured bot, which prevented channels from using the admin menu. These gates now check the actual report destination.

Validation uses getChat and getChatMember before persistence. Public links are checked against the numeric destination; private invite links are not an automatic bot-join mechanism. The bot must be an administrator, with posting permission in channels and topic-management permission in forums. Errors keep the wizard on the current step.

The nullable setting.report_channel_link column is added by an idempotent migration. Destination/link and topic IDs are saved transactionally. Non-forum destinations clear stale topic IDs. Failed forum creation or persistence removes only newly created topics, never reused ones. Existing settings remain intact after failure.

Regression coverage: link parsing, private/public channels, rights and mismatch errors, forum support/cleanup, actual wizard branching including legacy state recovery, and real MySQL persistence/rollback in a temporary database. No live report message was sent by the test tools.
