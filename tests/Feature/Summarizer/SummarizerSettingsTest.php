<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotSummarizer\Settings\SummarizerSettingsService;

beforeEach(function () {
    config('telegram.modules'); // force the module scan (require_once of sources)

    TgBot::create(['bot_id' => 'bot_x', 'token' => 'a:token']);
    TgBot::create(['bot_id' => 'bot_y', 'token' => 'b:token']);
});

it('applies defaults when no enablement rows exist', function () {
    $service = app(SummarizerSettingsService::class);
    $settings = $service->get('bot_x', 100);

    expect($settings->intervalMinutes)->toBe(360)
        ->and($settings->providerKey)->toBe('openai')
        ->and($settings->activeTokenId)->toBeNull()
        ->and($settings->templateId)->toBe('witty')
        ->and($settings->customTemplate)->toBeNull()
        ->and($settings->minMessages)->toBe(10)
        ->and($settings->customProvider)->toBeNull()
        // fresh chat = OFF (descriptor chat default); bot scope stays discoverable
        ->and($service->isEnabled('bot_x', 100))->toBeFalse()
        ->and(app(ModuleEnablementContract::class)->isEnabled('summarizer', 'bot_x', null))->toBeTrue();
});

it('persists chat-level patches and reflects them back', function () {
    $service = app(SummarizerSettingsService::class);

    $service->patch('bot_x', 100, [
        'interval_minutes' => 60,
        'min_messages' => 25,
        'custom_template' => 'Custom instructions {period}',
    ]);

    $settings = $service->get('bot_x', 100);

    expect($settings->intervalMinutes)->toBe(60)
        ->and($settings->minMessages)->toBe(25)
        ->and($settings->customTemplate)->toBe('Custom instructions {period}');
});

it('round-trips chat enablement through the reserved enabled patch', function () {
    $service = app(SummarizerSettingsService::class);
    $botId = 'bot_x';
    $chatId = 100;

    expect($service->isEnabled($botId, $chatId))->toBeFalse();

    // read-your-writes: patch() invalidates the enablement memo itself
    $service->patch($botId, $chatId, ['enabled' => true]);
    expect($service->isEnabled($botId, $chatId))->toBeTrue();

    smOptOutChat($botId, $chatId);
    expect($service->isEnabled($botId, $chatId))->toBeFalse();

    $raw = app(ModuleSettingsContract::class)->settingsFor('summarizer', $botId, $chatId);

    expect($raw)->not->toHaveKey('enabled')
        ->and($raw)->not->toHaveKey('__enabled__')
        ->and($raw)->not->toHaveKey(smChatKey($chatId));
});

it('keeps chat scopes isolated', function () {
    $service = app(SummarizerSettingsService::class);

    $service->patch('bot_x', 100, ['interval_minutes' => 30]);

    expect($service->get('bot_x', 200)->intervalMinutes)->toBe(360)
        ->and($service->get('bot_y', 100)->intervalMinutes)->toBe(360);
});
