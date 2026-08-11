<?php
/**
 * The QHTA Health screen.
 *
 * @package QHTA_Healthcheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the menu.
 *
 * Under Tools rather than a top-level menu: it is read a handful of times a
 * year, and a permanent top-level item for something that is green 360 days
 * out of 365 trains people to stop seeing it. The dashboard widget is where it
 * asks for attention.
 *
 * @return void
 */
function qhta_healthcheck_admin_menu() {
	add_management_page(
		__( 'QHTA Health', 'qhta-healthcheck' ),
		__( 'QHTA Health', 'qhta-healthcheck' ),
		qhta_healthcheck_capability(),
		QHTA_HEALTHCHECK_SLUG,
		'qhta_healthcheck_render_page'
	);
}
add_action( 'admin_menu', 'qhta_healthcheck_admin_menu' );

/**
 * The screen's stylesheet, on the screen only.
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function qhta_healthcheck_admin_assets( $hook ) {
	if ( 'tools_page_' . QHTA_HEALTHCHECK_SLUG !== $hook && 'index.php' !== $hook ) {
		return;
	}

	wp_enqueue_style(
		'qhta-healthcheck-admin',
		QHTA_HEALTHCHECK_URL . 'assets/admin.css',
		array(),
		QHTA_HEALTHCHECK_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'qhta_healthcheck_admin_assets' );

/**
 * Handle "Run checks now".
 *
 * A full run including the remote checks, then a redirect so a refresh does not
 * fire the HTTP calls again.
 *
 * @return void
 */
function qhta_healthcheck_handle_run_now() {
	if ( ! current_user_can( qhta_healthcheck_capability() ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to run the health checks.', 'qhta-healthcheck' ) );
	}

	check_admin_referer( 'qhta_healthcheck_run' );

	qhta_healthcheck_store_run( qhta_healthcheck_run_all( true ) );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page' => QHTA_HEALTHCHECK_SLUG,
				'ran'  => '1',
			),
			admin_url( 'tools.php' )
		)
	);
	exit;
}
add_action( 'admin_post_qhta_healthcheck_run', 'qhta_healthcheck_handle_run_now' );

/**
 * Render the board.
 *
 * The local checks are run live on every load, and the remote ones are carried
 * forward from the last scheduled pass. That split is the whole performance
 * story of this screen: everything that is a function_exists() or a SHOW TABLES
 * is too cheap to be worth caching and too important to show stale, while
 * anything that opens an HTTP connection is neither.
 *
 * @return void
 */
