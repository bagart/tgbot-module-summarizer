<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Llm;

use BAGArt\TelegramBotSummarizer\Settings\SummarizerSettings;

/**
 * Resolves effective runtime provider configuration from chat settings,
 * the preset catalog and the selected vault token.
 */
class LlmConfigResolver
{
    public function __construct(
        private readonly LlmProviderRegistry $registry,
        private readonly int $defaultTimeoutSeconds,
    ) {
    }

    public function resolve(SummarizerSettings $settings, ?string $tokenValue = null): LlmProviderConfig
    {
        $custom = $settings->customProvider;

        if ($settings->providerKey === LlmProviderRegistry::CUSTOM_KEY && is_array($custom)) {
            return new LlmProviderConfig(
                providerKey: LlmProviderRegistry::CUSTOM_KEY,
                name: (string) ($custom['name'] ?? 'Custom'),
                baseUrl: (string) ($custom['base_url'] ?? ''),
                model: $settings->modelOverride ?? (string) ($custom['model'] ?? ''),
                apiStyle: LlmApiStyle::from((string) ($custom['api_style'] ?? 'openai')),
                token: $tokenValue,
                temperature: (float) ($custom['temperature'] ?? 0.4),
                maxTokens: (int) ($custom['max_tokens'] ?? 1500),
                timeoutSeconds: (int) ($custom['timeout_seconds'] ?? $this->defaultTimeoutSeconds),
                extraHeaders: is_array($custom['extra_headers'] ?? null)
                    ? array_map('strval', $custom['extra_headers'])
                    : [],
            );
        }

        $preset = $this->registry->get($settings->providerKey)
            ?? throw new \InvalidArgumentException("Unknown provider '{$settings->providerKey}'");

        return new LlmProviderConfig(
            providerKey: $preset->key,
            name: $preset->name,
            baseUrl: $preset->baseUrl,
            model: $settings->modelOverride ?? $preset->model,
            apiStyle: $preset->apiStyle,
            token: $tokenValue,
            temperature: 0.4,
            maxTokens: 1500,
            timeoutSeconds: $this->defaultTimeoutSeconds,
        );
    }
}
