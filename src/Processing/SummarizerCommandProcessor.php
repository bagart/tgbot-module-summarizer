<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Processing;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\Processing\Processors\TgModuleProcessorContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Modules\TgCommandRegistry;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\Processing\ErrorHandling\ProcessorErrorContext;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\Enum\ParseModeEnum;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBotSummarizer\I18n\SummarizerStrings;
use BAGArt\TelegramBotSummarizer\ModuleFactory;
use BAGArt\TelegramBotSummarizer\Ui\AdminMenuRenderer;
use Throwable;

/**
 * "/summarizer" — opens the in-chat admin panel. Available to chat admins
 * holding the "delete messages" right, the inviter, and superadmins.
 */
class SummarizerCommandProcessor implements TgModuleProcessorContract
{
    public const NAME = 'summarizer';

    private function __construct(
        private readonly TgSenderContract $sender,
        private readonly AdminMenuRenderer $menu,
    ) {
    }

    public static function moduleId(): string
    {
        return 'summarizer';
    }

    public static function build(BotProcessorContext $context): self
    {
        return new self(sender: $context->tgSender, menu: ModuleFactory::menu());
    }

    public function support(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return $dto instanceof MessageTypeDTO
            && $dto->text !== null
            && TgCommandRegistry::parseCommandName($dto->text) === self::NAME;
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
        assert($dto instanceof MessageTypeDTO);

        if (! ModuleFactory::inLaravel() || $dto->from === null) {
            return;
        }

        $chatId = (int) $dto->chat->id;

        try {
            if (! ModuleFactory::access()->canManage($botConfig, $chatId, $dto->from)) {
                $botId = (string) $botConfig->botId;
                $settings = ModuleFactory::settings()->get($botId, $chatId);
                $t = fn (string $key, array $replacements = []): string => SummarizerStrings::get($settings->locale, $key, $replacements);

                $this->sender->send($botConfig, new SendMessageMethodDTO(
                    chatId: (string) $chatId,
                    text: $t('error.denied'),
                ));

                return;
            }

            $botId = (string) $botConfig->botId;
            $settings = ModuleFactory::settings()->get($botId, $chatId);
            $enabled = ModuleFactory::settings()->isEnabled($botId, $chatId);
            $tokens = \BAGArt\TelegramBotSummarizer\Models\SummarizerToken::query()
                ->where('bot_id', $botId)
                ->orderByDesc('created_at')
                ->get()
                ->all();

            $t = fn (string $key, array $replacements = []): string => SummarizerStrings::get($settings->locale, $key, $replacements);
            $page = $this->menu->main($chatId, $settings, $enabled, $tokens, $t);

            $this->sender->send($botConfig, new SendMessageMethodDTO(
                chatId: (string) $chatId,
                text: $page['text'],
                parseMode: ParseModeEnum::HTML,
                replyMarkup: $page['keyboard'],
            ));
        } catch (Throwable $e) {
            $botId = (string) $botConfig->botId;
            $settings = ModuleFactory::settings()->get($botId, $chatId);
            $t = fn (string $key, array $replacements = []): string => SummarizerStrings::get($settings->locale, $key, $replacements);

            $this->sender->send($botConfig, new SendMessageMethodDTO(
                chatId: (string) $chatId,
                text: $t('error.summarizer_error', ['message' => $e->getMessage()]),
            ));
        }
    }

    public function onException(ProcessorErrorContext $context): void
    {
    }
}
