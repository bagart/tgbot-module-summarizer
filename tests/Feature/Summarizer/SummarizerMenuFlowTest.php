<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Configs\TgServiceConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\Processing\RegisteredUpdateProcessorSelector;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\CallbackQueryTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberLeftTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberMemberTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberUpdatedTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UpdateTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UserTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\Enum\ChatPropTypeEnum;
use BAGArt\TelegramBot\TgBotSetup;
use BAGArt\TelegramBot\TgBotSetupFactory;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotSummarizer\Models\SummarizerChatAccess;
use BAGArt\TelegramBotSummarizer\Models\SummarizerMessage;
use BAGArt\TelegramBotSummarizer\Models\SummarizerPendingAction;
use BAGArt\TelegramBotSummarizer\Models\SummarizerToken;
use BAGArt\TelegramBotSummarizer\ModuleFactory;
use BAGArt\TelegramBotSummarizer\Processing\SummarizerCommandProcessor;
use BAGArt\TelegramBotSummarizer\Settings\SummarizerSettingsService;
use BAGArt\TelegramBotSummarizer\Ui\CallbackRoute;
use BAGArt\TelegramBotSummarizer\Ui\PendingInputService;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config('telegram.modules');
    config(['summarizer.superadmins' => []]);

    TgBot::create(['bot_id' => 'test_bot', 'token' => '123:test']);

    // Prime the admin-list cache so access checks never hit the network
    Cache::put('summarizer:admins:test_bot:-100100', [], 60);
});

function smSelector(?TgBotSetup $botSetup = null): RegisteredUpdateProcessorSelector
{
    $botSetup ??= app(TgBotSetupFactory::class)->create(serviceConfig: new TgServiceConfig());

    return new RegisteredUpdateProcessorSelector(
        serviceConfig: new TgServiceConfig(),
        botSetup: $botSetup,
        moduleEnablement: app(ModuleEnablementContract::class),
    );
}

/** Selector context with the sender swapped for a spy (assert on sends). */
function smContext(TgBotSetup $botSetup, TgSenderContract $sender): BotProcessorContext
{
    return new BotProcessorContext(
        logger: $botSetup->logger,
        tgSender: $sender,
        tgApiCaller: $botSetup->tgApiCaller,
        processorRegistry: $botSetup->processorRegistry,
        serviceConfig: $botSetup->serviceConfig,
        botSetup: $botSetup,
        dbLogger: $botSetup->dbLogger,
        messageRules: $botSetup->messageRules,
        commandRegistry: $botSetup->commandRegistry,
    );
}

function smRunUpdate(UpdateTypeDTO $update, TgBotConfig $botConfig): void
{
    foreach (smSelector()->selectProcessors($update, $botConfig) as $action => $processors) {
        foreach ($processors as $processor) {
            $dto = match ($action) {
                'message', 'editedMessage', 'channelPost' => $update->message,
                'callbackQuery' => $update->callbackQuery,
                'myChatMember' => $update->myChatMember,
                'chatMember' => $update->chatMember,
                default => $update->message,
            };

            $processor->process($dto, $botConfig, $action);
        }
    }
}

function smBotUser(): UserTypeDTO
{
    return new UserTypeDTO(id: '999000111', isBot: true, firstName: 'Digest', username: 'digest_bot');
}

it('discovers the summarizer module', function () {
    expect(app(BAGArt\TelegramBot\Modules\TgModuleRegistry::class)->has('summarizer'))->toBeTrue();
});

it('collects group messages once per message id', function () {
    smOptInChat('test_bot', -100100);

    smRunUpdate(new UpdateTypeDTO(updateId: 1, message: smGroupMessage(-100100, 42, 'hello there')), smBotConfig());
    // same message delivered again (at-least-once webhook) — must not duplicate
    smRunUpdate(new UpdateTypeDTO(updateId: 2, message: smGroupMessage(-100100, 42, 'hello there')), smBotConfig());

    expect(SummarizerMessage::query()->where('bot_id', 'test_bot')->count())->toBe(1)
        ->and(SummarizerMessage::query()->sole()->text)->toBe('hello there');
});

it('does not collect in a fresh chat that has not opted in', function () {
    smRunUpdate(new UpdateTypeDTO(updateId: 1, message: smGroupMessage(-100100, 42, 'private thought')), smBotConfig());

    expect(SummarizerMessage::query()->count())->toBe(0)
        ->and(app(SummarizerSettingsService::class)->isEnabled('test_bot', -100100))->toBeFalse();
});

