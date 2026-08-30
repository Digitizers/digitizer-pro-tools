<?php
/**
 * REST Bridge - putting the discovered fields on the REST API.
 *
 * Discovery says what exists; this says it out loud to the API, under the
 * names JetEngine actually uses. A small compatibility layer keeps the names
 * the plugin this module replaces had invented, because automations were
 * written against those and an upgrade is not the moment to break them.
 *
 * Capabilities are checked here, and have to be. Core's post and term
 * controllers establish that the request may edit the *object* before an
 * update callback runs; they do not apply the **per-key** metadata
 * capability to a field registered with register_rest_field(). So a key the
 * site has put an auth_post_meta_* or auth_term_meta_* filter on, or one
 * WordPress protects outright, was readable and writable through this module
 * by anyone who could edit the containing post or term - which WordPress's
 * own meta endpoints refuse. Both callbacks ask map_meta_cap() the question
 * it exists to answer rather than deriving one of their own.
 *
 * @package Digitizer_Pro_Tools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Registers REST fields for discovered and legacy meta.
 */
class DPT_RB_Fields {

	/**
	 * object/target => field name => the schema it was registered with, for
	 * the info endpoint.
	 *
	 * @var array
	 */
	private static $registered = array();

	/**
	 * Names the compatibility layer added.
	 *
	 * @var array
	 */
	private static $compat = array();

	/**
	 * Diagnostics for the info endpoint: compatibility names that were not
	 * added, and why. A field that silently fails to appear looks like a
	 * bug in the API rather than a deliberate choice.
	 *
	 * @var array
	 */
	private static $skipped = array();

	/**
	 * Whether this run is a rehearsal.
	 *
	 * A rehearsal answers one question: what would this module put on the API
	 * if it were switched on here. It is asked while the module is off, or
	 * while it is standing down for the plugin it replaces - which is the
	 * only moment the answer is worth anything, because it is the moment
	 * before somebody deactivates a working plugin on a client's site.
	 *
	 * The flag suppresses exactly one call, register_rest_field(), and
	 * nothing else. Every discovery, every collision check, every refusal and
	 * every diagnostic runs as it always does. A rehearsal that took a
	 * shortcut would describe a path the real run does not take, which is
	 * worse than showing nothing at all.
	 *
	 * @var bool
	 */
	private static $dry = false;

	/**
	 * The property names core's own controllers already define on a REST
	 * response, by object kind.
	 *
	 * register_rest_field() does not add a property beside an existing one.
	 * For a name the target controller already defines it *replaces* that
	 * property's schema and its response value, so a JetEngine field called
	 * title would hand /wp/v2/posts/{id} a meta string where core's title
	 * object belongs, let this module's edit-only context strip the real
	 * title out of every view response, and land one write in two places at
	 * once. That is not one field degraded - it is the site's REST API
	 * broken for every consumer of that post type, the block editor
	 * included. The plugin this module replaces knew it: it called the
	 * `title` meta key jet_faq_title rather than title for exactly this
	 * reason, which is also how we know a JetEngine field with that meta key
	 * exists on the sites this is for.
	 *
	 * A written list rather than a question put to the controller, which is
	 * the obvious alternative and is worse here on three counts. Core's
	 * answer is not stable while rest_api_init runs: add_additional_fields_
	 * schema() folds every field registered so far into the schema a
	 * controller hands back, so what counts as "core's own" depends on how
	 * far through the hook the question is asked - and this module's own
	 * registrations would come back as core's. Asking costs a controller
	 * instantiation and a full schema build per target, inside that hook,
	 * running whatever third-party code has filtered rest_{$type}_item_
	 * schema - re-entrancy nothing here can bound. And
	 * WP_Post_Type::get_rest_controller() is WP 5.9 and later, and answers
	 * null for a type whose rest_controller_class is not a
	 * WP_REST_Controller at all. Against that, the list is core's published
	 * API surface: renaming one of these would break /wp/v2 for everyone,
	 * which is not a change core makes.
	 *
	 * What belongs here is only what a target's *own* controller defines.
	 * One list wide enough to cover every controller at once is a rename
	 * list for names the target never owned, and a needless rename is the
	 * one thing this module exists not to do: a JetEngine field called
	 * `description` on `page` collides with nothing on /wp/v2/pages, so
	 * answering to jet_description there breaks the promise discovery is
	 * for - a site's fields under the names the site gave them - and an
	 * automation asking for `description` finds nothing. So the post list
	 * below is WP_REST_Posts_Controller's own surface, which is every post
	 * type's controller; what one named post type adds to it is in
	 * $reserved_post_type; and the taxonomy properties a post response
	 * carries are in neither, because reserved_for() reads those from the
	 * registry.
	 *
	 * Within that scope the list is the controller's surface rather than the
	 * schema one target would really build: most of these properties are
	 * switched on by a post type support, which any plugin may add after
	 * this list has been consulted. Over-reserving there costs one renamed
	 * field and says so in the diagnostics; under-reserving hands core's own
	 * property to a meta value and breaks the post type for every consumer.
	 * The gates that are exact rather than mutable - the ones core makes on
	 * the post type's *name* - are the ones split out below.
	 *
	 * @var array
	 */
	private static $reserved = array(
		// WP_REST_Posts_Controller: its base properties, the three a public
		// type adds, the parent of a hierarchical one, the ten a post type
		// support turns on, and the template every type carries. The rest
		// bases of the taxonomies attached to the target are deliberately
		// not listed - `categories` and `tags` are core's post type's, not
		// every post type's, and reserved_for() reads whichever ones the
		// target really has from the registry.
		'post'     => array(
			'id',
			'date',
			'date_gmt',
			'guid',
			'modified',
			'modified_gmt',
			'password',
			'slug',
			'status',
			'type',
			'link',
			'permalink_template',
			'generated_slug',
			'class_list',
			'parent',
			'title',
			'content',
			'author',
			'excerpt',
			'featured_media',
			'comment_status',
			'ping_status',
			'menu_order',
			'format',
			'meta',
			'template',
		),
		// WP_REST_Terms_Controller, which is the same nine properties on
		// every taxonomy there is.
		'taxonomy' => array(
			'id',
			'count',
			'description',
			'link',
			'name',
			'slug',
			'taxonomy',
			'parent',
			'meta',
		),
	);

