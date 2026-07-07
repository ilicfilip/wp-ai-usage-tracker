# Architecture

> The **map** doc. Read this first to understand how AI Usage Tracker
> (`wp-aiut`) is wired and *why*. For column-level table layouts see
> [DATA-MODEL.md](./DATA-MODEL.md); for every hook name and signature see
> [HOOKS.md](./HOOKS.md); for the reasoning behind specific trade-offs see
> [DECISIONS.md](./DECISIONS.md).

---

## 1. What this plugin is, and why it exists

WordPress 7.0 ships an **AI Client** in core: a single, site-wide provider key
that *any* installed plugin can spend against, with **no** native spend
controls and **no** native way to see *which* plugin or user spent the money.
The provider's own billing dashboard sees one key — it cannot break the spend
down by plugin. That blind spot is the wedge this plugin fills. The defensible
core feature is **attribution**: answering *"which plugin / which user spent
this?"* inside one shared key.

The plugin has two phases, **both built**:

- **Phase 1 — tracking.** Observe every AI Client request, attribute it to the
  originating plugin + user, record real tokens and an estimated cost, and
  surface it all on a dashboard (Tools → AI Usage). **The capture path itself
  never blocks a request.**
- **Phase 2 — limits & enforcement.** Configure limits (per
  plugin/user/role/global, by requests/tokens/cost, per day/month). The Enforcer
  blocks a prompt that has *already* breached a **hard** limit by returning
  `true` from the core `wp_ai_client_prevent_prompt` filter.

### The master invariant: observe-only by default, fail open always

> **The plugin stays observe-only until an enabled *hard* limit is configured,
> and any error anywhere in capture or enforcement fails open (allows the
> request).**

A tracking/billing plugin that breaks a site's AI is worse than useless. This
invariant is load-bearing and is enforced in three concrete places:

1. **`Gatekeeper::observe_prompt()`** wraps all bookkeeping in `try/catch
   (\Throwable)` and only consults the Enforcer *after* the request would
   otherwise proceed (`src/Capture/class-gatekeeper.php`).
2. **`Enforcer::should_block()`** fast-paths to `false` when
   `Limit_Repository::has_enabled_hard_limits()` (a cached option flag) is
   false, and catches every `\Throwable` to return `false`
   (`src/Enforcement/class-enforcer.php`). With no hard limits the plugin
   behaves *identically* to observe-only Phase 1.
3. Every capture path (`Result_Capturer`, `Chaining_Transporter`) wraps its
   observation in `try/catch` so a bug in token extraction can never break the
   AI request — only the forwarding to the real transporter is left
   un-swallowed.

---

## 2. The two hard problems

Everything in the design exists to solve two problems the core AI Client does
not solve for us.

### Problem 1 — Timing (tokens are known only *after* the request)

Accounting and limits are about **tokens**, but the only pre-request hook
(`wp_ai_client_prevent_prompt`) fires *before* the request, when no token count
exists. The SDK has no token-counting API and no metadata-attach channel.

**Solution: post-hoc capture + retrospective enforcement.**

- Pre-request, the **Gatekeeper** records a lightweight *pending intent*
  (attribution + a fingerprint + a `microtime` timestamp) in a request-scoped
  static registry. It does **not** know tokens yet.
- Post-request, the **Result_Capturer** receives real token usage from core's
  `wp_ai_client_after_generate_result` event and **correlates it back** to a
  pending intent — *by recency*, because the event does not echo the builder
  identity (`Gatekeeper::match_pending()` returns the newest un-finalised
  intent, optionally filtered by slug).
- Because tokens arrive too late to gate *this* request, **enforcement is
  retrospective**: the Enforcer blocks the *next* request once accrued usage has
  already met a hard cap.

### Problem 2 — Attribution (nothing says which plugin called)

Core gives us no caller identity. The **Caller_Resolver**
(`src/Attribution/class-caller-resolver.php`) resolves it by **layering, most
reliable first**, attaching a `confidence` to each:

