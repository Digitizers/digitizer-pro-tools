/**
 * Digitizer Pro Tools - Onboarding wizard.
 *
 * Walks the ticked rows one request at a time, in the order the manifest put
 * them on the page, and updates each row as its result comes back. Sequential
 * on purpose: parallel installs race on the same filesystem, and a parent
 * theme has to land before its child.
 */
( function () {
	'use strict';

	var cfg = window.DPT_ONB || {};
	var run = document.getElementById( 'dpt-onb-run' );
	var out = document.getElementById( 'dpt-onb-summary' );
	var all = document.getElementById( 'dpt-onb-all' );

	if ( ! run ) {
		return;
	}

	if ( all ) {
		all.addEventListener( 'change', function () {
			var boxes = document.querySelectorAll( '.dpt-onb-pick' );
			Array.prototype.forEach.call( boxes, function ( b ) {
				b.checked = all.checked;
			} );
		} );
	}

	function setStatus( id, text, state ) {
		var row = document.querySelector( '[data-item="' + id + '"] .dpt-onb-status' );
		if ( row ) {
			row.textContent = text;
			row.setAttribute( 'data-state', state );
		}
	}

	function autoUpdateWanted() {
		var box = document.getElementById( 'dpt-onb-auto-update' );
		return box ? box.checked : false;
	}

	function apply( id ) {
		var body = new FormData();
		body.append( 'action', 'dpt_onb_apply' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'item', id );
		if ( autoUpdateWanted() ) {
			body.append( 'auto_update', '1' );
		}

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				var msg = json && json.data && json.data.message ? json.data.message : cfg.strings.failed;
				return { id: id, outcome: 'failed', message: msg };
			}
			return json.data;
		} ).catch( function () {
			return { id: id, outcome: 'failed', message: cfg.strings.network };
		} );
	}

	function cleanup() {
		var body = new FormData();
		body.append( 'action', 'dpt_onb_cleanup' );
		body.append( 'nonce', cfg.nonce );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( json ) {
			if ( ! json || ! json.success || ! json.data || ! json.data.results ) {
				return json && json.data && json.data.message ? json.data.message : cfg.strings.failed;
			}
			return json.data.results.map( function ( r ) {
				return r.slug ? r.slug + ': ' + r.message : r.message;
			} ).join( ' ' );
		} ).catch( function () {
			return cfg.strings.network;
		} );
	}

	run.addEventListener( 'click', function () {
		var picked = Array.prototype.map.call(
			document.querySelectorAll( '.dpt-onb-pick:checked' ),
			function ( b ) { return b.value; }
		);
		if ( ! picked.length ) {
			return;
		}

		run.disabled = true;
		out.textContent = '';

		// Counted separately, because the rows distinguish them and a summary
		// that calls an activation an installation contradicts what the
		// operator just watched happen.
		var installed = 0;
		var activated = 0;
		var skipped = 0;
		var failed = 0;

		// Reduce over a promise chain so items run strictly one after another.
		picked.reduce( function ( chain, id ) {
			return chain.then( function () {
				setStatus( id, cfg.strings.working, 'working' );
				return apply( id ).then( function ( res ) {
					if ( 'failed' === res.outcome ) {
						failed++;
					} else if ( 'skipped' === res.outcome ) {
						skipped++;
					} else if ( 'activated' === res.outcome ) {
						activated++;
					} else {
						installed++;
					}
					setStatus( id, res.message, res.outcome );
				} );
			} );
		}, Promise.resolve() ).then( function () {
			var summary = cfg.strings.summary
				.replace( '%1$d', installed )
				.replace( '%2$d', activated )
				.replace( '%3$d', skipped )
				.replace( '%4$d', failed );

			var cleanupBox = document.getElementById( 'dpt-onb-cleanup' );
			if ( ! cleanupBox || ! cleanupBox.checked ) {
				out.textContent = summary;
				run.disabled = false;
				return;
			}

			// Deletion runs last, once, and only after the installs have
			// finished - the fallback it preserves depends on what is on disk
			// at that point.
			out.textContent = summary + ' ' + cfg.strings.cleanup;
			return cleanup().then( function ( lines ) {
				out.textContent = summary + ( lines ? ' ' + lines : '' );
				run.disabled = false;
			} );
		} );
	} );
}() );
