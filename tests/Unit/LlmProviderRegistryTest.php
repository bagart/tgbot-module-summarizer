<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Tests\Unit;

use BAGArt\TelegramBotSummarizer\Llm\LlmApiStyle;
use BAGArt\TelegramBotSummarizer\Llm\LlmProviderRegistry;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LlmProviderRegistryTest extends TestCase
{
    public function test_ships_presets_for_all_major_providers(): void
    {
        $registry = new LlmProviderRegistry();

        foreach ($registry->all() as $preset) {
            self::assertNotSame('', $preset->key);
            self::assertNotSame('', $preset->name);
            self::assertNotSame('', $preset->model);
            self::assertContains($preset->apiStyle, [LlmApiStyle::Openai, LlmApiStyle::Anthropic]);
        }

        $keys = array_keys($registry->all());

        foreach (['openai', 'anthropic', 'google', 'deepseek', 'mistral', 'groq', 'xai', 'openrouter', 'together', 'ollama'] as $expected) {
            self::assertContains($expected, $keys);
        }
    }

    public function test_uses_https_everywhere_except_local_ollama(): void
    {
        foreach ((new LlmProviderRegistry())->all() as $preset) {
            if ($preset->key === 'ollama') {
                self::assertStringStartsWith('http://localhost', $preset->baseUrl);

                continue;
            }

            self::assertStringStartsWith('https://', $preset->baseUrl);
        }
    }

    public function test_generates_a_custom_provider_template_that_passes_validation(): void
    {
        $registry = new LlmProviderRegistry();
        $config = $registry->validateCustomConfig($registry->customTemplateJson());

        self::assertSame('https://api.example.com/v1', $config['base_url']);
        self::assertSame('model-id-from-provider', $config['model']);
        self::assertSame('openai', $config['api_style']);
    }

    #[DataProvider('invalidConfigs')]
    public function test_rejects_invalid_custom_configs(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LlmProviderRegistry())->validateCustomConfig($json);
    }

    public static function invalidConfigs(): Generator
    {
        yield 'broken json' => ['{broken json'];
        yield 'missing model' => [json_encode(['base_url' => 'https://x.test/v1'])];
        yield 'insecure remote base_url' => [json_encode(['base_url' => 'http://evil.test/v1', 'model' => 'm'])];
        yield 'unknown api_style' => [json_encode(['base_url' => 'https://x.test/v1', 'model' => 'm', 'api_style' => 'graphql'])];
        yield 'non-url base_url' => [json_encode(['base_url' => 'not a url', 'model' => 'm'])];
    }

    public function test_accepts_http_only_for_local_addresses(): void
    {
        $config = (new LlmProviderRegistry())->validateCustomConfig(json_encode([
            'base_url' => 'http://192.168.1.10:8000/v1',
            'model' => 'local-model',
        ]));

        self::assertSame('http://192.168.1.10:8000/v1', $config['base_url']);
        self::assertSame('Custom provider', $config['name']);
    }

    public function test_normalizes_trailing_slashes_and_clamps_numeric_ranges(): void
    {
        $config = (new LlmProviderRegistry())->validateCustomConfig(json_encode([
            'name' => str_repeat('N', 200),
            'base_url' => 'https://gw.test/v1/',
            'model' => 'm',
            'temperature' => 9.9,
            'max_tokens' => 999999,
            'timeout_seconds' => 1,
        ]));

        self::assertSame('https://gw.test/v1', $config['base_url']);
        self::assertSame(2.0, $config['temperature']);
        self::assertSame(8000, $config['max_tokens']);
        self::assertSame(10, $config['timeout_seconds']);
        self::assertSame(60, mb_strlen($config['name']));
    }

    public function test_every_preset_has_a_non_empty_curated_model_list(): void
    {
        $registry = new LlmProviderRegistry();

        foreach ($registry->all() as $preset) {
            $models = $registry->modelsFor($preset->key);

            self::assertNotEmpty($models, "Preset '{$preset->key}' has no curated models");
        }
    }

    public function test_default_preset_model_is_first_in_its_curated_list(): void
    {
        $registry = new LlmProviderRegistry();

        foreach ($registry->all() as $preset) {
            $models = $registry->modelsFor($preset->key);

            self::assertSame(
                $preset->model,
                $models[0],
                "Preset '{$preset->key}' default model must lead its curated list",
            );
        }
    }

    public function test_models_for_returns_empty_for_unknown_and_custom_keys(): void
    {
        $registry = new LlmProviderRegistry();

        self::assertSame([], $registry->modelsFor('no-such-provider'));
        self::assertSame([], $registry->modelsFor(LlmProviderRegistry::CUSTOM_KEY));
    }
}
