# Decisions & Why (ADR log)

> For the next agent taking over **AI Usage Tracker** (`wp-aiut`).
>
> This is the *why* behind the non-obvious choices. The code says **what** it does; this
> file says **why it does it that way** and **what breaks if you change it**. Each entry
> has Decision / Context / Why / Consequences, plus a "Don't regress to…" where a previous
> wrong approach actually shipped and was fixed.
>
> Cross-references point at real files under `src/` and `assets/src/`. Verify against the
> source — these notes were written against the code as it stands, not a spec.

---

## ADR-1 — Observe-only by default, fail-open everywhere, cached hard-limit fast path

**Decision.** The plugin observes every AI request and **never blocks** unless an
*enabled, breached, confidence-satisfied **hard** limit* exists. With no hard limits
configured it behaves exactly like a passive tracker. Every capture and enforcement path
is wrapped so that any error **allows** the request (fails open).

**Context.** WP 7.0 ships AI in core with one shared provider key any plugin can spend
against, and no spend controls. This plugin's value is attribution + (Phase 2) limits. But
it sits on `wp_ai_client_prevent_prompt`, a filter that fires *before every prompt* and can
kill it by returning `true`. A tracking/billing plugin that breaks a site's AI is worse
than useless.

**Why.**
- The hot-path question "is there anything to enforce at all?" is answered from a cached
  option flag, **not** a query. `Limit_Repository::has_enabled_hard_limits()`
  (`src/Limits/class-limit-repository.php`) reads the `aiut_has_hard_limits` option;
  `Enforcer::should_block()` (`src/Enforcement/class-enforcer.php`) calls it first and
  returns `false` immediately when it's false. So observe-only installs pay ~one
  `get_option()` (autoloaded, `update_option(..., true)`) per prompt — no limits table read.
- The flag is recomputed and re-cached on every limit `save()`/`delete()` via
  `refresh_hard_flag()`, which runs `SELECT COUNT(*) ... WHERE enabled = 1 AND
  enforcement = 'hard'`. The flag stays correct without invalidation logic elsewhere.
- `Gatekeeper::observe_prompt()` (`src/Capture/class-gatekeeper.php`) wraps the
  bookkeeping in `try/catch(\Throwable)` and only calls the Enforcer *after* the intent is
  recorded; `Enforcer::should_block()` wraps its whole body and returns `false` on any
  `\Throwable`. Result capture, transporter decoration, attribution — all
  `try/catch(\Throwable)` with the catch swallowing the error.

**Consequences.**
- Enabling the plugin on a busy site cannot regress AI behaviour until an admin
  deliberately creates a `hard` limit. That is the intended safety story — keep it.
- **Don't regress to:** doing a live limits-table query on every prompt to decide whether
  to enforce, or letting any exception in capture/enforcement propagate. Both violate the
  "never break a site's AI" invariant. If you add a new enforcement input, gate it behind
  the cached flag and wrap it in fail-open `try/catch`.
- The verified WP 7.0 builder uses `__call` magic methods, so `method_exists()` returns
  **false** for its fluent/generate methods. Never gate behaviour on `method_exists()`
  against the prompt builder.

---

## ADR-2 — Capture real tokens from the core `wp_ai_client_after_generate_result` event + DTO getters

**Decision.** The primary capture path is the core PSR-14 → WordPress action
**`wp_ai_client_after_generate_result`**, whose payload is an `AfterGenerateResultEvent`.
We read `getResult()` → `GenerativeAiResult`, then the typed DTO getters:
`getTokenUsage()->getPromptTokens()/getCompletionTokens()/getThoughtTokens()`,
`getProviderMetadata()->getId()`, `getModelMetadata()->getId()`. See
`Result_Capturer::capture_from_core_event()` and `extract_usage_from_result()` /
`extract_meta_from_result()` in `src/Capture/class-result-capturer.php` (const
`CORE_RESULT_ACTION`).

**Context.** Tokens are only known *after* the call; the SDK exposes no token counter and
no metadata-attach channel. The original design tried to learn tokens by decorating the
HTTP transporter and probing array shapes of the raw response.

