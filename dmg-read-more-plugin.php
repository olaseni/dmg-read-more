<?php
/**
 * Plugin Name:       DMG Read More
 * Plugin URI:        https://github.com/olaseni/dmg-read-more
 * Description:       A plugin that adds a Gutenberg block for inserting post links and a WP-CLI command for searching posts which contain these blocks
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Olaseni Oluwunmi
 * Author URI:        https://github.com/olaseni
 * Text Domain:       dmg-read-more
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Plugin initialization.
 */
function dmg_read_more_init() {
	$plugin_root_path = plugin_dir_path( __FILE__ );

	// Register WP-CLI command if WP-CLI is available
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once $plugin_root_path . 'includes/class-dmg-read-more-command.php';
		WP_CLI::add_command( 'dmg-read-more', 'DMG_Read_More_Command' );
	}

	// Initialize block handler
	require_once $plugin_root_path . 'includes/class-dmg-read-more-block.php';
	new DMG_Read_More_Block();
}
add_action( 'plugins_loaded', 'dmg_read_more_init' );

/**
 * Activation hook.
 */
function dmg_read_more_activate() {
	// Add activation tasks here
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'dmg_read_more_activate' );

/**
 * Deactivation hook.
 */
function dmg_read_more_deactivate() {
	// Add deactivation tasks here
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'dmg_read_more_deactivate' );
