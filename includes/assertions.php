<?php
/**
 * The canary primitives.
 *
 * Every one of these answers a question about somebody else's system and
 * returns a result row. None of them writes anything: no option is created, no
 * meta is touched, no remote call is anything but a GET. That is the whole
 * contract of this file — if an assertion here ever needs to change state to
 * find its answer, the answer is not worth having.
 *
 * @package QHTA_Healthcheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is a function callable?
 *
 * The workhorse. Almost every QHTA plugin guards its integration with
 * function_exists(), which is exactly why this is the most common canary: the
 * guard is what makes the failure silent, so this is what makes it loud.
 *
 * @param string|string[] $functions One or more function names; all must exist.
 * @return array
 */
function qhta_healthcheck_assert_functions( $functions ) {
	$functions = (array) $functions;
	$missing   = array();

	foreach ( $functions as $function ) {
		if ( ! function_exists( $function ) ) {
			$missing[] = $function . '()';
		}
	}

	if ( $missing ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: %s: comma-separated function names. */
				__( 'Not callable: %s', 'qhta-healthcheck' ),
				implode( ', ', $missing )
			)
		);
	}

	return qhta_healthcheck_pass(
		sprintf(
			/* translators: %d: number of functions. */
			_n( '%d function present', '%d functions present', count( $functions ), 'qhta-healthcheck' ),
			count( $functions )
		)
	);
}

/**
 * Is a class loadable?
 *
 * @param string|string[] $classes One or more class names; all must exist.
 * @return array
 */
function qhta_healthcheck_assert_classes( $classes ) {
	$classes = (array) $classes;
	$missing = array();

	foreach ( $classes as $class ) {
		if ( ! class_exists( $class ) ) {
			$missing[] = $class;
		}
	}

	if ( $missing ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: %s: comma-separated class names. */
				__( 'Not loadable: %s', 'qhta-healthcheck' ),
				implode( ', ', $missing )
			)
		);
	}

	return qhta_healthcheck_pass( implode( ', ', $classes ) );
}

/**
 * Is a constant defined and non-empty?
 *
 * Non-empty rather than merely defined, because the site's configuration
 * constants live in wp-config.php and the failure mode that actually happens is
 * a key that got emptied during a host migration, not one that got deleted.
 *
 * The value is never reported — several of these are credentials.
 *
 * @param string $constant Constant name.
 * @return array
 */
function qhta_healthcheck_assert_constant( $constant ) {
	if ( ! defined( $constant ) ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: %s: constant name. */
				__( '%s is not defined (expected in wp-config.php)', 'qhta-healthcheck' ),
				$constant
			)
		);
	}

	if ( '' === (string) constant( $constant ) ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: %s: constant name. */
				__( '%s is defined but empty', 'qhta-healthcheck' ),
				$constant
			)
		);
	}

	return qhta_healthcheck_pass(
		sprintf(
			/* translators: %s: constant name. */
			__( '%s is defined', 'qhta-healthcheck' ),
			$constant
		)
	);
}

/**
 * Does a table exist, with the columns we read by name?
 *
 * qhta-revenue reads wp_pmpro_membership_orders directly, so both halves matter
 * — a table that survives with a renamed column produces an empty report rather
 * than an error, which is the worst possible outcome for an income figure.
 *
 * @param string   $table   Table name WITHOUT the wpdb prefix.
 * @param string[] $columns Columns that must be present.
 * @return array
 */
function qhta_healthcheck_assert_table( $table, array $columns = array() ) {
	global $wpdb;

	$full = $wpdb->prefix . $table;

	// esc_like() because the prefix contains underscores, which LIKE reads as
	// single-character wildcards.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full ) ) );

	if ( $found !== $full ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: %s: table name. */
				__( 'Table %s does not exist', 'qhta-healthcheck' ),
				$full
			)
		);
	}

	if ( ! $columns ) {
		return qhta_healthcheck_pass( $full );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder; it is built from $wpdb->prefix and a literal above.
	$present = $wpdb->get_col( "SHOW COLUMNS FROM `{$full}`" );
	$present = array_map( 'strtolower', (array) $present );
	$missing = array();

	foreach ( $columns as $column ) {
		if ( ! in_array( strtolower( $column ), $present, true ) ) {
			$missing[] = $column;
		}
	}

	if ( $missing ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: 1: table name, 2: comma-separated column names. */
				__( '%1$s is missing column(s): %2$s', 'qhta-healthcheck' ),
				$full,
				implode( ', ', $missing )
			)
		);
	}

	return qhta_healthcheck_pass(
		sprintf(
			/* translators: 1: table name, 2: number of columns. */
			__( '%1$s — %2$d expected columns present', 'qhta-healthcheck' ),
			$full,
			count( $columns )
		)
	);
}

