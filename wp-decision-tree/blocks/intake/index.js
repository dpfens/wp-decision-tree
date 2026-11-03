( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, ToggleControl, Placeholder, Spinner, Notice } = wp.components;
	const { createElement: el, Fragment, useState, useEffect } = wp.element;
	const apiFetch = wp.apiFetch;

	function Edit( { attributes, setAttributes } ) {
		const treeId = attributes.treeId || 0;
		const showMatches = !! attributes.showMatches;
		const [ trees, setTrees ] = useState( null );
		const [ error, setError ] = useState( '' );

		useEffect( () => {
			let live = true;
			apiFetch( { path: '/bdt/v1/trees' } )
				.then( ( list ) => live && setTrees( list ) )
				.catch( ( e ) => live && setError( e.message || 'Could not load trees.' ) );
			return () => {
				live = false;
			};
		}, [] );

		const options = [ { label: 'Choose a tree…', value: '0' } ].concat(
			( trees || [] ).map( ( t ) => ( { label: t.name, value: String( t.id ) } ) )
		);
		const selected = ( trees || [] ).find( ( t ) => t.id === treeId );
		const missing = trees !== null && treeId > 0 && ! selected;

		const picker = el( SelectControl, {
			label: 'Decision tree',
			value: String( treeId ),
			options,
			disabled: trees === null,
			onChange: ( v ) => setAttributes( { treeId: parseInt( v, 10 ) || 0 } ),
			__nextHasNoMarginBottom: true,
		} );

		const matchesToggle = el( ToggleControl, {
			label: 'Show matches while answering',
			checked: showMatches,
			onChange: ( v ) => setAttributes( { showMatches: !! v } ),
			help: showMatches
    ? __( 'Each question lists the items still in play, and visitors can stop and see full results at any time. This only controls the running list; which items can match is decided by the tree’s item nodes and pools.', 'item-decision-tree' )
    : __( 'Visitors answer every question before seeing any items. This only controls the running list; which items can match is decided by the tree’s item nodes and pools.', 'item-decision-tree' ),
			__nextHasNoMarginBottom: true,
		} );

		let body;
		if ( error ) {
			body = el( Notice, { status: 'error', isDismissible: false }, error );
		} else if ( trees === null ) {
			body = el( Placeholder, { label: 'Decision Tree' }, el( Spinner ) );
		} else if ( treeId === 0 || missing ) {
			body = el(
				Placeholder,
				{ label: 'Decision Tree', instructions: missing
					? 'The selected tree no longer exists. Choose another.'
					: 'Choose the decision tree visitors will walk through.' },
				picker
			);
		} else {
			body = el(
				Placeholder,
				{ label: 'Decision Tree', instructions: 'Visitors will answer the questions in “' + selected.name + '” and see matching items' + ( showMatches ? ', with a running list of matches as they go' : '' ) + '. The live questionnaire appears on the published page.' }
			);
		}

		return el(
			Fragment,
			null,
			el( InspectorControls, null, el( PanelBody, { title: 'Questionnaire' }, picker, matchesToggle ) ),
			el( 'div', useBlockProps(), body )
		);
	}

	registerBlockType( 'bdt/intake', {
		edit: Edit,
		save: () => null,
	} );
} )( window.wp );