	/**
	 * What a single named post type adds to the list above.
	 *
	 * Two of core's gates are on the post type's name rather than on a
	 * support or a registry flag, so they can be answered exactly rather
	 * than reserved everywhere and hoped for: WP_REST_Posts_Controller adds
	 * `sticky` only for `post`, and attachments are served by
	 * WP_REST_Attachments_Controller, which puts fifteen properties of its
	 * own on top of its parent's schema. Those fifteen reserved on every
	 * post type is what renamed an ordinary `description` field on `page` to
	 * jet_description - a collision with a controller that post type never
	 * uses.
	 *
	 * One inexactness is left, and it is the cheap way round: the
	 * attachments controller *unsets* `password`, so that one name stays
	 * reserved on a target that no longer owns it. A rename of a `password`
	 * field on the media library is a cost nobody will meet; handing core
	 * its own property back full of meta is not.
	 *
	 * @var array
	 */
	private static $reserved_post_type = array(
		'post'       => array( 'sticky' ),
		'attachment' => array(
			'alt_text',
			'caption',
			'description',
			'media_type',
			'mime_type',
			'media_details',
			'post',
			'source_url',
			'missing_image_sizes',
			'filename',
			'filesize',
			'exif_orientation',
			'image_quality',
			'image_output_format',
			'image_save_progressive',
		),
	);

	/**
	 * The reserved set per object/target, worked out once per request.
	 *
	 * @var array
	 */
	private static $reserved_cache = array();

	/**
	 * object/target => meta key => true, for every field the site's own
	 * JetEngine definitions claim.
	 *
	 * Frozen before anything is registered so that "the site defines this
	 * name here" is an order-independent question. Asking the live registry
	 * instead would answer differently depending on which of two definitions
	 * discovery happened to reach first.
	 *
	 * @var array
	 */
	private static $site_names = array();

	/**
	 * The names a colliding meta key is exposed under when it has a legacy
	 * one, rather than the general rule below.
	 *
	 * `title` on posts is the whole of it: the replaced plugin published
	 * that meta key as jet_faq_title, automations written against it use
	 * that name today, and an upgrade is not the moment to invent a second
	 * one for the same field.
	 *
	 * @var array
	 */
	private static $legacy_alias = array(
		'post/post/title' => 'jet_faq_title',
	);

	/**
	 * The legacy fields the replaced plugin promised, kept because
	 * automations use them and they may not be JetEngine fields at all.
	 *
	 * Every entry names a single target, so free_targets()' per-target
	 * decision has no multi-target entry to exercise on today's data.
	 *
	 * @return array
	 */
	private static function legacy() {
		return array(
			array(
				'meta_key' => 'reading_time',
				'title'    => 'Estimated reading time',
				'type'     => 'text',
				'fields'   => array(),
				'object'   => 'post',
				'targets'  => array( 'post' ),
			),
			// The FAQ section title. Its meta key really is `title`, which
			// is a property /wp/v2/posts already has, so it cannot be
			// exposed under its own name at all - and the replaced plugin
			// had already settled what it is called instead. Registering it
			// here rather than leaving the collision to drop it is what
			// stops a field the site defined from simply vanishing, and it
			// keeps the name every automation written against that plugin
			// already sends.
			array(
				'meta_key' => 'title',
				'expose'   => 'jet_faq_title',
				'title'    => 'JetEngine FAQ section title',
				'type'     => 'text',
				'fields'   => array(),
				'object'   => 'post',
				'targets'  => array( 'post' ),
			),
			array(
				'meta_key' => 'author_description',
				'title'    => 'Author bio description',
				'type'     => 'wysiwyg',
				'fields'   => array(),
				'object'   => 'taxonomy',
				'targets'  => array( 'authors' ),
			),
			// Both of these end up in an href or a src in a theme template,
			// and anyone who may edit a term on the authors taxonomy may
			// write them. url, not text: sanitize_text_field() would leave a
			// javascript: or data: URL intact, which is the treatment the
			// plugin this replaces was already careful to avoid.
			array(
				'meta_key' => 'author_image',
				'title'    => 'Author avatar image URL',
				'type'     => 'url',
				'fields'   => array(),
				'object'   => 'taxonomy',
				'targets'  => array( 'authors' ),
			),
			array(
				'meta_key' => 'linkedin',
				'title'    => 'Author LinkedIn URL',
				'type'     => 'url',
				'fields'   => array(),
				'object'   => 'taxonomy',
				'targets'  => array( 'authors' ),
			),
		);
	}

	/**
	 * Register everything. Called on rest_api_init.
	 */
	/**
	 * Run register() without registering anything.
	 *
	 * The flag is cleared in a finally, so a rehearsal that throws leaves the
	 * next real run to register normally rather than silently registering
	 * nothing - a failure mode that would look like the module being broken.
	 *
	 * What the rehearsal leaves behind in $registered, $compat and $skipped
	 * is the point: the caller reads it through the same reporting methods
	 * the info endpoint uses. It is not cleared afterwards because
	 * register() clears all of it on entry, so a real run later in the same
	 * request cannot inherit any of it.
	 *
	 * @return void
	 */
	public static function rehearse() {
		self::$dry = true;
		try {
			self::register();
		} finally {
			self::$dry = false;
		}
	}

