<?php

declare(strict_types=1);

/**
 * WP-CLI command for `dmg-read-more`.
 *
 * @package DMG_Read_More
 */

namespace DMG_Read_More;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die;

/**
 * DMG Read More WP-CLI Command class.
 */
class DMG_Read_More_Command {

	private const string QUERY_NAME = 'search_posts_containing_dmg_read_more';

	/**
	 * Search for posts containing DMG Read More blocks.
	 *
	 * Finds posts that contain the DMG Read More Gutenberg block.
	 * Automatically searches across all post types that support the block editor.
	 * Uses indexed lookups for optimal performance at scale.
	 *
	 * ## OPTIONS
	 *
	 * [--date-after=<date>]
	 * : Only include posts published after this date (Y-m-d format). Default: 30 days ago
	 *
	 * [--date-before=<date>]
	 * : Only include posts published before this date (Y-m-d format). Default: today
	 *
	 * [--debug-sql]
	 * : Log SQL queries to console for debugging.
	 *
	 * ## EXAMPLES
	 *
	 *     # List all posts with the DMG Read More block (last 30 days)
	 *     wp dmg-read-more search
	 *
	 *     # Find posts with block from specific date range
	 *     wp dmg-read-more search --date-after=2024-01-01 --date-before=2024-12-31
	 *
	 *     # All posts with block across all post types, all time
	 *     wp dmg-read-more search --date-after=2020-01-01
	 *
	 *     # Debug SQL query performance
	 *     wp dmg-read-more search --debug-sql
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function search( array $args, array $assoc_args ): void {
		$debug_sql = isset( $assoc_args['debug-sql'] );
		$this->activate_filters( $debug_sql );

		// Default to last 30 days if dates not provided
		$date_after  = $assoc_args['date-after'] ?? date( 'Y-m-d', strtotime( '-30 days' ) );
		$date_before = $assoc_args['date-before'] ?? date( 'Y-m-d' );

		// Validate date formats
		if ( ! $this->validate_date( $date_after ) ) {
			\WP_CLI::error( sprintf( 'Invalid date format for --date-after: "%s". Use Y-m-d format (e.g., 2024-01-31).', $date_after ) );
			return;
		}

		if ( ! $this->validate_date( $date_before ) ) {
			\WP_CLI::error( sprintf( 'Invalid date format for --date-before: "%s". Use Y-m-d format (e.g., 2024-12-31).', $date_before ) );
			return;
		}

		// Validate date logic (after should be before before)
		if ( strtotime( $date_after ) > strtotime( $date_before ) ) {
			\WP_CLI::error( '--date-after must be earlier than or equal to --date-before.' );
			return;
		}

		try {
			$post_ids = $this->search_posts( $date_after, $date_before );

			if ( empty( $post_ids ) ) {
				\WP_CLI::warning( 'No posts found.' );
				return;
			}

			\WP_CLI::log( join( ',', $post_ids ) );
		} catch ( \Exception $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
	}

	/**
	 * Get all post types that support the block editor.
	 *
	 * @return array Array of post type slugs that support the block editor.
	 */
	private function get_block_editor_post_types(): array {
		$post_types = get_post_types( [ 'public' => true ], 'names' );
		$block_editor_post_types = [];

		foreach ( $post_types as $post_type ) {
			if ( use_block_editor_for_post_type( $post_type ) ) {
				$block_editor_post_types[] = $post_type;
			}
		}

		return $block_editor_post_types;
	}

	/**
	 * Activate filters that are needed to modify the WP_Query for performance
	 *
	 * @param bool $debug_sql Whether to log SQL queries to console.
	 * @return void
	 */
	private function activate_filters( bool $debug_sql = false ): void
	{
		add_action('pre_get_posts', function ($query) use ( $debug_sql ) {
			// Target only this named query
			if (! $query->get(self::QUERY_NAME)) {
				return;
			}

			// Ordering impacts queries at this scale. We don't need it. And for 1-1 matching,
			// grouping is redundant as well
			add_filter('posts_orderby', '__return_empty_string');
			add_filter('posts_groupby', '__return_empty_string');

			if ( $debug_sql ) {
				add_filter('query', function ($query) {
					// Capture all queries (no filtering)
					$query_single_line = preg_replace('/\s+/', ' ', $query);
					\WP_CLI::log('SQL: ' . $query_single_line . PHP_EOL);
					return $query;
				});
			}
		});
	}

