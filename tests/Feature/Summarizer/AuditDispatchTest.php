<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use BAGArt\TelegramBot\Configs\TgServiceConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\TgApi\Types\DTO\CallbackQueryTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UserTypeDTO;
use BAGArt\TelegramBot\TgBotSetupFactory;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotSummarizer\Events\SummarizerAuditEvent;
use BAGArt\TelegramBotSummarizer\Models\SummarizerChatAccess;
use BAGArt\TelegramBotSummarizer\Models\SummarizerToken;
use BAGArt\TelegramBotSummarizer\ModuleFactory;
use BAGArt\TelegramBotSummarizer\Processing\AdminMenuProcessor;
use BAGArt\TelegramBotSummarizer\Processing\CollectMessageProcessor;
use BAGArt\TelegramBotSummarizer\Ui\CallbackRoute;
use BAGArt\TelegramBotSummarizer\Ui\PendingInputService;
use Illuminate\Support\Facades\Event;

/** Processor context with the shared sender swapped for a spy. */
function smAuditContext(TgSenderContract $sender): BotProcessorContext
{
    $botSetup = app(TgBotSetupFactory::class)->create(serviceConfig: new TgServiceConfig());

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

function smAuditCallback(string $data, int $fromId): CallbackQueryTypeDTO
{
    return new CallbackQueryTypeDTO(
        id: 'cbq-audit',
        from: new UserTypeDTO(id: (string) $fromId, isBot: false, firstName: 'Tester', username: 'tester'),
        chatInstance: 'ci',
        data: $data,
    );
}

beforeEach(function () {
    config('telegram.modules');
    config(['summarizer.superadmins' => []]);

    TgBot::create(['bot_id' => 'test_bot', 'token' => '123:test']);

    // Prime the admin-list cache so access checks never hit the network
    Cache::put('summarizer:admins:test_bot:-100100', [], 60);

    Event::fake([SummarizerAuditEvent::class]);
});

it('dispatches message.stored when a group message is collected (F1 entity)', function () {
    smOptInChat('test_bot', -100100);

    CollectMessageProcessor::build(smAuditContext(smSenderSpy()))
        ->process(smGroupMessage(-100100, 42, 'hello there'), smBotConfig(), 'message');

    Event::assertDispatched(
        SummarizerAuditEvent::class,
        static fn (SummarizerAuditEvent $event): bool => $event->operation() === SummarizerAuditEvent::MESSAGE_STORED
            && $event->subjectType() === 'chat',
    );
});

it('dispatches chat.enablement.changed from the inline-keyboard enable press (F2 .changed)', function () {
    SummarizerChatAccess::create([
        'bot_id' => 'test_bot',
        'chat_id' => -100100,
        'inviter_tg_id' => 42,
        'invited_at' => time(),
    ]);

    AdminMenuProcessor::build(smAuditContext(smSenderSpy()))->process(
        smAuditCallback(CallbackRoute::encode(-100100, CallbackRoute::VERB_ENABLE), 42),
        smBotConfig(),
        'callbackQuery',
    );

    Event::assertDispatched(
        SummarizerAuditEvent::class,
        static fn (SummarizerAuditEvent $event): bool => $event->operation() === SummarizerAuditEvent::CHAT_ENABLEMENT_CHANGED
            && $event->newState() === ['enabled' => true],
    );
});

it('dispatches retention.purged from the digests cron (F3 automatic/CLI)', function () {
    $this->artisan('summarizer:digests')->assertSuccessful();

    Event::assertDispatched(
        SummarizerAuditEvent::class,
        static fn (SummarizerAuditEvent $event): bool => $event->operation() === SummarizerAuditEvent::RETENTION_PURGED
            && $event->subjectType() === 'retention',
    );
});

it('keeps the provider token value out of the token-delete audit context (secrets)', function () {
    smOptInChat('test_bot', -100100);

    SummarizerChatAccess::create([
        'bot_id' => 'test_bot',
        'chat_id' => -100100,
        'inviter_tg_id' => 42,
        'invited_at' => time(),
    ]);

    $token = SummarizerToken::create([
        'bot_id' => 'test_bot',
        'provider_key' => 'openai',
        'label' => 'ci',
        'token' => 'sk-super-secret-value-000000000000',
        'created_by_tg_id' => 42,
        'created_by_username' => 'tester',
    ]);

    AdminMenuProcessor::build(smAuditContext(smSenderSpy()))->process(
        smAuditCallback(CallbackRoute::encode(-100100, CallbackRoute::VERB_DELETE_TOKEN, $token->id), 42),
        smBotConfig(),
        'callbackQuery',
    );

    Event::assertDispatched(
        SummarizerAuditEvent::class,
        static function (SummarizerAuditEvent $event) use ($token): bool {
            if ($event->operation() !== SummarizerAuditEvent::PROVIDER_TOKEN_DELETED) {
                return false;
            }

            $context = $event->context();

            return ! array_key_exists('token', $context)
                && ! str_contains(json_encode($context), 'sk-super-secret-value')
                && $context['providerKey'] === 'openai'
                && $event->subjectId() === $token->id;
        },
    );
});

it('keeps the provider token value out of the token-create audit context (secrets)', function () {
    smOptInChat('test_bot', -100100);
    ModuleFactory::pending()->start(
        'test_bot',
        -100100,
        42,
        PendingInputService::ACTION_TOKEN,
        ['provider_key' => 'openai'],
    );

    CollectMessageProcessor::build(smAuditContext(smSenderSpy()))
        ->process(smGroupMessage(-100100, 42, ' sk-create-super-secret-abcdef0123456789 ', 55), smBotConfig(), 'message');

    $created = SummarizerToken::query()
        ->where('bot_id', 'test_bot')
        ->where('provider_key', 'openai')
        ->first();

    expect($created)->not->toBeNull();

    Event::assertDispatched(
        SummarizerAuditEvent::class,
        static function (SummarizerAuditEvent $event) use ($created): bool {
            if ($event->operation() !== SummarizerAuditEvent::PROVIDER_TOKEN_CREATED) {
                return false;
            }

            $context = $event->context();

            return ! array_key_exists('token', $context)
                && ! str_contains(json_encode($context), 'sk-create-super-secret')
                && $context['providerKey'] === 'openai'
                && $event->subjectId() === $created->id;
        },
    );
});
