<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Tests\Unit;

use BAGArt\TelegramBotSummarizer\I18n\SummarizerStrings;
use PHPUnit\Framework\TestCase;

final class SummarizerStringsTest extends TestCase
{
    /**
     * EN and RU are the reference production locales and must be fully
     * complete. FR/ES/ZH completeness is enforced in
     * test_fr_es_zh_catalogs_are_translated_and_complete.
     */
    public function test_en_and_ru_catalogs_are_complete(): void
    {
        $reflection = new \ReflectionClass(SummarizerStrings::class);
        $constant = $reflection->getConstant('CATALOG');

        self::assertIsArray($constant);

        $enKeys = array_keys($constant['en'] ?? []);

        foreach (['en', 'ru'] as $locale) {
            $localeKeys = array_keys($constant[$locale] ?? []);
            $missing = array_diff($enKeys, $localeKeys);

            self::assertEmpty(
                $missing,
                "Locale '{$locale}' is missing keys: ".implode(', ', $missing),
            );
        }
    }

    public function test_get_returns_key_when_missing_in_catalog(): void
    {
        $result = SummarizerStrings::get('en', 'nonexistent.key');

        self::assertSame('nonexistent.key', $result);
    }

    public function test_get_falls_back_to_en_for_unknown_locale(): void
    {
        $result = SummarizerStrings::get('xx', 'menu.title');

        self::assertSame('⚙️ <b>Chat Summarizer</b>', $result);
    }

    public function test_get_replaces_placeholders(): void
    {
        $result = SummarizerStrings::get('en', 'menu.status_label', ['status' => '✅ ON']);

        self::assertSame('Status: ✅ ON', $result);
    }

    public function test_get_russian_title(): void
    {
        $result = SummarizerStrings::get('ru', 'menu.title');

        self::assertSame('⚙️ <b>Саммарайзер чата</b>', $result);
    }

    /**
     * FR/ES/ZH were once EN-fallback stubs; they now ship full catalogs
     * (module i18n convention: five languages from day one). Every EN key
     * must exist in each locale, and menu.title must be translated rather
     * than falling back to the EN string.
     */
    public function test_fr_es_zh_catalogs_are_translated_and_complete(): void
    {
        $reflection = new \ReflectionClass(SummarizerStrings::class);
        $catalog = $reflection->getConstant('CATALOG');

        self::assertIsArray($catalog);

        $enKeys = array_keys($catalog['en']);

        foreach (['fr', 'es', 'zh'] as $locale) {
            $missing = array_diff($enKeys, array_keys($catalog[$locale] ?? []));

            self::assertEmpty(
                $missing,
                "Locale '{$locale}' is missing keys: ".implode(', ', $missing),
            );

            self::assertNotSame(
                '⚙️ <b>Chat Summarizer</b>',
                SummarizerStrings::get($locale, 'menu.title'),
                "Locale '{$locale}' should translate menu.title instead of falling back to EN",
            );
        }
    }

    public function test_locale_constants_match_catalog_keys(): void
    {
        $reflection = new \ReflectionClass(SummarizerStrings::class);
        $catalog = $reflection->getConstant('CATALOG');

        self::assertIsArray($catalog);

        $constants = [
            SummarizerStrings::LOCALE_EN,
            SummarizerStrings::LOCALE_RU,
            SummarizerStrings::LOCALE_FR,
            SummarizerStrings::LOCALE_ES,
            SummarizerStrings::LOCALE_ZH,
        ];

        foreach ($constants as $locale) {
            self::assertArrayHasKey($locale, $catalog, "Constant locale '{$locale}' must have a catalog entry");
        }
    }

    public function test_all_error_keys_exist(): void
    {
        $reflection = new \ReflectionClass(SummarizerStrings::class);
        $catalog = $reflection->getConstant('CATALOG');

        $enKeys = array_keys($catalog['en']);
        $errorKeys = array_filter($enKeys, fn ($key) => str_starts_with($key, 'error.'));

        self::assertNotEmpty($errorKeys, 'Should have error keys in catalog');

        foreach ($errorKeys as $key) {
            foreach ([SummarizerStrings::LOCALE_RU, SummarizerStrings::LOCALE_FR, SummarizerStrings::LOCALE_ES, SummarizerStrings::LOCALE_ZH] as $locale) {
                self::assertArrayHasKey(
                    $key,
                    $catalog[$locale] ?? [],
                    "Error key '{$key}' must exist in locale '{$locale}'",
                );
            }

            self::assertNotSame(
                $key,
                SummarizerStrings::get('en', $key),
                "Error key '{$key}' should return a translated string, not the key itself",
            );
        }
    }

    public function test_confirm_keys_exist(): void
    {
        $reflection = new \ReflectionClass(SummarizerStrings::class);
        $catalog = $reflection->getConstant('CATALOG');

        $enKeys = array_keys($catalog['en']);
        $confirmKeys = array_filter($enKeys, fn ($key) => str_starts_with($key, 'confirm.'));

        self::assertNotEmpty($confirmKeys);

        foreach ($confirmKeys as $key) {
            self::assertNotSame(
                $key,
                SummarizerStrings::get('en', $key),
                "Confirm key '{$key}' should return a translated string",
            );
        }
    }
}