it('collects only in the opted-in sibling chat of the same bot', function () {
    smOptInChat('test_bot', -100100);

    // A chat-scoped settings write materializes the bot row with status=enabled;
    // a sibling chat without an explicit override must still resolve to OFF.
    app(SummarizerSettingsService::class)->patch('test_bot', -100200, ['interval_minutes' => 60]);

    smRunUpdate(new UpdateTypeDTO(updateId: 20, message: smGroupMessage(-100100, 42, 'for A')), smBotConfig());
    smRunUpdate(new UpdateTypeDTO(updateId: 21, message: smGroupMessage(-100200, 42, 'for B')), smBotConfig());

    expect(DB::table('bot_module_activations')->where('bot_id', 'test_bot')->where('module_id', 'summarizer')->value('status'))
        ->toBe(ModuleActivationReader::STATUS_ENABLED)
        ->and(SummarizerMessage::query()->where('chat_id', -100100)->count())->toBe(1)
        ->and(SummarizerMessage::query()->where('chat_id', -100200)->count())->toBe(0)
        ->and(app(SummarizerSettingsService::class)->isEnabled('test_bot', -100100))->toBeTrue()
        ->and(app(SummarizerSettingsService::class)->isEnabled('test_bot', -100200))->toBeFalse();
});

it('keeps a bot-level activation from turning a fresh chat on', function () {
    smSeedBotActivation('test_bot');

    expect(app(ModuleEnablementContract::class)->isEnabled('summarizer', 'test_bot', null))->toBeTrue();

    smRunUpdate(new UpdateTypeDTO(updateId: 22, message: smGroupMessage(-100100, 42, 'bot toggle only')), smBotConfig());

    expect(SummarizerMessage::query()->count())->toBe(0)
        ->and(app(SummarizerSettingsService::class)->isEnabled('test_bot', -100100))->toBeFalse();
});

it('records the inviter from my_chat_member service updates', function () {
    app()->instance(TgBotApiDTOClientContract::class, smFakeApiClient(result: smBotUser()));

    $update = new ChatMemberUpdatedTypeDTO(
        chat: new ChatTypeDTO(id: '-100100', type: ChatPropTypeEnum::SUPERGROUP),
        from: smUser(42),
        date: time(),
        oldChatMember: new ChatMemberLeftTypeDTO(user: smBotUser()),
        newChatMember: new ChatMemberMemberTypeDTO(user: smBotUser()),
    );

    // dispatches on BOT scope (D1) — this chat never opted in
    smRunUpdate(new UpdateTypeDTO(updateId: 3, myChatMember: $update), smBotConfig());

    expect(SummarizerChatAccess::query()->where('inviter_tg_id', 42)->exists())->toBeTrue();

    // the inviter gains admin rights without any Telegram API call
    app()->instance(TgBotApiDTOClientContract::class, smFakeApiClient(throw: true));

    expect(ModuleFactory::access()->canManage(smBotConfig(), -100100, smUser(42)))->toBeTrue();
});

it('consumes pasted tokens through the pending-input flow', function () {
    smOptInChat('test_bot', -100100);
    ModuleFactory::pending()->start('test_bot', -100100, 42, PendingInputService::ACTION_TOKEN, ['provider_key' => 'openai']);

    smRunUpdate(
        new UpdateTypeDTO(updateId: 4, message: smGroupMessage(-100100, 42, ' sk-live-abcdef0123456789 ', messageId: 55)),
        smBotConfig(),
    );

    $token = SummarizerToken::query()->sole();

    expect($token->token)->toBe('sk-live-abcdef0123456789')
        ->and($token->provider_key)->toBe('openai')
        ->and($token->created_by_tg_id)->toBe(42)
        ->and(SummarizerPendingAction::query()->count())->toBe(0);

    // token became active, provider followed
    $settings = app(SummarizerSettingsService::class)->get('test_bot', -100100);

    expect($settings->activeTokenId)->toBe($token->id)
        ->and($settings->providerKey)->toBe('openai');

    // the raw secret must not land in the collected transcript
    expect(SummarizerMessage::query()->where('text', 'like', '%sk-live%')->exists())->toBeFalse();
});

it('denies the panel to users without manage rights', function () {
    smOptInChat('test_bot', -100100);

    $command = new MessageTypeDTO(
        messageId: 90,
        date: time(),
        chat: new ChatTypeDTO(id: '-100100', type: ChatPropTypeEnum::SUPERGROUP),
        from: smUser(424242),
        text: '/summarizer',
    );

    smRunUpdate(new UpdateTypeDTO(updateId: 5, message: $command), smBotConfig());

    expect(SummarizerToken::query()->count())->toBe(0)
        ->and(app(SummarizerSettingsService::class)->get('test_bot', -100100)->intervalMinutes)->toBe(360);
});

