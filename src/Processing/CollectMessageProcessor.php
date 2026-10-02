<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Processing;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\Processing\Processors\TgModuleProcessorContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\Processing\ErrorHandling\ProcessorErrorContext;
use BAGArt\TelegramBot\TgApi\Methods\DTO\DeleteMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\Enum\ParseModeEnum;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\Enum\ChatPropTypeEnum;
use Illuminate\Support\Facades\Log;
use BAGArt\TelegramBotSummarizer\I18n\SummarizerStrings;
use BAGArt\TelegramBotSummarizer\Llm\LlmProviderRegistry;
use BAGArt\TelegramBotSummarizer\Models\SummarizerChatAccess;
use BAGArt\TelegramBotSummarizer\Models\SummarizerMessage;
use BAGArt\TelegramBotSummarizer\Models\SummarizerToken;
use BAGArt\TelegramBotSummarizer\ModuleFactory;
use BAGArt\TelegramBotSummarizer\Settings\SummarizerSettingsService;
use BAGArt\TelegramBotSummarizer\Ui\PendingInputService;
use Throwable;

/**
 * Regular message processor:
 * 1. records the "inviter" when service messages show the bot being added;
 * 2. consumes admin input flows (token paste, template editor, ...);
 * 3. collects chat messages for future digests.
 */
