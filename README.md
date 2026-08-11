# QHTA Healthcheck

Read-only self-monitoring for the QHTA custom plugins on qhta.com.au.

It discovers every QHTA plugin on the site, runs a set of **canaries** against
the external dependencies each one relies on — WooCommerce and PMPro functions,
hooks, database tables and columns, meta keys, checkout DOM selectors, cron
events, required add-ons, API keys — and reports **red / amber / green** to the
WordPress dashboard.

It changes nothing it watches.

---

## Why

Every QHTA plugin is a thin layer over somebody else's system:

- `qhta-commerce` gates pages on `wc_customer_bought_product()`.
- `qhta-membership` unsets a field PMPro would otherwise insist on, and moves
  two inputs around a form it does not own.
- `qhta-woo-invoice` hangs a PDF off `woocommerce_email_attachments`.
- `qhta-revenue` reads `wp_pmpro_membership_orders` by column name.
- `qhta-mailchimp-archive` depends on WP-Cron being the only thing that ever
  refreshes its cache.

None of those are contracts. They are other people's internals, and when one
moves the failure is almost always **silent** — because every one of those calls
is wrapped in a `function_exists()` guard that turns "this broke" into "this
politely did nothing".

Those guards are right. A fatal on the checkout or order-email path is worse
than a missing feature. But they mean nobody finds out. This plugin is the other
half of that bargain: it is the thing that notices, and it is the reason the
guards can stay quiet.

## What it is not

It cannot tell you the site is **down**. A dead WordPress cannot email you.
Site-up liveness is covered separately by the external scheduled **QHTA site
guardian** task (a weekly HTTP probe of login / my-account redirect /
recordings / home). Keep both — they answer different questions.

---

## Installing

Copy the plugin folder to `wp-content/plugins/qhta-healthcheck/` and activate
**QHTA Healthcheck**. Activation schedules a daily full run; deactivation
unschedules it and drops the cached results.

No settings. Administrators (`manage_options`) see:

- **Dashboard → QHTA Health** widget — the headline plus the worst five
  findings.
- **Tools → QHTA Health** — the full board, with a **Run checks now** button.

---

## How it decides what to watch

Three sources, in this order:

1. **Auto-discovery.** Anything installed whose *slug or plugin name* starts
   with `qhta`. This is why a new QHTA plugin can never be silently missing from
   the board — it appears the moment it is installed.
2. **The expected fleet** (`qhta_healthcheck_expected_plugins()` in
   `includes/checks.php`) — a deliberately dumb list of names, whether or not
   each is installed. Being on it is a statement that the plugin is *expected*
   on this site, so its absence is a finding rather than a non-event.
3. **The `qhta_healthcheck_watch_plugins` filter**, for a plugin that is neither
   `qhta`-prefixed nor in the expected fleet.

The fleet list is deliberately *not* derived from "which plugins have canaries".
Canaries self-register from the plugins themselves, so an uninstalled plugin
registers nothing — a derived fleet would quietly shrink to whatever happened to
be installed, and a deleted plugin's row would vanish rather than turn red. That
is the worst failure a monitor can have: reporting all-clear precisely *because*
something is missing.

Two plugins do not live where their name says they do, and the registry names
them canonically with an alias to where they actually install:

| Canonical slug | Installs as |
|---|---|
| `qhta-conference-plugin` | whatever folder it was uploaded into — it is the HTAA national conference's program, not a QHTA one, so its folder is not `qhta`-prefixed |
| `qhta-pmpro-invoice-extensions` | a single-file plugin whose folder has varied |

Resolution tries three things in order: the canonical slug as a folder, then the
alternatives in `qhta_healthcheck_slug_aliases`, then the plugin's **header name**
via `qhta_healthcheck_plugin_names`.

The third exists because the first two are guesses about a folder name that is
chosen at upload time and can be anything — and in production both missed, with
the conference program reported "expected on this site, but not installed" while
visible in the Plugins list. A header name is a property of the code, travels
with the plugin, and is what a human reads on the Plugins screen.

Renaming an installed plugin's *folder* so the monitor can find it would get the
dependency exactly backwards — and would deactivate it. Renaming its *display
name* is safe and costs nothing: WordPress identifies a plugin by file path.

### Auto-discovery cannot invent a canary

Discovery covers a plugin's **presence**. It does not know that `qhta-commerce`
cares about `wc_customer_bought_product()`. A watched plugin with no canaries is
therefore reported **amber**, not green:

> No canaries defined — this plugin has not been deployed with its own yet.

Showing it green would be a lie of omission. **That amber is the standing rule
made visible** — and during the rollout it doubles as the tracker: every plugin
still showing it is one that has not been redeployed yet.

---

## Adding a canary

Every canary lives in the plugin that owns the dependency. `includes/checks.php`
holds none, and that is the intended end state rather than an oversight — a
central copy puts the reminder in a different repository from the change.

### Self-registration, in the plugin that owns the dependency

```php
add_filter( 'qhta_healthcheck_checks', function ( $checks ) {
    $checks['qhta-commerce'][] = array(
        'id'       => 'woo-entitlement-api',
        'label'    => 'WooCommerce entitlement API',
        'why'      => 'wc_customer_bought_product() is the whole gate. It is called behind a function_exists() guard, so if it disappears every gated page silently redirects paying customers to /login/.',
        'severity' => 'critical',   // or 'warning' (the default)
        'remote'   => false,        // true = makes an HTTP request; daily only
        'test'     => function () {
            return qhta_healthcheck_assert_functions( 'wc_customer_bought_product' );
        },
    );

    return $checks;
} );
```

### A plugin with no canaries yet

