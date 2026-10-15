<?php
/**
 * The Code Highlighter's stylesheet keeps code left-to-right on RTL sites.
 *
 * Plain text checks on the CSS file: there is no browser here, and the rule
 * that matters is short enough to read directly.
 */
require_once __DIR__ . '/bootstrap.php';

$css = (string) file_get_contents( dirname( __DIR__ ) . '/modules/enlighter/assets/css/highlight.css' );

/**
 * The declarations of the first rule whose selector is exactly $selector.
 */
function dpt_test_css_rule( $css, $selector ) {
	$css = preg_replace( '#/\*.*?\*/#s', '', $css );
	if ( ! preg_match( '/(^|})\s*' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/', $css, $m ) ) {
		return '';
	}
	return preg_replace( '/\s+/', ' ', $m[2] );
}

// On a Hebrew page a block inherits rtl: lines align right, a trailing ; jumps
// to the start of the line and the gutter sits on the wrong side.
$block = dpt_test_css_rule( $css, '.dpt-en' );
dpt_test_ok( '' !== $block, 'the block rule is there' );
dpt_test_ok( false !== strpos( $block, 'direction: ltr;' ), 'a code block reads left to right whatever the page does' );
dpt_test_ok( false !== strpos( $block, 'text-align: left;' ), 'and is aligned to the left' );
dpt_test_ok( false !== strpos( $block, 'unicode-bidi: isolate;' ), 'and is isolated from the text around it' );

// Inline code inside a Hebrew sentence has the same problem on a smaller scale.
$inline = dpt_test_css_rule( $css, 'code.dpt-en-inline' );
dpt_test_ok( false !== strpos( $inline, 'direction: ltr;' ), 'inline code reads left to right too' );
dpt_test_ok( false !== strpos( $inline, 'unicode-bidi: isolate;' ), 'without reordering the sentence it sits in' );

exit( dpt_test_summary() > 0 ? 1 : 0 );
