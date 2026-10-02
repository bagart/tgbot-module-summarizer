<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Console;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotSummarizer\ModuleFactory;
use BAGArt\TelegramBotSummarizer\Models\SummarizerMessage;
use BAGArt\TelegramBotSummarizer\Models\SummarizerRun;
use BAGArt\TelegramBotSummarizer\Settings\SummarizerModuleId;
use BAGArt\TelegramBotSummarizer\Settings\SummarizerSettingsService;
use BAGArt\TelegramBotSummarizer\Ui\PendingInputService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Cron entry: scans enabled summarizer chats and produces due digests.
 * A chat is due when its interval has elapsed since the last run (any
 * status, so failures back off naturally) AND enough messages collected.
 */
class SummarizerDigestsCommand extends Command
{
    protected $signature = 'summarizer:digests {--chat= : Only this chat id}';

    protected $description = 'Produce due chat digests for the summarizer module';

    public function handle(
        SummarizerSettingsService $settingsService,
        ModuleSettingsContract $settingsContract,
    ): int {
        PendingInputService::pruneExpired();
        $this->pruneRetention();

        $chatOption = $this->option('chat');
        $onlyChatId = $chatOption === null ? null : (int) $chatOption;

        $candidates = array_values(array_filter(
            $settingsContract->chatsWithSettings(SummarizerModuleId::ID),
            fn (array $scope): bool => $scope['chatId'] !== null
                && ($onlyChatId === null || $scope['chatId'] === $onlyChatId)
                && $settingsService->isEnabled($scope['botId'], $scope['chatId']),
        ));

        $produced = 0;
        $skipped = 0;

        foreach ($candidates as $candidate) {
            $botId = $candidate['botId'];
            $chatId = $candidate['chatId'];
            $bot = TgBot::withTrashed()->find($botId);

            if ($bot === null) {
                continue;
            }

            $settings = $settingsService->get($botId, $chatId);

            // Discover distinct topics (thread_ids) from recent messages
            $topicThreadIds = $this->discoverTopics($botId, $chatId, $settings->intervalMinutes);

            // Always check the whole-chat digest (thread_id = null)
            if ($this->isDue($botId, $chatId, $settings->intervalMinutes, null)) {
                $produced += $this->runDigest($bot, $chatId, null) ? 1 : 0;
            } else {
                $skipped++;
            }

            // Process each discovered topic
            foreach ($topicThreadIds as $threadId) {
                if ($this->isDue($botId, $chatId, $settings->intervalMinutes, $threadId)) {
                    $produced += $this->runDigest($bot, $chatId, $threadId) ? 1 : 0;
                } else {
                    $skipped++;
                }
            }
        }

        $this->info("Summarizer: {$produced} digest(s) produced, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function discoverTopics(string $botId, int $chatId, int $intervalMinutes): array
    {
        $cutoff = time() - $intervalMinutes * 60;

        return SummarizerMessage::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->where('sent_at', '>=', $cutoff)
            ->whereNotNull('thread_id')
            ->distinct()
            ->pluck('thread_id')
            ->all();
    }

    private function isDue(string $botId, int $chatId, int $intervalMinutes, ?int $threadId): bool
    {
        $query = SummarizerRun::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId);

        if ($threadId !== null) {
            $query->where('thread_id', $threadId);
        } else {
            $query->whereNull('thread_id');
        }

        $lastRunAt = $query->max('created_at');

        if ($lastRunAt === null) {
            return true;
        }

        return $lastRunAt->getTimestamp() + $intervalMinutes * 60 <= time();
    }

    private function runDigest(TgBot $bot, int $chatId, ?int $threadId): bool
    {
        try {
            $runner = ModuleFactory::digestRunnerSync();
            $outcome = $runner->run(
                new TgBotConfig(token: $bot->token, botId: $bot->bot_id),
                $chatId,
                threadId: $threadId,
            );

            return $outcome->isSuccess();
        } catch (Throwable $e) {
            Log::error('Summarizer: cron digest failed', [
                'bot_id' => $bot->bot_id,
                'chat_id' => $chatId,
                'thread_id' => $threadId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function pruneRetention(): void
    {
        $retentionDays = (int) config('summarizer.retention_days', 14);
        $cutoff = time() - $retentionDays * 86400;

        SummarizerMessage::query()
            ->where('sent_at', '<', $cutoff)->delete();

        $expiredRuns = SummarizerRun::query()
            ->where('period_to', '<', $cutoff)
            ->get(['id', 'transcript_path']);

        foreach ($expiredRuns as $run) {
            if (is_string($run->transcript_path)) {
                Storage::disk('local')->delete($run->transcript_path);
            }
        }

        SummarizerRun::query()
            ->where('period_to', '<', $cutoff)->delete();
    }
}
