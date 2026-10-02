# Summarizer Module — SDD

> **Module:** `tgbot-module-summarizer` (`BAGArt\TelegramBotSummarizer`)
> **Status:** 100% complete

---

## What Was Done

LLM-powered chat summarizer with digest generation, in-chat admin panel, and web UI.

### Core Components

- **Digest Generation**: LLM-powered chat summaries (configurable time windows).
- **In-Chat Admin Panel**: Telegram-native admin interface for digest management.
- **Cron**: Scheduled digest generation via `summarizer:digests` command.
- **Settings**: Per-chat digest configuration (frequency, language, format).
- **Web UI**: Web-based digest viewer and configuration.
- **i18n**: 5 locales (RU, EN, FR, ES, ZH).

### Key Decisions

- LLM-first approach (OpenAI/compatible API for summarization).
- In-chat admin panel (no separate web admin needed for basic operations).
- Cron-based delivery (not real-time; configurable intervals).

### Files

- `src/` — Domain logic, LLM integration, commands, presenters

---

## Model Dropdown (2026-09-18)

Curated model picker per provider in the admin panel, plus "Custom" free-text input.

### Key Decisions

- `LlmProviderRegistry::modelsFor()` provides curated model lists per provider.
- Model override stored in `module_settings` JSON as `model_override`.
- `LlmConfigResolver::resolve()` checks for override before using preset default.
- Custom model via `PendingInputService::ACTION_MODEL` free-text flow.
- "Reset to default" clears `model_override` (null → use preset model).

### Files

- `src/Llm/LlmProviderRegistry.php` — `MODEL_LISTS` const + `modelsFor()` method
- `src/Settings/SummarizerSettings.php` — `$modelOverride` property + `fromArray()` roundtrip
- `src/Llm/LlmConfigResolver.php` — model override applied to both preset and custom providers
- `src/Ui/AdminMenuRenderer.php` — `models()` page + model label in `main()`
- `src/Ui/CallbackRoute.php` — `VERB_PAGE_MODELS`, `VERB_SET_MODEL`, `VERB_CUSTOM_MODEL`
- `src/Processing/AdminMenuProcessor.php` — verb routing + `setModel()`, `startCustomModelInput()`
- `src/Processing/CollectMessageProcessor.php` — `handleModelInput()` for free-text flow
- `src/Ui/PendingInputService.php` — `ACTION_MODEL` constant
- `src/I18n/SummarizerStrings.php` — `model.*` keys (EN + RU)

---

## Run History Page (2026-09-18)

Paginated history of the last digest runs, viewable from the admin panel.

### Key Decisions

- Direct DB reads from `summarizer_runs` — no caching.
- 10 entries per page with next/prev navigation.
- Each entry shows: date, provider, status emoji, duration.
- Tap entry shows full `summary_text` in a separate message.
- `SummarizerRun` model already had all needed fields.

### Files

- `src/Ui/AdminMenuRenderer.php` — `history()` + `historyView()` renderers
- `src/Ui/CallbackRoute.php` — `VERB_PAGE_HISTORY`, `VERB_HISTORY_PAGE`, `VERB_HISTORY_VIEW`
- `src/Processing/AdminMenuProcessor.php` — `renderHistoryPage()`, `showHistoryEntry()`
- `src/I18n/SummarizerStrings.php` — `history.*` keys (EN + RU)

---

## Per-Topic Independent Digesting (2026-09-18)

Topic-scoped digests for Telegram forum-style chats with threaded messages.

### Key Decisions

- `summarizer_messages.thread_id` already existed; `summarizer_runs.thread_id` added via migration.
- `DigestBuilder::build()` filters by `thread_id` when non-null; null = whole-chat digest (backward-compatible).
- `DigestRunner::run()` accepts `?int $threadId`, passed to builder, lock key, and `SendMessageMethodDTO.messageThreadId`.
- `SummarizerDigestsCommand` discovers topics via `SELECT DISTINCT thread_id` from recent messages.
- Per-thread scheduling: `isDue()` and `resolvePeriod()` filter by `thread_id`.
- Lock keys include thread_id to prevent cross-topic collisions.
- No module engine changes needed — `tg_module_enablements` stays per-chat.

### Files

- `database/migrations/2026_09_18_000001_add_thread_id_to_summarizer_runs.php` — migration
- `src/Models/SummarizerRun.php` — `thread_id` in `$fillable` + casts
- `src/Digest/DigestBuilder.php` — `?int $threadId = null` param + thread-scoped query
- `src/Digest/DigestRunner.php` — `?int $threadId`, lock key, resolvePeriod, storeRun, sendSummary
- `src/Console/SummarizerDigestsCommand.php` — `discoverTopics()`, per-thread iteration, `runDigest()`

---

## One-Flag Opt-In (2026-10-01)

Q11 (E→A): `ModuleEnablementContract::isEnabled('summarizer', bot, chat)` is now the single
source of truth for collection; the `SummarizerSettings::$enabled` DTO field and the
`chatOptedIn()` reconstruction (extra `chatsWithSettings` query per collect-gate read) are gone.

### Key Decisions

- Chat-level default OFF is a descriptor-level mechanism: `TgModuleDescriptor::$defaultChatEnabled`
  (new lib field, default `true`) set to `false` on the summarizer descriptor; bot scope
  (`defaultEnabled: true`) untouched. Engine reader: platform gate → explicit chat bool →
  `defaultChatEnabled === false ? false : row status / defaultEnabled`; legacy parity in
  `TgModuleEnablementService` (chat-scope reads consult only explicit chat rows for such modules).
- Dispatch entry points survive the OFF default (Q11-D1): `ModuleEnablementContract::isEnabled`
  accepts `?int $chatId = null` (null = bot scope) and the selector dispatches **command processors
  and chat-member updates at bot scope**; collection stays chat-gated. Deliberate lib contract
  widening — `CommandRoutingE2ETest` rewritten to the new AC.
- Opt-in write path unchanged: panel `patch(['enabled' => true])` → reserved key →
  `{chatId}:__enabled__` sentinel (engine) / chat row (legacy). `SummarizerSettingsService::patch()`
  now calls `enablement->refresh()` after an `enabled` write (engine memo had no wired afterWrite
  hook — read-your-writes).
- Web form drops the dead `enabled` field (it never reached enablement storage on either driver);
  enablement surfaces = in-chat panel + menu hub per-chat toggle.
- Feature suite switched to the engine driver (legacy pins + `LegacyEnablementSchema` removed).
- Pre-existing prod fatal fixed en route: missing `AntispamPipeline` import in antispam
  `CaptchaJoinProcessor`/`CaptchaCallbackProcessor` (`moduleId()` runs on every gated update),
  guarded by `ModuleProcessorSurfaceTest`.

### Files

- `src/SummarizerModule.php` — `defaultChatEnabled: false`
- `src/Settings/SummarizerSettings.php` / `SummarizerSettingsService.php` — field removed, overlay/`chatOptedIn` deleted, `refresh()` on enablement write
- `src/Processing/CollectMessageProcessor.php` — gate → `isEnabled()`
- `src/Ui/AdminMenuRenderer.php` + `SummarizerCommandProcessor.php` / `AdminMenuProcessor.php` — explicit `bool $enabled` parameter
- `src/Web/SummarizerWebUi.php` — `enabled` schema field/validate/locales removed
- Questions: `docs/questions/summarizer-{chat-default-mechanism,dispatch-exemption,suite-driver,web-enabled-field}.md`