	public static function register() {
		self::$registered     = array();
		self::$compat         = array();
		self::$skipped        = array();
		self::$reserved_cache = array();
		self::$site_names     = array();

		$discovered = DPT_RB_Definitions::all();

		// What the site itself claims, before a single field is registered.
		// A compatibility name - the jet_qna alias, a legacy field, the
		// alias a collision falls back on - never takes a name a definition
		// of the site's own already has, and asking the live registry for
		// that would make the answer depend on registration order.
		foreach ( $discovered as $descriptor ) {
			foreach ( $descriptor['targets'] as $target ) {
				self::$site_names[ $descriptor['object'] . '/' . $target ][ $descriptor['meta_key'] ] = true;
			}
		}

		foreach ( $discovered as $descriptor ) {
			// Explaining a target this site does not expose, because these
			// are the fields the site itself defined: one of its own meta
			// boxes producing nothing at an endpoint is exactly the gap the
			// info report exists to fill.
			self::register_one( $descriptor, $descriptor['meta_key'], true );
		}

		// What discovery landed, frozen before the compatibility layer adds
		// anything of its own. The alias below asks this rather than the
		// live registry so that "the site defines its own jet_qna here" and
		// "an earlier pass already aliased jet_qna here" stay two different
		// questions with two different answers.
		$owned = self::$registered;

		// The alias: one name the old plugin invented for a repeater whose
		// real name is qna. It writes the same meta key, so both names are
		// the same field seen twice rather than two fields to keep in step.
		// register_one() reports how many targets it actually landed on, so
		// compat() only hears about this when something really happened -
		// a taxonomy or post type the site does not expose to REST must not
		// be claimed as a compatibility field regardless.
		foreach ( $discovered as $descriptor ) {
			// jet_qna is a name on post types, where ContentEngine writes
			// it. A site whose qna repeater sits on a taxonomy gets no
			// alias: it would put the name somewhere no consumer looks for
			// it, and leave the post-side fallback below free to claim it a
			// second time.
			if ( 'post' !== $descriptor['object'] || 'qna' !== $descriptor['meta_key'] || 'repeater' !== $descriptor['type'] ) {
				continue;
			}
			// The alias lands on this descriptor's own targets, which are
			// not necessarily the post type called post - a site may have
			// defined its FAQ repeater on pages alone. Every such definition
			// is aliased on the targets it really has, and note_compat()
			// keeps the report a list of names rather than a tally, so a
			// second definition of the key cannot say jet_qna twice.
			//
			// Minus the targets where the site defines a field of its own
			// under that name: compatibility fills a gap, it never takes a
			// name that is already someone's.
			$aliased            = $descriptor;
			$aliased['targets'] = self::alias_targets( $descriptor, $owned );
			if ( ! $aliased['targets'] ) {
				continue;
			}
			if ( self::register_one( $aliased, 'jet_qna' ) > 0 ) {
				self::note_compat( 'jet_qna' );
			}
		}

		// And the fields the old plugin hard-coded. Each is decided per
		// target, not per descriptor: a name taken on one of a legacy
		// field's targets must not withhold it from another target that has
		// no collision at all.
		foreach ( self::legacy() as $descriptor ) {
			// The name it is exposed under is not always its meta key: the
			// FAQ title's key is one /wp/v2/posts already owns, so the
			// replaced plugin's name for it is the only one it can have.
			$name                  = self::legacy_name( $descriptor );
			$descriptor['targets'] = self::free_targets( $descriptor, $name );
			if ( ! $descriptor['targets'] ) {
				continue;
			}
			if ( self::register_one( $descriptor, $name ) > 0 ) {
				self::note_compat( $name );
			}
		}

		// jet_qna is not a name collision to check for like the legacy list
		// above - it is a meta key collision. Whatever already owns the qna
		// meta key on posts, discovered or not, decides what jet_qna means
		// or whether it should exist at all.
		self::register_qna_fallback( $discovered );
	}

	/**
	 * jet_qna exists only to keep ContentEngine's writes landing on the qna
	 * meta key of /wp/v2/posts. That is safe when nothing else already gives
	 * that key a shape *on posts*: a discovered repeater whose targets
	 * include post was already aliased by the loop above, and any other
	 * discovered type would have this fallback's repeater sanitizer
	 * overwrite data a real field understands as something else - scalar
	 * text turned into a list, or worse.
	 *
	 * The whole decision turns on the post *target*, never on the post
	 * object kind. A descriptor carries object 'post' for any post type at
	 * all, pages included, so a site whose FAQ repeater is defined only on
	 * pages used to be read as the owner of the post FAQ: the alias landed
	 * on page, this fallback stood down for an owner that was never on
	 * posts, and /wp/v2/posts/{id} lost jet_qna altogether - the one field
	 * ContentEngine's pipeline gates on.
	 *
	 * @param array $discovered Descriptors from DPT_RB_Definitions::all().
	 */
	private static function register_qna_fallback( $discovered ) {
		if ( self::name_taken( 'post', 'post', 'jet_qna' ) ) {
			// Something discovered is already registered under this exact
			// name on posts; overriding it is not this fallback's business.
			return;
		}

		$owner = null;
		foreach ( $discovered as $descriptor ) {
			if ( 'post' === $descriptor['object'] && 'qna' === $descriptor['meta_key'] && in_array( 'post', $descriptor['targets'], true ) ) {
				$owner = $descriptor;
				break;
			}
		}

		if ( null !== $owner ) {
			if ( 'repeater' !== $owner['type'] ) {
				// The site's own qna field on posts means something else.
				// Recording why keeps an automation from finding the absence
				// as a bare 404 with nothing to explain it.
				//
				// English, untranslated, like every other line in this list:
				// they are merged into one payload the info endpoint hands
				// to agents, and one language throughout is what makes that
				// list readable. It is not interface copy.
				self::$skipped[] = sprintf(
					'jet_qna was not registered because the site\'s own qna field is a %s field, not a repeater.',
					$owner['type']
				);
			}
			// A repeater owner on posts was already aliased by the loop
			// above; either way, the fallback has nothing left to add.
			return;
		}

		// Nothing owns the qna meta key on posts - whether because the site
		// has no qna field at all, or because the one it has lives on other
		// post types entirely. Either way ContentEngine's writes have
		// nowhere else to land, so the legacy shape is registered on post.
		if ( self::register_one( self::fallback_qna(), 'jet_qna' ) > 0 ) {
			self::note_compat( 'jet_qna' );
		}
	}

