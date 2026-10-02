<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One digest production attempt (successful or failed). Also serves as the
 * "last run at" marker for interval scheduling.
 */
class SummarizerRun extends Model
{
    use HasFactory;
    use HasUuids;

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \BAGArt\TelegramBotSummarizer\Database\Factories\SummarizerRunFactory::new();
    }

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const UPDATED_AT = null;

    protected $fillable = [
        'bot_id',
        'chat_id',
        'thread_id',
        'period_from',
        'period_to',
        'message_count',
        'participant_count',
        'status',
        'error',
        'summary_text',
        'transcript_path',
        'provider_key',
        'model',
        'token_id',
        'duration_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'thread_id' => 'integer',
            'period_from' => 'integer',
            'period_to' => 'integer',
            'message_count' => 'integer',
            'participant_count' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
