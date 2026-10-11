<?php

declare(strict_types=1);

use BAGArt\TelegramBotMenu\Menu\MenuCatalog;
use BAGArt\TelegramBotMenu\Menu\RefineFinding;
use BAGArt\TelegramBotMenu\Menu\RefineFindingBuffer;
use BAGArt\TelegramBotSummarizer\Web\SummarizerWebUi;

/**
 * B1 convention guard: manifest `t:` keys and translations() keys match 1:1
 * for the summarizer module — every manifest key resolves in every declared
 * locale, and no catalog key is left unreferenced by the manifest.
 */
it('resolves every manifest translation key in every declared locale', function () {
    $manifest = SummarizerWebUi::manifest();
    $translations = SummarizerWebUi::translations();

    expect(array_keys($translations))->toBe(['en', 'ru', 'fr', 'es', 'zh']);

    foreach (array_keys($translations) as $locale) {
        $buffer = new RefineFindingBuffer();

        MenuCatalog::compileLocalized(
            $locale,
            ['summarizer' => $manifest],
            ['summarizer' => $translations],
            $buffer,
        );

        expect($buffer->snapshot()->all())->toBe([], "unresolved manifest keys in locale '$locale'");
    }
});

it('keeps no catalog key the manifest never references', function () {
    $manifest = SummarizerWebUi::manifest();
    $translations = SummarizerWebUi::translations();

    foreach (array_keys($translations['en']) as $key) {
        $trimmed = $translations;
        unset($trimmed['en'][$key]);
        $buffer = new RefineFindingBuffer();

        MenuCatalog::compileLocalized(
            'en',
            ['summarizer' => $manifest],
            ['summarizer' => $trimmed],
            $buffer,
        );

        $unreferenced = array_filter(
            $buffer->snapshot()->all(),
            fn (RefineFinding $finding): bool => $finding->code === RefineFinding::TRANSLATION_KEY_UNRESOLVED
                && $finding->subject === $key,
        );

        expect($unreferenced)->not->toBeEmpty("catalog key '$key' is never referenced by the manifest");
    }
});