	/**
	 * Record a name the compatibility layer added, once.
	 *
	 * compat() is a list of names an agent reads out of the info endpoint,
	 * not a tally: the per-target detail is in registered(), which says
	 * exactly which post types and taxonomies each name landed on. A name
	 * can legitimately be added by two passes - jet_qna aliased onto a
	 * site's own page-only FAQ repeater, and the legacy shape registered on
	 * post - and saying it twice there would read as a bug rather than as
	 * the two places it really is.
	 *
	 * @param string $name The name that was registered.
	 */
	private static function note_compat( $name ) {
		if ( ! in_array( $name, self::$compat, true ) ) {
			self::$compat[] = $name;
		}
	}

	/**
	 * The FAQ repeater as the replaced plugin defined it, for a site whose
	 * JetEngine definitions this module cannot see.
	 *
	 * @return array
	 */
	private static function fallback_qna() {
		return array(
			'meta_key' => 'qna',
			'title'    => 'FAQ (question and answer pairs)',
			'type'     => 'repeater',
			'fields'   => array(
				array( 'meta_key' => 'question', 'title' => 'Question', 'type' => 'text', 'fields' => array() ),
				array( 'meta_key' => 'answer', 'title' => 'Answer', 'type' => 'wysiwyg', 'fields' => array() ),
			),
			'object'   => 'post',
			'targets'  => array( 'post' ),
		);
	}

	/**
	 * Which of a qna repeater's targets may carry the jet_qna alias.
	 *
	 * A post type can define both a qna repeater and a field of its own
	 * literally named jet_qna. Discovery registers the real jet_qna first,
	 * and the alias used to be registered straight over it - callbacks and
	 * schema replaced by ones pointing at the qna meta key, so reads and
	 * writes under jet_qna operated on the wrong metadata and the site's own
	 * field became unreachable through the API entirely. Same family as the
	 * legacy list's collision check above, and the same answer: the site's
	 * own definition wins.
	 *
	 * The skip is recorded rather than silent. An automation that expects
	 * jet_qna to mean the FAQ has to be able to learn that on this site it
	 * does not, and the info endpoint is where it looks.
	 *
	 * @param array $descriptor Discovered qna repeater.
	 * @param array $owned      What discovery registered, as it stood before
	 *                          the compatibility layer ran.
	 * @return array
	 */
	private static function alias_targets( $descriptor, $owned ) {
		$free = array();
		foreach ( $descriptor['targets'] as $target ) {
			$key = $descriptor['object'] . '/' . $target;
			if ( isset( $owned[ $key ]['jet_qna'] ) ) {
				// English, untranslated, like every other line in this list -
				// see register_qna_fallback() for why.
				$reason = sprintf(
					'The jet_qna alias for the qna repeater was not registered on %s because the site defines its own jet_qna field there.',
					$target
				);
				// Two qna definitions on one target would otherwise say the
				// same sentence twice, which reads as two problems.
				self::note_skip( $reason );
				continue;
			}
			$free[] = $target;
		}
		return $free;
	}

	/**
	 * Which of a legacy descriptor's targets do not already have a
	 * discovered field registered under that name. Decided per target
	 * rather than per descriptor: a collision on one of a legacy field's
	 * targets must not withhold it from another target that has none.
	 *
	 * @param array $descriptor Legacy descriptor.
	 * @return array
	 */
	private static function free_targets( $descriptor, $name ) {
		$free = array();
		foreach ( $descriptor['targets'] as $target ) {
			if ( ! self::name_taken( $descriptor['object'], $target, $name ) ) {
				$free[] = $target;
				continue;
			}
			// Said out loud, the way the jet_qna alias says it. A consumer
			// that has been reading a legacy name since the replaced plugin
			// has to be able to learn that on this site the name is the
			// site's own field rather than the one it remembers.
			//
			// English, untranslated, like every other line in this list -
			// see register_qna_fallback() for why.
			self::note_skip(
				sprintf(
					'The legacy field %1$s was not registered on %2$s because the site defines a field of its own under that name.',
					$name,
					$target
				)
			);
		}
		return $free;
	}

	/**
	 * The name a legacy descriptor is exposed under.
	 *
	 * Usually its meta key, and deliberately not always: the FAQ title's key
	 * is `title`, which /wp/v2/posts already owns, so the only name it can
	 * have is the one the replaced plugin gave it.
	 *
	 * @param array $descriptor Legacy descriptor.
	 * @return string
	 */
	private static function legacy_name( $descriptor ) {
		return isset( $descriptor['expose'] ) ? $descriptor['expose'] : $descriptor['meta_key'];
	}

	/**
	 * The property names already spoken for on one target: what every
	 * controller of that kind defines, what this one target's controller
	 * adds, and whatever this site's taxonomy registry adds on top.
	 *
	 * Three sources because the answer is genuinely three answers, and a
	 * name reserved on the wrong target is a field renamed for nothing.
	 * `sticky` and the attachment properties belong to one post type each;
	 * a post type's controller turns every REST-enabled taxonomy attached to
	 * it into a property of its own, named by that taxonomy's rest base -
	 * categories and tags on core's post, and whatever a site has called its
	 * own. No written list can know the last of those, so they are asked
	 * for: it is one read of the in-memory taxonomy registry per target,
	 * with no query behind it, and the answer is kept for the rest of the
	 * request. Terms have no equivalent of either -
	 * WP_REST_Terms_Controller's schema is the same nine properties on every
	 * taxonomy.
	 *
	 * @param string $object Object kind.
	 * @param string $target Post type or taxonomy name.
	 * @return array Name => true.
	 */
	private static function reserved_for( $object, $target ) {
		$key = $object . '/' . $target;
		if ( isset( self::$reserved_cache[ $key ] ) ) {
			return self::$reserved_cache[ $key ];
		}

		$names = isset( self::$reserved[ $object ] ) ? self::$reserved[ $object ] : array();

		if ( 'post' === $object ) {
			if ( isset( self::$reserved_post_type[ $target ] ) ) {
				$names = array_merge( $names, self::$reserved_post_type[ $target ] );
			}

			$taxonomies = get_object_taxonomies( $target, 'objects' );
			if ( is_array( $taxonomies ) ) {
				foreach ( $taxonomies as $taxonomy ) {
					if ( empty( $taxonomy->show_in_rest ) ) {
						continue;
					}
					$names[] = empty( $taxonomy->rest_base ) ? $taxonomy->name : $taxonomy->rest_base;
				}
			}
		}

		self::$reserved_cache[ $key ] = array_fill_keys( $names, true );
		return self::$reserved_cache[ $key ];
	}

