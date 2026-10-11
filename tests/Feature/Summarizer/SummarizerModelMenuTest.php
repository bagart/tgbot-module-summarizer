<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use BAGArt\TelegramBot\Configs\TgServiceConfig;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\CallbackQueryTypeDTO;
use BAGArt\TelegramBot\TgBotSetupFactory;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotSummarizer\Llm\LlmConfigResolver;
use BAGArt\TelegramBotSummarizer\Llm\LlmProviderRegistry;
use BAGArt\TelegramBotSummarizer\Models\SummarizerChatAccess;
use BAGArt\TelegramBotSummarizer\Models\SummarizerPendingAction;
use BAGArt\TelegramBotSummarizer\Processing\AdminMenuProcessor;
use BAGArt\TelegramBotSummarizer\Processing\CollectMessageProcessor;
use BAGArt\TelegramBotSummarizer\Settings\SummarizerSettingsService;
use BAGArt\TelegramBotSummarizer\Ui\CallbackRoute;
use BAGArt\TelegramBotSummarizer\Ui\PendingInputService;

beforeEach(function () {
    config('telegram.modules');
    config(['summarizer.superadmins' => []]);

    TgBot::create(['bot_id' => 'test_bot', 'token' => '123:test']);
    SummarizerChatAccess::create([
        'bot_id' => 'test_bot',
        'chat_id' => -100100,
        'inviter_tg_id' => 42,
        'invited_at' => time(),
    ]);
    app(SummarizerSettingsService::class)->patch('test_bot', -100100, ['provider_key' => 'openai']);
    smOptInChat('test_bot', -100100);
});

function smmdPress(string $verb, ?string $arg = null): array
{
    $spy = smSenderSpy();
    $botSetup = app(TgBotSetupFactory::class)->create(serviceConfig: new TgServiceConfig());

    $query = new CallbackQueryTypeDTO(
        id: 'cbq_'.uniqid(),
        from: smUser(42),
        chatInstance: 'ci',
        data: CallbackRoute::encode(-100100, $verb, $arg),
    );

    AdminMenuProcessor::build(smContext($botSetup, $spy))->process($query, smBotConfig());

    return array_values(array_filter($spy->sent, fn ($dto): bool => $dto instanceof SendMessageMethodDTO));
}

it('renders the model picker with the curated list for the active provider', function () {
    $messages = smmdPress(CallbackRoute::VERB_PAGE_MODELS);
    $buttons = implode(' ', array_map(
        fn ($row): string => implode(' ', array_map(fn ($b): string => $b->text, $row)),
        $messages[0]->replyMarkup->inlineKeyboard,
    ));

    expect($messages[0]->text)->toContain('Model')
        ->and($buttons)->toContain('gpt-4o-mini')
        ->and($buttons)->toContain('gpt-4o')
        ->and($buttons)->toContain('Custom model');
});

it('persists a selected model as the chat override', function () {
    smmdPress(CallbackRoute::VERB_SET_MODEL, 'gpt-4o');

    expect(app(SummarizerSettingsService::class)->get('test_bot', -100100)->modelOverride)->toBe('gpt-4o');
});

it('resolves the override through LlmConfigResolver instead of the preset default', function () {
    app(SummarizerSettingsService::class)->patch('test_bot', -100100, ['model_override' => 'gpt-4-turbo']);

    $config = (new LlmConfigResolver(new LlmProviderRegistry(), 30))
        ->resolve(app(SummarizerSettingsService::class)->get('test_bot', -100100), 'sk-test-key');

    expect($config->model)->toBe('gpt-4-turbo')
        ->and($config->providerKey)->toBe('openai');
});

it('falls back to the preset default model when no override is set', function () {
    $config = (new LlmConfigResolver(new LlmProviderRegistry(), 30))
        ->resolve(app(SummarizerSettingsService::class)->get('test_bot', -100100), 'sk-test-key');

    expect($config->model)->toBe('gpt-4o-mini');
});

it('stores a custom model string through the pending-input flow', function () {
    smmdPress(CallbackRoute::VERB_CUSTOM_MODEL);

    expect(SummarizerPendingAction::query()->where('action', PendingInputService::ACTION_MODEL)->exists())->toBeTrue();

    $botSetup = app(TgBotSetupFactory::class)->create(serviceConfig: new TgServiceConfig());
    CollectMessageProcessor::build(smContext($botSetup, smSenderSpy()))
        ->process(smGroupMessage(-100100, 42, ' my-org/special-model-v2 ', messageId: 77), smBotConfig());

    expect(app(SummarizerSettingsService::class)->get('test_bot', -100100)->modelOverride)->toBe('my-org/special-model-v2')
        ->and(SummarizerPendingAction::query()->count())->toBe(0);
});

it('resets the override back to the provider default', function () {
    app(SummarizerSettingsService::class)->patch('test_bot', -100100, ['model_override' => 'gpt-4o']);

    smmdPress(CallbackRoute::VERB_SET_MODEL, '__reset__');

    expect(app(SummarizerSettingsService::class)->get('test_bot', -100100)->modelOverride)->toBeNull();
});

it('rejects an empty model selection without touching settings', function () {
    smmdPress(CallbackRoute::VERB_SET_MODEL, null);

    expect(app(SummarizerSettingsService::class)->get('test_bot', -100100)->modelOverride)->toBeNull();
});
