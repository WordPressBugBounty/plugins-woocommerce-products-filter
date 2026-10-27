<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/**
 * Rate limit of the public MCP endpoint: token buckets keyed by address.
 *
 * The 2026-07-28 protocol revision has no sessions, so nothing but the
 * address identifies a caller, and the server adds no identifier of its own.
 *
 *  - A regular address gets a strict bucket: about 60 requests a minute,
 *    with a burst for the 5-10 calls an agent fires in a row.
 *  - The published egress ranges of AI platforms (claude.ai, ChatGPT, ...)
 *    get a much higher ceiling: every shopper using such an assistant
 *    arrives from those few addresses. One bucket per listed range, shared by
 *    every address in it, so the ceiling is the platform's total.
 *
 * Over the limit the caller gets HTTP 429 with Retry-After (server.php).
 *
 * Storage: the persistent object cache when the site has one (no database
 * write per request), else APCu when PHP has it, else transients.
 */
final class HUSKY_MCP_RATE_LIMIT {

	const GROUP = 'husky_mcp_rl';

	/**
	 * Default AI platform ranges (ai-ranges.txt), only those a vendor
	 * publishes as the source of its MCP calls, with the source URL above
	 * each block. The settings field holds the editable copy. Lines starting
	 * with # are comments.
	 */
	public static function default_ai_ranges() {
		$text  = (string) file_get_contents( __DIR__ . '/ai-ranges.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$lines = preg_split( '/\R/', trim( $text ) );
		return implode( "\n", apply_filters( 'woof_mcp_default_ai_ranges', $lines ) );
	}

	/**
	 * Takes one token for this address.
	 *
	 * @return int 0 when the request may go on, else seconds to wait
	 */
	public static function check( $ip ) {

		if ( ! intval( HUSKY_MCP::get( 'rl_enabled' ) ) ) {
			return 0;
		}

		$range = self::ai_range_of( $ip );

		if ( '' !== $range ) {
			return self::take( 'a_' . md5( $range ), intval( HUSKY_MCP::get( 'rl_ai_burst' ) ), intval( HUSKY_MCP::get( 'rl_ai_rpm' ) ) );
		}

		return self::take( 'i_' . md5( $ip ), intval( HUSKY_MCP::get( 'rl_ip_burst' ) ), intval( HUSKY_MCP::get( 'rl_ip_rpm' ) ) );
	}

	/**
	 * The listed AI platform range an address belongs to, '' for none.
	 */
	public static function ai_range_of( $ip ) {
		foreach ( self::ai_ranges() as $cidr ) {
			if ( self::in_cidr( $ip, $cidr ) ) {
				return $cidr;
			}
		}
		return '';
	}

	/**
	 * The ranges from the settings, comments and blanks left out.
	 *
	 * @return string[]
	 */
	public static function ai_ranges() {
		static $out = null;
		if ( null !== $out ) {
			return $out;
		}
		$text = HUSKY_MCP::get( 'rl_ai_ranges' );
		// empty = follow the default list (a text equal to it is stored empty)
		$text = ( null === $text || '' === trim( (string) $text ) ) ? self::default_ai_ranges() : (string) $text;
		$out  = array();
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( '' !== $line && self::valid_cidr( $line ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	public static function valid_cidr( $cidr ) {
		$parts = explode( '/', $cidr );
		$bin   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $bin ) {
			return false;
		}
		if ( ! isset( $parts[1] ) ) {
			return true;
		}
		$bits = strlen( $bin ) * 8;
		return ctype_digit( $parts[1] ) && intval( $parts[1] ) <= $bits;
	}

	/**
	 * Whether an IPv4 or IPv6 address is inside a CIDR range (a bare
	 * address matches itself).
	 */
	public static function in_cidr( $ip, $cidr ) {

		$parts = explode( '/', $cidr );
		$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$addr  = @inet_pton( $ip );       // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $net || false === $addr || strlen( $net ) !== strlen( $addr ) ) {
			return false;
		}

		$bits = isset( $parts[1] ) ? intval( $parts[1] ) : strlen( $net ) * 8;
		$full = intdiv( $bits, 8 );
		$rest = $bits % 8;

		if ( 0 !== strncmp( $net, $addr, $full ) ) {
			return false;
		}

		if ( 0 === $rest ) {
			return true;
		}

		$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
		return ( ord( $net[ $full ] ) & $mask ) === ( ord( $addr[ $full ] ) & $mask );
	}

	/**
	 * @param string $key
	 * @param int    $burst bucket size, 0 = this bucket is off
	 * @param int    $rpm   refill per minute, 0 = this bucket is off
	 * @return int seconds to wait, 0 when a token was taken
	 */
	private static function take( $key, $burst, $rpm ) {

		if ( $burst <= 0 || $rpm <= 0 ) {
			return 0;
		}

		$now    = microtime( true );
		$rate   = $rpm / 60; // tokens per second
		$bucket = self::read( $key );

		if ( ! is_array( $bucket ) || ! isset( $bucket['t'], $bucket['at'] ) ) {
			$bucket = array(
				't'  => (float) $burst,
				'at' => $now,
			);
		}

		$tokens = min( (float) $burst, (float) $bucket['t'] + ( $now - (float) $bucket['at'] ) * $rate );

		if ( $tokens < 1 ) {
			// not written back: a refused request costs nothing and does not
			// push the wait further out
			return max( 1, (int) ceil( ( 1 - $tokens ) / $rate ) );
		}

		// the bucket is full again after this long without requests
		$ttl = (int) ceil( $burst / $rate ) + 60;

		self::write(
			$key,
			array(
				't'  => $tokens - 1,
				'at' => $now,
			),
			$ttl
		);

		return 0;
	}

	/**
	 * Which storage holds the buckets (and the search cache): 'object-cache',
	 * 'apcu' or 'transient'.
	 */
	public static function backend() {
		if ( wp_using_ext_object_cache() ) {
			return 'object-cache';
		}
		if ( function_exists( 'apcu_fetch' ) && function_exists( 'apcu_enabled' ) && apcu_enabled() ) {
			return 'apcu';
		}
		return 'transient';
	}

	public static function read( $key, $group = self::GROUP ) {
		switch ( self::backend() ) {
			case 'object-cache':
				return wp_cache_get( $key, $group );
			case 'apcu':
				$ok = false;
				$v  = apcu_fetch( $group . '_' . get_current_blog_id() . '_' . $key, $ok );
				return $ok ? $v : false;
		}
		return get_transient( $group . '_' . $key );
	}

	public static function write( $key, $value, $ttl, $group = self::GROUP ) {
		switch ( self::backend() ) {
			case 'object-cache':
				wp_cache_set( $key, $value, $group, $ttl );
				return;
			case 'apcu':
				apcu_store( $group . '_' . get_current_blog_id() . '_' . $key, $value, $ttl );
				return;
		}
		set_transient( $group . '_' . $key, $value, $ttl );
	}
}
