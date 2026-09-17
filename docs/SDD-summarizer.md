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