/**
 * Is one of our callbacks still attached to a hook?
 *
 * Weaker than proving the effect, and used only where the effect cannot be
 * observed from wp-admin — you cannot ask "did the invoice attach itself to
 * that email" without sending an email. What it does catch is the case that
 * actually bites on a site with several plugins hooking the same filters: a
 * callback silently dropped, or a hook renamed upstream so our add_filter()
 * lands on a name nothing fires any more.
 *
 * Pass $callback = null to assert only that *somebody* is listening, which is
 * the best available answer when the callback is a closure or an object method.
 *
 * @param string      $hook     Hook name.
 * @param string|null $callback Function name, or null for "any listener".
 * @return array
 */
function qhta_healthcheck_assert_hooked( $hook, $callback = null ) {
	if ( null === $callback ) {
		if ( ! has_filter( $hook ) ) {
			return qhta_healthcheck_fail(
				sprintf(
					/* translators: %s: hook name. */
					__( 'Nothing is listening on %s', 'qhta-healthcheck' ),
					$hook
				)
			);
		}

		return qhta_healthcheck_pass(
			sprintf(
				/* translators: %s: hook name. */
				__( '%s has at least one listener', 'qhta-healthcheck' ),
				$hook
			)
		);
	}

	$priority = has_filter( $hook, $callback );

	if ( false === $priority ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: 1: callback name, 2: hook name. */
				__( '%1$s() is not attached to %2$s', 'qhta-healthcheck' ),
				$callback,
				$hook
			)
		);
	}

	return qhta_healthcheck_pass(
		sprintf(
			/* translators: 1: hook name, 2: priority. */
			__( '%1$s (priority %2$d)', 'qhta-healthcheck' ),
			$hook,
			(int) $priority
		)
	);
}

/**
 * Is a shortcode registered?
 *
 * @param string $tag Shortcode tag, without brackets.
 * @return array
 */
function qhta_healthcheck_assert_shortcode( $tag ) {
	if ( ! shortcode_exists( $tag ) ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: %s: shortcode tag. */
				__( '[%s] is not registered', 'qhta-healthcheck' ),
				$tag
			)
		);
	}

	return qhta_healthcheck_pass( '[' . $tag . ']' );
}

/**
 * Is a shortcode actually used by a published page or post?
 *
 * A registered shortcode nobody has placed renders nothing, and looks identical
 * to a working one from the admin side. This is the canary for the deployment
 * half of the dependency: the plugin is fine, but the page it was built for was
 * rebuilt in the editor and the shortcode went with it.
 *
 * Amber-severity by convention at the call site — it is a content problem, not
 * a code one.
 *
 * @param string $tag Shortcode tag.
 * @return array
 */
function qhta_healthcheck_assert_shortcode_in_use( $tag ) {
	global $wpdb;

	$like = '%' . $wpdb->esc_like( '[' . $tag ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no caching layer for a once-daily integrity probe.
	$found = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT post_title FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type IN ( 'page', 'post' )
			   AND post_content LIKE %s
			 LIMIT 1",
			$like
		)
	);

	if ( ! $found ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: %s: shortcode tag. */
				__( 'No published page or post contains [%s]', 'qhta-healthcheck' ),
				$tag
			)
		);
	}

	return qhta_healthcheck_pass(
		sprintf(
			/* translators: 1: shortcode tag, 2: page title. */
			__( '[%1$s] found on "%2$s"', 'qhta-healthcheck' ),
			$tag,
			$found
		)
	);
}

