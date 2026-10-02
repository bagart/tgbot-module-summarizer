<?php

declare(strict_types=1);

/*
 * The summarizer suite runs on the engine enablement driver (the platform
 * default, config/tg_modules.php): ModuleEnablementContract and
 * ModuleSettingsContract resolve to the engine adapters, and the engine
 * tables (bot_module_activations) come from the engine provider's own
 * migrations — no legacy schema recreation or contract pinning is needed.
 * The root tests/Pest.php require_once's this file (Pest only auto-loads a
 * package Pest.php when the package suite runs on its own).
 */
