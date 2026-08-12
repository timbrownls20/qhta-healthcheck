# Changelog

All notable changes to QHTA Healthcheck.

## 1.1.3 — 12 August 2026

### Added
- **Leftover copies from a migration are named as such.** When a plugin moves
  install location — the PMPro invoice plugin going from a bare
  `regenerate_invoice.php` to a proper folder — both copies exist on the site
  until the old one is deleted, and the old one was reported as a separate plugin
  with *"no canaries defined"*.

  That reads as "somebody forgot to write checks" rather than "this is the old
  copy, finish the job". It now says the latter, and names the entry that
  superseded it. Half a migration is a state worth describing in its own words.

### Fixed
- `$aliases` was undefined in `qhta_healthcheck_watched_plugins()` after an
  earlier refactor moved the lookup into `qhta_healthcheck_resolve_installed()`.
  A PHP warning rather than a failure, and the leftover detection above silently
  did nothing — found by running the test harness with warnings escalated to
  failures, which is now how it runs.

## 1.1.2 — 12 August 2026

### Fixed
- **Must-use plugins are now discovered.** `get_plugins()` does not list anything
  in `wp-content/mu-plugins/`, and one of the eight QHTA plugins lives there: the
  conference program. The board reported it *"expected on this site, but not
  installed"* while it was not merely installed but **permanently active** —
  the most misleading thing a monitor can say, and the actual cause of the red
  row. `get_mu_plugins()` is now read alongside `get_plugins()`.

  This, not the folder name, was the root cause. The `htaa-conference` alias
  added in 1.1.1 had been correct all along; nothing was ever looking in the
  right directory. The name-matching route from 1.1.1 is kept — the assumption it
  removes is still real — but it was not what was wrong.

  Must-use plugins are marked `mu` and always reported active: there is no
  activation state to read, so the "installed but not active" amber can never
  apply to one. The board tags them **must-use**, because a plugin that cannot be
  deactivated and does not appear on the normal Plugins screen is worth naming.

### Added
- **Detection of a plugin installed twice** — once as a must-use plugin and once
  through wp-admin. Reported ahead of that plugin's canaries, because two copies
  of the same code registering the same post types, constants and shortcodes
  makes every other reading on the card untrustworthy.

  **Amber** when the second copy is inactive (a fatal one click away), **red**
  when both are running. This happens naturally when a plugin historically
  dropped into `mu-plugins/` is later uploaded as a normal plugin — which is
  exactly what a deploy zip invites somebody to do.

## 1.1.1 — 12 August 2026

### Fixed
- **A plugin is now recognised by its header name as well as its folder.** The
  conference program was reported *"expected on this site, but not installed"*
  while sitting plainly in the Plugins list: its folder matched neither the
  canonical slug nor either alternative guessed for it, and its name began with
  "HTAA" rather than "QHTA", so all three routes missed.

  A folder name is decided at upload time by whoever unzipped the plugin and can
  be anything. The header name is different in kind — it is a property of the
  code, it travels with the plugin, and it is what a human reads on the Plugins
  screen. `qhta_healthcheck_plugin_names()` maps canonical slug → header names,
  and `qhta_healthcheck_resolve_installed()` now tries folder, then alias, then
  name.

  Old names are listed beside new ones, so a plugin rename does not drop it off
  the board in the window before the renamed build is deployed. That is why this
  fix works on its own: deploying **1.1.1 alone** clears the red row, without
  waiting for the conference plugin.

- `qhta_healthcheck_checks_for()` also merges canaries registered under the slug
  a plugin turned out to be installed as, for a plugin that keys its own
  registration off its folder rather than a hardcoded string.

## 1.1.0 — 12 August 2026

The canaries moved out. All 61 now ship inside the plugin they watch,
self-registered on `qhta_healthcheck_checks`; `includes/checks.php` holds none.

### Changed
- **`includes/checks.php` is now empty of canaries.** A canary and the dependency
  it guards should be impossible to deploy apart — when somebody changes
  qhta-commerce's gate, the canary for that gate should be in the diff they are
  already looking at. A central registry works quietly against that, because it
  puts the reminder in a different repository from the change.

  Each plugin now carries its own: `includes/healthcheck.php` in the five that
  have an includes directory, inline in the three single-file ones.

