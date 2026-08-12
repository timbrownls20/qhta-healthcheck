<?php
/**
 * Running the canaries, caching the answers, and rolling them up.
 *
 * @package QHTA_Healthcheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run one check, and never let it take the page down with it.
 *
 * The try/catch is the whole reason this is a function rather than a call site.
 * These canaries poke at other people's code — a class that has changed shape,
 * a function whose signature moved, a table that is mid-migration. A fatal in
 * any one of them would white-screen wp-admin, which is a strictly worse
 * outcome than the silent failure this plugin exists to prevent. So a check
 * that throws reports itself as the broken thing and the rest carry on.
 *
 * @param array $check Normalised check spec.
 * @return array{status:string,detail:string}
 */
function qhta_healthcheck_run_check( array $check ) {
	if ( ! is_callable( $check['test'] ) ) {
		return array(
			'status' => 'red',
			'detail' => __( 'Check has no runnable test.', 'qhta-healthcheck' ),
		);
	}

	try {
		$raw = call_user_func( $check['test'] );
	} catch ( Throwable $e ) {
		return array(
			'status' => ( 'critical' === $check['severity'] ) ? 'red' : 'amber',
			'detail' => sprintf(
				/* translators: %s: error message. */
				__( 'Check threw: %s', 'qhta-healthcheck' ),
				$e->getMessage()
			),
		);
	}

	return qhta_healthcheck_normalise_result( $raw, $check['severity'] );
}

/**
 * Run everything for one plugin.
 *
 * The plugin's own state is settled first, because it changes what the canaries
 * mean:
 *
 *   - Not installed, but named in the registry → red. The registry is a
 *     statement that it is expected here.
 *   - Not installed, discovered only → it cannot be, so this branch never runs.
 *   - Installed but inactive → amber, and every canary is skipped rather than
 *     failed. An inactive plugin's dependencies are not broken; they are
 *     irrelevant, and reporting twelve red lines for one deliberate
 *     deactivation is how a monitoring screen gets ignored.
 *   - Active with no canaries → amber. Nobody has said what this depends on.
 *
 * @param array $plugin       Descriptor from qhta_healthcheck_watched_plugins().
 * @param bool  $include_remote Run the HTTP checks too.
 * @param array $previous     Previously cached results, to keep remote answers when skipping them.
 * @return array
 */
