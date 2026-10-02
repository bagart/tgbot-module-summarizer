<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Digest;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use BAGArt\TelegramBotSummarizer\Llm\LlmCallException;
use BAGArt\TelegramBotSummarizer\Llm\LlmClient;
use BAGArt\TelegramBotSummarizer\Llm\LlmConfigResolver;
use BAGArt\TelegramBotSummarizer\Models\SummarizerRun;
use BAGArt\TelegramBotSummarizer\Models\SummarizerToken;
use BAGArt\TelegramBotSummarizer\Prompt\PromptRenderer;
use BAGArt\TelegramBotSummarizer\Settings\SummarizerSettings;
use BAGArt\TelegramBotSummarizer\Settings\SummarizerSettingsService;
use Throwable;

/**
 * Produces one digest for one (bot, chat): transcript → prompt → LLM →
 * send to chat → run history row. Shared by the cron scheduler and the
 * admin-menu "run now" action.
 */
class DigestRunner
{
    private const LOCK_TTL_SECONDS = 600;

    private const TELEGRAM_MESSAGE_LIMIT = 4000;

    public function __construct(
        private readonly SummarizerSettingsService $settingsService,
        private readonly DigestBuilder $builder,
        private readonly PromptRenderer $renderer,
        private readonly LlmClient $client,
        private readonly LlmConfigResolver $configResolver,
        private readonly TgSenderContract $sender,
    ) {
    }

