/**
 * Scalyn Mail Relay admin interface.
 *
 * @package ScalynMailRelay
 */

( function() {
	'use strict';

	/**
	 * Handle "Run Diagnostics Now" button click.
	 * Makes a POST request to the diagnostics endpoint.
	 *
	 * Only active on the Diagnostics screen (gated by checking for the dedicated button ID).
	 * Uses a dedicated id selector to avoid conflicts with other action buttons on the Dashboard.
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

		// Convert link to form submission
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
		const originalText = button.textContent;
		const originalDisabled = button.disabled;

		// Disable button and show loading state
		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		button.textContent = window.scalynMailRelaySettings?.runningLabel || 'Running...';

		// Make POST request to endpoint
		fetch( url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.scalynMailRelaySettings?.restNonce || '',
			},
		} )
			.then( ( response ) => {
				if ( response.ok ) {
					return response.json();
				}
				throw new Error( 'Diagnostics run failed: ' + response.statusText );
			} )
			.then( ( data ) => {
				// Check if the response indicates success
				if ( data && data.success ) {
					// Success - go to the results page when one is configured
					// (Dashboard button), otherwise reload to show updated results.
					if ( button.dataset.redirect ) {
						window.location.assign( button.dataset.redirect );
					} else {
						window.location.reload();
					}
				} else {
					// Server returned 200 but payload indicates failure
					const errorMsg = data && data.data ? data.data.message : 'Diagnostics run failed';
					throw new Error( errorMsg );
				}
			} )
			.catch( ( error ) => {
				// Show error in notice area instead of alert
				console.error( error );
				const notice = createErrorNotice( window.scalynMailRelaySettings?.errorPrefix || 'Error running diagnostics: ', error.message );
				// Diagnostics page: above the first results section. Dashboard: above
				// the Quick Actions card. Fallback: directly above the button.
				const container = document.querySelector( '.scalyn-diagnostics-section' )
					|| button.closest( '.scalyn-card' )
					|| button;
				if ( container && container.parentNode ) {
					container.parentNode.insertBefore( notice, container );
				}
				// Restore button state
				button.disabled = originalDisabled;
				button.setAttribute( 'aria-busy', 'false' );
				button.textContent = originalText;
			} );
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
		notice.className = 'notice notice-error';
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
		return text.replace( /[&<>"']/g, function( char ) {
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
