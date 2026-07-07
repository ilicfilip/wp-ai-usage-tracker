/**
 * React entry point for the AI Usage dashboard.
 *
 * Configures @wordpress/api-fetch with the localized REST root + nonce, then
 * mounts <App /> into the #wp-aiut-root element printed by Settings_Page::render.
 *
 * @package
 */

import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';

import App from './App';
import './style.scss';

/**
 * Runtime config injected by wp_add_inline_script (window.wpAiUsageTracker).
 *
 * @type {{restRoot: string, nonce: string, currency: string, attribute: string}}
 */
const config = window.wpAiUsageTracker || {
	restRoot: '/wp-json/wp-aiut/v1/',
	nonce: '',
	currency: 'USD',
	attribute: 'wp_aiut_attribute',
};

// Authenticate api-fetch with the REST nonce. We deliberately do NOT use
// createRootURLMiddleware: WordPress's own wp-api-fetch already installs a
// default root-URL middleware ('/wp-json/') that takes precedence, so a custom
// namespace root would be ignored and requests would 404. Instead every call
// passes the full namespaced path (e.g. 'wp-aiut/v1/totals').
if ( config.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
}

// Mount once the DOM is ready. domReady() fires immediately when the document
// is already interactive/complete — which it is here, because the bundle is
// enqueued in the footer ($in_footer = true) and therefore runs after
// #wp-aiut-root is already in the DOM. A bare DOMContentLoaded listener would
// never fire in that case, leaving the dashboard blank.
domReady( () => {
	const root = document.getElementById( 'wp-aiut-root' );
	if ( ! root ) {
		return;
	}

	createRoot( root ).render( <App config={ config } /> );
} );
