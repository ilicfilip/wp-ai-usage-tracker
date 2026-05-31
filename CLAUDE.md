# CLAUDE.md

Guidance for working in this repository.

## What this is

**AI Usage Tracker** (slug `wp-ai-rate-limiter`) — a WordPress plugin that observes
every **WordPress 7.0 AI Client** request, attributes it to the originating plugin and
user, and records tokens + estimated cost.

WP 7.0 ships AI in core with a single shared provider key that *any* plugin can spend
against, and **no** spend controls. The official AI plugin shipped usage *logging* but no
enforcement, and has none on its roadmap. This plugin fills that gap. The defensible
wedge is **attribution** — answering *"which plugin / which user spent this money"* inside
one shared key, which the provider billing dashboard cannot see.

### Phases
- **Phase 1 (built): tracking.** Track per plugin + per user, surface it on a dashboard.
  The capture path itself never blocks.
- **Phase 2 (built): limits & enforcement.** Configure limits (per plugin/user/role/
  global, by requests/tokens/cost, per day/month); the Enforcer blocks prompts that
  exceed a *hard* limit by returning `true` from `wp_ai_client_prevent_prompt`. **Stays
  observe-only until a hard limit is configured**, and fails open on any error.

Background design docs (architecture, investigation, phase specs) are kept
privately by the maintainer and are not part of this public repository.

## Hard invariants (do not violate)

1. **Block only on a configured hard limit; otherwise never block.** The
   `wp_ai_client_prevent_prompt` hook returns `$prevent` unchanged unless the Enforcer
   finds an *enabled, breached, confidence-satisfied hard limit*. With no hard limits the
   Enforcer short-circuits (cached flag) and the plugin behaves exactly like observe-only.
   The capture/enforcement paths are wrapped so any error **fails open** (allows) — a
   tracking/billing plugin that breaks a site's AI is worse than useless.
2. **No hard dependency on the WordPress/ai plugin.** Its `wpai_request_log_context`
   hook is an *optional* enhancement (an experiment that may change). The plugin must
   fully function without it.
3. **Cost is stored as integer micros** (1e-6 USD). No floats in the DB.
4. **All SDK interaction is runtime-guarded** (`function_exists` / `class_exists` /
   `method_exists`). The WP 7.0 AI Client and bundled php-ai-client SDK have no PHPStan
   stubs and may be absent at analysis time. Code must pass `php -l` with the SDK absent.

## Verified WP 7.0 AI Client API (don't contradict these)

- Entry: `wp_ai_client_prompt( string $prompt )` → fluent `WP_AI_Client_Prompt_Builder`.
- Filter `wp_ai_client_prevent_prompt`, signature `function( bool $prevent,
  WP_AI_Client_Prompt_Builder $builder ): bool`, priority `10`, `2` args. Fires
  **before** the request. Returning `true` blocks it (then `generate_*()` return
  `WP_Error`). **We never return true in Phase 1.**
- `$builder->generate_text()` → `string|WP_Error`; `generate_text_result()` →
  `GenerativeAiResult|WP_Error`. The builder uses `__call` magic methods, so
  `method_exists()` returns false for the fluent/generate methods — **never gate on
  `method_exists` for those**.
- Errors are `WP_Error`, **not** exceptions (the WP wrapper catches SDK exceptions).
- The SDK has **no** token counter and **no** metadata-attach channel — so attribution
  must be *inferred*, and pre-request token counts can only be *estimated*.

### Capture mechanism (verified on a live WP 7.0 install — this is the real path)

Core ships a PSR-14 event dispatcher (`WP_AI_Client_Event_Dispatcher`) that bridges SDK
events to WordPress actions. The one we use:

- **`wp_ai_client_after_generate_result`** (action, fires *after* generation) — the
  payload is an `AfterGenerateResultEvent` exposing:
  - `getResult()` → `GenerativeAiResult` (DTO class
    `WordPress\AiClient\Results\DTO\GenerativeAiResult`).
- `GenerativeAiResult->getTokenUsage()` → a **`TokenUsage`** DTO with **getter methods**
  (not array keys): `getPromptTokens()` (input), `getCompletionTokens()` (output),
  `getThoughtTokens()` (thinking), `getTotalTokens()`.
- `getProviderMetadata()` → `ProviderMetadata` (`getId()` = e.g. `anthropic`);
  `getModelMetadata()` → `ModelMetadata` (`getId()` = e.g. `claude-opus-4-8`).

This is the **primary, reliable** capture source — real tokens + provider/model straight
from the DTO, `estimated = 0`. It is hooked in `class-result-capturer.php`
(`capture_from_core_event`). The other paths are fallbacks only:
  - Optional `wpai_request_log_context` filter (AI logging plugin; not usually present).
  - Transporter decoration via `setHttpTransporter()` (chain, never replace) — best-effort.
  - chars/4 estimate (`estimated = 1`) — last resort when no real tokens arrive.

