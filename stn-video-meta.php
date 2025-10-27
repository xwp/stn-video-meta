<?php
/**
 * Plugin Name: STN Video Meta (Meta API)
 * Description: On post save, detect STN [sendtonews] embeds, fetch STN Video Meta, and store normalized schema/sitemap meta.
 * Version:     1.0.0
 * Author:      Heavy / STN
 * License:     GPL-2.0-or-later
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize the plugin.
 */
function stn_video_meta_init(): void {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin.php';
	// Bootstrap the plugin.
	new \STN\VideoMeta\Plugin();
}
add_action( 'plugins_loaded', 'stn_video_meta_init' );
