# bagart/tgbot-module-summarizer

Chat summarizer module for the [Telegram bot platform](../..): collects group
messages, produces scheduled LLM digests (themes, positions, witty
mini-summaries) and ships an in-chat admin panel for interval / provider /
token / template management.

Disabled by default per chat — nothing is collected until a chat admin enables
it via `/summarizer`.

## What it provides

- `SummarizerModule` — `TgModuleContract` plugin (processors for messages,
  chat-member updates and callback queries; `/summarizer` and
  `/summarizer_cancel` commands).
- `summarizer:digests` Artisan command — cron entry scanning enabled chats and
  producing due digests (self-registered into `telegram.modules_schedule`;
  users adjust periodicity in the host's `config/schedule-overrides.php`).
- `config/summarizer.php` — platform defaults and operational limits
  (env-driven).
- Migrations for `summarizer_tokens`, `summarizer_messages`, `summarizer_runs`,
  `summarizer_chat_access`, `summarizer_pending_actions`.

## Install (host app)

Dev mode (this monorepo) — already wired, no steps needed: path repository +
PSR-4 mapping in the host `composer.json`, provider registered in
`bootstrap/providers.php`:

```php
BAGArt\TelegramBotSummarizer\TelegramBotSummarizerServiceProvider::class,
```

Then `php artisan migrate` when the module is first enabled.

Prod mode (servers without `misc/`): `cmd/deps/install --mode=prod` resolves
`bagart/tgbot-module-summarizer` from VCS sources via
`composer.prod.json`. See AGENTS.md §Modules rule.

## Tests

```bash
composer test   # from this directory; uses the host app's PHPUnit
```

## Menu integration

Menu-hub surface per `telegram-platform-menu` contribution system (M-3c):
`SummarizerWebUi` (§8.3 schema form over `SummarizerSettings` keys) and
`SummarizerUiHandler` executing the `run-now` UiAction through
`ModuleFactory::digestRunnerSync()` (Admin, chat-scoped).