| Order | Mechanism | `source_type` | `confidence` |
|-------|-----------|---------------|--------------|
| 1 | **Self-ID action** — a cooperating plugin fires `do_action( 'wp_aiut_attribute', 'slug' )` right before its prompt; we read the top of a request-scoped stack | `plugin` | `high` |
| 2 | **Backtrace path-mapping** — walk `debug_backtrace()` (depth-capped, args ignored), map the first frame under `wp-content/plugins/<slug>/` or the theme root to that slug; memoised per call site | `plugin` / `theme` | `medium` |
| 3 | **Unknown** — `__unknown__`; still tracked, never dropped | `unknown` | `low` |

The user is resolved separately by `resolve_user()`:
`get_current_user_id()` + primary role, or `user_id = 0` / role `system` for
cron/REST/CLI contexts.

**Backtrace is the no-cooperation default (medium); self-ID is an optional
accuracy upgrade (high).** This matters for enforcement (§4): confidence gating
means a low-confidence attribution is never used to single out a plugin.

> **Watch (from CLAUDE.md):** if real data lands mostly as `estimated` or
> `__unknown__`, the capture design needs revisiting *before* trusting any
> enforcement. The `estimated` and `confidence` fields exist to make that
> visible.

---

## 3. Request lifecycle (the end-to-end walkthrough)

```
                         A WordPress request runs an AI Client prompt
                                          │
   ┌──────────────────────────────────────────────────────────────────────────┐
   │  PRE-REQUEST  (filter: wp_ai_client_prevent_prompt, prio 10, 2 args)      │
   │                                                                            │
   │  Gatekeeper::observe_prompt( $prevent, $builder )                          │
   │    1. Caller_Resolver::resolve()       -> source_slug, source_type,        │
   │                                            confidence (high|medium|low)    │
   │    2. Caller_Resolver::resolve_user()  -> user_id, user_role              │
   │    3. build a fingerprint (slug : builder-hash : ++sequence)               │
   │    4. record PENDING INTENT in static $pending[fingerprint]                │
   │         { slug, type, confidence, user, prompt_chars, created_at,          │
   │           finalized=false }                                                │
   │                                                                            │
   │    5. ENFORCEMENT decision (Phase 2):                                      │
   │         if $prevent already true OR caller/user unresolved -> return       │
   │         build_scopes() = { plugin, user, role, global }                    │
   │         Enforcer::should_block( scopes, confidence )                       │
   │            • fast path: has_enabled_hard_limits()? no -> false (observe)   │
   │            • Limit_Evaluator::first_hard_breach():                         │
   │                 for each scope -> enabled hard limits (key + '*' wildcard) │
   │                 confidence >= limit.min_confidence ?                       │
   │                 current_usage (Counter_Store) >= threshold > 0 ?           │
   │            • breach -> do_action('wp_aiut_blocked'); return true│
   │            • any \Throwable -> return false  (FAIL OPEN)                    │
   │       return true  => core returns WP_Error('prompt_prevented', 503)       │
   │       return $prevent (usually false) => request proceeds                  │
   └──────────────────────────────────────────────────────────────────────────┘
                                          │  (not blocked)
                                          ▼
                         core dispatches the prompt to the provider
                                          │
   ┌──────────────────────────────────────────────────────────────────────────┐
   │  POST-REQUEST  (action: wp_ai_client_after_generate_result, prio 10)      │
   │                                                                            │
   │  Result_Capturer::capture_from_core_event( $event )   [PRIMARY PATH]       │
   │    $result = $event->getResult()    // GenerativeAiResult DTO              │
   │    usage = TokenUsage:                                                      │
   │        getPromptTokens()  -> input                                         │
   │        getCompletionTokens() -> output                                     │
   │        getThoughtTokens() -> thinking                                      │
   │    meta  = getProviderMetadata()->getId(), getModelMetadata()->getId()     │
   │    match = Gatekeeper::match_pending(null)   // newest un-finalised        │
   │    finalize(match, usage, meta, estimated=false)                           │
   └──────────────────────────────────────────────────────────────────────────┘
                                          │
                                          ▼
   ┌──────────────────────────────────────────────────────────────────────────┐
   │  RECORDING   Usage_Recorder::record( $row )                                │
   │    1. normalise/sanitise the row                                           │
   │    2. Cost_Calculator::cost_micros() -> est_cost_micros (integer micros)   │
   │    3. INSERT one cold row into {prefix}aiut_events                         │
   │    4. FAN OUT to {prefix}aiut_counters via Counter_Store::increment()      │
   │         scopes: plugin, user, role, model("provider/model"), global("__all__")
   │         × periods: day + month   (atomic INSERT .. ON DUPLICATE KEY UPDATE)│
   │    5. do_action( 'wp_aiut_usage_recorded', $data, $scopes )     │
   └──────────────────────────────────────────────────────────────────────────┘
                                          │
                                          ▼
   ┌──────────────────────────────────────────────────────────────────────────┐
   │  ALERTS   Threshold_Watcher::on_recorded( $data, $scopes )                 │
   │    for each scope -> enabled limits (key + '*') -> current_usage / threshold
   │    pct >= 100 (alert_100) or >= 80 (alert_80) ?                            │
   │    per-(limit,scope,period,percent) transient dedup -> Notifier::notify()  │
   │       do_action('wp_aiut_notify') + wp_mail(admin)             │
   └──────────────────────────────────────────────────────────────────────────┘
```

