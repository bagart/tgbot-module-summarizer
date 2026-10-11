<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Events;

use BAGArt\TelegramBot\Contracts\Modules\ModuleAuditEventContract;

/**
 * Audit events dispatched by the chat summarizer module. One class per module:
 * the operation vocabulary lives in constants so the dispatch sites stay
 * typo-free and greppable, and the module registers this single class
 * through TgModuleRegistrar::auditEvent().
 */
final class SummarizerAuditEvent implements ModuleAuditEventContract
{
    public const string CHAT_ENABLEMENT_CHANGED = 'summarizer.chat.enablement.changed';

    public const string INTERVAL_CHANGED = 'summarizer.interval.changed';

    public const string TEMPLATE_CHANGED = 'summarizer.template.changed';

    public const string PROVIDER_CHANGED = 'summarizer.provider.changed';

    public const string PROVIDER_TOKEN_CREATED = 'summarizer.provider.token.created';

    public const string PROVIDER_TOKEN_DELETED = 'summarizer.provider.token.deleted';

    public const string MODEL_OVERRIDE_REMOVED = 'summarizer.model.override.removed';

    public const string MODEL_OVERRIDE_CREATED = 'summarizer.model.override.created';

    public const string DIGEST_TRIGGERED = 'summarizer.digest.triggered';

    public const string CHAT_ACCESS_CHANGED = 'summarizer.chat.access.changed';

    public const string MESSAGE_STORED = 'summarizer.message.stored';

    public const string RETENTION_PURGED = 'summarizer.retention.purged';

    public const string PENDING_INPUT_CHANGED = 'summarizer.pending.input.changed';

    /**
     * @param  array<string, mixed>|null  $oldState
     * @param  array<string, mixed>|null  $newState
     * @param  array<string, mixed>  $context  structured non-secret facts only — credentials/token values are forbidden
     */
    public function __construct(
        public readonly string $operation,
        public readonly string|int|null $botId = null,
        public readonly ?string $subjectType = null,
        public readonly string|int|null $subjectId = null,
        public readonly string|int|null $actorId = null,
        public readonly ?string $actorType = null,
        public readonly ?array $oldState = null,
        public readonly ?array $newState = null,
        public readonly array $context = [],
    ) {
    }

    public function moduleId(): string
    {
        return 'summarizer';
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function botId(): ?string
    {
        return $this->botId !== null ? (string) $this->botId : null;
    }

    public function subjectType(): ?string
    {
        return $this->subjectType;
    }

    public function subjectId(): ?string
    {
        return $this->subjectId !== null ? (string) $this->subjectId : null;
    }

    public function actorId(): ?string
    {
        return $this->actorId !== null ? (string) $this->actorId : null;
    }

    public function actorType(): ?string
    {
        return $this->actorType;
    }

    public function oldState(): ?array
    {
        return $this->oldState;
    }

    public function newState(): ?array
    {
        return $this->newState;
    }

    public function context(): array
    {
        return $this->context;
    }
}
