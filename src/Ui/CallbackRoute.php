<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Ui;

/**
 * Encode/decode inline-keyboard callback data ("sm:<chatId>:<verb>[:arg]").
 * Telegram caps callback_data at 64 bytes; chatId is embedded because the
 * parsed CallbackQuery DTO carries no usable originating-message payload.
 */
final class CallbackRoute
{
    private const PREFIX = 'sm';

    public const VERB_MENU = 'm';

    public const VERB_PAGE_INTERVALS = 'pint';

    public const VERB_PAGE_PROVIDERS = 'pprov';

    public const VERB_PAGE_TOKENS = 'ptok';

    public const VERB_PAGE_TEMPLATES = 'ptpl';

    public const VERB_ENABLE = 'on';

    public const VERB_DISABLE = 'off';

    public const VERB_SET_INTERVAL = 'ivl';

    public const VERB_SET_PROVIDER = 'pv';

    public const VERB_ADD_TOKEN = 'tka';

    public const VERB_SELECT_TOKEN = 'tks';

    public const VERB_DELETE_TOKEN = 'tkd';

    public const VERB_CUSTOM_PROVIDER = 'pjc';

    public const VERB_SET_TEMPLATE = 'tpl';

    public const VERB_CUSTOM_TEMPLATE = 'tplc';

    public const VERB_MIN_MESSAGES = 'mm';

    public const VERB_RUN_NOW = 'run';

    public const VERB_CLOSE = 'x';

    public const VERB_PAGE_MODELS = 'pmdl';

    public const VERB_SET_MODEL = 'mdl';

    public const VERB_CUSTOM_MODEL = 'mdlc';

    public const VERB_PAGE_HISTORY = 'phst';

    public const VERB_HISTORY_PAGE = 'hpg';

    public const VERB_HISTORY_VIEW = 'hvw';

    public static function encode(int $chatId, string $verb, ?string $arg = null): string
    {
        $data = self::PREFIX.':'.$chatId.':'.$verb;

        return $arg === null ? $data : $data.':'.$arg;
    }

    /**
     * @return array{chatId: int, verb: string, arg: ?string}|null
     */
    public static function decode(?string $data): ?array
    {
        if ($data === null || ! str_starts_with($data, self::PREFIX.':')) {
            return null;
        }

        $parts = explode(':', $data);

        if (count($parts) < 3 || count($parts) > 4) {
            return null;
        }

        [, $chatIdRaw, $verb] = $parts;
        $chatId = filter_var($chatIdRaw, FILTER_VALIDATE_INT);

        if ($chatId === false || $chatId === 0 || ! preg_match('/^[a-z]{1,6}$/', $verb)) {
            return null;
        }

        return [
            'chatId' => $chatId,
            'verb' => $verb,
            'arg' => $parts[3] ?? null,
        ];
    }
}
