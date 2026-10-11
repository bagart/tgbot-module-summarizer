<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Configs\TgServiceConfig;
use BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\CallbackQueryTypeDTO;
use BAGArt\TelegramBot\TgBotSetupFactory;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotSummarizer\Digest\DigestBuilder;
use BAGArt\TelegramBotSummarizer\Digest\DigestRunner;
use BAGArt\TelegramBotSummarizer\Llm\LlmClient;
use BAGArt\TelegramBotSummarizer\Llm\LlmConfigResolver;
use BAGArt\TelegramBotSummarizer\Llm\LlmProviderRegistry;
use BAGArt\TelegramBotSummarizer\Models\SummarizerChatAccess;
use BAGArt\TelegramBotSummarizer\Models\SummarizerMessage;
use BAGArt\TelegramBotSummarizer\Models\SummarizerRun;
use BAGArt\TelegramBotSummarizer\Models\SummarizerToken;
use BAGArt\TelegramBotSummarizer\Prompt\PromptRenderer;
use BAGArt\TelegramBotSummarizer\Prompt\PromptTemplateRegistry;
use BAGArt\TelegramBotSummarizer\Processing\AdminMenuProcessor;
use BAGArt\TelegramBotSummarizer\Settings\SummarizerSettingsService;
use BAGArt\TelegramBotSummarizer\Ui\CallbackRoute;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config('telegram.modules');
    config(['summarizer.superadmins' => []]);
    Storage::fake('local');
    config(['summarizer.transcript_budget_chars' => 120000]);

    TgBot::create(['bot_id' => 'test_bot', 'token' => '123:test']);
    SummarizerChatAccess::create([
        'bot_id' => 'test_bot',
        'chat_id' => -100100,
        'inviter_tg_id' => 42,
        'invited_at' => time(),
    ]);
});

function smtRunner(?\BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract $sender = null): DigestRunner
{
    $spy = $sender ?? smSenderSpy();

    return new DigestRunner(
        settingsService: new SummarizerSettingsService(
            app(ModuleSettingsContract::class),
            app(ModuleEnablementContract::class),
        ),
        builder: new DigestBuilder(120000, 2000, 1000),
        renderer: new PromptRenderer(new PromptTemplateRegistry()),
        client: new LlmClient(2097152),
        configResolver: new LlmConfigResolver(new LlmProviderRegistry(), 30),
        sender: $spy,
    );
}

function smtSeedMessages(int $threadId, int $count = 5): void
{
    foreach (range(1, $count) as $i) {
        SummarizerMessage::factory()->inChat('test_bot', -100100)->create([
            'message_id' => $threadId * 100 + $i,
            'thread_id' => $threadId,
            'text' => "topic {$threadId} message {$i}",
            'sent_at' => time() - ($count - $i) * 30,
        ]);
    }
}

function smtEnabledWithToken(): void
{
    smOptInChat('test_bot', -100100);
    app(SummarizerSettingsService::class)->patch('test_bot', -100100, ['provider_key' => 'openai']);

    $token = SummarizerToken::create([
        'bot_id' => 'test_bot',
        'provider_key' => 'openai',
        'label' => 'test',
        'token' => 'sk-openai-live-key-1234567890',
        'created_by_tg_id' => 42,
        'created_at' => now(),
    ]);

    app(SummarizerSettingsService::class)->patch('test_bot', -100100, ['active_token_id' => $token->id, 'min_messages' => 5]);
}

it('scopes the builder transcript to one topic and falls back to the main chat', function () {
    smtSeedMessages(threadId: 5, count: 3);
    smtSeedMessages(threadId: 9, count: 4);

    // main-chat messages (null thread) coexist with topic messages
    SummarizerMessage::factory()->inChat('test_bot', -100100)->create([
        'message_id' => 9001,
        'thread_id' => null,
        'text' => 'main chat only',
        'sent_at' => time() - 30,
    ]);

    $builder = new DigestBuilder(120000, 2000, 1000);
    $to = time();
    $from = $to - 3600;

    $topic5 = $builder->build('test_bot', -100100, $from, $to, threadId: 5);
    $topic9 = $builder->build('test_bot', -100100, $from, $to, threadId: 9);
    $main = $builder->build('test_bot', -100100, $from, $to, threadId: null);

    expect($topic5->messageCount)->toBe(3)
        ->and($topic5->transcript)->toContain('topic 5 message')
        ->and($topic5->transcript)->not->toContain('topic 9 message')
        ->and($topic9->messageCount)->toBe(4)
        ->and($main->messageCount)->toBe(1)
        ->and($main->transcript)->toContain('main chat only');
});

