import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

const blockType = 'dmg-read-more/dmg-read-more';

registerBlockType(blockType, {
	edit({ attributes, setAttributes }) {
		const blockProps = useBlockProps();
		const { searchTerm } = attributes;

		return (
			<div {...blockProps}>
				<InspectorControls>
					<PanelBody title={__('Search Settings', 'dmg-read-more')}>
						<TextControl
							label={__('Search Term', 'dmg-read-more')}
							value={searchTerm}
							onChange={(value) => setAttributes({ searchTerm: value })}
							help={__('Enter the term to search for across all post types', 'dmg-read-more')}
						/>
					</PanelBody>
				</InspectorControls>

				<ServerSideRender
					block={blockType}
					attributes={attributes}
				/>
			</div>
		);
	},
});