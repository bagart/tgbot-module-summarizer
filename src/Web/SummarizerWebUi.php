<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Web;

use BAGArt\TelegramBotMenu\Contracts\TgSettingsFormContract;
use BAGArt\TelegramBotMenu\Contracts\TgWebUiContract;
use BAGArt\TelegramBotMenu\Manifest\EffectiveRole;
use BAGArt\TelegramBotMenu\Manifest\TgWebUiManifest;
use BAGArt\TelegramBotMenu\Manifest\UiAction;
use BAGArt\TelegramBotMenu\Manifest\UiAudience;
use BAGArt\TelegramBotMenu\Manifest\UiEntry;
use BAGArt\TelegramBotMenu\Manifest\UiField;
use BAGArt\TelegramBotMenu\Manifest\UiFieldType;
use BAGArt\TelegramBotMenu\Manifest\UiGroup;
use BAGArt\TelegramBotMenu\Manifest\UiKind;
use BAGArt\TelegramBotSummarizer\Llm\LlmProviderRegistry;
use BAGArt\TelegramBotSummarizer\Prompt\PromptTemplateRegistry;
use BAGArt\TelegramBotSummarizer\Settings\SummarizerModuleId;
use BAGArt\TelegramBotSummarizer\Settings\SummarizerSettings;
use InvalidArgumentException;

/**
 * Menu-hub settings surface for the summarizer (menu_integration.md M-3c):
 * the /summarizer in-chat admin panel mirrored as a schema manifest + §8.3
 * settings form. The panel's «Run now» becomes a §8.9 UiAction executed
 * through the module's own webApi handler (SummarizerUiHandler). LLM API
 * keys stay out of the schema (encrypted token store + in-chat flow, §8.5).
 */
final readonly class SummarizerWebUi implements TgSettingsFormContract, TgWebUiContract
{
    public static function manifest(): TgWebUiManifest
    {
        return new TgWebUiManifest(
            moduleId: SummarizerModuleId::ID,
            title: 'Chat Summarizer',
            icon: '📝',
            kind: UiKind::Setting,
            minAudience: UiAudience::Admin,
            description: 'Scheduled LLM digests of the chat',
            entry: UiEntry::schema([
                UiGroup::of('digest', 'Digest', [
                    UiField::bool('enabled', 'Digests enabled', default: false),
                    UiField::enum('interval_minutes', 'Interval', options: array_map(
                        static fn (int $minutes): array => ['value' => $minutes, 'label' => self::intervalLabel($minutes)],
                        SummarizerSettings::INTERVAL_CHOICES,
                    ), default: SummarizerSettings::DEFAULT_INTERVAL_MINUTES),
                    new UiField('min_messages', 'Min messages to trigger', UiFieldType::Int, default: SummarizerSettings::DEFAULT_MIN_MESSAGES, extra: ['min' => 1, 'max' => 5000]),
                ]),
                UiGroup::of('model', 'Model and style', [
                    UiField::enum('provider_key', 'LLM provider', options: self::providerOptions(), default: 'openai'),
                    UiField::enum('template_id', 'Digest style', options: self::templateOptions(), default: 'witty'),
                ]),
            ]),
            actions: [
                new UiAction(
                    id: 'run-now',
                    label: 'Run digest now',
                    minRole: EffectiveRole::Admin,
                ),
            ],
            sortKey: 'summarizer',
            memberReadVisible: true,
        );
    }

    /** @return array<string, array<string, string>> */
    public static function translations(): array
    {
        return [
            'ru' => [
                'Chat Summarizer' => 'Суммаризатор чата',
                'Scheduled LLM digests of the chat' => 'Периодические LLM-дайджесты чата',
                'Digests enabled' => 'Дайджесты включены',
                'Interval' => 'Интервал',
                'Min messages to trigger' => 'Мин. сообщений для запуска',
                'LLM provider' => 'LLM-провайдер',
                'Digest style' => 'Стиль дайджеста',
                'Run digest now' => 'Собрать дайджест сейчас',
            ],
            'fr' => [
                'Chat Summarizer' => 'Résumé de chat',
                'Scheduled LLM digests of the chat' => 'Digests LLM planifiés du chat',
                'Digests enabled' => 'Digests activés',
                'Interval' => 'Intervalle',
                'Min messages to trigger' => 'Messages minimum pour déclencher',
                'LLM provider' => 'Fournisseur LLM',
                'Digest style' => 'Style de digest',
                'Run digest now' => 'Exécuter le digest maintenant',
            ],
            'es' => [
                'Chat Summarizer' => 'Resumen de chat',
                'Scheduled LLM digests of the chat' => 'Digests LLM programados del chat',
                'Digests enabled' => 'Digests habilitados',
                'Interval' => 'Intervalo',
                'Min messages to trigger' => 'Mín. mensajes para activar',
                'LLM provider' => 'Proveedor LLM',
                'Digest style' => 'Estilo de digest',
                'Run digest now' => 'Ejecutar digest ahora',
            ],
            'zh' => [
                'Chat Summarizer' => '聊天摘要',
                'Scheduled LLM digests of the chat' => '定期LLM聊天摘要',
                'Digests enabled' => '摘要已启用',
                'Interval' => '间隔',
                'Min messages to trigger' => '触发所需最少消息数',
                'LLM provider' => 'LLM服务商',
                'Digest style' => '摘要风格',
                'Run digest now' => '立即生成摘要',
            ],
        ];
    }

    public function validate(array $raw): array
    {
        $patch = [];

        if (array_key_exists('enabled', $raw)) {
            $patch['enabled'] = (bool) $raw['enabled'];
        }

        if (array_key_exists('interval_minutes', $raw)) {
            // Same clamp the DTO applies on read (15 min … 7 days).
            $patch['interval_minutes'] = max(15, min(10080, (int) $raw['interval_minutes']));
        }

        if (array_key_exists('min_messages', $raw)) {
            $patch['min_messages'] = max(1, min(5000, (int) $raw['min_messages']));
        }

        if (array_key_exists('provider_key', $raw)) {
            $providerKey = (string) $raw['provider_key'];

            if (! (new LlmProviderRegistry)->has($providerKey)) {
                throw new InvalidArgumentException('Unknown provider_key value.');
            }

            $patch['provider_key'] = $providerKey;
        }

        if (array_key_exists('template_id', $raw)) {
            $templateId = (string) $raw['template_id'];

            if (! (new PromptTemplateRegistry)->has($templateId)) {
                throw new InvalidArgumentException('Unknown template_id value.');
            }

            $patch['template_id'] = $templateId;
        }

        return $patch;
    }

    /**
     * The settings surface never reports needs_setup: a missing LLM key
     * surfaces as a digest error, not a broken config. Enablement itself is
     * the `enabled` key — the hub toggle and this field write the same row.
     */
    public function isConfigured(array $settings): bool
    {
        return true;
    }

    /** @return list<array{value: string, label: string}> */
    private static function providerOptions(): array
    {
        $options = [];

        foreach ((new LlmProviderRegistry)->all() as $preset) {
            $options[] = ['value' => $preset->key, 'label' => $preset->name];
        }

        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    private static function templateOptions(): array
    {
        $options = [];

        foreach ((new PromptTemplateRegistry)->all() as $template) {
            $options[] = ['value' => $template->id, 'label' => $template->name];
        }

        return $options;
    }

    private static function intervalLabel(int $minutes): string
    {
        return match (true) {
            $minutes >= 1440 => sprintf('%d d', intdiv($minutes, 1440)),
            $minutes >= 60 => sprintf('%d h', intdiv($minutes, 60)),
            default => sprintf('%d min', $minutes),
        };
    }
}