/**
 * Is a meta key still carrying data?
 *
 * The canary for a renamed or orphaned meta key. Zero rows does not prove the
 * key is wrong — a brand new site legitimately has none — so this is always a
 * warning at the call site, phrased as "nothing is using this" rather than
 * "this is broken".
 *
 * @param string $meta_key Meta key.
 * @param string $type     'post' or 'user'.
 * @return array
 */
function qhta_healthcheck_assert_meta_in_use( $meta_key, $type = 'post' ) {
	global $wpdb;

	$table  = ( 'user' === $type ) ? $wpdb->usermeta : $wpdb->postmeta;
	$column = ( 'user' === $type ) ? 'umeta_id' : 'meta_id';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers come from $wpdb, not from input.
	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT({$column}) FROM {$table} WHERE meta_key = %s AND meta_value != ''",
			$meta_key
		)
	);

	if ( 0 === $count ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: %s: meta key. */
				__( 'No rows carry %s — either nothing uses the feature, or the key has drifted', 'qhta-healthcheck' ),
				$meta_key
			)
		);
	}

	return qhta_healthcheck_pass(
		sprintf(
			/* translators: 1: number of rows, 2: meta key. */
			_n( '%1$d row carries %2$s', '%1$d rows carry %2$s', $count, 'qhta-healthcheck' ),
			$count,
			$meta_key
		)
	);
}

/**
 * Is a WooCommerce order meta key in use?
 *
 * Separate from the postmeta version because under HPOS the orders are not in
 * wp_posts and their meta is not in wp_postmeta — asking postmeta would return
 * zero on a perfectly healthy site, which is exactly the false alarm that
 * teaches people to ignore this screen. wc_get_orders() is used instead, so the
 * answer is right on both storage backends.
 *
 * @param string $meta_key Order meta key.
 * @param string $status   Order status to sample.
 * @return array
 */
function qhta_healthcheck_assert_order_meta_in_use( $meta_key, $status = 'completed' ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return qhta_healthcheck_skip( __( 'WooCommerce is not active', 'qhta-healthcheck' ) );
	}

	$orders = wc_get_orders(
		array(
			'limit'   => 20,
			'status'  => $status,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		)
	);

	// wc_get_orders() returns an array for these arguments, but returns an
	// object when paginating and can be filtered by other plugins — so the shape
	// is confirmed rather than assumed. A canary that emits a PHP warning while
	// reporting on somebody else's health is not in a position to be believed.
	if ( ! is_array( $orders ) || ! $orders ) {
		return qhta_healthcheck_skip(
			sprintf(
				/* translators: %s: order status. */
				__( 'No %s orders to sample yet', 'qhta-healthcheck' ),
				$status
			)
		);
	}

	$with = 0;

	foreach ( $orders as $order ) {
		if ( '' !== (string) $order->get_meta( $meta_key ) ) {
			$with++;
		}
	}

	if ( 0 === $with ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: 1: meta key, 2: number of orders sampled, 3: order status. */
				__( 'None of the last %2$d %3$s orders carry %1$s', 'qhta-healthcheck' ),
				$meta_key,
				count( $orders ),
				$status
			)
		);
	}

	return qhta_healthcheck_pass(
		sprintf(
			/* translators: 1: number with meta, 2: number sampled, 3: meta key. */
			__( '%1$d of the last %2$d orders carry %3$s', 'qhta-healthcheck' ),
			$with,
			count( $orders ),
			$meta_key
		)
	);
}

/**
 * Is a cron event scheduled, and is WP-Cron actually running it?
 *
 * Two failures in one, because they need the same lookup and have the same
 * consequence. An event that was never scheduled and an event scheduled for a
 * time that passed six hours ago both mean the same thing to the plugin that
 * depends on it: the refresh is not happening.
 *
 * The overdue window is generous on purpose. WP-Cron fires on page loads, so a
 * quiet site legitimately runs a "15 minute" event late, and a tight threshold
 * would flap.
 *
 * @param string $hook            Cron hook name.
 * @param int    $overdue_seconds How late is too late.
 * @return array
 */
