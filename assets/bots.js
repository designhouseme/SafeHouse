/**
 * WPHouse bot protection in the browser: the honeypot proof and Cloudflare Turnstile widgets.
 * Loaded only where one of the forms is printed, and on the block checkout.
 */
( function () {
	'use strict';

	var config = window.wphouseBots || {};
	var FIELD = 'cf-turnstile-response';
	// Sent when the browser cannot reach Cloudflare. The server then asks Cloudflare itself and
	// applies the "when unavailable" setting only if Cloudflare is down for the server too.
	var UNREACHABLE = 'unreachable';
	var WAIT_MS = 15000;

	function each( selector, fn, root ) {
		Array.prototype.forEach.call( ( root || document ).querySelectorAll( selector ), fn );
	}

	/* Honeypot: the proof goes in on the first keypress, tap or click inside the form. */
	function armHoneypot( box ) {
		var form = box.closest( 'form' );
		if ( ! form || form.wphouseHoneypot ) {
			return;
		}
		form.wphouseHoneypot = true;
		var write = function () {
			var input = form.querySelector( 'input[name="wphouse_proof"]' );
			if ( input ) {
				input.value = box.getAttribute( 'data-proof' ) || '';
			}
		};
		[ 'keydown', 'pointerdown', 'touchstart', 'input' ].forEach( function ( type ) {
			form.addEventListener( type, write, { once: true, passive: true } );
		} );
	}

	/* Turnstile */
	function field( el, create ) {
		var input = el.querySelector( 'input[name="' + FIELD + '"]' );
		if ( ! input && create ) {
			input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = FIELD;
			el.appendChild( input );
		}
		return input;
	}

	function token( el ) {
		var input = el && field( el, false );
		return input ? input.value : '';
	}

	function markUnreachable( el ) {
		var input = field( el, true );
		if ( ! input.value ) {
			input.value = UNREACHABLE;
		}
	}

	function resume( el ) {
		var go = el.wphouseResume;
		el.wphouseResume = null;
		if ( go ) {
			go();
		}
	}

	function render( el ) {
		if ( el.wphouseWidget !== undefined || ! window.turnstile ) {
			return;
		}
		el.wphouseWidget = window.turnstile.render( el, {
			sitekey: el.getAttribute( 'data-sitekey' ),
			action: el.getAttribute( 'data-action' ),
			appearance: 'interaction-only',
			size: 'flexible',
			callback: function () {
				resume( el );
			},
			'error-callback': function () {
				markUnreachable( el );
				resume( el );
			},
		} );
	}

	function reset( el ) {
		if ( el && el.wphouseWidget !== undefined && window.turnstile ) {
			window.turnstile.reset( el.wphouseWidget );
		}
	}

	function scan() {
		each( '.wphouse-hp', armHoneypot );
		each( '.wphouse-turnstile', render );
		placeBlockCheckoutWidget();
	}

	/* Wait for a token before a form is sent; give up after WAIT_MS and let the server decide. */
	document.addEventListener(
		'submit',
		function ( event ) {
			var form = event.target;
			var el = form.querySelector ? form.querySelector( '.wphouse-turnstile' ) : null;
			if ( ! el || token( el ) || form.wphouseSending ) {
				return;
			}
			if ( ! window.turnstile ) {
				markUnreachable( el );
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			if ( el.wphouseResume ) {
				return;
			}
			var submitter = event.submitter && event.submitter.form === form ? event.submitter : null;
			var timer = setTimeout( function () {
				resume( el );
			}, WAIT_MS );
			el.wphouseResume = function () {
				clearTimeout( timer );
				form.wphouseSending = true;
				if ( form.requestSubmit ) {
					form.requestSubmit( submitter || undefined );
				} else {
					form.submit();
				}
				form.wphouseSending = false;
			};
		},
		true
	);

	/* Classic checkout: the widget is re-rendered with the payment box, and a token is single-use. */
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'checkout_error', function () {
			each( 'form.checkout .wphouse-turnstile', reset );
		} );
	}

	/* Block checkout: no PHP form hook, so the widget goes above the Place order button, and the
	   token travels in a header on the Store API checkout request. */
	var blockWidget = null;

	function placeBlockCheckoutWidget() {
		if ( ! config.blockCheckout || ! config.sitekey || ( blockWidget && document.body.contains( blockWidget ) ) ) {
			return;
		}
		var actions = document.querySelector( '.wc-block-checkout__actions' );
		if ( ! actions || ! actions.parentNode ) {
			return;
		}
		blockWidget = document.createElement( 'div' );
		blockWidget.className = 'wphouse-turnstile';
		blockWidget.setAttribute( 'data-sitekey', config.sitekey );
		blockWidget.setAttribute( 'data-action', 'wphouse_checkout' );
		actions.parentNode.insertBefore( blockWidget, actions );
		render( blockWidget );
	}

	function isCheckoutPost( options ) {
		var method = ( options.method || 'GET' ).toUpperCase();
		var path = options.path || options.url || '';
		return 'POST' === method && /\/wc\/store(\/v\d+)?\/checkout(\/|\?|$)/i.test( path );
	}

	function waitForToken() {
		return new Promise( function ( resolve ) {
			var started = Date.now();
			( function poll() {
				if ( ! window.turnstile && blockWidget ) {
					markUnreachable( blockWidget );
				}
				var value = token( blockWidget );
				if ( value || Date.now() - started > WAIT_MS ) {
					resolve( value );
					return;
				}
				setTimeout( poll, 200 );
			} )();
		} );
	}

	if ( config.blockCheckout && config.sitekey && window.wp && window.wp.apiFetch ) {
		window.wp.apiFetch.use( function ( options, next ) {
			if ( ! isCheckoutPost( options ) ) {
				return next( options );
			}
			return waitForToken().then( function ( value ) {
				var headers = Object.assign( {}, options.headers );
				headers[ config.header ] = value;
				var result = next( Object.assign( {}, options, { headers: headers } ) );
				var again = function () {
					reset( blockWidget );
				};
				result.then( again, again );
				return result;
			} );
		} );
	}

	window.wphouseTurnstileReady = scan;
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', scan );
	} else {
		scan();
	}
	// Fragments (classic checkout, block checkout, AJAX comment forms) arrive later. One scan per frame.
	var queued = false;
	new MutationObserver( function () {
		if ( ! queued ) {
			queued = true;
			window.requestAnimationFrame( function () {
				queued = false;
				scan();
			} );
		}
	} ).observe( document.body, { childList: true, subtree: true } );
} )();