    public function run(TgBotConfig $botConfig, int $chatId, ?int $fromTsOverride = null, ?int $threadId = null): DigestOutcome
    {
        $botId = (string) $botConfig->botId;
        $lock = Cache::lock($this->lockKey($botId, $chatId, $threadId), self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return DigestOutcome::busy();
        }

        try {
            [$fromTs, $toTs] = $this->resolvePeriod($botId, $chatId, $fromTsOverride, $threadId);
            $digest = $this->builder->build($botId, $chatId, $fromTs, $toTs, $threadId);

            if ($digest === null) {
                return DigestOutcome::skipped('No messages collected for the period');
            }

            $settings = $this->settingsService->get($botId, $chatId);

            if ($digest->messageCount < $settings->minMessages) {
                return DigestOutcome::skipped(
                    sprintf('Only %d messages (threshold %d)', $digest->messageCount, $settings->minMessages),
                );
            }

            $tokenRow = $this->resolveToken($botId, $settings);
            if ($tokenRow === null) {
                return DigestOutcome::failed('No active LLM token configured — open /summarizer to add one');
            }

            $config = $this->configResolver->resolve($settings, $tokenRow->token);
            $prompt = $this->renderer->render(
                settings: $settings,
                period: $this->formatPeriod($fromTs, $toTs),
                stats: sprintf('%d messages from %d participants', $digest->messageCount, $digest->participantCount),
                languageHint: 'the dominant language of the transcript',
                transcript: $digest->transcript,
            );

            $startedAt = microtime(true);

            try {
                $summary = $this->client->complete($config, $prompt['system'], $prompt['user']);
            } catch (LlmCallException $e) {
                $this->storeRun($botId, $chatId, $threadId, $fromTs, $toTs, $digest, $config, null, $e->getMessage(), $startedAt, $tokenRow->id);

                return DigestOutcome::failed($e->getMessage());
            }

            $this->storeRun($botId, $chatId, $threadId, $fromTs, $toTs, $digest, $config, $summary, null, $startedAt, $tokenRow->id);
            $this->sendSummary($botConfig, $chatId, $summary, $threadId);

            Log::info('Summarizer digest produced', [
                'module' => 'summarizer',
                'bot_id' => $botId,
                'chat_id' => $chatId,
                'provider' => $config->providerKey,
                'model' => $config->model,
                'messages' => $digest->messageCount,
            ]);

            return DigestOutcome::success($digest->messageCount);
        } catch (Throwable $e) {
            Log::error('Summarizer digest crashed', [
                'module' => 'summarizer',
                'bot_id' => $botId,
                'chat_id' => $chatId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return DigestOutcome::failed($e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolvePeriod(string $botId, int $chatId, ?int $fromTsOverride, ?int $threadId): array
    {
        $toTs = time();

        if ($fromTsOverride !== null) {
            return [min($fromTsOverride, $toTs - 60), $toTs];
        }

        $settings = $this->settingsService->get($botId, $chatId);
        $query = SummarizerRun::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId);

        if ($threadId !== null) {
            $query->where('thread_id', $threadId);
        } else {
            $query->whereNull('thread_id');
        }

        $lastRunTo = $query->max('period_to');

        $windowStart = $toTs - $settings->intervalMinutes * 60;

        return [(int) max($lastRunTo ?? 0, $windowStart), $toTs];
    }

    private function resolveToken(string $botId, SummarizerSettings $settings): ?SummarizerToken
    {
        if ($settings->activeTokenId === null) {
            return null;
        }

        return SummarizerToken::query()
            ->where('bot_id', $botId)
            ->whereKey($settings->activeTokenId)
            ->first();
    }

    private function storeRun(
        string $botId,
        int $chatId,
        ?int $threadId,
        int $fromTs,
        int $toTs,
        DigestResult $digest,
        \BAGArt\TelegramBotSummarizer\Llm\LlmProviderConfig $config,
        ?string $summary,
        ?string $error,
        float $startedAt,
        string $tokenId,
    ): void {
        SummarizerRun::create([
            'bot_id' => $botId,
            'chat_id' => $chatId,
            'thread_id' => $threadId,
            'period_from' => $fromTs,
            'period_to' => $toTs,
            'message_count' => $digest->messageCount,
            'participant_count' => $digest->participantCount,
            'status' => $error === null ? SummarizerRun::STATUS_SUCCESS : SummarizerRun::STATUS_FAILED,
            'error' => $error,
            'summary_text' => $summary,
            'transcript_path' => $digest->filePath,
            'provider_key' => $config->providerKey,
            'model' => $config->model,
            'token_id' => $tokenId,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'created_at' => now(),
        ]);
    }

    private function sendSummary(TgBotConfig $botConfig, int $chatId, string $summary, ?int $threadId): void
    {
        foreach ($this->chunk($summary) as $chunk) {
            $this->sender->send($botConfig, new SendMessageMethodDTO(
                chatId: (string) $chatId,
                text: $chunk,
                messageThreadId: $threadId,
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function chunk(string $text): array
    {
        if (mb_strlen($text) <= self::TELEGRAM_MESSAGE_LIMIT) {
            return [$text];
        }

        $chunks = [];
        foreach (explode("\n\n", $text) as $paragraph) {
            $lastIndex = count($chunks) - 1;

            if ($lastIndex >= 0 && mb_strlen($chunks[$lastIndex]."\n\n".$paragraph) <= self::TELEGRAM_MESSAGE_LIMIT) {
                $chunks[$lastIndex] .= "\n\n".$paragraph;

                continue;
            }

            while (mb_strlen($paragraph) > self::TELEGRAM_MESSAGE_LIMIT) {
                $chunks[] = mb_substr($paragraph, 0, self::TELEGRAM_MESSAGE_LIMIT);
                $paragraph = mb_substr($paragraph, self::TELEGRAM_MESSAGE_LIMIT);
            }

            $chunks[] = $paragraph;
        }

        return $chunks;
    }

    private function formatPeriod(int $fromTs, int $toTs): string
    {
        return date('d.m.Y H:i', $fromTs).' — '.date('d.m.Y H:i', $toTs).' (UTC'.date('P').')';
    }

    private function lockKey(string $botId, int $chatId, ?int $threadId): string
    {
        return $threadId !== null
            ? sprintf('summarizer:run:%s:%d:t%d', $botId, $chatId, $threadId)
            : sprintf('summarizer:run:%s:%d', $botId, $chatId);
    }
}
