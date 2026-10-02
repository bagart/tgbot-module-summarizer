<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Summarizer Module
|--------------------------------------------------------------------------
|
| Chat digest module (bagart/tgbot-module-summarizer). Per-chat
| settings live in the module settings store behind
| ModuleSettingsContract; these are platform defaults and
| operational limits.
|
*/

return [
    // Telegram user ids allowed to manage/delete any LLM token: "111,222"
    'superadmins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SUMMARIZER_SUPERADMIN_TG_IDS', '')),
    ))),

    // Days to keep collected messages, transcript files and run history
    'retention_days' => (int) env('SUMMARIZER_RETENTION_DAYS', 14),

    // Transcript guard rails fed to the LLM
    'transcript_budget_chars' => (int) env('SUMMARIZER_TRANSCRIPT_BUDGET_CHARS', 120000),
    'max_transcript_messages' => (int) env('SUMMARIZER_MAX_TRANSCRIPT_MESSAGES', 2000),
    'max_chars_per_message' => (int) env('SUMMARIZER_MAX_CHARS_PER_MESSAGE', 1000),

    // Outgoing LLM HTTP call limits
    'llm_timeout_seconds' => (int) env('SUMMARIZER_LLM_TIMEOUT_SECONDS', 90),
    'llm_max_response_bytes' => (int) env('SUMMARIZER_LLM_MAX_RESPONSE_BYTES', 2097152),

    // Pending admin input (token paste, template editor) lifetime in minutes
    'pending_input_ttl_minutes' => (int) env('SUMMARIZER_PENDING_INPUT_TTL', 15),
];