	/**
	 * Whether the site's own JetEngine definitions claim a name on a target.
	 *
	 * @param string $object Object kind.
	 * @param string $target Post type or taxonomy.
	 * @param string $name   Field name.
	 * @return bool
	 */
	private static function site_defines( $object, $target, $name ) {
		return isset( self::$site_names[ $object . '/' . $target ][ $name ] );
	}

	/**
	 * The name this field may actually be registered under on one target, or
	 * an empty string when there is none.
	 *
	 * A name core's controller already defines is never taken: register_rest_
	 * field() would replace that property's schema and its value rather than
	 * sit beside it, which breaks the whole post type for every consumer
	 * rather than one field for one caller. But a field the site defined is
	 * not this module's to lose either, so a collision is renamed rather than
	 * dropped, by a rule a consumer can predict:
	 *
	 * - a triple with a legacy name gets that name (`title` on posts is
	 *   jet_faq_title, which is what the replaced plugin published);
	 * - anything else gets its own name prefixed with `jet_`, the same
	 *   prefix the legacy names use, and no core property begins with it.
	 *
	 * Either way the absence of the real name is recorded, because an
	 * automation that asked for it and got nothing needs the info endpoint to
	 * be able to say why. If the alias is not free either - reserved as well,
	 * defined by the site, or already registered - the field is left off
	 * entirely rather than laid over somebody else's name, and that is
	 * recorded too.
	 *
	 * @param array  $descriptor Field descriptor.
	 * @param string $target     Post type or taxonomy it lands on.
	 * @param string $name       The name it asked for.
	 * @return string
	 */
	private static function expose_name( $descriptor, $target, $name ) {
		$object   = isset( $descriptor['object'] ) ? $descriptor['object'] : '';
		$reserved = self::reserved_for( $object, $target );
		if ( ! isset( $reserved[ $name ] ) ) {
			return $name;
		}

		$triple = $object . '/' . $target . '/' . $name;
		$alias  = isset( self::$legacy_alias[ $triple ] ) ? self::$legacy_alias[ $triple ] : 'jet_' . $name;

		if ( isset( $reserved[ $alias ] ) || self::site_defines( $object, $target, $alias ) || self::name_taken( $object, $target, $alias ) ) {
			self::note_skip(
				sprintf(
					'The field %1$s was not registered on %2$s: %1$s is a property the WordPress REST API already defines there, and the alias %3$s is not free either.',
					$name,
					$target,
					$alias
				)
			);
			return '';
		}

		self::note_skip(
			sprintf(
				'The field %1$s was not registered on %2$s under its own name because %1$s is a property the WordPress REST API already defines there; it is exposed as %3$s instead.',
				$name,
				$target,
				$alias
			)
		);

		return $alias;
	}

	/**
	 * Record one diagnostic, once.
	 *
	 * Two definitions can produce the same sentence about the same target,
	 * and saying it twice reads as two problems rather than one.
	 *
	 * @param string $reason Plain English, untranslated - see
	 *                       register_qna_fallback() for why.
	 */
	private static function note_skip( $reason ) {
		if ( ! in_array( $reason, self::$skipped, true ) ) {
			self::$skipped[] = $reason;
		}
	}

	/**
	 * Whether a name is already registered on one target.
	 *
	 * @param string $object Object kind.
	 * @param string $target Post type or taxonomy.
	 * @param string $name   Field name.
	 * @return bool
	 */
	private static function name_taken( $object, $target, $name ) {
		$key = $object . '/' . $target;
		return isset( self::$registered[ $key ][ $name ] );
	}

