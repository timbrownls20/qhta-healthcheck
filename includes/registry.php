<?php
/**
 * Discovery of the watched plugins, and the registry of canaries against them.
 *
 * @package QHTA_Healthcheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turn a plugin file path into a stable slug.
 *
 * get_plugins() keys look like `qhta-commerce/qhta-commerce.php` for a foldered
 * plugin and `regenerate_invoice.php` for a single-file one. The folder is the
 * slug where there is one, because that is what everybody calls the plugin and
 * what a filter would name.
 *
 * @param string $file Plugin file, relative to the plugins directory.
 * @return string
 */
function qhta_healthcheck_plugin_slug( $file ) {
	$dir = dirname( $file );

	return ( '.' === $dir ) ? basename( $file, '.php' ) : $dir;
}

/**
 * Alternative slugs a canonical plugin may be installed under.
 *
 * Two of the QHTA plugins do not live where their name says they do:
 *
 *   - The conference program plugin is developed in a repo called
 *     qhta-conference-plugin but installs as `htaa-conference` — it is the HTAA
 *     national conference's program, not a QHTA one. Its slug therefore does
 *     not start with "qhta" and auto-discovery cannot see it.
 *   - The PMPro invoice work is a single-file plugin whose install folder has
 *     varied ("QHTA Regenerate Invoice" is its plugin name, not its slug).
 *
 * Rather than let either fall off the screen, the registry names them
 * canonically and this map says where else to look. The alternative — telling
 * people to rename an installed plugin so the monitor can find it — gets the
 * dependency exactly backwards.
 *
 * @return array<string,string[]> Canonical slug => alternative slugs.
 */
function qhta_healthcheck_slug_aliases() {
	/**
	 * Filter the canonical-slug → installed-slug aliases.
	 *
	 * @param array $aliases Canonical slug => array of alternative slugs.
	 */
	return (array) apply_filters(
		'qhta_healthcheck_slug_aliases',
		array(
			'qhta-conference-plugin'        => array( 'htaa-conference', 'qhta-conference' ),
			'qhta-pmpro-invoice-extensions' => array(
				'pmpro-invoice-extensions',
				'qhta-regenerate-invoice',
				'regenerate_invoice',
			),
		)
	);
}

/**
 * Plugin *names* that identify a canonical plugin, whatever its folder is called.
 *
 * The third and last way of recognising a plugin, and the one that finally works
 * when the other two do not.
 *
 * Matching on the folder assumes you know what the folder is called. That
 * assumption failed in production: the conference program was reported "expected
 * on this site, but not installed" while sitting plainly in the plugins list,
 * because it had been installed under neither its canonical slug nor either of
 * the alternatives guessed for it. A folder name is decided at upload time by
 * whoever unzipped it, and can be anything.
 *
 * The plugin *header name* is different in kind: it is a property of the code
 * itself, it travels with the plugin, and it is the thing a human reads on the
 * Plugins screen. Matching on it makes recognition independent of where the
 * plugin happens to have been put.
 *
 * Old names are kept alongside new ones so a rename does not lose the plugin
 * from the board on the way past — the site keeps running the old name until the
 * new build is actually deployed.
 *
 * @return array<string,string[]> Canonical slug => plugin header names.
 */
function qhta_healthcheck_plugin_names() {
	/**
	 * Filter the canonical-slug → plugin-name map.
	 *
	 * @param array $names Canonical slug => array of plugin header names.
	 */
	return (array) apply_filters(
		'qhta_healthcheck_plugin_names',
		array(
			'qhta-conference-plugin'        => array(
				'QHTA Conference Program',
				'HTAA Conference Program',
			),
			'qhta-pmpro-invoice-extensions' => array(
				'QHTA Regenerate Invoice',
				'QHTA PMPro Invoice Extensions',
			),
		)
	);
}

/**
 * Find how a canonical plugin is actually installed, if it is.
 *
 * Three ways of asking, cheapest and most certain first:
 *
 *   1. A folder matching the canonical slug.
 *   2. A folder matching one of its known alternatives.
 *   3. A plugin whose header Name identifies it, wherever it lives.
 *
 * Returning the installed slug rather than a bool is what lets the board say
 * "installed as …", which is the fastest way to find out what a plugin's folder
 * is actually called without a shell.
 *
 * @param string $slug Canonical plugin slug.
 * @return string Installed slug, or '' when not installed.
 */
function qhta_healthcheck_resolve_installed( $slug ) {
	$installed = qhta_healthcheck_installed_plugins();

	if ( isset( $installed[ $slug ] ) ) {
		return $slug;
	}

	$aliases = qhta_healthcheck_slug_aliases();

	if ( isset( $aliases[ $slug ] ) ) {
		foreach ( (array) $aliases[ $slug ] as $alternative ) {
			if ( isset( $installed[ $alternative ] ) ) {
				return $alternative;
			}
		}
	}

	$names = qhta_healthcheck_plugin_names();

	if ( isset( $names[ $slug ] ) ) {
		foreach ( (array) $names[ $slug ] as $name ) {
			foreach ( $installed as $installed_slug => $data ) {
				if ( isset( $data['Name'] ) && 0 === strcasecmp( (string) $data['Name'], $name ) ) {
					return $installed_slug;
				}
			}
		}
	}

	return '';
}

