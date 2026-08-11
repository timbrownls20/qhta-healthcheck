# Healthcheck note — this repo IS the aggregator

This plugin monitors the other QHTA plugins. It auto-discovers them, runs their canaries, and reports
red / amber / green to the WordPress dashboard (widget) and **Tools → QHTA Health** (full board).

**Every canary lives in the plugin it watches**, self-registered on the `qhta_healthcheck_checks`
filter. `includes/checks.php` deliberately holds **none** — a central copy would put the reminder in a
different repository from the change, which is exactly what the standing rule is trying to prevent.

**Standing rule.** When ANY QHTA plugin is created or changes an external dependency, the canary for
it goes in *that plugin*, in the same change. Nothing needs editing here. What must stay stable are
the filter contracts below, because eight repositories now depend on them.

## What this repo owns

| Thing | Why it cannot live in a child plugin |
|---|---|
| `qhta_healthcheck_expected_plugins()` in `includes/checks.php` | A plugin that is not installed cannot tell you it should have been. Absence from the site is the one claim only an outside observer can make — and it is reported **red**. |
| `qhta_healthcheck_slug_aliases()` in `includes/registry.php` | Maps canonical slug → folders a plugin may install under. |
| `qhta_healthcheck_plugin_names()` in `includes/registry.php` | Maps canonical slug → plugin *header names*. The route that works when the folder is unrecognisable — a folder name is chosen at upload time and can be anything; the header name travels with the code. |
| The 19 `qhta_healthcheck_assert_*` helpers | Shared vocabulary. Adding one is additive and safe; renaming or removing one breaks child plugins that call it. |
| The runner, board, widget, cron and result cache | The machinery itself. |

### Stable contracts — do not break

- `qhta_healthcheck_checks` — the self-registration filter. Check spec keys: `id`, `label`, `why`,
  `severity` (`critical` \| `warning`, default `warning`), `remote` (bool), `test` (callable).
- `qhta_healthcheck_assert_*` — the assertion helpers, returning `pass` / `fail` / `skip` rows.
- `qhta_healthcheck_watch_plugins` — watch a plugin whose slug does not start with `qhta`.
- `qhta_healthcheck_expected_plugins`, `qhta_healthcheck_slug_aliases`, `qhta_healthcheck_capability`.

Child plugins may register under **either** their canonical slug or their installed slug —
`qhta_healthcheck_checks_for()` merges through the alias map. A plugin should not have to know what
this one chooses to file it under.

## Watched today (8 plugins, 61 canaries)

| Registry slug | Installs as | Discovered by | Canaries |
|---|---|---|---|
| qhta-commerce | `qhta-commerce` | slug prefix | 13 |
| qhta-membership | `qhta-membership` | slug prefix | 8 |
| qhta-theme-extras | `qhta-theme-extras` | slug prefix | 4 |
| qhta-woo-invoice | `qhta-woo-invoice` | slug prefix | 10 |
| qhta-revenue | `qhta-revenue` | slug prefix | 6 |
| qhta-mailchimp-archive | `qhta-mailchimp-archive` | slug prefix | 7 |
| **qhta-conference-plugin** | whatever folder it was uploaded into | **plugin name** — not `qhta`-prefixed | 7 |
| **qhta-pmpro-invoice-extensions** | single file, plugin name "QHTA Regenerate Invoice" | **alias** + plugin-name match | 6 |

The last two are the reason discovery matches the **plugin name** as well as the slug, and the reason
the alias map exists at all. The earlier assumption that all eight were `qhta-`prefixed was wrong —
and so, it turned out, was the assumption that the conference program installs as `htaa-conference`.
It was reported "expected on this site, but not installed" until name matching was added in 1.1.1.

**The board's "installed as …" tag is how you find out what a folder is actually called** without a
shell — worth reading the first time each plugin appears.

## Rollout state

A plugin has **no canaries until it is next deployed**, and reports amber — *"no canaries defined"* —
in the meantime. That is deliberate: it needs no coordinated release, never runs two copies of a
check, and the board doubles as the rollout tracker. **Every plugin still showing that amber is one
that has not been redeployed yet.**

Minimum versions at which each plugin brings its own canaries: qhta-commerce 1.7.0 · qhta-membership
1.3.0 · qhta-theme-extras 1.1.0 · qhta-woo-invoice 1.2.0 · qhta-revenue 1.2.0 ·
qhta-mailchimp-archive 1.3.0 · conference program 1.1.1 · regenerate-invoice 1.1.0.

## More
- Full rule, rationale and the per-plugin canary list: `qhta-healthcheck-handover.md`.
- This plugin covers internal correctness only. It cannot tell you the site is **down** — a dead
  WordPress cannot email you. Site-up liveness is the external "QHTA site guardian" HTTP task. Keep
  both.
