<?php
/**
 * Plugin Name:       WP Search Posts
 * Plugin URI:        https://github.com/olaseni/wp-search-posts
 * Description:       A WP plugin that adds a Gutenberg block and a wp-cli command for searching posts
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Olaseni Oluwunmi
 * Author URI:        https://github.com/olaseni
 * Text Domain:       wp-search-posts
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 */
define( 'WP_SEARCH_POSTS_VERSION', '1.0.0' );

/**
 * Plugin initialization.
 */
function wp_search_posts_init() {
	// Register WP-CLI command if WP-CLI is available
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-dmg-read-more-command.php';
		WP_CLI::add_command( 'dmg-read-more', 'DMG_Read_More_Command' );
	}
}
add_action( 'plugins_loaded', 'wp_search_posts_init' );

/**
 * Activation hook.
 */
function wp_search_posts_activate() {
	// Add activation tasks here
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wp_search_posts_activate' );

/**
 * Deactivation hook.
 */
function wp_search_posts_deactivate() {
	// Add deactivation tasks here
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wp_search_posts_deactivate' );