function qhta_healthcheck_render_page() {
	if ( ! current_user_can( qhta_healthcheck_capability() ) ) {
		wp_die(
			esc_html__( 'Sorry, you are not allowed to view the health checks.', 'qhta-healthcheck' ),
			esc_html__( 'Not allowed', 'qhta-healthcheck' ),
			array( 'response' => 403 )
		);
	}

	$cached = qhta_healthcheck_cached_run();
	$run    = qhta_healthcheck_run_all( false );
	$tally  = qhta_healthcheck_tally( $run );

	echo '<div class="wrap qhta-healthcheck">';
	echo '<h1>' . esc_html__( 'QHTA Health', 'qhta-healthcheck' ) . '</h1>';

	echo '<p class="qhta-hc-intro">';
	echo esc_html__( 'Whether the things the QHTA plugins depend on are still there — WooCommerce and PMPro functions, hooks, tables, meta keys, checkout markup, cron events and API keys. This screen only reads; nothing here changes an order, a member or a setting.', 'qhta-healthcheck' );
	echo '</p>';

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success flag.
	if ( ! empty( $_GET['ran'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Checks re-run, including the remote ones.', 'qhta-healthcheck' ) . '</p></div>';
	}

	qhta_healthcheck_render_summary( $run, $tally, $cached );

	foreach ( $run['plugins'] as $plugin ) {
		qhta_healthcheck_render_plugin( $plugin );
	}

	qhta_healthcheck_render_footer();

	echo '</div>';
}

/**
 * The headline bar.
 *
 * @param array $run    Current run.
 * @param array $tally  Counts by status.
 * @param array $cached Last full run, for the remote-checks timestamp.
 * @return void
 */
function qhta_healthcheck_render_summary( array $run, array $tally, array $cached ) {
	$headline = array(
		'red'     => __( 'Something is broken', 'qhta-healthcheck' ),
		'amber'   => __( 'Needs a look', 'qhta-healthcheck' ),
		'green'   => __( 'All clear', 'qhta-healthcheck' ),
		'unknown' => __( 'Not yet run', 'qhta-healthcheck' ),
		'skipped' => __( 'Nothing to check', 'qhta-healthcheck' ),
	);

	$status = $run['status'];

	echo '<div class="qhta-hc-summary qhta-hc-status-' . esc_attr( $status ) . '">';
	echo '<div class="qhta-hc-summary-headline">';
	echo '<span class="qhta-hc-dot" aria-hidden="true"></span>';
	echo '<strong>' . esc_html( isset( $headline[ $status ] ) ? $headline[ $status ] : $status ) . '</strong>';
	echo '</div>';

	echo '<div class="qhta-hc-summary-counts">';
	printf(
		/* translators: 1: failed count, 2: warning count, 3: OK count, 4: skipped count. */
		esc_html__( '%1$d failed · %2$d warnings · %3$d OK · %4$d skipped', 'qhta-healthcheck' ),
		(int) $tally['red'],
		(int) $tally['amber'],
		(int) $tally['green'],
		(int) $tally['skipped']
	);
	echo '</div>';

	echo '<div class="qhta-hc-summary-actions">';

	if ( ! empty( $cached['time'] ) ) {
		echo '<span class="qhta-hc-muted">';
		printf(
			/* translators: %s: human-readable duration. */
			esc_html__( 'Remote checks last run %s ago.', 'qhta-healthcheck' ),
			esc_html( human_time_diff( (int) $cached['time'], time() ) )
		);
		echo '</span> ';
	} else {
		echo '<span class="qhta-hc-muted">' . esc_html__( 'Remote checks have not run yet.', 'qhta-healthcheck' ) . '</span> ';
	}

	$url = wp_nonce_url( admin_url( 'admin-post.php?action=qhta_healthcheck_run' ), 'qhta_healthcheck_run' );

	echo '<a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Run checks now', 'qhta-healthcheck' ) . '</a>';
	echo '</div>';

	echo '</div>';
}

/**
 * One plugin's card.
 *
 * @param array $plugin Result block from qhta_healthcheck_run_plugin().
 * @return void
 */
function qhta_healthcheck_render_plugin( array $plugin ) {
	$meta   = $plugin['plugin'];
	$labels = qhta_healthcheck_statuses();

	echo '<div class="qhta-hc-plugin qhta-hc-status-' . esc_attr( $plugin['status'] ) . '">';

	echo '<h2 class="qhta-hc-plugin-title">';
	echo '<span class="qhta-hc-dot" aria-hidden="true"></span>';
	echo esc_html( $meta['name'] );

	if ( $meta['version'] ) {
		echo ' <span class="qhta-hc-version">' . esc_html( $meta['version'] ) . '</span>';
	}

	// Where a plugin is installed under a different slug from the one the
	// registry names it by, say so — otherwise the alias is invisible and the
	// next person wonders why the screen calls it something else.
	if ( $meta['installed_as'] && $meta['installed_as'] !== $meta['slug'] ) {
		echo ' <span class="qhta-hc-version">';
		printf(
			/* translators: %s: installed plugin slug. */
			esc_html__( 'installed as %s', 'qhta-healthcheck' ),
			esc_html( $meta['installed_as'] )
		);
		echo '</span>';
	}

	echo '</h2>';

	if ( $plugin['note'] ) {
		echo '<p class="qhta-hc-note">' . esc_html( $plugin['note'] ) . '</p>';
	}

	if ( ! $plugin['results'] ) {
		echo '</div>';
		return;
	}

	echo '<table class="widefat striped qhta-hc-table">';
	echo '<thead><tr>';
	echo '<th scope="col" class="qhta-hc-col-status">' . esc_html__( 'Status', 'qhta-healthcheck' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Canary', 'qhta-healthcheck' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'What was found', 'qhta-healthcheck' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $plugin['results'] as $result ) {
		echo '<tr class="qhta-hc-status-' . esc_attr( $result['status'] ) . '">';

		echo '<td class="qhta-hc-col-status">';
		echo '<span class="qhta-hc-pill">' . esc_html( isset( $labels[ $result['status'] ] ) ? $labels[ $result['status'] ] : $result['status'] ) . '</span>';

		if ( 'critical' === $result['severity'] ) {
			echo '<span class="qhta-hc-sev">' . esc_html__( 'critical', 'qhta-healthcheck' ) . '</span>';
		}

		echo '</td>';

		echo '<td><strong>' . esc_html( $result['label'] ) . '</strong>';

		if ( ! empty( $result['remote'] ) ) {
			echo ' <span class="qhta-hc-tag">' . esc_html__( 'remote', 'qhta-healthcheck' ) . '</span>';
		}

		// The consequence is only worth the space when something is wrong.
		// Printing "here is what would break" against forty green rows is how a
		// screen becomes unreadable.
		if ( $result['why'] && in_array( $result['status'], array( 'red', 'amber' ), true ) ) {
			echo '<p class="qhta-hc-why">' . esc_html( $result['why'] ) . '</p>';
		}

		echo '</td>';

		echo '<td class="qhta-hc-detail">' . esc_html( $result['detail'] );

		if ( ! empty( $result['stale'] ) && 'unknown' !== $result['status'] ) {
			echo ' <span class="qhta-hc-tag">' . esc_html__( 'from the last full run', 'qhta-healthcheck' ) . '</span>';
		}

		echo '</td>';

		echo '</tr>';
	}

	echo '</tbody></table>';
	echo '</div>';
}

/**
 * The standing rule, restated where somebody will actually read it.
 *
 * This screen is the only place a person is guaranteed to be looking at the
 * canaries, so it is where the instruction for keeping them current belongs —
 * not solely in a handover document nobody opens while mid-change.
 *
 * @return void
 */
function qhta_healthcheck_render_footer() {
	echo '<div class="qhta-hc-footer">';
	echo '<h2>' . esc_html__( 'Keeping this honest', 'qhta-healthcheck' ) . '</h2>';

	echo '<p>' . esc_html__( 'Auto-discovery finds any plugin whose slug or name starts with "qhta", so a new QHTA plugin appears here on its own — but it appears with no canaries, and amber, because discovery cannot invent one. When a QHTA plugin gains or changes an external dependency (a WooCommerce/PMPro/Astra/ACF function, a hook, a table or column, a meta key, a checkout selector, a required add-on, a cron event), add or adjust its canary in the same change.', 'qhta-healthcheck' ) . '</p>';

	echo '<p>' . esc_html__( 'Preferred: the plugin self-registers through the qhta_healthcheck_checks filter, so the canary travels with the code that owns the dependency. Fallback: add it to this plugin\'s includes/checks.php. For a plugin whose slug does not start with "qhta", add it through qhta_healthcheck_watch_plugins.', 'qhta-healthcheck' ) . '</p>';

	echo '<p class="qhta-hc-muted">' . esc_html__( 'This plugin covers internal correctness only. It cannot tell you the site is down — a dead WordPress cannot email you. Site-up liveness is the separate external "QHTA site guardian" probe. Keep both.', 'qhta-healthcheck' ) . '</p>';

	echo '</div>';
}
