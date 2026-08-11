<?php
/**
 * The dashboard widget — where this plugin asks for attention.
 *
 * @package QHTA_Healthcheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the widget.
 *
 * @return void
 */
function qhta_healthcheck_add_dashboard_widget() {
	if ( ! current_user_can( qhta_healthcheck_capability() ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'qhta_healthcheck_widget',
		__( 'QHTA Health', 'qhta-healthcheck' ),
		'qhta_healthcheck_render_dashboard_widget'
	);
}
add_action( 'wp_dashboard_setup', 'qhta_healthcheck_add_dashboard_widget' );

/**
 * Render it.
 *
 * Local checks are re-run live here too. The dashboard is the one screen every
 * administrator loads without being asked to, so a stale green in this widget
 * would be worse than no widget: it is the thing that decides whether anyone
 * ever opens the full board. The local checks are all in-process lookups, which
 * is cheap enough to pay for on a dashboard load; the remote ones are carried
 * from the last scheduled pass, with their age stated rather than hidden.
 *
 * @return void
 */
function qhta_healthcheck_render_dashboard_widget() {
	$run   = qhta_healthcheck_run_all( false );
	$tally = qhta_healthcheck_tally( $run );

	$headline = array(
		'red'     => __( 'Something is broken', 'qhta-healthcheck' ),
		'amber'   => __( 'Needs a look', 'qhta-healthcheck' ),
		'green'   => __( 'All clear', 'qhta-healthcheck' ),
		'unknown' => __( 'Not yet run', 'qhta-healthcheck' ),
		'skipped' => __( 'Nothing to check', 'qhta-healthcheck' ),
	);

	echo '<div class="qhta-hc-widget qhta-hc-status-' . esc_attr( $run['status'] ) . '">';

	echo '<p class="qhta-hc-widget-headline">';
	echo '<span class="qhta-hc-dot" aria-hidden="true"></span>';
	echo '<strong>' . esc_html( isset( $headline[ $run['status'] ] ) ? $headline[ $run['status'] ] : $run['status'] ) . '</strong>';
	echo '</p>';

	$problems = qhta_healthcheck_problems( $run, 5 );

	if ( $problems ) {
		echo '<ul class="qhta-hc-widget-list">';

		foreach ( $problems as $problem ) {
			echo '<li class="qhta-hc-status-' . esc_attr( $problem['status'] ) . '">';
			echo '<span class="qhta-hc-dot" aria-hidden="true"></span>';
			echo '<strong>' . esc_html( $problem['plugin'] ) . '</strong> — ' . esc_html( $problem['label'] );

			if ( $problem['detail'] ) {
				echo '<br><span class="qhta-hc-muted">' . esc_html( $problem['detail'] ) . '</span>';
			}

			echo '</li>';
		}

		echo '</ul>';

		$remaining = (int) $tally['red'] + (int) $tally['amber'] - count( $problems );

		if ( $remaining > 0 ) {
			echo '<p class="qhta-hc-muted">';
			printf(
				/* translators: %d: number of further findings. */
				esc_html( _n( '%d further finding on the full board.', '%d further findings on the full board.', $remaining, 'qhta-healthcheck' ) ),
				(int) $remaining
			);
			echo '</p>';
		}
	} else {
		echo '<p class="qhta-hc-muted">';
		printf(
			/* translators: 1: number of passing checks, 2: number of plugins. */
			esc_html__( '%1$d checks passing across %2$d QHTA plugins.', 'qhta-healthcheck' ),
			(int) $tally['green'],
			count( $run['plugins'] )
		);
		echo '</p>';
	}

	echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=' . QHTA_HEALTHCHECK_SLUG ) ) . '">' . esc_html__( 'Open the full board', 'qhta-healthcheck' ) . '</a></p>';

	echo '</div>';
}
