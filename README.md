# AI Usage Tracker

Track and cap **WordPress 7.0 AI Client** usage — per plugin, per user — with an
admin dashboard and optional, confidence-gated limits.

> **Status:** working prototype (v0.1.0). Phase 1 (tracking) and Phase 2 (limits &
> enforcement) are both built and verified on a live WP 7.0 install. Cost figures are
> **estimates** (see [Cost calculation](#cost-calculation)).

---

## Why this exists

WordPress 7.0 ships an AI Client in core with a **single shared provider key** that *any*
plugin can spend against — and **no built-in spend controls**. The official AI plugin added
usage *logging* but no enforcement, and has none on its roadmap.

This plugin fills that gap. Its wedge is **attribution**: answering *"which plugin / which
user spent this money?"* inside one shared key — something the provider's billing dashboard
cannot see — and then letting you cap it.

## What it does

- **Tracks** every AI Client request: tokens (input/output/thinking), provider, model, and
  estimated cost, attributed to the originating **plugin** and **user/role**.
- **Dashboard** at **Tools → AI Usage**: total spend, per-plugin and per-user breakdowns
  (with attribution-confidence badges), a provider/model breakdown, and a usage-over-time
  chart.
- **Limits & enforcement** (Phase 2): configure caps per plugin / user / role / global, by
  **requests / tokens / cost**, per **day / month**. Modes: `off` (track only), `soft`
  (alert), `hard` (block). Hard blocks return a graceful `WP_Error` — no fatals.
- **Alerts** at 80% / 100% of a limit, via email (or a custom channel).

### Two safety guarantees

1. **Observe-only until you say otherwise.** With no *hard* limit configured, the plugin
   only tracks — it never blocks. A cached flag makes this a zero-cost fast path.
2. **Fails open.** Every capture and enforcement path is wrapped so a bug or
   misconfiguration **allows** the request. A usage/billing plugin must never take down a
   site's AI.

## Requirements

- WordPress **7.0+** (the AI Client API ships in core; `wp_ai_client_prompt()` must exist).
- PHP **7.4+**.
- At least one configured AI provider (e.g. the *AI Provider for Anthropic* plugin) under
  **Settings → Connectors** for real requests to flow.

## Install (development)

This is a source checkout, not a packaged plugin. To run it:

```bash
# PHP tooling
composer install

# Build the React dashboard
npm install
npm run build        # emits build/index.js, build/index.asset.php, build/style-index.css
```

Then symlink or copy the directory into `wp-content/plugins/` and activate. On activation
the plugin checks for WP 7.0 + the AI Client and creates its tables; if the environment is
unsupported it deactivates itself with an admin notice rather than erroring.

See **[docs/TESTING.md](docs/TESTING.md)** for the full build/verify workflow and the local
live-test setup.

## Usage

### See your usage
Open **Tools → AI Usage**. Data appears as soon as any plugin makes an AI Client request.

### Help attribution (optional, for plugin authors)
Attribution works automatically via call-stack inspection. A plugin can make itself
**precisely** attributed (and eligible for high-confidence hard limits) by announcing itself
right before its prompt:

```php
do_action( 'wp_ai_rate_limiter_attribute', 'my-plugin-slug' );
$text = wp_ai_client_prompt( 'Summarize this.' )->generate_text();
```

### Set a limit
**Tools → AI Usage → Limits → Add limit.** Pick a scope, a meter (cost/tokens/requests), a
period, a threshold, and an enforcement mode. A `hard` cost limit on a plugin will block
that plugin's requests (with a `WP_Error`) once it exceeds the cap for the period.

## How attribution works

No native mechanism tells us which plugin made a call, so attribution is **layered**, in
confidence order:

1. **Self-ID** (`high`) — the plugin called `wp_ai_rate_limiter_attribute` (above).
2. **Backtrace** (`medium`) — we map the calling file to its plugin/theme slug. Works with
   **zero cooperation** — this is the default for most plugins.
3. **Unknown** (`low`) — `__unknown__`. Still tracked, never dropped.

**Enforcement is confidence-gated:** hard limits block `high`+`medium` by default; a limit
can be set to require `high` (self-identified only); `__unknown__` is never singled out (but
can be capped as a group). This is the deliberate answer to *"we can't assume plugins
self-identify."* See **[docs/DECISIONS.md](docs/DECISIONS.md)**.

## Cost calculation

```
cost (USD) = Σ (tokens × price_per_million) ÷ 1,000,000      # per input/output/thinking
```

- Prices are **per 1,000,000 tokens, in USD**, in a table keyed by `provider/model`.
- Stored as integer **micros** (1e-6 USD) to avoid floating-point drift.
- Model lookup is **exact, then longest-prefix** — so a versioned slug like
  `claude-opus-4-8` resolves to the `claude-opus-4` family entry, and future minor versions
  keep working without edits.

> ⚠️ **The shipped prices are estimates, not verified live rates.** Providers change pricing
> over time and by tier/region. Override any row in the pricing table (option `aiut_pricing`)
> or via the `wp_ai_rate_limiter_pricing` filter before relying on the absolute dollar
> figures. The UI labels figures as *estimated* for this reason.

## Extending it

The plugin exposes actions and filters for integration (self-ID, block notifications,
custom alert channels, pricing overrides, capability/recipient filters) and a REST API under
`wp-ai-rate-limiter/v1`. Full reference: **[docs/HOOKS.md](docs/HOOKS.md)**.

## Documentation

| Doc | What's in it |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | System map, request lifecycle, component responsibilities |
| [docs/DATA-MODEL.md](docs/DATA-MODEL.md) | Tables, columns, scope/period model, options |
| [docs/HOOKS.md](docs/HOOKS.md) | Every action/filter + the REST API |
| [docs/DECISIONS.md](docs/DECISIONS.md) | **Why** things are built this way (read this first for a takeover) |
| [docs/TESTING.md](docs/TESTING.md) | Build, quality gates, and the live/no-cost test workflow |
| `CLAUDE.md` | Guidance for AI coding agents working in this repo |
| `BUILD_STATUS.md` | Point-in-time build/verification status |

Background design docs (problem framing, market investigation, phase specs) live outside the
repo in `<private-docs>/WP_AI_Rate_Limiter_*.md`.

## Project layout

```
wp-ai-rate-limiter.php     bootstrap: constants, autoloader, activation guard
uninstall.php              drops tables/options (opt-in)
src/                       PHP, namespace \WP_AI_Rate_Limiter\ (class-{kebab}.php)
  Capture/                 prevent_prompt hook, result capture, transporter decorator
  Attribution/             caller + user resolution (self-ID / backtrace / unknown)
  Accounting/              usage recorder, atomic counters, cost calculator
  Data/                    schema (dbDelta), usage repository
  Periods/                 timezone-aware day/month windows
  Limits/                  limit repository + evaluator (Phase 2)
  Enforcement/             the block decision (Phase 2)
  Alerts/                  threshold watcher + notifier (Phase 2)
  Admin/                   REST controller, settings page
assets/src/                React dashboard (index.js, App.js, Limits.js, style.scss)
build/                     compiled assets (generated)
docs/                      this documentation set
```

## Conventions & quality

WordPress Coding Standards (PHPCS WordPress ruleset), PHPStan level 10, `php-parallel-lint`,
and `@wordpress/scripts` JS linting — all wired into `composer` scripts and GitHub Actions.
See [docs/TESTING.md](docs/TESTING.md).

## License

GPL-2.0-or-later.