### Fallback capture paths (used only when the core event is absent)

The primary path is `wp_ai_client_after_generate_result` (real DTO tokens,
`estimated = 0`). `Result_Capturer::register()` also wires three fallbacks, in
descending priority. They exist so the plugin functions even when the core event
shape changes or the SDK is shaped differently — never as the main path:

- **Path A — `wpai_request_log_context` filter** (the WordPress/ai logging
  plugin, signature `($context, $decoded, $log_data)`, prio 10/3). *Optional*;
  the filter is cheap to hook and simply never fires when the plugin is absent.
  We read usage and return `$context` unchanged.
- **Path B — chaining HTTP transporter** (`Chaining_Transporter`, installed on
  `init` prio 5 via the SDK's `setHttpTransporter()`). It **chains** any existing
  transporter and never replaces it; when none is set it resolves the SDK's own
  default and wraps *that* so the chain still terminates in real transport. All
  SDK touch points are runtime-guarded.
- **Path C — chars/4 estimate** (`shutdown` prio 100). Any still-pending intent
  is finalised with `floor(prompt_chars / 4)` input tokens and `estimated = 1`,
  so counters still move and the dashboard can flag the row as estimated.

> **Do not regress** to probing array shapes for token usage. The real data lives
> behind typed DTO getters on `GenerativeAiResult`; the array-shape probing in
> `Result_Capturer` is fallback-only (see DECISIONS.md).

---

## 4. Component map (`src/`)

Each class's responsibility, read from its source docblock.

### `Capture/` — get the request and its tokens

| Class | File | Responsibility |
|-------|------|----------------|
| `Gatekeeper` | `class-gatekeeper.php` | Hooks `wp_ai_client_prevent_prompt`. Resolves attribution, records a request-scoped *pending intent*, and (Phase 2) consults the Enforcer to optionally block. Owns the `$pending` registry and the `match_pending()` / `mark_finalized()` correlation API. |
| `Result_Capturer` | `class-result-capturer.php` | Turns a completed request into a finalised usage row. Primary path: the core `after_generate_result` event (real DTO tokens). Fallbacks: AI-log filter, transporter decorator, chars/4 estimate. Hands the finalised row to `Usage_Recorder::record()`. |
| `Chaining_Transporter` | `class-chaining-transporter.php` | Decorator that wraps the SDK's HTTP transporter to observe responses (fallback Path B). Always **chains** (forwards to the inner transporter); never swallows transport. Duck-typed (`send`/`request`/`transport`/`__invoke`) because the SDK interface may be absent at analysis time. |

### `Attribution/` — figure out who called

| Class | File | Responsibility |
|-------|------|----------------|
| `Caller_Resolver` | `class-caller-resolver.php` | Resolves the calling plugin/theme (self-ID → backtrace → `__unknown__`, with confidence) and the current user/role (or role `system` for user_id 0; the declared `SYSTEM_USER_KEY = '__system__'` constant is currently unused by `resolve_user()`). Registers the self-ID action listener and maintains the request-scoped self-ID stack + per-call-site backtrace memo. |

### `Accounting/` — record and price usage

| Class | File | Responsibility |
|-------|------|----------------|
| `Usage_Recorder` | `class-usage-recorder.php` | **Single write entry point** (`::record($row)`): appends one `aiut_events` row and fans out atomic counter increments across all five scopes × both periods, then fires `wp_aiut_usage_recorded`. |
| `Counter_Store` | `class-counter-store.php` | Atomic counter upserts (`INSERT … ON DUPLICATE KEY UPDATE` on the `UNIQUE(scope_type, scope_key, period_kind, period_key)` key) plus `read()` / `read_one()` for the dashboard and evaluator. |
| `Cost_Calculator` | `class-cost-calculator.php` | Tokens → estimated cost in **integer micros** (1e-6 USD). Holds the filterable/admin-overridable pricing table; `rates_for()` resolves exact → longest-prefix → provider-default → global-default. |

### `Data/` — storage and dashboard reads

| Class | File | Responsibility |
|-------|------|----------------|
| `Schema` | `class-schema.php` | `dbDelta()` installer for `aiut_events`, `aiut_counters`, `aiut_limits`; table-name helpers; `DB_VERSION` tracking. Idempotent. |
| `Usage_Repository` | `class-usage-repository.php` | Read-side queries for the dashboard: ranked counters, daily timeseries (from events), and totals + per-provider/per-model breakdown. All parameterised via `$wpdb->prepare()`. |

### `Periods/` — time bucketing

| Class | File | Responsibility |
|-------|------|----------------|
| `Window` | `class-window.php` | Timezone-aware period keys (`day` = `Y-m-d`, `month` = `Y-m`) and half-open `[from, to)` ranges. No DB access. A new period is just a new key — never a destructive reset. |

### `Limits/` — configured limits (Phase 2)

| Class | File | Responsibility |
|-------|------|----------------|
| `Limit_Repository` | `class-limit-repository.php` | CRUD over `aiut_limits`; scope lookups (`enabled_for_scope()` returns specific-key + `*` wildcard rows); the cached `has_enabled_hard_limits()` fast-path flag (option `aiut_has_hard_limits`, refreshed on every write). |
| `Limit_Evaluator` | `class-limit-evaluator.php` | Given a request's scopes + confidence, finds the `first_hard_breach()` (confidence-gated, threshold met); computes `current_usage()` from counters for the limit's metric/period. Holds no state; reads counters. |

### `Enforcement/` — the block decision (Phase 2)

| Class | File | Responsibility |
|-------|------|----------------|
| `Enforcer` | `class-enforcer.php` | `should_block(scopes, confidence)`: fast-path `false` when no hard limits; else first breach → `do_action('wp_aiut_blocked')` + return `true`. **Fails open** (returns `false`) on any `\Throwable`. |

### `Alerts/` — threshold notifications (Phase 2)

| Class | File | Responsibility |
|-------|------|----------------|
| `Threshold_Watcher` | `class-threshold-watcher.php` | Hooks `wp_aiut_usage_recorded`; for each touched scope checks enabled limits for 80%/100% crossings; per-(limit, scope, period, percent) transient dedup so each alert fires at most once per period. |
| `Notifier` | `class-notifier.php` | Delivers the alert: fires `wp_aiut_notify` (for custom channels) then `wp_mail()` to the (filterable) admin recipient. Formats cost micros → USD. |

### `Admin/` — REST API and dashboard

| Class | File | Responsibility |
|-------|------|----------------|
| `Rest_Controller` | `class-rest-controller.php` | Registers the `wp-aiut/v1` namespace and its routes. Reads delegate to `Usage_Repository`; pricing to `Cost_Calculator`; limits to `Limit_Repository`. Every route guarded by a filterable `manage_options` permission callback. |
| `Settings_Page` | `class-settings-page.php` | Adds Tools → AI Usage and enqueues the compiled React build *only on its own screen*; injects `window.wpAiUsageTracker` (REST root + `wp_rest` nonce). |

### Bootstrap (`wp-ai-rate-limiter.php`) and wiring (`Plugin`)

`wp-ai-rate-limiter.php` defines constants, registers the PSR-4-ish autoloader
(`\WP_AIUT\Capture\Gatekeeper` → `src/Capture/class-gatekeeper.php`),
guards activation/boot on the environment (`wp_aiut_environment_ok()`
requires WP ≥ 7.0 + `wp_ai_client_prompt`), installs the schema on activation,
and calls `Plugin::boot()` on `plugins_loaded`.

`Plugin` (`src/class-plugin.php`) is a deliberately minimal singleton container.
Every collaborator is constructed behind `class_exists()` / `method_exists()`
guards (other agents own those files; some builds may lack them).

---

## 5. Wiring — which hook each component registers on

The `Plugin` container itself registers two hooks in `init()`:
`add_action('init', 'register_runtime')` and
`add_action('admin_menu', 'register_admin')`. Everything else is registered by
the components those callbacks construct.

| Hook | Registered by | What it does |
|------|---------------|--------------|
| `plugins_loaded` | `wp-ai-rate-limiter.php` | `Plugin::boot()` (guarded on environment). |
| `init` | `Plugin::register_runtime()` | Constructs Gatekeeper, Rest_Controller, Threshold_Watcher and calls their `register()`. |
| `admin_menu` | `Plugin::register_admin()` | Constructs Settings_Page and calls `register()`. |
| `wp_ai_client_prevent_prompt` (filter, 10/2) | `Gatekeeper::register()` | `observe_prompt()` — attribution + pending intent + enforcement decision. **The only place the plugin can block.** |
| `wp_aiut_attribute` (action, 10/1) | `Caller_Resolver::register()` (called from `Gatekeeper::register()`) | Pushes a self-identified slug onto the stack (high-confidence attribution). |
| `wp_ai_client_after_generate_result` (action, 10/1) | `Result_Capturer::register()` | **Primary capture** — real DTO tokens. |
| `wpai_request_log_context` (filter, 10/3) | `Result_Capturer::register()` | Fallback Path A (AI logging plugin; no-op when absent). |
| `init` (prio 5) | `Result_Capturer::register()` | Installs the chaining transporter (fallback Path B). |
| `shutdown` (prio 100) | `Result_Capturer::register()` | Finalises leftover intents with chars/4 estimates (fallback Path C). |
| `rest_api_init` | `Rest_Controller::register()` | Registers all `wp-aiut/v1` routes. |
| `admin_enqueue_scripts` | `Settings_Page::register()` | Enqueues the React build on its own screen only. |
| `wp_aiut_usage_recorded` (action, 10/2) | `Threshold_Watcher::register()` | 80%/100% crossing detection + alerts. |
| `admin_notices` | `wp-ai-rate-limiter.php` (+ `Settings_Page`) | Activation-refused notice; missing-build notice. |

See [HOOKS.md](./HOOKS.md) for full signatures, the public extension hooks
(`wp_aiut_blocked`, `_notify`, `_alert_email`, `_capability`,
`_pricing`, `_chars_per_token`, `_sdk_client`, `_default_transporter`), and the
REST route table.

---

## 6. REST + dashboard at a glance

- **REST namespace:** `wp-aiut/v1`. Routes: `GET /usage`,
  `GET /timeseries`, `GET /totals`, `GET|PUT /pricing`, `GET /scopes`, and
  (Phase 2) `GET|POST /limits`, `PUT|DELETE /limits/(?P<id>\d+)`. Every route is
  guarded by `check_permission()` → `current_user_can( apply_filters(
  'wp_aiut_capability', 'manage_options' ) )`.
- **Dashboard:** React via `@wordpress/scripts`, mounted at Tools → AI Usage
  into `#wp-aiut-root`. Source in `assets/src/{index.js,App.js,Limits.js,
  style.scss}`, built to `build/`. Note `wp-scripts` emits the stylesheet as
  **`style-index.css`** (not `index.css`), and the bundle loads in the footer so
  it mounts via `@wordpress/dom-ready`.

---

## 7. Data model in one paragraph

Three tables, all `{$wpdb->prefix}aiut_*` (the prefix already ends in `_`, so the
helper appends `aiut_` — on a `wp_` install: `wp_aiut_events`). `aiut_events` is
the **cold**, append-only per-request log. `aiut_counters` is the **hot**,
pre-aggregated table keyed `UNIQUE(scope_type, scope_key, period_kind,
period_key)` and updated atomically — counters are authoritative and survive
event pruning. `aiut_limits` (Phase 2) holds configured limits keyed
`UNIQUE(scope_type, scope_key, limit_type, period_kind)`, with `scope_key = '*'`
as the wildcard. Cost is stored everywhere as **integer micros** (1e-6 USD), no
floats. Full column definitions and scope-key conventions live in
[DATA-MODEL.md](./DATA-MODEL.md).
