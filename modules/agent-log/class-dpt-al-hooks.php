<?php
/**
 * Agent Log module - the WordPress wiring.
 *
 * The only file here that hooks anything. What it decides lives in static
 * methods that take their inputs as arguments, so the decisions can be tested
 * without a request.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_AL_Hooks {

	/**
	 * Columns whose change says nothing. post_modified moves on every save by
	 * definition, so reporting it would put one meaningless name in every row.
	 */
	private static $ignored_columns = array( 'post_modified', 'post_modified_gmt' );

	/**
	 * @return void
	 */
	public static function init() {
		// Nothing at all is hooked for a read: the HTTP verb is knowable this
		// early, and a poll that changes nothing should not even listen.
		//
		// But only on a channel this request has not already named. Most
		// hosts disable WP-Cron and have a system cron fetch wp-cron.php,
		// which is a GET; WP-CLI has no HTTP verb at all. Skipping either for
		// looking like a read would leave a whole advertised channel with no
		// listener and no shutdown flush, so every change it made would go
		// unrecorded. See DPT_AL_Channel::is_early_channel().
		//
		// The channel is deliberately NOT checked here. init() is reached from
		// DPT_Plugin::boot() on 'plugins_loaded', but core defines REST_REQUEST
		// inside rest_api_loaded() (wp-includes/rest-api.php), which
		// default-filters.php hooks to 'parse_request' - long after
		// plugins_loaded. Gating here would read '' for every REST request and
		// register nothing, which is the module's main channel recording
		// nothing at all. The gate lives in flush() instead, on 'shutdown', by
		// which time the constant exists.
		if ( ! DPT_AL_Channel::is_early_channel() && DPT_AL_Channel::is_read_request() ) {
			return;
		}

		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_post_saved' ), 10, 4 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_deleted' ), 10, 2 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );
		add_action( 'created_term', array( __CLASS__, 'on_term_created' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'on_term_edited' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'on_term_deleted' ), 10, 4 );
		add_action( 'user_register', array( __CLASS__, 'on_user_created' ) );
		add_action( 'profile_update', array( __CLASS__, 'on_user_updated' ) );
		add_action( 'set_user_role', array( __CLASS__, 'on_user_role' ), 10, 2 );
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_activated' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_deactivated' ) );
		add_action( 'switch_theme', array( __CLASS__, 'on_theme_switched' ), 10, 2 );
		add_action( 'updated_option', array( __CLASS__, 'on_option_updated' ) );

		// Late on purpose, and for the same reason the update policy hooks
		// allow_major_auto_core_updates at 9999: the answer that runs last is
		// the one that counts. Here it is not an argument being won but data
		// being lost - the change listeners above stay hooked for the whole of
		// 'shutdown', so a plugin that saves something from its own shutdown
		// callback (deferred writes are an ordinary pattern) buffers a change
		// after a default-priority flush has already run and emptied the
		// buffer, and that change is then never written by anyone. Running
		// after those callbacks is what makes the log complete. Not
		// PHP_INT_MAX, by the same reasoning as the update policy: a site that
		// deliberately wants the very last word from its own mu-plugin should
		// still be able to take it.
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 9999 );
	}

	/**
	 * The options worth a row.
	 *
	 * @return array
	 */
	public static function watched_options() {
		$default = array(
			'siteurl',
			'home',
			'blogname',
			'users_can_register',
			'default_role',
			'permalink_structure',
			'template',
			'stylesheet',
			'active_plugins',
		);
		/**
		 * Filter which options are recorded when they change.
		 *
		 * @param array $options Option names.
		 */
		$filtered = apply_filters( 'dpt_agent_log_watched_options', $default );
		// A filter that returns something other than a list would otherwise
		// turn the allowlist into "watch everything", which is the one
		// outcome it exists to prevent.
		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $default;
	}

	/**
	 * Which columns of a post actually changed.
	 *
	 * @param object      $after  Post after the save.
	 * @param object|null $before Post before it, null on a create.
	 * @return array
	 */
	public static function post_field_diff( $after, $before ) {
		if ( ! is_object( $after ) || ! is_object( $before ) ) {
			// A create has no before. Every column would read as changed,
			// which is true and useless: the action already says it is new.
			return array();
		}
		$changed = array();
		foreach ( get_object_vars( $after ) as $column => $value ) {
			if ( in_array( $column, self::$ignored_columns, true ) ) {
				continue;
			}
			if ( ! property_exists( $before, $column ) ) {
				continue;
			}
			if ( $before->$column !== $value ) {
				$changed[] = $column;
			}
		}
		return $changed;
	}

	public static function on_post_saved( $post_id, $post, $update, $post_before ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$type = ( isset( $post->post_type ) && 'attachment' === $post->post_type ) ? 'attachment' : 'post';
		DPT_AL_Buffer::record(
			$type,
			isset( $post->post_type ) ? $post->post_type : '',
			$post_id,
			$update ? 'updated' : 'created',
			isset( $post->post_title ) ? $post->post_title : '',
			self::post_field_diff( $post, $post_before )
		);
	}

	public static function on_post_deleted( $post_id, $post = null ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			// wp_delete_post_revision() (wp-includes/revision.php) prunes the
			// oldest revisions past WP_POST_REVISIONS on every save that pushes
			// a post over the limit, and it deletes each one through
			// wp_delete_post(), which fires this same 'before_delete_post'
			// hook (wp-includes/post.php). Without this guard, every routine
			// automated update would also write a spurious "deleted" row for
			// internal housekeeping nobody asked about - the same reasoning
			// on_post_saved() above already applies to revisions and autosaves.
			return;
		}
		$type = ( is_object( $post ) && isset( $post->post_type ) && 'attachment' === $post->post_type ) ? 'attachment' : 'post';
		DPT_AL_Buffer::record(
			$type,
			is_object( $post ) && isset( $post->post_type ) ? $post->post_type : '',
			$post_id,
			'deleted',
			is_object( $post ) && isset( $post->post_title ) ? $post->post_title : ''
		);
	}

	public static function on_post_meta( $meta_id, $object_id, $meta_key ) {
		$post = get_post( $object_id );
		if ( ! $post ) {
			return;
		}
		$type = ( 'attachment' === $post->post_type ) ? 'attachment' : 'post';
		DPT_AL_Buffer::record( $type, $post->post_type, $object_id, 'updated', $post->post_title, array( $meta_key ) );
	}

	public static function on_term_created( $term_id, $tt_id, $taxonomy ) {
		self::record_term( $term_id, $taxonomy, 'created' );
	}

	public static function on_term_edited( $term_id, $tt_id, $taxonomy ) {
		self::record_term( $term_id, $taxonomy, 'updated' );
	}

	public static function on_term_deleted( $term_id, $tt_id, $taxonomy, $deleted_term ) {
		DPT_AL_Buffer::record(
			'term',
			(string) $taxonomy,
			$term_id,
			'deleted',
			is_object( $deleted_term ) && isset( $deleted_term->name ) ? $deleted_term->name : ''
		);
	}

	private static function record_term( $term_id, $taxonomy, $action ) {
		$term = get_term( $term_id, $taxonomy );
		DPT_AL_Buffer::record(
			'term',
			(string) $taxonomy,
			$term_id,
			$action,
			( $term && ! is_wp_error( $term ) && isset( $term->name ) ) ? $term->name : ''
		);
	}

	public static function on_user_created( $user_id ) {
		self::record_user( $user_id, 'created' );
	}

	public static function on_user_updated( $user_id ) {
		self::record_user( $user_id, 'updated' );
	}

	public static function on_user_role( $user_id, $role ) {
		DPT_AL_Buffer::record( 'user', (string) $role, $user_id, 'updated', self::user_login( $user_id ), array( 'role' ) );
	}

	public static function on_user_deleted( $user_id ) {
		DPT_AL_Buffer::record( 'user', '', $user_id, 'deleted' );
	}

	private static function record_user( $user_id, $action ) {
		DPT_AL_Buffer::record( 'user', '', $user_id, $action, self::user_login( $user_id ) );
	}

	private static function user_login( $user_id ) {
		$user = get_userdata( $user_id );
		return ( $user && isset( $user->user_login ) ) ? $user->user_login : '';
	}

	public static function on_plugin_activated( $plugin ) {
		DPT_AL_Buffer::record( 'plugin', (string) $plugin, 0, 'activated', (string) $plugin );
	}

	public static function on_plugin_deactivated( $plugin ) {
		DPT_AL_Buffer::record( 'plugin', (string) $plugin, 0, 'deactivated', (string) $plugin );
	}

	public static function on_theme_switched( $new_name, $new_theme = null ) {
		DPT_AL_Buffer::record( 'theme', '', 0, 'switched', (string) $new_name );
	}

	public static function on_option_updated( $option ) {
		if ( ! in_array( $option, self::watched_options(), true ) ) {
			return;
		}
		DPT_AL_Buffer::record( 'option', '', 0, 'updated', (string) $option, array( (string) $option ) );
	}

	/**
	 * Write this request's rows, then prune at most once an hour.
	 *
	 * Pruning here rather than on a scheduled event: a module that is
	 * switched off never runs init(), so it can never unschedule an event it
	 * scheduled while on, and a scheduled hook whose callback is gone is a
	 * leak nothing on the Modules screen would show. A site that is not being
	 * written to has nothing to prune.
	 *
	 * @return void
	 */
	public static function flush() {
		// The channel gate. See init() for why it is here and not there: this
		// runs on 'shutdown', the first point at which REST_REQUEST is defined
		// for a REST request. A browser request that reached a listener leaves
		// nothing behind for a later request in the same process.
		$channel = DPT_AL_Channel::current();
		if ( '' === $channel ) {
			DPT_AL_Buffer::reset();
			return;
		}

		if ( ! DPT_AL_Buffer::pending() ) {
			// Nothing changed. app_name() reads user meta, so it is not paid
			// for until there is a row that needs it.
			return;
		}

		$rows = DPT_AL_Buffer::rows(
			$channel,
			DPT_AL_Channel::app_name(),
			get_current_user_id(),
			time()
		);
		DPT_AL_Buffer::reset();

		if ( empty( $rows ) ) {
			return;
		}

		// One request can have changed things on more than one site: a
		// maintenance script that loops the network with switch_to_blog() is
		// an ordinary thing to write, and by the time 'shutdown' runs it has
		// long since restored the site it started on. The table is per site
		// - $wpdb->prefix follows the switch (wp-includes/ms-blogs.php:534,
		// via wpdb::set_blog_id()), and so does DPT_AL_Store::table() - so
		// writing every row here would file all of them under whichever site
		// happens to be current now. Group by the site each change was
		// recorded on and write each group in that site's context.
		$grouped = array();
		foreach ( $rows as $row ) {
			$grouped[ isset( $row['blog_id'] ) ? (int) $row['blog_id'] : 0 ][] = $row;
		}

		$current = (int) get_current_blog_id();
		foreach ( $grouped as $blog_id => $blog_rows ) {
			// Single site: is_multisite() is false, switch_to_blog() is not
			// even defined (core loads ms-blogs.php only for a network,
			// wp-settings.php:160), and this is the whole of the difference -
			// no switch, no restore, no extra query, exactly the path this
			// method has always taken.
			$switched = is_multisite() && $blog_id > 0 && $blog_id !== $current;
			if ( $switched ) {
				switch_to_blog( $blog_id );
			}
			try {
				// Enablement is per site, and DPT_Plugin::load_modules()
				// decided it once, for whichever site the request started on.
				// Now that rows are written in other sites' contexts, that one
				// answer is the wrong one for every other group: a run that
				// switches into a site where the operator turned Agent Log off
				// would record there anyway, into a table install_table() was
				// never run to create. So each group asks the site it is about.
				if ( self::blog_records() ) {
					foreach ( $blog_rows as $row ) {
						DPT_AL_Store::insert( $row );
					}
					self::maybe_prune();
				}
			} finally {
				// Restored even when a write throws. Leaving a switch on the
				// stack would hand the rest of shutdown - and every other
				// plugin on it - the wrong site.
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Whether the site that is current right now records agent activity.
	 *
	 * Two questions, both answered per site and both answered from that
	 * site's own options, so this is only ever meaningful inside the context
	 * the rows are about:
	 *
	 * - Is the module switched on here? The flag lives in the 'modules' array
	 *   of the dpt_settings option, which is per site. The reading of it is
	 *   not repeated here: DPT_Plugin::is_module_enabled() already knows how
	 *   to read it, including the registry default for a site that has never
	 *   saved the map, and a second copy of that rule would drift from the
	 *   Modules screen the operator actually used.
	 * - Is the table there? DPT_AL_Store::install_table() runs from the
	 *   module's init(), which only ever runs on the site the request started
	 *   on - so a site reached by switch_to_blog() may have the module on and
	 *   no table yet, and inserting into a table that does not exist is an
	 *   error per row. The schema stamp is exactly the right thing to ask,
	 *   because install_table() only writes it once it has confirmed the
	 *   table is really present.
	 *
	 * Installing listeners for a site the request did not start on is not
	 * attempted: by the time flush() runs the changes have already happened,
	 * and the hooks were registered - or not - long before. Declining the
	 * write is the whole of the fix.
	 *
	 * @return bool
	 */
	private static function blog_records() {
		if ( class_exists( 'DPT_Plugin' ) && ! DPT_Plugin::instance()->is_module_enabled( 'agent_log' ) ) {
			return false;
		}
		return get_option( 'dpt_agent_log_schema', '' ) === DPT_AL_Store::SCHEMA_VERSION;
	}

	/**
	 * Prune this site's log, at most once an hour.
	 *
	 * Called inside the site context the rows were just written in, because
	 * both halves of it are per site: the table it trims, and the throttle
	 * stamp in the options table that decides whether to trim at all. Pruning
	 * from the originating site's context instead would trim that site's table
	 * on behalf of writes made elsewhere, leave the other sites' tables to grow
	 * without bound, and push the originating site's stamp forward so its own
	 * next prune is skipped.
	 *
	 * @return void
	 */
	private static function maybe_prune() {
		$last = (int) get_option( 'dpt_agent_log_last_prune', 0 );
		if ( ( time() - $last ) < HOUR_IN_SECONDS ) {
			return;
		}
		update_option( 'dpt_agent_log_last_prune', time(), false );
		DPT_AL_Store::prune( DPT_AL_Buffer::max_age_days(), DPT_AL_Buffer::max_rows(), time() );
	}
}
