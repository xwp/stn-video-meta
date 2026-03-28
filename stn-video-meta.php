<?php
/**
 * Plugin Name: STN Video Meta (Meta API)
 * Description: On post save, detect STN [sendtonews] embeds, fetch STN Video Meta, and store normalized schema/sitemap meta.
 * Version:     1.0.1
 * Author:      Heavy / STN
 * License:     GPL-2.0-or-later
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin main file path (used by Settings for plugin_action_links filter).
 */
define( 'STNVM_MAIN_FILE', __FILE__ );

/**
 * Initialize the plugin.
 */
function stn_video_meta_init(): void {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-settings.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin.php';

	new \STN\VideoMeta\Settings();
	new \STN\VideoMeta\Plugin();
}
add_action( 'plugins_loaded', 'stn_video_meta_init' );