**Why.** That original approach was wrong and *shipped a bug*: the first real AI call
captured `output_tokens = 0`, **no provider/model**, and `estimated = 1` — i.e. it fell
through every "real" path to the input-only chars/4 estimate. Root cause: token usage
lives behind **typed DTO getter methods**, not array keys, so shape-probing
(`$arr['usage']['output_tokens']` etc.) found nothing. The fix added
`capture_from_core_event()` as the primary path; verified live capturing `anthropic /
claude-opus-4-8`, real input+output tokens, `estimated = 0`.

Every DTO access is guarded with `method_exists()` (on the *result/usage DTOs*, which are
plain getter objects — not the magic-method builder) so a future SDK shape change degrades
gracefully rather than fatalling. The file passes `php -l` with the SDK entirely absent.

**Consequences.**
- The intent→result match is **by recency**, not identity: the event does not echo which
  builder it corresponds to, so `capture_from_core_event()` calls
  `$gatekeeper->match_pending( null )` and takes the newest un-finalised intent
  (`Gatekeeper::match_pending()`). This is correct for the overwhelmingly common
  one-call-per-request shape; concurrent interleaved calls within a single PHP request
  could mis-pair. Acceptable for now; revisit if real data shows interleaving.
- **Stale-intent hardening.** Because matching is by recency, an intent that never
  receives its result would otherwise linger as the "newest un-finalised" match and steal
  the *next* genuine result — a permanent off-by-one for the rest of the request. Two
  guards close this: (1) a **blocked** prompt (prior filter or a hard-limit block) discards
  its intent immediately in `observe_prompt()` — no request runs, so no result will arrive;
  (2) `match_pending()` ignores intents older than a correlation window
  (`Gatekeeper::MATCH_MAX_AGE_SECONDS`, default 300s, filter `wp_aiut_match_max_age`), so an
  abandoned/errored intent ages out and is left for the shutdown estimate sweep rather than
  mis-pairing a later real result. **Don't regress to:** matching un-finalised intents of
  unbounded age, or leaving a blocked call's intent live.
- The other three paths still exist as **fallbacks only**, in priority order: Path A the
  optional `wpai_request_log_context` filter (AI logging plugin, usually absent), Path B
  the chaining transporter decorator (`Chaining_Transporter`, chains via
  `setHttpTransporter()` — **never replaces** an existing transporter), Path C a chars/4
  estimate swept on `shutdown` and flagged `estimated = 1`.
- **Don't regress to:** probing array shapes / transporter internals for token usage as
  the *primary* source. The reliable data is behind typed DTO getters on the core event.
  If output tokens come back 0 or provider/model are blank, suspect the core event path
  first — not the fallbacks.

---

## ADR-3 — Retrospective enforcement (block the *next* request), bounded one-request overshoot

**Decision.** Enforcement is **retrospective**: a hard limit blocks the *next* request once
*already-accumulated* usage meets or exceeds the threshold. We do not pre-estimate a
request's cost and block it before it runs (an upfront-estimate gate is off by default and
not wired into the block decision).

**Context.** The `prevent_prompt` filter fires *before* the call, but tokens — and
therefore cost — are only known *after*. You cannot know what a request will cost at the
moment you have to decide whether to allow it.

