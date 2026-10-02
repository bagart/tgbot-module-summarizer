<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiMethodDTOContract;
use BAGArt\TelegramBot\Http\Pure\TgApiResponse;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberAdministratorTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberOwnerTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\Enum\ChatPropTypeEnum;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UserTypeDTO;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use Illuminate\Support\Facades\DB;

/*
 * Shared fixtures for Summarizer module tests.
 */

function smBotConfig(): TgBotConfig
{
    return new TgBotConfig(token: '123:test', botId: 'test_bot');
}

function smUser(int $id, ?string $username = 'tester'): UserTypeDTO
{
    return new UserTypeDTO(id: (string) $id, isBot: false, firstName: 'Tester', username: $username);
}

function smGroupMessage(int $chatId, int $userId, string $text, int $messageId = 10): MessageTypeDTO
{
    return new MessageTypeDTO(
        messageId: $messageId,
        date: time(),
        chat: new ChatTypeDTO(id: (string) $chatId, type: ChatPropTypeEnum::SUPERGROUP),
        from: smUser($userId),
        text: $text,
    );
}

/** Sender spy recording every pushed method DTO. */
function smSenderSpy(): TgSenderContract
{
    return new class () implements TgSenderContract {
        /** @var list<TgApiMethodDTOContract> */
        public array $sent = [];

        public function send(TgBotConfig $botConfig, TgApiMethodDTOContract $dto): void
        {
            $this->sent[] = $dto;
        }
    };
}

/**
 * API-client stub returning a fixed payload for every request; throws when
 * $throw is set (used to prove no network path is taken / fail-closed).
 */
function smFakeApiClient(mixed $result = null, bool $ok = true, bool $throw = false): TgBotApiDTOClientContract
{
    return new class ($result, $ok, $throw) implements TgBotApiDTOClientContract {
        public function __construct(
            private readonly mixed $result,
            private readonly bool $ok,
            private readonly bool $throw,
        ) {
        }

        public function request(
            BAGArt\TelegramBot\Configs\TgBotConfig $botConfig,
            TgApiMethodDTOContract $dto,
            ?int $timeout = null,
        ): TgApiResponse {
            if ($this->throw) {
                throw new RuntimeException('network disabled in test');
            }

            return new TgApiResponse(ok: $this->ok, possibleResultTypes: [], result: $this->result);
        }

        public function requestAsync(
            BAGArt\TelegramBot\Configs\TgBotConfig $botConfig,
            TgApiMethodDTOContract $dto,
            ?int $timeout = null,
        ): BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract {
            throw new RuntimeException('not used in tests');
        }

        public function tickable(): array
        {
            return [];
        }
    };
}

function smAdminMember(int $tgId, bool $canDelete): ChatMemberAdministratorTypeDTO
{
    return new ChatMemberAdministratorTypeDTO(
        user: smUser($tgId),
        canBeEdited: false,
        isAnonymous: false,
        canManageChat: true,
        canDeleteMessages: $canDelete,
        canManageVideoChats: false,
        canRestrictMembers: false,
        canPromoteMembers: false,
        canChangeInfo: false,
        canInviteUsers: false,
        canPostStories: false,
        canEditStories: false,
        canDeleteStories: false,
    );
}

function smOwnerMember(int $tgId): ChatMemberOwnerTypeDTO
{
    return new ChatMemberOwnerTypeDTO(user: smUser($tgId), isAnonymous: false);
}

/** Raw module_settings key holding the per-chat enablement override. */
function smChatKey(int $chatId): string
{
    return $chatId.':'.ModuleActivationReader::CHAT_ENABLED_KEY;
}

/** @param  array<string, mixed>  $settings  raw module_settings payload (chat keys included) */
function smSeedBotActivation(string $botId, string $status = ModuleActivationReader::STATUS_ENABLED, array $settings = []): void
{
    DB::table('bot_module_activations')->insert([
        'bot_id' => $botId,
        'module_id' => 'summarizer',
        'status' => $status,
        'revision' => 1,
        'module_settings' => $settings === [] ? null : json_encode($settings, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function smOptInChat(string $botId, int $chatId): void
{
    app(ModuleSettingsContract::class)->patchSettings('summarizer', $botId, $chatId, ['enabled' => true]);
    app(ModuleEnablementContract::class)->refresh($botId, $chatId);
}

function smOptOutChat(string $botId, int $chatId): void
{
    app(ModuleSettingsContract::class)->patchSettings('summarizer', $botId, $chatId, ['enabled' => false]);
    app(ModuleEnablementContract::class)->refresh($botId, $chatId);
}
