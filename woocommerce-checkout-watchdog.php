<?php
/**
 * Plugin Name: WooCommerce Checkout Watchdog
 * Description: Temporary diagnostic for a hanging WooCommerce checkout / order-review AJAX. Logs request timing, outbound HTTP calls, PHP errors and WooCommerce render checkpoints to a protected log file. Remove once the cause is found.
 * Author:      Fernando Tellado
 * Author URI:  https://ayudawp.com/
 * Plugin URI:   https://github.com/fernandotellado
 * Version:     1.0.0
 * Requires PHP: 7.4
 *
 * HOW TO USE
 *   1. Copy this file into  wp-content/mu-plugins/  (create that folder if it does not exist).
 *      mu-plugins load automatically: no activation needed, no admin screen.
 *   2. Reproduce the stuck checkout once or twice (place an order / change a field on the review).
 *   3. Read the log file. Its exact path is printed on the first "REQUEST START" line and is:
 *         wp-content/ayudawp-cw/checkout-watchdog-XXXXXXXXXXXX.log
 *   4. When you are done, DELETE this file and the whole  wp-content/ayudawp-cw/  folder.
 *
 * WHAT IT DOES (only on checkout-related requests, near-zero overhead elsewhere)
 *   - Times the whole request and every outbound HTTP call (the usual cause of a frozen spinner).
 *   - Captures PHP fatals, warnings and notices that never reach the browser when WP_DEBUG is off.
 *   - Drops WooCommerce render checkpoints so you can see WHICH phase eats the time / where it stops.
 *
 * PRIVACY: it never dumps $_POST or any personal data; HTTP URLs are logged without their query
 * string (so API keys/tokens are not written), and slow SQL is truncated.
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tiny state container kept in a function static, so we do not pollute the global scope.
 * Returned by reference so callers can mutate it.
 *
 * @return array
 */
function &ayudawp_cw_state() {
	static $state = array(
		'armed'              => false,
		'start'              => 0.0,
		'rid'                => '',
		'http_stack'         => array(),
		'http_count'         => 0,
		'http_inflight'      => 0,
		'log_path'           => null,
		'prev_error_handler' => null,
	);

	return $state;
}

/**
 * Decide, as early as possible and from request parameters alone, whether this is a
 * checkout-related request worth instrumenting.
 *
 * @return bool
 */
function ayudawp_cw_is_target_request() {
	// WooCommerce AJAX front controller: /?wc-ajax=ACTION .
	if ( isset( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only request routing for diagnostics, no state change, no output.
		$action  = sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$watched = array(
			'update_order_review',
			'checkout',
			'update_shipping_method',
			'apply_coupon',
			'remove_coupon',
			'get_refreshed_fragments',
		);
		if ( in_array( $action, $watched, true ) ) {
			return true;
		}
	}

	// Classic admin-ajax.php WooCommerce actions (older flows / some gateways).
	if ( isset( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 === strpos( $action, 'woocommerce_' ) || 0 === strpos( $action, 'wc_' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Resolve (and memoize) the log file path, creating a protected folder for it.
 *
 * @return string Absolute path, or empty string if the folder is not writable.
 */
function ayudawp_cw_log_path() {
	$state = &ayudawp_cw_state();
	if ( null !== $state['log_path'] ) {
		return $state['log_path'];
	}

	$base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
	$dir  = $base . '/ayudawp-cw';

	if ( ! is_dir( $dir ) ) {
		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		} else {
			mkdir( $dir, 0755, true );
		}
	}

	if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
		$state['log_path'] = '';
		return '';
	}

	// Block web access (Apache). On nginx the random filename suffix below is the guard.
	$htaccess = $dir . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- runs before WP_Filesystem is available; direct write is intentional for a diagnostic tool.
	}
	$index = $dir . '/index.php';
	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- see above.
	}

	// Non-guessable, but stable across requests so all hits share one log file.
	$secret = ( defined( 'AUTH_SALT' ) && AUTH_SALT ) ? AUTH_SALT : ( defined( 'NONCE_SALT' ) ? NONCE_SALT : ABSPATH );
	$suffix = substr( hash( 'sha256', 'ayudawp-cw|' . $secret ), 0, 12 );

	$state['log_path'] = $dir . '/checkout-watchdog-' . $suffix . '.log';
	return $state['log_path'];
}

/**
 * Append one timestamped line to the log (falls back to the PHP error log if the file fails).
 *
 * @param string $line Message.
 */
function ayudawp_cw_log( $line ) {
	$path = ayudawp_cw_log_path();
	if ( '' === $path ) {
		error_log( 'ayudawp-cw: ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional fallback for a diagnostic tool.
		return;
	}

	$state = &ayudawp_cw_state();
	$stamp = function_exists( 'current_time' ) ? current_time( 'Y-m-d H:i:s' ) : gmdate( 'Y-m-d H:i:s' );
	$entry = sprintf( "[%s][%s] %s\n", $stamp, $state['rid'], $line );

	// LOCK_EX so concurrent checkout requests do not interleave mid-line.
	file_put_contents( $path, $entry, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- intentional direct write for a diagnostic tool.
}

/**
 * Strip ABSPATH from a file path for shorter, less leaky log lines.
 *
 * @param string $path File path.
 * @return string
 */
function ayudawp_cw_relpath( $path ) {
	$path = (string) $path;
	if ( defined( 'ABSPATH' ) && 0 === strpos( $path, ABSPATH ) ) {
		return substr( $path, strlen( ABSPATH ) );
	}
	return $path;
}

/**
 * Drop the query string from a URL so secrets in query params are never logged.
 *
 * @param string $url URL.
 * @return string
 */
function ayudawp_cw_strip_query( $url ) {
	$pos = strpos( $url, '?' );
	return ( false === $pos ) ? $url : substr( $url, 0, $pos );
}

/**
 * Collapse whitespace and truncate an SQL string for the log.
 *
 * @param string $sql SQL.
 * @return string
 */
function ayudawp_cw_trim_sql( $sql ) {
	$sql = preg_replace( '/\s+/', ' ', trim( $sql ) );
	if ( strlen( $sql ) > 200 ) {
		$sql = substr( $sql, 0, 200 ) . '...';
	}
	return $sql;
}

/**
 * Error handler that logs warnings/notices which stay invisible in production
 * (WP_DEBUG off) yet can corrupt an AJAX JSON response. Chains to any previous handler.
 *
 * @return bool
 */
function ayudawp_cw_error_handler( $errno, $errstr, $errfile, $errline ) {
	$state = &ayudawp_cw_state();

	// Respect the @-operator and the current error_reporting() level.
	if ( 0 === ( error_reporting() & $errno ) ) {
		return false;
	}

	$labels = array(
		E_WARNING           => 'WARNING',
		E_NOTICE            => 'NOTICE',
		E_DEPRECATED        => 'DEPRECATED',
		E_USER_ERROR        => 'USER_ERROR',
		E_USER_WARNING      => 'USER_WARNING',
		E_USER_NOTICE       => 'USER_NOTICE',
		E_USER_DEPRECATED   => 'USER_DEPRECATED',
		E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
	);
	$label = isset( $labels[ $errno ] ) ? $labels[ $errno ] : ( 'ERR(' . (int) $errno . ')' );

	ayudawp_cw_log( sprintf( 'PHP %s: %s in %s:%d', $label, $errstr, ayudawp_cw_relpath( $errfile ), (int) $errline ) );

	if ( is_callable( $state['prev_error_handler'] ) ) {
		return call_user_func( $state['prev_error_handler'], $errno, $errstr, $errfile, $errline );
	}
	return false; // Let PHP run its built-in handler too.
}

/**
 * pre_http_request: record the start of every outbound HTTP call and log it immediately.
 * If a call hangs, this is the LAST line you will see in the log, which is exactly the clue.
 * Returns the incoming value unchanged so the real request still runs.
 */
function ayudawp_cw_pre_http( $preempt, $parsed_args, $url ) {
	$state = &ayudawp_cw_state();

	$timeout  = isset( $parsed_args['timeout'] ) ? (float) $parsed_args['timeout'] : 0.0;
	$method   = isset( $parsed_args['method'] ) ? (string) $parsed_args['method'] : 'GET';
	$endpoint = ayudawp_cw_strip_query( (string) $url );

	$state['http_count']++;
	$state['http_inflight']++;
	$state['http_stack'][] = array(
		'endpoint' => $endpoint,
		'start'    => microtime( true ),
	);

	ayudawp_cw_log( sprintf( 'HTTP -> #%d %s %s (timeout=%ss)', $state['http_count'], $method, $endpoint, $timeout ) );

	return $preempt;
}

/**
 * http_api_debug: close the timing for the HTTP call started above and log status + duration.
 */
function ayudawp_cw_http_debug( $response, $context, $class, $parsed_args, $url ) {
	$state = &ayudawp_cw_state();

	$entry    = array_pop( $state['http_stack'] );
	$elapsed  = is_array( $entry ) ? ( microtime( true ) - $entry['start'] ) * 1000 : 0.0;
	$endpoint = is_array( $entry ) ? $entry['endpoint'] : ayudawp_cw_strip_query( (string) $url );
	$state['http_inflight'] = max( 0, $state['http_inflight'] - 1 );

	if ( is_wp_error( $response ) ) {
		ayudawp_cw_log( sprintf(
			'HTTP xx %s FAILED after %.0fms -- %s: %s',
			$endpoint,
			$elapsed,
			$response->get_error_code(),
			$response->get_error_message()
		) );
		return;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$flag = ( $elapsed >= 1000 ) ? ' [SLOW]' : '';
	ayudawp_cw_log( sprintf( 'HTTP ok %s -> HTTP %s in %.0fms%s', $endpoint, ( '' === $code ) ? '?' : $code, $elapsed, $flag ) );
}

/**
 * Log a WooCommerce render checkpoint with elapsed time since request start.
 *
 * @param string $label Checkpoint label.
 */
function ayudawp_cw_checkpoint( $label ) {
	$state   = &ayudawp_cw_state();
	$elapsed = ( microtime( true ) - $state['start'] ) * 1000;
	ayudawp_cw_log( sprintf( 'CHECKPOINT %-28s @ %.0fms', $label, $elapsed ) );
}

/**
 * Turn on full instrumentation for this request (idempotent).
 *
 * @param string $reason Why we armed (ajax / checkout-page).
 */
function ayudawp_cw_arm( $reason ) {
	$state = &ayudawp_cw_state();
	if ( $state['armed'] ) {
		return;
	}
	$state['armed'] = true;

	// Capture otherwise-invisible PHP notices/warnings; remember the previous handler to chain.
	$state['prev_error_handler'] = set_error_handler( 'ayudawp_cw_error_handler' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure -- diagnostic only, restored at shutdown.

	// Time every outbound HTTP request.
	add_filter( 'pre_http_request', 'ayudawp_cw_pre_http', 10, 3 );
	add_action( 'http_api_debug', 'ayudawp_cw_http_debug', 10, 5 );

	// WooCommerce render checkpoints, in roughly the order they fire while building the review.
	$checkpoints = array(
		'woocommerce_checkout_update_order_review' => 'ajax:update_order_review:in',
		'woocommerce_after_calculate_totals'       => 'totals:calculated',
		'woocommerce_review_order_before_payment'  => 'review:before_payment',
		'woocommerce_review_order_after_submit'    => 'review:after_submit',
		'woocommerce_checkout_order_processed'     => 'checkout:order_processed',
	);
	foreach ( $checkpoints as $hook => $label ) {
		add_action(
			$hook,
			static function () use ( $label ) {
				ayudawp_cw_checkpoint( $label );
			},
			0
		);
	}

	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

	ayudawp_cw_log( sprintf(
		'==== REQUEST START (%s) %s %s | log=%s | display_errors=%s WP_DEBUG=%s PHP=%s',
		$reason,
		$method,
		$uri,
		ayudawp_cw_log_path(),
		ini_get( 'display_errors' ) ? 'on' : 'off',
		( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'on' : 'off',
		PHP_VERSION
	) );
}

/**
 * Arm on the checkout page itself (covers custom slugs and a hang during the initial render,
 * not just the AJAX). Runs early on 'wp' once conditional tags are available.
 */
function ayudawp_cw_maybe_arm_on_checkout() {
	$state = &ayudawp_cw_state();
	if ( $state['armed'] ) {
		return;
	}
	if ( function_exists( 'is_checkout' ) && is_checkout()
		&& ! ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) )
	) {
		ayudawp_cw_arm( 'checkout-page' );
	}
}

/**
 * Final summary: total time, captured fatal, slow queries, inflight HTTP warning, response type.
 * Runs even when the browser only ever saw a spinner.
 */
function ayudawp_cw_shutdown() {
	$state = &ayudawp_cw_state();
	if ( ! $state['armed'] ) {
		return;
	}

	restore_error_handler();

	$elapsed = ( microtime( true ) - $state['start'] ) * 1000;
	$peak    = function_exists( 'memory_get_peak_usage' ) ? round( memory_get_peak_usage( true ) / 1048576, 1 ) : 0;

	// Fatal error, captured even when WP shows the generic "critical error" page or a blank 500.
	$last       = error_get_last();
	$fatal_mask = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
	if ( is_array( $last ) && ( $last['type'] & $fatal_mask ) ) {
		ayudawp_cw_log( sprintf( 'FATAL %s in %s:%d', $last['message'], ayudawp_cw_relpath( $last['file'] ), (int) $last['line'] ) );
	}

	// Slow queries, only if the site already has SAVEQUERIES enabled in wp-config.php.
	if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
		global $wpdb;
		if ( isset( $wpdb->queries ) && is_array( $wpdb->queries ) ) {
			$slow = array();
			foreach ( $wpdb->queries as $q ) {
				// $q = array( 0 => SQL, 1 => time in seconds, 2 => caller ).
				if ( isset( $q[1] ) && (float) $q[1] >= 0.25 ) {
					$slow[] = $q;
				}
			}
			if ( ! empty( $slow ) ) {
				usort(
					$slow,
					static function ( $a, $b ) {
						return ( (float) $b[1] ) <=> ( (float) $a[1] );
					}
				);
				foreach ( array_slice( $slow, 0, 3 ) as $q ) {
					ayudawp_cw_log( sprintf(
						'SLOW QUERY %.0fms -- %s -- caller: %s',
						(float) $q[1] * 1000,
						ayudawp_cw_trim_sql( (string) $q[0] ),
						isset( $q[2] ) ? (string) $q[2] : 'n/a'
					) );
				}
			}
		}
	}

	// Response content type: a wc-ajax order review must come back as JSON.
	$content_type = '';
	foreach ( headers_list() as $h ) {
		if ( 0 === stripos( $h, 'content-type:' ) ) {
			$content_type = trim( substr( $h, strlen( 'content-type:' ) ) );
			break;
		}
	}

	if ( $state['http_inflight'] > 0 ) {
		ayudawp_cw_log( sprintf(
			'WARNING %d outbound HTTP call(s) started but never returned -- the last "HTTP ->" line above is the prime suspect for the hang.',
			$state['http_inflight']
		) );
	}

	ayudawp_cw_log( sprintf(
		'==== REQUEST END %.0fms total | http_calls=%d | peak_mem=%sMB | content-type=%s | logged_in=%s',
		$elapsed,
		$state['http_count'],
		$peak,
		( '' === $content_type ) ? 'n/a' : $content_type,
		( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) ? 'yes' : 'no'
	) );
	ayudawp_cw_log( '' );
}

/*
 * Bootstrap: record the start time as early as possible, then arm when relevant.
 */
$ayudawp_cw_state          = &ayudawp_cw_state();
$ayudawp_cw_state['start'] = microtime( true );
$ayudawp_cw_state['rid']   = substr( md5( uniqid( '', true ) ), 0, 6 );

// Shutdown is always registered but no-ops unless the request gets armed.
register_shutdown_function( 'ayudawp_cw_shutdown' );

// Arm immediately for obvious checkout AJAX requests.
if ( ayudawp_cw_is_target_request() ) {
	ayudawp_cw_arm( 'ajax' );
}

// Also arm on the checkout page itself (custom slugs, initial-render hangs).
add_action( 'wp', 'ayudawp_cw_maybe_arm_on_checkout', 1 );
