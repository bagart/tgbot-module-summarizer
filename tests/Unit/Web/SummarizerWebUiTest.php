<?php

declare(strict_types=1);

use BAGArt\TelegramBotMenu\Manifest\ChatScope;
use BAGArt\TelegramBotMenu\Manifest\EffectiveRole;
use BAGArt\TelegramBotMenu\Support\BotRef;
use BAGArt\TelegramBotMenu\Support\ModuleRef;
use BAGArt\TelegramBotMenu\Support\TgUiContext;
use BAGArt\TelegramBotMenu\Support\TgWebRequest;
use BAGArt\TelegramBotMenu\Support\UserRef;
use BAGArt\TelegramBotMenu\Testing\TgWebUiContractTest;
use BAGArt\TelegramBotSummarizer\Settings\SummarizerSettings;
use BAGArt\TelegramBotSummarizer\Web\SummarizerUiHandler;
use BAGArt\TelegramBotSummarizer\Web\SummarizerWebUi;

/**
 * menu_integration.md M-3c: summarizer schema manifest, settings round-trip
 * and the §8.9 run-now action surface.
 */
it('satisfies the TgWebUiContract shape for the summarizer module', function () {
    TgWebUiContractTest::assertContractShape(SummarizerWebUi::class, 'summarizer');
});

it('declares the run-now action and its matching webApi route', function () {
    $actions = SummarizerWebUi::manifest()->actions;
    $routes = SummarizerUiHandler::routes();

    expect($actions)->toHaveCount(1)
        ->and($actions[0]->id)->toBe('run-now')
        ->and($actions[0]->minRole)->toBe(EffectiveRole::Admin)
        ->and($routes)->toHaveCount(1)
        ->and($routes[0]->method)->toBe('POST')
        ->and($routes[0]->path)->toBe('actions/run-now')
        ->and($routes[0]->minRole)->toBe(EffectiveRole::Admin)
        ->and($routes[0]->chatScope)->toBe(ChatScope::Required);
});

it('declares a schema entry without the legacy enablement field', function () {
    $keys = [];

    foreach (SummarizerWebUi::manifest()->entry->groups as $group) {
        foreach ($group->fields as $field) {
            $keys[] = $field->key;
        }
    }

    expect($keys)->toBe(['interval_minutes', 'min_messages', 'provider_key', 'template_id'])
        ->and($keys)->not->toContain('enabled');
});

it('maps schema keys onto SummarizerSettings raw keys via validate', function () {
    $patch = (new SummarizerWebUi())->validate([
        // legacy payload key: enablement is no longer part of this form
        'enabled' => true,
        'interval_minutes' => '99999',
        'min_messages' => 25,
        'provider_key' => 'groq',
        'template_id' => 'detailed',
    ]);

    expect($patch)->not->toHaveKey('enabled')
        ->and($patch['interval_minutes'])->toBe(10080)
        ->and($patch['min_messages'])->toBe(25)
        ->and($patch['provider_key'])->toBe('groq')
        ->and($patch['template_id'])->toBe('detailed');
});

it('feeds the validated patch straight into SummarizerSettings::fromArray', function () {
    $patch = (new SummarizerWebUi())->validate([
        'interval_minutes' => 60,
        'template_id' => 'laconic',
    ]);

    $settings = SummarizerSettings::fromArray($patch);

    expect($settings->intervalMinutes)->toBe(60)
        ->and($settings->templateId)->toBe('laconic');
});

it('rejects unknown provider and template values', function () {
    $form = new SummarizerWebUi();

    expect(fn () => $form->validate(['provider_key' => 'skynet']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $form->validate(['template_id' => 'haiku']))
        ->toThrow(InvalidArgumentException::class);
});

it('answers 404 for unknown routes from the context alone (G9)', function () {
    $context = new TgUiContext(
        bot: new BotRef('7003', 'testbot'),
        chat: null,
        module: new ModuleRef('summarizer'),
        role: EffectiveRole::Admin,
        user: new UserRef(42, 'Admin', 'en'),
    );

    $request = new TgWebRequest(
        botId: '7003',
        tgUserId: 42,
        role: EffectiveRole::Admin,
        chatId: null,
        locale: 'en',
        payload: [],
        requestId: 'req-1',
        context: $context,
    );

    $response = (new SummarizerUiHandler())->handle($request, ['unknown']);

    expect($response->status)->toBe(404)
        ->and($response->body['error']['code'])->toBe('not_found');
});