class CollectMessageProcessor implements TgModuleProcessorContract
{
    private function __construct(
        private readonly TgSenderContract $sender,
        private readonly SummarizerSettingsService $settings,
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
            pending: ModuleFactory::pending(),
        );
    }

    public function support(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return $dto instanceof MessageTypeDTO
            && in_array($dto->chat->type, [ChatPropTypeEnum::GROUP, ChatPropTypeEnum::SUPERGROUP], true)
            && $dto->from !== null;
    }

    public function isStrictOrdered(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return true;
    }

    public function process(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): void {
        assert($dto instanceof MessageTypeDTO);

        if (! ModuleFactory::inLaravel()) {
            return;
        }

        $botId = (string) $botConfig->botId;
        $chatId = (int) $dto->chat->id;
        $user = $dto->from;

        if ($this->trackInviter($dto, $botConfig, $botId, $chatId)) {
            return;
        }

        if ($user->isBot) {
            return;
        }

        // Chat-scope enablement gate: collection (and the pending-input
        // flows behind it) runs only where a chat admin opted in via
        // /summarizer — descriptor chat default is OFF.
        if (! $this->settings->isEnabled($botId, $chatId)) {
            return;
        }

        if ($this->consumePendingInput($dto, $botConfig, $botId, $chatId)) {
            return;
        }

        $this->collect($dto, $botId, $chatId);
    }

    /**
     * Records who added the bot to the chat; returns true when the message
     * was an add-bot service message (nothing else to do with it).
     */
    private function trackInviter(MessageTypeDTO $message, TgBotConfig $botConfig, string $botId, int $chatId): bool
    {
        if ($message->newChatMembers === null || $message->newChatMembers === []) {
            return false;
        }

        $botUserId = ModuleFactory::access()->botUserId($botConfig);

        foreach ($message->newChatMembers as $member) {
            if ($member instanceof \BAGArt\TelegramBot\TgApi\Types\DTO\UserTypeDTO
                && $member->id === $botUserId
                && $message->from->id !== $botUserId
            ) {
                try {
                    SummarizerChatAccess::query()->updateOrCreate(
                        ['bot_id' => $botId, 'chat_id' => $chatId, 'inviter_tg_id' => (int) $message->from->id],
                        ['inviter_username' => $message->from->username, 'invited_at' => time()],
                    );
                } catch (Throwable $e) {
                    Log::warning('Summarizer: failed to record inviter', [
                        'bot_id' => $botId,
                        'chat_id' => $chatId,
                        'exception' => $e::class,
                    ]);
                }

                return true;
            }
        }

        return false;
    }

    /**
     * When the user has a pending admin-input action, their next message is
     * consumed as that input instead of being collected.
     */
    private function consumePendingInput(
        MessageTypeDTO $message,
        TgBotConfig $botConfig,
        string $botId,
        int $chatId,
    ): bool {
        $action = $this->pending->pop($botId, $chatId, (int) $message->from->id);

        if ($action === null) {
            return false;
        }

        $text = trim((string) ($message->text ?? ''));

        $settings = $this->settings->get($botId, $chatId);
        $t = fn (string $key, array $replacements = []): string => SummarizerStrings::get($settings->locale, $key, $replacements);

        try {
            match ($action->action) {
                PendingInputService::ACTION_TOKEN => $this->handleTokenInput($message, $botConfig, $botId, $chatId, $text, $action, $t),
                PendingInputService::ACTION_TEMPLATE => $this->handleTemplateInput($botConfig, $botId, $chatId, $text, $t),
                PendingInputService::ACTION_PROVIDER_JSON => $this->handleProviderJsonInput($message, $botConfig, $botId, $chatId, $text, $t),
                PendingInputService::ACTION_MIN_MESSAGES => $this->handleMinMessagesInput($botConfig, $botId, $chatId, $text, $t),
                PendingInputService::ACTION_MODEL => $this->handleModelInput($botConfig, $botId, $chatId, $text, $t),
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            // Re-arm so the admin can resend corrected input without reopening the menu
            $this->pending->start($botId, $chatId, (int) $message->from->id, $action->action, $action->payload ?? []);
            $this->reply($botConfig, $chatId, $t('error.input_correct_and_retry', ['message' => $e->getMessage()]));
        }

        return true;
    }

    /**
     * @param  callable(string, array<string, string>): string  $t
     */
    private function handleTokenInput(
        MessageTypeDTO $message,
        TgBotConfig $botConfig,
        string $botId,
        int $chatId,
        string $text,
        \BAGArt\TelegramBotSummarizer\Models\SummarizerPendingAction $action,
        callable $t,
    ): void {
        $token = preg_replace('/\s+/', '', $text) ?? '';

        if (mb_strlen($token) < 8 || mb_strlen($token) > 512) {
            throw new \InvalidArgumentException($t('error.token_key_length'));
        }

        $providerKey = (string) ($action->payload['provider_key'] ?? '');
        $preset = ModuleFactory::providers()->get($providerKey);

        if ($providerKey === '' || (! ModuleFactory::providers()->has($providerKey))) {
            throw new \InvalidArgumentException($t('error.token_unknown_provider'));
        }

        $row = SummarizerToken::create([
            'bot_id' => $botId,
            'provider_key' => $providerKey,
            'label' => ($preset?->name ?? $providerKey).' · '.now()->format('d.m.y H:i'),
            'token' => $token,
            'created_by_tg_id' => (int) $message->from->id,
            'created_by_username' => $message->from->username,
        ]);

        $this->settings->patch($botId, $chatId, [
            'active_token_id' => $row->id,
            'provider_key' => $providerKey,
        ]);

        // The key must not stay visible in the chat history
        $this->sender->send($botConfig, new DeleteMessageMethodDTO(chatId: (string) $chatId, messageId: $message->messageId));
        $this->reply(
            $botConfig,
            $chatId,
            $t('token_confirm.stored', [
                'provider' => $preset?->name ?? $providerKey,
                'masked' => SummarizerToken::mask($token),
            ]),
        );
    }

    /**
     * @param  callable(string, array<string, string>): string  $t
     */
    private function handleTemplateInput(TgBotConfig $botConfig, string $botId, int $chatId, string $text, callable $t): void
    {
        if (mb_strlen($text) < 20 || mb_strlen($text) > 4000) {
            throw new \InvalidArgumentException($t('error.template_length'));
        }

        if (! str_contains($text, '{period}') && ! str_contains($text, '{stats}')) {
            throw new \InvalidArgumentException($t('error.template_no_placeholder'));
        }

        $this->settings->patch($botId, $chatId, ['custom_template' => $text, 'template_id' => 'witty']);
        $this->reply($botConfig, $chatId, $t('token_confirm.template_saved'));
    }

    /**
     * @param  callable(string, array<string, string>): string  $t
     */
    private function handleProviderJsonInput(
        MessageTypeDTO $message,
        TgBotConfig $botConfig,
        string $botId,
        int $chatId,
        string $text,
        callable $t,
    ): void {
        $config = ModuleFactory::providers()->validateCustomConfig($text);

        $this->settings->patch($botId, $chatId, [
            'custom_provider' => $config,
            'provider_key' => LlmProviderRegistry::CUSTOM_KEY,
        ]);

        $this->reply(
            $botConfig,
            $chatId,
            $t('token_confirm.custom_saved', [
                'name' => $config['name'],
                'base_url' => $config['base_url'],
                'model' => $config['model'],
            ]),
        );
    }

    /**
     * @param  callable(string, array<string, string>): string  $t
     */
    private function handleMinMessagesInput(TgBotConfig $botConfig, string $botId, int $chatId, string $text, callable $t): void
    {
        if (! ctype_digit($text)) {
            throw new \InvalidArgumentException($t('error.min_messages_not_number'));
        }

        $value = (int) $text;

        if ($value < 1 || $value > 5000) {
            throw new \InvalidArgumentException($t('error.min_messages_out_of_range'));
        }

        $this->settings->patch($botId, $chatId, ['min_messages' => $value]);
        $this->reply($botConfig, $chatId, $t('token_confirm.min_messages_set', ['count' => (string) $value]));
    }

    /**
     * @param  callable(string, array<string, string>): string  $t
     */
    private function handleModelInput(TgBotConfig $botConfig, string $botId, int $chatId, string $text, callable $t): void
    {
        $model = trim($text);

        if ($model === '') {
            throw new \InvalidArgumentException($t('error.unknown_model'));
        }

        $this->settings->patch($botId, $chatId, ['model_override' => $model]);
        $this->reply($botConfig, $chatId, $t('confirm.model_selected'));
    }

    private function collect(MessageTypeDTO $message, string $botId, int $chatId): void
    {
        try {
            SummarizerMessage::query()->create([
                'bot_id' => $botId,
                'chat_id' => $chatId,
                'message_id' => $message->messageId,
                'thread_id' => $message->messageThreadId,
                'user_tg_id' => (int) $message->from->id,
                'username' => $message->from->username,
                'display_name' => trim(($message->from->firstName ?? '')
                    .($message->from->lastName !== null ? ' '.$message->from->lastName : '')) ?: null,
                'text' => $this->extractText($message),
                'is_bot' => $message->from->isBot,
                'sent_at' => $message->date,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // At-least-once webhook delivery: duplicates are expected and ignored.
            $sqlState = (string) $e->getCode();

            if ($sqlState !== '23000'
                && ! str_contains($e->getMessage(), 'Duplicate entry')
                && ! str_contains($e->getMessage(), 'UNIQUE constraint failed')
            ) {
                throw $e;
            }
        }
    }

    private function extractText(MessageTypeDTO $message): ?string
    {
        if ($message->text !== null && $message->text !== '') {
            return $message->text;
        }

        if ($message->caption !== null && $message->caption !== '') {
            return $message->caption;
        }

        return match (true) {
            $message->photo !== null => '[photo]',
            $message->sticker !== null => '[sticker '.($message->sticker->emoji ?? '').']',
            $message->voice !== null => '[voice]',
            $message->videoNote !== null => '[video note]',
            $message->video !== null => '[video]',
            $message->animation !== null => '[gif]',
            $message->audio !== null => '[audio]',
            $message->document !== null => '[document]',
            default => '[media]',
        };
    }

    private function reply(TgBotConfig $botConfig, int $chatId, string $text): void
    {
        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $chatId,
            text: $text,
            parseMode: ParseModeEnum::HTML,
        ));
    }

    public function onException(ProcessorErrorContext $context): void
    {
    }
}