	/**
	 * Register one descriptor under one name, on every target that the site
	 * actually exposes to the REST API.
	 *
	 * @param array  $descriptor Field descriptor.
	 * @param string $name       The name to expose it under.
	 * @param bool   $explain    Whether a target this site does not expose
	 *                           to REST is worth a diagnostic. True for the
	 *                           fields the site itself defined, where an
	 *                           absence is a surprise; false for the
	 *                           compatibility layer, which offers names on
	 *                           targets most sites simply do not have and
	 *                           would otherwise fill the report with the
	 *                           absence of an `authors` taxonomy nobody
	 *                           asked for.
	 * @return int How many targets it actually landed on. A caller that
	 *             reports this name as a compatibility field has to know
	 *             this was not zero, or the report is a lie.
	 */
	private static function register_one( $descriptor, $name, $explain = false ) {
		$count = 0;

		// Discovery hands over the meta key JetEngine stored, exactly as it
		// stored it, because that is the key the site's own templates and
		// JetEngine itself read. Which of those keys WordPress will actually
		// carry is a different question, and it is asked here: discovery
		// describes what JetEngine defines - which the info endpoint reports -
		// rather than what this module may expose, and the object type the
		// answer depends on is in hand here and not there.
		$refusal = self::key_refusal( $descriptor );
		if ( '' !== $refusal ) {
			// English, untranslated, like every other line in this list -
			// see register_qna_fallback() for why.
			self::note_skip( $refusal );
			return 0;
		}

		foreach ( $descriptor['targets'] as $target ) {
			if ( ! self::exposed( $descriptor['object'], $target ) ) {
				if ( $explain ) {
					// English, untranslated, like every other line in this
					// list - see register_qna_fallback() for why.
					self::note_skip( self::exposure_refusal( $descriptor['object'], $target, $name ) );
				}
				continue;
			}

			// What this field may honestly be called here. Not always the
			// name it asked for: core's own controller properties are not
			// this module's to overwrite, and a field whose name is one of
			// them is renamed rather than lost.
			$expose = self::expose_name( $descriptor, $target, $name );
			if ( '' === $expose ) {
				continue;
			}

			// The descriptor as this module treats it here, which is not
			// always the descriptor discovery handed over: a handful of
			// object/target/meta-key triples are URLs whatever type the
			// site's own definition gives them, because a theme prints them
			// into an href. Resolved once, into a new variable the schema and
			// both callbacks then share, so the advertised type, the write
			// sanitizer and the read shaping cannot disagree - and the loop's
			// next target still starts from the descriptor it was given.
			$resolved = DPT_RB_Schema::resolve_descriptor( $descriptor, $target );

			// Built per target rather than once for the descriptor: the read
			// context is a per-target answer, because a legacy name is only
			// public on the target the replaced plugin published it on.
			$schema = DPT_RB_Schema::for_descriptor( $resolved, $target );
			/**
			 * Filters the REST contexts one field may be read in.
			 *
			 * Discovered fields default to edit only, because this module
			 * cannot tell a public field from an internal one and the safe
			 * default is the one that cannot leak a site's data. A site that
			 * knows better - a field it wants on an anonymous GET - adds
			 * 'view' here.
			 *
			 * @param array  $context    Context names, e.g. array( 'edit' ).
			 * @param array  $descriptor The field descriptor being registered.
			 * @param string $target     Post type or taxonomy it lands on.
			 */
			$schema['context'] = array_values( (array) apply_filters( 'dpt_rb_field_context', $schema['context'], $resolved, $target ) );

			// Whether the read is gated, decided by the context this field
			// was really registered with rather than by a list written here.
			// A field readable in the view context is one this site
			// publishes to anyone - the legacy keys the replaced plugin
			// published, and anything a site has opted in with the filter
			// above - so gating its read would un-publish it for the
			// anonymous callers it exists for. Everything else is edit-only,
			// and its read is gated by the same per-key capability its write
			// is. Carried on the descriptor because that is what the
			// callbacks below are handed.
			$resolved['public_read'] = in_array( 'view', $schema['context'], true );

			// The one call a rehearsal does not make. Everything above it has
			// already run, so what is recorded below is what a real run would
			// have recorded.
			if ( ! self::$dry ) {
				register_rest_field(
					self::rest_field_type( $descriptor['object'], $target ),
					$expose,
					array(
						'get_callback'    => function ( $object ) use ( $resolved ) {
							return DPT_RB_Fields::read( $resolved, $object );
						},
						'update_callback' => function ( $value, $object ) use ( $resolved ) {
							return DPT_RB_Fields::write( $resolved, $value, $object );
						},
						'schema'          => $schema,
					)
				);
			}

			$key = $descriptor['object'] . '/' . $target;
			if ( ! isset( self::$registered[ $key ] ) ) {
				self::$registered[ $key ] = array();
			}
			// Keyed by name and holding the schema, because the info endpoint
			// promises an agent the schemas and must read them from what was
			// really registered rather than deriving them a second time.
			self::$registered[ $key ][ $expose ] = $schema;
			if ( $expose !== $name ) {
				// A renamed field answers to a name its own definition never
				// gave it, so the name is owed to this module's aliasing
				// rule rather than to the site - which is exactly what
				// compat() lists.
				self::note_compat( $expose );
			}
			$count++;
		}

		return $count;
	}

	/**
	 * The object type register_rest_field() has to be handed for one target.
	 *
	 * It is not always the target's own name, and the difference is not
	 * cosmetic: a field registered under a name no controller looks up is a
	 * field that never fires. `register_rest_field()` files what it is given
	 * in `$wp_rest_additional_fields` under that string, and a controller
	 * looks its own fields up by `WP_REST_Controller::get_object_type()`,
	 * which is `$schema['title']` - so the title a controller gives itself is
	 * the whole of the answer.
	 *
	 * `WP_REST_Posts_Controller::get_item_schema()` titles itself
	 * `$this->post_type`, so every post type is its own name.
	 * `WP_REST_Terms_Controller::get_item_schema()` titles itself
	 * `'post_tag' === $this->taxonomy ? 'tag' : $this->taxonomy`, so a
	 * taxonomy is its own name too - except `post_tag`, which is `tag`. Core
	 * writes that same rule out a second time, for this exact purpose, in
	 * `WP_REST_Term_Meta_Fields::get_rest_field_type()`, whose docblock points
	 * at `register_rest_field()`; `WP_REST_Post_Meta_Fields` returns the post
	 * type unchanged beside it. There is only the one remapping in core, so
	 * `category` is `category` - **not** `categories`, its REST base, which is
	 * the tempting wrong answer for a name whose plural differs.
	 *
	 * Registered under `post_tag`, a discovered field on the tag taxonomy was
	 * never read and never written, while registered() still reported it - the
	 * info endpoint claiming a field that does not exist, which is the one
	 * thing that endpoint exists not to do. The bookkeeping below stays keyed
	 * by the taxonomy's own name, because that is the target the site named
	 * and the identity an operator reads the report by; only the string core
	 * is handed changes.
	 *
	 * @param string $object Object kind.
	 * @param string $target Post type or taxonomy name.
	 * @return string
	 */
	private static function rest_field_type( $object, $target ) {
		if ( 'taxonomy' !== $object ) {
			return $target;
		}

		return 'post_tag' === $target ? 'tag' : $target;
	}

