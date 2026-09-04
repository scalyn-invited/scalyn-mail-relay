/**
 * Scalyn Mail Relay admin interface.
 *
 * @package ScalynMailRelay
 */

( function() {
	'use strict';

	/**
	 * Upper bound on how long a diagnostics request may stay pending before the
	 * UI gives up and tells the user to reload. Diagnostics perform DNS lookups
	 * and an SMTP/TLS probe, each of which has its own server-side timeout.
	 */
	const REQUEST_TIMEOUT_MS = 120000;

	/**
	 * Returns the localized settings object, or an empty object if it is missing.
	 *
	 * @return {Object} Settings passed via wp_localize_script().
	 */
	function settings() {
		return window.scalynMailRelaySettings || {};
	}

	/**
	 * Handle "Run Diagnostics" button click.
	 * Makes a POST request to the diagnostics endpoint.
	 *
	 * Active wherever the dedicated button id is rendered (Diagnostics page and
	 * Dashboard quick actions).
	 */
	function initDiagnosticsButton() {
		const button = document.getElementById( 'scalyn-run-diagnostics' );
		if ( ! button || ! button.dataset.scalynAction || 'run-diagnostics' !== button.dataset.scalynAction ) {
			return;
		}

		const url = button.dataset.endpoint;
		if ( ! url ) {
			return;
		}

		// Convert link to a POST request; the endpoint does not accept GET.
		button.addEventListener( 'click', function( e ) {
			e.preventDefault();
			runDiagnostics( button, url );
		} );
	}

	/**
	 * Make POST request to run diagnostics endpoint.
	 *
	 * @param {HTMLElement} button The button element.
	 * @param {string}      url    The diagnostics endpoint URL.
	 */
	function runDiagnostics( button, url ) {
		// The enabled button is an <a>, which has no native disabled state, so a
		// second click during a run must be ignored explicitly. Otherwise the
		// second call would capture "Running..." as the label to restore.
		if ( 'true' === button.dataset.scalynBusy ) {
			return;
		}

		const originalText = button.textContent;
		setBusy( button, true );
		button.textContent = settings().runningLabel || 'Running...';

		const controller = ( 'AbortController' in window ) ? new AbortController() : null;
		const timer = controller ? window.setTimeout( function() {
			controller.abort();
		}, REQUEST_TIMEOUT_MS ) : null;

		fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': settings().restNonce || '',
			},
			signal: controller ? controller.signal : undefined,
		} )
			.then( function( response ) {
				// Read as text first so a non-JSON body (PHP notice, HTML error
				// page) produces a readable error instead of an opaque parse failure.
				return response.text().then( function( text ) {
					return { response: response, text: text };
				} );
			} )
			.then( function( result ) {
				const data = parseJson( result.text );
				if ( result.response.ok && data && data.success ) {
					refreshResults( button );
					return;
				}
				throw new Error( errorMessageFor( result.response, data ) );
			} )
			.catch( function( error ) {
				let message;
				if ( error && 'AbortError' === error.name ) {
					message = settings().timeoutMessage || 'The diagnostics run is taking longer than expected. Reload the page in a moment to see the latest results.';
				} else {
					message = String( ( error && error.message ) || 'Diagnostics run failed' );
				}
				showErrorNotice( button, message );
				button.textContent = originalText;
				setBusy( button, false );
			} )
			.finally( function() {
				if ( null !== timer ) {
					window.clearTimeout( timer );
				}
			} );
	}

	/**
	 * Toggles the busy/disabled presentation of the button.
	 *
	 * @param {HTMLElement} button The button element.
	 * @param {boolean}     busy   Whether a run is in progress.
	 */
	function setBusy( button, busy ) {
		button.dataset.scalynBusy = busy ? 'true' : 'false';
		button.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		button.setAttribute( 'aria-disabled', busy ? 'true' : 'false' );
		if ( 'disabled' in button ) {
			button.disabled = busy;
		}
	}

	/**
	 * Navigates to the results view with a cache-busting parameter so the
	 * freshly persisted run is always rendered, even behind an aggressive
	 * page cache. Uses the button's redirect target when set (Dashboard),
	 * otherwise the current page (Diagnostics).
	 *
	 * @param {HTMLElement} button The button element.
	 */
	function refreshResults( button ) {
		const target = button.dataset.redirect || window.location.href;
		window.location.replace( withCacheBuster( target ) );
	}

	/**
	 * Appends/replaces a timestamp query parameter on a URL.
	 *
	 * @param {string} url Absolute or relative URL.
	 * @return {string} URL with a scalyn_refreshed parameter.
	 */
	function withCacheBuster( url ) {
		try {
			const parsed = new URL( url, window.location.href );
			parsed.searchParams.set( 'scalyn_refreshed', String( Date.now() ) );
			parsed.hash = '';
			return parsed.toString();
		} catch ( e ) {
			return url;
		}
	}

	/**
	 * Parses a JSON response body, tolerating stray output before the JSON
	 * (for example a PHP notice emitted with WP_DEBUG_DISPLAY on).
	 *
	 * @param {string} text Raw response body.
	 * @return {Object|null} Parsed object, or null when no JSON could be found.
	 */
	function parseJson( text ) {
		if ( ! text ) {
			return null;
		}
		try {
			return JSON.parse( text );
		} catch ( e ) {
			const start = text.indexOf( '{' );
			if ( start > 0 ) {
				try {
					return JSON.parse( text.slice( start ) );
				} catch ( inner ) {
					return null;
				}
			}
			return null;
		}
	}

	/**
	 * Builds a human-readable error message from a failed response.
	 *
	 * @param {Response}    response The fetch Response.
	 * @param {Object|null} data     Parsed JSON body, if any.
	 * @return {string} Error message.
	 */
	function errorMessageFor( response, data ) {
		if ( data && typeof data.message === 'string' && data.message ) {
			// WP_Error / WP_REST_Response error shape: { code, message, data }.
			return data.message;
		}
		if ( data && data.data && typeof data.data.message === 'string' && data.data.message ) {
			return data.data.message;
		}
		if ( ! response.ok ) {
			return 'Diagnostics run failed (HTTP ' + response.status + ( response.statusText ? ' ' + response.statusText : '' ) + ').';
		}
		return 'Diagnostics run failed: the server returned an unexpected response.';
	}

	/**
	 * Inserts an error notice near the button.
	 *
	 * Diagnostics page: above the first results section. Dashboard: above the
	 * Quick Actions card. Fallback: directly above the button.
	 *
	 * @param {HTMLElement} button  The button element.
	 * @param {string}      message The error message.
	 */
	function showErrorNotice( button, message ) {
		try {
			const existing = document.querySelector( '.scalyn-diagnostics-notice' );
			if ( existing && existing.parentNode ) {
				existing.parentNode.removeChild( existing );
			}
			const notice = createErrorNotice( settings().errorPrefix || 'Error running diagnostics:', message );
			const container = document.querySelector( '.scalyn-diagnostics-section' )
				|| button.closest( '.scalyn-card' )
				|| button;
			if ( container && container.parentNode ) {
				container.parentNode.insertBefore( notice, container );
			}
		} catch ( e ) {
			// Never let notice rendering prevent the button from being restored.
			console.error( e );
		}
	}

	/**
	 * Creates an error notice element.
	 *
	 * @param {string} prefix   The error prefix text.
	 * @param {string} message  The error message.
	 * @return {HTMLElement} The notice element.
	 */
	function createErrorNotice( prefix, message ) {
		const notice = document.createElement( 'div' );
		notice.className = 'notice notice-error scalyn-diagnostics-notice';
		notice.setAttribute( 'role', 'status' );
		notice.setAttribute( 'aria-live', 'polite' );
		notice.innerHTML = '<p><strong>' + escapeHtml( prefix ) + '</strong> ' + escapeHtml( message ) + '</p>';
		return notice;
	}

	/**
	 * Simple HTML escape helper.
	 *
	 * @param {string} text Text to escape.
	 * @return {string} Escaped text.
	 */
	function escapeHtml( text ) {
		const map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;',
		};
		return String( text ).replace( /[&<>"']/g, function( char ) {
			return map[ char ];
		} );
	}

	// Initialize when DOM is ready
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initDiagnosticsButton );
	} else {
		initDiagnosticsButton();
	}
} )();