	/**
	 * Execute post search and return post IDs containing DMG Read More blocks.
	 *
	 * Uses indexed meta query for optimal performance at scale.
	 * Automatically searches across all post types that support the block editor.
	 * Returns all matching posts without limit.
	 *
	 * @param string|null $date_after  Posts published after this date (Y-m-d).
	 * @param string|null $date_before Posts published before this date (Y-m-d).
	 * @return array Array of post IDs.
	 */
	private function search_posts( ?string $date_after = null, ?string $date_before = null ): array {
		// Get all post types that support the block editor
		$post_types = $this->get_block_editor_post_types();

		$query_args = [
			self::QUERY_NAME => true,
			'post_type'              => $post_types,
			'posts_per_page'         => -1, // Return all matching posts
			'post_status'            => 'publish',
			'no_found_rows'          => true,
			'fields'                 => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => [
				[
					'key'   => DMG_Read_More_Block::META_FLAG,
					'value' => '1',
				],
			],
		];

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

		return $query->posts;
	}

	/**
	 * Reindex existing posts to populate block usage metadata.
	 *
	 * This command scans all posts and updates the associated
	 * meta flag. Run this once after enabling the plugin on an existing site,
	 * or when migrating content.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<post-type>]
	 * : The post type to reindex. If omitted, reindexes all post types.
	 *
	 * [--batch-size=<number>]
	 * : Number of posts to process per batch. Default: 100000
	 *
	 * ## EXAMPLES
	 *
	 *     # Reindex all posts across all post types
	 *     wp dmg-read-more reindex
	 *
	 *     # Reindex only pages
	 *     wp dmg-read-more reindex --post-type=page
	 *
	 *     # Reindex with larger batch size
	 *     wp dmg-read-more reindex --batch-size=500
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function reindex( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$post_type   = $assoc_args['post-type'] ?? 'any';
		$batch_size  = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 100000;

		// Validate batch size
		if ( $batch_size < 1 ) {
			\WP_CLI::error( 'Batch size must be a positive integer.' );
			return;
		}

		// Validate post type exists (skip validation for 'any')
		if ( $post_type !== 'any' && ! post_type_exists( $post_type ) ) {
			\WP_CLI::error( sprintf( 'Post type "%s" does not exist.', $post_type ) );
			return;
		}

		\WP_CLI::log( sprintf( 'Starting reindex of %s posts in batches of %s ...', $post_type, number_format_i18n($batch_size) ) );

		$query_args = [
			'post_type'      => $post_type,
			'posts_per_page' => $batch_size,
			'post_status'    => 'any',
			'paged'          => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false, // Need total count for progress
		];

		$total_indexed   = 0;
		$total_with_block = 0;
		$page            = 1;

		do {
			$query_args['paged'] = $page;
			$query = new \WP_Query( $query_args );

			if ( ! $query->have_posts() ) {
				break;
			}

			foreach ( $query->posts as $post_id ) {
				$post_content = get_post_field( 'post_content', $post_id );
				$has_block    = has_block( DMG_Read_More_Block::BLOCK_NAME, $post_content );

				if ( $has_block ) {
					// Add/update meta flag when block exists
					update_post_meta( $post_id, DMG_Read_More_Block::META_FLAG, '1' );
					$total_with_block++;
				} else {
					// Remove meta flag when block doesn't exist
					delete_post_meta( $post_id, DMG_Read_More_Block::META_FLAG );
				}

				$total_indexed++;
			}

			\WP_CLI::log( sprintf(
				'Processed batch %d/%d (%d posts indexed, %d with block)...',
				$page,
				$query->max_num_pages,
				$total_indexed,
				$total_with_block
			) );

			$page++;

			// Prevent memory issues on very large datasets
			wp_cache_flush();

		} while ( $page <= $query->max_num_pages );

		\WP_CLI::success( sprintf(
			'Reindexed %d posts total. Found %d posts containing the DMG Read More block.',
			$total_indexed,
			$total_with_block
		) );
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

		$parsed_date = date_parse_from_format( 'Y-m-d', $date );

		return $parsed_date['error_count'] === 0
			&& $parsed_date['warning_count'] === 0
			&& checkdate( $parsed_date['month'], $parsed_date['day'], $parsed_date['year'] );
	}
}
