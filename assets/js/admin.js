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
	 */
	function initDiagnosticsButton() {
		const button = document.querySelector( '.scalyn-actions .scalyn-action-btn' );
		if ( ! button || ! button.href ) {
			return;
		}

		// Convert link to button with POST handler
		const link = button.href;
		button.href = '#';
		button.addEventListener( 'click', function( e ) {
			e.preventDefault();
			runDiagnostics( link );
		} );
	}

	/**
	 * Make POST request to run diagnostics endpoint.
	 *
	 * @param {string} url The diagnostics endpoint URL.
	 */
	function runDiagnostics( url ) {
		const button = document.querySelector( '.scalyn-actions .scalyn-action-btn' );
		if ( ! button ) {
			return;
		}

		// Disable button and show loading state
		button.disabled = true;
		const originalText = button.textContent;
		button.textContent = 'Running...';

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
				// Success - reload page to show updated results
				window.location.reload();
			} )
			.catch( ( error ) => {
				// Show error message
				console.error( error );
				alert( 'Error running diagnostics: ' + error.message );
				button.disabled = false;
				button.textContent = originalText;
			} );
	}

	// Initialize when DOM is ready
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initDiagnosticsButton );
	} else {
		initDiagnosticsButton();
	}
} )();
