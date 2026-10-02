<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Digest;

use Illuminate\Support\Facades\Storage;
use BAGArt\TelegramBotSummarizer\Models\SummarizerMessage;

/**
 * Assembles the period transcript ("all messages of the period saved into a
 * file") from collected messages and persists it to local storage for audit.
 */
class DigestBuilder
{
    private const HEAD_SHARE = 0.3;

    public function __construct(
        private readonly int $budgetChars,
        private readonly int $maxMessages,
        private readonly int $maxCharsPerMessage,
    ) {
    }

    public function build(string $botId, int $chatId, int $fromTs, int $toTs, ?int $threadId = null): ?DigestResult
    {
        $query = SummarizerMessage::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->whereBetween('sent_at', [$fromTs, $toTs])
            ->orderBy('sent_at')
            ->orderBy('id');

        if ($threadId !== null) {
            $query->where('thread_id', $threadId);
        } else {
            $query->whereNull('thread_id');
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            return null;
        }

        $skippedOld = max(0, $total - $this->maxMessages);
        $messages = $query
            ->when($skippedOld > 0, fn ($q) => $q->skip($skippedOld))
            ->limit($this->maxMessages)
            ->get();

        $transcript = $this->renderTranscript($messages, $skippedOld);
        $truncated = false;

        if (mb_strlen($transcript) > $this->budgetChars) {
            $transcript = $this->fitToBudget($transcript);
            $truncated = true;
        }

        return new DigestResult(
            transcript: $transcript,
            filePath: $this->persist($botId, $chatId, $fromTs, $toTs, $transcript),
            messageCount: $total,
            participantCount: $messages->whereNull('user_tg_id')->isNotEmpty()
                ? $messages->unique(fn ($m) => $m->user_tg_id ?? 'anon:'.$m->display_name)->count()
                : $messages->unique('user_tg_id')->count(),
            truncated: $truncated,
        );
    }

    /**
     * @param  iterable<SummarizerMessage>  $messages
     */
    private function renderTranscript(iterable $messages, int $skippedOld): string
    {
        $lines = [];

        if ($skippedOld > 0) {
            $lines[] = "[{$skippedOld} older messages omitted]";
        }

        foreach ($messages as $message) {
            $author = $message->display_name ?: ('#'.$message->user_tg_id);

            if ($message->username !== null && $message->username !== '') {
                $author .= ' (@'.$message->username.')';
            }

            $time = date('d.m H:i', $message->sent_at);
            $text = $this->normalizeText($message->text);

            $lines[] = sprintf('[%s] %s: %s', $time, $author, $text);
        }

        return implode("\n", $lines);
    }

    private function normalizeText(?string $text): string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return '[media]';
        }

        if (mb_strlen($text) > $this->maxCharsPerMessage) {
            $text = mb_substr($text, 0, $this->maxCharsPerMessage).'…[cut]';
        }

        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private function fitToBudget(string $transcript): string
    {
        $marker = "\n[…middle of transcript truncated to fit context budget…]\n";
        $headLength = (int) (($this->budgetChars - mb_strlen($marker)) * self::HEAD_SHARE);
        $tailLength = $this->budgetChars - mb_strlen($marker) - $headLength;

        return mb_substr($transcript, 0, $headLength)
            .$marker
            .mb_substr($transcript, -$tailLength);
    }

    private function persist(string $botId, int $chatId, int $fromTs, int $toTs, string $transcript): string
    {
        $path = sprintf('summarizer/%s/%d/%d-%d.txt', $botId, $chatId, $fromTs, $toTs);

        Storage::disk('local')->put($path, $transcript);

        return $path;
    }
}
