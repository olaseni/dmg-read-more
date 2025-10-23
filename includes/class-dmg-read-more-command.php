<?php
/**
 * WP-CLI command for DMG Read More functionality.
 *
 * @package WP_Search_Posts
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DMG Read More WP-CLI Command class.
 */
class DMG_Read_More_Command {

	/**
	 * Search for posts.
	 *
	 * ## OPTIONS
	 *
	 * [<search-term>]
	 * : The term to search for in posts.
	 *
	 * [--post-type=<post-type>]
	 * : The post type to search. Default: post
	 *
	 * [--limit=<number>]
	 * : Maximum number of results to return. Default: 10
	 *
	 * ## EXAMPLES
	 *
	 *     wp dmg-read-more search "hello world"
	 *     wp dmg-read-more search "hello" --post-type=page --limit=5
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function search( $args, $assoc_args ) {
		$search_term = isset( $args[0] ) ? $args[0] : '';
		$post_type   = isset( $assoc_args['post-type'] ) ? $assoc_args['post-type'] : 'post';
		$limit       = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 10;

		if ( empty( $search_term ) ) {
			WP_CLI::error( 'Please provide a search term.' );
			return;
		}

		$post_ids = $this->search_posts( $search_term, $post_type, $limit );

		if ( empty( $post_ids ) ) {
			WP_CLI::warning( 'No posts found.' );
			return;
		}

		WP_CLI::log( join( ',', $post_ids ) );
	}

	/**
	 * Execute post search and return post IDs.
	 *
	 * @param string $search_term The term to search for.
	 * @param string $post_type   The post type to search.
	 * @param int    $limit       Maximum number of results.
	 * @return array Array of post IDs.
	 */
	public function search_posts( $search_term, $post_type = 'post', $limit = 10 ) {
		$query_args = array(
			's'              => $search_term,
			'post_type'      => $post_type,
			'posts_per_page' => $limit,
			'post_status'    => 'publish',
		);

		$query = new WP_Query( $query_args );

		$post_ids = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_ids[] = get_the_ID();
			}
		}

		wp_reset_postdata();

		return $post_ids;
	}
}
