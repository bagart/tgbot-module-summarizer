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
            title: 't:summarizer.title',
            icon: '📝',
            kind: UiKind::Setting,
            minAudience: UiAudience::Admin,
            description: 't:summarizer.description',
            entry: UiEntry::schema([
                UiGroup::of('digest', 't:summarizer.group.digest', [
                    UiField::enum('interval_minutes', 't:summarizer.field.interval_minutes', options: array_map(
                        static fn (int $minutes): array => ['value' => $minutes, 'label' => self::intervalLabel($minutes)],
                        SummarizerSettings::INTERVAL_CHOICES,
                    ), default: SummarizerSettings::DEFAULT_INTERVAL_MINUTES),
                    new UiField('min_messages', 't:summarizer.field.min_messages', UiFieldType::Int, default: SummarizerSettings::DEFAULT_MIN_MESSAGES, extra: ['min' => 1, 'max' => 5000]),
                ]),
                UiGroup::of('model', 't:summarizer.group.model', [
                    UiField::enum('provider_key', 't:summarizer.field.provider_key', options: self::providerOptions(), default: 'openai'),
                    UiField::enum('template_id', 't:summarizer.field.template_id', options: self::templateOptions(), default: 'witty'),
                ]),
            ]),
            actions: [
                new UiAction(
                    id: 'run-now',
                    label: 't:summarizer.action.run_now',
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
            'en' => [
                'summarizer.title' => 'Chat Summarizer',
                'summarizer.description' => 'Scheduled LLM digests of the chat',
                'summarizer.group.digest' => 'Digest',
                'summarizer.field.interval_minutes' => 'Interval',
                'summarizer.field.min_messages' => 'Min messages to trigger',
                'summarizer.group.model' => 'Model and style',
                'summarizer.field.provider_key' => 'LLM provider',
                'summarizer.field.template_id' => 'Digest style',
                'summarizer.action.run_now' => 'Run digest now',
            ],
            'ru' => [
                'summarizer.title' => 'Суммаризатор чата',
                'summarizer.description' => 'Периодические LLM-дайджесты чата',
                'summarizer.group.digest' => 'Дайджест',
                'summarizer.field.interval_minutes' => 'Интервал',
                'summarizer.field.min_messages' => 'Мин. сообщений для запуска',
                'summarizer.group.model' => 'Модель и стиль',
                'summarizer.field.provider_key' => 'LLM-провайдер',
                'summarizer.field.template_id' => 'Стиль дайджеста',
                'summarizer.action.run_now' => 'Собрать дайджест сейчас',
            ],
            'fr' => [
                'summarizer.title' => 'Résumé de chat',
                'summarizer.description' => 'Digests LLM planifiés du chat',
                'summarizer.group.digest' => 'Digest',
                'summarizer.field.interval_minutes' => 'Intervalle',
                'summarizer.field.min_messages' => 'Messages minimum pour déclencher',
                'summarizer.group.model' => 'Modèle et style',
                'summarizer.field.provider_key' => 'Fournisseur LLM',
                'summarizer.field.template_id' => 'Style de digest',
                'summarizer.action.run_now' => 'Exécuter le digest maintenant',
            ],
            'es' => [
                'summarizer.title' => 'Resumen de chat',
                'summarizer.description' => 'Digests LLM programados del chat',
                'summarizer.group.digest' => 'Digest',
                'summarizer.field.interval_minutes' => 'Intervalo',
                'summarizer.field.min_messages' => 'Mín. mensajes para activar',
                'summarizer.group.model' => 'Modelo y estilo',
                'summarizer.field.provider_key' => 'Proveedor LLM',
                'summarizer.field.template_id' => 'Estilo de digest',
                'summarizer.action.run_now' => 'Ejecutar digest ahora',
            ],
            'zh' => [
                'summarizer.title' => '聊天摘要',
                'summarizer.description' => '定期LLM聊天摘要',
                'summarizer.group.digest' => '摘要',
                'summarizer.field.interval_minutes' => '间隔',
                'summarizer.field.min_messages' => '触发所需最少消息数',
                'summarizer.group.model' => '模型和风格',
                'summarizer.field.provider_key' => 'LLM服务商',
                'summarizer.field.template_id' => '摘要风格',
                'summarizer.action.run_now' => '立即生成摘要',
            ],
        ];
    }

    public function validate(array $raw): array
    {
        $patch = [];

        if (array_key_exists('interval_minutes', $raw)) {
            // Same clamp the DTO applies on read (15 min … 7 days).
            $patch['interval_minutes'] = max(15, min(10080, (int) $raw['interval_minutes']));
        }

        if (array_key_exists('min_messages', $raw)) {
            $patch['min_messages'] = max(1, min(5000, (int) $raw['min_messages']));
        }

        if (array_key_exists('provider_key', $raw)) {
            $providerKey = (string) $raw['provider_key'];

            if (! (new LlmProviderRegistry())->has($providerKey)) {
                throw new InvalidArgumentException('Unknown provider_key value.');
            }

            $patch['provider_key'] = $providerKey;
        }

        if (array_key_exists('template_id', $raw)) {
            $templateId = (string) $raw['template_id'];

            if (! (new PromptTemplateRegistry())->has($templateId)) {
                throw new InvalidArgumentException('Unknown template_id value.');
            }

            $patch['template_id'] = $templateId;
        }

        return $patch;
    }

    /**
     * The settings surface never reports needs_setup: a missing LLM key
     * surfaces as a digest error, not a broken config. Enablement is not
     * part of this form — the in-chat panel (reserved `enabled` patch) and
     * the hub per-chat toggle own it.
     */
    public function isConfigured(array $settings): bool
    {
        return true;
    }

    /** @return list<array{value: string, label: string}> */
    private static function providerOptions(): array
    {
        $options = [];

        foreach ((new LlmProviderRegistry())->all() as $preset) {
            $options[] = ['value' => $preset->key, 'label' => $preset->name];
        }

        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    private static function templateOptions(): array
    {
        $options = [];

        foreach ((new PromptTemplateRegistry())->all() as $template) {
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
