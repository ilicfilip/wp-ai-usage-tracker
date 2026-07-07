# Testing, Building & Verifying — AI Usage Tracker

This is the hand-off doc for developing, building, and verifying the plugin —
including the **live-instance** workflow on a real WordPress 7.0 install. It
covers the quality gates (and which warnings are acceptable), and — crucially —
**how to exercise the whole capture/enforcement/alert pipeline without spending
a single API token.**

Everything below was verified against the source. File paths are absolute where
it matters; commands assume you are in the plugin root
(`<plugin-root>`).

---

## 1. Toolchain overview

Two independent build systems live side by side:

| Concern | Tool | Config |
| --- | --- | --- |
| PHP deps + quality gates | Composer | `composer.json`, `phpcs.xml.dist`, `phpstan.neon.dist` |
| React dashboard | `@wordpress/scripts` (webpack) | `package.json` |

The PHP runtime targets **PHP 7.4+** (Composer pins the *platform* to 8.3 so the
lockfile resolves consistently — see `composer.json` `config.platform.php`). The
plugin itself only boots on **WordPress >= 7.0 with the AI Client present**
(`wp_ai_client_prompt()` must exist); otherwise it stays dormant — see
`wp_aiut_environment_ok()` in `wp-ai-rate-limiter.php`.

---

## 2. Install & build

### PHP dependencies

```bash
composer install
```

Installs WPCS, PHPCompatibilityWP, parallel-lint, and PHPStan (+ the WordPress
extension) into `vendor/`. Nothing here ships in the plugin zip — they are
`require-dev`.

### JS dependencies + dashboard build

```bash
npm install
npm run build      # production build into build/
```

`npm run build` runs (from `package.json`):

```
wp-scripts build --webpack-src-dir=assets/src --output-path=build
```

**Important wp-scripts quirks the next agent must know:**

- The entry is `assets/src/index.js`; sources are `App.js`, `Limits.js`,
  `style.scss`.
- wp-scripts **extracts the stylesheet to `build/style-index.css`** (plus an RTL
  variant `build/style-index-rtl.css`) — **not** `index.css`. The PHP enqueue in
  `src/Admin/class-settings-page.php` references `style-index.css` for exactly
  this reason (see the inline comment on its `$style_path`).
- It emits `build/index.asset.php`, a manifest of script dependencies + a version
  hash, e.g.:
  ```php
  <?php return array('dependencies' => array('react-jsx-runtime', 'wp-api-fetch', 'wp-components', 'wp-dom-ready', 'wp-element', 'wp-i18n'), 'version' => 'b191294fca165bf510a1');
  ```
  `Settings_Page::enqueue_assets()` reads this file for the real dependency array
  and version. **If `build/index.asset.php` is missing, the dashboard refuses to
  enqueue and shows a "run npm install && npm run build" admin notice.** Always
  build before testing the UI.
- The bundle loads **in the footer**, so React mounts via `@wordpress/dom-ready`
  (`wp-dom-ready` is a declared dependency) rather than mounting at parse time.

### Watch mode (active UI development)

```bash
npm start          # wp-scripts start: rebuilds build/ on every save
```

Leave this running; it writes the same `build/` artifacts incrementally. The
admin page picks them up on reload (the asset version hash busts caches).

### Other JS scripts (from `package.json`)

```bash
npm run lint:js    # wp-scripts lint-js assets/src
npm run lint:css   # wp-scripts lint-style "assets/src/**/*.scss"
npm run format     # wp-scripts format assets/src
```

---

## 3. Quality gates (exact commands)

These four gates are the contract. **All must be green before any change is
considered done.** Each mirrors a CI workflow under `.github/workflows/`.

### 3.1 PHP coding standards — PHPCS

```bash
composer check-cs          # = php ./vendor/bin/phpcs -s
composer fix-cs            # = php ./vendor/bin/phpcbf  (auto-fix)
```

Ruleset is `phpcs.xml.dist` (WordPress-Extra + WordPress-Docs + PHPCompatibilityWP,
short-array enforced, Yoda disabled). **Expected result: `0 ERRORS`.**

There are **4 benign WARNINGs** (across 4 files). They are *accepted by design* —
do not "fix" them by renaming public API or fighting the sniffer:

