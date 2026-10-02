<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBotManagement\Models\TgBot;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use BAGArt\TelegramBotSummarizer\Digest\DigestBuilder;
use BAGArt\TelegramBotSummarizer\Digest\DigestRunner;
use BAGArt\TelegramBotSummarizer\Llm\LlmClient;
use BAGArt\TelegramBotSummarizer\Llm\LlmConfigResolver;
use BAGArt\TelegramBotSummarizer\Llm\LlmProviderRegistry;
use BAGArt\TelegramBotSummarizer\Models\SummarizerMessage;
use BAGArt\TelegramBotSummarizer\Models\SummarizerRun;
use BAGArt\TelegramBotSummarizer\Models\SummarizerToken;
use BAGArt\TelegramBotSummarizer\Prompt\PromptRenderer;
use BAGArt\TelegramBotSummarizer\Prompt\PromptTemplateRegistry;
use BAGArt\TelegramBotSummarizer\Settings\SummarizerSettingsService;

beforeEach(function () {
    config('telegram.modules');
    Storage::fake('local');
    config(['summarizer.transcript_budget_chars' => 120000]);

    TgBot::create(['bot_id' => 'test_bot', 'token' => '123:test']);
});

function smRunner(TgSenderContract $sender): DigestRunner
{
    return new DigestRunner(
        settingsService: new SummarizerSettingsService(
            app(ModuleSettingsContract::class),
            app(ModuleEnablementContract::class),
        ),
        builder: new DigestBuilder(120000, 2000, 1000),
        renderer: new PromptRenderer(new PromptTemplateRegistry()),
        client: new LlmClient(2097152),
        configResolver: new LlmConfigResolver(new LlmProviderRegistry(), 30),
        sender: $sender,
    );
}

function smEnabledChatWithToken(): void
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

    app(SummarizerSettingsService::class)->patch('test_bot', -100100, [
        'active_token_id' => $token->id,
    ]);

    foreach (range(1, 12) as $i) {
        SummarizerMessage::factory()->inChat('test_bot', -100100)->create([
            'message_id' => $i,
            'text' => 'discussion point '.$i,
            'sent_at' => time() - (13 - $i) * 30,
        ]);
    }
}

it('produces a digest: calls the LLM, posts the summary, stores the run', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => "## Topics\n- Bananas\n\nAll good."]]],
        ]),
    ]);

    smEnabledChatWithToken();
    $spy = smSenderSpy();

    $outcome = smRunner($spy)->run(new TgBotConfig(token: '123:test', botId: 'test_bot'), -100100);

    expect($outcome->isSuccess())->toBeTrue()
        ->and($outcome->messageCount)->toBe(12);

    $run = SummarizerRun::query()->sole();

    expect($run->status)->toBe(SummarizerRun::STATUS_SUCCESS)
        ->and($run->summary_text)->toContain('Bananas')
        ->and($run->provider_key)->toBe('openai')
        ->and($run->model)->toBe('gpt-4o-mini');

    $summaryMessages = array_filter($spy->sent, fn ($dto) => $dto instanceof SendMessageMethodDTO);
    $texts = array_map(fn (SendMessageMethodDTO $dto) => $dto->text, $summaryMessages);

    expect($texts !== [])->toBeTrue()
        ->and(implode("\n", $texts))->toContain('Bananas')
        ->and(implode("\n", $texts))->not->toContain('sk-openai-live-key');

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return str_contains((string) ($body['messages'][1]['content'] ?? ''), '<<<TRANSCRIPT>>>')
            && str_contains((string) ($body['messages'][0]['content'] ?? ''), 'HARD LIMITS');
    });
});

it('sends the system prompt with safety preamble and no tool parameters', function () {
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

    smEnabledChatWithToken();
    smRunner(smSenderSpy())->run(new TgBotConfig(token: '123:test', botId: 'test_bot'), -100100);

    Http::assertSent(function (Request $request) {
        $body = $request->data();
        $system = (string) ($body['messages'][0]['content'] ?? '');

        return str_contains($system, 'HARD LIMITS')
            && str_contains($system, 'Never include API keys')
            && ! array_key_exists('tools', $body)
            && ! array_key_exists('functions', $body)
            && ($body['stream'] ?? true) === false;
    });
});

it('stores a failed run and reports the error when the provider is down', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

    smEnabledChatWithToken();
    $spy = smSenderSpy();

    $outcome = smRunner($spy)->run(new TgBotConfig(token: '123:test', botId: 'test_bot'), -100100);

    expect($outcome->isSuccess())->toBeFalse()
        ->and($outcome->error)->toContain('HTTP 500');

    expect(SummarizerRun::query()->where('status', SummarizerRun::STATUS_FAILED)->exists())->toBeTrue();

    $summaryTexts = array_filter($spy->sent, fn ($dto) => $dto instanceof SendMessageMethodDTO);
    expect($summaryTexts)->toBe([]);
});

it('skips chats below the message threshold without touching the LLM', function () {
    Http::fake();

    app(SummarizerSettingsService::class)->patch('test_bot', -100100, ['min_messages' => 500]);

    foreach (range(1, 3) as $i) {
        SummarizerMessage::factory()->inChat('test_bot', -100100)->create(['message_id' => $i]);
    }

    $outcome = smRunner(smSenderSpy())->run(new TgBotConfig(token: '123:test', botId: 'test_bot'), -100100);

    expect($outcome->isSuccess())->toBeFalse()
        ->and(SummarizerRun::query()->count())->toBe(0)
        ->and(Http::recorded())->toHaveCount(0);
});

it('refuses to run without an active token', function () {
    Http::fake();

    smOptInChat('test_bot', -100100);

    foreach (range(1, 12) as $i) {
        SummarizerMessage::factory()->inChat('test_bot', -100100)->create(['message_id' => $i]);
    }

    $outcome = smRunner(smSenderSpy())->run(new TgBotConfig(token: '123:test', botId: 'test_bot'), -100100);

    expect($outcome->isSuccess())->toBeFalse()
        ->and($outcome->error)->toContain('token');
});