- **No fallback copy, deliberately.** A plugin has no canaries until it is next
  deployed and reports amber — *"no canaries defined"* — in the meantime. That is
  what makes the rollout incremental: no coordinated release, no flag day, never
  two copies of a check running, and the board doubles as the tracker. Every
  plugin still showing that amber is one that has not been redeployed yet.

  The cost is a window in which a plugin is watched for presence but not
  correctness. That is an honest amber rather than a false green, and it closes
  the first time each plugin ships.

### Added
- **`qhta_healthcheck_expected_plugins()`** — the fleet that ought to be
  installed, as a plain list of names, with a filter of the same name.

  This exists *because* the canaries left. While they lived centrally the fleet
  could be derived from them: a plugin with canaries in the registry was, by
  definition, one somebody expected. That stops being true the moment canaries
  self-register, because an uninstalled plugin registers nothing — a derived
  fleet would quietly shrink to whatever happened to be installed, and a deleted
  plugin's row would **vanish rather than turn red**. That is the worst failure a
  monitor can have: reporting all-clear precisely because something is missing.

  It is also the one claim a plugin genuinely cannot make about itself.

- **Alias-aware canary lookup.** A plugin may self-register under either its
  canonical slug or its installed slug; `qhta_healthcheck_checks_for()` merges
  through the alias map. The conference program registers under
  `htaa-conference`, its own folder name, and is filed under
  `qhta-conference-plugin`. Requiring each plugin to know the name this monitor
  files it under would be a dependency pointing the wrong way.

### Fixed
- A plugin watched only through the `qhta_healthcheck_watch_plugins` filter and
  never installed now reports **amber**, not red. Somebody's intention to watch a
  plugin is not the same claim as the site's contract that it should be present —
  only the expected fleet gets the red.

## 1.0.0

First release.

### Added

- **Auto-discovery** of watched plugins. Anything installed whose slug *or
  plugin name* starts with `qhta` is picked up without being told, so a new QHTA
  plugin can never be silently absent from the board. Name matching is there for
  single-file plugins, whose slug is whatever the file happened to be called.
- **Canary registry** with two homes: the `qhta_healthcheck_checks` filter for
  self-registration from the plugin that owns the dependency (preferred), and
  the central `includes/checks.php` (fallback, and the permanent home for checks
  about a plugin's *absence*).
- **61 starter canaries** across all eight QHTA plugins — see
  `qhta-healthcheck-handover.md` for the per-plugin specification.
- **19 assertion primitives** covering functions, classes, constants, tables and
  columns, hooks, shortcodes (registered *and* placed), meta keys (postmeta and
  HPOS-safe order meta), cron events and custom schedules, options, files, pages
  at hardcoded paths, WooCommerce system pages, My Account endpoints, the active
  theme, front-end markup, and third-party API credentials.
- **Tools → QHTA Health** board, and a **Dashboard widget** carrying the
  headline plus the worst five findings.
- **Daily scheduled full run** including the remote checks, plus a **Run checks
  now** button.
- `qhta_healthcheck_watch_plugins`, `qhta_healthcheck_slug_aliases` and
  `qhta_healthcheck_capability` filters.

### Notes on the design

- **Local checks run live on every screen load; remote checks do not.**
  Everything that is a `function_exists()` or a `SHOW TABLES` is too cheap to be
  worth caching and too important to show stale. Anything that opens an HTTP
  connection is neither, so it runs on the daily pass and carries its last answer
  forward with its age stated.
- **Only a full run is persisted.** Storing a local-only run would overwrite the
  remote answers with "unknown" the first time anybody opened the board.
- **A watched plugin with no canaries reports amber**, not green. Discovery
  covers presence; it cannot invent the canary. Showing it green would be a lie
  of omission.
- **`skipped` never colours the roll-up.** Without it, deactivating one plugin
  would light up every screen in red and the dashboard would be ignored within a
  week.
- **Severity defaults to `warning`.** A canary contributed without thinking about
  consequence should not be able to turn the whole site red.
- **Every canary runs inside a `try`/`catch`.** A monitoring plugin that can
  white-screen wp-admin is worse than no monitoring plugin.

### Two discrepancies found and handled

- `qhta-conference-plugin` installs as **`htaa-conference`** — its slug does not
  start with `qhta`, so auto-discovery alone would never have seen it. Reached
  through `qhta_healthcheck_slug_aliases()`.
- `qhta-pmpro-invoice-extensions` is a single-file plugin whose folder has
  varied; it is matched by plugin name ("QHTA Regenerate Invoice") and by alias.
  The alias also collapses what would otherwise be a duplicate row carrying a
  permanent "no canaries defined" amber.
