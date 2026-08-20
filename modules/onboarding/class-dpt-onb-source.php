<?php
/**
 * Onboarding module - turning a manifest item into a downloadable ZIP.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_ONB_Source {

	const TRANSIENT_PREFIX = 'dpt_onb_gh_';
	const RELEASE_PREFIX   = 'dpt_onb_rel_';
	const CACHE_TTL        = 6 * HOUR_IN_SECONDS;
	const FAILURE_TTL      = 15 * MINUTE_IN_SECONDS;

	/**
	 * Hosts a GitHub download can legitimately touch, including the redirect
	 * targets an asset or zipball URL lands on.
	 */
	const GITHUB_HOSTS = array(
		'github.com',
		'api.github.com',
		'codeload.github.com',
		'objects.githubusercontent.com',
		'release-assets.githubusercontent.com',
	);

	/**
	 * The agent this module identifies itself with.
	 *
	 * WordPress's default user agent embeds the site's own URL. There is no
	 * reason to hand a client's address to GitHub for a public download.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return 'Digitizer Pro Tools/' . DPT_VERSION;
	}

	/**
	 * Replace the user agent on requests to GitHub.
	 *
	 * Attached to http_request_args around a download, because the release
	 * lookup and the package download are two separate requests: setting the
	 * agent on the lookup alone still discloses the site URL when the archive
	 * itself is fetched.
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public static function anonymize_request( $args, $url = '' ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		if ( in_array( $host, self::GITHUB_HOSTS, true ) ) {
			$args['user-agent'] = self::user_agent();
		}
		return $args;
	}

	/**
	 * The best ZIP URL in a GitHub release payload.
	 *
	 * Prefers a published release asset, because that is a real build; falls
	 * back to the source zipball, which is what a repository without release
	 * assets offers. Pure - no HTTP, no options - so the choice is testable.
	 *
	 * @param array $release Decoded release payload.
	 * @return string|null
	 */
	public static function pick_asset( $release ) {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				$url = (string) $asset['browser_download_url'];
				// https only: this URL is handed to the upgrader, which will
				// download and execute what comes back.
				if ( 0 !== stripos( $url, 'https://' ) ) {
					continue;
				}
				$path = (string) wp_parse_url( $url, PHP_URL_PATH );
				if ( '.zip' === strtolower( substr( $path, -4 ) ) ) {
					return $url;
				}
			}
		}
		if ( ! empty( $release['zipball_url'] ) ) {
			return (string) $release['zipball_url'];
		}
		return null;
	}

	/**
	 * ZIP URL for a GitHub item.
	 *
	 * Cached for six hours. GitHub allows 60 unauthenticated requests per hour
	 * per IP, and a shared host shares that budget with every other site on it,
	 * so an uncached lookup per item per attempt is not affordable. Failures
	 * are never cached - a rate limit clears, and the next run should retry.
	 *
	 * @param array $item Manifest item.
	 * @return string|WP_Error
	 */
	public static function github_zip_url( $item ) {
		$repo = $item['repo'];
		$key  = self::TRANSIENT_PREFIX . md5( $repo );

		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$api = 'https://api.github.com/repos/' . $repo . '/releases/latest';
		$res = wp_remote_get(
			$api,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => self::user_agent(),
				),
			)
		);

		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'dpt_onb_github_http', $res->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $res );

		// 404 means the repository publishes no releases - not an error, just
		// the other source.
		if ( 404 === $code ) {
			$url = 'https://api.github.com/repos/' . $repo . '/zipball';
			set_transient( $key, $url, self::CACHE_TTL );
			return $url;
		}

		if ( 200 !== $code ) {
			return new WP_Error(
				'dpt_onb_github_http',
				sprintf(
					/* translators: 1: repository, 2: HTTP status code */
					__( 'GitHub answered %2$d for %1$s.', 'digitizer-pro-tools' ),
					$repo,
					$code
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'dpt_onb_github_parse', __( 'GitHub returned a response that could not be read.', 'digitizer-pro-tools' ) );
		}

		$url = self::pick_asset( $body );
		if ( null === $url ) {
			return new WP_Error( 'dpt_onb_github_asset', __( 'That release has no downloadable archive.', 'digitizer-pro-tools' ) );
		}

		set_transient( $key, $url, self::CACHE_TTL );
		return $url;
	}

	/**
	 * The latest published release of a GitHub repository: its version and the
	 * archive to install.
	 *
	 * Separate from github_zip_url(), which answers the install path and can
	 * fall back to a branch zipball. A zipball has no version, and offering an
	 * update needs one, so a repository without releases simply has no update
	 * information here.
	 *
	 * Failures are cached, unlike the install path's. This runs behind
	 * WordPress's update transient rather than behind a button, so an
	 * unreachable GitHub or a spent rate limit would otherwise mean a request
	 * on every admin page load. Fifteen minutes is short enough that a rate
	 * limit clearing is noticed within the hour.
	 *
	 * The timeout is shorter than the install path's. This runs inside
	 * WordPress's own update check, where three repositories are asked in
	 * sequence and the whole check is something the operator is waiting on.
	 *
	 * @param string $repo    Owner/name pair.
	 * @param int    $timeout Seconds to wait.
	 * @return array|WP_Error array( version, package ), version without the tag's leading v.
	 */
	public static function github_release( $repo, $timeout = 8 ) {
		$key    = self::RELEASE_PREFIX . md5( (string) $repo );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return isset( $cached['error'] )
				? new WP_Error( 'dpt_onb_github_cached_error', (string) $cached['error'] )
				: $cached;
		}

		$res = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/releases/latest',
			array(
				'timeout' => (int) $timeout,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => self::user_agent(),
				),
			)
		);

		$fail = function ( $message ) use ( $key ) {
			set_transient( $key, array( 'error' => $message ), self::FAILURE_TTL );
			return new WP_Error( 'dpt_onb_github_release', $message );
		};

		if ( is_wp_error( $res ) ) {
			return $fail( $res->get_error_message() );
		}
		if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return $fail(
				sprintf(
					/* translators: 1: repository, 2: HTTP status code */
					__( 'GitHub answered %2$d for %1$s.', 'digitizer-pro-tools' ),
					$repo,
					wp_remote_retrieve_response_code( $res )
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			return $fail( __( 'GitHub returned a response that could not be read.', 'digitizer-pro-tools' ) );
		}

		$release = self::release_from( $body );
		if ( null === $release ) {
			return $fail( __( 'That release has no downloadable archive.', 'digitizer-pro-tools' ) );
		}

		set_transient( $key, $release, self::CACHE_TTL );
		return $release;
	}

	/**
	 * Version and package from a decoded release payload.
	 *
	 * Pure, so the shapes GitHub answers with are testable. A release with no
	 * usable archive, or none this can name a version for, is not an update.
	 * The zipball is deliberately not used as a fallback here: it exists at
	 * every commit, and installing one over a tagged release would replace a
	 * known version with an unknown one.
	 *
	 * @param array $release Decoded release payload.
	 * @return array|null
	 */
	public static function release_from( $release ) {
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return null;
		}
		if ( ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			return null;
		}
		$version = ltrim( (string) $release['tag_name'], 'vV' );
		if ( '' === $version ) {
			return null;
		}
		$package = self::pick_asset( array( 'assets' => isset( $release['assets'] ) ? $release['assets'] : array() ) );
		if ( null === $package ) {
			return null;
		}
		return array(
			'version' => $version,
			'package' => $package,
		);
	}

	/**
	 * ZIP URL for any manifest item.
	 *
	 * @param array $item Manifest item.
	 * @return string|WP_Error
	 */
	public static function zip_url( $item ) {
		if ( 'github' === $item['source'] ) {
			return self::github_zip_url( $item );
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		$args = array(
			'slug'   => $item['slug'],
			'fields' => array( 'sections' => false ),
		);
		$res  = ( 'theme' === $item['type'] )
			? themes_api( 'theme_information', $args )
			: plugins_api( 'plugin_information', $args );

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res->download_link ) ) {
			return new WP_Error(
				'dpt_onb_wporg_no_link',
				sprintf(
					/* translators: %s: item slug */
					__( 'WordPress.org returned no download for %s.', 'digitizer-pro-tools' ),
					$item['slug']
				)
			);
		}
		return (string) $res->download_link;
	}
}
