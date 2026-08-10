<?php
/**
 * Embed module - settings storage and URL resolution.
 *
 * A focused alternative to EmbedPress: a [dpt_embed] shortcode for the couple of
 * sources WordPress core oEmbed does NOT handle - PDF files and Google Docs /
 * Sheets / Slides / Forms / Drive previews - rendered in a responsive frame.
 * Everything core already oEmbeds (YouTube, Vimeo, Twitter, ...) is left to core.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_EMB_Settings {

	const OPTION = 'dpt_embed';

	public static function defaults() {
		return array(
			'default_ratio'  => '4:3',
			'default_height' => '', // blank = use the responsive aspect ratio
			'lazy_load'      => '1',
		);
	}

	public static function install_defaults() {
		$existing = get_option( self::OPTION );
		if ( ! is_array( $existing ) ) {
			add_option( self::OPTION, self::defaults() );
			return;
		}
		update_option( self::OPTION, array_merge( self::defaults(), $existing ) );
	}

	public static function all() {
		$opts = get_option( self::OPTION, array() );
		$all  = array_merge( self::defaults(), is_array( $opts ) ? $opts : array() );

		$all['default_ratio']  = self::sanitize_ratio( $all['default_ratio'], '4:3' );
		$all['default_height'] = self::sanitize_height( $all['default_height'] );
		$all['lazy_load']      = ( '1' === (string) $all['lazy_load'] ) ? '1' : '0';
		return $all;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	public static function is_on( $key ) {
		return '1' === (string) self::get( $key );
	}

	/**
	 * A "W:H" ratio string, or the fallback when malformed / zero.
	 *
	 * @param mixed  $value    Raw ratio.
	 * @param string $fallback Fallback ratio.
	 * @return string
	 */
	public static function sanitize_ratio( $value, $fallback = '4:3' ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( preg_match( '/^(\d{1,3}):(\d{1,3})$/', $value, $m ) && (int) $m[1] > 0 && (int) $m[2] > 0 ) {
			return (int) $m[1] . ':' . (int) $m[2];
		}
		return $fallback;
	}

	/**
	 * A non-negative pixel height, or '' when unset/blank/invalid.
	 *
	 * @param mixed $value Raw height.
	 * @return string
	 */
	public static function sanitize_height( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value || ! preg_match( '/^\d{1,5}$/', $value ) ) {
			return '';
		}
		$h = (int) $value;
		return ( $h > 0 ) ? (string) $h : '';
	}

	/**
	 * The responsive padding-top percentage for a ratio string (H / W * 100).
	 *
	 * @param string $ratio "W:H".
	 * @return float
	 */
	public static function ratio_padding( $ratio ) {
		$ratio = self::sanitize_ratio( $ratio, '4:3' );
		list( $w, $h ) = array_map( 'intval', explode( ':', $ratio ) );
		if ( $w <= 0 ) {
			$w = 4;
			$h = 3;
		}
		return round( ( $h / $w ) * 100, 4 );
	}

	/**
	 * Resolve a raw URL into an embeddable source. Returns an array
	 * [ 'type' => 'pdf'|'google', 'src' => string ] or null when the URL is not
	 * a supported (PDF / Google) source.
	 *
	 * @param string $url Raw URL.
	 * @return array|null
	 */
	public static function resolve( $url ) {
		$url = is_scalar( $url ) ? trim( (string) $url ) : '';
		if ( '' === $url ) {
			return null;
		}
		// Only ever embed http(s) URLs - no javascript:, data:, etc.
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		// Google Docs / Sheets / Slides / Forms / Drive previews.
		if ( 'docs.google.com' === $host || 'drive.google.com' === $host ) {
			$src = self::google_embed_src( $url, $scheme, $host, $path );
			return ( null !== $src ) ? array( 'type' => 'google', 'src' => $src ) : null;
		}

		// A direct PDF file (ignore the query string / fragment when matching).
		if ( preg_match( '/\.pdf$/i', (string) $path ) ) {
			return array( 'type' => 'pdf', 'src' => $url );
		}

		return null;
	}

	/**
	 * Convert a Google URL into its embeddable preview/embed URL, or null.
	 * The base is rebuilt from the already-normalised (lower-cased) scheme and
	 * host and the path segments, so a mixed-case host still resolves.
	 *
	 * @param string $url    Full URL (for its query/fragment).
	 * @param string $scheme Lower-cased scheme.
	 * @param string $host   Lower-cased host.
	 * @param string $path   URL path.
	 * @return string|null
	 */
	private static function google_embed_src( $url, $scheme, $host, $path ) {
		$origin = $scheme . '://' . $host;

		// Forms: use the embedded viewform, preserving any pre-fill parameters
		// (entry.NNN=...) already on the URL and just adding embedded=true.
		if ( false !== strpos( $path, '/forms/' ) ) {
			if ( preg_match( '#^/forms/d/(e/)?([a-zA-Z0-9_-]+)#', $path, $m ) ) {
				$base  = $origin . '/forms/d/' . ( ! empty( $m[1] ) ? 'e/' : '' ) . $m[2];
				$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
				// Keep the raw query pairs verbatim - do NOT parse_str them, which
				// would mangle the dotted "entry.123" keys into "entry_123". Drop
				// any existing embedded flag, then append our own.
				$pairs = array();
				foreach ( ( '' !== $query ) ? explode( '&', $query ) : array() as $pair ) {
					if ( '' === $pair || 0 === stripos( $pair, 'embedded=' ) ) {
						continue;
					}
					$pairs[] = $pair;
				}
				$pairs[] = 'embedded=true';
				return $base . '/viewform?' . implode( '&', $pairs );
			}
			return null;
		}
		// Legacy Drive share link: drive.google.com/open?id=FILE_ID. Map it to
		// the modern /file/d/FILE_ID/preview shape.
		if ( 'drive.google.com' === $host && '/open' === $path ) {
			$id = self::query_param( $url, 'id' );
			if ( null !== $id && preg_match( '/^[a-zA-Z0-9_-]+$/', $id ) ) {
				return $origin . '/file/d/' . $id . '/preview' . self::preview_suffix( $url );
			}
			return null;
		}

		// Docs / Sheets / Slides / Drive files: swap the trailing action for
		// /preview, which Google renders as a read-only embeddable view.
		if ( preg_match( '#^/([a-zA-Z]+)/d/(e/)?([a-zA-Z0-9_-]+)#', $path, $m ) ) {
			$preview = $origin . '/' . strtolower( $m[1] ) . '/d/' . ( ! empty( $m[2] ) ? 'e/' : '' ) . $m[3] . '/preview';
			return $preview . self::preview_suffix( $url );
		}
		return null;
	}

	/**
	 * The raw value of a named query parameter, or null. Values are kept verbatim
	 * (no parse_str), so dotted keys/values are not mangled.
	 *
	 * @param string $url  Full URL.
	 * @param string $name Parameter name.
	 * @return string|null
	 */
	private static function query_param( $url, $name ) {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		foreach ( ( '' !== $query ) ? explode( '&', $query ) : array() as $pair ) {
			if ( 0 === strpos( $pair, $name . '=' ) ) {
				return substr( $pair, strlen( $name ) + 1 );
			}
		}
		return null;
	}

	/**
	 * The query/fragment suffix to carry onto a Google /preview URL: only the
	 * parameters that change what is shown.
	 *  - resourcekey (query): without it a link-protected file errors.
	 *  - gid (query or #fragment): selects a Sheets worksheet.
	 *  - tab (query): selects a Google Docs tab.
	 *  - slide (#fragment): selects a Google Slides slide.
	 * Everything else (usp, etc.) is dropped as noise.
	 *
	 * @param string $url Full URL.
	 * @return string
	 */
	private static function preview_suffix( $url ) {
		$allowed = array( 'resourcekey', 'gid', 'tab' );
		$carry   = array();
		$query   = (string) wp_parse_url( $url, PHP_URL_QUERY );
		foreach ( ( '' !== $query ) ? explode( '&', $query ) : array() as $pair ) {
			$key = strstr( $pair, '=', true );
			if ( false !== $key && in_array( $key, $allowed, true ) && strlen( $pair ) > strlen( $key ) + 1 ) {
				$carry[ $key ] = $pair;
			}
		}

		$fragment = (string) wp_parse_url( $url, PHP_URL_FRAGMENT );
		// A worksheet gid is often only in the fragment (#gid=NNN).
		if ( ! isset( $carry['gid'] ) && preg_match( '/^gid=(\d+)$/', $fragment, $fm ) ) {
			$carry['gid'] = 'gid=' . $fm[1];
		}

		$ordered = array();
		foreach ( $allowed as $key ) {
			if ( isset( $carry[ $key ] ) ) {
				$ordered[] = $carry[ $key ];
			}
		}
		$suffix = ! empty( $ordered ) ? '?' . implode( '&', $ordered ) : '';

		// The Slides slide selector stays a fragment (#slide=id.gXXX).
		if ( preg_match( '/^slide=([a-zA-Z0-9_.\-]+)$/', $fragment, $sm ) ) {
			$suffix .= '#slide=' . $sm[1];
		}
		return $suffix;
	}

	public static function save( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$before = self::all();
		$clean  = $before;
		$clean['default_ratio']  = self::sanitize_ratio( isset( $raw['default_ratio'] ) ? $raw['default_ratio'] : '4:3', '4:3' );
		$clean['default_height'] = self::sanitize_height( isset( $raw['default_height'] ) ? $raw['default_height'] : '' );
		$clean['lazy_load']      = ( isset( $raw['lazy_load'] ) && '1' === (string) $raw['lazy_load'] ) ? '1' : '0';
		update_option( self::OPTION, $clean );

		// The defaults are baked into cached shortcode HTML (inline ratio/height,
		// the loading attribute), so a full-page cache would keep serving the old
		// markup. Purge it when the cleaned settings actually change.
		if ( $clean != $before && class_exists( 'DPT_CB_Settings' ) && method_exists( 'DPT_CB_Settings', 'purge_page_caches' ) ) {
			DPT_CB_Settings::purge_page_caches();
		}
		return true;
	}
}
