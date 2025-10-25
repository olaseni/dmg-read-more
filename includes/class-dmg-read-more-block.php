<?php

declare(strict_types=1);

/**
 * Block handler for DMG Read More Gutenberg block.
 *
 * @package DMG_Read_More
 */

namespace DMG_Read_More;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die;

/**
 * DMG_Read_More_Block class.
 *
 * Handles server-side rendering and logic for the DMG Read More block.
 */
class DMG_Read_More_Block {

	/**
	 * The block name/identifier.
	 * @var string
	 */
	public const string BLOCK_NAME = 'dmg-read-more/dmg-read-more';

	/**
	 * The post meta flag to use for signaling that the post contains a block.
	 * @var string
	 */
	public const string META_FLAG = '_has_dmg_read_more_block';

	/**
	 * Initialize the block handler.
	 */
	public function __construct() {
		// Hook to register block
		add_action( 'init', [ $this, 'register_block' ] );

		// Hook to index block usage for efficient searching
		add_action( 'save_post', [ $this, 'index_block_usage' ], 10, 2 );
	}

	/**
	 * Register the Gutenberg block.
	 *
	 * @return void
	 */
	public function register_block(): void {
		register_block_type(
			plugin_dir_path( dirname( __FILE__ ) ) . 'build/dmg-read-more',
			[
				'render_callback' => [ $this, 'render_callback' ],
			]
		);
	}

	/**
	 * Render callback for DMG Read More block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML output.
	 */
	public function render_callback( array $attributes ): string {
		// Get post ID from attributes
		$post_id = absint( $attributes['postId'] ?? 0 );

		// Handle empty state
		if ( empty( $post_id ) ) {
			return $this->render_empty_state();
		}

		// Fetch and validate post
		$post = get_post( $post_id );

		if ( ! $post || $post->post_status !== 'publish' ) {
			return $this->render_error_state();
		}

		// Generate and return output
		return $this->render_link( $post_id );
	}

	/**
	 * Render empty state when no post is selected.
	 *
	 * @return string HTML for empty state.
	 */
	private function render_empty_state(): string {
		return sprintf(
			'<div class="dmg-read-more-placeholder"><p>%s</p></div>',
			esc_html__( 'Select a post to display a Read More link', 'dmg-read-more' )
		);
	}

	/**
	 * Render error state when post is unavailable.
	 *
	 * @return string HTML for error state.
	 */
	private function render_error_state(): string {
		return sprintf(
			'<div class="dmg-read-more-error"><p>%s</p></div>',
			esc_html__( 'Selected post is not available', 'dmg-read-more' )
		);
	}

	/**
	 * Render the Read More link.
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML for the Read More link.
	 */
	private function render_link( int $post_id ): string {
		$permalink = get_permalink( $post_id );
		$title     = get_the_title( $post_id );

		return sprintf(
			'<p class="dmg-read-more">%s<a href="%s">%s</a></p>',
			__( 'Read More: ', 'dmg-read-more' ),
			esc_url( $permalink ),
			esc_html( $title )
		);
	}

	/**
	 * Index block usage on post save for efficient searching.
	 *
	 * Maintains a post meta flag (_has_dmg_read_more_block) that indicates
	 * whether a post contains the DMG Read More block. This enables fast
	 * indexed lookups via meta_query instead of scanning post_content.
	 *
	 * Strategy: Only create meta row when block exists; delete it when block is removed.
	 * This reduces database bloat for posts without the block.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function index_block_usage( int $post_id, \WP_Post $post ): void {
		// Skip autosaves and revisions
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Check if post contains the DMG Read More block
		$has_block = has_block( self::BLOCK_NAME, $post->post_content );

		if ( $has_block ) {
			// Add/update meta flag when block exists
			update_post_meta( $post_id, self::META_FLAG, '1' );
		} else {
			// Remove meta flag when block doesn't exist
			delete_post_meta( $post_id, self::META_FLAG );
		}
	}
}
