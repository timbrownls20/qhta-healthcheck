<?php
/**
 * Plugin Name:       QHTA Healthcheck
 * Description:       Read-only self-monitoring for the QHTA custom plugins on qhta.com.au. Discovers every QHTA plugin, runs a set of canaries against the external dependencies each one relies on — WooCommerce and PMPro functions, hooks, tables, meta keys, checkout DOM selectors, cron events, add-ons — and reports red / amber / green to the WordPress dashboard. Changes nothing it watches.
 * Version:           1.1.3
 * Author:            QHTA
 * License:           GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * Scope rule: this plugin observes, it never repairs. It has no opinion about
 * what the other plugins should do — only about whether the things they depend
 * on are still there. Business logic belongs in the plugin that owns it.
 *
 * @package QHTA_Healthcheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QHTA_HEALTHCHECK_VERSION', '1.1.3' );
define( 'QHTA_HEALTHCHECK_PATH', plugin_dir_path( __FILE__ ) );
define( 'QHTA_HEALTHCHECK_URL', plugin_dir_url( __FILE__ ) );
define( 'QHTA_HEALTHCHECK_SLUG', 'qhta-healthcheck' );

/**
 * Where the last full run is cached.
 *
 * autoload=no — it is only ever read on two admin screens.
 */
const QHTA_HEALTHCHECK_RESULTS_OPTION = 'qhta_healthcheck_results';

/**
 * The scheduled full run, including the remote checks.
 */
const QHTA_HEALTHCHECK_CRON_HOOK = 'qhta_healthcheck_run';


/* -------------------------------------------------------------------------
 * Why this plugin exists
 *
 * Every QHTA plugin is a thin layer over somebody else's system. qhta-commerce
 * gates pages on wc_customer_bought_product(); qhta-membership unsets a field
 * PMPro would otherwise insist on and moves two inputs around a form it does
 * not own; qhta-woo-invoice hangs a PDF off woocommerce_email_attachments;
 * qhta-revenue reads a PMPro table by name. None of those dependencies are
 * contracts. They are other people's internals, and when one of them moves the
 * failure is almost always SILENT — the gate stops gating, the invoice stops
 * attaching, the username field reappears at checkout — because a
 * function_exists() guard turns "this broke" into "this politely did nothing".
 *
 * Those guards are right: a fatal on the checkout path is worse than a missing
 * feature. But they mean nobody finds out. This plugin is the other half of
 * that bargain — it is the thing that notices, and it is the reason the guards
 * can stay quiet.
 *
 * Three rules the code below obeys:
 *
 *   1. IT CHANGES NOTHING IT WATCHES. No check writes an order, a member, a
 *      post, a meta row or another plugin's option. Remote checks are GETs.
 *      A canary that had to modify something to prove a thing works would be a
 *      test, not a canary, and belongs in a staging environment.
 *
 *      The single carve-out is this plugin's own results cache (the option
 *      above) and its own cron event. That is bookkeeping about the checks, not
 *      data — deleting it loses nothing but the last run's timestamp.
 *
 *   2. IT FAILS SOFT, ALWAYS. Every canary runs inside a try/catch and a
 *      severity. A check that throws reports itself as broken rather than
 *      taking down wp-admin. A monitoring plugin that can white-screen the
 *      dashboard is worse than no monitoring plugin.
 *
 *   3. A CANARY PROVES THE EFFECT, NOT THE INTENTION, wherever it can. Asking
 *      "is the conference_session post type registered?" is worth more than
 *      "did our init callback get added?", because the first fails when ACF is
 *      deactivated, when the plugin is half-deployed, and when somebody
 *      unhooks us — and the second only fails for the last of those.
 *
 * Discovery is automatic; canaries are not. Anything installed whose slug or
 * plugin name starts with "qhta" is picked up and watched without being told,
 * so a new plugin can never be silently absent from this screen. But
 * auto-discovery cannot invent a canary — it does not know that qhta-commerce
 * cares about wc_customer_bought_product(). A watched plugin with no canaries
 * is therefore reported AMBER, not green: "nobody has said what this depends
 * on" is a real gap, and showing it green would be a lie of omission.
 *
 * See qhta-healthcheck-handover.md for the standing rule that keeps the two in
 * step when a watched plugin changes.
 * ---------------------------------------------------------------------- */


/**
 * Which capability may see the health screen.
 *
 * manage_options: this is a site-integrity screen, not an operational report,
 * and the detail lines name internal function and table names.
 *
 * @return string
 */
function qhta_healthcheck_capability() {
	/**
	 * Filter the capability required to view QHTA Health.
	 *
	 * @param string $cap Capability.
	 */
	return (string) apply_filters( 'qhta_healthcheck_capability', 'manage_options' );
}

require_once QHTA_HEALTHCHECK_PATH . 'includes/results.php';
require_once QHTA_HEALTHCHECK_PATH . 'includes/assertions.php';
require_once QHTA_HEALTHCHECK_PATH . 'includes/registry.php';
require_once QHTA_HEALTHCHECK_PATH . 'includes/checks.php';
require_once QHTA_HEALTHCHECK_PATH . 'includes/runner.php';
require_once QHTA_HEALTHCHECK_PATH . 'includes/admin-page.php';
require_once QHTA_HEALTHCHECK_PATH . 'includes/dashboard-widget.php';

/**
 * Schedule the daily full run on activation.
 *
 * Daily rather than hourly: these dependencies move when a plugin is updated or
 * a setting is changed, which is a human-timescale event. The screen itself
 * re-runs the local checks live on every load, so "daily" only governs how
 * stale the remote checks and the dashboard widget can be.
 *
 * @return void
 */
function qhta_healthcheck_activate() {
	if ( ! wp_next_scheduled( QHTA_HEALTHCHECK_CRON_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', QHTA_HEALTHCHECK_CRON_HOOK );
	}
}
register_activation_hook( __FILE__, 'qhta_healthcheck_activate' );

/**
 * Unschedule on deactivation, and drop the cache.
 *
 * The cached results describe a moment that has passed; leaving them behind
 * would mean a reactivated plugin showed a stale green until the next run.
 *
 * @return void
 */
function qhta_healthcheck_deactivate() {
	$timestamp = wp_next_scheduled( QHTA_HEALTHCHECK_CRON_HOOK );

	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, QHTA_HEALTHCHECK_CRON_HOOK );
	}

	delete_option( QHTA_HEALTHCHECK_RESULTS_OPTION );
}
register_deactivation_hook( __FILE__, 'qhta_healthcheck_deactivate' );
