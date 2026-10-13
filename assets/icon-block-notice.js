/**
 * Say so in the editor when an Icon block points at an icon that is gone.
 *
 * A collection can leave: a theme is switched, a plugin is deactivated, a folder
 * is renamed, a set is trashed. The reference saved in the post stays, and
 * everything downstream treats it the way it treats any other reference it
 * cannot resolve -- the front end draws nothing and the editor shows an empty
 * frame. Nothing says the icon used to be there, so the first person to notice
 * is a visitor.
 *
 * Deliberately editor-only. The front end stays silent: a notice there would
 * publish an internal problem to readers, and the person who can fix it is the
 * one editing the page.
 *
 * Plain wp.element, no build step: this file is three screens long and every
 * dependency is already on the page.
 */
( function ( wp ) {
	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.data || ! wp.compose ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	/**
	 * What is wrong with this reference, or '' when nothing is.
	 *
	 * Two different answers, because the remedies differ: a collection that is
	 * not here at all is usually a theme or plugin that left, and an icon
	 * missing from a collection that IS here is usually a rename.
	 */
	function problem( reference, icons, collections ) {
		if ( ! reference || ! Array.isArray( icons ) ) {
			return '';
		}
		for ( var i = 0; i < icons.length; i++ ) {
			if ( icons[ i ] && icons[ i ].name === reference ) {
				return '';
			}
		}
		var slash = reference.indexOf( '/' );
		var collection = slash > 0 ? reference.slice( 0, slash ) : '';
		var known = false;
		if ( collection && Array.isArray( collections ) ) {
			for ( var c = 0; c < collections.length; c++ ) {
				if ( collections[ c ] && collections[ c ].slug === collection ) {
					known = true;
				}
			}
		}
		return known
			? sprintf(
					/* translators: 1: the saved icon reference, 2: the collection it names. */
					__( 'This icon is no longer in the collection it was taken from: %1$s. The collection %2$s is still here, so the icon was renamed or removed. Pick it again.', 'easy-svg' ),
					reference,
					collection
			  )
			: sprintf(
					/* translators: 1: the saved icon reference, 2: the collection it names. */
					__( 'This icon is not available on this site: %1$s. Nothing registers the collection %2$s. A theme or plugin that provided it may have been switched off, and visitors see no icon here.', 'easy-svg' ),
					reference,
					collection || reference
			  );
	}

	var withMissingIconNotice = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( 'core/icon' !== props.name ) {
				return el( BlockEdit, props );
			}

			var reference = props.attributes && props.attributes.icon ? String( props.attributes.icon ) : '';

			/*
			 * Asked for once, and held here.
			 *
			 * Reading the store directly looked simpler and was wrong: the
			 * icons arrive from a REST request, and a block that rendered
			 * before they did never rendered again -- measured, the warning
			 * appeared on one page load out of two. Resolving a promise and
			 * setting state makes the second render certain.
			 */
			var icons = wp.element.useState( null );
			var collections = wp.element.useState( null );

			wp.element.useEffect( function () {
				var alive = true;
				var core = wp.data.resolveSelect( 'core' );
				if ( ! core || ! core.getIcons ) {
					return undefined;
				}
				Promise.all( [ core.getIcons(), core.getIconCollections ? core.getIconCollections() : [] ] ).then(
					function ( answer ) {
						if ( ! alive ) {
							return;
						}
						icons[ 1 ]( Array.isArray( answer[ 0 ] ) ? answer[ 0 ] : [] );
						collections[ 1 ]( Array.isArray( answer[ 1 ] ) ? answer[ 1 ] : [] );
					}
				);
				return function () {
					alive = false;
				};
			}, [] );

			// Until the list is here, every reference looks missing. A warning
			// that appears on load and disappears a moment later is worse than
			// no warning at all.
			var message = null === icons[ 0 ] ? '' : problem( reference, icons[ 0 ], collections[ 0 ] );

			return el(
				wp.element.Fragment,
				null,
				message
					? el(
							wp.blockEditor.Warning,
							{ className: 'easy-svg-missing-icon' },
							message
					  )
					: null,
				el( BlockEdit, props )
			);
		};
	}, 'withMissingIconNotice' );

	wp.hooks.addFilter( 'editor.BlockEdit', 'easy-svg/missing-icon-notice', withMissingIconNotice );
} )( window.wp );