/**
 * Every installed plugin, keyed by slug.
 *
 * Cached per request — get_plugins() reads and parses the header of every file
 * in wp-content/plugins, and the answer cannot change mid-request.
 *
 * @return array<string,array> Slug => plugin data, with 'file' and 'active' added.
 */
function qhta_healthcheck_installed_plugins() {
	static $plugins = null;

	if ( null !== $plugins ) {
		return $plugins;
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugins = array();

	foreach ( get_plugins() as $file => $data ) {
		$slug = qhta_healthcheck_plugin_slug( $file );

		$data['file']   = $file;
		$data['slug']   = $slug;
		$data['active'] = is_plugin_active( $file );

		$plugins[ $slug ] = $data;
	}

	return $plugins;
}

/**
 * Which plugins this screen watches.
 *
 * Three sources, in this order:
 *
 *   1. Anything installed whose slug or plugin name starts with "qhta". This is
 *      the automatic half, and the reason a new QHTA plugin can never be
 *      silently missing from the dashboard — it appears the moment it is
 *      installed, with "no canaries defined" against it until somebody adds
 *      some. The name is matched as well as the slug because a single-file
 *      plugin's slug is whatever the file happened to be called.
 *   2. The expected fleet (qhta_healthcheck_expected_plugins()), whether or not
 *      each one is installed.
 *   3. Anything added through the qhta_healthcheck_watch_plugins filter, for a
 *      plugin that is neither qhta-prefixed nor in the expected fleet.
 *
 * Source 2 is deliberately NOT "anything the registry has canaries for". Now
 * that the canaries self-register from the plugins themselves, an uninstalled
 * plugin registers nothing — so deriving the fleet from the registry would make
 * a plugin's disappearance erase its own row instead of reporting it. The one
 * finding that matters most, "this should be here and is not", is exactly the
 * one that would go silent. Hence a separate, deliberately boring list of names.
 *
 * @return array<string,array> Slug => descriptor.
 */
function qhta_healthcheck_watched_plugins() {
	$installed = qhta_healthcheck_installed_plugins();
	$watch     = array();

	foreach ( $installed as $slug => $data ) {
		$name = isset( $data['Name'] ) ? $data['Name'] : '';

		if ( 0 === stripos( $slug, 'qhta' ) || 0 === stripos( $name, 'qhta' ) ) {
			$watch[] = $slug;
		}
	}

	$expected = qhta_healthcheck_expected_plugins();
	$watch    = array_merge( $watch, $expected );

	/**
	 * Filter the list of plugin slugs to watch.
	 *
	 * Only needed for a plugin whose slug does not start with "qhta" and which
	 * is not in the expected fleet — everything else is picked up
	 * automatically.
	 *
	 *   add_filter( 'qhta_healthcheck_watch_plugins', function ( $slugs ) {
	 *       $slugs[] = 'some-other-plugin';
	 *       return $slugs;
	 *   } );
	 *
	 * @param string[] $watch Plugin slugs.
	 */
	$watch = (array) apply_filters( 'qhta_healthcheck_watch_plugins', $watch );

	// This plugin watches the others, not itself. Reporting on its own presence
	// on a screen it is drawing would be a tautology.
	$watch = array_diff( array_unique( $watch ), array( QHTA_HEALTHCHECK_SLUG ) );

	// Resolve each canonical slug to whatever it is actually installed as, then
	// collapse those installed slugs out of the watch list so nothing is listed
	// twice — once canonically and once under its own folder name.
	$resolved  = array();
	$collapse  = array();

	foreach ( $watch as $slug ) {
		$as = qhta_healthcheck_resolve_installed( $slug );

		if ( '' !== $as ) {
			$resolved[ $slug ] = $as;

			if ( $as !== $slug ) {
				$collapse[ $as ] = $slug;
			}
		}
	}

	$watch = array_filter(
		$watch,
		function ( $slug ) use ( $collapse ) {
			return ! isset( $collapse[ $slug ] );
		}
	);

	$watching = array();

	foreach ( $watch as $slug ) {
		$as    = isset( $resolved[ $slug ] ) ? $resolved[ $slug ] : '';
		$found = ( '' !== $as && isset( $installed[ $as ] ) ) ? $installed[ $as ] : null;

		$watching[ $slug ] = array(
			'slug'         => $slug,
			'installed_as' => $found ? $as : '',
			'name'         => $found && ! empty( $found['Name'] ) ? $found['Name'] : $slug,
			'version'      => $found && ! empty( $found['Version'] ) ? $found['Version'] : '',
			'installed'    => (bool) $found,
			'active'       => (bool) ( $found && $found['active'] ),
			'expected'     => in_array( $slug, $expected, true ),
		);
	}

	ksort( $watching );

	return $watching;
}

/**
 * Every canary, keyed by plugin slug.
 *
 * The filter is where every canary comes from. A plugin that adds a dependency
 * adds the canary for it in its own repository, so the two cannot be deployed
 * apart — which is the entire point, and why checks.php contributes nothing.
 *
 * A plugin that has not yet been deployed with its canaries simply has none, and
 * is reported amber until it is. Nothing here fills that gap on its behalf: a
 * stand-in copy would either duplicate the real one or, worse, outlive it and go
 * on asserting something the plugin no longer does.
 *
 * A check is:
 *
 *   array(
 *       'id'       => 'woo-orders-api',                 // unique within the plugin
 *       'label'    => 'WooCommerce order API',          // shown on screen
 *       'why'      => 'The gate cannot check…',         // what breaks if it fails
 *       'severity' => 'critical',                       // or 'warning'
 *       'remote'   => false,                            // true = HTTP, daily only
 *       'test'     => function () { … },                // returns a result row
 *   )
 *
 * @return array<string,array[]> Slug => list of check specs.
 */
function qhta_healthcheck_registry() {
	static $registry = null;

	if ( null !== $registry ) {
		return $registry;
	}

	$registry = qhta_healthcheck_central_checks();

	/**
	 * Register or adjust healthcheck canaries.
	 *
	 * This is where every canary now comes from: each QHTA plugin registers its
	 * own on this filter, from a file that ships beside the code owning the
	 * dependency. qhta-healthcheck itself contributes almost nothing here — see
	 * qhta_healthcheck_central_checks().
	 *
	 *   add_filter( 'qhta_healthcheck_checks', function ( $checks ) {
	 *       $checks['my-plugin'][] = array(
	 *           'id'       => 'some-api',
	 *           'label'    => 'Some API is present',
	 *           'why'      => 'Without it the widget silently renders nothing.',
	 *           'severity' => 'critical',
	 *           'test'     => function () {
	 *               return qhta_healthcheck_assert_functions( 'some_api_call' );
	 *           },
	 *       );
	 *       return $checks;
	 *   } );
	 *
	 * @param array $registry Slug => list of check specs.
	 */
	$registry = (array) apply_filters( 'qhta_healthcheck_checks', $registry );

	foreach ( $registry as $slug => $checks ) {
		$registry[ $slug ] = array_map( 'qhta_healthcheck_normalise_check', (array) $checks );
	}

	return $registry;
}

/**
 * Fill in a check spec's optional keys.
 *
 * Severity defaults to warning rather than critical on purpose. A canary
 * contributed without thinking about consequence should not be able to turn the
 * whole site red; making somebody type 'critical' is the point at which they
 * decide whether it really is.
 *
 * @param array $check Raw check spec.
 * @return array
 */
function qhta_healthcheck_normalise_check( $check ) {
	$check = (array) $check;

	$check += array(
		'id'       => '',
		'label'    => '',
		'why'      => '',
		'severity' => 'warning',
		'remote'   => false,
		'test'     => null,
	);

	if ( 'critical' !== $check['severity'] ) {
		$check['severity'] = 'warning';
	}

	$check['remote'] = (bool) $check['remote'];

	if ( '' === $check['id'] ) {
		$check['id'] = sanitize_key( $check['label'] );
	}

	return $check;
}

/**
 * The canaries for one watched plugin.
 *
 * Alias-aware, and it has to be. A plugin self-registering its own canaries
 * knows the slug it is *installed* under — the conference program plugin can
 * reasonably say 'htaa-conference', because that is what its folder is called —
 * but the board keys everything by the canonical slug. Requiring each plugin to
 * know the canonical name this monitor happens to file it under would be a
 * dependency pointing the wrong way. So both are accepted and merged.
 *
 * @param string $slug Canonical plugin slug.
 * @return array[]
 */
function qhta_healthcheck_checks_for( $slug ) {
	$registry = qhta_healthcheck_registry();
	$aliases  = qhta_healthcheck_slug_aliases();

	$keys = array_merge(
		array( $slug ),
		isset( $aliases[ $slug ] ) ? (array) $aliases[ $slug ] : array(),
		// And whatever it turned out to be installed as, for a plugin that keys
		// its own registration off its folder rather than a hardcoded string.
		array( qhta_healthcheck_resolve_installed( $slug ) )
	);

	$keys = array_filter( array_unique( $keys ) );

	$checks = array();

	foreach ( $keys as $key ) {
		if ( ! empty( $registry[ $key ] ) ) {
			$checks = array_merge( $checks, $registry[ $key ] );
		}
	}

	return $checks;
}
