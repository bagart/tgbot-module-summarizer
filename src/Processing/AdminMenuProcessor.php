<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Processing;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\Processing\Processors\TgModuleProcessorContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\Processing\ErrorHandling\ProcessorErrorContext;
use BAGArt\TelegramBot\TgApi\Methods\DTO\AnswerCallbackQueryMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\CallbackQueryTypeDTO;
use BAGArt\TelegramBotSummarizer\I18n\SummarizerStrings;
use BAGArt\TelegramBotSummarizer\Llm\LlmProviderRegistry;
use BAGArt\TelegramBotSummarizer\Models\SummarizerRun;
use BAGArt\TelegramBotSummarizer\Models\SummarizerToken;
use BAGArt\TelegramBotSummarizer\ModuleFactory;
use BAGArt\TelegramBotSummarizer\Prompt\PromptTemplateRegistry;
use BAGArt\TelegramBotSummarizer\Settings\SummarizerSettingsService;
use BAGArt\TelegramBotSummarizer\Ui\AdminMenuRenderer;
use BAGArt\TelegramBotSummarizer\Ui\CallbackRoute;
use BAGArt\TelegramBotSummarizer\Ui\PendingInputService;
use Throwable;

/**
 * Inline-keyboard router for the /summarizer admin menu. Every press is
 * re-authorized; menus are sent as fresh messages (the parsed CallbackQuery
 * DTO carries no usable originating-message id to edit).
 */
class AdminMenuProcessor implements TgModuleProcessorContract
{
    private function __construct(
        private readonly TgSenderContract $sender,
        private readonly SummarizerSettingsService $settings,
        private readonly AdminMenuRenderer $menu,
        private readonly PendingInputService $pending,
    ) {
    }

    public static function moduleId(): string
    {
        return 'summarizer';
    }

    public static function build(BotProcessorContext $context): self
    {
        return new self(
            sender: $context->tgSender,
            settings: ModuleFactory::settings(),
            menu: ModuleFactory::menu(),
            pending: ModuleFactory::pending(),
        );
    }

    public function support(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return $dto instanceof CallbackQueryTypeDTO
            && $dto->data !== null
            && CallbackRoute::decode($dto->data) !== null;
    }

    public function isStrictOrdered(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return false;
    }

    public function process(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): void {
        assert($dto instanceof CallbackQueryTypeDTO);

        if (! ModuleFactory::inLaravel()) {
            return;
        }

        $route = CallbackRoute::decode($dto->data);
        $chatId = $route['chatId'] ?? 0;
        $verb = $route['verb'] ?? '';
        $arg = $route['arg'] ?? null;
        $botId = (string) $botConfig->botId;

        try {
            if (! ModuleFactory::access()->canManage($botConfig, $chatId, $dto->from)) {
                $settings = $this->settings->get($botId, $chatId);
                $t = $this->makeTranslator($settings);
                $this->answer(
                    $dto,
                    $t('error.denied_callback'),
                    alert: true,
                );

                return;
            }

            $this->dispatchVerb($dto, $botConfig, $chatId, $verb, $arg);
        } catch (Throwable $e) {
            $settings = $this->settings->get($botId, $chatId);
            $t = $this->makeTranslator($settings);
            $this->answer($botConfig, $dto, $t('error.menu_error', ['message' => $e->getMessage()]), alert: true);
        }
    }

