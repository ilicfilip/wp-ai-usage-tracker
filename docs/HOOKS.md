# Hooks & REST API Reference

The complete public extension surface of **AI Usage Tracker** (`wp-aiut`):
every action/filter the plugin **fires** (for you to hook), every core/3rd‑party hook it
**consumes**, and the REST API.

This file is generated from — and verified against — the source. Every hook name,
signature, priority, and arg count below was read out of `src/`. If you change a hook,
update this file in the same change.

> **Naming.** All plugin‑provided hooks are prefixed `wp_aiut_`. The
> attribution self‑ID action is the one most integrators care about — see
> [`wp_aiut_attribute`](#wp_aiut_attribute) first.

---

## Quick index

### Hooks the plugin fires (you hook these)

| Hook | Type | Fired in | Purpose |
| --- | --- | --- | --- |
| [`wp_aiut_attribute`](#wp_aiut_attribute) | action *(you fire it, plugin listens)* | `Attribution\Caller_Resolver` | Self‑identify your plugin before a prompt (the good‑citizen integration point). |
| [`wp_aiut_usage_recorded`](#wp_aiut_usage_recorded) | action | `Accounting\Usage_Recorder` | After an event is written + counters fanned out. |
| [`wp_aiut_blocked`](#wp_aiut_blocked) | action | `Enforcement\Enforcer` | A hard limit is about to block a prompt. |
| [`wp_aiut_notify`](#wp_aiut_notify) | action | `Alerts\Notifier` | A limit crossed an 80%/100% alert threshold (custom alert channel). |
| [`wp_aiut_pricing`](#wp_aiut_pricing) | filter | `Accounting\Cost_Calculator` | Override the active pricing table. |
| [`wp_aiut_alert_email`](#wp_aiut_alert_email) | filter | `Alerts\Notifier` | Override the alert email recipient. |
| [`wp_aiut_capability`](#wp_aiut_capability) | filter | `Admin\Rest_Controller` | Override the capability required for the REST API / dashboard. |
| [`wp_aiut_chars_per_token`](#wp_aiut_chars_per_token) | filter | `Capture\Result_Capturer` | Override the chars→token divisor for the estimate fallback. |
| [`wp_aiut_match_max_age`](#wp_aiut_match_max_age) | filter | `Capture\Gatekeeper` | Correlation window (seconds) for matching a result back to a pending intent. |
| [`wp_aiut_sdk_client`](#wp_aiut_sdk_client) | filter | `Capture\Result_Capturer` | Hand the plugin the SDK client object for transporter decoration. |
| [`wp_aiut_default_transporter`](#wp_aiut_default_transporter) | filter | `Capture\Chaining_Transporter` | Hand the plugin the SDK default transporter to wrap (Path B). |

### Core / SDK hooks the plugin consumes

| Hook | Type | Origin | Why we use it |
| --- | --- | --- | --- |
| [`wp_ai_client_prevent_prompt`](#wp_ai_client_prevent_prompt) | filter | WP 7.0 AI Client (core) | Observe every prompt pre‑request; return `true` to block on a hard limit. |
| [`wp_ai_client_after_generate_result`](#wp_ai_client_after_generate_result) | action | WP 7.0 AI Client (core) | **Primary capture path** — real tokens + provider/model from the result DTO. |
| [`wpai_request_log_context`](#wpai_request_log_context) | filter | `wordpress/ai` logging plugin (optional) | Fallback capture path; read‑only, returned unchanged. |

---

## Hooks the plugin fires

### `wp_aiut_attribute`

> **The good‑citizen integration point.** If your plugin makes AI Client calls, fire
> this immediately before each prompt so usage is attributed to you with **`high`**
> confidence instead of relying on a `debug_backtrace` guess (`medium`) or landing in
> `__unknown__` (`low`).

- **Type:** action — *you* call `do_action()`; the plugin's `Caller_Resolver` listens.
- **Listener registered in:** `src/Attribution/class-caller-resolver.php`
  (`Caller_Resolver::register()` → `add_action( self::ATTRIBUTE_ACTION, [...], 10, 1 )`,
  where `ATTRIBUTE_ACTION = 'wp_aiut_attribute'`).
- **Signature:**

  ```php
  do_action( 'wp_aiut_attribute', string $slug );
  ```

  | Param | Type | Description |
  | --- | --- | --- |
  | `$slug` | `string` | Your plugin/theme slug. Run through `sanitize_key()` internally; empty values are ignored. |

**Mechanics.** The listener pushes `$slug` onto a request‑scoped stack
(`Caller_Resolver::push_slug()`). When the `wp_ai_client_prevent_prompt` filter fires for
the next prompt, the Gatekeeper reads the **top** of that stack
(`Caller_Resolver::current_slug()`) and attributes the prompt to it with
`confidence = 'high'`. The stack is request‑global static state shared across instances.

> **Note on the stack.** `current_slug()` *reads* the top without popping. The slug you
> push therefore stays "active" for subsequent prompts in the same request until you push
> a different one. There is a `pop_slug()` method, but nothing calls it automatically — if
> your plugin makes one AI call and then yields control, push your slug right before the
> call. Pushing again before the next call keeps attribution correct.

**Example — minimal good citizen:**

```php
// Right before your AI Client call, self-identify.
do_action( 'wp_aiut_attribute', 'my-cool-plugin' );

$text = wp_ai_client_prompt( 'Summarise this post in one sentence.' )
    ->generate_text();
```

**Example — a small wrapper you can reuse:**

```php
/**
 * Run an AI prompt attributed to this plugin.
 */
function mycoolplugin_ai_text( string $prompt ) {
    if ( function_exists( 'wp_ai_client_prompt' ) ) {
        do_action( 'wp_aiut_attribute', 'my-cool-plugin' );
        return wp_ai_client_prompt( $prompt )->generate_text();
    }
    return new WP_Error( 'ai_unavailable', 'AI Client not available.' );
}
```

The call is harmless when AI Usage Tracker is **not** installed — `do_action()` on a hook
nobody listens to is a no‑op. There is no hard dependency in either direction.

---

### `wp_aiut_usage_recorded`

Fires once after a usage event row has been written to `{prefix}aiut_events` **and** the
per‑scope counters have been incremented in `{prefix}aiut_counters`. This is the canonical
"a request was just accounted for" signal — the built‑in `Threshold_Watcher` uses it to
detect 80%/100% limit crossings.

- **Type:** action.
- **Fired in:** `src/Accounting/class-usage-recorder.php`, inside
  `Usage_Recorder::record()`, after the events insert and the counter fan‑out. **Only
  fires when the `Counter_Store` and `Window` classes are present** (it is inside that
  guard).
- **Signature:**

  ```php
  do_action( 'wp_aiut_usage_recorded', array $data, array $scopes );
  ```

  | Param | Type | Description |
  | --- | --- | --- |
  | `$data` | `array<string,mixed>` | The sanitised event row. Keys: `plugin_slug`, `plugin_confidence` (`high`/`medium`/`low`), `user_id` (int), `user_role`, `provider`, `model`, `input_tokens`, `output_tokens`, `thinking_tokens`, `estimated` (`0`/`1`). |
  | `$scopes` | `array<int,array{0:string,1:string}>` | The scope tuples that were incremented: `['plugin', $slug]`, `['user', (string) $user_id]`, `['role', $role]`, `['model', "provider/model"]`, `['global', '__all__']`. |

**Example — mirror events to an external log:**

```php
add_action(
    'wp_aiut_usage_recorded',
    function ( array $data, array $scopes ) {
        error_log( sprintf(
            'AI usage: %s spent %d in + %d out tokens (estimated=%d)',
            $data['plugin_slug'],
            $data['input_tokens'],
            $data['output_tokens'],
            $data['estimated']
        ) );
    },
    10,
    2
);
```

---

### `wp_aiut_blocked`

Fires the moment the Enforcer decides a prompt must be blocked because an enabled, breached,
confidence‑satisfied **hard** limit was found — *immediately before* the Gatekeeper returns
`true` from `wp_ai_client_prevent_prompt`. Use it to log the block or to surface a friendlier
message than core's generic "prevented by a filter" error.

- **Type:** action.
- **Fired in:** `src/Enforcement/class-enforcer.php`, inside `Enforcer::should_block()`,
  after `Limit_Evaluator::first_hard_breach()` returns a non‑null breach and before
  `return true`.
- **Signature:**

  ```php
  do_action( 'wp_aiut_blocked', array $breach, array $scopes, string $confidence );
  ```

  | Param | Type | Description |
  | --- | --- | --- |
  | `$breach` | `array<string,mixed>` | The breached limit row, including a `current` usage value. Carries the limit's `scope_type`, `scope_key`, `limit_type` (`requests`/`tokens`/`cost`), `period_kind`, `threshold`, `enforcement`, `min_confidence`. |
  | `$scopes` | `array<string,string>` | The request's scope set: `plugin`, `user`, `role`, `global` (no `model` — the model is unknown pre‑request). |
  | `$confidence` | `string` | Attribution confidence for this request: `high`, `medium`, or `low`. |

> **Fails open.** `should_block()` is wrapped in a `try/catch` that returns `false`
> (allow) on any `Throwable`. If your callback throws, you do **not** unblock the prompt
> — the breach decision has already been made — but keep callbacks cheap and defensive
> anyway; this fires on the request hot path.

**Example — log blocks for an audit trail:**

```php
add_action(
    'wp_aiut_blocked',
    function ( array $breach, array $scopes, string $confidence ) {
        error_log( sprintf(
            'AI prompt blocked: %s limit on %s=%s (%s, confidence=%s)',
            $breach['limit_type'],
            $breach['scope_type'],
            $breach['scope_key'],
            $breach['period_kind'],
            $confidence
        ) );
    },
    10,
    3
);
```

---

### `wp_aiut_notify`

Fires when a usage limit crosses an **alert** threshold (80% or 100% of its `threshold`
this period). It runs **regardless of whether the built‑in admin email is sent** and
*before* the email — so it is the clean place to route an alert to Slack, a webhook, PagerDuty,
etc.

- **Type:** action.
- **Fired in:** `src/Alerts/class-notifier.php`, at the top of `Notifier::notify()`,
  before the recipient is resolved and `wp_mail()` is called.
- **Dedup:** the upstream `Threshold_Watcher` only calls `notify()` once per
  `(limit_id, scope_key, period_key, percent)` (transient‑based — the dedup key is an
  md5 of those four parts), so this action does not fire repeatedly within a period. The
  `scope_key` is part of the key so each concrete key of a wildcard (`*`) limit dedups
  independently.
- **Signature:**

  ```php
  do_action( 'wp_aiut_notify', array $limit, int $current, int $percent );
  ```

  | Param | Type | Description |
  | --- | --- | --- |
  | `$limit` | `array<string,mixed>` | The limit row that crossed. Its `scope_key` has been annotated with the **concrete** scope key (a wildcard `*` limit reports the actual key that crossed). Includes `limit_type`, `threshold`, `period_kind`, `enforcement`. For `cost`, `threshold`/values are integer **micros** (1e‑6 USD). |
  | `$current` | `int` | Current usage in the limit's unit (requests/tokens count, or cost micros). |
  | `$percent` | `int` | Threshold crossed: `80` or `100`. |

**Example — post to Slack:**

```php
add_action(
    'wp_aiut_notify',
    function ( array $limit, int $current, int $percent ) {
        $value = ( 'cost' === $limit['limit_type'] )
            ? '$' . number_format( $current / 1000000, 2 )
            : (string) $current;

        wp_remote_post( 'https://hooks.slack.com/services/XXX', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'text' => sprintf(
                    ':warning: AI usage at %d%% for %s %s — %s of %d (%s).',
                    $percent,
                    $limit['scope_type'],
                    $limit['scope_key'],
                    $value,
                    (int) $limit['threshold'],
                    $limit['period_kind']
                ),
            ] ),
        ] );
    },
    10,
    3
);
```

---

### `wp_aiut_pricing`

Filters the active pricing table (per‑1,000,000‑token prices in **USD**, keyed by
`"provider/model"`) just before it is used for cost estimation. This is the last word on
pricing — applied *after* the shipped defaults are merged with the admin‑editable
`aiut_pricing` option.

- **Type:** filter.
- **Applied in:** `src/Accounting/class-cost-calculator.php`, inside
  `Cost_Calculator::get_pricing()` (`self::PRICING_FILTER = 'wp_aiut_pricing'`).
- **Signature:**

  ```php
  $pricing = apply_filters( 'wp_aiut_pricing', array $pricing );
  ```

  | Param | Type | Description |
  | --- | --- | --- |
  | `$pricing` | `array<string, array{input:float, output:float, thinking?:float}>` | Pricing table keyed by `"provider/model"`. Prices are **USD per 1e6 tokens**. `thinking` is optional and defaults to the `output` price when absent. |

  **Return:** the (possibly modified) table. If a callback returns a non‑array or empty
  value, `get_pricing()` falls back to the shipped defaults.

**Lookup behaviour you should know** (`Cost_Calculator::rates_for()`): exact `provider/model`
match → **longest‑prefix** match within the provider (so `claude-opus-4-8` resolves to a
`claude-opus-4` entry) → `provider/__default__` → `__default__/__default__`. Use base‑family
keys and minor versions resolve automatically.

**Example — set your negotiated enterprise rate:**

```php
add_filter( 'wp_aiut_pricing', function ( array $pricing ) {
    // USD per 1,000,000 tokens.
    $pricing['anthropic/claude-opus-4'] = [
        'input'    => 12.0,
        'output'   => 60.0,
        'thinking' => 60.0,
    ];
    return $pricing;
} );
```

---

### `wp_aiut_alert_email`

Filters the recipient address for the built‑in threshold alert email. Return `''` (or any
non‑email value) to suppress the email entirely while still letting
[`wp_aiut_notify`](#wp_aiut_notify) fire for custom channels.

- **Type:** filter.
- **Applied in:** `src/Alerts/class-notifier.php`, inside `Notifier::recipient()`.
- **Signature:**

  ```php
  $email = apply_filters( 'wp_aiut_alert_email', string $email );
  ```

  | Param | Type | Description |
  | --- | --- | --- |
  | `$email` | `string` | Default is the site `admin_email` option. The returned value is validated with `is_email()`; an invalid address means **no email is sent**. |

**Example — route alerts to a billing distro:**

```php
add_filter( 'wp_aiut_alert_email', function () {
    return 'ai-billing@example.com';
} );
```

**Example — disable the email, rely on Slack only:**

```php
add_filter( 'wp_aiut_alert_email', '__return_empty_string' );
```

---

### `wp_aiut_capability`

Filters the capability required to access the dashboard's REST API (and therefore the
Tools → AI Usage screen's data). Defaults to `manage_options`.

- **Type:** filter.
- **Applied in:** `src/Admin/class-rest-controller.php`, inside
  `Rest_Controller::check_permission()` (the `permission_callback` on **every** route;
  `CAPABILITY_FILTER = 'wp_aiut_capability'`).
- **Signature:**

  ```php
  $capability = apply_filters( 'wp_aiut_capability', string $capability );
  ```

  | Param | Type | Description |
  | --- | --- | --- |
  | `$capability` | `string` | Default `'manage_options'`. The result is passed to `current_user_can()`. |

**Example — let a custom "AI manager" role view usage:**

```php
add_filter( 'wp_aiut_capability', function () {
    return 'view_ai_usage'; // a custom capability you grant.
} );
```

> This gates **all** routes uniformly, including the pricing/limit **writes**. Choose a
> capability you are comfortable granting write access with.

---

### `wp_aiut_chars_per_token`

Filters the characters‑per‑token divisor used by the **estimate fallback** (Path C), which
runs on `shutdown` for any pending intent that never received real tokens. Real captures via
the core result event are unaffected — this only touches estimated rows (`estimated = 1`).

- **Type:** filter.
- **Applied in:** `src/Capture/class-result-capturer.php`, inside
  `Result_Capturer::estimate_usage()`.
- **Signature:**

  ```php
  $divisor = apply_filters( 'wp_aiut_chars_per_token', int $divisor );
  ```

  | Param | Type | Description |
  | --- | --- | --- |
  | `$divisor` | `int` | Default `4` (`Result_Capturer::CHARS_PER_TOKEN`). Estimated input tokens = `floor( prompt_chars / $divisor )`. A `$divisor <= 0` is ignored (the default is kept). |

**Example — tune for a non‑English corpus:**

```php
add_filter( 'wp_aiut_chars_per_token', function () {
    return 3; // denser tokenisation -> more tokens per char.
} );
```

---

### `wp_aiut_match_max_age`

Filters the **correlation window** (in seconds) used when matching a completed request's
token usage back to a pending pre‑request intent. Because the result event carries no
builder identity, matching is by recency (`Gatekeeper::match_pending()`); an intent older
than this window is ignored — an abandoned or errored call that never produced a result ages
out and is swept by the estimate pass instead of stealing a later, unrelated result.

- **Type:** filter.
- **Applied in:** `src/Capture/class-gatekeeper.php`, inside `Gatekeeper::match_max_age()`
  (called from `match_pending()`).
- **Signature:**

  ```php
  $seconds = apply_filters( 'wp_aiut_match_max_age', float $seconds );
  ```

  | Param | Type | Description |
  | --- | --- | --- |
  | `$seconds` | `float` | Default `300.0` (`Gatekeeper::MATCH_MAX_AGE_SECONDS`). A value `<= 0` is ignored (the default is kept). |

**Example — widen for an unusually slow provider:**

```php
add_filter( 'wp_aiut_match_max_age', function () {
    return 600.0; // allow up to 10 minutes between intent and result.
} );
```

---

### `wp_aiut_sdk_client`

Filters the SDK **client/registry** object the plugin tries to decorate for Path B
(transporter‑based capture). Auto‑discovery probes a few known static accessors; this filter
lets you hand the plugin the exact object that owns the HTTP transporter when discovery
fails. Path B is a **best‑effort fallback** — the primary capture path is the core result
event, so most sites never need this.

- **Type:** filter.
- **Applied in:** `src/Capture/class-result-capturer.php`, inside
  `Result_Capturer::locate_sdk_client()` (called from `install_transporter_decorator()` on
  `init`, priority 5).
- **Signature:**

  ```php
  $client = apply_filters( 'wp_aiut_sdk_client', object|null $client );
  ```

  | Param | Type | Description |
  | --- | --- | --- |
  | `$client` | `object\|null` | Default `null`. The returned object is only used if it `is_object()` **and** `method_exists( $client, 'setHttpTransporter' )`. |

**Example — supply your SDK singleton:**

```php
add_filter( 'wp_aiut_sdk_client', function ( $client ) {
    if ( class_exists( '\\My\\Sdk\\Client' ) ) {
        return \My\Sdk\Client::instance(); // must expose setHttpTransporter().
    }
    return $client;
} );
```

---

### `wp_aiut_default_transporter`

Filters the SDK **default HTTP transporter** that the `Chaining_Transporter` wraps when no
existing transporter is set (the AI‑plugin‑absent Path B scenario). Auto‑discovery tries to
construct a known transporter class with no required constructor args; this filter overrides
that.

- **Type:** filter.
- **Applied in:** `src/Capture/class-chaining-transporter.php`, inside
  `Chaining_Transporter::resolve_default_transporter()`.
- **Signature:**

  ```php
  $transporter = apply_filters( 'wp_aiut_default_transporter', object|null $transporter );
  ```

  | Param | Type | Description |
  | --- | --- | --- |
  | `$transporter` | `object\|null` | Default `null`. If an object is returned it is wrapped (and chained); the decorator forwards to it via the first of `send` / `request` / `transport` / `__invoke` that exists. |

> **Always chains, never replaces.** The decorator forwards untouched arguments to the
> inner transporter and returns its response unmodified — it only *peeks* at the response
> for token usage. If you supply a transporter here, it becomes the terminal real transport
> of the chain.

**Example:**

```php
add_filter( 'wp_aiut_default_transporter', function ( $transporter ) {
    return new \My\Sdk\Http\Transporter(); // must expose send()/request()/transport()/__invoke().
} );
```

---

## Core / SDK hooks consumed

These are **not** plugin‑provided — they belong to WordPress 7.0 core (the AI Client) or
the optional `wordpress/ai` logging plugin. They are documented here so the next agent knows
exactly what the plugin attaches to and why. There is **no hard dependency** on the
logging plugin; the core result action is the reliable path.

### `wp_ai_client_prevent_prompt`

The pre‑request gate. Core fires this filter **before** every AI Client request. Returning
`true` blocks the prompt; core then makes the `generate_*()` call return a `WP_Error`.

- **Origin:** WP 7.0 AI Client (core).
- **Hooked in:** `src/Capture/class-gatekeeper.php`,
  `Gatekeeper::register()` → `add_filter( 'wp_ai_client_prevent_prompt', [ $this, 'observe_prompt' ], 10, 2 )`.
- **Verified core signature:**

  ```php
  function ( bool $prevent, WP_AI_Client_Prompt_Builder $builder ): bool
  ```

  Priority `10`, `2` args. Our callback is `Gatekeeper::observe_prompt( $prevent, $builder = null )`.

**What we do with it:**

1. **Observe (always).** Resolve attribution (caller slug + confidence, user + role),
   fingerprint the request, and record a *pending intent* in a request‑scoped registry.
   The intent is later matched to real token usage delivered by
   [`wp_ai_client_after_generate_result`](#wp_ai_client_after_generate_result). All of
   this bookkeeping is wrapped in `try/catch` and **never** changes `$prevent`.
2. **Enforce (Phase 2, conditional).** If a prior filter already set `$prevent = true`, or
   attribution couldn't be resolved, we return `$prevent` unchanged. Otherwise we ask the
   `Enforcer`. The Enforcer **short‑circuits** via a cached
   `Limit_Repository::has_enabled_hard_limits()` flag — with no hard limits configured the
   plugin behaves **exactly like observe‑only** and the filter returns `$prevent` unchanged.
   When a hard, breached, confidence‑satisfied limit exists, we return **`true`** to block.

> **Important core behaviour to rely on.** The filter is a `(bool)` contract. The
> `$builder` argument is effectively a **read‑only clone** for inspection — do not mutate
> it expecting the live request to change. When some filter returns `true`, core blocks the
> request and `generate_*()` returns `WP_Error( 'prompt_prevented', ... )` with HTTP status
> **503**. The plugin **never** returns `true` unless a configured hard limit is breached,
> and **fails open** (returns `$prevent` unchanged) on any internal error.

The builder uses `__call` magic, so `method_exists()` returns false for its fluent/generate
methods — the plugin treats `$builder` as opaque (it only serialises it for a fingerprint
hash and a rough char count), and never gates on `method_exists` for those methods.

### `wp_ai_client_after_generate_result`

**The primary, reliable capture path.** Core ships a PSR‑14 dispatcher that bridges SDK
events to WordPress actions; this action fires **after** generation with an
`AfterGenerateResultEvent`.

- **Origin:** WP 7.0 AI Client (core).
- **Hooked in:** `src/Capture/class-result-capturer.php`,
  `Result_Capturer::register()` → `add_action( 'wp_ai_client_after_generate_result', [ $this, 'capture_from_core_event' ], 10, 1 )`
  (`CORE_RESULT_ACTION` constant).
- **Callback signature:** `capture_from_core_event( object $event )` (priority 10, 1 arg).

**What we read** (all behind `method_exists` guards so an SDK shape change degrades
gracefully):

- `$event->getResult()` → `GenerativeAiResult`
  (`WordPress\AiClient\Results\DTO\GenerativeAiResult`).
- `$result->getTokenUsage()` → a `TokenUsage` DTO with getter methods:
  `getPromptTokens()` (input), `getCompletionTokens()` (output), `getThoughtTokens()`
  (thinking). *(These are method calls, not array keys.)*
- `$result->getProviderMetadata()->getId()` → provider slug (e.g. `anthropic`).
- `$result->getModelMetadata()->getId()` → model slug (e.g. `claude-opus-4-8`).

The captured usage is matched to the most recent un‑finalised pending intent
(`Gatekeeper::match_pending( null )`) and finalised through `Usage_Recorder::record()` with
`estimated = 0`. This path yields **real** tokens + provider/model. The transporter
decorator (Path B) and the `shutdown` chars/4 estimate (Path C) are fallbacks only.

> Don't regress to probing array shapes for token usage — the data lives behind typed DTO
> getters. The historical transporter‑guessing design produced `output_tokens = 0`, no
> provider/model, and `estimated = 1`; the core event fixed all three.

### `wpai_request_log_context`

An **optional** capture fallback (Path A) from the `wordpress/ai` logging plugin. It is
experimental and usually absent; the plugin hooks it cheaply regardless (it simply never
fires when the logging plugin isn't installed) and **never** modifies it.

- **Origin:** `wordpress/ai` logging plugin (optional, may change).
- **Hooked in:** `src/Capture/class-result-capturer.php`,
  `add_filter( 'wpai_request_log_context', [ $this, 'capture_from_ai_log' ], 10, 3 )`
  (`AI_LOG_FILTER` constant).
- **Callback signature:** `capture_from_ai_log( $context, $decoded = null, $log_data = null )`
  — priority 10, 3 args, mandated by the AI plugin.

**Read‑only.** The callback probes `$decoded`/`$log_data` for a recognisable token‑usage
shape and, on a match, finalises the most recent matching pending intent. It then **returns
`$context` unchanged** — the plugin is a passive observer of this filter and must never
alter the log context.

---

## REST API

- **Namespace:** `wp-aiut/v1` (`Rest_Controller::NAMESPACE`).
- **Registered in:** `src/Admin/class-rest-controller.php` on `rest_api_init`.
- **Permission:** **every** route uses `Rest_Controller::check_permission()`, which checks
  `current_user_can( apply_filters( 'wp_aiut_capability', 'manage_options' ) )`.
  See [`wp_aiut_capability`](#wp_aiut_capability).
- All reads delegate to `Data\Usage_Repository`; pricing to `Accounting\Cost_Calculator`;
  limits to `Limits\Limit_Repository`. Responses go through `rest_ensure_response()`.

### Routes

| Method | Route | Callback | Purpose |
| --- | --- | --- | --- |
| `GET` | `/usage` | `get_usage` | Ranked counters for a scope type + period. |
| `GET` | `/timeseries` | `get_timeseries` | Daily buckets for the over‑time chart. |
| `GET` | `/totals` | `get_totals` | Top‑line totals + provider/model breakdown. |
| `GET` | `/pricing` | `get_pricing` | Current pricing table. |
| `PUT`/`PATCH` | `/pricing` | `update_pricing` | Persist an admin pricing table. |
| `GET` | `/scopes` | `get_scopes` | Discovered plugin slugs, models, and roles (for dropdowns). |
| `GET` | `/limits` | `get_limits` | List all configured limits (Phase 2). |
| `POST` | `/limits` | `create_limit` | Create a limit (Phase 2). |
| `PUT`/`PATCH` | `/limits/{id}` | `update_limit` | Update a limit (Phase 2). |
| `DELETE` | `/limits/{id}` | `delete_limit` | Delete a limit (Phase 2). |

> `WP_REST_Server::EDITABLE` maps to `PUT, PATCH, POST` in WP; the table lists the primary
> verb the dashboard uses. `CREATABLE` = `POST`, `DELETABLE` = `DELETE`, `READABLE` = `GET`.

### `GET /usage`

Ranked per‑scope counters for one scope type and period.

| Arg | Type | Default | Constraints |
| --- | --- | --- | --- |
| `scope_type` | string | `plugin` | enum: `plugin`, `user`, `role`, `model`, `global`; `sanitize_key` |
| `period` | string | `month` | enum: `day`, `month`; `sanitize_key` |
| `period_key` | string | *(current period)* | optional; must match `^\d{4}-\d{2}(-\d{2})?$`; key is validated against `period` kind |

**Response:** `{ scope_type, period, period_key, rows }` where `rows` is from
`Usage_Repository::ranked_by_scope()`. Each row carries `scope_type`, `scope_key`,
`requests`, `input_tokens`, `output_tokens`, `thinking_tokens`, `est_cost_micros`.

**Row enrichment (display helpers).** For human-friendly, linked labels the controller
enriches rows server-side (only the viewer-permitted fields are added):

- `scope_type = 'user'` → `enrich_user_rows()` adds, for real users (id ≥ 1):
  - `display_name`, `user_login` (e.g. `filip`), and
  - `edit_url` — the profile edit link, **only if** the current user `can( 'edit_user', id )`.
  - The reserved `__system__` / user-`0` bucket is left unenriched (UI shows a generic label).
- `scope_type = 'role'` → `enrich_role_rows()` adds, for real roles:
  - `role_label` — the translated human role name (e.g. `Administrator`), and
  - `list_url` — `users.php?role=<role>`, **only if** the current user `can( 'list_users' )`.
  - The reserved `system` bucket is left unenriched.

The dashboard's `ScopeName` component renders `User #1 (filip)` / the linked role name when
these fields are present, and falls back to the plain label otherwise. The counters table
itself stores only the numeric id / raw role slug — enrichment is purely a presentation-layer
concern resolved at request time.

### `GET /timeseries`

Daily buckets for the usage‑over‑time chart. Window defaults to the current calendar month
when `from`/`to` are omitted.

| Arg | Type | Default | Constraints |
| --- | --- | --- | --- |
| `metric` | string | `cost` | enum: `cost`, `tokens`; `sanitize_key` |
| `scope_type` | string | *(none)* | optional; enum: `plugin`, `user`, `role`; `sanitize_key` |
| `scope_key` | string | *(none)* | optional; `sanitize_text_field` |
| `from` | string | *(month start)* | optional; `^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$` |
| `to` | string | *(month end)* | optional; same date pattern (treated as an exclusive upper bound — a bare date expands to start of the next day) |

**Response:** `{ metric, from, to, series }` from `Usage_Repository::timeseries()`.

### `GET /totals`

Top‑line totals plus provider/model breakdown for a period.

| Arg | Type | Default | Constraints |
| --- | --- | --- | --- |
| `period` | string | `month` | enum: `day`, `month` |
| `period_key` | string | *(current period)* | optional; `^\d{4}-\d{2}(-\d{2})?$`, validated vs `period` |

**Response:** `{ totals, by_provider, by_model, period, period_key }` from
`Usage_Repository::totals()`.

### `GET /pricing`

**Response:** `{ pricing }` — the active table from `Cost_Calculator::get_pricing()`
(defaults + `aiut_pricing` option + [`wp_aiut_pricing`](#wp_aiut_pricing)
filter). Prices are USD per 1e6 tokens.

### `PUT /pricing`

| Arg | Type | Required | Notes |
| --- | --- | --- | --- |
| `pricing` | object | yes | Table keyed by `"provider/model"`; each row `{ input, output, thinking? }` in USD/1e6 tokens. |

Sanitised to non‑negative floats and stored in the `aiut_pricing` option (autoload `false`).
Non‑array body → `WP_Error( 'aiut_invalid_pricing', 400 )`. Calculator class missing →
`WP_Error( 'aiut_unavailable', 500 )`. **Response:** `{ pricing }` (re‑read after save).

### `GET /scopes`

Filter‑dropdown source data. **No args.**

**Response:** `{ plugins, roles, models }` — `plugins`/`models` are `DISTINCT scope_key`
values read from `{prefix}aiut_counters`; `roles` comes from the live role list
(`wp_roles()->get_names()`), so roles appear even before any usage exists.

### `GET /limits`

List all configured limits. **No args.** **Response:** `{ limits }` from
`Limit_Repository::all()`.

### `POST /limits` and `PUT /limits/{id}`

Create or update a limit. Both use the shared `limit_args()` schema:

| Arg | Type | Required / Default | Constraints |
| --- | --- | --- | --- |
| `scope_type` | string | **required** | enum: `plugin`, `user`, `role`, `model`, `global`; `sanitize_key` |
| `scope_key` | string | default `*` | `sanitize_text_field` (`*` = wildcard: all keys of that type) |
| `limit_type` | string | **required** | enum: `requests`, `tokens`, `cost`; `sanitize_key` |
| `period_kind` | string | default `month` | enum: `day`, `month`; `sanitize_key` |
| `threshold` | integer | **required** | `minimum: 0`. For `cost`, this is **micros** (1e‑6 USD). |
| `enforcement` | string | default `soft` | enum: `off`, `soft`, `hard`; `sanitize_key`. Only `hard` blocks. |
| `min_confidence` | string | default `medium` | enum: `high`, `medium`. `high` restricts enforcement to self‑identified callers; `__unknown__` is never singled out. |
| `alert_80` | boolean | default `true` | Fire the 80% alert. |
| `alert_100` | boolean | default `true` | Fire the 100% alert. |
| `enabled` | boolean | default `true` | Whether the limit is active. |

- `{id}` path segment is `(?P<id>\d+)`.
- **POST** → `{ limit }` of the created row, or `WP_Error( 'aiut_limit_save_failed', 500 )`.
- **PUT** on a missing id → `WP_Error( 'aiut_limit_not_found', 404 )`; save failure →
  `WP_Error( 'aiut_limit_save_failed', 500 )`; success → `{ limit }`.

### `DELETE /limits/{id}`

Delete a limit. **Response:** `{ deleted: bool }` from `Limit_Repository::delete()`.

---

## Constants worth knowing (hook names live as class constants)

| Constant | Value | Class |
| --- | --- | --- |
| `Caller_Resolver::ATTRIBUTE_ACTION` | `wp_aiut_attribute` | `Attribution\Caller_Resolver` |
| `Result_Capturer::CORE_RESULT_ACTION` | `wp_ai_client_after_generate_result` | `Capture\Result_Capturer` |
| `Result_Capturer::AI_LOG_FILTER` | `wpai_request_log_context` | `Capture\Result_Capturer` |
| `Result_Capturer::CHARS_PER_TOKEN` | `4` | `Capture\Result_Capturer` |
| `Cost_Calculator::PRICING_FILTER` | `wp_aiut_pricing` | `Accounting\Cost_Calculator` |
| `Cost_Calculator::PRICING_OPTION` | `aiut_pricing` | `Accounting\Cost_Calculator` |
| `Rest_Controller::NAMESPACE` | `wp-aiut/v1` | `Admin\Rest_Controller` |
| `Rest_Controller::CAPABILITY_FILTER` | `wp_aiut_capability` | `Admin\Rest_Controller` |

The literal hook names `wp_aiut_blocked`, `wp_aiut_notify`,
`wp_aiut_usage_recorded`, `wp_aiut_alert_email`,
`wp_aiut_chars_per_token`, `wp_aiut_sdk_client`, and
`wp_aiut_default_transporter` are passed inline (not class constants) at their
fire sites listed above.