| File:line | Warning | Why it's acceptable |
| --- | --- | --- |
| `wp-ai-rate-limiter.php:69` | Reserved keyword `class` used as param name (`function wp_aiut_autoload( $class )`) | `$class` is the conventional SPL autoloader parameter name; renaming it would make the autoloader read worse than every other WP autoloader. |
| `src/Limits/class-limit-repository.php:214` | Reserved keyword `default` used as param name | A tiny local enum-sanitiser closure `($value, $allowed, $default)`. `$default` is the clearest possible name for a fallback value; the closure is private. |
| `src/Accounting/class-cost-calculator.php:129` | Dynamic hook name doesn't start with plugin prefix (`apply_filters( self::PRICING_FILTER, ... )`) | The hook **is** prefixed — the *value* of the `PRICING_FILTER` constant is `wp_aiut_pricing`. The sniff can't resolve the constant statically, so it false-positives. |
| `src/Admin/class-rest-controller.php:208` | Dynamic hook name doesn't start with plugin prefix (`self::CAPABILITY_FILTER`) | Same false positive: the constant's value is correctly plugin-prefixed; PHPCS just can't see through the `self::` constant reference. |

If you see **more** than these 4 warnings, or **any** error, you have
regressed something.

> CI mirror: `.github/workflows/cs.yml` runs
> `composer check-cs -- --no-cache --report-full --report-checkstyle=./phpcs-report.xml`
> on PHP 8.3 and surfaces results in the PR via `cs2pr`.

### 3.2 Static analysis — PHPStan (level 10)

```bash
composer phpstan           # = php ./vendor/bin/phpstan analyse --memory-limit=2048M
```

Config: `phpstan.neon.dist`, **level 10**, analysing `src/`,
`wp-ai-rate-limiter.php`, `uninstall.php`. **Expected: no errors.**

The `ignoreErrors` list is deliberate and load-bearing — do not remove entries
casually:

