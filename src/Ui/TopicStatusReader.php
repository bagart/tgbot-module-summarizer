<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Ui;

use BAGArt\TelegramBotSummarizer\Models\SummarizerRun;

/**
 * Latest digest-run status per forum topic (thread_id) for the admin
 * panel's topic-level status block. Read-only projection over runs.
 */
final class TopicStatusReader
{
    /**
     * @return list<array{threadId: int, ok: bool, lastRunLabel: string}>
     */
    public static function read(string $botId, int $chatId): array
    {
        $runs = SummarizerRun::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->whereNotNull('thread_id')
            ->orderByDesc('created_at')
            ->get(['thread_id', 'status', 'created_at']);

        $latest = [];

        foreach ($runs as $run) {
            $threadId = (int) $run->thread_id;

            if (isset($latest[$threadId])) {
                continue;
            }

            $latest[$threadId] = [
                'threadId' => $threadId,
                'ok' => $run->status === SummarizerRun::STATUS_SUCCESS,
                'lastRunLabel' => $run->created_at?->format('d.m H:i') ?? '?',
            ];
        }

        return array_values($latest);
    }
}
