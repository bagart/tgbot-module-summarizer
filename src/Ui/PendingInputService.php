<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Ui;

use Illuminate\Support\Facades\DB;
use BAGArt\TelegramBotSummarizer\Models\SummarizerPendingAction;

/**
 * One-active-action-per-user store for admin input flows (token paste,
 * template editor, provider JSON editor, min-messages value).
 */
class PendingInputService
{
    public const ACTION_TOKEN = 'token_input';

    public const ACTION_TEMPLATE = 'template_input';

    public const ACTION_PROVIDER_JSON = 'provider_json';

    public const ACTION_MIN_MESSAGES = 'min_messages';

    public const ACTION_MODEL = 'model_input';

    public function __construct(
        private readonly int $ttlMinutes,
    ) {
    }

    public function start(string $botId, int $chatId, int $userTgId, string $action, array $payload = []): void
    {
        SummarizerPendingAction::query()->updateOrCreate(
            ['bot_id' => $botId, 'chat_id' => $chatId, 'user_tg_id' => $userTgId],
            [
                'action' => $action,
                'payload' => $payload,
                'expires_at' => time() + $this->ttlMinutes * 60,
            ],
        );
    }

    /**
     * Atomically consume the pending action (null when none/expired).
     */
    public function pop(string $botId, int $chatId, int $userTgId): ?SummarizerPendingAction
    {
        return DB::transaction(function () use ($botId, $chatId, $userTgId): ?SummarizerPendingAction {
            $row = SummarizerPendingAction::query()
                ->valid()
                ->where('bot_id', $botId)
                ->where('chat_id', $chatId)
                ->where('user_tg_id', $userTgId)
                ->lockForUpdate()
                ->first();

            $row?->delete();

            return $row;
        });
    }

    public function cancel(string $botId, int $chatId, int $userTgId): int
    {
        return SummarizerPendingAction::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->where('user_tg_id', $userTgId)
            ->delete();
    }

    /** Garbage-collect expired rows; called from the digest cron. */
    public static function pruneExpired(): int
    {
        return SummarizerPendingAction::query()->where('expires_at', '<=', time())->delete();
    }
}
