<?php

declare(strict_types=1);

/**
 * Plugin Name:       DMG Read More
 * Plugin URI:        https://github.com/olaseni/dmg-read-more
 * Description:       A plugin that adds a Gutenberg block for inserting post links and a WP-CLI command for searching posts which contain these blocks
 * Version:           1.0.0
 * Requires at least: 6.8.3
 * Requires PHP:      8.3
 * Author:            Olaseni Oluwunmi
 * Author URI:        https://github.com/olaseni
 * Text Domain:       dmg-read-more
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DMG_Read_More;

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

/**
 * Plugin initialization.
 *
 * @return void
 */
function init(): void {
	$plugin_root_path = plugin_dir_path( __FILE__ );

	// Register WP-CLI command if WP-CLI is available
	if ( defined( 'WP_CLI' ) && \WP_CLI ) {
		require_once $plugin_root_path . 'includes/class-dmg-read-more-command.php';
		\WP_CLI::add_command( 'dmg-read-more', DMG_Read_More_Command::class );
	}

	// Initialize block handler
	require_once $plugin_root_path . 'includes/class-dmg-read-more-block.php';
	new DMG_Read_More_Block();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\init' );

/**
 * Plugin activation hook.
 *
 * @return void
 */
function activate(): void {
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );

/**
 * Plugin deactivation hook.
 *
 * @return void
 */
function deactivate(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );
