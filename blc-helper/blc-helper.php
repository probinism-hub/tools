<?php
/**
 * Plugin Name: Broken Link Checker Helper
 * Description: Server-side helper for the Broken Link Checker tool (pasted into an Elementor HTML widget). It reads web pages and tests links on behalf of the tool, so no third-party relay services are needed.
 * Version: 1.0.0
 * Requires at least: 5.2
 * Requires PHP: 7.2
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLC_Helper {

	const VERSION      = '1.0.0';
	const MAX_HOPS     = 5;       // redirects followed per link.
	const TIME_BUDGET  = 22;      // seconds one request may spend in total.
	const PAGE_BYTES   = 2000000; // largest page body read (2 MB).
	const RATE_LIMIT   = 2500;    // requests per visitor per hour (filter: blc_rate_limit).

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'blc/v1',
			'/fetch',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'mode' => array(
						'type'    => 'string',
						'default' => 'check',
						'enum'    => array( 'ping', 'check', 'page' ),
					),
					'url'  => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	private static function respond( $data, $status = 200 ) {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}

	public static function handle( $request ) {
		$mode = $request->get_param( 'mode' );

		if ( 'ping' === $mode ) {
			return self::respond( array( 'ok' => true, 'version' => self::VERSION ) );
		}

		if ( self::rate_limited() ) {
			return self::respond( array( 'error' => 'rate_limited' ), 429 );
		}

		$url = trim( (string) $request->get_param( 'url' ) );
		if ( '' === $url || strlen( $url ) > 2048 || ! preg_match( '#^https?://#i', $url ) ) {
			return self::respond( array( 'status' => 0, 'error' => 'blocked_url', 'final' => $url, 'hops' => 0 ) );
		}
		$url = esc_url_raw( $url );

		@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return self::respond( self::follow( $url, $mode ) );
	}

	/** Simple per-visitor hourly limit so the endpoint cannot be abused as a free proxy. */
	private static function rate_limited() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
		$key = 'blc_rl_' . md5( $ip );
		$d   = get_transient( $key );
		if ( ! is_array( $d ) || ! isset( $d['n'], $d['t'] ) ) {
			$d = array( 'n' => 0, 't' => time() );
		}
		$limit = (int) apply_filters( 'blc_rate_limit', self::RATE_LIMIT );
		if ( $d['n'] >= $limit ) {
			return true;
		}
		$d['n']++;
		$ttl = max( 60, HOUR_IN_SECONDS - ( time() - $d['t'] ) );
		set_transient( $key, $d, $ttl );
		return false;
	}

	private static function request( $url, $method, $limit ) {
		return wp_safe_remote_request(
			$url,
			array(
				'method'              => $method,
				'timeout'             => 10,
				'redirection'         => 0, // we follow redirects ourselves so every hop is re-validated.
				'limit_response_size' => $limit,
				'user-agent'          => 'Mozilla/5.0 (compatible; BrokenLinkChecker/' . self::VERSION . '; +' . home_url( '/' ) . ')',
				'headers'             => array(
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en',
				),
			)
		);
	}

	private static function follow( $url, $mode ) {
		$started = time();
		$method  = ( 'page' === $mode ) ? 'GET' : 'HEAD';
		$limit   = ( 'page' === $mode ) ? self::PAGE_BYTES : 4096;
		$hops    = 0;

		while ( true ) {
			if ( time() - $started > self::TIME_BUDGET ) {
				return array( 'status' => 0, 'error' => 'timeout', 'final' => $url, 'hops' => $hops );
			}

			$res = self::request( $url, $method, $limit );

			// Some servers answer HEAD wrongly. Confirm any doubtful result with a light GET.
			if ( 'HEAD' === $method ) {
				$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
				if ( is_wp_error( $res ) || in_array( $code, array( 400, 401, 403, 404, 405, 406, 429, 500, 501, 502, 503 ), true ) ) {
					if ( time() - $started <= self::TIME_BUDGET ) {
						$retry = self::request( $url, 'GET', 4096 );
						if ( ! is_wp_error( $retry ) || is_wp_error( $res ) ) {
							$res = $retry;
						}
					}
				}
			}

			if ( is_wp_error( $res ) ) {
				return array( 'status' => 0, 'error' => self::error_code( $res ), 'final' => $url, 'hops' => $hops );
			}

			$code = (int) wp_remote_retrieve_response_code( $res );

			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$loc = wp_remote_retrieve_header( $res, 'location' );
				if ( is_array( $loc ) ) {
					$loc = reset( $loc );
				}
				if ( $loc ) {
					if ( $hops >= self::MAX_HOPS ) {
						return array( 'status' => 0, 'error' => 'redirect_loop', 'final' => $url, 'hops' => $hops );
					}
					$next = self::absolutize( (string) $loc, $url );
					if ( ! $next || ! preg_match( '#^https?://#i', $next ) ) {
						return array( 'status' => $code, 'final' => $url, 'hops' => $hops );
					}
					$url = $next;
					$hops++;
					continue;
				}
			}

			$out = array( 'status' => $code, 'final' => $url, 'hops' => $hops );

			if ( 'page' === $mode ) {
				$ctype        = strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) );
				$out['ctype'] = $ctype;
				if ( $code < 400 && ( '' === $ctype || false !== strpos( $ctype, 'html' ) || false !== strpos( $ctype, 'xml' ) ) ) {
					$out['body'] = wp_check_invalid_utf8( (string) wp_remote_retrieve_body( $res ), true );
				}
			}
			return $out;
		}
	}

	private static function error_code( $err ) {
		$m = strtolower( $err->get_error_message() );
		if ( false !== strpos( $m, 'valid url' ) || false !== strpos( $m, 'not allowed' ) ) {
			return 'blocked_url';
		}
		if ( false !== strpos( $m, 'resolve' ) || false !== strpos( $m, 'getaddrinfo' ) || false !== strpos( $m, 'name or service' ) ) {
			return 'dns';
		}
		if ( false !== strpos( $m, 'timed out' ) || false !== strpos( $m, 'timeout' ) ) {
			return 'timeout';
		}
		if ( false !== strpos( $m, 'ssl' ) || false !== strpos( $m, 'certificate' ) ) {
			return 'ssl';
		}
		return 'connect';
	}

	private static function absolutize( $loc, $base ) {
		if ( preg_match( '#^https?://#i', $loc ) ) {
			return $loc;
		}
		$p = wp_parse_url( $base );
		if ( empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return '';
		}
		$origin = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		if ( 0 === strpos( $loc, '//' ) ) {
			return $p['scheme'] . ':' . $loc;
		}
		if ( 0 === strpos( $loc, '/' ) ) {
			return $origin . $loc;
		}
		$path = isset( $p['path'] ) ? $p['path'] : '/';
		$dir  = substr( $path, 0, strrpos( $path, '/' ) + 1 );
		return $origin . $dir . $loc;
	}
}

BLC_Helper::init();
