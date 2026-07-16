/**
 * Digitizer Pro Tools - Enlighter
 * Dependency-free syntax highlighter. It reads each block's textContent
 * (already HTML-escaped by the browser), tokenises it and rebuilds the markup
 * from individually escaped pieces, so nothing in the source code can inject
 * markup or script.
 */
( function () {
	'use strict';

	var KEYWORDS = {
		php: 'abstract and array as break callable case catch class clone const continue declare default do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile enum extends final finally fn for foreach function global goto if implements include include_once instanceof insteadof interface isset list match namespace new or print private protected public readonly require require_once return static switch throw trait try unset use var while xor yield true false null',
		javascript: 'await async break case catch class const continue debugger default delete do else export extends finally for function if import in instanceof let new of return static super switch this throw try typeof var void while with yield true false null undefined',
		css: '',
		html: '',
		sql: 'select insert update delete from where join inner left right outer on group by order having limit offset union all as into values set create table alter drop index view primary key foreign references default null not and or like in between distinct count sum avg min max',
		bash: 'if then else elif fi for while do done case esac function in select until echo export local return read set unset shift source alias',
		python: 'and as assert async await break class continue def del elif else except finally for from global if import in is lambda nonlocal not or pass raise return try while with yield True False None self',
		json: 'true false null',
		plain: ''
	};

	function esc( s ) {
		return s.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	function span( cls, text ) {
		return '<span class="dpt-en-' + cls + '">' + esc( text ) + '</span>';
	}

	// Ordered token matchers. First match wins at each position.
	function rulesFor( lang ) {
		var kw = ( KEYWORDS[ lang ] || '' ).trim();
		var rules = [];

		if ( lang === 'html' ) {
			rules.push( [ 'comment', /^<!--[\s\S]*?-->/ ] );
			rules.push( [ 'tag', /^<\/?[a-zA-Z][\w:-]*/ ] );
			rules.push( [ 'string', /^"[^"]*"|^'[^']*'/ ] );
			rules.push( [ 'attr', /^[a-zA-Z_:][\w:.-]*(?==)/ ] );
			return rules;
		}

		// Comments.
		if ( lang === 'python' || lang === 'bash' ) {
			rules.push( [ 'comment', /^#[^\n]*/ ] );
		}
		if ( lang === 'sql' ) {
			rules.push( [ 'comment', /^--[^\n]*/ ] );
		}
		if ( lang === 'php' || lang === 'javascript' || lang === 'css' || lang === 'sql' ) {
			rules.push( [ 'comment', /^\/\*[\s\S]*?\*\// ] );
		}
		if ( lang === 'php' || lang === 'javascript' ) {
			rules.push( [ 'comment', /^\/\/[^\n]*/ ] );
			rules.push( [ 'comment', /^#[^\n]*/ ] );
		}

		// Strings.
		rules.push( [ 'string', /^"(?:\\.|[^"\\])*"/ ] );
		rules.push( [ 'string', /^'(?:\\.|[^'\\])*'/ ] );
		if ( lang === 'javascript' ) {
			rules.push( [ 'string', /^`(?:\\.|[^`\\])*`/ ] );
		}

		// PHP / bash variables.
		if ( lang === 'php' || lang === 'bash' ) {
			rules.push( [ 'variable', /^\$[a-zA-Z_]\w*/ ] );
		}

		// Numbers.
		rules.push( [ 'number', /^0x[0-9a-fA-F]+|^\d+\.?\d*(?:[eE][+-]?\d+)?/ ] );

		// Keywords / identifiers.
		if ( kw ) {
			var words = kw.split( /\s+/ ).map( function ( w ) {
				return w.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
			} );
			rules.push( [ 'keyword', new RegExp( '^(?:' + words.join( '|' ) + ')\\b' ) ] );
		}

		return rules;
	}

	function highlight( code, lang ) {
		var rules = rulesFor( lang );
		var out = '';
		var i = 0;
		var guard = 0;
		while ( i < code.length && guard++ < 500000 ) {
			var rest = code.slice( i );
			var matched = false;
			for ( var r = 0; r < rules.length; r++ ) {
				var m = rules[ r ][ 1 ].exec( rest );
				if ( m && m[ 0 ].length ) {
					out += span( rules[ r ][ 0 ], m[ 0 ] );
					i += m[ 0 ].length;
					matched = true;
					break;
				}
			}
			if ( ! matched ) {
				// Consume one identifier/word or a single character.
				var w = /^[A-Za-z_]\w*|^\s+|^./.exec( rest );
				var chunk = w ? w[ 0 ] : rest[ 0 ];
				out += esc( chunk );
				i += chunk.length;
			}
		}
		return out;
	}

	function detectLang( el ) {
		var cls = el.className || '';
		var m = /(?:language|lang|brush)[-:]([a-z0-9#+]+)/i.exec( cls );
		if ( m ) { return m[ 1 ].toLowerCase(); }
		if ( el.getAttribute( 'data-lang' ) ) { return el.getAttribute( 'data-lang' ).toLowerCase(); }
		return 'plain';
	}

	function decorate( block ) {
		if ( block.getAttribute( 'data-dpt-en-done' ) === '1' ) { return; }
		block.setAttribute( 'data-dpt-en-done', '1' );

		var codeEl = block.querySelector( 'code' ) || block;
		var lang = detectLang( block ) !== 'plain' ? detectLang( block ) : detectLang( codeEl );
		var source = codeEl.textContent || '';
		// Trim a single leading/trailing newline for tidy rendering.
		source = source.replace( /^\n/, '' ).replace( /\s+$/, '' );

		codeEl.innerHTML = highlight( source, lang );
		block.classList.add( 'dpt-en', 'dpt-en-lang-' + lang );

		if ( block.getAttribute( 'data-dpt-en-lines' ) === '1' ) {
			block.classList.add( 'dpt-en-has-lines' );
			buildLineNumbers( block, source );
		}
		if ( block.getAttribute( 'data-dpt-en-copy' ) === '1' ) {
			addCopyButton( block, source );
		}
	}

	function buildLineNumbers( block, source ) {
		var count = source.split( '\n' ).length;
		var gutter = document.createElement( 'span' );
		gutter.className = 'dpt-en-gutter';
		gutter.setAttribute( 'aria-hidden', 'true' );
		var frag = '';
		for ( var n = 1; n <= count; n++ ) { frag += n + '\n'; }
		gutter.textContent = frag;
		block.insertBefore( gutter, block.firstChild );
	}

	function addCopyButton( block, source ) {
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'dpt-en-copy';
		btn.textContent = ( window.DPTEnlighter && window.DPTEnlighter.copyLabel ) || 'Copy';
		btn.addEventListener( 'click', function () {
			var done = function () {
				var old = btn.textContent;
				btn.textContent = ( window.DPTEnlighter && window.DPTEnlighter.copiedLabel ) || 'Copied';
				setTimeout( function () { btn.textContent = old; }, 1500 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( source ).then( done, function () {} );
			} else {
				var ta = document.createElement( 'textarea' );
				ta.value = source;
				document.body.appendChild( ta );
				ta.select();
				try { document.execCommand( 'copy' ); done(); } catch ( e ) {}
				document.body.removeChild( ta );
			}
		} );
		block.appendChild( btn );
	}

	function run() {
		var blocks = document.querySelectorAll( 'pre.dpt-en-block, pre[data-dpt-en]' );
		blocks.forEach( decorate );

		if ( window.DPTEnlighter && window.DPTEnlighter.auto ) {
			document.querySelectorAll( 'pre > code' ).forEach( function ( code ) {
				var pre = code.parentNode;
				if ( ! pre.hasAttribute( 'data-dpt-en-done' ) && ! pre.classList.contains( 'dpt-en-block' ) ) {
					if ( window.DPTEnlighter.lines ) { pre.setAttribute( 'data-dpt-en-lines', '1' ); }
					if ( window.DPTEnlighter.copy ) { pre.setAttribute( 'data-dpt-en-copy', '1' ); }
					decorate( pre );
				}
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}

	window.DPTEnlighter = window.DPTEnlighter || {};
	window.DPTEnlighter.highlight = highlight;
} )();
