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

	// Register Gutenberg block
	register_block_type(
		$plugin_root_path . '/build/dmg-read-more',
		array(
			'render_callback' => 'dmg_read_more_render_block',
		)
	);
}
add_action( 'init', 'dmg_read_more_init' );

/**
 * Render callback for search posts block.
 *
 * @param array $attributes Block attributes.
 * @return string Block HTML output.
 */
function dmg_read_more_render_block( $attributes ) {
	$search_term = isset( $attributes['searchTerm'] ) ? sanitize_text_field( $attributes['searchTerm'] ) : '';

	if ( empty( $search_term ) ) {
		return '<div class="wp-block-wp-search-posts-dmg-search-posts"><p>' . esc_html__( 'Please enter a search term in the block settings.', 'wp-search-posts' ) . '</p></div>';
	}

	$query_args = array(
		's'              => $search_term,
		'post_type'      => 'any',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
	);

	$query = new WP_Query( $query_args );

	if ( ! $query->have_posts() ) {
		return '<div class="wp-block-wp-search-posts-dmg-search-posts"><p>' . esc_html__( 'No posts found.', 'wp-search-posts' ) . '</p></div>';
	}

	ob_start();
	?>
	<div class="wp-block-wp-search-posts-dmg-search-posts">
		<ul class="wp-search-posts-list">
			<?php while ( $query->have_posts() ) : $query->the_post(); ?>
				<li>
					<a href="<?php echo esc_url( get_permalink() ); ?>">
						<?php echo esc_html( get_the_title() ); ?>
					</a>
				</li>
			<?php endwhile; ?>
		</ul>
	</div>
	<?php
	wp_reset_postdata();
	return ob_get_clean();
}

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
