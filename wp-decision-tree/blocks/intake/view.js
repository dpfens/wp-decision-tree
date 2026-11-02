( function () {
	'use strict';

	function h( tag, attrs, ...kids ) {
		const node = document.createElement( tag );
		for ( const [ key, value ] of Object.entries( attrs || {} ) ) {
			if ( key === 'class' ) {
				node.className = value;
			} else if ( key.startsWith( 'on' ) ) {
				node.addEventListener( key.slice( 2 ), value );
			} else {
				node.setAttribute( key, value );
			}
		}
		kids.flat().forEach( ( kid ) => {
			if ( kid !== null && kid !== undefined ) {
				node.append( kid ); // strings become text nodes, never HTML
			}
		} );
		return node;
	}

	function init( root ) {
		const treeId = parseInt( root.dataset.treeId, 10 );
		const endpoint = ( root.dataset.endpoint || '' ).replace( /\/$/, '' );
		const storeKey = 'bdt-intake-' + treeId;
		let token = null;
		let busy = false;

		try {
			token = window.sessionStorage.getItem( storeKey );
		} catch ( e ) {}

		function saveToken( value ) {
			token = value;
			try {
				if ( value ) {
					window.sessionStorage.setItem( storeKey, value );
				} else {
					window.sessionStorage.removeItem( storeKey );
				}
			} catch ( e ) {}
		}

		async function api( path, body ) {
			const res = await fetch( endpoint + path, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'omit',
				body: JSON.stringify( body ),
			} );
			let data = null;
			try {
				data = await res.json();
			} catch ( e ) {}
			if ( ! res.ok ) {
				const err = new Error( ( data && data.message ) || 'Something went wrong. Please try again.' );
				err.status = res.status;
				throw err;
			}
			return data;
		}

		// replaceChildren() would print null as text and stringify arrays, so flatten and skip empties.
		function render( ...nodes ) {
			root.replaceChildren( ...nodes.flat().filter( ( n ) => n !== null && n !== undefined ) );
		}

		function button( label, onclick, extraClass ) {
			return h( 'button', {
				type: 'button',
				class: 'wp-element-button ' + ( extraClass || '' ),
				onclick: ( ev ) => {
					ev.currentTarget.classList.add( 'is-selected' );
					onclick();
				},
			}, label );
		}

		// Non-fatal problems keep the current screen so the visitor can retry.
		function flash( message ) {
			root.querySelectorAll( '.bdt-error' ).forEach( ( n ) => n.remove() );
			root.querySelectorAll( '.is-selected' ).forEach( ( n ) => n.classList.remove( 'is-selected' ) );
			root.prepend( h( 'p', { class: 'bdt-error', role: 'alert' }, message ) );
		}

		function showStart() {
			render( button( 'Start', start, 'bdt-start' ) );
		}

		function showError( message ) {
			render(
				h( 'p', { class: 'bdt-error', role: 'alert' }, message ),
				button( 'Start over', startOver, 'bdt-restart' )
			);
		}

		function startOver() {
			saveToken( null );
			showStart();
		}

		function beerCard( beer ) {
			const body = h( 'div', { class: 'bdt-beer-content' } );
			body.innerHTML = beer.content; // sanitized with wp_kses_post on the server

			const card = h( 'article', { class: 'bdt-beer' } );
			if ( beer.image ) {
				card.append( h( 'img', { src: beer.image, alt: '', loading: 'lazy' } ) );
			}
			card.append( h( 'h4', { class: 'bdt-beer-name' }, beer.name ), body );

			if ( beer.attributes.length ) {
				const list = h( 'dl', { class: 'bdt-beer-attributes' } );
				beer.attributes.forEach( ( a ) => {
					list.append(
						h( 'dt', {}, a.label ),
						h( 'dd', {}, a.unit ? a.value + ' ' + a.unit : a.value )
					);
				} );
				card.append( list );
			}
			return card;
		}

		function show( data, moveFocus ) {
			const step = data.step;
			const trail = data.trail || [];
			const heading = h( 'h3', { class: 'bdt-prompt', tabindex: '-1' } );

			const trailEl = trail.length
				? h( 'ol', { class: 'bdt-trail' }, trail.map( ( t ) =>
					h( 'li', {}, h( 'span', { class: 'bdt-trail-q' }, t.prompt ), ' ', h( 'strong', { class: 'bdt-trail-a' }, t.answer ) )
				) )
				: null;
			const backBtn = trail.length ? button( 'Back', goBack, 'bdt-back' ) : null;

			if ( step.status === 'question' ) {
				heading.append( step.prompt );
				render(
					trailEl,
					h( 'p', { class: 'bdt-progress' }, 'Question ' + ( trail.length + 1 ) ),
					heading,
					h( 'ul', { class: 'bdt-options' }, step.options.map( ( o ) =>
						h( 'li', {}, button( o.label, () => answer( o.node_id ), 'bdt-option' ) )
					) ),
					backBtn
				);
			} else {
				heading.append( step.beers.length ? 'Your matches' : 'No matches found' );
				render(
					trailEl,
					heading,
					step.beers.map( beerCard ),
					backBtn,
					button( 'Start over', startOver, 'bdt-restart' )
				);
			}

			if ( moveFocus ) {
				heading.focus();
			}
		}

		async function run( task ) {
			if ( busy ) {
				return;
			}
			busy = true;
			root.setAttribute( 'aria-busy', 'true' );
			try {
				await task();
			} catch ( e ) {
				// An expired or changed session cannot continue; anything else can be retried.
				if ( e.status === 404 || e.status === 410 ) {
					showError( e.message );
				} else {
					flash( e.message );
				}
			} finally {
				busy = false;
				root.removeAttribute( 'aria-busy' );
			}
		}

		function start() {
			return run( async () => {
				const data = await api( '/start', { tree_id: treeId } );
				saveToken( data.token );
				show( data, true );
			} );
		}

		function goBack() {
			return run( async () => {
				show( await api( '/back', { token } ), true );
			} );
		}

		function answer( toNodeId ) {
			return run( async () => {
				const data = await api( '/answer', { token, to_node_id: toNodeId } );
				show( data, true );
			} );
		}

		if ( token ) {
			run( async () => {
				try {
					show( await api( '/resume', { token } ), false );
				} catch ( e ) {
					saveToken( null );
					showStart();
				}
			} );
		} else {
			showStart();
		}
	}

	function boot() {
		document.querySelectorAll( '.bdt-intake[data-tree-id]' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