    private function dispatchVerb(
        CallbackQueryTypeDTO $query,
        TgBotConfig $botConfig,
        int $chatId,
        string $verb,
        ?string $arg,
    ): void {
        $botId = (string) $botConfig->botId;
        $settings = $this->settings->get($botId, $chatId);
        $enabled = $this->settings->isEnabled($botId, $chatId);
        $t = $this->makeTranslator($settings);

        switch ($verb) {
            case CallbackRoute::VERB_MENU:
                $this->renderPage($botConfig, $chatId, fn ($s, $tk) => $this->menu->main($chatId, $s, $enabled, $tk, $t));
                $this->answer($botConfig, $query);

                return;

            case CallbackRoute::VERB_PAGE_INTERVALS:
                $this->renderPage($botConfig, $chatId, fn ($s, $tk) => $this->menu->intervals($chatId, $s, $t));
                $this->answer($botConfig, $query);

                return;

            case CallbackRoute::VERB_PAGE_PROVIDERS:
                $this->renderPage($botConfig, $chatId, fn ($s, $tk) => $this->menu->providers($chatId, $s, $t));
                $this->answer($botConfig, $query);

                return;

            case CallbackRoute::VERB_PAGE_TOKENS:
                $this->renderPage($botConfig, $chatId, fn ($s, $tk) => $this->menu->tokens($chatId, $s, $tk, $t));
                $this->answer($botConfig, $query);

                return;

            case CallbackRoute::VERB_PAGE_TEMPLATES:
                $this->renderPage($botConfig, $chatId, fn ($s, $tk) => $this->menu->templates($chatId, $s, $t));
                $this->answer($botConfig, $query);

                return;

            case CallbackRoute::VERB_ENABLE:
            case CallbackRoute::VERB_DISABLE:
                // Reassigns the flag read at dispatchVerb entry: after this
                // reserved-key write $enabled is the post-toggle state.
                $enabled = $verb === CallbackRoute::VERB_ENABLE;
                $this->settings->patch($botId, $chatId, ['enabled' => $enabled]);
                $this->answer($botConfig, $query, $enabled ? $t('confirm.enabled') : $t('confirm.disabled'));
                $this->renderPage($botConfig, $chatId, fn ($s, $tk) => $this->menu->main($chatId, $s, $enabled, $tk, $t));

                return;

            case CallbackRoute::VERB_SET_INTERVAL:
                $minutes = (int) $arg;

                if (! in_array($minutes, [30, 60, 120, 180, 360, 720, 1440], true)) {
                    $this->answer($botConfig, $query, $t('error.unknown_interval'), alert: true);

                    return;
                }

                $this->settings->patch($botId, $chatId, ['interval_minutes' => $minutes]);
                $this->answer($botConfig, $query, $t('confirm.interval_updated'));
                $this->renderPage($botConfig, $chatId, fn ($s, $tk) => $this->menu->intervals($chatId, $s, $t));

                return;

            case CallbackRoute::VERB_SET_PROVIDER:
                $this->selectProvider($query, $botConfig, $chatId, (string) $arg, $t);

                return;

            case CallbackRoute::VERB_CUSTOM_PROVIDER:
                $this->startCustomProviderEditor($query, $botConfig, $chatId, $t);

                return;

            case CallbackRoute::VERB_ADD_TOKEN:
                $this->startTokenInput($query, $botConfig, $chatId, (string) $arg, $t);

                return;

            case CallbackRoute::VERB_SELECT_TOKEN:
                $this->selectToken($query, $botConfig, $chatId, (string) $arg, $t);

                return;

            case CallbackRoute::VERB_DELETE_TOKEN:
                $this->deleteToken($query, $botConfig, $chatId, (string) $arg, $t);

                return;

            case CallbackRoute::VERB_SET_TEMPLATE:
                $templates = new PromptTemplateRegistry();

                if (! $templates->has((string) $arg)) {
                    $this->answer($botConfig, $query, $t('error.unknown_template'), alert: true);

                    return;
                }

                $this->settings->patch($botId, $chatId, ['template_id' => (string) $arg, 'custom_template' => null]);
                $this->answer($botConfig, $query, $t('confirm.template_updated'));
                $this->renderPage($botConfig, $chatId, fn ($s, $tk) => $this->menu->templates($chatId, $s, $t));

                return;

            case CallbackRoute::VERB_CUSTOM_TEMPLATE:
                ModuleFactory::pending()->start(
                    $botId,
                    $chatId,
                    (int) $query->from->id,
                    PendingInputService::ACTION_TEMPLATE,
                );
                $this->answer($botConfig, $query, $t('confirm.digest_started'));
                $this->sendText($botConfig, $chatId, implode("\n", [
                    $t('input.custom_template_title'),
                    $t('input.custom_template_ask'),
                    $t('input.custom_template_placeholders'),
                    $t('input.custom_template_preamble_note'),
                    $t('input.cancel_hint'),
                ]));

                return;

            case CallbackRoute::VERB_MIN_MESSAGES:
                ModuleFactory::pending()->start(
                    $botId,
                    $chatId,
                    (int) $query->from->id,
                    PendingInputService::ACTION_MIN_MESSAGES,
                );
                $this->answer($botConfig, $query, $t('confirm.digest_started'));
                $this->sendText($botConfig, $chatId, $t('input.min_messages_ask')."\n".$t('input.cancel_hint'));

                return;

            case CallbackRoute::VERB_RUN_NOW:
                $this->answer($botConfig, $query, $t('confirm.digest_started'));
                $outcome = ModuleFactory::digestRunner($this->sender)->run($botConfig, $chatId);
                $this->sendText($botConfig, $chatId, $outcome->isSuccess()
                    ? $t('digest.posted', ['count' => (string) $outcome->messageCount])
                    : $t('digest.not_produced', ['reason' => $outcome->error ?? 'unknown reason']));

                return;

            case CallbackRoute::VERB_PAGE_MODELS:
                $this->renderPage($botConfig, $chatId, fn ($s, $tk) => $this->menu->models($chatId, $s, $t));
                $this->answer($botConfig, $query);

                return;

            case CallbackRoute::VERB_SET_MODEL:
                $this->setModel($query, $botConfig, $chatId, $arg, $t);

                return;

            case CallbackRoute::VERB_CUSTOM_MODEL:
                $this->startCustomModelInput($query, $botConfig, $chatId, $t);

                return;

            case CallbackRoute::VERB_PAGE_HISTORY:
                $this->renderHistoryPage($botConfig, $chatId, 1, $t);
                $this->answer($botConfig, $query);

                return;

            case CallbackRoute::VERB_HISTORY_PAGE:
                $page = max(1, (int) ($arg ?? 1));
                $this->renderHistoryPage($botConfig, $chatId, $page, $t);
                $this->answer($botConfig, $query);

                return;

            case CallbackRoute::VERB_HISTORY_VIEW:
                $this->showHistoryEntry($query, $botConfig, $chatId, (string) $arg, $t);

                return;

            case CallbackRoute::VERB_CLOSE:
                $this->answer($botConfig, $query, $t('confirm.closed'));

                return;

            default:
                $this->answer($botConfig, $query, $t('error.unsupported_action'), alert: true);
        }
    }

