import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	RangeControl,
	Button,
	Spinner,
	Notice
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

// Import block styles
import './style.scss';

const blockType = 'dmg-read-more/dmg-read-more';

registerBlockType(blockType, {
	edit({ attributes, setAttributes }) {
		const blockProps = useBlockProps();
		const { postId, postsPerPage } = attributes;

		// State management
		const [searchTerm, setSearchTerm] = useState('');
		const [currentPage, setCurrentPage] = useState(1);
		const [posts, setPosts] = useState([]);
		const [hasMorePages, setHasMorePages] = useState(false);
		const [isLoading, setIsLoading] = useState(false);
		const [error, setError] = useState(null);
		const [selectedPostData, setSelectedPostData] = useState(null);

		// Fetch posts function
		const fetchPosts = async (search = '', page = 1) => {
			setIsLoading(true);
			setError(null);

			try {
				// Check if search term is a number (post ID)
				const isNumeric = /^\d+$/.test(search.trim());

				let path;

				if (isNumeric && search.trim()) {
					// Search by post ID - use include parameter to find specific post
					// This returns just the one post matching the ID
					path = addQueryArgs('/wp/v2/search', {
						include: search.trim(),
						_fields: 'id,title,url,subtype',
					});
				} else {
					// Use search endpoint to query across all post types
					const queryArgs = {
						per_page: postsPerPage,
						page: page,
						_fields: 'id,title,url,subtype',
						subtype: 'any', // Search across all post types
					};

					if (search.trim()) {
						// Search by text across all post types
						queryArgs.search = search.trim();
					}
					// For default (no search), the endpoint returns posts without search parameter

					path = addQueryArgs('/wp/v2/search', queryArgs);
				}

				const response = await apiFetch({
					path,
					parse: false,
				});

				const postsData = await response.json();

				// Determine if there are more pages based on result count
				// If we got a full page, assume there might be more
				const hasMore = postsData.length === postsPerPage;

				setPosts(postsData);
				setHasMorePages(hasMore);
			} catch (err) {
				setError(err.message || __('Failed to fetch posts', 'dmg-read-more'));
				setPosts([]);
			} finally {
				setIsLoading(false);
			}
		};

		// Debounced search effect
		useEffect(() => {
			const timer = setTimeout(() => {
				setCurrentPage(1); // Reset to first page on new search
				fetchPosts(searchTerm, 1);
			}, 300);

			return () => clearTimeout(timer);
		}, [searchTerm, postsPerPage]);

		// Pagination effect - fetch when page changes (but skip initial mount which search effect handles)
		const isInitialMount = currentPage === 1 && posts.length === 0;
		useEffect(() => {
			if (!isInitialMount) {
				fetchPosts(searchTerm, currentPage);
			}
		}, [currentPage]);

		// Get selected post from in-memory data (no need for API call)
		const selectedPost = posts.find(post => post.id === postId) || selectedPostData;

		// Update selected post data when postId changes and not in current posts list
		useEffect(() => {
			if (postId && !posts.find(post => post.id === postId) && selectedPostData?.id !== postId) {
				// Fetch only if we don't have it in memory
				apiFetch({
					path: addQueryArgs('/wp/v2/search', {
						include: postId,
						_fields: 'id,title,url,subtype',
					}),
				})
					.then(data => {
						if (data && data.length > 0) {
							setSelectedPostData(data[0]);
						}
					})
					.catch(() => {
						setSelectedPostData(null);
					});
			}
		}, [postId, posts]);

		// Pagination handlers
		const handlePrevPage = () => {
			if (currentPage > 1) {
				setCurrentPage(currentPage - 1);
			}
		};

		const handleNextPage = () => {
			if (hasMorePages) {
				setCurrentPage(currentPage + 1);
			}
		};

		// Post selection handler
		const handleSelectPost = (id) => {
			setAttributes({ postId: id });
		};

		// Detect search mode
		const searchMode = /^\d+$/.test(searchTerm.trim()) && searchTerm.trim()
			? __('Searching by post ID...', 'dmg-read-more')
			: searchTerm.trim()
			? __('Searching posts...', 'dmg-read-more')
			: __('Showing recent posts', 'dmg-read-more');

		return (
			<>
				<InspectorControls>
					<PanelBody title={__('Post Selection', 'dmg-read-more')} initialOpen={true}>
						<TextControl
							label={__('Search posts or enter post ID', 'dmg-read-more')}
							value={searchTerm}
							onChange={(value) => setSearchTerm(value)}
							placeholder={__('Type to search or enter ID...', 'dmg-read-more')}
							help={searchMode}
						/>

						<RangeControl
							label={__('Posts per page', 'dmg-read-more')}
							value={postsPerPage}
							onChange={(value) => setAttributes({ postsPerPage: value })}
							min={5}
							max={50}
							step={5}
						/>

						<div style={{ marginTop: '20px' }}>
							<strong>{__('Select a post:', 'dmg-read-more')}</strong>

							{isLoading && (
								<div style={{ textAlign: 'center', padding: '20px' }}>
									<Spinner />
								</div>
							)}

							{error && (
								<Notice status="error" isDismissible={false}>
									{error}
								</Notice>
							)}

							{!isLoading && posts.length === 0 && (
								<Notice status="warning" isDismissible={false}>
									{__('No posts found', 'dmg-read-more')}
								</Notice>
							)}

							{!isLoading && posts.length > 0 && (
								<div style={{
									maxHeight: '400px',
									overflowY: 'auto',
									marginTop: '10px',
									marginBottom: '10px'
								}}>
									{posts.map((post) => (
										<Button
											key={post.id}
											variant={post.id === postId ? 'primary' : 'secondary'}
											onClick={() => handleSelectPost(post.id)}
											style={{
												width: '100%',
												marginBottom: '5px',
												justifyContent: 'flex-start',
												textAlign: 'left',
											}}
										>
											{post.id === postId && (
												<span style={{ marginRight: '8px' }}>✓</span>
											)}
											<span
												dangerouslySetInnerHTML={{ __html: post.title }}
												style={{ flex: 1 }}
											/>
											<span style={{
												fontSize: '0.85em',
												opacity: 0.7,
												marginLeft: '8px'
											}}>
												({post.subtype})
											</span>
										</Button>
									))}
								</div>
							)}

							{/* Pagination Controls */}
							{!isLoading && posts.length > 0 && (currentPage > 1 || hasMorePages) && (
								<div style={{
									display: 'flex',
									justifyContent: 'space-between',
									alignItems: 'center',
									marginTop: '10px',
									paddingTop: '10px',
									borderTop: '1px solid #ddd'
								}}>
									<Button
										variant="secondary"
										onClick={handlePrevPage}
										disabled={currentPage === 1}
										size="small"
									>
										{__('Previous', 'dmg-read-more')}
									</Button>
									<span style={{ fontSize: '0.9em' }}>
										{__('Page', 'dmg-read-more')} {currentPage}
									</span>
									<Button
										variant="secondary"
										onClick={handleNextPage}
										disabled={!hasMorePages}
										size="small"
									>
										{__('Next', 'dmg-read-more')}
									</Button>
								</div>
							)}
						</div>
					</PanelBody>
				</InspectorControls>

				<div {...blockProps}>
					{postId === 0 ? (
						<div className="dmg-read-more-placeholder">
							<p>
								{__('Select a post to display a Read More link', 'dmg-read-more')}
							</p>
						</div>
					) : !selectedPost ? (
						<div className="dmg-read-more-error">
							<p>
								{__('Selected post is not available', 'dmg-read-more')}
							</p>
						</div>
					) : (
						<p className="dmg-read-more">
							<a
								href={selectedPost.url}
								onClick={(e) => e.preventDefault()}
							>
								{__('Read More:', 'dmg-read-more')} {selectedPost.title}
							</a>
						</p>
					)}
				</div>
			</>
		);
	},
});