**Why.** `Limit_Evaluator::first_hard_breach()` (`src/Limits/class-limit-evaluator.php`)
reads the **current** counter value via `current_usage()` (which reads the pre-aggregated
`{prefix}aiut_counters` row for the limit's period) and compares it to the threshold. The
Gatekeeper records the intent, then asks the Enforcer; the Enforcer reads counters that
reflect *prior* completed requests. So a cap is enforced one request after it is crossed.

**Consequences.**
- **Bounded overshoot of exactly one request.** The request that *tips* usage past the cap
  still runs (its tokens aren't known yet); the *following* request is the one blocked.
  This is an accepted, documented tradeoff — there is no way to block the tipping request
  without a reliable upfront cost, which the SDK cannot give us.
- `build_scopes()` in the Gatekeeper deliberately omits the **model** scope (the model
  isn't known pre-request). Model limits are still *tracked* and accrue under counters;
  they just can't be the dimension that triggers a pre-request block. Per-model caps are
  effectively retrospective via the other scopes / global.
- **Don't regress to:** assuming you can hard-block the exact request that exceeds a cap.
  If you add an upfront token estimate, keep it advisory/off-by-default and never let a
  *wrong* estimate become the basis for a hard block (see ADR-4).

---

## ADR-4 — Confidence-gated enforcement: backtrace is the no-cooperation default; self-ID is the high-confidence upgrade; `__unknown__` is never singled out

**Decision.** Attribution carries a confidence level and enforcement is gated on it:
- **high** — the plugin self-identified via `do_action( 'wp_aiut_attribute',
  'slug' )` before its prompt.
- **medium** — we mapped a `debug_backtrace()` frame to a plugin/theme directory slug.
- **low** — `__unknown__`; nothing could be resolved.

Hard limits default to `min_confidence = medium` (block medium + high). A limit set to
`min_confidence = high` only ever fires for self-identified callers. `__unknown__` is
**never singled out** by a plugin-scoped limit, but *can* be capped as a group via a
global or `scope_key = '*'` wildcard limit.

See `Caller_Resolver::resolve()` (`src/Attribution/class-caller-resolver.php`) for the
high→medium→low layering, and `Limit_Evaluator::confidence_allows()` plus the
`$confidence_rank` map (`low=0, medium=1, high=2`) for the gate. The Limit_Repository only
allows `min_confidence ∈ {high, medium}` (`CONFIDENCES`).

**Context.** Nothing in the AI Client tells us which plugin made a call. The honest answer
to "can we assume plugins self-identify?" is **no** — so the system must work, and enforce,
*without* cooperation, while still rewarding cooperation with higher trust.

**Why.** The risk being managed is **hard-blocking on a wrong attribution**. A backtrace
can mis-attribute (shared libraries, Action Scheduler/cron frames, mu-plugins — see
ADR-5), so a medium-confidence attribution is good enough to *track* and to block under the
default, but an admin who wants stricter blocking can set `min_confidence = high` to
restrict a limit to callers that explicitly identified themselves. `low`/`__unknown__` is
never the basis for singling out a specific plugin — you can't fairly cap "plugin X" using
a request you couldn't attribute to plugin X — but the group is still cappable globally so
unattributed spend isn't a blind spot.

**Consequences.**
- `confidence_allows()` defaults a *missing* `min_confidence` to `1` (medium) — i.e. a
  malformed limit is treated as medium, never as "block everything including unknown."
- The `estimated` and `plugin_confidence` columns on every event row exist precisely to
  make trust visible. **Watch:** if real-world data lands mostly as `estimated` or
  `__unknown__`, the *capture* design needs revisiting before leaning on enforcement —
  never hard-block on numbers you don't trust.
- **Don't regress to:** treating all attributions as equally trustworthy for enforcement,
  or letting a plugin-scoped hard limit fire on a `low`/`__unknown__` request.

---

## ADR-5 — `debug_backtrace` for attribution: cheap enough; the real cost is accuracy, not speed

**Decision.** Backtrace path-mapping is the medium-confidence attribution mechanism.
`Caller_Resolver::resolve_from_backtrace()` calls
`debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, self::BACKTRACE_DEPTH )` (depth cap **30**),
walks frames, skips core (`ABSPATH . WPINC`) and our own plugin dir, and maps the first
frame under the plugins or themes base dir to its directory slug. Results are memoised per
call site (`file:line`) in `$call_site_cache` for the request.

**Context.** A naive worry is that `debug_backtrace()` on every prompt is too expensive to
run in production.

**Why.** Measured cost is ~**0.0004 ms/call** with `IGNORE_ARGS` + the depth cap — about
one-millionth of a typical ~1s AI request. `IGNORE_ARGS` avoids
copying (potentially huge prompt) argument values; the depth cap bounds the walk; the
per-call-site memo means repeated calls from the same code location don't re-walk the
filesystem. Speed is a non-issue.

The **real** tradeoffs are *accuracy*, not performance:
- Shared libraries / vendored code: the first plugin-dir frame may belong to a bundled lib
  rather than the plugin that "meant" to make the call.
- Cron / Action Scheduler / WP-CLI: the originating plugin may be several frames removed,
  or absent from the trace, landing as `__unknown__`.
- mu-plugins outside the plugins/themes map resolve to `__unknown__` (the resolver only
  maps the plugins dir and `get_theme_root()`).

**Consequences.**
- This is exactly why attribution is layered and confidence-gated (ADR-4): self-ID exists
  as the accurate upgrade for cooperating plugins, and `__unknown__` is a first-class
  bucket, never dropped.
- **Don't regress to:** removing `DEBUG_BACKTRACE_IGNORE_ARGS` (it both speeds things up
  and avoids copying sensitive prompt args), uncapping the depth, or "fixing perf" that
  isn't a problem. If you want better attribution, improve *which frame* is chosen, not the
  call's speed.

---

## ADR-6 — Cost as integer micros; prices are estimates; longest-PREFIX model matching

**Decision.** Cost is stored and computed as **integer micros** (1e-6 USD) — never floats
in the DB. Prices are a filterable/admin-overridable table of USD-per-1,000,000-tokens,
keyed `"provider/model"`. `Cost_Calculator::rates_for()`
(`src/Accounting/class-cost-calculator.php`) resolves a model by: (1) exact match, (2)
**longest-prefix** match within the same provider, (3) `provider/__default__`, (4)
`__default__/__default__`.

**Context.** Float accumulation drifts; and provider model slugs are versioned
(`claude-opus-4-8`) while a hand-maintained price table can only realistically carry family
entries (`claude-opus-4`).

**Why.**
- Micros: `cost_micros()` computes `tokens * price_per_million` per bucket (input/output/
  thinking) and `round()`s the sum to an integer. Because price is "USD per 1e6 tokens" and
  micros are "USD × 1e6", the per-bucket micro cost is simply `tokens × price` — no
  intermediate fractional USD, no drift, exact aggregation in the counters.
- Longest-prefix matching fixed a **real ~5× cost understatement**: when the exact slug
  `claude-opus-4-8` missed, the old fallback dropped straight to
  `__default__/__default__` (input 3 / output 15 USD per 1M) instead of the
  `anthropic/claude-opus-4` family (input 15 / output 75) — understating Opus output cost
  ~5×. Prefix matching makes `claude-opus-4-8` resolve to the `claude-opus-4` family
  entry, and future minor versions resolve **without table edits**. The match requires the
  configured model to be a *prefix* of the real model and picks the **longest** such match,
  so `claude-opus-4` beats a shorter `claude` would-be entry.

**Consequences.**
- Prices ship as **2026 placeholder estimates** and are explicitly labelled estimates in
  the dashboard. Admins override via the `aiut_pricing` option (REST `PUT /pricing`) and/or
  the `wp_aiut_pricing` filter; defaults are always re-merged at read time so a
  partial override can't delete the `__default__/__default__` safety net.
- A `cost` limit's `threshold` is in **micros**, matching `est_cost_micros` in the
  counters. Keep units aligned if you add limit types.
- **Don't regress to:** floats in the DB, or a flat exact-match-then-global-default lookup
  (that's the bug that understated Opus ~5×). If you add a model, prefer the **family**
  slug as the key and let prefix matching cover versions.

---

## ADR-7 — WordPress file-naming convention + custom autoloader; single-underscore table prefix; prototype ⇒ no migration

**Decision.** PHP classes live in `class-{lowercase-hyphenated}.php` files matching the WP
convention, resolved by a custom autoloader in `wp-ai-rate-limiter.php`
(`wp_aiut_autoload`). Tables are `{$wpdb->prefix}aiut_{name}` — the helper
`wp_aiut_table()` appends `aiut_` (single underscore), since `$wpdb->prefix`
already ends in `_`.

**Context.** Two naming surfaces: PSR-style class names need a WP-convention file mapping;
and the table prefix helper originally double-appended an underscore.

**Why.**
- Autoloader: it strips the `WP_AIUT\` namespace prefix, maps sub-namespaces to
  directories, lowercases + hyphenates the class name, and prepends `class-`. So
  `\WP_AIUT\Capture\Gatekeeper` → `src/Capture/class-gatekeeper.php`. **Keep the
  file name and class name in sync when you add a class** or the autoloader silently won't
  find it (`is_readable()` guard just returns).
- Table prefix: the helper builds `wp_aiut_events` (correct). **Caveat for the takeover:**
  an *earlier* build double-appended (`wp__aiut_events`, see `BUILD_STATUS.md`). The helper
  in the current code is correct (`$wpdb->prefix . 'aiut_' . $name`). If you encounter a
  live install with double-underscore tables, that's a pre-fix prototype install — because
  this was still a prototype with no production data, the choice was **no migration**; just
  reinstall/recreate. Don't write a migration for a prototype artifact.

**Consequences.**
- `uninstall.php` rebuilds the names inline as `$wpdb->prefix . 'aiut_events'` /
  `'aiut_counters'` / `'aiut_limits'` to mirror the helper (it can't call the helper —
  uninstall runs in a bare context). It drops all three tables and deletes every option the
  plugin writes: `aiut_db_version`, `aiut_delete_on_uninstall`, `aiut_pricing`,
  `aiut_settings`, and `aiut_has_hard_limits` (the autoloaded hard-limit fast-path flag).
  **Keep this list in sync** when you add a table or a persisted option — a fully opted-in
  uninstall must leave nothing behind.
- Schema is versioned (`Schema::DB_VERSION = '2'`, option `aiut_db_version`); `install()`
  is idempotent via `dbDelta()`. Bump `DB_VERSION` when columns change.

---

## ADR-8 — PHPCS: scoped exclusions for unavoidable custom-table SQL

**Decision.** The custom-table direct-DB sniffs are **scoped-excluded** in
`phpcs.xml.dist` for the data-layer files only (`src/Data/*`, `src/Accounting/*`,
`src/Admin/class-rest-controller.php`, `uninstall.php`), rather than littering the codebase
with inline `// phpcs:ignore` or relaxing the rule globally.

**Context.** The plugin owns custom tables. Their names are `$wpdb->prefix`-derived
identifiers that **cannot** be parameterised in SQL (you can't `%s` a table name), and the
hot per-row counter upserts/reads are intentionally not object-cached. PHPCS's
`WordPress.DB.*` sniffs flag all of this.

**Why.** Excluding `PreparedSQL.InterpolatedNotPrepared`, `DirectDatabaseQuery.DirectQuery`,
`DirectDatabaseQuery.NoCaching` (and `SchemaChange` for the schema installer) **only** for
those files means genuine DB issues *elsewhere* are still caught. The table name is always
a trusted, internal identifier (never user input); all *values* still go through
`$wpdb->prepare`, `$wpdb->insert` with format specifiers, etc. This is the standard WP
pattern for plugins with their own tables.

**Consequences.**
- `composer check-cs` is expected to be 0 errors; warnings about custom-table direct DB
  queries inside the scoped files are expected and acceptable.
- **Don't regress to:** disabling these sniffs project-wide, or sprinkling per-line ignores.
  If you add a new data-layer file with custom-table SQL, add it to the scoped
  exclude-patterns — don't widen the scope or ignore inline.

---

## ADR-9 — Dashboard footguns: full namespaced api-fetch paths, `dom-ready` mount, ToggleGroupControl, `style-index.css`

**Decision (a) — api-fetch uses the FULL namespaced path; no `createRootURLMiddleware`.**
Every dashboard call passes the complete path, e.g. `apiFetch( { path:
'wp-aiut/v1/totals' } )` (`assets/src/App.js`, `assets/src/Limits.js`). We do
**not** install `createRootURLMiddleware`.

- **Why.** WordPress's own `@wordpress/api-fetch` already installs a default root-URL
  middleware (`/wp-json/`) that **wins** over a custom-namespace root, stripping the
  namespace and producing 404s ("No route was found"). This actually shipped as a bug and
  was fixed by dropping the custom root middleware and prefixing every call with the full
  `wp-aiut/v1` namespace. We still install the nonce middleware
  (`createNonceMiddleware`) from `window.wpAiUsageTracker.nonce`.
- **Don't regress to:** `createRootURLMiddleware( config.restRoot )` + bare paths. The
  default `/wp-json/` middleware will silently override it.

**Decision (b) — mount via `@wordpress/dom-ready`, not `DOMContentLoaded`.** `index.js`
wraps `createRoot(...).render()` in `domReady(...)`.

- **Why.** The bundle is enqueued in the **footer** (`$in_footer = true` in
  `Settings_Page::enqueue_assets`), so by the time it runs `#wp-aiut-root` is already in
  the DOM and the document is `interactive`/`complete`. A bare `DOMContentLoaded` listener
  would have already missed its event and **never fire**, leaving the dashboard blank.
  `domReady()` invokes the callback immediately in that case.
- **Don't regress to:** `document.addEventListener('DOMContentLoaded', …)` for a
  footer-loaded bundle.

**Decision (c) — `__experimentalToggleGroupControl`, not `ButtonGroup`.** The segmented
controls (period, scope, chart metric) use
`__experimentalToggleGroupControl` / `__experimentalToggleGroupControlOption`
(`assets/src/App.js`).

- **Why.** `ButtonGroup` as a segmented control is deprecated in this `@wordpress/components`
  version; `ToggleGroupControl` is the supported replacement and is still behind the
  `__experimental` prefix in the WP version this targets. Keep the experimental import until
  it graduates; rename when it does.

**Decision (d) — enqueue `style-index.css`, not `index.css`.** `enqueue_assets` references
`build/style-index.css`.

- **Why.** `@wordpress/scripts` (`npm run build`) extracts the stylesheet to
  **`style-index.css`** (from `style.scss` imported in `index.js`), *not* `index.css`. The
  enqueue must reference that exact filename or the stylesheet 404s and the dashboard is
  unstyled.
- **Don't regress to:** `index.css`. Re-check the emitted filename if you change the build
  config or entry point.

**Shared context.** The dashboard is a thin shell: `Settings_Page::render()` prints a single
`#wp-aiut-root` and injects `window.wpAiUsageTracker` (REST root + `wp_rest` nonce +
currency + the attribute action name) via `wp_add_inline_script(..., 'before')`. Assets load
**only** on the Tools → AI Usage screen (gated on the `add_submenu_page` hook suffix), never
site-wide.

---

## ADR-10 — User/role rows are enriched for display at request time, not stored

**Decision.** The counters table stores only the **numeric user id** (`scope_key` for user
scope) and the **raw role slug** (for role scope) — never names or URLs. Human-friendly,
linked labels are added in the REST layer (`get_usage` → `enrich_user_rows()` /
`enrich_role_rows()` in `src/Admin/class-rest-controller.php`) and rendered by the
`ScopeName` component in `assets/src/App.js`.

- **User rows** gain `display_name`, `user_login`, and (capability-permitting) `edit_url`;
  the UI shows `User #1 (filip)` with `filip` linked to the profile.
- **Role rows** gain a translated `role_label` and (capability-permitting) a `list_url`
  pointing at `users.php?role=<role>`; the UI shows the linked role name.

**Why.**
- **Storage stays stable and join-free.** Ids/slugs are the durable identity; display names
  change (a user can rename themselves) and must reflect *current* state, so resolving at
  read time is correct — caching a name at capture time would go stale.
- **Capability-gated links.** `edit_url` is only added when the viewer
  `can( 'edit_user', $id )` and `list_url` only when they `can( 'list_users' )`, so the
  dashboard never exposes a link the current admin couldn't otherwise reach.
- **Reserved buckets stay generic.** The `__system__` / user-`0` and `system`-role buckets
  (cron/REST/CLI) are intentionally left unenriched — they aren't real users/roles — and the
  UI falls back to its plain label (`User #0`, `system`).

**Consequences.**
- The `/usage` response shape is scope-dependent: user/role rows carry extra optional fields.
  Documented in `HOOKS.md` under `GET /usage`.
- `ScopeName` must keep its fallback path (plain `scopeLabel()`) for rows without enrichment
  (e.g. plugin/model scopes, or reserved buckets).
- **Don't regress to:** storing display names in the counters/events tables, or rendering raw
  ids/slugs in the UI when enrichment fields are present.