    /**
     * @param  callable(SummarizerSettings, list<SummarizerToken>): array{text: string, keyboard: \BAGArt\TelegramBot\TgApi\Types\DTO\InlineKeyboardMarkupTypeDTO}  $pageBuilder
     */
    private function renderPage(TgBotConfig $botConfig, int $chatId, callable $pageBuilder): void
    {
        $botId = (string) $botConfig->botId;
        $settings = $this->settings->get($botId, $chatId);
        $tokens = $this->tokensOf($botId);

        $page = $pageBuilder($settings, $tokens);

        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $chatId,
            text: $page['text'],
            parseMode: \BAGArt\TelegramBot\TgApi\Methods\Enum\ParseModeEnum::HTML,
            replyMarkup: $page['keyboard'],
        ));
    }

    /**
     * @param  callable(string, array<string, string>): string  $t
     */
    private function selectProvider(CallbackQueryTypeDTO $query, TgBotConfig $botConfig, int $chatId, string $key, callable $t): void
    {
        $providers = new LlmProviderRegistry();
        $botId = (string) $botConfig->botId;

        if (! $providers->has($key)) {
            $this->answer($botConfig, $query, $t('error.unknown_provider'), alert: true);

            return;
        }

        // Keep the active token only when it belongs to the chosen provider.
        $patch = ['provider_key' => $key];
        $active = $this->activeToken($botId, $this->settings->get($botId, $chatId));

        if ($active !== null && $active->provider_key !== $key) {
            $patch['active_token_id'] = null;
        }

        $this->settings->patch($botId, $chatId, $patch);
        $this->answer($botConfig, $query, $t('confirm.provider_selected'));
        $this->renderPage($botConfig, $chatId, fn ($s, $tk) => $this->menu->providers($chatId, $s, $t));
    }

    /**
     * @param  callable(string, array<string, string>): string  $t
     */
    private function startCustomProviderEditor(CallbackQueryTypeDTO $query, TgBotConfig $botConfig, int $chatId, callable $t): void
    {
        ModuleFactory::pending()->start(
            (string) $query->data !== null ? CallbackRoute::decode($query->data)['chatId'] ?? '' : '',
            $chatId,
            (int) $query->from->id,
            PendingInputService::ACTION_PROVIDER_JSON,
        );

        $templateJson = ModuleFactory::providers()->customTemplateJson();

        $this->answer($botConfig, $query, $t('confirm.digest_started'));
        $this->sendText($botConfig, $chatId, implode("\n", [
            $t('input.custom_provider_title'),
            $t('input.custom_provider_ask'),
            '<pre>'.htmlspecialchars($templateJson).'</pre>',
            $t('input.custom_provider_ssrf_note'),
        ]));
    }

    /**
     * @param  callable(string, array<string, string>): string  $t
     */
    private function startTokenInput(CallbackQueryTypeDTO $query, TgBotConfig $botConfig, int $chatId, string $providerKey, callable $t): void
    {
        $providers = new LlmProviderRegistry();

        if (! $providers->has($providerKey)) {
            $this->answer($botConfig, $query, $t('error.unknown_provider'), alert: true);

            return;
        }

        $preset = $providers->get($providerKey);

        ModuleFactory::pending()->start(
            (string) $botConfig->botId,
            $chatId,
            (int) $query->from->id,
            PendingInputService::ACTION_TOKEN,
            ['provider_key' => $providerKey],
        );

        $this->answer($botConfig, $query, $t('confirm.digest_started'));
        $this->sendText($botConfig, $chatId, implode("\n", [
            $t('input.token_ask', ['provider' => $preset?->name ?? $providerKey]),
            $t('input.token_stored_hint'),
            $t('input.token_mask_hint'),
            $t('input.cancel_hint'),
        ]));
    }

    /**
     * @param  callable(string, array<string, string>): string  $t
     */
    private function selectToken(CallbackQueryTypeDTO $query, TgBotConfig $botConfig, int $chatId, string $tokenId, callable $t): void
    {
        $botId = (string) $botConfig->botId;
        $token = $this->findToken($botId, $tokenId);

        if ($token === null) {
            $this->answer($botConfig, $query, $t('error.token_not_found'), alert: true);

            return;
        }

        $this->settings->patch($botId, $chatId, [
            'active_token_id' => $token->id,
            'provider_key' => $token->provider_key,
        ]);

        $this->answer($botConfig, $query, $t('confirm.token_active_set'));
        $this->renderPage($botConfig, $chatId, fn ($s, $tk) => $this->menu->tokens($chatId, $s, $tk, $t));
    }

    /**
     * @param  callable(string, array<string, string>): string  $t
     */
    private function deleteToken(CallbackQueryTypeDTO $query, TgBotConfig $botConfig, int $chatId, string $tokenId, callable $t): void
    {
        $botId = (string) $botConfig->botId;
        $access = ModuleFactory::access();
        $token = $this->findToken($botId, $tokenId);

        if ($token === null) {
            $this->answer($botConfig, $query, $t('error.token_not_found'), alert: true);

            return;
        }

        $isOwner = (int) $token->created_by_tg_id === (int) $query->from->id;

        if (! $isOwner && ! $access->isSuperadmin($query->from->id)) {
            $this->answer($botConfig, $query, $t('error.token_delete_forbidden'), alert: true);

            return;
        }

        $wasActive = $this->settings->get($botId, $chatId)->activeTokenId === $token->id;
        $token->delete();

        if ($wasActive) {
            $this->settings->patch($botId, $chatId, ['active_token_id' => null]);
        }

        $this->answer($botConfig, $query, $t('confirm.token_deleted'));
        $this->renderPage($botConfig, $chatId, fn ($s, $tk) => $this->menu->tokens($chatId, $s, $tk, $t));
    }

    /**
     * @return list<SummarizerToken>
     */
    private function tokensOf(string $botId): array
    {
        return SummarizerToken::query()->where('bot_id', $botId)->orderByDesc('created_at')->get()->all();
    }

    private function findToken(string $botId, string $tokenId): ?SummarizerToken
    {
        return SummarizerToken::query()->where('bot_id', $botId)->whereKey($tokenId)->first();
    }

    private function activeToken(string $botId, \BAGArt\TelegramBotSummarizer\Settings\SummarizerSettings $settings): ?SummarizerToken
    {
        if ($settings->activeTokenId === null) {
            return null;
        }

        return $this->findToken($botId, $settings->activeTokenId);
    }

    private function answer(TgBotConfig $botConfig, CallbackQueryTypeDTO $query, ?string $text = null, bool $alert = false): void
    {
        $this->sender->send($botConfig, new AnswerCallbackQueryMethodDTO(
            callbackQueryId: $query->id,
            text: $text,
            showAlert: $alert ? true : null,
        ));
    }

    private function sendText(TgBotConfig $botConfig, int $chatId, string $text): void
    {
        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $chatId,
            text: $text,
            parseMode: \BAGArt\TelegramBot\TgApi\Methods\Enum\ParseModeEnum::HTML,
        ));
    }

    private function makeTranslator(\BAGArt\TelegramBotSummarizer\Settings\SummarizerSettings $settings): callable
    {
        return fn (string $key, array $replacements = []): string => SummarizerStrings::get($settings->locale, $key, $replacements);
    }

    /**
     * @param  callable(string, array<string, string>): string  $t
     */
    private function setModel(CallbackQueryTypeDTO $query, TgBotConfig $botConfig, int $chatId, ?string $model, callable $t): void
    {
        $botId = (string) $botConfig->botId;
        $providers = new LlmProviderRegistry();
        $settings = $this->settings->get($botId, $chatId);
        $models = $providers->modelsFor($settings->providerKey);

        if ($model === '__reset__') {
            $this->settings->patch($botId, $chatId, ['model_override' => null]);
            $this->answer($botConfig, $query, $t('confirm.model_reset'));
            $this->renderPage($botConfig, $chatId, fn ($s, $tk) => $this->menu->models($chatId, $s, $t));

            return;
        }

        if ($model === null || $model === '') {
            $this->answer($botConfig, $query, $t('error.unknown_model'), alert: true);

            return;
        }

        $this->settings->patch($botId, $chatId, ['model_override' => $model]);
        $this->answer($botConfig, $query, $t('confirm.model_selected'));
        $this->renderPage($botConfig, $chatId, fn ($s, $tk) => $this->menu->models($chatId, $s, $t));
    }

    /**
     * @param  callable(string, array<string, string>): string  $t
     */
    private function startCustomModelInput(CallbackQueryTypeDTO $query, TgBotConfig $botConfig, int $chatId, callable $t): void
    {
        $botId = (string) $botConfig->botId;

        ModuleFactory::pending()->start(
            $botId,
            $chatId,
            (int) $query->from->id,
            PendingInputService::ACTION_MODEL,
        );

        $this->answer($botConfig, $query, $t('confirm.digest_started'));
        $this->sendText($botConfig, $chatId, implode("\n", [
            $t('input.custom_model_title'),
            $t('input.custom_model_ask'),
            $t('input.cancel_hint'),
        ]));
    }

    private const HISTORY_PAGE_SIZE = 10;

    /**
     * @param  callable(string, array<string, string>): string  $t
     */
    private function renderHistoryPage(TgBotConfig $botConfig, int $chatId, int $page, callable $t): void
    {
        $botId = (string) $botConfig->botId;
        $settings = $this->settings->get($botId, $chatId);
        $t = $this->makeTranslator($settings);

        $total = SummarizerRun::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->count();

        $totalPages = max(1, (int) ceil($total / self::HISTORY_PAGE_SIZE));
        $page = max(1, min($page, $totalPages));

        $runs = SummarizerRun::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->orderByDesc('created_at')
            ->skip(($page - 1) * self::HISTORY_PAGE_SIZE)
            ->limit(self::HISTORY_PAGE_SIZE)
            ->get()
            ->all();

        $page = $this->menu->history($chatId, $runs, $page, $totalPages, $t);

        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $chatId,
            text: $page['text'],
            parseMode: \BAGArt\TelegramBot\TgApi\Methods\Enum\ParseModeEnum::HTML,
            replyMarkup: $page['keyboard'],
        ));
    }

    /**
     * @param  callable(string, array<string, string>): string  $t
     */
    private function showHistoryEntry(CallbackQueryTypeDTO $query, TgBotConfig $botConfig, int $chatId, string $runId, callable $t): void
    {
        $botId = (string) $botConfig->botId;

        $run = SummarizerRun::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->whereKey($runId)
            ->first();

        if ($run === null) {
            $this->answer($botConfig, $query, $t('error.run_not_found'), alert: true);

            return;
        }

        $page = $this->menu->historyView($chatId, $run, $t);

        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $chatId,
            text: $page['text'],
            parseMode: \BAGArt\TelegramBot\TgApi\Methods\Enum\ParseModeEnum::HTML,
            replyMarkup: $page['keyboard'],
        ));

        $this->answer($botConfig, $query);
    }

    public function onException(ProcessorErrorContext $context): void
    {
    }
}
