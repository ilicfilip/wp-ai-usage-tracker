# Build Status — AI Usage Tracker

Phase 1 (tracking) built 2026-05-29; Phase 2 (limits & enforcement) built 2026-05-30.
Specs: `WP_AI_Rate_Limiter_Phase1_Spec.md`, `WP_AI_Rate_Limiter_Phase2_Spec.md`.

## ✅ PHASE 2 COMPLETE — limits & enforcement (2026-05-30)

Built and verified end-to-end on the `<wordpress-root>` WP 7.0 install. The plugin can now
*enforce* limits, not just track. **Still fails open and stays observe-only until a hard
limit is configured.**

What was built (spec `_Phase2_Spec.md`):
- **Limits table** (`{prefix}aiut_limits`, Schema v2) + `Limit_Repository` (CRUD, cached
  "any hard limit?" fast-path flag).
- **`Limit_Evaluator`** — confidence-gated breach detection against Phase 1 counters.
- **`Enforcer`** + Gatekeeper wiring — returns `true` from `wp_ai_client_prevent_prompt`
  when a hard limit is already breached; core then returns its graceful 503 `WP_Error`.
  Fails open on any error; no-op when no hard limits exist.
- **REST**: `GET/POST /limits`, `PUT/DELETE /limits/{id}`.
- **React Limits UI** — list + add/edit/delete form (its own `Limits.js` module).
- **Alerts**: `Threshold_Watcher` (80%/100% crossing detection, per-period dedup) +
  `Notifier` (email via `wp_mail`, `wp_ai_rate_limiter_notify` action for custom channels).