it('dispatches /summarizer in a fresh chat and renders the panel', function () {
    // manage rights for the caller without a Telegram API round-trip
    SummarizerChatAccess::create([
        'bot_id' => 'test_bot',
        'chat_id' => -100100,
        'inviter_tg_id' => 42,
        'invited_at' => time(),
    ]);

    $command = new MessageTypeDTO(
        messageId: 91,
        date: time(),
        chat: new ChatTypeDTO(id: '-100100', type: ChatPropTypeEnum::SUPERGROUP),
        from: smUser(42),
        text: '/summarizer',
    );
    $botConfig = smBotConfig();
    $botSetup = app(TgBotSetupFactory::class)->create(serviceConfig: new TgServiceConfig());

    // D1: the command dispatches on BOT scope, the chat never opted in
    $dispatched = [];
    foreach (smSelector($botSetup)->selectProcessors(new UpdateTypeDTO(updateId: 10, message: $command), $botConfig) as $processors) {
        foreach ($processors as $processor) {
            $dispatched[] = $processor::class;
        }
    }

    expect($dispatched)->toContain(SummarizerCommandProcessor::class)
        ->and(app(SummarizerSettingsService::class)->isEnabled('test_bot', -100100))->toBeFalse();

    $spy = smSenderSpy();
    SummarizerCommandProcessor::build(smContext($botSetup, $spy))->process($command, $botConfig);

    $messages = array_values(array_filter($spy->sent, static fn ($dto): bool => $dto instanceof SendMessageMethodDTO));

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->text)->toContain('Chat Summarizer')
        ->and($messages[0]->replyMarkup)->not->toBeNull();
});

it('enables the module from an inline-keyboard press of an inviter', function () {
    SummarizerChatAccess::create([
        'bot_id' => 'test_bot',
        'chat_id' => -100100,
        'inviter_tg_id' => 42,
        'invited_at' => time(),
    ]);

    $query = new CallbackQueryTypeDTO(
        id: 'cbq1',
        from: smUser(42),
        chatInstance: 'ci',
        data: CallbackRoute::encode(-100100, CallbackRoute::VERB_ENABLE),
    );

    smRunUpdate(new UpdateTypeDTO(updateId: 6, callbackQuery: $query), smBotConfig());

    // the dispatch memoized the pre-patch decision — drop it before asserting
    app(ModuleEnablementContract::class)->refresh('test_bot', -100100);

    expect(app(SummarizerSettingsService::class)->isEnabled('test_bot', -100100))->toBeTrue();
});

it('denies inline-keyboard presses from users without manage rights', function () {
    $query = new CallbackQueryTypeDTO(
        id: 'cbq2',
        from: smUser(8888),
        chatInstance: 'ci',
        data: CallbackRoute::encode(-100100, CallbackRoute::VERB_ENABLE),
    );

    smRunUpdate(new UpdateTypeDTO(updateId: 7, callbackQuery: $query), smBotConfig());

    // the unauthorized press must leave no enablement/settings trace
    expect(DB::table('bot_module_activations')->where('bot_id', 'test_bot')->where('module_id', 'summarizer')->count())->toBe(0)
        ->and(app(SummarizerSettingsService::class)->isEnabled('test_bot', -100100))->toBeFalse();
});

it('enforces single-owner token deletion while superadmins may delete any', function () {
    smOptInChat('test_bot', -100100);

    $foreign = SummarizerToken::create([
        'bot_id' => 'test_bot',
        'provider_key' => 'openai',
        'label' => 'foreign',
        'token' => 'sk-foreign-key-000000000000',
        'created_by_tg_id' => 1001,
        'created_at' => now(),
    ]);

    // user 42 is an inviter (manage rights) but not the owner → delete denied
    SummarizerChatAccess::create(['bot_id' => 'test_bot', 'chat_id' => -100100, 'inviter_tg_id' => 42, 'invited_at' => time()]);

    $query = new CallbackQueryTypeDTO(
        id: 'cbq3',
        from: smUser(42),
        chatInstance: 'ci',
        data: CallbackRoute::encode(-100100, CallbackRoute::VERB_DELETE_TOKEN, $foreign->id),
    );

    smRunUpdate(new UpdateTypeDTO(updateId: 8, callbackQuery: $query), smBotConfig());

    expect(SummarizerToken::query()->whereKey($foreign->id)->exists())->toBeTrue();

    // superadmin may delete foreign tokens
    config(['summarizer.superadmins' => ['42']]);

    $querySuper = new CallbackQueryTypeDTO(
        id: 'cbq4',
        from: smUser(42),
        chatInstance: 'ci',
        data: CallbackRoute::encode(-100100, CallbackRoute::VERB_DELETE_TOKEN, $foreign->id),
    );

    smRunUpdate(new UpdateTypeDTO(updateId: 9, callbackQuery: $querySuper), smBotConfig());

    expect(SummarizerToken::query()->whereKey($foreign->id)->exists())->toBeFalse()
        ->and(app(SummarizerSettingsService::class)->get('test_bot', -100100)->activeTokenId)->toBeNull();
});