- **Type-detail noise** (`missingType.iterableValue`, `argument.type`,
  `return.type`, casts, etc.) is intentionally not enforced (mirrors the
  progress-planner project's posture).
- **`constant.notFound`** is ignored because single-file analysis can't see the
  `WP_AIUT_*` constants defined in the main file or core runtime
  constants — they are guarded/defined for real at runtime.
- **The WordPress 7.0 AI Client + bundled `php-ai-client` SDK have no PHPStan
  stubs yet.** All access to `wp_ai_client_prompt`,
  `WP_AI_Client_Prompt_Builder`, `GenerativeAiResult`, etc. is runtime-guarded
  with `function_exists`/`method_exists`/`class_exists`, so the corresponding
  "unknown class / function not found" messages are ignored by pattern. **If
  you add a new SDK call, guard it the same way** rather than widening the
  ignore list.
- The optional `WordPress/ai` logging plugin (`wpai_*` functions) is not a
  dependency, so those are ignored too.

> CI mirror: `.github/workflows/phpstan.yml`, PHP 8.3, `composer phpstan`.

### 3.3 Parse-error lint — parallel-lint

```bash
composer lint              # parallel-lint . -e php --show-deprecated
                           #   --exclude vendor --exclude node_modules --exclude .git
```

Pure parse/deprecation check. **Expected: no parse errors.**

> CI mirror: `.github/workflows/lint.yml` runs this across a PHP matrix
> **7.4 / 8.0 / 8.1 / 8.2 / 8.3 / 8.4** (it first `composer remove`s WPCS +
> PHPCompatibility so they don't constrain the installable PHP range). This is
> the gate that actually guarantees the PHP 7.4 floor — keep new syntax 7.4-safe.

### 3.4 JS lint

```bash
npx wp-scripts lint-js assets/src     # or: npm run lint:js
```

No dedicated JS CI workflow exists today — run it locally before touching the
dashboard. (Only `cs`, `lint`, `phpstan` have workflows.)

### Run-everything one-liner

```bash
composer check-cs && composer phpstan && composer lint && npm run build && npx wp-scripts lint-js assets/src
```

---

## 4. The live test environment

The plugin is developed against a **real WP 7.0 install** so the AI Client hooks
actually fire.

- **Install root:** `<wordpress-root>` (Laravel Valet).
- **Symlink:** the repo is symlinked into
  `<wordpress-root>/wp-content/plugins/wp-aiut` — so edits in
  `<plugin-root>` are live immediately; no copy step.
  (`build/` must still be rebuilt for UI changes.)
- **URL:** http://your-site.test
- **Admin:** an admin account
- **AI provider:** Anthropic is configured, so real `generate_text()` calls work.

> The plugin only boots when `wp_ai_client_prompt()` exists (core 7.0 AI Client).
> If activation is refused, you'll get the self-deactivation notice from
> `wp_aiut_activation_notice()` — that means the AI Client isn't
> available in that install.

### WP-CLI is the primary test harness

Run WP-CLI from the WP root so it loads that install:

```bash
wp --path=$WP_ROOT <command>
```

Two workhorses:

- **`wp eval '<php>'`** — boots WordPress and runs PHP in-process. This is how we
  invoke the plugin's classes directly (autoloader is registered on load, so
  `\WP_AIUT\...` resolves).
- **`wp db query '<sql>'`** — inspect the custom tables directly.

Confirm the tables exist (note `wp_` prefix + `aiut_` → `wp_aiut_*`):

```bash
wp --path=$WP_ROOT db query "SHOW TABLES LIKE 'wp_aiut_%'"
# wp_aiut_events / wp_aiut_counters / wp_aiut_limits
```

Inspect recorded usage:

```bash
wp --path=$WP_ROOT db query \
  "SELECT created_at, plugin_slug, plugin_confidence, model, input_tokens, output_tokens, est_cost_micros, estimated FROM wp_aiut_events ORDER BY id DESC LIMIT 10"

wp --path=$WP_ROOT db query \
  "SELECT scope_type, scope_key, period_kind, period_key, requests, est_cost_micros FROM wp_aiut_counters ORDER BY updated_at DESC LIMIT 20"
```

---

## 5. Testing WITHOUT spending API tokens (the important part)

You almost never need a real model call. The pipeline is built so each stage is
independently driveable, and the costly stage (the actual generation) can be
**simulated** by writing the same data the `Result_Capturer` would have written.

Key facts that make this free:

- **Enforcement runs *before* any API call.** `Gatekeeper::observe_prompt()`
  returns `true` (block) on the `wp_ai_client_prevent_prompt` filter, and core
  turns that into `WP_Error('prompt_prevented', ...)` (503) **without ever
  calling the provider**. So testing a *blocked* prompt is free.
- **Usage is recorded by `Usage_Recorder::record( array $row )`** — a plain
  static method. Call it directly to seed synthetic events + counters; no model
  involved.
- **Alerts fire off the `wp_aiut_usage_recorded` action**, which
  `record()` emits. So recording synthetic usage also drives the
  `Threshold_Watcher`.
- **`wp_mail()` can be short-circuited** with the core `pre_wp_mail` filter, so
  you can assert an alert *would* have been sent without an SMTP round-trip.

All snippets below are copy-paste `wp eval` one-liners. Run from the repo root
or anywhere — they use `--path`.

> Tip: wrap multi-line PHP in a heredoc and pipe to `wp eval-file -` if a snippet
> gets long; the inline form below works for everything here.

### 5.1 Seed a limit

Create an enabled **hard** cost limit on `global/*` for the month, then verify
the cached fast-path flag flipped on:

```bash
wp --path=$WP_ROOT eval '
$repo = new \WP_AIUT\Limits\Limit_Repository();
$id = $repo->save([
  "scope_type"     => "global",
  "scope_key"      => "*",
  "limit_type"     => "cost",      // requests | tokens | cost
  "period_kind"    => "month",     // day | month
  "threshold"      => 1000000,     // cost is in micros: 1000000 = $1.00
  "enforcement"    => "hard",      // off | soft | hard
  "min_confidence" => "medium",    // high | medium
  "alert_80"       => 1,
  "alert_100"      => 1,
  "enabled"        => 1,
]);
echo "saved limit id=$id\n";
echo "has_enabled_hard_limits=" . var_export($repo->has_enabled_hard_limits(), true) . "\n";
'
```

`save()` calls `refresh_hard_flag()` internally, which recomputes and caches the
`aiut_has_hard_limits` option. (Thresholds for `cost` are **integer micros** —
1e-6 USD; `requests`/`tokens` are plain counts.)

### 5.2 Check `should_block()` against seeded counter data

`Enforcer::should_block( array $scopes, $confidence )` is the exact call the
Gatekeeper makes. Drive it directly — no prompt, no API:

```bash
wp --path=$WP_ROOT eval '
$enf = new \WP_AIUT\Enforcement\Enforcer();
$scopes = [
  "plugin" => "acme-ai",
  "user"   => "1",
  "role"   => "administrator",
  "global" => "__all__",
];
// confidence: high=self-ID, medium=backtrace, low=__unknown__ (never singled out)
var_dump($enf->should_block($scopes, "high"));
'
```

With a $1.00 hard cap and **no** usage yet this prints `false` (fast path passes
but no breach). To make it return `true`, first push the counter over the
threshold — see 5.3 — then re-run. The decision is **retrospective**: it blocks
the *next* request once accumulated usage already meets the cap.

You can also test the evaluator in isolation:

```bash
wp --path=$WP_ROOT eval '
$repo = new \WP_AIUT\Limits\Limit_Repository();
$ev   = new \WP_AIUT\Limits\Limit_Evaluator($repo);
$breach = $ev->first_hard_breach(["global" => "__all__"], "high");
var_export($breach);   // null, or the breached limit row incl. "current" usage
'
```

### 5.3 Record synthetic usage (no model call)

This writes one `wp_aiut_events` row **and** fans out to `wp_aiut_counters`
across day + month for every scope — exactly what a real captured request would
do. It also computes estimated cost via `Cost_Calculator` and emits the
`wp_aiut_usage_recorded` action (so alerts get a chance to fire):

```bash
wp --path=$WP_ROOT eval '
$ok = \WP_AIUT\Accounting\Usage_Recorder::record([
  "plugin_slug"       => "acme-ai",
  "plugin_confidence" => "high",
  "user_id"           => 1,
  "user_role"         => "administrator",
  "provider"          => "anthropic",
  "model"             => "claude-opus-4-8",
  "input_tokens"      => 500000,
  "output_tokens"     => 500000,
  "thinking_tokens"   => 0,
  "estimated"         => false,
]);
echo "recorded=" . var_export($ok, true) . "\n";
'
```

Then check the counter and re-run the `should_block()` check from 5.2 — with a
big enough token count the cost crosses $1.00 and the global hard limit now
blocks. (Cost is a longest-prefix match: `claude-opus-4-8` resolves against the
`claude-opus-4` family rate, then provider default, then global default — see
`Cost_Calculator::rates_for()`.)

### 5.4 Trip a threshold alert with mail interception

Install a `pre_wp_mail` short-circuit that captures the email instead of sending
it, then record enough usage to cross 80% (or 100%) of a limit that has
`alert_80`/`alert_100` enabled. The `Threshold_Watcher` runs on the
`wp_aiut_usage_recorded` action and calls `Notifier::notify()`, which
calls `wp_mail()`:

```bash
wp --path=$WP_ROOT eval '
// Intercept the email: return non-null from pre_wp_mail to skip real sending.
add_filter("pre_wp_mail", function($null, $atts){
  fwrite(STDOUT, "ALERT MAIL → to={$atts["to"][0]} | subject={$atts["subject"]}\n");
  fwrite(STDOUT, $atts["message"] . "\n");
  return true; // short-circuit: nothing actually sent
}, 10, 2);

// Wire the watcher for this CLI request (normally wired on init in the plugin).
(new \WP_AIUT\Alerts\Threshold_Watcher())->register();

// Record usage that pushes the global cost limit past its alert threshold.
\WP_AIUT\Accounting\Usage_Recorder::record([
  "plugin_slug" => "acme-ai", "plugin_confidence" => "high",
  "user_id" => 1, "user_role" => "administrator",
  "provider" => "anthropic", "model" => "claude-opus-4-8",
  "input_tokens" => 500000, "output_tokens" => 500000, "thinking_tokens" => 0,
  "estimated" => false,
]);
echo "done\n";
'
```

Notes:
- Alerts **dedup per (limit, period, threshold)** via a transient
  (`aiut_alert_<md5>`, 32-day TTL). If an alert doesn't re-fire, it already fired
  this period — clear it with
  `wp --path=$WP_ROOT transient delete --all` (or delete the specific
  `aiut_alert_*` transient) to retest.
- 100% is checked before 80%, so a single event crossing both fires only the
  100% alert.
- You can also bypass email entirely and assert on the structured payload by
  hooking the `wp_aiut_notify` action (fires regardless of email),
  or redirect the recipient with the `wp_aiut_alert_email` filter.

### 5.5 Resetting between runs

```bash
# Wipe synthetic data (keeps schema):
wp --path=$WP_ROOT db query "TRUNCATE wp_aiut_events"
wp --path=$WP_ROOT db query "TRUNCATE wp_aiut_counters"
wp --path=$WP_ROOT db query "TRUNCATE wp_aiut_limits"
# Clear the cached hard-limit flag + alert dedup transients:
wp --path=$WP_ROOT option delete aiut_has_hard_limits
wp --path=$WP_ROOT transient delete --all
```

---

## 6. Making a REAL end-to-end call (sparingly)

When you genuinely need to prove the real capture path
(`wp_ai_client_after_generate_result` → `Result_Capturer` reading the
`GenerativeAiResult` DTO), make the **smallest possible** call. Use the self-ID
action so attribution is high-confidence, then a one-word prompt:

```bash
wp --path=$WP_ROOT eval '
do_action("wp_aiut_attribute", "smoke-test");   // high-confidence self-ID
$text = wp_ai_client_prompt("hi")->generate_text();
echo $text . "\n";
'
```

Then confirm a real (non-estimated) row landed:

```bash
wp --path=$WP_ROOT db query \
  "SELECT plugin_slug, plugin_confidence, provider, model, input_tokens, output_tokens, est_cost_micros, estimated FROM wp_aiut_events ORDER BY id DESC LIMIT 1"
```

You should see `plugin_slug=smoke-test`, `plugin_confidence=high`,
`estimated=0`, and non-zero `input_tokens`/`output_tokens` pulled from
`getTokenUsage()->getPromptTokens()/getCompletionTokens()`. Keep these rare —
they cost money.

---

## 7. Definition of done

A change is done only when **all** of the following hold:

1. **All four gates green:**
   - `composer check-cs` → **0 errors**, only the **4 documented warnings**
     (§3.1). No new warnings.
   - `composer phpstan` → no errors (don't widen `ignoreErrors` to hide a real
     issue; guard SDK calls at runtime instead).
   - `composer lint` → no parse errors (PHP **7.4-safe** syntax — the CI matrix
     starts at 7.4).
   - `npx wp-scripts lint-js assets/src` → clean (if you touched the dashboard).
2. **Dashboard rebuilt** if you changed `assets/src/**`: `npm run build`,
   and confirm `build/index.js`, `build/index.asset.php`, and
   `build/style-index.css` regenerated. Load **Tools → AI Usage** at
   http://your-site.test and confirm it mounts (no console errors, REST calls to
   `wp-aiut/v1/*` succeed).
3. **Observe-only invariant preserved.** Phase 1 must never block. With **no
   enabled hard limits**, `Enforcer::has_enabled_hard_limits()` is false and
   `should_block()` short-circuits to `false` — behaviour is byte-for-byte
   observe-only. Verify with the §5.2 snippet on a fresh DB (no limits) →
   must print `false`. Enforcement is **fail-open**: any `Throwable` in the
   Gatekeeper/Enforcer/Watcher is swallowed and the request proceeds. A change
   must not introduce a path where a plugin bug can take down a site's AI
   features.
4. **Live smoke test.** At minimum the **free** path: seed a hard limit (§5.1),
   push a counter over it (§5.3), confirm `should_block()` flips to `true`
   (§5.2), and confirm an intercepted alert email fires (§5.4). Optionally one
   **real** call (§6) if you touched the `Result_Capturer` / DTO-reading code.

If all four hold, ship it.