function qhta_healthcheck_run_plugin( array $plugin, $include_remote = false, array $previous = array() ) {
	$checks  = qhta_healthcheck_checks_for( $plugin['slug'] );
	$results = array();
	$notes   = array();

	if ( ! $plugin['installed'] ) {
		// Red only when the fleet list says it ought to be here. A slug added
		// through the watch filter and never installed is somebody's intention,
		// not the site's contract, and does not deserve the same alarm.
		return array(
			'plugin'  => $plugin,
			'status'  => $plugin['expected'] ? 'red' : 'amber',
			'note'    => $plugin['expected']
				? __( 'Expected on this site, but not installed.', 'qhta-healthcheck' )
				: __( 'Watched, but not installed.', 'qhta-healthcheck' ),
			'results' => array(),
		);
	}

	if ( ! $plugin['active'] ) {
		foreach ( $checks as $check ) {
			$results[ $check['id'] ] = array(
				'label'    => $check['label'],
				'why'      => $check['why'],
				'severity' => $check['severity'],
				'remote'   => $check['remote'],
				'status'   => 'skipped',
				'detail'   => __( 'Plugin is inactive.', 'qhta-healthcheck' ),
			);
		}

		return array(
			'plugin'  => $plugin,
			'status'  => 'amber',
			'note'    => __( 'Installed but not active — canaries skipped.', 'qhta-healthcheck' ),
			'results' => $results,
		);
	}

	// An old install location still sitting on disk after the plugin has moved.
	if ( ! empty( $plugin['leftover_of'] ) ) {
		return array(
			'plugin'  => $plugin,
			'status'  => 'amber',
			'note'    => sprintf(
				/* translators: %s: canonical plugin slug it was superseded by. */
				__( 'Leftover copy from an earlier install location — this plugin now lives at %s, listed above. Nothing is wrong, but delete this one to finish the move. Its canaries are reported against the new copy, not here.', 'qhta-healthcheck' ),
				$plugin['leftover_of']
			),
			'results' => array(),
		);
	}

	// The same plugin installed twice — once as a must-use plugin, once through
	// wp-admin — is reported ahead of everything else, because it is the kind of
	// problem that makes every other reading on this card untrustworthy. Two
	// copies of the same code both registering the same post type, constants and
	// shortcode is not a degraded state, it is a fatal waiting for somebody to
	// press Activate.
	if ( ! empty( $plugin['duplicated'] ) ) {
		$note = $plugin['duplicate_active']
			? sprintf(
				/* translators: 1: plugin name of the second copy, 2: its file. */
				__( 'INSTALLED TWICE AND BOTH ARE RUNNING — as a must-use plugin, and as "%1$s" (%2$s), which is active. The same post types, constants and shortcodes are being registered twice. Deactivate and delete one copy.', 'qhta-healthcheck' ),
				$plugin['duplicate_name'],
				$plugin['duplicate_of']
			)
			: sprintf(
				/* translators: 1: plugin name of the second copy, 2: its file. */
				__( 'Installed twice: as a must-use plugin (always active), and as "%1$s" (%2$s), currently inactive. Do NOT activate the second copy — both would run and register the same post types, constants and shortcodes. Delete one.', 'qhta-healthcheck' ),
				$plugin['duplicate_name'],
				$plugin['duplicate_of']
			);

		return array(
			'plugin'  => $plugin,
			'status'  => $plugin['duplicate_active'] ? 'red' : 'amber',
			'note'    => $note,
			'results' => array(),
		);
	}

	if ( ! $checks ) {
		return array(
			'plugin'  => $plugin,
			'status'  => 'amber',
			'note'    => __( 'No canaries defined — this plugin has not been deployed with its own yet. Auto-discovery found it and is watching that it is present and active, but cannot invent a check for what it depends on. Canaries arrive with the plugin\'s next update.', 'qhta-healthcheck' ),
			'results' => array(),
		);
	}

	foreach ( $checks as $check ) {
		$id = $check['id'];

		$row = array(
			'label'    => $check['label'],
			'why'      => $check['why'],
			'severity' => $check['severity'],
			'remote'   => $check['remote'],
		);

		if ( $check['remote'] && ! $include_remote ) {
			// Carry the last scheduled answer forward rather than dropping to
			// "unknown" on every screen load — a day-old answer about a remote
			// service is worth considerably more than no answer, as long as the
			// screen says how old it is.
			$carried = isset( $previous[ $plugin['slug'] ]['results'][ $id ] ) ? $previous[ $plugin['slug'] ]['results'][ $id ] : null;

			$row['status'] = $carried ? $carried['status'] : 'unknown';
			$row['detail'] = $carried ? $carried['detail'] : __( 'Runs on the daily pass, or press Run checks now.', 'qhta-healthcheck' );
			$row['stale']  = true;
		} else {
			$outcome       = qhta_healthcheck_run_check( $check );
			$row['status'] = $outcome['status'];
			$row['detail'] = $outcome['detail'];
		}

		$results[ $id ] = $row;
		$notes[]        = $row['status'];
	}

	return array(
		'plugin'  => $plugin,
		'status'  => qhta_healthcheck_worst( $notes ),
		'note'    => '',
		'results' => $results,
	);
}

/**
 * Run the whole board.
 *
 * @param bool $include_remote Run the HTTP checks too.
 * @return array{time:int,remote:bool,status:string,plugins:array}
 */
