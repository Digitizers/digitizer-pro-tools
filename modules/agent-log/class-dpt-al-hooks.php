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
		// The two options whose kit mirror is skipped below are also recorded
		// from their own update_option_{$option} action, ahead of every other
		// callback on it. Core fires that action after the row is written and
		// updated_option only once every callback on it has returned - so a
		// callback that aborts the request (Elementor's mirror itself, or
		// another plugin's) would leave the option changed, its mirror
		// skipped, and no row at all. Recording first means the option row
		// exists before anything can be skipped on its account; the buffer
		// keys on the option name, so the row is the same one updated_option
		// would have written. First on purpose, and PHP_INT_MIN rather than a
		// low number: this is not a policy another site should be able to
		// run before, it is the record of a write that has already happened.
		foreach ( self::$kit_mirrored_options as $option ) {
			add_action( 'update_option_' . $option, array( __CLASS__, 'on_mirrored_option_updated' ), PHP_INT_MIN, 3 );
		}

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
			'blogdescription',
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

	/**
	 * Whether a post id is a revision or an autosave - the internal
	 * housekeeping every callback that resolves a post id must decline to
	 * report on, not just the one a reviewer happened to name.
	 *
	 * Registered post meta with revision support is copied onto each
	 * revision, and removed from it again on pruning, through the ordinary
	 * metadata APIs (wp_save_revisioned_meta_fields() and
	 * wp_delete_post_revision(), both wp-includes/revision.php, via
	 * _wp_copy_post_meta() and delete_metadata_by_mid() in
	 * wp-includes/meta.php since WP 6.4). Those calls fire the same
	 * added_post_meta / deleted_post_meta hooks a real edit would, with the
	 * revision's own id as the object id - so this guard belongs wherever a
	 * callback turns an id into a post, not only in the two places that
	 * already had it.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_revision_or_autosave( $post_id ) {
		return wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id );
	}

	public static function on_post_saved( $post_id, $post, $update, $post_before ) {
		if ( self::is_revision_or_autosave( $post_id ) ) {
			return;
		}
		$fields = self::post_field_diff( $post, $post_before );
		if ( $update && empty( $fields ) && self::is_kit_mirror_of_option( $post ) ) {
			// The mirror's wp_update_post() changes no column of the kit - it
			// only moves the modified date. A save inside the same action that
			// does change a column is some other callback's doing, kept.
			return;
		}
		$type = ( isset( $post->post_type ) && 'attachment' === $post->post_type ) ? 'attachment' : 'post';
		DPT_AL_Buffer::record(
			$type,
			isset( $post->post_type ) ? $post->post_type : '',
			$post_id,
			$update ? 'updated' : 'created',
			isset( $post->post_title ) ? $post->post_title : '',
			$fields
		);
	}

	public static function on_post_deleted( $post_id, $post = null ) {
		if ( self::is_revision_or_autosave( $post_id ) ) {
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
		if ( self::is_revision_or_autosave( $object_id ) ) {
			// Registered meta with revision support rides along on every
			// revision - see is_revision_or_autosave() for the core paths
			// that fire added_post_meta / deleted_post_meta with the
			// revision's own id. Left unguarded, every automated save on an
			// affected site would write a spurious "updated" row for
			// housekeeping nobody asked about, exactly like the unguarded
			// on_post_deleted() case above.
			return;
		}
		$post = get_post( $object_id );
		if ( ! $post ) {
			return;
		}
		if ( self::is_kit_mirror_of_option( $post, $meta_key ) ) {
			return;
		}
		$type = ( 'attachment' === $post->post_type ) ? 'attachment' : 'post';
		DPT_AL_Buffer::record( $type, $post->post_type, $object_id, 'updated', $post->post_title, array( $meta_key ) );
	}

	/**
	 * The site-identity options Elementor copies into its active kit.
	 *
	 * @var array
	 */
	private static $kit_mirrored_options = array( 'blogname', 'blogdescription' );

	/**
	 * Whether this write to a post is Elementor copying a renamed site into
	 * its kit, rather than anything the agent asked for.
	 *
	 * Elementor mirrors the site name and tagline into the active kit's
	 * settings (elementor/core/kits/manager.php, on update_option_blogname
	 * and update_option_blogdescription, each calling
	 * update_kit_settings_based_on_option()). That reaches WordPress as two
	 * writes, both seen here: Document::update_settings() goes through the
	 * page settings manager, whose ajax_before_save_settings() calls
	 * wp_update_post() on the kit (bumping its modified date - a save with no
	 * field of its own, seen by on_post_saved()) and whose save_settings_to_db()
	 * then writes _elementor_page_settings (seen by on_post_meta()). Both are
	 * real writes, and both are Elementor's reaction, not the agent's action;
	 * a row for either says nothing the option row does not already say.
	 * Worse, core fires update_option_{$option} before updated_option, so
	 * the reaction would reach the buffer ahead of its cause and the log
	 * would read effect-then-cause.
	 *
	 * Decided here, at the moment of the write, and not later from what the
	 * request's rows look like: an agent that renames the site and also
	 * edits the kit's settings on purpose in the same request makes two
	 * writes to the same post, which the buffer folds into one entry -
	 * indistinguishable after the fact from the echo alone. What does
	 * distinguish them is *when* each happened. The copy runs inside the
	 * update_option_{$option} action and nowhere else, and doing_action()
	 * (wp-includes/plugin.php) answers exactly that question from core's own
	 * filter stack. The deliberate edit runs outside the action and is
	 * recorded like any other. Elementor's own guard goes the other way -
	 * update_kit_settings_based_on_option() returns early while the kit
	 * is_saving() - so a kit save can never be the thing that is skipped
	 * here.
	 *
	 * Only the mirror's own two writes: the kit's post row saved with no
	 * column changed (on_post_saved() checks the diff), and the one meta key
	 * it writes. Another plugin hooked on the same option action that
	 * changes a real column of the kit, writes some other key on it, or
	 * writes any other post, is that plugin's side effect, which this log
	 * keeps as it keeps every other (see the readme: nothing is filtered by
	 * default, and an entry for a plugin rewriting its own data is true,
	 * only uninteresting). And only the active kit, the one post Elementor
	 * mirrors into.
	 *
	 * Core has already written the option by the time this is asked:
	 * update_option() (wp-includes/option.php) updates the row, returns
	 * false on failure, and only then fires update_option_{$option} - on
	 * which init() records the option first, ahead of the mirror - and then
	 * updated_option. So a write skipped here is never left with no option
	 * row beside it, even when a later callback on the action aborts the
	 * request before updated_option is reached.
	 *
	 * @param object      $post     The post written.
	 * @param string|null $meta_key The meta key, when the write is a meta
	 *                              write; null for the post row itself.
	 * @return bool
	 */
	private static function is_kit_mirror_of_option( $post, $meta_key = null ) {
		if ( ! is_object( $post ) || ! isset( $post->post_type ) || 'elementor_library' !== $post->post_type ) {
			return false;
		}
		if ( null !== $meta_key && '_elementor_page_settings' !== $meta_key ) {
			return false;
		}
		$inside = false;
		foreach ( self::$kit_mirrored_options as $option ) {
			if ( doing_action( 'update_option_' . $option ) ) {
				$inside = true;
				break;
			}
		}
		if ( ! $inside ) {
			return false;
		}
		// Elementor's default for the option is 0, so a site with no kit
		// never matches a real post id.
		return (int) get_option( 'elementor_active_kit', 0 ) === (int) $post->ID;
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
	 * update_option_{$option} passes ( $old_value, $value, $option ); the
	 * option name is third. See init() for why these two are recorded here
	 * as well as on updated_option.
	 */
	public static function on_mirrored_option_updated( $old_value, $value, $option ) {
		self::on_option_updated( $option );
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
					$written = 0;
					foreach ( $blog_rows as $row ) {
						/**
						 * Whether one change is worth recording.
						 *
						 * Every entry passes here, so a site has one place to
						 * silence a writer it does not care about - a plugin
						 * that rewrites its own settings on load, say, which
						 * is a real change and an uninteresting one. Nothing
						 * is filtered out by default: what is absent from
						 * this log is supposed to mean it did not happen over
						 * an API, and a default exclusion would quietly make
						 * that untrue.
						 *
						 * Applied here rather than when the change is first
						 * seen, for two reasons. The channel and the
						 * application password's name are not known until the
						 * request ends, and they are most of what a useful
						 * rule matches on. And this runs inside the site the
						 * entry belongs to: one CLI or cron request can walk a
						 * whole network, and a callback reading get_option()
						 * to decide what to silence has to read the options of
						 * the site the change happened on, not the site the
						 * request started in.
						 *
						 * @param bool  $record Whether to record it. Default true.
						 * @param array $entry  The finished entry: object_type,
						 *                      object_subtype, object_id,
						 *                      object_name, action, fields,
						 *                      blog_id, logged_at, channel,
						 *                      app, user_id.
						 */
						if ( ! apply_filters( 'dpt_agent_log_record', true, $row ) ) {
							continue;
						}
						DPT_AL_Store::insert( $row );
						$written++;
					}
					// Pruned only when something was actually written. A
					// request whose every row was filtered away has changed
					// nothing here, and stamping the throttle for it would
					// starve the prune that a real write would have done.
					if ( $written > 0 ) {
						self::maybe_prune();
					}
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
