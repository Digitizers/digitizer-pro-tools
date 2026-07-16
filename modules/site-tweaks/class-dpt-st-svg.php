<?php
/**
 * Site Tweaks module - conservative SVG sanitiser.
 *
 * SVG is XML that browsers execute: <script>, event handlers, javascript:
 * URLs and <foreignObject> all run in the page's origin. An unsanitised SVG
 * upload is therefore stored XSS. This sanitiser parses the file with
 * DOMDocument (no network, no entity expansion, so XXE / billion-laughs are
 * neutralised) and strips every scriptable element and attribute before the
 * file is ever written to the uploads directory.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_ST_SVG_Sanitizer {

	/**
	 * Elements that can execute script or load remote content. Matched
	 * case-insensitively against the local tag name.
	 */
	private static function blocked_tags() {
		return array(
			'script', 'foreignobject', 'iframe', 'embed', 'object',
			'audio', 'video', 'handler', 'listener',
			// SMIL animation can rewrite attributes (e.g. set href to a
			// javascript: URL) at runtime - drop it rather than reason about it.
			'set', 'animate', 'animatetransform', 'animatemotion',
		);
	}

	/**
	 * Sanitise a file in place. Returns true on success (file rewritten with
	 * clean SVG), false if the file is not parseable SVG.
	 *
	 * @param string $path Absolute path to the uploaded file.
	 */
	public static function sanitize_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return false;
		}
		$svg = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $svg || '' === trim( $svg ) ) {
			return false;
		}
		$clean = self::sanitize( $svg );
		if ( null === $clean ) {
			return false;
		}
		return false !== file_put_contents( $path, $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
	}

	/**
	 * Sanitise an SVG string. Returns the cleaned markup, or null if it could
	 * not be parsed as SVG.
	 *
	 * @param string $svg Raw SVG markup.
	 * @return string|null
	 */
	public static function sanitize( $svg ) {
		// Strip a UTF-8 BOM and any XML/doctype prolog - the DOCTYPE is where
		// entity-expansion attacks live, and we rebuild a clean prolog anyway.
		$svg = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $svg );
		$svg = preg_replace( '/<!DOCTYPE[^>]*(?:\[[^\]]*\])?>/is', '', $svg );
		$svg = preg_replace( '/<\?xml[^>]*\?>/i', '', $svg );

		if ( false === stripos( $svg, '<svg' ) ) {
			return null;
		}

		$dom                      = new DOMDocument();
		$dom->preserveWhiteSpace  = false;
		$dom->formatOutput        = false;

		// LIBXML_NONET blocks network access; we deliberately do NOT pass
		// LIBXML_NOENT, so custom entities are never expanded. Errors are
		// buffered so malformed markup fails cleanly instead of warning.
		$prev = libxml_use_internal_errors( true );
		$ok   = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok ) {
			return null;
		}

		$root = $dom->documentElement;
		if ( ! $root || 'svg' !== strtolower( $root->localName ) ) {
			return null;
		}

		// Drop any DOCTYPE node that survived parsing.
		if ( $dom->doctype ) {
			$dom->doctype->parentNode->removeChild( $dom->doctype );
		}

		// Clean the root <svg>'s own attributes (e.g. onload) as well as every
		// descendant - clean_element() only recurses into children.
		self::clean_attributes( $root );
		self::clean_element( $root );

		$out = $dom->saveXML( $root );
		return is_string( $out ) ? $out : null;
	}

	/**
	 * Recursively strip blocked elements and scriptable attributes.
	 */
	private static function clean_element( DOMElement $el ) {
		$blocked = self::blocked_tags();

		// Iterate over a static list: we mutate child nodes as we go.
		$children = array();
		foreach ( $el->childNodes as $child ) {
			$children[] = $child;
		}

		foreach ( $children as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				// Remove processing instructions (they can carry scripts in
				// some renderers); keep text/comment/CDATA nodes.
				if ( XML_PI_NODE === $child->nodeType ) {
					$el->removeChild( $child );
				}
				continue;
			}

			$tag = strtolower( $child->localName );
			if ( in_array( $tag, $blocked, true ) ) {
				$el->removeChild( $child );
				continue;
			}

			self::clean_attributes( $child );
			self::clean_element( $child );
		}
	}

	/**
	 * Remove event handlers, javascript: URLs and unsafe references from a
	 * single element's attributes.
	 */
	private static function clean_attributes( DOMElement $el ) {
		$attrs = array();
		foreach ( $el->attributes as $attr ) {
			$attrs[] = $attr;
		}

		foreach ( $attrs as $attr ) {
			$name  = strtolower( $attr->nodeName );
			$local = strtolower( $attr->localName );
			$value = (string) $attr->nodeValue;

			// Any on* event handler (onload, onclick, onmouseover, ...).
			if ( 0 === strpos( $local, 'on' ) ) {
				$el->removeAttribute( $attr->nodeName );
				continue;
			}

			// href / xlink:href / src: allow only same-document fragments and
			// safe raster data URIs. Everything else (javascript:, data:text,
			// remote URLs) is dropped.
			if ( 'href' === $local || 'src' === $local ) {
				if ( ! self::is_safe_reference( $value ) ) {
					$el->removeAttribute( $attr->nodeName );
				}
				continue;
			}

			// style: block script-bearing CSS.
			if ( 'style' === $name && self::style_is_dangerous( $value ) ) {
				$el->removeAttribute( $attr->nodeName );
				continue;
			}

			// A javascript: payload can hide in almost any attribute value.
			if ( false !== strpos( self::normalize( $value ), 'javascript:' ) ) {
				$el->removeAttribute( $attr->nodeName );
			}
		}
	}

	/**
	 * True for values safe to keep on href/src: internal fragment refs and
	 * base64-encoded raster images.
	 */
	private static function is_safe_reference( $value ) {
		$v = trim( $value );
		if ( '' === $v || '#' === $v[0] ) {
			return true;
		}
		if ( preg_match( '#^data:image/(png|jpe?g|gif|webp);base64,#i', $v ) ) {
			return true;
		}
		return false;
	}

	private static function style_is_dangerous( $style ) {
		$s = self::normalize( $style );
		return ( false !== strpos( $s, 'javascript:' )
			|| false !== strpos( $s, 'expression(' )
			|| false !== strpos( $s, '@import' )
			|| preg_match( '/url\s*\(\s*["\']?\s*(?:https?:|javascript:|data:text)/', $s ) );
	}

	/**
	 * Collapse whitespace, HTML entities and control characters so obfuscated
	 * "j&#97;vascript:" style payloads are detected.
	 */
	private static function normalize( $value ) {
		$v = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5 );
		$v = preg_replace( '/[\x00-\x20]+/', '', $v ); // strip all whitespace/control chars
		return strtolower( (string) $v );
	}
}
