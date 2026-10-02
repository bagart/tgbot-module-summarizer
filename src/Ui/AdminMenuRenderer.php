<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Ui;

use BAGArt\TelegramBot\TgApi\Types\DTO\InlineKeyboardButtonTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\InlineKeyboardMarkupTypeDTO;
use BAGArt\TelegramBotSummarizer\Llm\LlmProviderRegistry;
use BAGArt\TelegramBotSummarizer\Models\SummarizerToken;
use BAGArt\TelegramBotSummarizer\Models\SummarizerRun;
use BAGArt\TelegramBotSummarizer\Prompt\PromptTemplateRegistry;
use BAGArt\TelegramBotSummarizer\Settings\SummarizerSettings;

/**
 * Builds admin-menu texts + inline keyboards. Pure formatting — no I/O.
 *
 * Each render method accepts a `callable $t` closure:
 *   fn(string $key, array $replacements = []): string
 * The caller (AdminMenuProcessor / SummarizerCommandProcessor) creates it
 * from SummarizerStrings::get() with the chat's locale.
 */
class AdminMenuRenderer
{
    public function __construct(
        private readonly LlmProviderRegistry $providers,
        private readonly PromptTemplateRegistry $templates,
    ) {
    }

    /**
     * @param  list<SummarizerToken>  $tokens
     * @param  callable(string, array<string, string>): string  $t  i18n lookup
     * @return array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}
     */
    public function main(int $chatId, SummarizerSettings $settings, bool $enabled, array $tokens, callable $t): array
    {
        $tokenLabel = $this->activeTokenLabel($settings, $tokens, $t);
        $providerName = $this->providerName($settings->providerKey, $t);
        $modelLabel = $settings->modelOverride ?? $this->defaultModel($settings->providerKey);

        $status = $enabled ? $t('menu.status_on') : $t('menu.status_off');
        $templateLabel = $settings->customTemplate !== null
            ? $t('menu.template_custom')
            : ($this->templates->get($settings->templateId)?->name ?? $settings->templateId);

        $text = $t('menu.title')."\n"
            .$t('menu.status_label', ['status' => $status])."\n"
            .$t('menu.interval_label', ['interval' => $this->formatInterval($settings->intervalMinutes)])."\n"
            .$t('menu.provider_label', ['provider' => $providerName])."\n"
            .$t('menu.model_label', ['model' => $modelLabel])."\n"
            .$t('menu.active_token_label', ['token' => $tokenLabel])."\n"
            .$t('menu.template_label', ['template' => $templateLabel])."\n"
            .$t('menu.min_messages_label', ['count' => (string) $settings->minMessages])."\n\n"
            .$t('menu.privacy_hint');

        $turnOff = $enabled
            ? $t('menu.btn.turn_off')
            : $t('menu.btn.turn_on');
        $turnVerb = $enabled ? CallbackRoute::VERB_DISABLE : CallbackRoute::VERB_ENABLE;

        $rows = [
            [$this->button($turnOff, $chatId, $turnVerb)],
            [$this->button($t('menu.btn.interval'), $chatId, CallbackRoute::VERB_PAGE_INTERVALS)],
            [$this->button($t('menu.btn.llm_provider'), $chatId, CallbackRoute::VERB_PAGE_PROVIDERS)],
            [$this->button($t('menu.btn.model'), $chatId, CallbackRoute::VERB_PAGE_MODELS)],
            [$this->button($t('menu.btn.tokens'), $chatId, CallbackRoute::VERB_PAGE_TOKENS)],
            [$this->button($t('menu.btn.template'), $chatId, CallbackRoute::VERB_PAGE_TEMPLATES)],
            [
                $this->button($t('menu.btn.min_messages'), $chatId, CallbackRoute::VERB_MIN_MESSAGES),
                $this->button($t('menu.btn.run_now'), $chatId, CallbackRoute::VERB_RUN_NOW),
            ],
            [$this->button($t('menu.btn.history'), $chatId, CallbackRoute::VERB_PAGE_HISTORY)],
            [$this->button($t('menu.btn.close'), $chatId, CallbackRoute::VERB_CLOSE)],
        ];

        return ['text' => $text, 'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows)];
    }

    /**
     * @param  callable(string, array<string, string>): string  $t  i18n lookup
     * @return array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}
     */
    public function intervals(int $chatId, SummarizerSettings $settings, callable $t): array
    {
        $rows = [];
        $row = [];

        foreach (SummarizerSettings::INTERVAL_CHOICES as $minutes) {
            $label = $this->formatInterval($minutes).($settings->intervalMinutes === $minutes ? ' ●' : '');
            $row[] = $this->button($label, $chatId, CallbackRoute::VERB_SET_INTERVAL, (string) $minutes);

            if (count($row) === 4) {
                $rows[] = $row;
                $row = [];
            }
        }

        if ($row !== []) {
            $rows[] = $row;
        }

        $rows[] = [$this->button($t('interval.btn.back'), $chatId, CallbackRoute::VERB_MENU)];

        return [
            'text' => $t('interval.title')."\n".$t('interval.description'),
            'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows),
        ];
    }

    /**
     * @param  callable(string, array<string, string>): string  $t  i18n lookup
     * @return array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}
     */
    public function providers(int $chatId, SummarizerSettings $settings, callable $t): array
    {
        $rows = [];

        foreach ($this->providers->all() as $preset) {
            $marker = $settings->providerKey === $preset->key ? ' ●' : '';
            $needsKey = $preset->needsToken ? '' : ' '.$t('provider.no_key');
            $rows[] = [$this->button("{$preset->name}{$needsKey}{$marker}", $chatId, CallbackRoute::VERB_SET_PROVIDER, $preset->key)];
        }

        $customMarker = $settings->providerKey === LlmProviderRegistry::CUSTOM_KEY ? ' ●' : '';
        $rows[] = [$this->button($t('provider.custom_button').$customMarker, $chatId, CallbackRoute::VERB_CUSTOM_PROVIDER)];

        return [
            'text' => $t('provider.title')."\n"
                .$t('provider.current', ['provider' => $this->providerName($settings->providerKey, $t)])
                ."\n".$t('provider.description'),
            'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows),
        ];
    }

    /**
     * @param  list<SummarizerToken>  $tokens
     * @param  callable(string, array<string, string>): string  $t  i18n lookup
     * @return array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}
     */
    public function tokens(int $chatId, SummarizerSettings $settings, array $tokens, callable $t): array
    {
        $lines = [$t('token.title'), $t('token.description')];
        $rows = [];

        foreach ($tokens as $token) {
            $active = $settings->activeTokenId === $token->id;
            $label = sprintf('%s%s', $active ? '● ' : '', $token->masked());
            $rows[] = [
                $this->button($active ? $label.' '.$t('token.active_suffix') : $label, $chatId, CallbackRoute::VERB_SELECT_TOKEN, $token->id),
                $this->button('🗑', $chatId, CallbackRoute::VERB_DELETE_TOKEN, $token->id),
            ];
            $lines[] = sprintf('%s · %s · added by %s', $token->masked(), $this->providerName($token->provider_key, $t), $token->created_by_username ?: ('id'.$token->created_by_tg_id));
        }

        if ($tokens === []) {
            $lines[] = "\n".$t('token.empty');
        }

        foreach (array_keys($this->providers->all()) as $presetKey) {
            $preset = $this->providers->get($presetKey);
            $rows[] = [$this->button($t('token.add_key', ['provider' => $preset->name]), $chatId, CallbackRoute::VERB_ADD_TOKEN, $presetKey)];
        }
        $rows[] = [$this->button($t('token.add_custom_key'), $chatId, CallbackRoute::VERB_ADD_TOKEN, LlmProviderRegistry::CUSTOM_KEY)];

        $rows[] = [$this->button($t('token.btn.back'), $chatId, CallbackRoute::VERB_MENU)];

        return ['text' => implode("\n", $lines), 'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows)];
    }

    /**
     * @param  callable(string, array<string, string>): string  $t  i18n lookup
     * @return array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}
     */
    public function templates(int $chatId, SummarizerSettings $settings, callable $t): array
    {
        $rows = [];

        foreach ($this->templates->all() as $template) {
            $marker = $settings->customTemplate === null && $settings->templateId === $template->id ? ' ●' : '';
            $rows[] = [$this->button($template->name.$marker, $chatId, CallbackRoute::VERB_SET_TEMPLATE, $template->id)];
        }

        $customMarker = $settings->customTemplate !== null ? ' ●' : '';
        $rows[] = [$this->button($t('template.custom_button').$customMarker, $chatId, CallbackRoute::VERB_CUSTOM_TEMPLATE)];
        $rows[] = [$this->button($t('template.btn.back'), $chatId, CallbackRoute::VERB_MENU)];

        return [
            'text' => $t('template.title')."\n".$t('template.description')."\n".$t('template.placeholders'),
            'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows),
        ];
    }

    /**
     * @param  callable(string, array<string, string>): string  $t  i18n lookup
     * @return array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}
     */
    public function models(int $chatId, SummarizerSettings $settings, callable $t): array
    {
        $rows = [];
        $models = $this->providers->modelsFor($settings->providerKey);

        foreach ($models as $model) {
            $marker = ($settings->modelOverride ?? $this->defaultModel($settings->providerKey)) === $model ? ' ●' : '';
            $rows[] = [$this->button($model.$marker, $chatId, CallbackRoute::VERB_SET_MODEL, $model)];
        }

        $customMarker = $settings->modelOverride !== null && ! in_array($settings->modelOverride, $models, true) ? ' ●' : '';
        $rows[] = [$this->button($t('model.custom_button').$customMarker, $chatId, CallbackRoute::VERB_CUSTOM_MODEL)];

        if ($settings->modelOverride !== null) {
            $rows[] = [$this->button($t('model.reset_button'), $chatId, CallbackRoute::VERB_SET_MODEL, '__reset__')];
        }

        $rows[] = [$this->button($t('model.btn.back'), $chatId, CallbackRoute::VERB_MENU)];

        return [
            'text' => $t('model.title')."\n"
                .$t('model.current', ['model' => $settings->modelOverride ?? $this->defaultModel($settings->providerKey)])
                ."\n".$t('model.description'),
            'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows),
        ];
    }

    /**
     * @param  list<SummarizerRun>  $runs
     * @param  callable(string, array<string, string>): string  $t  i18n lookup
     * @return array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}
     */
    public function history(int $chatId, array $runs, int $page, int $totalPages, callable $t): array
    {
        $lines = [$t('history.title')];

        if ($runs === []) {
            $lines[] = $t('history.empty');
        }

        foreach ($runs as $run) {
            $statusEmoji = $run->status === 'success' ? '✅' : '❌';
            $date = $run->created_at?->format('d.m.Y H:i') ?? '?';
            $duration = $run->duration_ms !== null ? round($run->duration_ms / 1000, 1).'s' : '?';
            $lines[] = "{$statusEmoji} {$date} · {$run->provider_key} · {$duration}";
        }

        $rows = [];

        foreach ($runs as $run) {
            $rows[] = [$this->button($t('history.view_button', ['date' => $run->created_at?->format('d.m H:i') ?? '?']), $chatId, CallbackRoute::VERB_HISTORY_VIEW, $run->id)];
        }

        if ($totalPages > 1) {
            $navRow = [];
            if ($page > 1) {
                $navRow[] = $this->button('◀️', $chatId, CallbackRoute::VERB_HISTORY_PAGE, (string) ($page - 1));
            }
            $navRow[] = $this->button("{$page}/{$totalPages}", $chatId, CallbackRoute::VERB_PAGE_HISTORY);
            if ($page < $totalPages) {
                $navRow[] = $this->button('▶️', $chatId, CallbackRoute::VERB_HISTORY_PAGE, (string) ($page + 1));
            }
            $rows[] = $navRow;
        }

        $rows[] = [$this->button($t('history.btn.back'), $chatId, CallbackRoute::VERB_MENU)];

        return ['text' => implode("\n", $lines), 'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows)];
    }

    /**
     * @param  callable(string, array<string, string>): string  $t  i18n lookup
     * @return array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}
     */
    public function historyView(int $chatId, SummarizerRun $run, callable $t): array
    {
        $statusEmoji = $run->status === 'success' ? '✅' : '❌';
        $date = $run->created_at?->format('d.m.Y H:i') ?? '?';
        $duration = $run->duration_ms !== null ? round($run->duration_ms / 1000, 1).'s' : '?';

        $text = $t('history.view_title')."\n"
            .$t('history.view_date', ['date' => $date])."\n"
            .$t('history.view_provider', ['provider' => $run->provider_key])."\n"
            .$t('history.view_model', ['model' => $run->model ?? '?'])."\n"
            .$t('history.view_status', ['status' => $statusEmoji])."\n"
            .$t('history.view_duration', ['duration' => $duration])."\n"
            .$t('history.view_messages', ['count' => (string) $run->message_count])."\n\n"
            .($run->summary_text ?? $t('history.view_no_summary'));

        $rows = [[$this->button($t('history.btn.back_to_list'), $chatId, CallbackRoute::VERB_PAGE_HISTORY)]];

        return ['text' => $text, 'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows)];
    }

    private function button(string $label, int $chatId, string $verb, ?string $arg = null): InlineKeyboardButtonTypeDTO
    {
        return new InlineKeyboardButtonTypeDTO(text: $label, callbackData: CallbackRoute::encode($chatId, $verb, $arg));
    }

    /**
     * @param  list<SummarizerToken>  $tokens
     * @param  callable(string, array<string, string>): string  $t  i18n lookup
     */
    private function activeTokenLabel(SummarizerSettings $settings, array $tokens, callable $t): string
    {
        if ($settings->activeTokenId === null) {
            return $t('active_token.none');
        }

        foreach ($tokens as $token) {
            if ($token->id === $settings->activeTokenId) {
                return $token->masked().' ('.$this->providerName($token->provider_key, $t).')';
            }
        }

        return $t('active_token.missing');
    }

    /**
     * @param  callable(string, array<string, string>): string  $t  i18n lookup
     */
    private function providerName(string $key, callable $t): string
    {
        if ($key === LlmProviderRegistry::CUSTOM_KEY) {
            return $t('provider_name.custom');
        }

        return $this->providers->get($key)?->name ?? $key;
    }

    private function formatInterval(int $minutes): string
    {
        return match (true) {
            $minutes % 1440 === 0 => intdiv($minutes, 1440).'d',
            $minutes % 60 === 0 => intdiv($minutes, 60).'h',
            default => $minutes.'m',
        };
    }

    private function defaultModel(string $providerKey): string
    {
        return $this->providers->get($providerKey)?->model ?? '';
    }
}