	/**
	 * Why WordPress will not carry this meta key, or an empty string when it
	 * will.
	 *
	 * The key is JetEngine's, verbatim, and almost anything is a meta key as
	 * far as WordPress is concerned: update_metadata() puts it in a text
	 * column with no character rule of any kind, which is why this module
	 * stopped deriving one of its own. Three things are genuinely refused,
	 * and each is refused for its own reason - a diagnostic that names the
	 * wrong one sends whoever reads it looking for a problem the site does
	 * not have.
	 *
	 * - A key WordPress protects. map_meta_cap() refuses the per-key meta
	 *   capability for one to every user there is, administrators included,
	 *   so a field on it could only ever read empty and refuse every write.
	 *   Asked of core rather than tested for a leading underscore, because
	 *   core strips everything outside printable ASCII and the Unicode
	 *   letters before it looks - so a name written in Hebrew ahead of an
	 *   underscore really is protected - and because is_protected_meta() is
	 *   filterable and a plugin may protect keys of its own.
	 * - The key "0". update_metadata() opens with `! $meta_key`, and PHP
	 *   reads that string as empty, so no write can ever land.
	 * - A key longer than the column that has to hold it. meta_key is
	 *   varchar(255), so a longer key is a different key by the time it
	 *   reaches storage - the same silent substitution this module stopped
	 *   making itself. Counted in characters, which is what that column
	 *   counts on a utf8mb4 table, and not in bytes.
	 *
	 * The per-user half of the protection question - the auth_*_meta_*
	 * filters a site installs - cannot be answered at registration at all and
	 * stays where it belongs, in the callbacks.
	 *
	 * @param array $descriptor Field descriptor.
	 * @return string Plain English, untranslated - see
	 *                register_qna_fallback() for why. Empty when the key is
	 *                usable.
	 */
	private static function key_refusal( $descriptor ) {
		$key       = $descriptor['meta_key'];
		$meta_type = ( isset( $descriptor['object'] ) && 'taxonomy' === $descriptor['object'] ) ? 'term' : 'post';

		if ( is_protected_meta( $key, $meta_type ) ) {
			return sprintf(
				'The field %s was not registered because WordPress treats that meta key as protected: no user at all, administrators included, may read or write one through the REST API.',
				$key
			);
		}

		if ( '' === $key || '0' === $key ) {
			return sprintf(
				'The field %s was not registered because WordPress cannot store a meta key that PHP reads as empty: update_metadata() refuses one before it looks at anything else.',
				'' === $key ? '(unnamed)' : $key
			);
		}

		// Characters, not bytes. meta_key is varchar(255) in a utf8mb4 table,
		// which counts characters - so a 200-character Hebrew key is 400
		// bytes and fits perfectly well, and counting bytes here would refuse
		// a field for a limit WordPress does not have. That is the same
		// mistake in a different place, on the same sites.
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $key, 'UTF-8' ) : strlen( $key );
		if ( $length > 255 ) {
			return sprintf(
				'The field %1$s was not registered because its meta key is %2$d characters long and WordPress stores meta keys in a column of 255: a longer key is written and read as a different key.',
				$key,
				$length
			);
		}