Verified live:
- Enforcer decisions correct: over-limit (high conf) → block; no-limit → allow;
  over-limit but **low confidence** → allow (confidence-gating, the answer to "we can't
  assume self-ID").
- Full path: a self-identified prompt to an over-limit plugin returns the
  `prompt_prevented` 503 `WP_Error` **before any API call** (no cost). An under-limit
  plugin still generates normally.
- UI round-trip: created a hard cost limit through the form → persisted (cost stored as
  micros) → Enforcer read it → correct allow/block.
- Alerts: recording usage that crossed a threshold sent an email (intercepted); a second
  event did **not** re-alert (dedup works).

Key verified core facts (from reading `class-wp-ai-client-prompt-builder.php`):
- `wp_ai_client_prevent_prompt` is `(bool) apply_filters(..., false, clone $this)` —
  return `true` to block; `$builder` is a read-only clone.
- Blocked `generate_*()` → `WP_Error('prompt_prevented', …, ['status'=>503])`. No fatal.

`debug_backtrace` cost (measured): ~0.0004 ms/call with `IGNORE_ARGS`+depth-cap — ~1/1e6
of an AI request. Not a performance concern.

All checks green: `php -l` (18 files), PHPStan level 10, PHPCS 0 errors, parallel-lint,
JS lint, `npm run build`.

---

## Phase 1 — tracking (observe-only; never blocks)

## ✅ FULL END-TO-END VERIFIED — real capture working (2026-05-30)

Tested live on the `<wordpress-root>` WP 7.0 install (`http://your-site.test`) with Anthropic
configured, driving the real browser. **Phase 1 is functionally complete.**

What was proven with real AI calls:
- Real request captured, attributed to the calling plugin via the self-ID hook
  (`plugin_slug = aiut-test-caller`, `confidence = high`).
- **Real token usage**: input + **output** tokens, real `provider = anthropic`,
  `model = claude-opus-4-8`, `estimated = 0`.
- All four dashboard views render the live data correctly (totals, provider/model
  breakdown, spend-per-plugin with confidence badges, spend-per-user).

### The capture bug we found and fixed (the last real correctness gap)

First real call captured `output_tokens = 0`, no provider/model, `estimated = 1` — i.e.
the live-capture paths were failing and it fell back to the input-only estimate.

**Root cause:** the original capturer guessed at transporter internals and probed array
shapes for token usage. The real, reliable source is core's PSR-14 event bridge:
- Action **`wp_ai_client_after_generate_result`** carries an `AfterGenerateResultEvent`.
- `getResult()` → `GenerativeAiResult` → `getTokenUsage()` → a **`TokenUsage` DTO** with
  getter methods `getPromptTokens()` / `getCompletionTokens()` / `getThoughtTokens()`
  (NOT array keys — that's why the old shape-probing found nothing).
- `getProviderMetadata()->getId()` and `getModelMetadata()->getId()` give provider/model.

**Fix:** added `capture_from_core_event()` as the primary path in
`class-result-capturer.php`, hooking that action and reading the DTO getters. The
transporter/log/estimate paths remain as fallbacks. Verified with one call:
`anthropic / claude-opus-4-8`, input + output tokens, `estimated = 0`. All standards
checks (`php -l`, PHPCS, PHPStan) still green.

### Other live fixes this session
- **REST 404 / "No route was found"** — dashboard `apiFetch` calls weren't including the
  `wp-ai-rate-limiter/v1` namespace (a `createRootURLMiddleware` footgun: WP's default
  `/wp-json/` root middleware wins). Fixed by dropping that middleware and passing the
  full namespaced path in each call. Verified: all 4 calls return 200.
- **Table names** carry a double underscore (`wp__aiut_events`) — `$wpdb->prefix` already
  ends in `_`. Cosmetic; left as-is (rename would need a migration).

### Test cleanup (done)
Test data truncated from both tables; the `aiut-test-caller` test plugin
deactivated and removed. The AI Usage Tracker plugin remains symlinked + active.

## ✅ LIVE TEST PASSED — activate + verify schema (2026-05-30)

Symlinked into `<wordpress-root>/wp-content/plugins/` (a real **WP 7.0** install)
and activated via WP-CLI. Results:

- **Activation succeeded**, no fatal — the WP 7.0 + `wp_ai_client_prompt` guard passes
  (confirmed the function is available at runtime on this install).
- **Both tables created** with the correct schema: `wp__aiut_events` and
  `wp__aiut_counters`, and `aiut_db_version` option = `1`.
- **Settings_Page** class loads, page slug `wp-ai-usage` registered under Tools.
- **All 6 REST routes** register under `wp-ai-rate-limiter/v1` (usage, timeseries,
  totals, pricing, scopes + the index).
- **No plugin-related PHP errors.**

Two non-blocking observations (flagged, not changed):
1. **Table names have a double underscore** — `wp__aiut_events` (`$wpdb->prefix` `wp_` +
   `_aiut_`). Harmless but ugly; renaming later needs a migration.
2. **REST controller registers via `init` → then hooks `rest_api_init`.** Works because
   `init` precedes `rest_api_init` in every real request, but it's indirection that
   could be `rest_api_init`-direct. Minor.

Still NOT tested live: real attribution + token capture (needs AI requests firing from a
test plugin with a configured provider) — see "full end-to-end" option.

## What got built

A complete Phase 1 plugin at `<plugin-root>/`:

- **Bootstrap + data** — main file, namespace→`src/` autoloader, non-fatal activation
  guard (WP≥7.0 + `wp_ai_client_prompt`), `dbDelta` schema (events + counters tables,
  cost in integer micros), timezone-aware period windows, opt-in uninstall.
- **Capture + attribution** — `wp_ai_client_prevent_prompt` hook (observe-only, returns
  `$prevent` unchanged), pending-intent registry, `Caller_Resolver` (self-ID hook →
  backtrace path-mapping → `__unknown__`, with confidence), result capture paths A
  (AI-plugin filter) / B (chaining transporter) / C (estimate), all wrapped so they
  can never break a host request.
- **Accounting + REST** — cost calculator (filterable pricing), atomic counter store
  (`INSERT … ON DUPLICATE KEY UPDATE`), usage recorder (fans one event into
  plugin/user/role/model/global counters × day+month), repository read queries, and the
  `wp-ai-rate-limiter/v1` REST controller (`/usage`, `/timeseries`, `/totals`,
  `/pricing`, `/scopes`).
- **Dashboard** — Tools → AI Usage. React (built with `@wordpress/scripts`). All four
  spec §7 views: totals strip, spend-per-plugin (headline, with confidence badges),
  spend-per-user/role toggle, usage-over-time chart (inline SVG, no heavy chart dep).

## Verification done (all green)

| Check | Result |
|---|---|
| `php -l` on all PHP files (16) | ✅ pass |
| **PHPCS** (`composer check-cs`, WordPress-Extra + Docs, PHP 7.4 compat) | ✅ **0 errors** (warnings only: custom-table direct DB queries + 1 dynamic-hook false positive) |
| **PHPStan** (`composer phpstan`, **level 10** + szepeviktor/phpstan-wordpress) | ✅ **No errors** |
| **parallel-lint** (`composer lint`) | ✅ No syntax error (16 files) |
| Autoloader path mapping (`WP_AI_Rate_Limiter\Data\Schema` → `src/Data/class-schema.php`) | ✅ correct (verified by script) |
| `npm install` (1503 pkgs) + `npm run build` (webpack) | ✅ compiled successfully, exit 0 |
| Build artifacts (`index.js`, `index.asset.php`, `style-index.css`) | ✅ emitted |
| REST routes registered vs dashboard `apiFetch` paths | ✅ match |
| api-fetch root + nonce middleware wired | ✅ |
| 4-dimension adversarial review (api-correctness, sql-security, php-correctness, dashboard-rest) | ✅ 7 findings, 3 actionable, all fixed |

## Tooling & CI (matched to the Progress Planner repo)

Added the Emilia Capital / Progress Planner toolchain, adapted to this plugin:

- **`composer.json`** — dev deps: `wp-coding-standards/wpcs ^3.1`, `phpcompatibility-wp`,
  `php-parallel-lint`, `phpstan ^2.0`, `szepeviktor/phpstan-wordpress ^2.0`,
  `phpstan/extension-installer`. Scripts: `check-cs`, `fix-cs`, `lint`, `phpstan`.
- **`phpcs.xml.dist`** — WordPress-Extra + WordPress-Docs + PHPCompatibilityWP
  (`testVersion 7.4-`), prefixes `WP_AI_Rate_Limiter`/`wp_ai_rate_limiter`/`aiut`,
  text domain `wp-ai-rate-limiter`, short-array enforced, Yoda off — same shape as PP's.
- **`phpstan.neon.dist`** — level 10, mirrors PP's ignore-identifier list + SDK/stub
  patterns for the un-stubbed WP 7.0 AI Client.
- **`.github/workflows/`** — `cs.yml` (PHPCS + cs2pr inline), `phpstan.yml`,
  `lint.yml` (PHP 7.4–8.4 matrix) — copied from PP's workflows, trimmed to main/develop.
- **`.gitignore`** — vendor, node_modules, build, reports.

**File-naming convention change:** to match Progress Planner / the WP standard, all 13
class files were renamed from PSR-4 `Gatekeeper.php` to `class-gatekeeper.php`
(lowercase, hyphenated) and the autoloader was rewritten accordingly. Verified all
classes still resolve.

## Bugs caught & fixed during verification

1. **(high) Dashboard would render blank** — entry used `DOMContentLoaded`, but the
   bundle loads in the footer (after that event fires). Switched to `@wordpress/dom-ready`.
   *Caught by adversarial review.*
2. **(high) Path B transporter capture was dead code** — refused to install when no
   existing transporter was set, which is exactly the AI-plugin-absent case it exists
   for. Fixed to wrap the SDK default. *Caught by adversarial review.*
3. **(medium) Over-time chart ignored the period switcher** — timeseries fetch didn't
   pass from/to. Now derives the range client-side. *Caught by adversarial review.*
4. **(real) CSS 404 — dashboard would load unstyled** — enqueue referenced
   `build/index.css`, but wp-scripts emits `build/style-index.css`. Fixed.
   *Caught by my post-build check (the agents couldn't see it — no build had run yet).*

## ⚠️ NOT verified — needs a live WP 7.0 environment (your action)

Static checks can't cover these. This is the spec §9 "definition of done":

1. **Install + activate on a real WP 7.0 site** with a configured AI provider. Confirm
   tables create and no activation errors.
2. **Trigger AI requests from 2+ plugins under 2+ users**, then confirm they appear,
   correctly attributed and costed, across all four dashboard views.
3. **Path B SDK specifics are best-effort.** The exact php-ai-client client class and
   `setHttpTransporter()`/`getHttpTransporter()` accessor names were inferred, not
   verified against an installed build — there's a `wp_ai_rate_limiter_sdk_client`
   filter escape hatch. **Verify token capture actually fires** on a real install; if
   most rows land as `estimated`/`__unknown__`, the capture design needs revisiting
   *before* Phase 2 enforcement (don't hard-block on numbers you don't trust).
4. **`wpai_request_log_context` hook** (Path A) is an AI-plugin experiment — confirm the
   name/signature against the installed AI plugin version.

Fastest way to run 1–2: **WP Playground** (there's a `wp-playground` skill). I can set up
a blueprint that boots WP 7.0, mounts this plugin, and a tiny test plugin that fires AI
requests — say the word.

## Guardrails held

No git init/commit/push. Nothing outside the plugin folder. Nothing sent externally.
`node_modules/` is present from the build (gitignore it before any commit).

## Suggested next steps

1. You: run the live smoke test (or have me set up the Playground blueprint).
2. Then: Phase 2 (limits) — but only once Path B token capture is confirmed reliable.
3. Housekeeping: add `.gitignore` (node_modules, build optional), README, and decide
   git init when you're ready.