it('sends the digest into the topic and stores the thread on the run', function () {
    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [['message' => ['content' => 'Topic digest body']]],
    ])]);

    smtEnabledWithToken();
    smtSeedMessages(threadId: 7, count: 6);

    $spy = smSenderSpy();
    $outcome = smtRunner($spy)->run(new TgBotConfig(token: '123:test', botId: 'test_bot'), -100100, threadId: 7);

    expect($outcome->isSuccess())->toBeTrue();

    $run = SummarizerRun::query()->sole();

    expect($run->thread_id)->toBe(7)
        ->and($run->summary_text)->toContain('Topic digest body');

    $summaries = array_values(array_filter($spy->sent, fn ($dto): bool => $dto instanceof SendMessageMethodDTO));

    expect($summaries)->not->toBeEmpty()
        ->and($summaries[0]->messageThreadId)->toBe(7);
});

it('keeps topic and main-chat periods independent', function () {
    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [['message' => ['content' => 'ok']]],
    ])]);

    smtEnabledWithToken();
    smtSeedMessages(threadId: 7, count: 6);

    // A completed topic run must not satisfy/consume the main-chat period.
    $runner = smtRunner();
    $runner->run(new TgBotConfig(token: '123:test', botId: 'test_bot'), -100100, threadId: 7);

    $topicRun = SummarizerRun::query()->whereNotNull('thread_id')->sole();

    // main chat has no messages of its own → skipped, despite the topic run
    $mainOutcome = $runner->run(new TgBotConfig(token: '123:test', botId: 'test_bot'), -100100, threadId: null);

    expect($mainOutcome->isSuccess())->toBeFalse()
        ->and(SummarizerRun::query()->whereNull('thread_id')->count())->toBe(0)
        ->and($topicRun->thread_id)->toBe(7);
});

it('lists topic-level status on the admin main menu', function () {
    SummarizerRun::factory()->create(['bot_id' => 'test_bot', 'chat_id' => -100100, 'thread_id' => 7, 'status' => SummarizerRun::STATUS_SUCCESS, 'created_at' => now()]);
    SummarizerRun::factory()->create(['bot_id' => 'test_bot', 'chat_id' => -100100, 'thread_id' => 8, 'status' => SummarizerRun::STATUS_FAILED, 'created_at' => now()->subMinute()]);
    // latest run per topic wins
    SummarizerRun::factory()->create(['bot_id' => 'test_bot', 'chat_id' => -100100, 'thread_id' => 7, 'status' => SummarizerRun::STATUS_FAILED, 'created_at' => now()]);

    $spy = smSenderSpy();
    $botSetup = app(TgBotSetupFactory::class)->create(serviceConfig: new TgServiceConfig());
    $query = new CallbackQueryTypeDTO(
        id: 'cbq_topics',
        from: smUser(42),
        chatInstance: 'ci',
        data: CallbackRoute::encode(-100100, CallbackRoute::VERB_MENU),
    );

    AdminMenuProcessor::build(smContext($botSetup, $spy))->process($query, smBotConfig());

    $messages = array_values(array_filter($spy->sent, fn ($dto): bool => $dto instanceof SendMessageMethodDTO));
    $text = $messages[0]->text;

    expect($text)->toContain('Topics (2)')
        ->and($text)->toContain('#7')
        ->and($text)->toContain('#8')
        // topic 7's latest run is failed → red cross, not the older success
        ->and(substr_count($text, '✅'))->toBeGreaterThanOrEqual(1)
        ->and($text)->toContain('❌');
});

it('shows the no-topics state on chats without topic digests', function () {
    $spy = smSenderSpy();
    $botSetup = app(TgBotSetupFactory::class)->create(serviceConfig: new TgServiceConfig());
    $query = new CallbackQueryTypeDTO(
        id: 'cbq_notopics',
        from: smUser(42),
        chatInstance: 'ci',
        data: CallbackRoute::encode(-100100, CallbackRoute::VERB_MENU),
    );

    AdminMenuProcessor::build(smContext($botSetup, $spy))->process($query, smBotConfig());

    $messages = array_values(array_filter($spy->sent, fn ($dto): bool => $dto instanceof SendMessageMethodDTO));

    expect($messages[0]->text)->toContain('No topic digests yet');
});