		return '';
	}

	/**
	 * Whether a post type or taxonomy is on the REST API at all. Registering
	 * a field on something invisible would only be a lie in the info report.
	 *
	 * @param string $object Object kind.
	 * @param string $target Post type or taxonomy name.
	 * @return bool
	 */
	private static function exposed( $object, $target ) {
		if ( 'taxonomy' === $object ) {
			$tax = get_taxonomy( $target );
			return $tax && ! empty( $tax->show_in_rest );
		}
		$type = get_post_type_object( $target );
		return $type && ! empty( $type->show_in_rest );
	}

	/**
	 * Why a field the site defined did not land on one of the targets its
	 * meta box names.
	 *
	 * Two different answers, and telling them apart is the point. A target
	 * that is not in the registry at all is usually a plugin that is not
	 * active on this site, or a name that no longer matches - which is what
	 * running sanitize_key() over a taxonomy name used to cause here, since
	 * register_taxonomy() keys the registry by the name it was given and
	 * never lowercases it. A target that is registered but has show_in_rest
	 * off has made a deliberate choice this module is not going to argue
	 * with. Either way the field vanishes from the API, and until this the
	 * loop above simply moved on: a site looking for its own field found
	 * nothing at the endpoint and nothing in the diagnostics either.
	 *
	 * @param string $object Object kind.
	 * @param string $target Post type or taxonomy name.
	 * @param string $name   The name the field asked for.
	 * @return string Plain English, untranslated.
	 */
	private static function exposure_refusal( $object, $target, $name ) {
		$kind   = 'taxonomy' === $object ? 'taxonomy' : 'post type';
		$exists = 'taxonomy' === $object ? (bool) get_taxonomy( $target ) : (bool) get_post_type_object( $target );

		if ( ! $exists ) {
			return sprintf(
				'The field %1$s was not registered on the %2$s %3$s because no %2$s of that name is registered on this site.',
				$name,
				$kind,
				$target
			);
		}

		return sprintf(
			'The field %1$s was not registered on the %2$s %3$s because that %2$s is not on the REST API: it was registered with show_in_rest off.',
			$name,
			$kind,
			$target
		);
	}

	/**
	 * What was registered where: object/target => name => schema.
	 *
	 * @return array
	 */
	public static function registered() {
		return self::$registered;
	}

	/**
	 * Names owed to the compatibility layer rather than to a definition -
	 * the aliases, the legacy fields, and any name a collision with a core
	 * REST property made this module invent.
	 *
	 * @return array
	 */
	public static function compat() {
		return self::$compat;
	}

	/**
	 * Why a compatibility name was not added, for a site where that would
	 * otherwise look like an unexplained gap.
	 *
	 * @return array
	 */
	public static function skipped() {
		return self::$skipped;
	}

	/**
	 * The id of the object a REST callback was handed. Core passes an array
	 * for a read and an object for a write, and terms and posts name their
	 * id differently.
	 *
	 * @param mixed $object Post or term, as array or object.
	 * @return int
	 */
	private static function object_id( $object ) {
		if ( is_array( $object ) ) {
			if ( isset( $object['id'] ) ) {
				return (int) $object['id'];
			}
			return isset( $object['term_id'] ) ? (int) $object['term_id'] : 0;
		}
		if ( is_object( $object ) ) {
			if ( isset( $object->ID ) ) {
				return (int) $object->ID;
			}
			return isset( $object->term_id ) ? (int) $object->term_id : 0;
		}
		return 0;
	}

	/**
	 * Whether this request may read one field's value.
	 *
	 * A field registered in the view context is one this site publishes to
	 * anyone at all: the keys the replaced plugin published anonymously, and
	 * anything a site has opted in with the dpt_rb_field_context filter.
	 * Gating those would silently un-publish them, which is the regression a
	 * capability check run with no user would otherwise cause. Everything
	 * else is edit-only, so its read is the same question as its write.
	 *
	 * @param array $descriptor Field descriptor.
	 * @param int   $id         Post or term id.
	 * @return bool
	 */
	private static function may_read( $descriptor, $id ) {
		if ( ! empty( $descriptor['public_read'] ) ) {
			return true;
		}
		return self::may_edit_meta( $descriptor, $id );
	}

	/**
	 * Whether this request may edit one meta key on one object.
	 *
	 * The capability WordPress itself uses for metadata, asked of
	 * map_meta_cap() rather than re-derived: it resolves the containing
	 * object's own edit capability, then refuses a protected key outright,
	 * then applies the site's auth_{$type}_meta_{$key} filter. Only the
	 * first of those three has been settled by the controller before a field
	 * callback runs.
	 *
	 * @param array $descriptor Field descriptor.
	 * @param int   $id         Post or term id.
	 * @return bool
	 */
	private static function may_edit_meta( $descriptor, $id ) {
		$cap = ( isset( $descriptor['object'] ) && 'taxonomy' === $descriptor['object'] )
			? 'edit_term_meta'
			: 'edit_post_meta';

		return current_user_can( $cap, $id, $descriptor['meta_key'] );
	}

	/**
	 * Read a field.
	 *
	 * @param array $descriptor Field descriptor.
	 * @param mixed $object     Post or term.
	 * @return mixed
	 */
	public static function read( $descriptor, $object ) {
		$id = self::object_id( $object );
		if ( ! $id ) {
			return DPT_RB_Schema::normalize_read( $descriptor, null );
		}

		// Not published, and not this reader's to see. The schema-honest
		// empty for the field's own type rather than a bare '' - a number
		// reads back 0, a repeater [] - because a refusal must look like a
		// field with nothing in it, not like a field of a different shape.
		if ( ! self::may_read( $descriptor, $id ) ) {
			return DPT_RB_Schema::normalize_read( $descriptor, null );
		}

		$stored = 'taxonomy' === $descriptor['object']
			? get_term_meta( $id, $descriptor['meta_key'], true )
			: get_post_meta( $id, $descriptor['meta_key'], true );

		return DPT_RB_Schema::normalize_read( $descriptor, $stored );
	}

	/**
	 * Write a field.
	 *
	 * @param array $descriptor Field descriptor.
	 * @param mixed $value      Incoming value.
	 * @param mixed $object     Post or term.
	 * @return true|WP_Error
	 */
	public static function write( $descriptor, $value, $object ) {
		$id = self::object_id( $object );
		if ( ! $id ) {
			return new WP_Error(
				'dpt_rb_no_object',
				__( 'The object to update could not be identified.', 'digitizer-pro-tools' ),
				array( 'status' => 400 )
			);
		}

		// Before the value is even looked at: a write nobody is allowed to
		// make is not one worth sanitizing. rest_authorization_required_code()
		// is core's own answer to the difference this module has to keep -
		// 401 where there is no user to refuse, 403 where there is one and
		// they may not.
		if ( ! self::may_edit_meta( $descriptor, $id ) ) {
			return new WP_Error(
				'dpt_rb_cannot_edit_meta',
				sprintf(
					/* translators: %s: field name */
					__( 'You are not allowed to edit the field %s.', 'digitizer-pro-tools' ),
					$descriptor['meta_key']
				),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$clean = DPT_RB_Schema::sanitize( $descriptor, $value );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$is_tax = 'taxonomy' === $descriptor['object'];
		$key    = $descriptor['meta_key'];

		// An empty repeater means "clear this", which is a delete rather than
		// a write of nothing.
		if ( 'repeater' === $descriptor['type'] && array() === $clean ) {
			$deleted = $is_tax ? delete_term_meta( $id, $key ) : delete_post_meta( $id, $key );
			if ( $deleted ) {
				return true;
			}
			// A delete also reports false when there was nothing there, which
			// is the outcome that was asked for.
			$still = $is_tax ? get_term_meta( $id, $key, true ) : get_post_meta( $id, $key, true );
			if ( '' === $still || array() === $still ) {
				return true;
			}
			return new WP_Error(
				'dpt_rb_not_cleared',
				sprintf(
					/* translators: %s: field name */
					__( 'The field %s could not be cleared.', 'digitizer-pro-tools' ),
					$key
				),
				array( 'status' => 500 )
			);
		}

		// Slashed, because update_metadata() unslashes whatever it is handed
		// before it stores it. Handed $clean directly, a value with a literal
		// backslash in it - a Windows path, a regular expression, an unknown
		// repeater column carrying either - reached storage with the
		// backslashes stripped out, and because a successful write returns
		// before the comparison below, the endpoint reported 200 over a value
		// it had quietly changed. A value this module cannot shape is not a
		// value it may destroy. The comparison below still reads storage
		// against $clean rather than against the slashed copy: storage holds
		// the unslashed side of that pair, so the two go on meaning the same
		// value.
		$slashed = wp_slash( $clean );
		$updated = $is_tax ? update_term_meta( $id, $key, $slashed ) : update_post_meta( $id, $key, $slashed );
		if ( false === $updated ) {
			// update_*_meta() returns false both for a refusal and for a
			// write that landed the value already stored; telling those
			// apart means reading storage back. Comparing the raw values
			// read back is not trustworthy either - meta storage round-trips
			// a scalar through a string, so a number field's sanitized int
			// 42 can come back as the string "42", a different PHP value for
			// the same field value. Running both sides through the same
			// read-side shaping asks the question this check actually
			// means: does storage now hold what was asked for.
			$stored = $is_tax ? get_term_meta( $id, $key, true ) : get_post_meta( $id, $key, true );
			// Compared as the API would send them rather than as PHP values:
			// read shaping hands a checkbox back as a fresh object, and two
			// separate objects are never identical however equal they read.
			// An encode that fails on either side answers nothing, so it is
			// treated as the failure it might be.
			$after = wp_json_encode( DPT_RB_Schema::normalize_read( $descriptor, $stored ) );
			$asked = wp_json_encode( DPT_RB_Schema::normalize_read( $descriptor, $clean ) );
			if ( false === $after || $after !== $asked ) {
				return new WP_Error(
					'dpt_rb_not_saved',
					sprintf(
						/* translators: %s: field name */
						__( 'The field %s could not be saved.', 'digitizer-pro-tools' ),
						$key
					),
					array( 'status' => 500 )
				);
			}
		}

		return true;
	}
}