function qhta_healthcheck_assert_cron( $hook, $overdue_seconds = HOUR_IN_SECONDS * 6 ) {
	$next = wp_next_scheduled( $hook );

	if ( ! $next ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: %s: cron hook name. */
				__( '%s is not scheduled', 'qhta-healthcheck' ),
				$hook
			)
		);
	}

	$late = time() - $next;

	if ( $late > $overdue_seconds ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: 1: cron hook, 2: human-readable duration. */
				__( '%1$s was due %2$s ago — WP-Cron may be stalled', 'qhta-healthcheck' ),
				$hook,
				human_time_diff( $next, time() )
			)
		);
	}

	return qhta_healthcheck_pass(
		sprintf(
			/* translators: 1: cron hook, 2: human-readable duration. */
			__( '%1$s next runs in %2$s', 'qhta-healthcheck' ),
			$hook,
			human_time_diff( time(), $next )
		)
	);
}

/**
 * Is a custom cron schedule registered?
 *
 * wp_schedule_event() against an unregistered interval fails, and the event
 * simply never reappears after it next fires. Silent, and only visible weeks
 * later as stale content.
 *
 * @param string $name Schedule key, e.g. 'fifteen_minutes'.
 * @return array
 */
function qhta_healthcheck_assert_cron_schedule( $name ) {
	$schedules = wp_get_schedules();

	if ( ! isset( $schedules[ $name ] ) ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: %s: cron schedule name. */
				__( "Cron schedule '%s' is not registered", 'qhta-healthcheck' ),
				$name
			)
		);
	}

	return qhta_healthcheck_pass(
		sprintf(
			/* translators: 1: schedule name, 2: interval in seconds. */
			__( "'%1\$s' registered (%2\$d seconds)", 'qhta-healthcheck' ),
			$name,
			(int) $schedules[ $name ]['interval']
		)
	);
}

/**
 * Is an option present and non-empty?
 *
 * @param string $option Option name.
 * @param string $label  What the option is for, for the detail line.
 * @return array
 */
function qhta_healthcheck_assert_option( $option, $label = '' ) {
	$value = get_option( $option, null );

	if ( null === $value || '' === $value || array() === $value ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: 1: option name, 2: what it configures. */
				__( 'Option %1$s is unset or empty%2$s', 'qhta-healthcheck' ),
				$option,
				$label ? ' — ' . $label : ''
			)
		);
	}

	return qhta_healthcheck_pass( $option );
}

/**
 * Is a file present and readable?
 *
 * @param string $path  Absolute path.
 * @param string $label What the file is.
 * @return array
 */
function qhta_healthcheck_assert_file( $path, $label = '' ) {
	if ( ! file_exists( $path ) ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: 1: what the file is, 2: path. */
				__( '%1$s missing: %2$s', 'qhta-healthcheck' ),
				$label ? $label : __( 'File', 'qhta-healthcheck' ),
				qhta_healthcheck_relative_path( $path )
			)
		);
	}

	if ( ! is_readable( $path ) ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: %s: path. */
				__( 'Not readable: %s', 'qhta-healthcheck' ),
				qhta_healthcheck_relative_path( $path )
			)
		);
	}

	return qhta_healthcheck_pass( qhta_healthcheck_relative_path( $path ) );
}

/**
 * Trim an absolute path down to something readable on screen.
 *
 * Absolute server paths are noise on a monitoring screen and, on a shared host,
 * mildly indiscreet.
 *
 * @param string $path Absolute path.
 * @return string
 */
function qhta_healthcheck_relative_path( $path ) {
	$root = defined( 'ABSPATH' ) ? ABSPATH : '';

	if ( $root && 0 === strpos( $path, $root ) ) {
		return substr( $path, strlen( $root ) );
	}

	return $path;
}

/**
 * Is a page published at a given path?
 *
 * Several plugins redirect to a hardcoded path — qhta-commerce sends both
 * logged-out and unentitled visitors to /login/. If that page is unpublished or
 * renamed, the gate redirects buyers into a 404 and nothing in the code notices,
 * because the redirect itself worked perfectly.
 *
 * @param string $path Site-relative path, e.g. '/login/'.
 * @return array
 */