It has none until it is next deployed, and reports amber in the meantime. Nothing
here fills that gap on its behalf: a stand-in copy would either duplicate the
real one or, worse, outlive it and go on asserting something the plugin no longer
does. An honest amber beats a false green, and it closes the first time the
plugin ships.

That is what makes the rollout incremental — no coordinated release, no flag day,
one plugin at a time.

The one claim that stays central is the expected-fleet list, because a plugin
that is not installed cannot tell you it should have been.

### The `why` field is not documentation politeness

This screen is read once every few months, usually in a hurry. Write what
actually breaks *for a member or a buyer* when the check fails. A check whose
consequence has to be reconstructed from the code is a check that gets ignored.
It is only printed when the check is failing.

---

## Writing a good test

A `test` returns `qhta_healthcheck_pass( $detail )`, `qhta_healthcheck_fail( $detail )`,
`qhta_healthcheck_skip( $detail )`, a bare `bool`, or a `WP_Error`.

**Prove the effect, not the intention, wherever you can.** "Is the
`conference_session` post type registered?" is worth more than "did our `init`
callback get added?" — the first fails when ACF is deactivated, when the plugin
is half-deployed, *and* when somebody unhooks us. The second only fails for the
last of those.

`qhta_healthcheck_assert_hooked()` is the weaker fallback, for the cases where
the effect cannot be observed from wp-admin — you cannot ask "did the invoice
attach itself to that email" without sending an email.

### Available assertions

| Assertion | Answers |
|---|---|
| `assert_functions( $names )` | are these callable? |
| `assert_classes( $names )` | are these loadable? |
| `assert_constant( $name )` | defined *and* non-empty (never prints the value) |
| `assert_table( $table, $columns )` | table exists, with the columns we read by name |
| `assert_hooked( $hook, $callback = null )` | is our callback still attached (or anyone)? |
| `assert_shortcode( $tag )` | registered? |
| `assert_shortcode_in_use( $tag )` | actually placed on a published page? |
| `assert_meta_in_use( $key, $type )` | is any row still carrying this meta key? |
| `assert_order_meta_in_use( $key, $status )` | same, but HPOS-safe via `wc_get_orders()` |
| `assert_cron( $hook, $overdue )` | scheduled, and not overdue (i.e. WP-Cron alive)? |
| `assert_cron_schedule( $name )` | is the custom interval registered? |
| `assert_option( $name )` | present and non-empty? |
| `assert_file( $path )` | present and readable? |
| `assert_page_at( $path )` | is a page published at this hardcoded path? |
| `assert_wc_page( $key )` | is the WooCommerce shop/cart/checkout/account page set? |
| `assert_account_endpoint( $endpoint )` | endpoint routed *and* rewrites flushed? |
| `assert_theme( $slug )` | is the theme we style against active? |
| `assert_markup_contains( $url, $needles )` | **remote** — is the markup still shaped this way? |
| `assert_api_reachable( $url, $headers, $label )` | **remote** — does the credential still work? |

### Severity

`critical` → a failure is **red**. `warning` (the default) → **amber**. The
default is deliberate: a canary contributed without thinking about consequence
should not be able to turn the whole site red. Making somebody type `critical`
is the point at which they decide whether it really is.

### Remote checks

Set `'remote' => true` for anything that opens an HTTP connection. Remote checks
run only on the daily pass and on **Run checks now** — never on a screen load.
On the board they carry their last answer forward, tagged *from the last full
run*, rather than dropping to "unknown": a day-old answer about a remote service
is worth considerably more than no answer, as long as the screen says how old it
is.

---

## Statuses

| Status | Means |
|---|---|
| **red** | a `critical` check failed |
| **amber** | a `warning` check failed, the plugin is inactive, or it has no canaries yet |
| **green** | passed |
| **skipped** | could not meaningfully run — the plugin is inactive, or the optional system it tests is not installed. Never colours the roll-up |
| **unknown** | a remote check that has not run yet. Reads as "no answer", not "no problem" |

`skipped` matters more than it looks. Without it, deactivating one plugin would
light up every screen in red, and the dashboard would be ignored within a week.

---

## What it writes

Nothing it watches. No order, member, post, meta row or other plugin's option is
created or modified by any code path here, and every remote check is a `GET`.

Two exceptions, both bookkeeping about the checks themselves rather than data:

- `qhta_healthcheck_results` — the cached last full run (`autoload=no`).
- the `qhta_healthcheck_run` daily cron event.

Deleting both loses nothing but the last run's timestamp.

Only a **full** run (remote included) is stored. The board re-runs the local
checks live on every load and does not persist them — persisting a local-only
run would overwrite the remote answers with "unknown" the first time anybody
opened the screen, and the dashboard widget would go grey until the next nightly
pass.

## Failing soft

Every canary runs inside a `try`/`catch`. A check that throws reports *itself*
as the broken thing and the rest carry on. A monitoring plugin that can
white-screen wp-admin is worse than no monitoring plugin.

---

## Filters

| Filter | For |
|---|---|
| `qhta_healthcheck_checks` | register or adjust canaries (**the main extension point**) |
| `qhta_healthcheck_expected_plugins` | the fleet that ought to be installed; absence is red |
| `qhta_healthcheck_watch_plugins` | watch a plugin whose slug does not start with `qhta` |
| `qhta_healthcheck_slug_aliases` | folders a canonically-named plugin may install under |
| `qhta_healthcheck_plugin_names` | header names that identify a plugin whatever its folder |
| `qhta_healthcheck_capability` | who may see the board (default `manage_options`) |

## Scope

This plugin observes; it never repairs. It has no opinion about what the other
plugins should do — only about whether the things they depend on are still
there. Business logic belongs in the plugin that owns it.
