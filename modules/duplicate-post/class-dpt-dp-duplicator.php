<?php
/**
 * Duplicate Post module - the actual copy logic.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DPT_DP_Duplicator {

	/**
	 * Meta keys that must never be copied to the clone.
	 */
	public static function skipped_meta_keys() {
		return apply_filters( 'dpt_dp_skipped_meta_keys', array(
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_old_date',
		) );
	}

	/**
	 * Duplicate a post into a new draft. Returns the new post ID or a
	 * WP_Error.
	 *
	 * @param int $post_id Source post ID.
	 */
	public static function duplicate( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'dpt_dp_not_found', __( 'Post not found.', 'digitizer-pro-tools' ) );
		}

		$o      = DPT_DP_Settings::all();
		$suffix = trim( (string) $o['title_suffix'] );
		$title  = $post->post_title;
		if ( '' !== $suffix ) {
			$title .= ' ' . $suffix;
		}

		$new_post = array(
			'post_title'     => $title,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_type'      => $post->post_type,
			'post_status'    => 'draft',
			'post_parent'    => $post->post_parent,
			'menu_order'     => $post->menu_order,
			'post_password'  => $post->post_password,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'post_author'    => get_current_user_id(),
		);

		// Keep the original date only when explicitly requested; the
		// default is a fresh date so drafts sort naturally.
		if ( '1' === $o['copy_date'] ) {
			$new_post['post_date']     = $post->post_date;
			$new_post['post_date_gmt'] = $post->post_date_gmt;
		}

		// wp_insert_post() unslashes its input - wp_slash() protects
		// content with legitimate backslashes.
		$new_id = wp_insert_post( wp_slash( $new_post ), true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		if ( '1' === $o['copy_taxonomies'] ) {
			self::copy_taxonomies( $post, $new_id );
		}
		if ( '1' === $o['copy_meta'] ) {
			self::copy_meta( $post->ID, $new_id );
		}

		/**
		 * Fires after a post was duplicated.
		 *
		 * @param int     $new_id New (draft) post ID.
		 * @param WP_Post $post   Source post.
		 */
		do_action( 'dpt_dp_duplicated', $new_id, $post );

		return $new_id;
	}

	private static function copy_taxonomies( $post, $new_id ) {
		$taxonomies = get_object_taxonomies( $post->post_type );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				wp_set_object_terms( $new_id, $terms, $taxonomy );
			}
		}
	}

	/**
	 * Copy all post meta (including page templates, featured image and
	 * builder data such as Elementor's _elementor_data).
	 */
	private static function copy_meta( $source_id, $new_id ) {
		$skip = self::skipped_meta_keys();
		// Raw (still-serialized) values, every entry per key.
		$meta = get_post_custom( $source_id );
		if ( ! is_array( $meta ) ) {
			return;
		}
		foreach ( $meta as $key => $values ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				// maybe_unserialize() restores arrays/objects so WordPress
				// re-serializes them itself; wp_slash() protects JSON meta
				// (e.g. Elementor) whose quotes/backslashes update/add_meta
				// would otherwise strip when unslashing.
				add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}
}
