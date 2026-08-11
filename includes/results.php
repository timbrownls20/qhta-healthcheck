<?php
/**
 * The status vocabulary, and the primitives a canary returns.
 *
 * @package QHTA_Healthcheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The five states a check can be in.
 *
 * `red` and `amber` are the two failure states, and which one a failing check
 * lands in comes from the check's own severity rather than from the assertion —
 * "wc_get_orders() is missing" is critical to qhta-revenue and merely a warning
 * to the plugin that only uses it to draw a banner. Same assertion, different
 * consequence, so severity belongs to the check.
 *
 * `skipped` is for a check that cannot meaningfully run — its plugin is
 * inactive, or the optional system it tests is not installed here. Skipped
 * never colours the roll-up. This matters: without it, deactivating one plugin
 * would light up every screen in red and the dashboard would be ignored within
 * a week.
 *
 * `unknown` is for a check that has not run yet (a remote check with no cached
 * result). It reads as "no answer", not "no problem", and is shown as such.
 *
 * @return array<string,string> Status key => human label.
 */
function qhta_healthcheck_statuses() {
	return array(
		'red'     => __( 'Failed', 'qhta-healthcheck' ),
		'amber'   => __( 'Warning', 'qhta-healthcheck' ),
		'green'   => __( 'OK', 'qhta-healthcheck' ),
		'unknown' => __( 'Not yet run', 'qhta-healthcheck' ),
		'skipped' => __( 'Skipped', 'qhta-healthcheck' ),
	);
}

/**
 * Rank a status for sorting and roll-up. Higher is worse.
 *
 * @param string $status Status key.
 * @return int
 */
function qhta_healthcheck_status_rank( $status ) {
	$rank = array(
		'green'   => 0,
		'skipped' => 1,
		'unknown' => 2,
		'amber'   => 3,
		'red'     => 4,
	);

	return isset( $rank[ $status ] ) ? $rank[ $status ] : 2;
}

/**
 * The worst status in a list — the roll-up.
 *
 * `unknown` deliberately ranks below `amber`: a remote check that has not run
 * yet should not be reported with the same weight as a dependency that is
 * actually missing.
 *
 * @param string[] $statuses Status keys.
 * @return string
 */
function qhta_healthcheck_worst( array $statuses ) {
	$worst = 'green';

	foreach ( $statuses as $status ) {
		if ( qhta_healthcheck_status_rank( $status ) > qhta_healthcheck_status_rank( $worst ) ) {
			$worst = $status;
		}
	}

	return $worst;
}

/**
 * A passing canary.
 *
 * The detail is not decoration. "OK" on its own tells a reader nothing they can
 * act on six months later, so the assertions below all report *what* they found
 * — the version, the row count, the resolved path — which is what turns this
 * screen into something you can diff against last month.
 *
 * @param string $detail What was found.
 * @return array{ok:bool,detail:string}
 */
function qhta_healthcheck_pass( $detail = '' ) {
	return array(
		'ok'     => true,
		'detail' => (string) $detail,
	);
}

/**
 * A failing canary.
 *
 * Whether this ends up red or amber is decided by the check's severity, not
 * here.
 *
 * @param string $detail What was missing, and where it was looked for.
 * @return array{ok:bool,detail:string}
 */
function qhta_healthcheck_fail( $detail = '' ) {
	return array(
		'ok'     => false,
		'detail' => (string) $detail,
	);
}

/**
 * A canary that could not run.
 *
 * @param string $detail Why not.
 * @return array{ok:null,detail:string}
 */
function qhta_healthcheck_skip( $detail = '' ) {
	return array(
		'ok'     => null,
		'detail' => (string) $detail,
	);
}

/**
 * Normalise whatever a check's `test` returned into a result row.
 *
 * A test may return one of the three helpers above, a bare bool (for the
 * one-liners where a detail line would only restate the label), or a WP_Error
 * (so a test can hand back an HTTP failure without unwrapping it first).
 * Anything else is a programming error in the check, and is reported as one
 * rather than being quietly treated as a pass — a check that cannot say whether
 * it passed has not passed.
 *
 * @param mixed  $raw      Whatever the test returned.
 * @param string $severity 'critical' or 'warning'.
 * @return array{status:string,detail:string}
 */
function qhta_healthcheck_normalise_result( $raw, $severity ) {
	$failed = ( 'critical' === $severity ) ? 'red' : 'amber';

	if ( is_wp_error( $raw ) ) {
		return array(
			'status' => $failed,
			'detail' => $raw->get_error_message(),
		);
	}

	if ( is_bool( $raw ) ) {
		return array(
			'status' => $raw ? 'green' : $failed,
			'detail' => '',
		);
	}

	if ( is_array( $raw ) && array_key_exists( 'ok', $raw ) ) {
		if ( null === $raw['ok'] ) {
			$status = 'skipped';
		} else {
			$status = $raw['ok'] ? 'green' : $failed;
		}

		return array(
			'status' => $status,
			'detail' => isset( $raw['detail'] ) ? (string) $raw['detail'] : '',
		);
	}

	return array(
		'status' => $failed,
		'detail' => __( 'Check returned an unusable value — fix the check.', 'qhta-healthcheck' ),
	);
}
