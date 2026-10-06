/**
 * WPHouse settings page. Without this script every view and every module's settings are shown;
 * with it, views switch in place and module settings fold away until asked for.
 */
( () => {
	const root = document.querySelector( '.wphouse' );
	if ( ! root ) {
		return;
	}
	const form = root.querySelector( '.wphouse-form' );
	const links = root.querySelectorAll( '.wphouse-tabs a[data-view]' );

	// Keep ?tab= in the address and in the form's referer, so saving brings the user back here.
	const remember = ( view ) => {
		const url = new URL( window.location.href );
		url.searchParams.set( 'tab', view );
		url.searchParams.delete( 'settings-updated' );
		url.hash = '';
		window.history.replaceState( null, '', url );
		const referer = form && form.querySelector( 'input[name="_wp_http_referer"]' );
		if ( referer ) {
			const back = new URL( referer.value, window.location.origin );
			back.searchParams.set( 'tab', view );
			back.searchParams.delete( 'settings-updated' );
			referer.value = back.pathname + back.search;
		}
	};

	const show = ( view ) => {
		links.forEach( ( link ) => {
			const on = link.dataset.view === view;
			if ( on ) {
				link.setAttribute( 'aria-current', 'page' );
			} else {
				link.removeAttribute( 'aria-current' );
			}
			const panel = document.getElementById( 'wphouse-view-' + link.dataset.view );
			if ( panel ) {
				panel.hidden = ! on;
			}
		} );
		remember( view );
	};

	links.forEach( ( link ) => {
		link.addEventListener( 'click', ( event ) => {
			event.preventDefault();
			show( link.dataset.view );
		} );
	} );

	const modules = new Map();
	root.querySelectorAll( '.wphouse-module' ).forEach( ( module ) => {
		const button = module.querySelector( '.wphouse-module__more' );
		const body = module.querySelector( '.wphouse-module__body' );
		if ( ! button || ! body ) {
			return;
		}
		const open = ( state ) => {
			button.setAttribute( 'aria-expanded', String( state ) );
			body.hidden = ! state;
		};
		button.hidden = false;
		open( false );
		button.addEventListener( 'click', () => open( 'true' !== button.getAttribute( 'aria-expanded' ) ) );
		// Switching a module on shows the settings it now uses.
		const toggle = module.querySelector( '.wphouse-switch input' );
		if ( toggle ) {
			toggle.addEventListener( 'change', () => toggle.checked && open( true ) );
		}
		modules.set( module, open );
	} );

	// Bring an element into view: its view, and its module's settings if it sits inside them.
	const reveal = ( element ) => {
		const view = element.closest( '.wphouse-view' );
		if ( view && view.hidden ) {
			show( view.id.replace( 'wphouse-view-', '' ) );
		}
		const module = element.closest( '.wphouse-module' );
		if ( module && modules.has( module ) ) {
			modules.get( module )( true );
		}
	};

	// Links such as Site Health's "Open WPHouse" point at #wphouse-<module>.
	const target = window.location.hash ? document.getElementById( window.location.hash.slice( 1 ) ) : null;
	if ( target && root.contains( target ) ) {
		reveal( target );
		target.scrollIntoView();
	}

	// A field the browser rejects must be visible, or the form silently refuses to submit.
	if ( form ) {
		form.addEventListener( 'invalid', ( event ) => reveal( event.target ), true );
	}
} )();
