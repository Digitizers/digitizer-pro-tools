/**
 * Digitizer Pro Tools - WooCommerce Checkout helpers.
 * Email-domain typo suggestions and Israeli phone-number validation.
 * All user text is inserted via DOM text nodes (never innerHTML), so nothing
 * typed into the fields can inject markup.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.DPTWooCheckout || {};
	var i18n = cfg.i18n || {};

	function fmt( template, args ) {
		var s = String( template || '' );
		// Positional (%1$d / %2$s) first, then a plain %s / %d fallback.
		s = s.replace( /%(\d+)\$[ds]/g, function ( _, n ) {
			return typeof args[ n - 1 ] !== 'undefined' ? args[ n - 1 ] : '';
		} );
		var i = 0;
		s = s.replace( /%[ds]/g, function () {
			return typeof args[ i ] !== 'undefined' ? args[ i++ ] : '';
		} );
		return s;
	}

	// --- Email typo suggestion --------------------------------------------

	function levenshtein( a, b ) {
		a = String( a );
		b = String( b );
		var m = a.length, n = b.length;
		if ( ! m ) { return n; }
		if ( ! n ) { return m; }
		var prev = [], cur = [], i, j;
		for ( j = 0; j <= n; j++ ) { prev[ j ] = j; }
		for ( i = 1; i <= m; i++ ) {
			cur[ 0 ] = i;
			for ( j = 1; j <= n; j++ ) {
				var cost = a.charAt( i - 1 ) === b.charAt( j - 1 ) ? 0 : 1;
				cur[ j ] = Math.min( cur[ j - 1 ] + 1, prev[ j ] + 1, prev[ j - 1 ] + cost );
			}
			prev = cur.slice();
		}
		return prev[ n ];
	}

	function closestDomain( domain, domains ) {
		var best = null, bestDist = Infinity;
		for ( var k = 0; k < domains.length; k++ ) {
			var d = levenshtein( domain, domains[ k ] );
			if ( d < bestDist ) { bestDist = d; best = domains[ k ]; }
		}
		return { domain: best, dist: bestDist };
	}

	function checkEmail( email, domains ) {
		var at = email.lastIndexOf( '@' );
		if ( at < 1 || at === email.length - 1 ) {
			return { valid: false, suggestion: '' };
		}
		var username = email.slice( 0, at );
		var domain = email.slice( at + 1 ).toLowerCase();

		if ( domains.indexOf( domain ) !== -1 ) {
			return { valid: true, suggestion: '' };
		}
		var match = closestDomain( domain, domains );
		// Suggest only for a near-miss: a small absolute edit distance that is
		// also a small fraction of the domain length (avoids suggesting a
		// completely different domain).
		if ( match.domain && match.dist > 0 && match.dist <= 2 &&
			match.dist <= Math.ceil( match.domain.length / 3 ) ) {
			return { valid: false, suggestion: username + '@' + match.domain };
		}
		return { valid: false, suggestion: '' };
	}

	function removeEmailSuggestion() {
		$( '#dpt-wcc-email-suggest' ).remove();
	}

	function showEmailSuggestion( $input, suggestion ) {
		removeEmailSuggestion();
		var template = i18n.emailSuggest || 'Did you mean %s?';
		var idx = template.indexOf( '%s' );
		var before = idx === -1 ? template + ' ' : template.slice( 0, idx );
		var after = idx === -1 ? '' : template.slice( idx + 2 );

		var $div = $( '<div>', { id: 'dpt-wcc-email-suggest', 'class': 'dpt-wcc-suggest' } );
		$div.append( document.createTextNode( before ) );
		$( '<u>' ).text( suggestion ).appendTo( $div ); // .text() escapes
		$div.append( document.createTextNode( after ) );

		$div.on( 'click', function () {
			$input.val( suggestion ).removeClass( 'dpt-wcc-bad' ).addClass( 'dpt-wcc-ok' );
			removeEmailSuggestion();
		} );

		$input.parent().append( $div );
		// Fade in.
		$div[ 0 ].offsetHeight; // reflow
		$div.addClass( 'is-visible' );
	}

	function bindEmail() {
		var $input = $( '#billing_email' );
		if ( ! $input.length ) { return; }
		var domains = ( cfg.domains || [] ).map( function ( d ) { return String( d ).toLowerCase(); } );

		$( document.body ).on( 'blur', '#billing_email', function () {
			var email = String( $( this ).val() || '' ).trim();
			if ( ! email ) { removeEmailSuggestion(); return; }
			var result = checkEmail( email, domains );
			if ( result.valid ) {
				$( this ).removeClass( 'dpt-wcc-bad' ).addClass( 'dpt-wcc-ok' );
				removeEmailSuggestion();
			} else if ( result.suggestion ) {
				$( this ).removeClass( 'dpt-wcc-ok' ).addClass( 'dpt-wcc-bad' );
				showEmailSuggestion( $( this ), result.suggestion );
			} else {
				// No suggestion applies (unknown but plausible domain): clear
				// any stale valid/invalid state left by a previous entry.
				$( this ).removeClass( 'dpt-wcc-ok dpt-wcc-bad' );
				removeEmailSuggestion();
			}
		} );
	}

	// --- Phone validation --------------------------------------------------

	function validatePhone( phone ) {
		var cleaned = String( phone ).replace( /[^\d+]/g, '' );
		// Collapse to a single optional leading '+' so padding plus signs
		// (e.g. "05++++") cannot satisfy the length check.
		var lead = cleaned.charAt( 0 ) === '+' ? '+' : '';
		cleaned = lead + cleaned.replace( /\+/g, '' );
		var rules = [
			{ prefix: '+972', len: 13 },
			{ prefix: '972', len: 12 },
			{ prefix: '05', len: 10 }
		];
		for ( var k = 0; k < rules.length; k++ ) {
			if ( cleaned.indexOf( rules[ k ].prefix ) === 0 ) {
				var diff = cleaned.length - rules[ k ].len;
				if ( diff === 0 ) { return { valid: true }; }
				if ( diff < 0 ) {
					return { valid: false, message: fmt( i18n.phoneMissing, [ -diff, rules[ k ].prefix ] ) };
				}
				return { valid: false, message: fmt( i18n.phoneExtra, [ diff, rules[ k ].prefix ] ) };
			}
		}
		return { valid: false, message: i18n.phoneFormat || '' };
	}

	function removePhoneError() {
		$( '#dpt-wcc-phone-error' ).remove();
	}

	function showPhoneError( $input, message ) {
		removePhoneError();
		var $div = $( '<div>', { id: 'dpt-wcc-phone-error', 'class': 'dpt-wcc-error' } );
		$div.text( message ); // .text() escapes
		$input.parent().append( $div );
	}

	function bindPhone() {
		if ( ! $( '#billing_phone' ).length ) { return; }

		$( document.body ).on( 'keypress', '#billing_phone', function ( e ) {
			var code = e.which ? e.which : e.keyCode;
			if ( code < 32 ) { return; } // allow control keys
			var ch = String.fromCharCode( code );
			if ( ! /[\d+]/.test( ch ) || ( ch === '+' && this.value.indexOf( '+' ) !== -1 ) ) {
				e.preventDefault();
			}
		} );

		$( document.body ).on( 'blur', '#billing_phone', function () {
			var phone = String( $( this ).val() || '' ).trim();
			if ( ! phone ) { removePhoneError(); $( this ).removeClass( 'dpt-wcc-ok dpt-wcc-bad' ); return; }
			var result = validatePhone( phone );
			if ( result.valid ) {
				$( this ).removeClass( 'dpt-wcc-bad' ).addClass( 'dpt-wcc-ok' );
				removePhoneError();
			} else {
				$( this ).removeClass( 'dpt-wcc-ok' ).addClass( 'dpt-wcc-bad' );
				showPhoneError( $( this ), result.message );
			}
		} );
	}

	$( function () {
		if ( cfg.emailEnabled ) { bindEmail(); }
		if ( cfg.phoneEnabled ) { bindPhone(); }
	} );

	// Expose for testing.
	window.DPTWooCheckout = window.DPTWooCheckout || {};
	window.DPTWooCheckout._checkEmail = checkEmail;
	window.DPTWooCheckout._validatePhone = validatePhone;
	window.DPTWooCheckout._levenshtein = levenshtein;
} )( jQuery );
