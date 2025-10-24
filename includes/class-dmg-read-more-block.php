<?php

/**
 * Block handler for DMG Read More Gutenberg block.
 *
 * @package DMG_Read_More
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * DMG_Read_More_Block class.
 *
 * Handles server-side rendering and logic for the DMG Read More block.
 */
class DMG_Read_More_Block
{

	/**
	 * Initialize the block handler.
	 */
	public function __construct()
	{
		// Hook to register block
		add_action('init', array($this, 'register_block'));
	}

	/**
	 * Register the Gutenberg block.
	 */
	public function register_block()
	{
		register_block_type(
			plugin_dir_path(dirname(__FILE__)) . 'build/dmg-read-more',
			array(
				'render_callback' => array($this, 'render_callback'),
			)
		);
	}

	/**
	 * Render callback for DMG Read More block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML output.
	 */
	public function render_callback($attributes)
	{
		// Get post ID from attributes
		$post_id = isset($attributes['postId']) ? absint($attributes['postId']) : 0;

		// Handle empty state
		if (empty($post_id)) {
			return $this->render_empty_state();
		}

		// Fetch and validate post
		$post = get_post($post_id);

		if (! $post || $post->post_status !== 'publish') {
			return $this->render_error_state();
		}

		// Generate and return output
		return $this->render_link($post_id);
	}

	/**
	 * Render empty state when no post is selected.
	 *
	 * @return string HTML for empty state.
	 */
	private function render_empty_state()
	{
		return sprintf(
			'<div class="dmg-read-more-placeholder"><p>%s</p></div>',
			esc_html__('Select a post to display a Read More link', 'dmg-read-more')
		);
	}

	/**
	 * Render error state when post is unavailable.
	 *
	 * @return string HTML for error state.
	 */
	private function render_error_state()
	{
		return sprintf(
			'<div class="dmg-read-more-error"><p>%s</p></div>',
			esc_html__('Selected post is not available', 'dmg-read-more')
		);
	}

	/**
	 * Render the Read More link.
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML for the Read More link.
	 */
	private function render_link($post_id)
	{
		$permalink = get_permalink($post_id);
		$title     = get_the_title($post_id);

		return sprintf(
			'<p class="dmg-read-more">%s<a href="%s">%s</a></p>',
			__('Read More: ', 'dmg-read-more'),
			esc_url($permalink),
			esc_html($title)
		);
	}
}
