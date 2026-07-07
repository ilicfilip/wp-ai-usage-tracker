# Data Model & Storage Reference

This is the database and storage reference for **AI Usage Tracker** (`wp-aiut`).
It documents the three custom tables, the scope/period model that ties them together, the
options/transients the plugin uses, and the deliberate design decisions behind them.

Read this alongside the source it describes:

- `src/Data/class-schema.php` — the `dbDelta()` `CREATE TABLE` statements (authoritative).
- `src/Accounting/class-counter-store.php` — atomic counter upsert/reads (the hot path).
- `src/Accounting/class-usage-recorder.php` — the single write entry point (`record()`).
- `src/Limits/class-limit-repository.php` — limits CRUD + the cached fast-path flag.
- `src/Periods/class-window.php` — timezone-aware period keys and ranges.
- `wp-ai-rate-limiter.php` — `wp_aiut_table()` (the name helper).
- `uninstall.php` — what gets dropped/deleted (and what doesn't).

---

## 1. Table naming

All tables are named `{$wpdb->prefix}aiut_{name}`. The single source of truth is the
helper in `wp-ai-rate-limiter.php`:

```php
function wp_aiut_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'aiut_' . $name;
}
```

`$wpdb->prefix` **already ends in an underscore** (e.g. `wp_`), so the helper appends
`aiut_` and **not** `_aiut_`. On a standard `wp_` install the three tables are:

| Logical name | Physical table (wp_ install) | `Schema` accessor       |
| ------------ | ---------------------------- | ----------------------- |
| events       | `wp_aiut_events`             | `Schema::events_table()`   |
| counters     | `wp_aiut_counters`           | `Schema::counters_table()` |
| limits       | `wp_aiut_limits`             | `Schema::limits_table()`   |

> **Decision:** the name is computed in exactly one place so every consumer
> (`Schema`, repositories, `uninstall.php`) agrees. `uninstall.php` cannot call the
> autoloaded helper safely in all teardown contexts, so it re-derives the name inline
> with the same `$wpdb->prefix . 'aiut_' . $name` formula — keep those in sync.

---

## 2. The events vs counters split (why two tables)

A single AI request produces **one** row in `aiut_events` and **ten** counter upserts in
`aiut_counters` (5 scopes × 2 period kinds). Both happen inside
`Usage_Recorder::record()`.

- **`aiut_events` is cold and append-only.** It is the per-request forensic log: one row
  per captured request with full detail (which plugin, which user, provider, model, exact
  token counts, estimated flag). It is written once and never updated. It is intended to
  be prunable for retention without affecting accounting.

- **`aiut_counters` is hot and pre-aggregated.** It holds running totals per
  scope/period, maintained with a single atomic
  `INSERT ... ON DUPLICATE KEY UPDATE`. The dashboard and (Phase 2) the Enforcer read it.

**Why the split:** the enforcement/accounting hot path must answer "how much has scope X
spent this period?" by reading **one indexed counter row**, not by scanning and summing
the entire event log. Counters are authoritative running totals; they are **never derived
from events**, so events can be pruned for retention without corrupting the counters
(`Usage_Recorder` docblock: "Counters are authoritative and are never derived from
events"). Events answer "show me the detail / break it down later"; counters answer "what
is the total right now" cheaply.

---

## 3. Table: `aiut_events` (cold, append-only)

Per-request detail log. Exact columns, copied from the `dbDelta()` SQL in
`src/Data/class-schema.php`:

```sql
CREATE TABLE {prefix}aiut_events (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	plugin_slug varchar(191) NOT NULL DEFAULT '__unknown__',
	plugin_confidence varchar(20) NOT NULL DEFAULT 'low',
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	user_role varchar(100) NOT NULL DEFAULT '',
	provider varchar(100) NOT NULL DEFAULT '',
	model varchar(191) NOT NULL DEFAULT '',
	input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
	output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
	thinking_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
	est_cost_micros bigint(20) unsigned NOT NULL DEFAULT 0,
	estimated tinyint(1) NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY created_at (created_at),
	KEY plugin_slug_created_at (plugin_slug, created_at),
	KEY user_id_created_at (user_id, created_at)
) {charset_collate};
```

### Columns

| Column              | Type                  | Default                 | Meaning |
| ------------------- | --------------------- | ----------------------- | ------- |
| `id`                | `bigint unsigned` AI  | —                       | Surrogate primary key. |
| `created_at`        | `datetime`            | `'0000-00-00 00:00:00'` | When recorded. Written via `current_time( 'mysql' )` (site-local time). |
| `plugin_slug`       | `varchar(191)`        | `'__unknown__'`         | Attributed plugin/theme slug. `__unknown__` when attribution fails. |
| `plugin_confidence` | `varchar(20)`         | `'low'`                 | Attribution confidence: `high` (self-ID hook), `medium` (backtrace), `low` (unknown). Normalised against this set by `Usage_Recorder::normalize()`. |
| `user_id`           | `bigint unsigned`     | `0`                     | `get_current_user_id()`, or `0` for no logged-in user. |
| `user_role`         | `varchar(100)`        | `''`                    | Resolved user role string. |
| `provider`          | `varchar(100)`        | `''`                    | Provider id from the DTO (e.g. `anthropic`). |
| `model`             | `varchar(191)`        | `''`                    | Model id from the DTO (e.g. `claude-opus-4-8`). |
| `input_tokens`      | `bigint unsigned`     | `0`                     | Prompt tokens (`TokenUsage::getPromptTokens()`). |
| `output_tokens`     | `bigint unsigned`     | `0`                     | Completion tokens (`getCompletionTokens()`). |
| `thinking_tokens`   | `bigint unsigned`     | `0`                     | Thought/reasoning tokens (`getThoughtTokens()`). |
| `est_cost_micros`   | `bigint unsigned`     | `0`                     | Estimated cost in **integer micros** (1e-6 USD). See §7. |
| `estimated`         | `tinyint(1)`          | `0`                     | `1` if tokens were estimated (chars/4 fallback), `0` if real DTO tokens. |

### Keys

- `PRIMARY KEY (id)`
- `KEY created_at (created_at)` — time-range scans.
- `KEY plugin_slug_created_at (plugin_slug, created_at)` — per-plugin detail over time.
- `KEY user_id_created_at (user_id, created_at)` — per-user detail over time.

> `varchar(191)` for `plugin_slug` / `model` is the classic utf8mb4 index-safe length
> (191 × 4 bytes ≤ the 767-byte index prefix limit on older MySQL).

---

## 4. Table: `aiut_counters` (hot, pre-aggregated)

Running totals per scope/period. Exact columns from `class-schema.php`:

```sql
CREATE TABLE {prefix}aiut_counters (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	scope_type varchar(20) NOT NULL DEFAULT 'global',
	scope_key varchar(191) NOT NULL DEFAULT '',
	period_kind varchar(10) NOT NULL DEFAULT 'day',
	period_key varchar(20) NOT NULL DEFAULT '',
	requests bigint(20) unsigned NOT NULL DEFAULT 0,
	input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
	output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
	thinking_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
	est_cost_micros bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY scope_period (scope_type, scope_key, period_kind, period_key)
) {charset_collate};
```

### Columns

| Column            | Type              | Default     | Meaning |
| ----------------- | ----------------- | ----------- | ------- |
| `id`              | `bigint unsigned` AI | —        | Surrogate primary key. |
| `scope_type`      | `varchar(20)`     | `'global'`  | Scope dimension: `plugin` / `user` / `role` / `model` / `global`. See §6. |
| `scope_key`       | `varchar(191)`    | `''`        | Identity within the scope type (slug, user id string, role, `provider/model`, `__all__`). See §6. |
| `period_kind`     | `varchar(10)`     | `'day'`     | `day` or `month`. |
| `period_key`      | `varchar(20)`     | `''`        | Period identity: `Y-m-d` for day, `Y-m` for month. See §5. |
| `requests`        | `bigint unsigned` | `0`         | Number of requests in this bucket. |
| `input_tokens`    | `bigint unsigned` | `0`         | Summed prompt tokens. |
| `output_tokens`   | `bigint unsigned` | `0`         | Summed completion tokens. |
| `thinking_tokens` | `bigint unsigned` | `0`         | Summed thought tokens. |
| `est_cost_micros` | `bigint unsigned` | `0`         | Summed estimated cost in micros. |
| `updated_at`      | `datetime`        | `'0000-00-00 00:00:00'` | Last upsert time (`current_time('mysql')`). |

### Keys

- `PRIMARY KEY (id)`
- `UNIQUE KEY scope_period (scope_type, scope_key, period_kind, period_key)` —
  **the load-bearing constraint.** It guarantees exactly one row per
  scope/period bucket and is what makes the atomic upsert collapse onto a single row, and
  what makes the hot-path read a single indexed lookup.

### The atomic upsert

`Counter_Store::increment()` builds one statement (table name interpolated from the
trusted prefix-derived constant, all values bound via `$wpdb->prepare()`):

```sql
INSERT INTO {table}
	(scope_type, scope_key, period_kind, period_key,
	 requests, input_tokens, output_tokens, thinking_tokens, est_cost_micros, updated_at)
VALUES (%s, %s, %s, %s, %d, %d, %d, %d, %d, %s)
ON DUPLICATE KEY UPDATE
	requests        = requests + VALUES(requests),
	input_tokens    = input_tokens + VALUES(input_tokens),
	output_tokens   = output_tokens + VALUES(output_tokens),
	thinking_tokens = thinking_tokens + VALUES(thinking_tokens),
	est_cost_micros = est_cost_micros + VALUES(est_cost_micros),
	updated_at      = VALUES(updated_at)
```

The delta columns it maintains are `Counter_Store::COLUMNS`:
`requests`, `input_tokens`, `output_tokens`, `thinking_tokens`, `est_cost_micros`.
Deltas are normalised to non-negative integers (`max(0, (int) …)`); unknown keys ignored,
missing keys default to `0`.

> **Why ON DUPLICATE KEY UPDATE rather than SELECT-then-UPDATE:** the counter moves in a
> single statement, so concurrent requests don't need a read lock held across the
> round-trip and can't lose increments to a race. The `UNIQUE(scope_period)` key is what
> the upsert keys on.

### Reads

- `Counter_Store::read( $scope_type, $period_kind, $period_key )` — all rows for a scope
  type in a period, `ORDER BY est_cost_micros DESC` (powers the dashboard breakdowns).
- `Counter_Store::read_one( $scope_type, $scope_key, $period_kind, $period_key )` — the
  single bucket row (the enforcement hot-path read), or `null`.

---

## 5. Period model (`Periods\Window`)

`Window` is pure date math — **no database access** — and resolves everything in the
**site timezone** (`wp_timezone()`).

- Two kinds: `Window::KIND_DAY = 'day'`, `Window::KIND_MONTH = 'month'`.
- `period_key` formats:
  - `day`   → `Y-m-d` (e.g. `2026-05-29`)
  - `month` → `Y-m`   (e.g. `2026-05`)
- `Window::current_period_key( $kind )` returns the key for "now"; `period_key( $kind, $ts )`
  for an arbitrary timestamp. `@`-timestamps are treated as UTC then shifted into the site
  timezone.
- `Window::range( $kind, $period_key )` returns a half-open `[from, to)` interval of
  `DateTimeImmutable` (site tz) — `from` is the first instant of the period, `to` the
  first instant of the next — or `null` if the key is malformed for the kind (validated by
  regex `^\d{4}-\d{2}-\d{2}$` / `^\d{4}-\d{2}$`).

> **Decision — there is no reset job.** A new period is simply a new `period_key`, so its
> counter row is created on the first request of that period (the upsert inserts at the
> delta values), starting at zero. There is no cron that zeroes counters at midnight or
> month boundaries; old period rows just stop being written to. This means quota
> "resets" are free and cannot fail. The site timezone is the boundary that matters —
> change the WP timezone and the day/month edges move with it.

---

## 6. Scope model (precise)

One usage event fans out into **5 scopes × 2 period kinds = 10 counter upserts**. The
fan-out is in `Usage_Recorder::record()`:

```php
$scopes = [
	[ 'plugin', $data['plugin_slug'] ],
	[ 'user',   (string) $data['user_id'] ],
	[ 'role',   $data['user_role'] ],
	[ 'model',  self::model_scope_key( $data['provider'], $data['model'] ) ],
	[ 'global', '__all__' ],
];
$period_kinds = [ Window::KIND_DAY, Window::KIND_MONTH ];
```

### `scope_type` ∈ { `plugin`, `user`, `role`, `model`, `global` }

### `scope_key` conventions (counters)

| `scope_type` | `scope_key` convention                  | Notes |
| ------------ | --------------------------------------- | ----- |
| `plugin`     | plugin slug                             | `__unknown__` when attribution failed. |
| `user`       | `(string) user_id`                      | The numeric user id, cast to string. `"0"` for no logged-in user. |
| `role`       | the role string                         | May be empty string if no role resolved. |
| `model`      | `"provider/model"`                      | Built by `model_scope_key()`; empty provider/model each become `__unknown__`, so the key is e.g. `anthropic/claude-opus-4-8` or `__unknown__/__unknown__`. |
| `global`     | `"__all__"`                             | The single site-wide bucket. |

> `model_scope_key()` substitutes `__unknown__` for an empty provider or model
> **independently**, so a partially-known event can produce `anthropic/__unknown__`.

> **Display names are NOT stored.** The counters keep only the numeric `user_id` and the
> raw role slug. Human labels + profile/role links are resolved at request time in the REST
> layer (`enrich_user_rows()` / `enrich_role_rows()`), never persisted — see ADR-10 in
> `DECISIONS.md` and `GET /usage` in `HOOKS.md`. This keeps the identity stable while the
> display name always reflects the user's *current* name.

### `scope_key` in the **limits** table — the `'*'` wildcard

The limits table reuses the same `scope_type` set, but its `scope_key` has an **extra
convention**: `'*'` means **wildcard / all keys of that type**. `Limit_Repository::sanitize()`
defaults an empty `scope_key` to `'*'` (and the column `DEFAULT` is `'*'`). The matcher
(`enabled_for_scope()`) selects both the concrete key and the wildcard:

```sql
WHERE enabled = 1 AND enforcement <> 'off'
  AND scope_type = %s
  AND scope_key IN ( %s, '*' )
```

So a `plugin` / `*` limit caps "all plugins" and applies alongside any plugin-specific
limit. This is the mechanism by which `__unknown__` callers — never singled out
individually — can still be capped **as a group** via a global or wildcard limit.

> **Note the asymmetry:** in the **counters** table the site-wide bucket is the literal
> `global` / `__all__` row. In the **limits** table the catch-all is the `'*'` wildcard.
> They are different strings serving related-but-distinct roles; don't conflate them.

---

## 7. Cost storage: integer micros

`est_cost_micros` (both tables) is the estimated cost in **micros = 1e-6 USD**
(`$1.00` = `1000000`). Cost is computed by `Cost_Calculator::cost_micros()` and stored as
a `bigint unsigned` integer.

> **Decision — never store money as a float.** Token-rate math (prices per 1e6 tokens)
> produces fractional cents that accumulate across thousands of events; floating-point
> drift would make the running counter totals subtly wrong over time. Storing integer
> micros keeps the `requests + VALUES(requests)`-style summation exact. Prices are
> estimates (admin-overridable; see the pricing option), but the *arithmetic* is exact.
> The dashboard divides by 1e6 only at the display edge.

This is a hard invariant (see `CLAUDE.md`): **cost is stored as integer micros, no floats
in the DB.**

---

## 8. Table: `aiut_limits` (Phase 2)

Configured usage limits, consumed by the Enforcer and `Threshold_Watcher`. Exact columns
from `class-schema.php`:

```sql
CREATE TABLE {prefix}aiut_limits (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	scope_type varchar(20) NOT NULL DEFAULT 'global',
	scope_key varchar(191) NOT NULL DEFAULT '*',
	limit_type varchar(20) NOT NULL DEFAULT 'cost',
	period_kind varchar(10) NOT NULL DEFAULT 'month',
	threshold bigint(20) unsigned NOT NULL DEFAULT 0,
	enforcement varchar(10) NOT NULL DEFAULT 'soft',
	min_confidence varchar(10) NOT NULL DEFAULT 'medium',
	alert_80 tinyint(1) NOT NULL DEFAULT 1,
	alert_100 tinyint(1) NOT NULL DEFAULT 1,
	enabled tinyint(1) NOT NULL DEFAULT 1,
	created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY scope_limit (scope_type, scope_key, limit_type, period_kind),
	KEY enabled_enforcement (enabled, enforcement)
) {charset_collate};
```

### Columns

| Column           | Type              | Default     | Meaning |
| ---------------- | ----------------- | ----------- | ------- |
| `id`             | `bigint unsigned` AI | —        | Surrogate primary key. |
| `scope_type`     | `varchar(20)`     | `'global'`  | `plugin` / `user` / `role` / `model` / `global` (`Limit_Repository::SCOPE_TYPES`). |
| `scope_key`      | `varchar(191)`    | `'*'`       | Concrete key, or `'*'` wildcard (= all keys of the type). See §6. |
| `limit_type`     | `varchar(20)`     | `'cost'`    | The metric: `requests` / `tokens` / `cost` (`LIMIT_TYPES`). Determines the units of `threshold`. |
| `period_kind`    | `varchar(10)`     | `'month'`   | `day` / `month` (`PERIOD_KINDS`). |
| `threshold`      | `bigint unsigned` | `0`         | The cap, in units of `limit_type`: a **count** for `requests`/`tokens`, **micros** for `cost`. |
| `enforcement`    | `varchar(10)`     | `'soft'`    | `off` / `soft` / `hard` (`ENFORCEMENTS`). Only `hard` blocks; `soft` alerts only; `off` is inert. |
| `min_confidence` | `varchar(10)`     | `'medium'`  | Minimum attribution confidence to enforce: `medium` or `high` (`CONFIDENCES`). `high` restricts the limit to self-identified callers only. |
| `alert_80`       | `tinyint(1)`      | `1`         | Send the 80%-crossing alert. |
| `alert_100`      | `tinyint(1)`      | `1`         | Send the 100%-crossing alert. |
| `enabled`        | `tinyint(1)`      | `1`         | Master on/off for the row. |
| `created_at`     | `datetime`        | `'0000-00-00 00:00:00'` | Row creation time. |
| `updated_at`     | `datetime`        | `'0000-00-00 00:00:00'` | Last modification time. |

### Keys

- `PRIMARY KEY (id)`
- `UNIQUE KEY scope_limit (scope_type, scope_key, limit_type, period_kind)` — one limit
  per (scope_type, scope_key, metric, period). Writes are upserts onto this identity.
- `KEY enabled_enforcement (enabled, enforcement)` — supports the
  "any enabled hard limit?" count and the enabled-limit scan.

### Validation (`Limit_Repository::sanitize()`)

Enum columns are clamped to their allowed sets, falling back to the defaults above
(`scope_type → global`, `limit_type → cost`, `period_kind → month`, `enforcement → soft`,
`min_confidence → medium`). An empty `scope_key` becomes `'*'`; non-empty is
`sanitize_text_field()`'d and truncated to 191 chars. `threshold` is `max(0, (int))`.
`alert_80`/`alert_100`/`enabled` are coerced to `0`/`1`. Reads cast `id`, `threshold`,
`alert_80`, `alert_100`, `enabled` back to `int` for JSON (`cast_row()`).

---

## 9. Options & transients

| Key                         | Storage   | Autoload | Written by | Purpose |
| --------------------------- | --------- | -------- | ---------- | ------- |
| `aiut_db_version`           | option    | `false`  | `Schema::install()` | Installed schema version (`Schema::DB_VERSION_OPTION`). Currently `'2'`. |
| `aiut_pricing`              | option    | (default) | `Cost_Calculator` / REST `PUT /pricing` | Admin pricing overrides (`Cost_Calculator::PRICING_OPTION`). Merged over built-in rates; also filterable via `wp_aiut_pricing`. |
| `aiut_has_hard_limits`      | option    | `true`   | `Limit_Repository::refresh_hard_flag()` | **Cached fast-path flag.** `1` if any `enabled = 1 AND enforcement = 'hard'` limit exists, else `0`. Read by the Enforcer on every prompt to short-circuit when there's nothing to enforce. Recomputed on every limit save/delete. Autoloaded so the hot-path read is free. |
| `aiut_delete_on_uninstall`  | option    | (default) | admin/settings | Opt-in: only when truthy does `uninstall.php` drop tables/delete options. Default behaviour keeps all data. |
| `aiut_settings`             | option    | (default) | settings    | General settings blob (deleted on opt-in uninstall). |
| `aiut_alert_{md5}`          | transient | —        | `Threshold_Watcher::maybe_fire()` | Per-period **alert dedup**. Key is `'aiut_alert_' . md5( limit_id . '|' . scope_key . '|' . period_key . '|' . percent )`; set to `1` with a 32-day TTL (longest plausible period) so each 80%/100% crossing alerts at most once per period. |

### The fast-path flag in detail (`aiut_has_hard_limits`)

`Limit_Repository::has_enabled_hard_limits()` reads the option; if it's `null` (never
computed) it falls through to `refresh_hard_flag()`, which runs
`SELECT COUNT(*) ... WHERE enabled = 1 AND enforcement = 'hard'` and caches the boolean
(autoloaded). Every `save()` and `delete()` calls `refresh_hard_flag()` to keep it
current.

> **Why:** the Enforcer reads this on **every** prompt. When `false` it short-circuits and
> the plugin behaves exactly like observe-only Phase 1 — no per-prompt limit query at all.
> The whole enforcement layer is dormant until an admin actually configures a hard limit.

---

## 10. Schema versioning & the no-migration caveat

- `Schema::DB_VERSION = '2'` (version 2 added the Phase 2 `aiut_limits` table).
  `Schema::DB_VERSION_OPTION = 'aiut_db_version'`.
- `Schema::install()` is idempotent: it runs `dbDelta()` on all three `CREATE TABLE`
  statements (which reconciles live columns/keys against the declared SQL) and then
  `update_option( 'aiut_db_version', '2', false )`.

> **Caveat — this is a prototype with NO migration logic.** There is **no** code that
> reads the stored `aiut_db_version`, branches on it, and runs versioned data migrations.
> The version bump on `DB_VERSION` is purely a marker; schema evolution relies entirely on
> `dbDelta()` reconciling structure. `dbDelta()` can **add** columns/keys but does **not**
> drop or rename columns, change semantics of existing data, or backfill. If a future
> change needs anything beyond an additive column/index — a column rename, a data
> backfill, a type narrowing — it must be written by hand; do not assume bumping
> `DB_VERSION` does anything on its own. Treat the tables as recreatable from scratch, not
> migratable.

---

## 11. Uninstall behaviour (and a known gap)

`uninstall.php` runs only on a real uninstall (`WP_UNINSTALL_PLUGIN` defined) **and** only
when `aiut_delete_on_uninstall` is truthy. When it does run it drops:

- `{prefix}aiut_events`
- `{prefix}aiut_counters`

and deletes the options `aiut_db_version`, `aiut_delete_on_uninstall`, `aiut_pricing`,
`aiut_settings`.

> **Known gap for the next agent:** `uninstall.php` does **not** drop the Phase 2
> `{prefix}aiut_limits` table, and does **not** delete the `aiut_has_hard_limits` option
> or any `aiut_alert_*` dedup transients. On an opt-in delete these are left behind. If
> full teardown matters, extend `uninstall.php` to drop the limits table and delete the
> hard-flag option (transients expire on their own within 32 days).
