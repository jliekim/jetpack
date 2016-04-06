<?php


class Jetpack_Sync_Posts {

	static $max_to_sync = 10;
	static $que_option_name = 'jetpack_sync_post_ids_que';

	static function init() {

		add_action( 'post_updated', array( 'Jetpack_Sync', 'sync_action' ), 0, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'transition_post_status' ), 10, 3 );
		add_action( 'deleted_post', array( 'Jetpack_Sync', 'sync_action' ) );
		// We should change this to 'attachment_updated' introduced in WP 4.4 once it's our latest WP version supported
		add_action( 'edit_attachment', array( __CLASS__, 'edit_attachment' ) );
		add_action( 'attachment_updated', array( 'Jetpack_Sync', 'sync_action' ) );

		add_action( 'add_attachment', array( __CLASS__, 'add_attachment' ) );

		// Mark the post as needs updating when taxonomies get added to it.
		add_action( 'set_object_terms', array( 'Jetpack_Sync', 'sync_action' ), 10, 6 );

		// Update comment count
		add_action( 'wp_update_comment_count', array( 'Jetpack_Sync', 'sync_action'  ), 10, 3 );

		// Sync post when the cache is cleared
		// add_action( 'clean_post_cache', array( __CLASS__, 'clear_post_cache' ), 10, 2 );
	}

	static function get_post_diff( $post_after, $post_before ) {
		return Jetpack_Sync::array_diff_assoc_recursive( (array)$post_after, (array)$post_before );
	}

	static function transition_post_status( $new_status, $old_status, $post ) {
		if ( $new_status !== $old_status ) {
			Jetpack_Sync::sync_action( 'transition_post_status', $new_status, $old_status );
		}
	}

	static function sync_attachment( $post_id ) {
		self::sync( $post_id );
	}

	static function clear_post_cache( $post_id, $post ) {
		self::sync( $post_id );
	}

	static function get_synced_post_types() {
		$allowed_post_types = array();
		foreach ( get_post_types( array(), 'objects' ) as $post_type => $post_type_object ) {
			if ( post_type_supports( $post_type, 'comments' ) ||
			     post_type_supports( $post_type, 'publicize' ) ||
			     $post_type_object->public
			) {
				$allowed_post_types[] = $post_type;
			}
		}
		$allowed_post_types = apply_filters( 'jetpack_post_sync_post_type', $allowed_post_types );

		return array_diff( $allowed_post_types, array( 'revision' ) );
	}

	static function get_synced_post_status() {
		$allowed_post_stati = apply_filters( 'jetpack_post_sync_post_status', get_post_stati() );

		return array_diff( $allowed_post_stati, array( 'auto-draft' ) );
	}

	static function get_post( $post_id, $allowed_post_types = array(), $allowed_post_statuses = array() ) {
		$post_obj = get_post( $post_id );
		if ( ! $post_obj ) {
			return false;
		}

		if ( is_null( $allowed_post_types ) ) {
			$allowed_post_types = self::get_synced_post_types();
		}
		if ( is_null( $allowed_post_types ) ) {
			$allowed_post_statuses = self::get_synced_post_status();
		}

		if ( ! in_array( $post_obj->post_type, $allowed_post_types ) ) {
			return false;
		}

		if ( ! in_array( $post_obj->post_status, $allowed_post_statuses ) ) {
			return false;
		}

		if ( is_callable( $post_obj, 'to_array' ) ) {
			// WP >= 3.5
			$post = $post_obj->to_array();
		} else {
			// WP < 3.5
			$post = get_object_vars( $post_obj );
		}

		if ( 0 < strlen( $post['post_password'] ) ) {
			$post['post_password'] = 'auto-' . wp_generate_password( 10, false ); // We don't want the real password.  Just pass something random.
		}

		// local optimizations
		unset(
			$post['filter'],
			$post['ancestors'],
			$post['post_content_filtered'],
			$post['to_ping'],
			$post['pinged']
		);

		if ( self::is_post_public( $post ) ) {
			$post['post_is_public'] = Jetpack_Options::get_option( 'public' );
		} else {
			//obscure content
			$post['post_content']   = '';
			$post['post_excerpt']   = '';
			$post['post_is_public'] = false;
		}
		$post_type_obj                        = get_post_type_object( $post['post_type'] );
		$post['post_is_excluded_from_search'] = $post_type_obj->exclude_from_search;

		$post['tax'] = array();
		$taxonomies  = get_object_taxonomies( $post_obj );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_object_term_cache( $post_obj->ID, $taxonomy );
			if ( empty( $terms ) ) {
				$terms = wp_get_object_terms( $post_obj->ID, $taxonomy );
			}
			$term_names = array();
			foreach ( $terms as $term ) {
				$term_names[] = $term->name;
			}
			$post['tax'][ $taxonomy ] = $term_names;
		}

		$meta         = get_post_meta( $post_obj->ID, false );
		$post['meta'] = array();
		foreach ( $meta as $key => $value ) {
			$post['meta'][ $key ] = array_map( 'maybe_unserialize', $value );
		}

		$post['extra'] = array(
			'author'                  => get_the_author_meta( 'display_name', $post_obj->post_author ),
			'author_email'            => get_the_author_meta( 'email', $post_obj->post_author ),
			'dont_email_post_to_subs' => get_post_meta( $post_obj->ID, '_jetpack_dont_email_post_to_subs', true ),
		);

		if ( $attachment_id = get_post_thumbnail_id( $post_id ) ) {
			$feature = wp_get_attachment_image_src( $attachment_id, 'large' );
			if ( ! empty( $feature[0] ) ) {
				$post['extra']['featured_image'] = $feature[0];
			}

			$attachment = get_post( $attachment_id );
			if ( ! empty( $attachment ) ) {
				$metadata = wp_get_attachment_metadata( $attachment_id );

				$post['extra']['post_thumbnail'] = array(
					'ID'        => (int) $attachment_id,
					'URL'       => (string) wp_get_attachment_url( $attachment_id ),
					'guid'      => (string) $attachment->guid,
					'mime_type' => (string) $attachment->post_mime_type,
					'width'     => (int) isset( $metadata['width'] ) ? $metadata['width'] : 0,
					'height'    => (int) isset( $metadata['height'] ) ? $metadata['height'] : 0,
				);

				if ( isset( $metadata['duration'] ) ) {
					$post['extra']['post_thumbnail'] = (int) $metadata['duration'];
				}

				/**
				 * Filters the Post Thumbnail information returned for a specific post.
				 *
				 * @since 3.3.0
				 *
				 * @param array $post ['extra']['post_thumbnail'] {
				 *    Array of details about the Post Thumbnail.
				 * @param int ID Post Thumbnail ID.
				 * @param string URL Post thumbnail URL.
				 * @param string guid Post thumbnail guid.
				 * @param string mime_type Post thumbnail mime type.
				 * @param int width Post thumbnail width.
				 * @param int height Post thumbnail height.
				 * }
				 */
				$post['extra']['post_thumbnail'] = (object) apply_filters( 'get_attachment', $post['extra']['post_thumbnail'] );
			}
		}

		$post['permalink'] = get_permalink( $post_obj->ID );
		$post['shortlink'] = wp_get_shortlink( $post_obj->ID );
		/**
		 * Allow modules to send extra info on the sync post process.
		 *
		 * @since 2.8.0
		 *
		 * @param array $args Array of custom data to attach to a post.
		 * @param Object $post_obj Object returned by get_post() for a given post ID.
		 */
		$post['module_custom_data']                      = apply_filters( 'jetpack_sync_post_module_custom_data', array(), $post_obj );
		$post['module_custom_data']['cpt_publicizeable'] = post_type_supports( $post_obj->post_type, 'publicize' ) ? true : false;

		return $post;
	}

	static function is_post_public( $post ) {
		if ( ! is_array( $post ) ) {
			$post = (array) $post;
		}

		if ( 0 < strlen( $post['post_password'] ) ) {
			return false;
		}
		if ( ! in_array( $post['post_type'], get_post_types( array( 'public' => true ) ) ) ) {
			return false;
		}
		$post_status = get_post_status( $post['ID'] ); // Inherited status is resolved here.
		if ( ! in_array( $post_status, get_post_stati( array( 'public' => true ) ) ) ) {
			return false;
		}

		return true;
	}

}
