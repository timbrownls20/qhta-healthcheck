# qhta-healthcheck — Handover Notes

**Status:** implemented, v1.1.0. This document is the standing rule plus the
per-plugin canary specification the rule refers to.

**As of 1.1.0 every canary lives in the plugin it watches**, self-registered on
the `qhta_healthcheck_checks` filter. `qhta-healthcheck/includes/checks.php`
holds none. Each plugin also carries its own `HEALTHCHECK.md` restating the rule
and listing its canaries, so the reminder is in the repo somebody is editing.

---

## The standing rule

`qhta-healthcheck` is the read-only self-monitoring plugin that verifies the
other QHTA plugins' external dependencies still hold and reports red/amber/green
to the WordPress dashboard. It only stays useful if its **canaries** track the
plugins it watches.

> **Whenever a QHTA plugin is created, or changed in a way that adds or alters an
> external dependency — a WooCommerce/PMPro/Astra/ACF function, a hook, a DB table
> or column, an order/post meta key, a checkout DOM selector, a required add-on, a
> cron event — update its healthcheck canaries in the *same* change.**

The canary goes in **that plugin**, on the `qhta_healthcheck_checks` filter —
`includes/healthcheck.php` where the plugin has an includes directory, inline in
the single-file ones. Nothing needs editing in `qhta-healthcheck`.

There is no central copy to keep in step, and that is deliberate. A canary and
the dependency it guards should be impossible to deploy apart.

A new dependency with no canary is exactly the silent-failure risk this plugin
exists to catch. Auto-discovery covers a plugin's **presence** automatically —
anything whose slug *or plugin name* starts with `qhta` is picked up, and shown
amber as "no canaries defined" until you add some — but it **cannot invent the
canary**. That is the human step.

### Checklist when you add or change a plugin

1. Did this add or change any external dependency (function / hook / table /
   meta key / DOM selector / add-on / cron)? If yes →
2. Add or update a canary for it (self-registration preferred, central registry
   otherwise).
3. Confirm it shows on **Tools → QHTA Health** and is green.
4. Bump the plugin's version and rebuild its zip — the canary only reaches the
   site when the plugin does.
5. If the plugin's slug does **not** start with `qhta`, also add it to the
   `qhta_healthcheck_watch_plugins` filter — or, if it is one of the plugins the
   registry already names canonically, add its real slug to
   `qhta_healthcheck_slug_aliases` instead. And add it to
   `qhta_healthcheck_expected_plugins()` so its absence would be reported.

### Companion: the external guardian

`qhta-healthcheck` covers **internal correctness**. It cannot report that the
site is **down** — a dead WordPress cannot email you. Site-up **liveness** is
covered separately by the external scheduled "QHTA site guardian" task (weekly
HTTP probe of login / my-account redirect / recordings / home). Keep both.

---

## Coverage today — 8 plugins

All eight are watched. Two are **not** `qhta`-prefixed in their installed form
and are reached through the alias map rather than auto-discovery — this differs
from the earlier assumption that all eight were `qhta-`prefixed:

