<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Settings;

use BAGArt\TelegramBotSummarizer\I18n\SummarizerStrings;

/**
 * Effective per-chat summarizer settings resolved through
 * ModuleSettingsContract::settingsFor() with platform defaults applied.
 */
final readonly class SummarizerSettings
{
    public const DEFAULT_INTERVAL_MINUTES = 360;

    public const DEFAULT_MIN_MESSAGES = 10;

    public const INTERVAL_CHOICES = [30, 60, 120, 180, 360, 720, 1440];

    /**
     * @param  array<string, mixed>|null  $customProvider  validated custom provider config
     */
    public function __construct(
        public int $intervalMinutes,
        public string $providerKey,
        public ?string $activeTokenId,
        public string $templateId,
        public ?string $customTemplate,
        public int $minMessages,
        public ?array $customProvider,
        public string $locale = SummarizerStrings::LOCALE_EN,
        public ?string $modelOverride = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            intervalMinutes: self::clampInterval((int) ($raw['interval_minutes'] ?? self::DEFAULT_INTERVAL_MINUTES)),
            providerKey: (string) ($raw['provider_key'] ?? 'openai'),
            activeTokenId: isset($raw['active_token_id']) ? (string) $raw['active_token_id'] : null,
            templateId: (string) ($raw['template_id'] ?? 'witty'),
            customTemplate: isset($raw['custom_template']) && $raw['custom_template'] !== '' ? (string) $raw['custom_template'] : null,
            minMessages: max(1, min(5000, (int) ($raw['min_messages'] ?? self::DEFAULT_MIN_MESSAGES))),
            customProvider: is_array($raw['custom_provider'] ?? null) ? $raw['custom_provider'] : null,
            locale: self::clampLocale((string) ($raw['locale'] ?? SummarizerStrings::LOCALE_EN)),
            modelOverride: isset($raw['model_override']) && $raw['model_override'] !== '' ? (string) $raw['model_override'] : null,
        );
    }

    private static function clampInterval(int $minutes): int
    {
        return max(15, min(10080, $minutes));
    }

    private static function clampLocale(string $locale): string
    {
        return in_array($locale, [
            SummarizerStrings::LOCALE_EN,
            SummarizerStrings::LOCALE_RU,
            SummarizerStrings::LOCALE_FR,
            SummarizerStrings::LOCALE_ES,
            SummarizerStrings::LOCALE_ZH,
        ], true) ? $locale : SummarizerStrings::LOCALE_EN;
    }
}
