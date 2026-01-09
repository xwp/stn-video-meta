<?php

namespace STN\VideoMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	/**
	 * Cached credentials.
	 *
	 * @var array{cid:string, authcode:string}|null
	 */
	private $credentials = null;
	/**
	 * STN Meta API endpoint.
	 */
	private const API_URL = 'https://api.stnvideo.com/api/v1/video';
	/**
	 * Cache group for object cache entries.
	 */
	private const CACHE_GROUP = 'stnvm';

	/**
	 * Post meta keys (internal storage for normalized data and status).
	 */
	private const META_KEY_STATUS       = 'stnvm_status'; // Possible values: ok, error, no_shortcode, not_found
	private const META_KEY_KEYS         = 'stnvm_keys'; // All player keys found (for change detection).
	private const META_KEY_ACTIVE_KEY   = 'stnvm_active_key'; // The successful key used for schema - only one is used.
	private const META_KEY_ACTIVE_CID   = 'stnvm_active_cid'; // The CID for the active key.

	/**
	 * Default schema meta key consumed by the host site.
	 */
	private const DEFAULT_SCHEMA_META_KEY = 'hvy_video_schema_data';

	/**
	 * HTTP request timeout (seconds).
	 */
	private const REQUEST_TIMEOUT = 10;

	/**
	 * Preferred MP4 conversions in order.
	 */
	private const MP4_PRIORITY = [ 'MP43200k', 'MP41080p', 'MP4300k' ];

	/**
	 * Register hooks for the plugin lifecycle.
	 */
	public function __construct() {
		// Register processing hook: run on save to detect STN embeds and update schema.
		add_action( 'save_post', [ $this, 'on_save_post' ], 10, 3 );
	}

	/**
	 * Whether the given post type should be processed.
	 *
	 * @param string $post_type Post type name.
	 * @return bool True if allowed.
	 */
	private function is_supported_post_type( string $post_type ): bool {
		$allowed = (array) apply_filters( 'stnvm_post_types', [ 'post', 'videos' ] );
		return in_array( $post_type, $allowed, true );
	}

	/**
	 * Update post meta only if the new value differs from the stored value.
	 *
	 * @param int   $post_id   Post ID.
	 * @param string $key       Meta key.
	 * @param mixed  $new_value New value to store.
	 * @return void
	 */
	private function update_meta_if_changed( int $post_id, string $key, mixed $new_value ): void {
		// Avoid unnecessary DB writes by checking if the value actually changed.
		$old_value = get_post_meta( $post_id, $key, true );
		if ( $old_value !== $new_value ) {
			update_post_meta( $post_id, $key, $new_value );
		}
	}

	/**
	 * Resolve STN credentials from the STN plugin settings (model/options).
	 *
	 * @return array{cid:string,authcode:string}
	 */
	private function get_credentials(): array {
		// Return cached credentials for the current request if already resolved.
		if ( is_array( $this->credentials ) ) {
			return $this->credentials;
		}

		$cid  = '';
		$auth = '';

		// STN plugin stores settings in an option as JSON string (contains cid/authcode).
		$opt_raw = get_option( 'model_sendtonews_settings' );
		if ( is_string( $opt_raw ) && '' !== $opt_raw ) {
			$opt = json_decode( $opt_raw, true );

			if ( ! is_array( $opt ) ) {
				return [
					'cid'      => '',
					'authcode' => '',
				];
			}

			$cid  = (string) ( $opt['cid'] ?? '' );
			$auth = (string) ( $opt['authcode'] ?? '' );
		}

		$this->credentials = [
			'cid'      => $cid,
			'authcode' => $auth,
		];
		return $this->credentials;
	}

	/**
	 * Get STN customer ID.
	 *
	 * @return string
	 */
	private function get_cid(): string {
		$creds = $this->get_credentials();
		return (string) $creds['cid'];
	}

	/**
	 * Get STN auth code.
	 *
	 * @return string
	 */
	private function get_authcode(): string {
		$creds = $this->get_credentials();
		return (string) $creds['authcode'];
	}

	/**
	 * Get the schema meta key where JSON-LD is stored.
	 *
	 * @return string
	 */
	private function get_schema_meta_key(): string {
		return (string) apply_filters( 'stnvm_schema_meta_key', self::DEFAULT_SCHEMA_META_KEY );
	}

	/**
	 * save_post callback: detect STN keys, fetch Meta API, and persist normalized meta/schema.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an existing post update.
	 * @return void
	 */
	public function on_save_post( int $post_id, \WP_Post $post, bool $update ): void {
		// Guard against revisions/autosaves, unsupported post types, and insufficient permissions.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $this->is_supported_post_type( (string) $post->post_type ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Extract STN video keys from content (shortcodes and blocks) and synchronize stored keys.
		$content      = (string) $post->post_content;
		$keys_current = $this->extract_stn_keys_from_content( $content );
		$keys_stored  = (array) get_post_meta( $post_id, self::META_KEY_KEYS, true );
		$status_prev  = (string) get_post_meta( $post_id, self::META_KEY_STATUS, true );

		$this->update_meta_if_changed( $post_id, self::META_KEY_KEYS, $keys_current );

		// No STN embeds present: mark status and remove previous schema JSON (if any).
		if ( empty( $keys_current ) ) {
			$this->update_meta_if_changed( $post_id, self::META_KEY_STATUS, 'no_shortcode' );
			if ( metadata_exists( 'post', $post_id, $this->get_schema_meta_key() ) ) {
				delete_post_meta( $post_id, $this->get_schema_meta_key() );
			}
			delete_post_meta( $post_id, self::META_KEY_ACTIVE_KEY );
			delete_post_meta( $post_id, self::META_KEY_ACTIVE_CID );
			return;
		}

		$keys_changed = ( $keys_current !== $keys_stored );

		// If keys are unchanged and status is final (ok, not_found), return early without calling the API.
		if ( ! $keys_changed && in_array( $status_prev, [ 'ok', 'not_found' ], true ) ) {
			return;
		}

		// Credentials are required to call STN Meta API.
		if ( '' === $this->get_cid() || '' === $this->get_authcode() ) {
			$this->update_meta_if_changed( $post_id, self::META_KEY_STATUS, 'error' );
			return;
		}

		// Iterate over detected keys until a successful API response or a 404 is encountered.
		$usable_meta   = [];
		$successful_key = '';
		foreach ( $keys_current as $key ) {
			$api = $this->fetch_meta_for_key( $key );
			if ( ( isset( $api['success'] ) && true === $api['success'] ) ) {
				$usable_meta    = $this->normalize_meta( $api, (string) get_the_title( $post_id ) );
				$successful_key = $key;
				break;
			}
			$code_val = isset( $api['code'] ) ? (int) $api['code'] : 0;
			if ( 404 === $code_val ) {
				$this->update_meta_if_changed( $post_id, self::META_KEY_STATUS, 'not_found' );
				return;
			}
		}

		if ( ! empty( $usable_meta ) && '' !== $successful_key ) {
			$this->update_meta_if_changed( $post_id, self::META_KEY_STATUS, 'ok' );

			$json = $this->build_schema_json( $usable_meta );
			$this->update_meta_if_changed( $post_id, $this->get_schema_meta_key(), (string) $json );

			// Store the successful key and CID for sitemap player URL generation.
			$this->update_meta_if_changed( $post_id, self::META_KEY_ACTIVE_KEY, $successful_key );
			$this->update_meta_if_changed( $post_id, self::META_KEY_ACTIVE_CID, $this->get_cid() );
			return;
		}

		// Persist error status and clear stale schema JSON on failure.
		$this->update_meta_if_changed( $post_id, self::META_KEY_STATUS, 'error' );
		if ( metadata_exists( 'post', $post_id, $this->get_schema_meta_key() ) ) {
			delete_post_meta( $post_id, $this->get_schema_meta_key() );
		}
		delete_post_meta( $post_id, self::META_KEY_ACTIVE_KEY );
		delete_post_meta( $post_id, self::META_KEY_ACTIVE_CID );
	}

	/**
	 * Extract STN keys from shortcodes and Gutenberg block comments in content.
	 *
	 * @param string $content Post content (raw).
	 * @return array<int,string> List of unique keys.
	 */
	private function extract_stn_keys_from_content( string $content ): array {
		$keys = [];
		if ( '' === $content ) {
			return $keys;
		}

		// Parse classic shortcode embeds: [sendtonews key="..."]
		if ( function_exists( 'has_shortcode' ) && has_shortcode( $content, 'sendtonews' ) ) {
			$pattern = get_shortcode_regex( [ 'sendtonews' ] );
			if ( preg_match_all( '/' . $pattern . '/s', $content, $m ) > 0 ) {
				foreach ( $m[3] as $atts_raw ) {
					$atts = $this->parse_shortcode_atts_safe( (string) $atts_raw );
					if ( isset( $atts['key'] ) && '' !== $atts['key'] ) {
						$keys[] = sanitize_text_field( (string) $atts['key'] );
					}
				}
			}
		}

		// Parse Gutenberg block comment embeds: <!-- wp:sendtonews/playerselector {"embedKey":"..."} -->
		if ( false !== strpos( $content, 'wp:sendtonews' ) ) {
			if ( preg_match_all( '/<!--\s*wp:sendtonews\/playerselector\b[^>]*({.*?})[^>]*-->/', $content, $matches ) > 0 ) {
				foreach ( $matches[1] as $json ) {
					$args = json_decode( trim( (string) $json ), true );
					if ( is_array( $args ) ) {
						$key = $args['embedKey'] ?? ( $args['key'] ?? '' );
						if ( '' !== $key ) {
							$keys[] = sanitize_text_field( (string) $key );
						}
					}
				}
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Parse shortcode attributes safely and sanitize values.
	 *
	 * @param string $text Shortcode attribute string.
	 * @return array<string,string>
	 */
	private function parse_shortcode_atts_safe( string $text ): array {
		// Use core parser then sanitize each attribute to prevent unsafe values.
		$atts = shortcode_parse_atts( $text );
		if ( empty( $atts ) ) {
			return [];
		}
		foreach ( $atts as $k => $v ) {
			$atts[ $k ] = sanitize_text_field( (string) $v );
		}
		return $atts;
	}

	/**
	 * Call the STN Meta API for a specific key with transient caching.
	 *
	 * @param string $key STN video key.
	 * @return array<string,mixed>
	 */
	private function fetch_meta_for_key( string $key ): array {
		// Cache API responses by key to reduce HTTP calls during editing flows.
		$cache_key = 'stnvm_meta_' . md5( $key );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$creds = $this->get_credentials();
		$cid   = (string) $creds['cid'];
		$auth  = (string) $creds['authcode'];

		if ( '' === $cid || '' === $auth ) {
			$result = [
				'success' => false,
				'code'    => 500,
				'errors'  => [ 'Missing STN credentials.' ],
			];
			wp_cache_set( $cache_key, $result, self::CACHE_GROUP, 10 * MINUTE_IN_SECONDS );
			return $result;
		}

		// Build POST request payload expected by STN Meta API.
		$args = [
			'timeout'     => self::REQUEST_TIMEOUT,
			'headers'     => [ 'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8' ],
			'body'        => [
				'cid'         => $cid,
				'authcode'    => $auth,
				'searchKey'   => 'key',
				'searchValue' => $key,
			],
			'data_format' => 'body',
		];

		$response = wp_remote_post( self::API_URL, $args );
		if ( is_wp_error( $response ) ) {
			$result = [
				'success' => false,
				'code'    => 500,
				'errors'  => [ $response->get_error_message() ],
			];
			wp_cache_set( $cache_key, $result, self::CACHE_GROUP, 10 * MINUTE_IN_SECONDS );
			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( ! is_array( $json ) ) {
			$result = [
				'success' => false,
				'code'    => $code,
				'errors'  => [ 'Invalid JSON from API.' ],
			];
			wp_cache_set( $cache_key, $result, self::CACHE_GROUP, 10 * MINUTE_IN_SECONDS );
			return $result;
		}

		// Successful responses live longer in cache than failures.
		if ( isset( $json['success'] ) && true === $json['success'] ) {
			wp_cache_set( $cache_key, $json, self::CACHE_GROUP, HOUR_IN_SECONDS );
		} else {
			wp_cache_set( $cache_key, $json, self::CACHE_GROUP, 10 * MINUTE_IN_SECONDS );
		}

		return $json;
	}

	/**
	 * Normalize Meta API response into a compact structure used for schema.
	 *
	 * @param array<string,mixed> $api        Decoded API response.
	 * @param string              $post_title Current post title to override VideoObject name.
	 * @return array{name:string,description:string,uploadDate:string,thumbnail:string,duration:string,contentUrl:string}
	 */
	private function normalize_meta( array $api, string $post_title = '' ): array {
		// Use the first story media as the primary video source.
		$media_first = [];
		if ( isset( $api['storyMedias'] ) && is_array( $api['storyMedias'] ) && ! empty( $api['storyMedias'] ) ) {
			$media_first = (array) $api['storyMedias'][0];
		}

		// Prefer the post title for the VideoObject name; fall back to API headline.
		$title_post = '' !== $post_title ? wp_strip_all_tags( $post_title ) : '';
		$title_api  = isset( $api['headline'] ) ? (string) $api['headline'] : '';
		$title      = '' !== $title_post ? $title_post : $title_api;

		// Prefer summary for concise description; fall back to full description.
		$summary     = isset( $api['summary'] ) ? (string) $api['summary'] : '';
		$description = isset( $api['description'] ) ? (string) $api['description'] : '';
		$desc        = '' !== $summary ? $summary : $description;

		// Normalize publish date to ISO8601 (UTC) for schema consumers.
		$upload_raw = isset( $api['publishDate'] ) ? (string) $api['publishDate'] : '';
		$upload_iso = $this->to_iso8601_z( $upload_raw );

		// Derive media fields: thumbnail, ISO8601 duration, and best MP4 rendition.
		$thumb       = isset( $media_first['thumbnailUrl'] ) ? (string) $media_first['thumbnailUrl'] : '';
		$seconds     = isset( $media_first['length'] ) ? (float) $media_first['length'] : 0.0;
		$duration    = $this->iso8601_duration_from_seconds( $seconds );
		$content_url = $this->pick_best_mp4( $media_first );

		return [
			'name'        => $title,
			'description' => $desc,
			'uploadDate'  => $upload_iso,
			'thumbnail'   => $thumb,
			'duration'    => $duration,
			'contentUrl'  => $this->normalize_https_url( $content_url ),
		];
	}

	/**
	 * Convert a date string to ISO8601 (UTC 'Z') or empty string when invalid.
	 *
	 * @param string $date Input date string.
	 * @return string ISO8601 or empty.
	 */
	private function to_iso8601_z( string $date ): string {
		if ( '' === $date ) {
			return '';
		}
		$ts = strtotime( $date );
		if ( false === $ts ) {
			return '';
		}
		// Format timestamp as ISO8601 string with timezone offset.
		return gmdate( 'c', $ts );
	}

	/**
	 * Convert seconds to ISO8601 duration string.
	 *
	 * @param float $seconds Duration in seconds.
	 * @return string ISO8601 duration.
	 */
	private function iso8601_duration_from_seconds( float $seconds ): string {
		if ( 0.0 >= $seconds ) {
			return 'PT0S';
		}
		// Use floor to avoid overstating play length when seconds have fractional part.
		$total   = (int) floor( $seconds );
		$hours   = (int) floor( $total / 3600 );
		$minutes = (int) floor( ( $total % 3600 ) / 60 );
		$secs    = (int) ( $total % 60 );

		$out = 'PT';
		if ( 0 !== $hours ) {
			$out .= $hours . 'H';
		}
		if ( 0 !== $minutes ) {
			$out .= $minutes . 'M';
		}
		if ( 0 !== $secs || ( 0 === $hours && 0 === $minutes ) ) {
			$out .= $secs . 'S';
		}
		return $out;
	}

	/**
	 * Pick the best MP4 rendition from media_conversions or fall back to videoUrl.
	 *
	 * @param array<string,mixed> $story_media First story media item.
	 * @return string Content URL or empty string.
	 */
	private function pick_best_mp4( array $story_media ): string {
		// Allow overriding rendition preference; defaults prioritize higher bitrate first.
		$priority = (array) apply_filters( 'stnvm_mp4_priority', self::MP4_PRIORITY );
		if ( ! empty( $story_media['media_conversions'] ) && is_array( $story_media['media_conversions'] ) ) {
			foreach ( $priority as $type ) {
				foreach ( $story_media['media_conversions'] as $conv ) {
					if (
						isset( $conv['type'], $conv['mediaUrl'] ) &&
						$type === $conv['type'] &&
						'' !== $conv['mediaUrl']
					) {
						return (string) $conv['mediaUrl'];
					}
				}
			}
		}
		if ( isset( $story_media['videoUrl'] ) && '' !== $story_media['videoUrl'] ) {
			return (string) $story_media['videoUrl'];
		}
		return '';
	}

	/**
	 * Normalize protocol-relative URLs to https.
	 *
	 * @param string $url Input URL.
	 * @return string Normalized URL.
	 */
	private function normalize_https_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return 'https:' . $url;
		}
		return $url;
	}

	/**
	 * Build VideoObject JSON-LD string with available fields only.
	 *
	 * @param array<string,string> $usable_meta Normalized meta.
	 * @return string JSON-LD string.
	 */
	private function build_schema_json( array $usable_meta ): string {
		// Include only non-empty fields to keep JSON-LD compact and clean.
		$data = array_filter(
			[
				'@context'     => 'https://schema.org',
				'@type'        => 'VideoObject',
				'name'         => $usable_meta['name'] ?? '',
				'description'  => $usable_meta['description'] ?? '',
				'uploadDate'   => $usable_meta['uploadDate'] ?? '',
				'thumbnailUrl' => ! empty( $usable_meta['thumbnail'] ) ? [ $this->normalize_https_url( $usable_meta['thumbnail'] ) ] : null,
				'duration'     => ( ! empty( $usable_meta['duration'] ) && 'PT0S' !== $usable_meta['duration'] ) ? $usable_meta['duration'] : null,
				'contentUrl'   => ! empty( $usable_meta['contentUrl'] ) ? $this->normalize_https_url( $usable_meta['contentUrl'] ) : null,
			],
			static function ( $v ) {
				return ( null !== $v && '' !== $v );
			}
		);

		// Encode safely for inline script tag while keeping URLs readable.
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT );
	}
}
