<?php
/**
 * Content Control module - rule engine. A restriction's conditions are
 * {operator, items[]} of rules and one level of groups; check() answers
 * "does this restriction apply to the given content context?".
 *
 * Contexts:
 *   post - array( 'type' => 'post', 'post' => WP_Post, 'term' => null )
 *   term - array( 'type' => 'term', 'post' => null, 'term' => WP_Term )
 *   main - as above plus 'main' => array of conditional-tag answers:
 *          is_front_page, is_home, is_search, is_404,
 *          is_post_type_archive (type names), is_tax (taxonomy names).
 *
 * Unknown rules evaluate to false - a rule nobody defined restricts
 * nothing, and NOT does not rescue it either.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_CC_Rules {

	/** @var array<string,array>|null Lazily built rule registry. */
	private static $definitions = null;

	public static function check( $conditions, $context ) {
		if ( ! is_array( $conditions ) || empty( $conditions['items'] ) || ! is_array( $conditions['items'] ) ) {
			return false;
		}
		$op   = ( isset( $conditions['operator'] ) && 'or' === $conditions['operator'] ) ? 'or' : 'and';
		$seen = false;
		foreach ( $conditions['items'] as $item ) {
			$result = ( isset( $item['type'] ) && 'group' === $item['type'] )
				? self::check_group( $item, $context )
				: self::check_rule( $item, $context );
			if ( null === $result ) {
				// A masked rule is NEUTRAL: it must neither satisfy an OR
				// nor fail an AND - "entire_site AND search" masked has to
				// keep reading as the search arm. (Codex round-11 P1)
				continue;
			}
			$seen = true;
			if ( 'or' === $op && $result ) {
				return true;
			}
			if ( 'and' === $op && ! $result ) {
				return false;
			}
		}
		return $seen && 'and' === $op;
	}

	private static function check_group( $group, $context ) {
		if ( empty( $group['items'] ) || ! is_array( $group['items'] ) ) {
			return false;
		}
		$op   = ( isset( $group['operator'] ) && 'and' === $group['operator'] ) ? 'and' : 'or';
		$seen = false;
		foreach ( $group['items'] as $rule ) {
			$result = self::check_rule( $rule, $context );
			if ( null === $result ) {
				continue;
			}
			$seen = true;
			if ( 'or' === $op && $result ) {
				return true;
			}
			if ( 'and' === $op && ! $result ) {
				return false;
			}
		}
		// A group of only masked rules is itself neutral.
		return $seen ? ( 'and' === $op ) : null;
	}

	private static function check_rule( $rule, $context ) {
		if ( ! is_array( $rule ) ) {
			return false;
		}
		$defs = self::definitions();
		$name = isset( $rule['name'] ) ? $rule['name'] : '';
		if ( ! isset( $defs[ $name ] ) || ! is_callable( $defs[ $name ]['callback'] ) ) {
			return false; // Fail closed; NOT does not apply to a rule that never ran.
		}
		if ( ! empty( $context['mask_any'] ) && ! empty( $defs[ $name ]['any_context'] ) ) {
			return null; // Neutral under mask - see check(). NOT does not resurrect it.
		}
		$options = isset( $rule['options'] ) && is_array( $rule['options'] ) ? $rule['options'] : array();
		$result  = (bool) call_user_func( $defs[ $name ]['callback'], $options, $context );
		return ! empty( $rule['not'] ) ? ! $result : $result;
	}

	/** Comma/space separated IDs option -> int[]. */
	public static function id_list( $options ) {
		$raw = isset( $options['ids'] ) ? (string) $options['ids'] : '';
		return array_values( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $raw ) ) ) );
	}

	private static function type_label( $type ) {
		$obj = get_post_type_object( $type );
		return ( $obj && isset( $obj->labels->singular_name ) ) ? $obj->labels->singular_name : $type;
	}

	private static function tax_label( $tax ) {
		$obj = get_taxonomy( $tax );
		return ( $obj && isset( $obj->labels->singular_name ) ) ? $obj->labels->singular_name : $tax;
	}

	/**
	 * The rule registry: name => {label, category, option, callback}.
	 * 'option' names the extra input a rule needs in the editor:
	 * '' (none), 'ids' (comma-separated IDs) or 'template' (file name).
	 * Built on first use so custom post types and taxonomies exist.
	 */
	public static function definitions() {
		if ( null !== self::$definitions ) {
			return self::$definitions;
		}
		$general = __( 'General', 'digitizer-pro-tools' );
		$defs    = array(
			'entire_site'               => array(
				'label'       => __( 'Entire site', 'digitizer-pro-tools' ),
				'category'    => $general,
				'option'      => '',
				// mask_any: the enforcement layer re-evaluates a matched row
				// with any-context rules NEUTRALIZED (skipped, not falsified)
				// to learn whether a MAIN-ONLY arm - search, blog index,
				// 404, archives - carries the match on its own. Pure post
				// rules are already false in a post-less main context;
				// entire_site is the one rule that is not, and falsifying it
				// would break "entire_site AND search". (Codex round-10/11 P1)
				'any_context' => true,
				'callback'    => static function () {
					return true;
				},
			),
			'content_is_front_page'     => array(
				'label'    => __( 'The front page', 'digitizer-pro-tools' ),
				'category' => $general,
				'option'   => '',
				'callback' => static function ( $o, $ctx ) {
					return ! empty( $ctx['main']['is_front_page'] );
				},
			),
			'content_is_blog_index'     => array(
				'label'    => __( 'The blog index', 'digitizer-pro-tools' ),
				'category' => $general,
				'option'   => '',
				'callback' => static function ( $o, $ctx ) {
					return ! empty( $ctx['main']['is_home'] );
				},
			),
			'content_is_search_results' => array(
				'label'    => __( 'Search results', 'digitizer-pro-tools' ),
				'category' => $general,
				'option'   => '',
				'callback' => static function ( $o, $ctx ) {
					return ! empty( $ctx['main']['is_search'] );
				},
			),
			'content_is_404_page'       => array(
				'label'    => __( 'The 404 page', 'digitizer-pro-tools' ),
				'category' => $general,
				'option'   => '',
				'callback' => static function ( $o, $ctx ) {
					return ! empty( $ctx['main']['is_404'] );
				},
			),
		);

		$taxonomies = array_keys( (array) get_taxonomies( array( 'public' => true ), 'names' ) );

		foreach ( array_keys( (array) get_post_types( array( 'public' => true ), 'names' ) ) as $pt ) {
			$obj   = get_post_type_object( $pt );
			$label = self::type_label( $pt );

			$defs[ "content_is_{$pt}" ] = array(
				/* translators: %s: post type name */
				'label'    => sprintf( __( 'A %s', 'digitizer-pro-tools' ), $label ),
				'category' => $label,
				'option'   => '',
				'callback' => static function ( $o, $ctx ) use ( $pt ) {
					return ! empty( $ctx['post'] ) && $ctx['post']->post_type === $pt;
				},
			);
			if ( 'post' === $pt || ( $obj && ! empty( $obj->has_archive ) ) ) {
				$defs[ "content_is_{$pt}_archive" ] = array(
					/* translators: %s: post type name */
					'label'    => sprintf( __( '%s archive', 'digitizer-pro-tools' ), $label ),
					'category' => $label,
					'option'   => '',
					'callback' => static function ( $o, $ctx ) use ( $pt ) {
						if ( 'post' === $pt && ! empty( $ctx['main']['is_home'] ) ) {
							return true;
						}
						return isset( $ctx['main']['is_post_type_archive'] )
							&& in_array( $pt, (array) $ctx['main']['is_post_type_archive'], true );
					},
				);
			}
			$defs[ "content_is_selected_{$pt}" ] = array(
				/* translators: %s: post type name */
				'label'    => sprintf( __( 'Selected %s (IDs)', 'digitizer-pro-tools' ), $label ),
				'category' => $label,
				'option'   => 'ids',
				'callback' => static function ( $o, $ctx ) use ( $pt ) {
					return ! empty( $ctx['post'] ) && $ctx['post']->post_type === $pt
						&& in_array( (int) $ctx['post']->ID, self::id_list( $o ), true );
				},
			);
			if ( is_post_type_hierarchical( $pt ) ) {
				$defs[ "content_is_child_of_{$pt}" ] = array(
					/* translators: %s: post type name */
					'label'    => sprintf( __( 'Child of %s (IDs)', 'digitizer-pro-tools' ), $label ),
					'category' => $label,
					'option'   => 'ids',
					'callback' => static function ( $o, $ctx ) use ( $pt ) {
						if ( empty( $ctx['post'] ) || $ctx['post']->post_type !== $pt ) {
							return false;
						}
						return (bool) array_intersect(
							self::id_list( $o ),
							array_map( 'intval', (array) get_post_ancestors( $ctx['post'] ) )
						);
					},
				);
				$defs[ "content_is_ancestor_of_{$pt}" ] = array(
					/* translators: %s: post type name */
					'label'    => sprintf( __( 'Ancestor of %s (IDs)', 'digitizer-pro-tools' ), $label ),
					'category' => $label,
					'option'   => 'ids',
					'callback' => static function ( $o, $ctx ) use ( $pt ) {
						if ( empty( $ctx['post'] ) || $ctx['post']->post_type !== $pt ) {
							return false;
						}
						foreach ( self::id_list( $o ) as $child_id ) {
							if ( in_array( (int) $ctx['post']->ID, array_map( 'intval', (array) get_post_ancestors( $child_id ) ), true ) ) {
								return true;
							}
						}
						return false;
					},
				);
			}
			if ( 'page' === $pt ) {
				$defs['content_is_page_with_template'] = array(
					'label'    => __( 'Page with template (file name, or "default")', 'digitizer-pro-tools' ),
					'category' => $label,
					'option'   => 'template',
					'callback' => static function ( $o, $ctx ) {
						if ( empty( $ctx['post'] ) || 'page' !== $ctx['post']->post_type ) {
							return false;
						}
						$want = isset( $o['template'] ) ? (string) $o['template'] : '';
						$slug = (string) get_page_template_slug( $ctx['post'] );
						if ( 'default' === $want ) {
							return '' === $slug;
						}
						return '' !== $want && $slug === $want;
					},
				);
			}

			foreach ( $taxonomies as $tax ) {
				$defs[ "content_is_{$pt}_with_{$tax}" ] = array(
					/* translators: 1: post type name, 2: taxonomy name */
					'label'    => sprintf( __( '%1$s with %2$s (term IDs)', 'digitizer-pro-tools' ), $label, self::tax_label( $tax ) ),
					'category' => $label,
					'option'   => 'ids',
					'callback' => static function ( $o, $ctx ) use ( $pt, $tax ) {
						$ids = self::id_list( $o );
						if ( ! $ids ) {
							// has_term( array(), ... ) means "has ANY term" -
							// a blank IDs field must match nothing instead of
							// restricting every tagged post. (Codex round-4 P1)
							return false;
						}
						return ! empty( $ctx['post'] ) && $ctx['post']->post_type === $pt
							&& has_term( $ids, $tax, $ctx['post'] );
					},
				);
			}
		}

		foreach ( $taxonomies as $tax ) {
			$tlabel = self::tax_label( $tax );
			/* translators: %s: taxonomy name */
			$tcat = sprintf( __( 'Taxonomy: %s', 'digitizer-pro-tools' ), $tlabel );

			$defs[ "content_is_{$tax}_archive" ] = array(
				/* translators: %s: taxonomy name */
				'label'    => sprintf( __( 'Any %s archive', 'digitizer-pro-tools' ), $tlabel ),
				'category' => $tcat,
				'option'   => '',
				'callback' => static function ( $o, $ctx ) use ( $tax ) {
					if ( ! empty( $ctx['term'] ) && $ctx['term']->taxonomy === $tax ) {
						return true;
					}
					return isset( $ctx['main']['is_tax'] ) && in_array( $tax, (array) $ctx['main']['is_tax'], true );
				},
			);
			$defs[ "content_is_selected_tax_{$tax}" ] = array(
				/* translators: %s: taxonomy name */
				'label'    => sprintf( __( 'Selected %s (term IDs)', 'digitizer-pro-tools' ), $tlabel ),
				'category' => $tcat,
				'option'   => 'ids',
				'callback' => static function ( $o, $ctx ) use ( $tax ) {
					return ! empty( $ctx['term'] ) && $ctx['term']->taxonomy === $tax
						&& in_array( (int) $ctx['term']->term_id, self::id_list( $o ), true );
				},
			);
			if ( is_taxonomy_hierarchical( $tax ) ) {
				$defs[ "content_is_child_of_tax_{$tax}" ] = array(
					/* translators: %s: taxonomy name */
					'label'    => sprintf( __( 'Child of %s (term IDs)', 'digitizer-pro-tools' ), $tlabel ),
					'category' => $tcat,
					'option'   => 'ids',
					'callback' => static function ( $o, $ctx ) use ( $tax ) {
						if ( empty( $ctx['term'] ) || $ctx['term']->taxonomy !== $tax ) {
							return false;
						}
						return (bool) array_intersect(
							self::id_list( $o ),
							array_map( 'intval', (array) get_ancestors( (int) $ctx['term']->term_id, $tax ) )
						);
					},
				);
			}
		}

		/**
		 * Extra or replacement rules. Each entry:
		 * name => {label, category, option, callback($options, $context)}.
		 */
		self::$definitions = (array) apply_filters( 'dpt_cc_rules', $defs );
		return self::$definitions;
	}

	/** Forget the built registry (tests, or after registering new types). */
	public static function flush_definitions() {
		self::$definitions = null;
	}
}
