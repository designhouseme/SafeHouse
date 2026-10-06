/**
 * SafeHouse settings page. Without this script every view and every module's settings are shown;
 * with it, views switch in place and module settings fold away until asked for.
 */
( () => {
	const root = document.querySelector( '.shouse' );
	if ( ! root ) {
		return;
	}
	const form = root.querySelector( '.shouse-form' );
	const links = root.querySelectorAll( '.shouse-tabs a[data-view]' );

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
			const panel = document.getElementById( 'shouse-view-' + link.dataset.view );
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
	root.querySelectorAll( '.shouse-module' ).forEach( ( module ) => {
		const button = module.querySelector( '.shouse-module__more' );
		const body = module.querySelector( '.shouse-module__body' );
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
		const toggle = module.querySelector( '.shouse-switch input' );
		if ( toggle ) {
			toggle.addEventListener( 'change', () => toggle.checked && open( true ) );
		}
		modules.set( module, open );
	} );

	// Bring an element into view: its view, and its module's settings if it sits inside them.
	const reveal = ( element ) => {
		const view = element.closest( '.shouse-view' );
		if ( view && view.hidden ) {
			show( view.id.replace( 'shouse-view-', '' ) );
		}
		const module = element.closest( '.shouse-module' );
		if ( module && modules.has( module ) ) {
			modules.get( module )( true );
		}
	};

	// Links such as Site Health's "Open SafeHouse" point at #shouse-<module>.
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
