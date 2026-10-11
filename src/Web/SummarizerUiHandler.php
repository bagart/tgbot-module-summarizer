<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotSummarizer\Web;

use BAGArt\TelegramBotSummarizer\Events\SummarizerAuditEvent;
use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotMenu\Contracts\TgWebApiHandlerContract;
use BAGArt\TelegramBotMenu\Manifest\ChatScope;
use BAGArt\TelegramBotMenu\Manifest\EffectiveRole;
use BAGArt\TelegramBotMenu\Support\TgWebApiRoute;
use BAGArt\TelegramBotMenu\Support\TgWebRequest;
use BAGArt\TelegramBotMenu\Support\TgWebResponse;
use BAGArt\TelegramBotSummarizer\ModuleFactory;

/**
 * webApi surface for the summarizer (menu_integration.md M-3c): executes the
 * §8.9 `run-now` UiAction declared on the manifest. The dispatcher (§27.9)
 * has already enforced role/chat-scope/enablement before handle() runs; the
 * digest posts to the chat through the synchronous sender, so the web user
 * sees the result where the whole chat can read it.
 */
final readonly class SummarizerUiHandler implements TgWebApiHandlerContract
{
    /** @return list<TgWebApiRoute> */
    public static function routes(): array
    {
        return [
            new TgWebApiRoute('POST', 'actions/run-now', EffectiveRole::Admin, chatScope: ChatScope::Required),
        ];
    }

    public function handle(TgWebRequest $request, array $path): TgWebResponse
    {
        if ($path === ['actions', 'run-now']) {
            return $this->runNow($request);
        }

        return TgWebResponse::error('not_found', 'Unknown summarizer route.', 404, $request->requestId);
    }

    private function runNow(TgWebRequest $request): TgWebResponse
    {
        $context = $request->context;
        $chat = $context->chat;

        if ($chat === null) {
            return TgWebResponse::error('chat_required', 'The digest runs against a chat.', 403, $request->requestId);
        }

        $token = TgBot::query()->where('bot_id', $context->bot->id)->value('token');

        if (! is_string($token) || $token === '') {
            return TgWebResponse::error('internal', 'Bot credentials are missing.', 500, $request->requestId);
        }

        $outcome = ModuleFactory::digestRunnerSync()
            ->run(new TgBotConfig($token, $context->bot->id), $chat->id);

        if (! $outcome->isSuccess()) {
            return TgWebResponse::error(
                'digest_failed',
                sprintf('Digest not produced: %s', $outcome->error ?? 'unknown reason'),
                409,
                $request->requestId,
            );
        }

        event(new SummarizerAuditEvent(
            SummarizerAuditEvent::DIGEST_TRIGGERED,
            botId: $context->bot->id,
            subjectType: 'chat',
            subjectId: $chat->id,
            actorId: $context->user->id,
            context: ['messageCount' => $outcome->messageCount, 'requestId' => $request->requestId],
        ));

        return TgWebResponse::ok([
            'message' => sprintf('Digest posted (%d messages analyzed).', $outcome->messageCount),
            'messageCount' => $outcome->messageCount,
        ]);
    }
}
