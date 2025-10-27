# STN Video Meta (Meta API)

A lightweight WordPress plugin that, on post save, detects STN/SendtoNews embeds in post content, calls the STN Meta API to retrieve metadata for Heavy-owned videos, and stores normalized data for schema and sitemap.

## What it does

- Scans post content for STN Video embeds:
  - Shortcode: `[sendtonews key="..."]`
- Gutenberg block comment: `<!-- wp:sendtonews/playerselector {"embedKey":"..."} /-->`
- For each shortcode key, calls the STN Meta API:
  - `POST https://api.stnvideo.com/api/v1/video` with `cid`, `authcode`, `searchKey=key`, `searchValue={key}`
- Normalizes API response to a minimal structure and builds a `VideoObject` JSON-LD with whatever fields are available:
  - name (headline)
  - description (summary/description)
  - uploadDate (ISO8601, UTC)
  - thumbnailUrl (if present)
  - duration (ISO8601 if length present)
  - contentUrl (preferred MP4 rendition else `videoUrl`)
- Persists:
  - `hvy_video_schema_data`: JSON-LD used by sitemap and `<head>` schema output
  - `stnvm_status`: one of `ok`, `no_shortcode`, `not_found`, `error`
  - `stnvm_keys`: array of discovered keys
- Caches STN API responses in object cache (Memcached), success for 1 hour and errors for 10 minutes.

## How it works (flow)

1. Hooked to `save_post` (after capability checks and revision/autosave guards) for supported post types (default `post`, `videos`).
2. Extracts keys from content. If none:
   - Sets `stnvm_status = no_shortcode`
   - Deletes `hvy_video_schema_data` if present
   - Returns
3. Keys are tried in this order: shortcodes (content order), then blocks (content order). The first successful API response is used. A `404` response short-circuits with `not_found`.
4. If keys are unchanged and prior status is final (`ok`, `not_found`), returns early without calling the API.
5. Fetches credentials from the STN plugin settings model and options.
6. Calls the Meta API with caching:
   - Successful responses cached for 1 hour
   - Non-success responses cached for 10 minutes to avoid hammering
7. If `success=true`, normalizes and writes JSON-LD and sets `stnvm_status = ok`.
8. If `code=404`, sets `stnvm_status = not_found` and returns.
9. Otherwise sets `stnvm_status = error` and returns.

Notes:
- JSON-LD saves only fields present in the response; sitemap output remains guarded by existing site code (it emits only when `contentUrl` and `duration` are present).
- Meta writes avoid unnecessary updates to reduce DB churn.

## Requirements

- WordPress
- STN "Video Player Selector" plugin installed, activated, and configured
  - Credentials are read from the plugin model `SendtoNews\Models\Settings` (or the corresponding options).
- PHP 8.0+
- Outbound HTTP access to `https://api.stnvideo.com/`

## Configuration

- Post types to process (default `['post','videos']`): use the `stnvm_post_types` filter.
- Schema meta key (default `hvy_video_schema_data`): use the `stnvm_schema_meta_key` filter.
- API timeout (default `10` seconds) is fixed in code; adjust here if needed.
- MP4 selection priority (default `['MP43200k','MP41080p','MP4300k']`): use the `stnvm_mp4_priority` filter.
- Cache TTLs are fixed: success 1 hour, errors 10 minutes.

## Usage

1. Install this folder under `wp-content/plugins/stn-video-meta/`.
2. Activate the plugin in WP admin.
3. Configure STN plugin credentials under Settings → STN Video.
4. Create or edit a post and add an STN embed:
   - Shortcode example: `[sendtonews type="float" key="qI2M6B3PAC-4180995-10456"]`
5. Save the post. The plugin will fetch metadata and write JSON-LD to `hvy_video_schema_data`.
6. Sitemap and `<head>` schema output already consume `hvy_video_schema_data` elsewhere in the theme/plugins.

## Status semantics

- `ok`: JSON-LD saved for at least one key
- `no_shortcode`: no STN embed detected
- `not_found`: API returned 404 for the provided key(s)
- `error`: credentials missing, transport error, invalid JSON, or non-404 error response

## Security & VIP considerations

- No credentials are hard-coded; credentials loaded from the STN plugin settings.
- Uses `wp_remote_post` with 10s timeout; no filesystem writes.
- Caches in object cache with explicit TTLs; avoids unnecessary meta writes.

## Multisite

- Runs per-site; does not switch blogs.

## Extensibility ideas

- Add an admin meta box to show `stnvm_status` and last check time.
- Add an explicit “Recheck” action to force refetch and refresh caches.
- Optional async processing via Action Scheduler or a custom queue if save latency becomes noticeable.
