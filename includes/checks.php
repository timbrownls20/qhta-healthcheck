<?php
/**
 * The expected fleet, and the (empty) central canary registry.
 *
 * Every canary now lives in the plugin that owns the dependency, self-registered
 * through the qhta_healthcheck_checks filter. None live here.
 *
 * That means a plugin has NO canaries until it is next deployed, and reports
 * amber — "no canaries defined" — in the meantime. That is a deliberate choice
 * and a good one: it needs no coordinated release, it never runs two copies of a
 * check, and the board doubles as the rollout tracker. Every plugin still
 * showing that amber is a plugin that has not been redeployed yet, which is
 * exactly the list somebody would otherwise be keeping by hand.
 *
 * What it costs is a window in which a plugin is watched for presence but not
 * for correctness. That is an honest amber rather than a false green, and it
 * closes the first time each plugin ships.
 *
 * @package QHTA_Healthcheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugins that ought to be installed on this site.
 *
 * Deliberately dumb — names only, no logic — and deliberately separate from the
 * canaries.
 *
 * If the canaries still lived here, the fleet could be derived from them: a
 * plugin with canaries in the registry was, by definition, one somebody
 * expected. That stops being true the moment canaries self-register, because an
 * uninstalled plugin registers nothing. A fleet derived from the registry would
 * quietly shrink to match whatever happened to be installed — a plugin could be
 * deleted from the site and its row would vanish from this screen rather than
 * turn red. That is the worst failure a monitor can have: reporting "all clear"
 * precisely because something is missing.
 *
 * This is also the one claim a plugin genuinely cannot make about itself. A
 * plugin that is not installed cannot tell you it should have been.
 *
 * Add a plugin here when it becomes part of the site. Remove it when it is
 * genuinely retired, not when it is temporarily deactivated — an inactive plugin
 * is reported amber, which is a different thing from an absent one.
 *
 * Slugs are canonical. Where a plugin installs under a different folder — the
 * conference program installs as htaa-conference — the mapping lives in
 * qhta_healthcheck_slug_aliases().
 *
 * @return string[] Canonical plugin slugs.
 */
function qhta_healthcheck_expected_plugins() {
	/**
	 * Filter the list of plugins expected to be installed on this site.
	 *
	 * @param string[] $expected Canonical plugin slugs.
	 */
	return (array) apply_filters(
		'qhta_healthcheck_expected_plugins',
		array(
			'qhta-commerce',
			'qhta-conference-plugin',
			'qhta-mailchimp-archive',
			'qhta-membership',
			'qhta-pmpro-invoice-extensions',
			'qhta-revenue',
			'qhta-theme-extras',
			'qhta-woo-invoice',
		)
	);
}

/**
 * Canaries owned by this plugin rather than by the plugin they watch.
 *
 * Empty, and that is the intended end state rather than an oversight.
 *
 * A canary and the dependency it guards should be impossible to deploy apart.
 * When somebody changes qhta-commerce's gate, the canary for that gate should be
 * in the diff they are already looking at — and a central registry works quietly
 * against that, because it puts the reminder in a different repository from the
 * change.
 *
 * One kind of canary would still belong here if it existed: a check spanning two
 * plugins where neither owns the relationship. Note how few of those there
 * really are. qhta-commerce redirecting to a page qhta-membership owns is still
 * qhta-commerce's dependency, because qhta-commerce is the thing that breaks.
 *
 * Resist filling it. A canary that ends up here should have to justify why it
 * could not live with the code it guards.
 *
 * @return array<string,array[]> Slug => list of check specs.
 */
function qhta_healthcheck_central_checks() {
	return array();
}
