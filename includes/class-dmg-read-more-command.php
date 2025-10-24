<?php

declare(strict_types=1);

/**
 * WP-CLI command for `dmg-read-more`.
 *
 * @package DMG_Read_More
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die;

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
	 * : The term to search for in posts. If omitted, matches all posts.
	 *
	 * [--post-type=<post-type>]
	 * : The post type to search. Default: post
	 *
	 * [--limit=<number>]
	 * : Maximum number of results to return. Default: 100
	 *
	 * [--date-after=<date>]
	 * : Only include posts after this date (Y-m-d format). Default: 30 days ago
	 *
	 * [--date-before=<date>]
	 * : Only include posts before this date (Y-m-d format). Default: today
	 *
	 * ## EXAMPLES
	 *
	 *     wp dmg-read-more search
	 *     wp dmg-read-more search "hello world"
	 *     wp dmg-read-more search "hello" --post-type=page --limit=5
	 *     wp dmg-read-more search --date-after=2024-01-01 --date-before=2024-12-31
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function search( array $args, array $assoc_args ): void {
		$search_term = $args[0] ?? '';
		$post_type   = $assoc_args['post-type'] ?? 'post';
		$limit       = isset( $assoc_args['limit'] ) ? \absint( $assoc_args['limit'] ) : 100;

		// Default to last 30 days if dates not provided
		$date_after  = $assoc_args['date-after'] ?? \date( 'Y-m-d', \strtotime( '-30 days' ) );
		$date_before = $assoc_args['date-before'] ?? \date( 'Y-m-d' );

		// Validate limit
		if ( $limit < 1 ) {
			\WP_CLI::error( 'Limit must be a positive integer.' );
			return;
		}

		// Validate post type exists
		if ( ! \post_type_exists( $post_type ) ) {
			\WP_CLI::error( \sprintf( 'Post type "%s" does not exist.', $post_type ) );
			return;
		}

		// Validate date formats
		if ( ! $this->validate_date( $date_after ) ) {
			\WP_CLI::error( \sprintf( 'Invalid date format for --date-after: "%s". Use Y-m-d format (e.g., 2024-01-31).', $date_after ) );
			return;
		}

		if ( ! $this->validate_date( $date_before ) ) {
			\WP_CLI::error( \sprintf( 'Invalid date format for --date-before: "%s". Use Y-m-d format (e.g., 2024-12-31).', $date_before ) );
			return;
		}

		// Validate date logic (after should be before before)
		if ( \strtotime( $date_after ) > \strtotime( $date_before ) ) {
			\WP_CLI::error( '--date-after must be earlier than or equal to --date-before.' );
			return;
		}

		try {
			$post_ids = $this->search_posts( $search_term, $post_type, $limit, $date_after, $date_before );

			if ( empty( $post_ids ) ) {
				\WP_CLI::warning( 'No posts found.' );
				return;
			}

			\WP_CLI::log( \join( ',', $post_ids ) );
		} catch ( \Exception $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
	}

	/**
	 * Execute post search and return post IDs.
	 *
	 * @param string      $search_term The term to search for.
	 * @param string      $post_type   The post type to search.
	 * @param int         $limit       Maximum number of results.
	 * @param string|null $date_after  Posts published after this date (Y-m-d).
	 * @param string|null $date_before Posts published before this date (Y-m-d).
	 * @return array Array of post IDs.
	 */
	public function search_posts( string $search_term, string $post_type = 'post', int $limit = 10, ?string $date_after = null, ?string $date_before = null ): array {
		$query_args = [
			'post_type'              => $post_type,
			'posts_per_page'         => $limit,
			'post_status'            => 'publish',
			'no_found_rows'          => true,
			'fields'                 => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		// Add search term if provided
		if ( ! empty( $search_term ) ) {
			$query_args['s'] = $search_term;
		}

		// Add date query if dates are provided
		if ( $date_after || $date_before ) {
			$query_args['date_query'] = [];

			if ( $date_after ) {
				$query_args['date_query'][] = [
					'after'     => $date_after,
					'inclusive' => true,
				];
			}

			if ( $date_before ) {
				$query_args['date_query'][] = [
					'before'    => $date_before,
					'inclusive' => true,
				];
			}
		}

		$query = new \WP_Query( $query_args );

		if ( $query->have_posts() ) {
			return $query->posts;
		}

		return [];
	}

	/**
	 * Validate date format (Y-m-d).
	 *
	 * @param string $date Date string to validate.
	 * @return bool True if valid, false otherwise.
	 */
	private function validate_date( string $date ): bool {
		if ( empty( $date ) ) {
			return false;
		}

		$parsed_date = \date_parse_from_format( 'Y-m-d', $date );

		return $parsed_date['error_count'] === 0
			&& $parsed_date['warning_count'] === 0
			&& \checkdate( $parsed_date['month'], $parsed_date['day'], $parsed_date['year'] );
	}
}