| Registry slug | Installed as | Discovered by |
|---|---|---|
| qhta-commerce | `qhta-commerce` | slug prefix |
| qhta-membership | `qhta-membership` | slug prefix |
| qhta-theme-extras | `qhta-theme-extras` | slug prefix |
| qhta-woo-invoice | `qhta-woo-invoice` | slug prefix |
| qhta-revenue | `qhta-revenue` | slug prefix |
| qhta-mailchimp-archive | `qhta-mailchimp-archive` | slug prefix |
| **qhta-conference-plugin** | **`htaa-conference`** | **alias** (it is the HTAA conference's program) |
| **qhta-pmpro-invoice-extensions** | single file, plugin name "QHTA Regenerate Invoice" | **alias**, and plugin-name prefix |

Both aliases are declared in `qhta_healthcheck_slug_aliases()` in
`includes/registry.php`. The alias also *collapses* the duplicate — without it
the single-file invoice plugin appears twice, once canonically with its canaries
and once under its file-derived slug with a permanent "no canaries defined"
amber.

---

## Starter canaries per plugin

All of the below currently live in the **central registry**
(`includes/checks.php`), because none of the eight plugins has adopted the
self-registration filter yet. Moving any of them into its own plugin is always
an improvement and needs no change to `qhta-healthcheck`.

Severity: **C** = critical (fails red), **W** = warning (fails amber).
**R** = remote (HTTP; daily pass only).

### qhta-commerce — 13 canaries

| Canary | Sev | Watches |
|---|---|---|
| WooCommerce entitlement API | C | `wc_customer_bought_product`, `wc_get_product`, `wc_get_order`, `wc_get_page_id`, `wc_get_page_permalink` |
| Gate field `_qhta_gate_product_id` | W | post meta still carrying the gating product ID |
| My Account → `my-content` endpoint | C | endpoint routed **and** rewrite rules flushed |
| `woocommerce_account_menu_items` | C | the My Content tab is still injected |
| `woocommerce_account_my-content_endpoint` | C | the tab body still renders (hook name is built from the slug) |
| Rewrite rules match deployed version | W | `qhta_commerce_rewrites_version` vs `QHTA_COMMERCE_VERSION` |
| `/login/` redirect target | C | the gate's redirect destination is a published page |
| WooCommerce shop page | W | `wc_get_page_id( 'shop' )` is set and published |
| Store is reachable by someone | W | `QHTA_STORE_LIVE` or `QHTA_STORE_PREVIEW_TOKEN` — with neither, nobody can see the store |
| Checkout phone-field override | W | hooked on `pre_option_…`, **and** the option row still does not exist |
| Thank-you page resources | W | `woocommerce_thankyou` |
| Cart-count fragment | W | `woocommerce_add_to_cart_fragments` |
| PMPro member-pricing banner | W | `pmpro_hasMembershipLevel`, `pmpro_url` |

The phone-field canary is the subtle one: the filter only fires *because the
option row is absent*. Saving WooCommerce's checkout settings creates the row,
the `pre_option_` filter stops being consulted, and the phone field silently
returns. The canary checks both halves.

### qhta-membership — 8 canaries

| Canary | Sev | Watches |
|---|---|---|
| PMPro functions used at checkout | C | `pmpro_hasMembershipLevel`, `pmpro_url`, `pmpro_is_checkout`, `pmpro_getLevelAtCheckout` |
| Username removed from required fields | C | `pmpro_required_user_fields` — else every signup is rejected for a field the member cannot see |
| Debug membership level hidden | C | `pmpro_levels_array` **and** `pmpro_checkout_checks` — else the public can buy a $1 debug membership |
| PMPro checkout DOM selectors | C, R | `.pmpro_form`, `#username`, `#bemail` in the real checkout HTML |
| PMPro account heading relabel | W | `gettext_paid-memberships-pro` (text-domain rename canary) |
| WooCommerce account functions | W | `wc_get_customer_available_downloads` + 4 others |
| Login and logout redirects | W | all four redirect filters still attached |
| Account nav-item flag `_qhta_account_link` | W | the header account slot still exists |

The DOM-selector canary is the only way to catch a PMPro template rewrite. CSS
targeting `.pmpro_form_field-username` and a script looking up `#bemail` are
dependencies on somebody else's markup, and no PHP introspection will tell you
the markup changed.

### qhta-theme-extras — 4 canaries

| Canary | Sev | Watches |
|---|---|---|
| Astra theme active | C | `wp_get_theme()->get_template() === 'astra'` |
| `theme-extras.css` present | C | half-deployed plugin |
| Stylesheet actually loads on the front end | C, R | `theme-extras.css` appears in the home-page HTML |
| Block-editor styles hooked | W | `enqueue_block_assets` |

The remote one earns its place: the enqueue declares `astra-theme-css` as a
dependency so the rules win without `!important`, and **WordPress silently drops
an enqueue whose dependency is not registered**. If Astra renames that handle the
entire stylesheet vanishes from the page with no error anywhere.

### qhta-conference-plugin — 7 canaries

| Canary | Sev | Watches |
|---|---|---|
| ACF field API | C | `get_field`, `acf_add_local_field_group` |
| `conference_session` post type | C | registered (reports the published count) |
| Session Details field group | C | `group_htaa_session` registered with ACF |
| Session field names | C | all 8 `session_*` fields the renderer reads by name |
| `[conference_program]` shortcode | C | registered |
| `[conference_program]` is on a page | W | actually placed on a published page |
| `conference-config.php` loads | C | returns an array with `conference`, `categories`, `locations`, `days`, `time_slots` |

Written as effect-checks throughout, because this plugin registers everything
from closures that cannot be looked up by name — and because the effect is the
better question anyway.

### qhta-woo-invoice — 10 canaries

| Canary | Sev | Watches |
|---|---|---|
| WooCommerce order API | C | `WC_Order` |
| Dompdf and Mustache loadable | C | the bundled `lib/` actually deployed |
| Invoice template readable | C | uploads override first, plugin default second (reports which is in use) |
| Invoice directory exists and is protected | C | directory + `.htaccess` + `index.php` + writable |
| `woocommerce_email_attachments` | C | the only path by which a customer gets an invoice unprompted |
| Generate on order completion | C | `woocommerce_order_status_completed` priority 5 |
| Invoice download handler | C | `admin_post_` and `admin_post_nopriv_` |
| HPOS compatibility declared | W | `Automattic\WooCommerce\Utilities\FeaturesUtil` |
| Recent orders carry an invoice | W | `_qhta_woo_invoice_file` on recent completed orders, read HPOS-safely |
| Invoice buttons on order lists | W | My Account + admin order actions |

"Recent orders carry an invoice" is the end-to-end canary — it proves invoices
are actually being produced, not merely that every part looks present. It reads
through `wc_get_orders()` rather than `wp_postmeta` so it is right under HPOS,
where the orders are not in `wp_posts` at all.

### qhta-pmpro-invoice-extensions — 6 canaries

| Canary | Sev | Watches |
|---|---|---|
| PMPro `MemberOrder` | C | PMPro present |
| `pmpro_orders_user_row_actions` | C | the Regenerate Invoice link's hook |
| **PMPro PDF Invoices internals** | C | `pmpropdf_generate_pdf`, `pmpropdf_get_invoice_directory_or_url`, `pmpropdf_generate_invoice_name` |
| Regenerate handler registered | C | `admin_post_qhta_regenerate_invoice` |
| PMPro invoice template override | W | `uploads/pmpro-pdf-invoices/invoice.html` |
| PMPro invoice output directory | W | the real directory, or the guessed fallback |

This is the most exposed of the eight — its own README says it leans on
undocumented `pmpropdf_*` functions belonging to a third-party plugin, and its
`function_exists()` guards turn an upstream rename into an admin notice that
nobody sees until they actually try to reissue an invoice. These canaries move
that discovery forward.

### qhta-revenue — 6 canaries

| Canary | Sev | Watches |
|---|---|---|
| PMPro orders table and columns | C | `wp_pmpro_membership_orders` + the 8 columns read by name |
| `wc_get_orders()` available | C | the HPOS-safe read path for store income |
| Report capability exists | W | administrator still holds `manage_woocommerce` |
| Stripe secret key resolvable | W | `qhta_revenue_stripe_key()` returns something |
| Stripe API accepts the key | W, R | one `GET /v1/balance` a day — a present key is not a working key |
| PMPro and WooCommerce agree on currency | W | the combined total is not adding unlike units |

The currency canary is the one nothing else would ever surface: the report adds
membership and store amounts into a single figure, and if the two systems are
configured in different currencies that total is arithmetic on unlike units with
nothing anywhere saying so.

### qhta-mailchimp-archive — 7 canaries

| Canary | Sev | Watches |
|---|---|---|
| `QHTA_MAILCHIMP_API_KEY` | C | defined, non-empty, **and** parses to `key-dc` |
| Refresh cron scheduled and on time | C | `qhta_mc_archive_refresh`, overdue > 6h = WP-Cron stalled |
| `'fifteen_minutes'` schedule registered | C | the custom interval the event reschedules against |
| Cached campaign page 1 | C | `qhta_mc_archive_page_1` shaped correctly (reports newest send date) |
| `[mailchimp_archive]` shortcode | C | registered |
| `[mailchimp_archive]` is on a page | W | actually placed |
| Mailchimp API accepts the key | W, R | `GET /3.0/ping` |

The cron canary matters more here than anywhere else. This plugin caches into
`wp_options` rather than transients **because Hostinger's object cache holds
transients past their TTL** — which means WP-Cron is the only thing that ever
refreshes the archive. If cron stalls, the page keeps rendering happily from a
cache that stopped changing, and the failure is invisible until somebody notices
the newest newsletter is months old.

---

## Open items

- **Confirm the `qhta-pmpro-invoice-extensions` install slug** on the live site
  and, if it is not already covered, add it to `qhta_healthcheck_slug_aliases`.
  Locally the repo is `Development/PMPro Invoice Extensions` and the file is
  `regenerate_invoice.php`; the alias list currently covers
  `pmpro-invoice-extensions`, `qhta-regenerate-invoice` and `regenerate_invoice`.
- **The remote checks have never run against production.** Two of them make
  assumptions worth verifying on the first real pass: the checkout probe opens
  `pmpro_url( 'checkout' )` with the first visible level, and the theme-extras
  probe reads the home page as a logged-out visitor. A page behind a cache that
  serves something different will fail honestly rather than pretend to pass —
  which is correct, but means the first run may need the needles adjusted.