> History: the original design relied on transporter/array-shape guessing and produced
> `output_tokens = 0`, no provider/model, `estimated = 1`. The core event + DTO getters
> fixed all three. Don't regress to probing array shapes for token usage — the data lives
> behind typed DTO getters.

## The two hard problems (the heart of the design)

1. **Timing.** Limits/accounting are about tokens, but tokens are only known *after* the
   call, while the pre-request `prevent_prompt` hook fires *before*. Capture correlates a
   pre-request "intent" (recorded by the Gatekeeper) with the post-request token usage
   delivered by the `wp_ai_client_after_generate_result` event (see capture mechanism
   above). The intent→result match is by recency, since the event doesn't echo the
   builder identity.
2. **Attribution.** Nothing tells us which plugin made the call. Resolved by layering, in
   confidence order: self-ID action hook (`wp_ai_rate_limiter_attribute`, high) →
   `debug_backtrace` path-mapping to a plugin/theme slug (medium) → `__unknown__` (low).
   User is `get_current_user_id()` / role, or `__system__` for cron/REST.

**Watch:** if real-world data lands mostly as `estimated` or `__unknown__`, the capture
design needs revisiting *before* any Phase 2 enforcement — never hard-block on numbers
you don't trust. The `estimated` / `confidence` fields exist to make this visible.

## Architecture / layout

PHP under `src/`, namespace `\WP_AI_Rate_Limiter\`. React dashboard under `assets/src/`,
built to `build/`.

```
wp-ai-rate-limiter.php            bootstrap: constants, autoloader, activation guard
uninstall.php                     drops tables/options only if opted in
src/
  class-plugin.php                wiring (hooks capture/rest/admin)
  Capture/
    class-gatekeeper.php          hooks wp_ai_client_prevent_prompt (OBSERVE ONLY); pending-intent registry
    class-result-capturer.php     primary: wp_ai_client_after_generate_result event (real DTO tokens); fallbacks: wpai hook / transporter / estimate
    class-chaining-transporter.php fallback decorator that chains, never replaces
  Attribution/
    class-caller-resolver.php     self-ID -> backtrace -> unknown; user/role
  Accounting/
    class-usage-recorder.php      single entry point: ::record($row) — writes event + fans out counters
    class-counter-store.php       atomic INSERT ... ON DUPLICATE KEY UPDATE
    class-cost-calculator.php     pricing table (filterable), tokens -> micros
  Data/
    class-schema.php              dbDelta: events + counters tables; table-name helpers
    class-usage-repository.php    dashboard read queries
  Periods/
    class-window.php              tz-aware day/month period keys + ranges
  Admin/
    class-rest-controller.php     namespace wp-ai-rate-limiter/v1
    class-settings-page.php       Tools -> AI Usage; enqueues the React build
