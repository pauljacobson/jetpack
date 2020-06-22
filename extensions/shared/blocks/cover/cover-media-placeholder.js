
/**
 * WordPress dependencies
 */
import { useBlockEditContext } from '@wordpress/block-editor';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useContext } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { isUpgradable, isVideoFile } from './utils';
import { CoverMediaContext } from './components';

export default createHigherOrderComponent(
	CoreMediaPlaceholder => props => {
		const { name } = useBlockEditContext();
		if ( ! name || ! isUpgradable( name ) ) {
			return <CoreMediaPlaceholder { ...props } />;
		}

		const onFilesUpload = useContext( CoverMediaContext );
		const { onError } = props;

		/**
		 * On Uploading error handler.
		 * Try to pick up filename from the error message.
		 * We should find a better way to do it. Unstable.
		 *
		 * @param {Array} message - Error message provided by the callback.
		 * @returns {*} Error handling.
		 */
		const uploadingErrorHandler = ( message ) => {
			const filename = message?.[ 0 ]?.props?.children;
			if ( filename && isVideoFile( filename ) ) {
				return onFilesUpload( [ filename ] );
			}
			return onError( message );
		};

		return (
			<div className="jetpack-cover-media-placeholder">
				<CoreMediaPlaceholder
					{ ...props }
					onFilesPreUpload={ onFilesUpload }
					onError = { uploadingErrorHandler }
				/>
			</div>
		);
	},
	'JetpackCoverMediaPlaceholder'
);
