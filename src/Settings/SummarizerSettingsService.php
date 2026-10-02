<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Settings;

use BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;

/**
 * Reads effective summarizer settings and persists chat-level patches
 * through ModuleSettingsContract (driver-agnostic: legacy enablement rows
 * or engine activation rows). The reserved `enabled` patch key is the only
 * WRITE path for chat enablement — it never lands in the settings map, and
 * patch() invalidates the enablement memo so the next read sees the write
 * instead of waiting out the driver's cache TTL. Enablement reads go
 * through isEnabled(): the single source of truth for collection, digests
 * and the admin panel.
 */
class SummarizerSettingsService
{
    public function __construct(
        private readonly ModuleSettingsContract $settings,
        private readonly ModuleEnablementContract $enablement,
    ) {
    }

    public function get(string $botId, int $chatId): SummarizerSettings
    {
        return SummarizerSettings::fromArray(
            $this->settings->settingsFor(SummarizerModuleId::ID, $botId, $chatId),
        );
    }

    public function isEnabled(string $botId, int $chatId): bool
    {
        return $this->enablement->isEnabled(SummarizerModuleId::ID, $botId, $chatId);
    }

    /**
     * @param  array<string, mixed|null>  $patch  settings keys to merge at the chat scope; null removes a key; the reserved `enabled` key routes to chat enablement, never into the settings map
     */
    public function patch(string $botId, int $chatId, array $patch): void
    {
        $this->settings->patchSettings(SummarizerModuleId::ID, $botId, $chatId, $patch);

        // The engine settings adapter's afterWrite hook is not wired in
        // production, so a reserved `enabled` write would leave the enablement
        // memo stale for its full TTL (read-your-writes on the toggle path).
        if (array_key_exists('enabled', $patch)) {
            $this->enablement->refresh($botId, $chatId);
        }
    }
}
