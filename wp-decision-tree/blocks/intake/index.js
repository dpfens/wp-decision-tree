( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, Placeholder, Spinner, Notice } = wp.components;
	const { createElement: el, Fragment, useState, useEffect } = wp.element;
	const apiFetch = wp.apiFetch;

	function Edit( { attributes, setAttributes } ) {
		const treeId = attributes.treeId || 0;
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

		let body;
		if ( error ) {
			body = el( Notice, { status: 'error', isDismissible: false }, error );
		} else if ( trees === null ) {
			body = el( Placeholder, { label: 'Beer Decision Tree' }, el( Spinner ) );
		} else if ( treeId === 0 || missing ) {
			body = el(
				Placeholder,
				{ label: 'Beer Decision Tree', instructions: missing
					? 'The selected tree no longer exists. Choose another.'
					: 'Choose the decision tree visitors will walk through.' },
				picker
			);
		} else {
			body = el(
				Placeholder,
				{ label: 'Beer Decision Tree', instructions: 'Visitors will answer the questions in “' + selected.name + '” and see matching beers. The live questionnaire appears on the published page.' }
			);
		}

		return el(
			Fragment,
			null,
			el( InspectorControls, null, el( PanelBody, { title: 'Questionnaire' }, picker ) ),
			el( 'div', useBlockProps(), body )
		);
	}

	registerBlockType( 'bdt/intake', {
		edit: Edit,
		save: () => null,
	} );
} )( window.wp );