assets/src/{index.js,App.js,style.scss}   React dashboard (four views, spec §7)
```

### Data model
Tables are `{prefix}aiut_*` — `$wpdb->prefix` already ends in `_`, so the helper appends
`aiut_` (NOT `_aiut_`). On a `wp_` install: `wp_aiut_events`, etc.
- `{prefix}aiut_events` — cold append-only detail (per request).
- `{prefix}aiut_counters` — hot pre-aggregated, `UNIQUE(scope_type, scope_key,
  period_kind, period_key)`, updated atomically. One event fans into plugin / user /
  role / model / global counters for both `day` and `month`. Counter scope_keys:
  plugin=slug, user=`(string) user_id`, role=role, model=`"provider/model"`,
  global=`"__all__"`.
- `{prefix}aiut_limits` (Phase 2) — configured limits.
  `UNIQUE(scope_type, scope_key, limit_type, period_kind)`. `scope_key = '*'` = wildcard
  (all keys of that type). `threshold` units match `limit_type` (requests/tokens are
  counts; cost is micros). `enforcement` ∈ off|soft|hard; `min_confidence` ∈ medium|high.

### Enforcement (Phase 2)
- `src/Limits/`: `Limit_Repository` (CRUD + cached `has_enabled_hard_limits()` fast-path),
  `Limit_Evaluator` (`first_hard_breach()`, confidence-gated; `current_usage()` reads
  counters).
- `src/Enforcement/class-enforcer.php`: `should_block(scopes, confidence)` — fast-path
  returns false when no hard limits; else first breach → `do_action('wp_ai_rate_limiter_blocked')`
  + return true. **Fails open** on any error.
- The Gatekeeper builds `scopes = {plugin, user, role, global}` (no model pre-request) and
  calls the Enforcer after recording the intent.
- `src/Alerts/`: `Threshold_Watcher` (hooks `wp_ai_rate_limiter_usage_recorded`, detects
  80%/100% crossings, per-period transient dedup) + `Notifier` (`wp_mail`, plus
  `wp_ai_rate_limiter_notify` action and `wp_ai_rate_limiter_alert_email` filter).
- Confidence gating answers "we can't assume plugins self-identify": **backtrace is the
  no-cooperation default** (medium); self-ID is an optional accuracy upgrade (high). Hard
  limits default to blocking medium+high; a limit's `min_confidence = high` restricts it to
  self-identified callers only. `__unknown__` is never singled out (but can be capped as a
  group via a global/wildcard limit).

### REST (namespace `wp-ai-rate-limiter/v1`, cap `manage_options`, filterable)
`GET /usage`, `GET /timeseries`, `GET /totals`, `GET /pricing`, `PUT /pricing`,
`GET /scopes`, and (Phase 2) `GET/POST /limits`, `PUT/DELETE /limits/{id}`. All reads
delegate to `Usage_Repository`; pricing to `Cost_Calculator`; limits to `Limit_Repository`.

### Dashboard (Tools → AI Usage)
React + `@wordpress/scripts` + `@wordpress/components`. Four views: totals strip,
spend-per-plugin (headline, with confidence badges), spend-per-user/role, usage-over-time
(inline SVG, no heavy chart dep). Money formatted from micros. Mount via
`@wordpress/dom-ready` (the bundle loads in the footer, so a bare `DOMContentLoaded`
listener would never fire).

## Conventions

- **Coding standard:** WordPress (WPCS / WordPress-Extra + Docs). Tabs, snake_case
  functions/vars, full docblocks, **short arrays**, Yoda off. Prefixes:
  `WP_AI_Rate_Limiter` / `wp_ai_rate_limiter` / `aiut`. Text domain `wp-ai-rate-limiter`.
- **File naming (WP convention):** class files are `class-{lowercase-hyphenated}.php`,
  e.g. `\WP_AI_Rate_Limiter\Capture\Gatekeeper` → `src/Capture/class-gatekeeper.php`.
  The autoloader in the main file enforces this mapping — keep them in sync when adding
  classes.
- **PHP 7.4+** syntax only (no enums, no `readonly`, no PHP 8-only syntax). Typed
  properties are avoided in favor of docblock types for breadth.
- **Security:** `$wpdb->prepare` for all SQL (or `$wpdb->insert` with format specifiers),
  sanitize input, escape output, capability + nonce on REST writes.

## Commands

PHP (needs `composer install` first):
```
composer check-cs        # PHPCS  (composer fix-cs auto-fixes what it can)
composer phpstan         # PHPStan level 10
composer lint            # php-parallel-lint
```
JS (needs `npm install` first):
```
npm run build            # wp-scripts build -> build/index.js + index.asset.php + style-index.css
npm start                # watch mode
```
Note: `wp-scripts` emits the stylesheet as **`style-index.css`** (not `index.css`); the
enqueue in `class-settings-page.php` must reference that exact name.

## Before considering a change "done"

1. `composer check-cs` → 0 errors (warnings for custom-table direct DB queries are
   expected and acceptable).
2. `composer phpstan` → no errors. If a new genuine type issue appears, fix it; only add
   to the `ignoreErrors` list for the known noise classes already documented there.
3. `composer lint` and `php -l` clean.
4. If JS changed, `npm run build` compiles clean.
5. The observe-only invariant still holds.

### Live testing

Develop against a real WordPress 7.0 install with an AI provider configured. The plugin is symlinked into
its `wp-content/plugins/`. To test real capture, activate a small caller plugin that runs
`do_action('wp_ai_rate_limiter_attribute','slug'); wp_ai_client_prompt('hi')->generate_text();`
then check Tools → AI Usage. **Real AI calls cost money — keep prompts minimal and few.**

Already verified live (2026-05-30): activation + schema, all 6 REST routes (200 for an
admin), the dashboard rendering across all four views, attribution via the self-ID hook
(`confidence = high`), and real token/provider/model capture via
`wp_ai_client_after_generate_result` (`estimated = 0`).

## CI

`.github/workflows/` — `cs.yml` (PHPCS + cs2pr inline annotations), `phpstan.yml`,
`lint.yml` (PHP 7.4–8.4 matrix). Modeled on the Progress Planner repo's setup.

## Git

This directory is **not yet a git repo**. Do not `git init`, commit, or push without
being asked. `vendor/`, `node_modules/`, and `build/` are gitignored; `composer.lock` is
committed for reproducible CI.