function qhta_healthcheck_assert_page_at( $path ) {
	$page_id = url_to_postid( home_url( $path ) );

	if ( ! $page_id ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: %s: path. */
				__( 'No published page resolves at %s', 'qhta-healthcheck' ),
				$path
			)
		);
	}

	$status = get_post_status( $page_id );

	if ( 'publish' !== $status ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: 1: path, 2: post status. */
				__( '%1$s exists but is "%2$s", not published', 'qhta-healthcheck' ),
				$path,
				$status
			)
		);
	}

	return qhta_healthcheck_pass(
		sprintf(
			/* translators: 1: path, 2: page title. */
			__( '%1$s → "%2$s"', 'qhta-healthcheck' ),
			$path,
			get_the_title( $page_id )
		)
	);
}

/**
 * Is a WooCommerce system page configured?
 *
 * wc_get_page_id() returns -1 for a page that was never set, and 0 for one that
 * was set and then deleted. Both are the same failure to a plugin that links to
 * it.
 *
 * @param string $key WooCommerce page key: shop, cart, checkout, myaccount.
 * @return array
 */
function qhta_healthcheck_assert_wc_page( $key ) {
	if ( ! function_exists( 'wc_get_page_id' ) ) {
		return qhta_healthcheck_skip( __( 'WooCommerce is not active', 'qhta-healthcheck' ) );
	}

	$id = (int) wc_get_page_id( $key );

	if ( $id <= 0 || 'publish' !== get_post_status( $id ) ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: %s: WooCommerce page key. */
				__( 'WooCommerce "%s" page is not set or not published', 'qhta-healthcheck' ),
				$key
			)
		);
	}

	return qhta_healthcheck_pass(
		sprintf(
			/* translators: 1: page key, 2: page title. */
			__( '%1$s → "%2$s"', 'qhta-healthcheck' ),
			$key,
			get_the_title( $id )
		)
	);
}

/**
 * Is a WooCommerce My Account endpoint reachable?
 *
 * Two things have to be true and they fail independently: WooCommerce must know
 * the endpoint (so it routes), and the rewrite rules must have been flushed
 * since it was added (so the URL resolves). The second is the one that actually
 * happens — a deploy that adds an endpoint without a flush produces a 404 on a
 * tab that is visibly present in the account menu.
 *
 * @param string $endpoint Endpoint slug.
 * @return array
 */
function qhta_healthcheck_assert_account_endpoint( $endpoint ) {
	if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
		return qhta_healthcheck_skip( __( 'WooCommerce is not active', 'qhta-healthcheck' ) );
	}

	$rules   = get_option( 'rewrite_rules', array() );
	$matched = false;

	foreach ( (array) $rules as $pattern => $target ) {
		if ( false !== strpos( $pattern, $endpoint ) ) {
			$matched = true;
			break;
		}
	}

	if ( ! $matched ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: %s: endpoint slug. */
				__( 'No rewrite rule mentions "%s" — flush permalinks (deactivate/reactivate the plugin)', 'qhta-healthcheck' ),
				$endpoint
			)
		);
	}

	return qhta_healthcheck_pass( wc_get_account_endpoint_url( $endpoint ) );
}

/**
 * Is the active theme the one a plugin styles against?
 *
 * @param string $expected Theme stylesheet/template slug, e.g. 'astra'.
 * @return array
 */
function qhta_healthcheck_assert_theme( $expected ) {
	$theme = wp_get_theme();

	if ( $expected !== $theme->get_template() ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: 1: expected theme slug, 2: active theme name. */
				__( 'Expected the %1$s theme; active theme is "%2$s"', 'qhta-healthcheck' ),
				$expected,
				$theme->get( 'Name' )
			)
		);
	}

	return qhta_healthcheck_pass(
		sprintf(
			/* translators: 1: theme name, 2: version. */
			__( '%1$s %2$s', 'qhta-healthcheck' ),
			$theme->get( 'Name' ),
			$theme->get( 'Version' )
		)
	);
}

