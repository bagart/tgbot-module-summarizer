<?php

declare(strict_types=1);

require_once __DIR__.'/helpers.php';

use BAGArt\TelegramBot\Configs\TgServiceConfig;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\CallbackQueryTypeDTO;
use BAGArt\TelegramBot\TgBotSetupFactory;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotSummarizer\Models\SummarizerChatAccess;
use BAGArt\TelegramBotSummarizer\Models\SummarizerRun;
use BAGArt\TelegramBotSummarizer\Processing\AdminMenuProcessor;
use BAGArt\TelegramBotSummarizer\Ui\CallbackRoute;

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
});

/** Press an admin-menu callback as the inviter (user 42) and return sent texts. */
function smhPress(string $verb, ?string $arg = null): array
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

function smhSeedRun(int $chatId = -100100, ?string $summary = 'Witty summary.', string $botId = 'test_bot'): SummarizerRun
{
    return SummarizerRun::factory()->create([
        'bot_id' => $botId,
        'chat_id' => $chatId,
        'summary_text' => $summary,
        'created_at' => now(),
    ]);
}

it('shows an empty history state when the chat has no runs', function () {
    $messages = smhPress(CallbackRoute::VERB_PAGE_HISTORY);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->text)->toContain('Run history')
        ->and($messages[0]->text)->toContain('No runs yet')
        ->and($messages[0]->replyMarkup)->not->toBeNull();
});

it('lists runs newest first and paginates beyond the page size', function () {
    foreach (range(1, 11) as $i) {
        smhSeedRun(summary: 'Summary number '.$i);
    }

    $page1 = smhPress(CallbackRoute::VERB_PAGE_HISTORY)[0];
    $page2 = smhPress(CallbackRoute::VERB_HISTORY_PAGE, '2')[0];

    expect($page1->text)->not->toContain('Summary number') // list shows meta only, not bodies
        ->and($page1->text)->toContain('openai')
        ->and($page2->text)->toContain('openai');

    // 10 view buttons + nav + back on page 1, 1 view button + nav + back on page 2
    $rows1 = $page1->replyMarkup->inlineKeyboard;
    $rows2 = $page2->replyMarkup->inlineKeyboard;

    expect($rows1)->toHaveCount(12)
        ->and($rows2)->toHaveCount(3);
});

it('shows the full summary text when a history entry is tapped', function () {
    $run = smhSeedRun(summary: "## Topics\n\nThe *full* summary body lives here.");

    $messages = smhPress(CallbackRoute::VERB_HISTORY_VIEW, (string) $run->id);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->text)->toContain('The *full* summary body lives here.')
        ->and($messages[0]->text)->toContain('Run details');
});

it('does not leak runs from another chat into the history', function () {
    smhSeedRun(chatId: -100200, summary: 'Secret other-chat summary.');

    $messages = smhPress(CallbackRoute::VERB_PAGE_HISTORY);

    expect($messages[0]->text)->toContain('No runs yet')
        ->and($messages[0]->text)->not->toContain('Secret other-chat summary');
});

it('reports a missing run when the viewed id belongs to another chat', function () {
    $run = smhSeedRun(chatId: -100200);

    $spy = smSenderSpy();
    $botSetup = app(TgBotSetupFactory::class)->create(serviceConfig: new TgServiceConfig());
    $query = new CallbackQueryTypeDTO(
        id: 'cbq_iso',
        from: smUser(42),
        chatInstance: 'ci',
        data: CallbackRoute::encode(-100100, CallbackRoute::VERB_HISTORY_VIEW, (string) $run->id),
    );

    AdminMenuProcessor::build(smContext($botSetup, $spy))->process($query, smBotConfig());

    $answers = array_values(array_filter($spy->sent, fn ($dto): bool => $dto instanceof \BAGArt\TelegramBot\TgApi\Methods\DTO\AnswerCallbackQueryMethodDTO));

    expect($answers)->not->toBeEmpty()
        ->and($answers[0]->showAlert)->toBeTrue()
        ->and($answers[0]->text)->not->toBeEmpty()
        ->and(array_filter($spy->sent, fn ($dto): bool => $dto instanceof SendMessageMethodDTO))->toBe([]);
});
