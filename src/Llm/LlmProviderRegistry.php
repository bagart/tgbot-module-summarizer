<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Llm;

use InvalidArgumentException;

/**
 * Catalog of LLM providers available for one-click selection plus the
 * validator for admin-authored custom provider configs (JSON editor flow).
 */
class LlmProviderRegistry
{
    public const CUSTOM_KEY = 'custom';

    /** @var array<string, LlmProviderPreset> */
    private array $presets;

    /**
     * Curated model lists per provider key for the admin model picker.
     * The first entry is the default (preset) model.
     *
     * @var array<string, list<string>>
     */
    private const MODEL_LISTS = [
        'openai' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo'],
        'anthropic' => ['claude-3-5-haiku-latest', 'claude-3-5-sonnet-latest', 'claude-3-opus-latest'],
        'google' => ['gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-pro'],
        'deepseek' => ['deepseek-chat', 'deepseek-reasoner'],
        'mistral' => ['mistral-small-latest', 'mistral-medium-latest', 'mistral-large-latest'],
        'groq' => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768'],
        'xai' => ['grok-3-mini', 'grok-3', 'grok-2'],
        'openrouter' => ['openrouter/auto'],
        'together' => ['meta-llama/Llama-3.3-70B-Instruct-Turbo', 'meta-llama/Meta-Llama-3.1-8B-Instruct-Turbo'],
        'ollama' => ['llama3.1', 'mistral', 'codellama', 'qwen2.5'],
    ];

    public function __construct()
    {
        $presets = [
            new LlmProviderPreset('openai', 'OpenAI', 'https://api.openai.com/v1', 'gpt-4o-mini', LlmApiStyle::Openai),
            new LlmProviderPreset('anthropic', 'Anthropic Claude', 'https://api.anthropic.com/v1', 'claude-3-5-haiku-latest', LlmApiStyle::Anthropic),
            new LlmProviderPreset('google', 'Google Gemini', 'https://generativelanguage.googleapis.com/v1beta/openai', 'gemini-2.0-flash', LlmApiStyle::Openai),
            new LlmProviderPreset('deepseek', 'DeepSeek', 'https://api.deepseek.com/v1', 'deepseek-chat', LlmApiStyle::Openai),
            new LlmProviderPreset('mistral', 'Mistral AI', 'https://api.mistral.ai/v1', 'mistral-small-latest', LlmApiStyle::Openai),
            new LlmProviderPreset('groq', 'Groq', 'https://api.groq.com/openai/v1', 'llama-3.3-70b-versatile', LlmApiStyle::Openai),
            new LlmProviderPreset('xai', 'xAI Grok', 'https://api.x.ai/v1', 'grok-3-mini', LlmApiStyle::Openai),
            new LlmProviderPreset('openrouter', 'OpenRouter', 'https://openrouter.ai/api/v1', 'openrouter/auto', LlmApiStyle::Openai),
            new LlmProviderPreset('together', 'Together AI', 'https://api.together.xyz/v1', 'meta-llama/Llama-3.3-70B-Instruct-Turbo', LlmApiStyle::Openai),
            new LlmProviderPreset('ollama', 'Ollama (local)', 'http://localhost:11434/v1', 'llama3.1', LlmApiStyle::Openai, needsToken: false),
        ];

        $this->presets = [];
        foreach ($presets as $preset) {
            $this->presets[$preset->key] = $preset;
        }
    }

    /** @return array<string, LlmProviderPreset> */
    public function all(): array
    {
        return $this->presets;
    }

    public function has(string $key): bool
    {
        return $key === self::CUSTOM_KEY || isset($this->presets[$key]);
    }

    public function get(string $key): ?LlmProviderPreset
    {
        return $this->presets[$key] ?? null;
    }

    /**
     * Curated model list for the given provider key. Returns an empty array
     * for unknown keys (the admin can still type a custom model string).
     *
     * @return list<string>
     */
    public function modelsFor(string $providerKey): array
    {
        return self::MODEL_LISTS[$providerKey] ?? [];
    }

    /**
     * Pre-generated JSON shown to the admin in the custom-provider editor.
     */
    public function customTemplateJson(): string
    {
        $template = [
            'name' => 'My LLM gateway',
            'base_url' => 'https://api.example.com/v1',
            'model' => 'model-id-from-provider',
            'api_style' => 'openai',
            'temperature' => 0.4,
            'max_tokens' => 1500,
            'timeout_seconds' => 90,
            'extra_headers' => new \stdClass(),
        ];

        return json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Validate an admin-submitted custom provider config (assoc array from
     * JSON). Returns normalized config or throws InvalidArgumentException
     * with a human-readable reason.
     *
     * @return array<string, mixed>
     */
    public function validateCustomConfig(string $json): array
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Invalid JSON: '.$e->getMessage());
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('JSON must encode an object.');
        }

        $baseUrl = trim((string) ($data['base_url'] ?? ''));
        if ($baseUrl === '' || ! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('base_url must be a valid absolute URL.');
        }

        $host = parse_url($baseUrl, PHP_URL_HOST) ?? '';
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_starts_with($host, '192.168.')
            || str_starts_with($host, '10.');

        if (! str_starts_with($baseUrl, 'https://') && ! $isLocal) {
            throw new InvalidArgumentException('base_url must use https (http is allowed only for local addresses).');
        }

        $model = trim((string) ($data['model'] ?? ''));
        if ($model === '') {
            throw new InvalidArgumentException('model is required.');
        }

        $style = strtolower(trim((string) ($data['api_style'] ?? 'openai')));
        if (! in_array($style, [LlmApiStyle::Openai->value, LlmApiStyle::Anthropic->value], true)) {
            throw new InvalidArgumentException("api_style must be 'openai' or 'anthropic'.");
        }

        $extraHeaders = $data['extra_headers'] ?? [];
        if (! is_array($extraHeaders)) {
            throw new InvalidArgumentException('extra_headers must be an object of header => value.');
        }
        foreach ($extraHeaders as $header => $value) {
            if (! is_string($header) || ! is_scalar($value)) {
                throw new InvalidArgumentException('extra_headers must map header names to string values.');
            }
        }

        return [
            'name' => mb_substr(trim((string) ($data['name'] ?? 'Custom provider')), 0, 60),
            'base_url' => rtrim($baseUrl, '/'),
            'model' => mb_substr($model, 0, 100),
            'api_style' => $style,
            'temperature' => max(0.0, min(2.0, (float) ($data['temperature'] ?? 0.4))),
            'max_tokens' => max(100, min(8000, (int) ($data['max_tokens'] ?? 1500))),
            'timeout_seconds' => max(10, min(300, (int) ($data['timeout_seconds'] ?? 90))),
            'extra_headers' => array_map('strval', $extraHeaders),
        ];
    }
}
