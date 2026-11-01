/**
 * UTM Passthrough: copy the address's utm_* parameters, and any others the
 * site names, onto its own links. Links are rewritten rather than handled on click, so a middle click,
 * "open in new tab" and "copy link" carry the parameters too.
 */
( function () {
	'use strict';

	var config = window.DPT_UTM_CONFIG || {};
	var named  = config.params || [];
	var pairs  = [];
	new URLSearchParams( window.location.search ).forEach( function ( value, key ) {
		if ( isCarried( key ) ) {
			pairs.push( [ key, value ] );
		}
	} );
	if ( ! pairs.length ) {
		return;
	}

	var query   = new URLSearchParams( pairs ).toString();
	var host    = bareHost( window.location.hostname );
	var exclude = config.exclude || [];
	var files   = /\.(pdf|zip|rar|7z|gz|docx?|xlsx?|pptx?|csv|jpe?g|png|gif|webp|svg|mp3|mp4|mov|webm)$/i;

	function isCarried( key ) {
		return 0 === key.indexOf( 'utm_' ) || -1 !== named.indexOf( key );
	}

	function bareHost( name ) {
		return name.replace( /^www\./i, '' ).toLowerCase();
	}

	function excludedPath( path ) {
		return exclude.some( function ( item ) {
			var dir = item.replace( /\/$/, '' );
			return path === dir || 0 === path.indexOf( dir + '/' );
		} );
	}

	function hasOwnOrCart( params ) {
		var found = false;
		params.forEach( function ( value, key ) {
			found = found || isCarried( key ) || 'add-to-cart' === key;
		} );
		return found;
	}

	function update( link ) {
		var attr = link.getAttribute( 'href' );
		if ( ! attr || '#' === attr.charAt( 0 ) || link.hasAttribute( 'download' ) ) {
			return;
		}
		var url;
		try {
			url = new URL( link.href );
		} catch ( e ) {
			return;
		}
		if ( ( 'http:' !== url.protocol && 'https:' !== url.protocol ) || bareHost( url.hostname ) !== host ) {
			return;
		}
		if ( excludedPath( url.pathname ) || files.test( url.pathname ) || hasOwnOrCart( url.searchParams ) ) {
			return;
		}
		// Appended to the query as written: re-serializing would respell it.
		url.search = ( url.search ? url.search + '&' : '?' ) + query;
		link.href  = url.href;
	}

	function updateWithin( node ) {
		if ( 1 !== node.nodeType ) {
			return;
		}
		if ( node.matches( 'a[href]' ) ) {
			update( node );
		}
		node.querySelectorAll( 'a[href]' ).forEach( update );
	}

	function start() {
		document.querySelectorAll( 'a[href]' ).forEach( update );
		// Popups, Loop Grids and menus add their links after load.
		new MutationObserver( function ( records ) {
			records.forEach( function ( record ) {
				record.addedNodes.forEach( updateWithin );
			} );
		} ).observe( document.documentElement, { childList: true, subtree: true } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