/**
 * Fetch a front-end URL and look for markup that must be there.
 *
 * This is the only class of canary that can answer the questions the others
 * cannot: does our stylesheet actually get enqueued on the front end, and is
 * the checkout form still built out of the DOM selectors a plugin reaches into?
 * A CSS rule targeting `.pmpro_form_field-username` and a script looking up
 * `#bemail` are dependencies on somebody else's markup, and no amount of PHP
 * introspection will tell you the markup changed.
 *
 * It is deliberately a GET, deliberately logged-out, and deliberately only run
 * on the scheduled pass rather than on every screen load.
 *
 * Note what a failure here means: the needle was not found in the HTML *as
 * served to a logged-out visitor*. A page behind a login or a cache that serves
 * something else will fail honestly rather than pretending to pass, which is
 * why the detail line always names the URL.
 *
 * @param string   $url      Absolute URL to fetch.
 * @param string[] $needles  Substrings that must all appear in the response body.
 * @param string   $label    What is being proven, for the detail line.
 * @return array
 */
function qhta_healthcheck_assert_markup_contains( $url, array $needles, $label = '' ) {
	if ( ! $url ) {
		return qhta_healthcheck_skip( __( 'No URL to check', 'qhta-healthcheck' ) );
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 15,
			'redirection' => 3,
			'user-agent'  => 'QHTA Healthcheck/' . QHTA_HEALTHCHECK_VERSION,
			// Loopback requests to one's own host are routine here; a host that
			// blocks them will surface as a WP_Error below rather than silently.
			'sslverify'   => true,
		)
	);

	if ( is_wp_error( $response ) ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: 1: URL, 2: error message. */
				__( 'Could not fetch %1$s — %2$s', 'qhta-healthcheck' ),
				$url,
				$response->get_error_message()
			)
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $code ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: 1: URL, 2: HTTP status code. */
				__( '%1$s returned HTTP %2$d', 'qhta-healthcheck' ),
				$url,
				$code
			)
		);
	}

	$body    = (string) wp_remote_retrieve_body( $response );
	$missing = array();

	foreach ( $needles as $needle ) {
		if ( false === strpos( $body, $needle ) ) {
			$missing[] = $needle;
		}
	}

	if ( $missing ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: 1: what was being proven, 2: comma-separated needles, 3: URL. */
				__( '%1$s not found (%2$s) in the HTML served at %3$s', 'qhta-healthcheck' ),
				$label ? $label : __( 'Expected markup', 'qhta-healthcheck' ),
				implode( ', ', $missing ),
				$url
			)
		);
	}

	return qhta_healthcheck_pass(
		sprintf(
			/* translators: 1: number of markers, 2: URL. */
			_n( '%1$d marker present at %2$s', '%1$d markers present at %2$s', count( $needles ), 'qhta-healthcheck' ),
			count( $needles ),
			$url
		)
	);
}

/**
 * GET a third-party API endpoint and check it answers.
 *
 * Credential-shaped canary: it proves the key still works, which neither
 * "constant is defined" nor "option is non-empty" can. Kept to endpoints that
 * are free, idempotent and boring — Mailchimp's /ping, Stripe's /balance — and
 * only run once a day.
 *
 * @param string $url     Absolute API URL.
 * @param array  $headers Request headers, typically Authorization.
 * @param string $label   Service name for the detail line.
 * @return array
 */
function qhta_healthcheck_assert_api_reachable( $url, array $headers, $label ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 15,
			'headers' => $headers,
		)
	);

	if ( is_wp_error( $response ) ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: 1: service name, 2: error message. */
				__( '%1$s unreachable — %2$s', 'qhta-healthcheck' ),
				$label,
				$response->get_error_message()
			)
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( 401 === $code || 403 === $code ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: 1: service name, 2: HTTP status. */
				__( '%1$s rejected the credentials (HTTP %2$d)', 'qhta-healthcheck' ),
				$label,
				$code
			)
		);
	}

	if ( 200 !== $code ) {
		return qhta_healthcheck_fail(
			sprintf(
				/* translators: 1: service name, 2: HTTP status. */
				__( '%1$s returned HTTP %2$d', 'qhta-healthcheck' ),
				$label,
				$code
			)
		);
	}

	return qhta_healthcheck_pass(
		sprintf(
			/* translators: %s: service name. */
			__( '%s answered with HTTP 200', 'qhta-healthcheck' ),
			$label
		)
	);
}