function qhta_healthcheck_run_all( $include_remote = false ) {
	$previous = qhta_healthcheck_cached_run();
	$previous = isset( $previous['plugins'] ) ? $previous['plugins'] : array();

	$plugins  = array();
	$statuses = array();

	foreach ( qhta_healthcheck_watched_plugins() as $slug => $plugin ) {
		$plugins[ $slug ] = qhta_healthcheck_run_plugin( $plugin, $include_remote, $previous );
		$statuses[]       = $plugins[ $slug ]['status'];
	}

	return array(
		'time'    => time(),
		'remote'  => (bool) $include_remote,
		'status'  => qhta_healthcheck_worst( $statuses ),
		'plugins' => $plugins,
	);
}

/**
 * The last stored run, or an empty shell.
 *
 * @return array
 */
function qhta_healthcheck_cached_run() {
	$run = get_option( QHTA_HEALTHCHECK_RESULTS_OPTION, array() );

	if ( ! is_array( $run ) || ! isset( $run['plugins'] ) ) {
		return array(
			'time'    => 0,
			'remote'  => false,
			'status'  => 'unknown',
			'plugins' => array(),
		);
	}

	return $run;
}

/**
 * Store a run.
 *
 * autoload=no: two admin screens read this and nothing else does, so there is
 * no reason to carry it on every front-end request.
 *
 * Only a full run (remote included) is stored. A local-only run is what the
 * health screen does on every load; persisting those would overwrite the remote
 * answers with "unknown" the first time somebody opened the screen, and the
 * dashboard widget would go grey until the next nightly pass.
 *
 * @param array $run Run payload.
 * @return void
 */
function qhta_healthcheck_store_run( array $run ) {
	if ( empty( $run['remote'] ) ) {
		return;
	}

	update_option( QHTA_HEALTHCHECK_RESULTS_OPTION, $run, false );
}

/**
 * The scheduled full pass.
 *
 * @return void
 */
function qhta_healthcheck_cron_run() {
	qhta_healthcheck_store_run( qhta_healthcheck_run_all( true ) );
}
add_action( QHTA_HEALTHCHECK_CRON_HOOK, 'qhta_healthcheck_cron_run' );

/**
 * Count the checks in a run by status.
 *
 * @param array $run Run payload.
 * @return array<string,int>
 */
function qhta_healthcheck_tally( array $run ) {
	$tally = array_fill_keys( array_keys( qhta_healthcheck_statuses() ), 0 );

	foreach ( (array) $run['plugins'] as $plugin ) {
		foreach ( (array) $plugin['results'] as $result ) {
			if ( isset( $tally[ $result['status'] ] ) ) {
				$tally[ $result['status'] ]++;
			}
		}
	}

	return $tally;
}

/**
 * The failing checks across the whole run, worst first.
 *
 * Used by the dashboard widget, which has room for the headline and not the
 * board.
 *
 * @param array $run   Run payload.
 * @param int   $limit Maximum rows.
 * @return array[]
 */
function qhta_healthcheck_problems( array $run, $limit = 5 ) {
	$problems = array();

	foreach ( (array) $run['plugins'] as $slug => $plugin ) {
		if ( ! empty( $plugin['note'] ) && 'green' !== $plugin['status'] ) {
			$problems[] = array(
				'plugin' => $plugin['plugin']['name'],
				'label'  => $plugin['note'],
				'detail' => '',
				'status' => $plugin['status'],
			);
		}

		foreach ( (array) $plugin['results'] as $result ) {
			if ( in_array( $result['status'], array( 'red', 'amber' ), true ) ) {
				$problems[] = array(
					'plugin' => $plugin['plugin']['name'],
					'label'  => $result['label'],
					'detail' => $result['detail'],
					'status' => $result['status'],
				);
			}
		}
	}

	usort(
		$problems,
		function ( $a, $b ) {
			return qhta_healthcheck_status_rank( $b['status'] ) <=> qhta_healthcheck_status_rank( $a['status'] );
		}
	);

	return array_slice( $problems, 0, $limit );
}
