/**
 * SafeHouse bot protection in the browser: form challenges and Cloudflare Turnstile widgets.
 * Loaded only where one of the forms is printed, and on the block checkout.
 */
( function () {
	'use strict';

	var config = window.shouseBots || {};
	var FIELD = 'cf-turnstile-response';
	// Sent when the browser cannot reach Cloudflare. The server then asks Cloudflare itself and
	// applies the "when unavailable" setting only if Cloudflare is down for the server too.
	var UNREACHABLE = 'unreachable';
	var WAIT_MS = 15000;

	function each( selector, fn, root ) {
		Array.prototype.forEach.call( ( root || document ).querySelectorAll( selector ), fn );
	}

	// Serialize issuance so two forms on a fresh page do not race to set the browser cookie.
	var challengeQueue = Promise.resolve();

	function resumeForm( form, submitter ) {
		if ( form.requestSubmit ) {
			form.requestSubmit( submitter || undefined );
		} else {
			// Preserve WooCommerce's submit-button discriminator in older browsers.
			var button;
			if ( submitter && submitter.name ) {
				button = document.createElement( 'input' );
				button.type = 'hidden';
				button.name = submitter.name;
				button.value = submitter.value;
				form.appendChild( button );
			}
			HTMLFormElement.prototype.submit.call( form );
			if ( button ) {
				button.remove();
			}
		}
	}

	/* Cached HTML contains no usable proof. Fetch a fresh, browser-bound challenge on interaction. */
	function armHoneypot( box ) {
		var form = box.closest( 'form' );
		if ( ! form || box.shouseHoneypot ) {
			return;
		}
		var proof = box.querySelector( 'input[name="shouse_challenge"]' );
		var trap = box.querySelector( '[data-shouse-trap]' );
		// Old cached markup cannot produce a v2 challenge; the server returns reload guidance.
		if ( ! proof || ! trap || ! config.challengeUrl ) { return; }
		var state = { pending: null, expires: 0, ready: 0, generation: 0, used: false, manualRetry: false, retryAt: 0 };
		box.shouseHoneypot = state;
		var status = box.parentNode.querySelector( '.shouse-hp-status' );
		var target = function () {
			var post = form.elements.namedItem( 'comment_post_ID' );
			return box.getAttribute( 'data-form' ) === 'comments' && post ? post.value : ( box.getAttribute( 'data-target' ) || '0' );
		};
		state.valid = function () {
			return state.inactive || ( ! state.used && proof.value && Date.now() < state.expires && state.target === target() );
		};
		state.invalidate = function () {
			state.generation++;
			state.used = true;
			state.inactive = false;
			state.expires = 0;
			state.ready = 0;
			// Keep the submitted value for later AJAX/FormData handlers. Only the next explicit
			// submission may replace it; ordinary typing must not alter an in-flight payload.
		};
		state.afterSubmit = function ( event ) {
			var generation = state.generation;
			setTimeout( function () {
				if ( ! event.shouseWaitingTurnstile && state.generation === generation ) { state.invalidate(); }
			}, 0 );
		};
		state.prepare = function ( manual ) {
			if ( state.pending ) {
				return state.pending;
			}
			if ( state.valid() ) {
				return Promise.resolve();
			}
			if ( ( ! manual && ( state.used || state.manualRetry ) ) || Date.now() < state.retryAt ) {
				return Promise.reject( new Error( 'challenge-retry' ) );
			}
			var generation = state.generation;
			proof.value = '';
			state.pending = challengeQueue.then( function () {
				var controller = new AbortController();
				var timer = setTimeout( function () { controller.abort(); }, WAIT_MS );
				var issuedTarget = target();
				var body = new URLSearchParams( {
					action: 'shouse_challenge',
					form: box.getAttribute( 'data-form' ),
					target: issuedTarget,
				} );
				return fetch( config.challengeUrl, {
					method: 'POST', credentials: 'same-origin', cache: 'no-store',
					headers: { 'X-SHouse-Form': '1' }, body: body, signal: controller.signal,
				} ).then( function ( response ) {
					// An open/cached page may outlive module disable or safe mode. WordPress returns
					// exactly 400/0 for an unregistered AJAX action; defer to the real form handler.
					if ( response.status === 400 ) {
						return response.text().then( function ( body ) {
							if ( body.trim() === '0' ) { return { inactive: true }; }
							throw new Error( 'challenge' );
						} );
					}
					if ( response.status === 429 || response.status === 503 ) {
						return response.json().catch( function () { return {}; } ).then( function ( result ) {
							var retry = Number( result.data && result.data.retryAfter ) || 0;
							var header = response.headers.get( 'Retry-After' );
							var headerWait = Number( header ) || Math.max( 0, ( Date.parse( header ) - Date.now() ) / 1000 ) || 0;
							var error = new Error( 'challenge' );
							error.retryAfter = Math.max( retry, headerWait ) || 60;
							error.rate = response.status === 429;
							error.unavailable = response.status === 503;
							throw error;
						} );
					}
					if ( ! response.ok ) { throw new Error( 'challenge' ); }
					return response.json();
				} ).then( function ( result ) {
					if ( generation !== state.generation ) { throw new Error( 'challenge-stale' ); }
					if ( result.inactive ) { state.inactive = true; return; }
					var data = result.data;
					if ( ! result.success || ! data || typeof data.challenge !== 'string' || typeof data.trap !== 'string' ) {
						throw new Error( 'challenge' );
					}
					// Never clear a populated trap: changing its name must not erase bot evidence.
					trap.name = data.trap;
					proof.value = data.challenge;
					state.expires = Date.now() + Math.max( 0, data.expiresIn - 30 ) * 1000;
					state.ready = Date.now() + data.wait + 100;
					state.target = issuedTarget;
					state.used = false;
					state.manualRetry = false;
					state.retryAt = 0;
					if ( status ) { status.textContent = ''; status.hidden = true; }
				} ).finally( function () { clearTimeout( timer ); } );
			} ).catch( function ( error ) {
				if ( generation === state.generation ) {
					state.manualRetry = true;
					state.retryAt = Date.now() + Math.max( 0, error.retryAfter || 0 ) * 1000;
					if ( status ) {
						status.textContent = ( error.rate && config.challengeRateError ) || ( error.unavailable && config.challengeUnavailableError ) || config.challengeError;
						status.hidden = false;
					}
				}
				throw error;
			} ).finally( function () { state.pending = null; } );
			challengeQueue = state.pending.catch( function () {} );
			return state.pending;
		};
		var prepare = function () {
			if ( form.contains( box ) ) {
				state.prepare().catch( function () {} );
			}
		};
		[ 'focusin', 'pointerdown', 'input' ].forEach( function ( type ) {
			form.addEventListener( type, prepare, { passive: true } );
		} );
		form.addEventListener( 'reset', state.invalidate );
	}
	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			each( '.shouse-hp', function ( box ) {
				if ( box.shouseHoneypot ) { box.shouseHoneypot.invalidate(); }
			} );
		}
	} );

	// The submit path also handles autofill, keyboard-only use and very fast submissions.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var box = form.querySelector ? form.querySelector( '.shouse-hp' ) : null;
		if ( ! box ) { return; }
		armHoneypot( box );
		var state = box.shouseHoneypot;
		if ( ! state ) { return; }
		if ( state.valid() && Date.now() >= state.ready ) { state.afterSubmit( event ); return; }
		event.preventDefault();
		event.stopImmediatePropagation();
		if ( state.submitting ) { return; }
		state.submitting = true;
		var submitter = event.submitter && event.submitter.form === form ? event.submitter : null;
		state.prepare( true ).then( function () {
			return new Promise( function ( resolve ) { setTimeout( resolve, Math.max( 0, state.ready - Date.now() ) ); } );
		} ).then( function () {
			state.submitting = false;
			if ( document.contains( form ) && form.contains( box ) ) { resumeForm( form, submitter ); }
		} ).catch( function () { state.submitting = false; } );
	}, true );

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
		var go = el.shouseResume;
		el.shouseResume = null;
		if ( go ) {
			go();
		}
	}

	function render( el ) {
		if ( el.shouseWidget !== undefined || ! window.turnstile ) {
			return;
		}
		el.shouseWidget = window.turnstile.render( el, {
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
		if ( el && el.shouseWidget !== undefined && window.turnstile ) {
			window.turnstile.reset( el.shouseWidget );
		}
	}

	function scan() {
		each( '.shouse-hp', armHoneypot );
		each( '.shouse-turnstile', render );
		placeBlockCheckoutWidget();
	}

	/* Wait for a token before a form is sent; give up after WAIT_MS and let the server decide. */
	document.addEventListener(
		'submit',
		function ( event ) {
			var form = event.target;
			var el = form.querySelector ? form.querySelector( '.shouse-turnstile' ) : null;
			if ( ! el || token( el ) || form.shouseSending ) {
				return;
			}
			if ( ! window.turnstile ) {
				markUnreachable( el );
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			event.shouseWaitingTurnstile = true;
			if ( el.shouseResume ) {
				return;
			}
			var submitter = event.submitter && event.submitter.form === form ? event.submitter : null;
			var timer = setTimeout( function () {
				resume( el );
			}, WAIT_MS );
			el.shouseResume = function () {
				clearTimeout( timer );
				form.shouseSending = true;
				resumeForm( form, submitter );
				form.shouseSending = false;
			};
		},
		true
	);

	/* Classic checkout: the widget is re-rendered with the payment box, and a token is single-use. */
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'checkout_error', function () {
			each( 'form.checkout .shouse-turnstile', reset );
		} );
	}

	/* Block checkout (found in the DOM, wherever the theme put the block): no PHP form hook, so the
	   widget goes above the Place order button, and the token travels in a header on the Store API
	   checkout request. */
	var blockWidget = null;

	function placeBlockCheckoutWidget() {
		if ( ! config.checkout || ! config.sitekey || ( blockWidget && document.body.contains( blockWidget ) ) ) {
			return;
		}
		var actions = document.querySelector( '.wc-block-checkout__actions' );
		if ( ! actions || ! actions.parentNode ) {
			return;
		}
		blockWidget = document.createElement( 'div' );
		blockWidget.className = 'shouse-turnstile';
		blockWidget.setAttribute( 'data-sitekey', config.sitekey );
		blockWidget.setAttribute( 'data-action', 'shouse_checkout' );
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

	if ( config.checkout && config.sitekey && window.wp && window.wp.apiFetch ) {
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

	window.shouseTurnstileReady = scan;
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
