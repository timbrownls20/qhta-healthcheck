#!/usr/bin/env bash
#
# Produce the deploy zip for wp-admin -> Plugins -> Add New -> Upload Plugin.
#
# Reads the version out of the plugin header rather than taking it as an
# argument, and refuses to build unless the header and QHTA_HEALTHCHECK_VERSION
# agree. Same guard as qhta-commerce, qhta-membership, qhta-theme-extras and
# qhta-revenue: a mismatch means the two sources of truth for "what version is
# live" have drifted.
#
# Usage: ./scripts/build-zip.sh

set -euo pipefail

PLUGIN_SLUG="qhta-healthcheck"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PARENT_DIR="$(dirname "$PLUGIN_DIR")"
BOOTSTRAP="$PLUGIN_DIR/$PLUGIN_SLUG.php"

# Version: 1.0.0  ->  1.0.0
header_version="$(sed -n "s/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][^[:space:]]*\).*/\1/p" "$BOOTSTRAP")"

# define( 'QHTA_HEALTHCHECK_VERSION', '1.0.0' );  ->  1.0.0
const_version="$(sed -n "s/^define([[:space:]]*'QHTA_HEALTHCHECK_VERSION',[[:space:]]*'\([^']*\)'.*/\1/p" "$BOOTSTRAP")"

if [[ -z "$header_version" || -z "$const_version" ]]; then
	echo "error: could not read the version from $BOOTSTRAP" >&2
	echo "       header='$header_version' constant='$const_version'" >&2
	exit 1
fi

if [[ "$header_version" != "$const_version" ]]; then
	echo "error: version mismatch — bump both before deploying." >&2
	echo "       plugin header:             $header_version" >&2
	echo "       QHTA_HEALTHCHECK_VERSION:  $const_version" >&2
	exit 1
fi

VERSION="$header_version"
ZIP_PATH="$PLUGIN_DIR/$PLUGIN_SLUG-$VERSION.zip"

# Syntax-check every PHP file so a typo cannot reach the live site. This one
# matters more than usual: the dashboard widget runs on every wp-admin load, so
# a parse error here white-screens the dashboard for everybody — which is the
# exact failure mode the plugin's own try/catch exists to prevent it causing.
if command -v php >/dev/null 2>&1; then
	while IFS= read -r -d '' php_file; do
		php -l "$php_file" >/dev/null
	done < <(find "$PLUGIN_DIR" -name '*.php' -not -path '*/.git/*' -print0)
else
	echo "note: php not on PATH, skipping syntax check" >&2
fi

rm -f "$ZIP_PATH"

# WordPress needs the plugin folder as the top level inside the archive, so zip
# has to run from the parent. The output lands in the plugin root, which is
# inside the tree being zipped — build to a temp file and move it in afterwards
# so the archive cannot swallow itself.
staging_dir="$(mktemp -d)"
trap 'rm -rf "$staging_dir"' EXIT
staging_zip="$staging_dir/$PLUGIN_SLUG-$VERSION.zip"

# Excludes: editor cruft, git metadata, local Claude settings (permission
# allowlist, not for the web server), previous builds, the handover notes, and
# this build tooling.
cd "$PARENT_DIR"
zip -rq "$staging_zip" "$PLUGIN_SLUG" \
	-x "*.DS_Store" \
	   "*.git*" \
	   "*.claude*" \
	   "*.zip" \
	   "qhta-healthcheck/HEALTHCHECK.md" \
	   "$PLUGIN_SLUG/scripts/*" \
	   "$PLUGIN_SLUG/$PLUGIN_SLUG-handover.md"

mv "$staging_zip" "$ZIP_PATH"

echo "built $ZIP_PATH ($VERSION)"
unzip -l "$ZIP_PATH"

cat <<EOF

Next:
  1. wp-admin -> Plugins -> Add New -> Upload Plugin -> replace -> activate
  2. It appears as "QHTA Health" in two places — a Dashboard widget and
     Tools -> QHTA Health. There is no front-end footprint and nothing to
     configure. Activation schedules one daily cron event.
  3. Smoke test, in this order:
     a. Tools -> QHTA Health loads  -> 8 QHTA plugins listed, this one absent
                                       (it watches the others, not itself)
     b. the conference program      -> listed as qhta-conference-plugin, tagged
                                       "installed as htaa-conference". If it is
                                       MISSING, auto-discovery could not see it
                                       and the alias needs its real slug.
     c. the PMPro invoice plugin    -> appears ONCE. Twice means its install slug
                                       is not in qhta_healthcheck_slug_aliases()
                                       and the duplicate is the uncovered copy.
     d. plugins saying              -> expected during the staged rollout: each
        "No canaries defined"          plugin brings its own canaries on its next
                                       deploy. The list of plugins still showing
                                       it IS the rollout tracker.
     e. Dashboard widget            -> headline agrees with the board, and its
                                       finding count matches the board's
     f. "Run checks now"            -> the 4 remote checks stop saying
                                       "Not yet run" and return a real answer
     g. the two markup probes are   -> PMPro checkout selectors, and theme-extras
        the ones to read closely       loading on the home page. These have never
                                       run against production; a cache or a login
                                       wall makes them fail honestly rather than
                                       pretend to pass. Their needles live in
                                       qhta-membership and qhta-theme-extras
                                       respectively, not here.
     h. deactivate one QHTA plugin  -> it goes AMBER "installed but not active"
                                       with its canaries SKIPPED — not a wall of
                                       red. Reactivate.
     i. confirm it wrote nothing    -> the only new option is
                                       qhta_healthcheck_results, and the only new
                                       cron event is qhta_healthcheck_run
  4. Then the standing rule takes over: whenever a QHTA plugin gains or changes
     an external dependency, the canary for it goes in THAT plugin, in the same
     change, on the qhta_healthcheck_checks filter. Nothing needs editing here.
     See qhta-healthcheck-handover.md.

  Note this plugin cannot tell you the site is down — a dead WordPress cannot
  email you. Keep the external "QHTA site guardian" probe running alongside it.
EOF
