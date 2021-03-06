<?php
/**
 * Setup cleancoded post type
 *
 * @package redirect-url
 */

/**
 * Post type class
 */
class cleancoded_Post_Type {

	/**
	 * Status code lables for reuse
	 *
	 * @var array
	 */
	public $status_code_labels = array(); // Defined later to allow i18n

	/**
	 * We have to store the redirect search so we can grab it later
	 *
	 * @var string
	 */
	private $redirect_search_term;

	/**
	 * Sets up redirect manager
	 *
	 * @since 1.8
	 */
	public function setup() {
		$this->status_code_labels = array(
			301 => esc_html__( 'Moved Permanently', 'redirect-url' ),
			302 => esc_html__( 'Found', 'redirect-url' ),
			303 => esc_html__( 'See Other', 'redirect-url' ),
			307 => esc_html__( 'Temporary Redirect', 'redirect-url' ),
			403 => esc_html__( 'Forbidden', 'redirect-url' ),
			404 => esc_html__( 'Not Found', 'redirect-url' ),
		);

		add_action( 'init', array( $this, 'action_register_post_types' ) );
		add_action( 'admin_init', array( $this, 'init_search_filters' ) );
		add_action( 'save_post', array( $this, 'action_save_post' ) );
		add_filter( 'manage_redirect_rule_posts_columns', array( $this, 'filter_redirect_columns' ) );
		add_filter( 'manage_edit-redirect_rule_sortable_columns', array( $this, 'filter_redirect_sortable_columns' ) );
		add_action( 'manage_redirect_rule_posts_custom_column', array( $this, 'action_custom_redirect_columns' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'action_transition_post_status' ), 10, 3 );
		add_filter( 'post_updated_messages', array( $this, 'filter_redirect_updated_messages' ) );
		add_action( 'admin_notices', array( $this, 'action_redirect_chain_alert' ) );
		add_filter( 'the_title', array( $this, 'filter_admin_title' ), 100, 2 );
		add_filter( 'bulk_actions-edit-redirect_rule', array( $this, 'filter_bulk_actions' ) );
		add_action( 'admin_print_styles-edit.php', array( $this, 'action_print_logo_css' ), 10, 1 );
		add_action( 'admin_print_styles-post.php', array( $this, 'action_print_logo_css' ), 10, 1 );
		add_action( 'admin_print_styles-post-new.php', array( $this, 'action_print_logo_css' ), 10, 1 );
		add_filter( 'post_type_link', array( $this, 'filter_post_type_link' ), 10, 2 );
		add_filter( 'default_hidden_columns', array( $this, 'filter_hidden_columns' ), 10, 1 );
	}

	/**
	 * Setup search filters
	 */
	public function init_search_filters() {
		$redirect_capability = $this->get_redirect_capability();

		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( $redirect_capability ) ) {
			return;
		}

		add_action( 'pre_get_posts', array( $this, 'disable_core_search' ) );
		add_filter( 'posts_clauses', array( $this, 'filter_search_clauses' ), 10, 2 );

		add_filter( 'post_row_actions', array( $this, 'filter_disable_quick_edit' ), 10, 2 );
	}

	/**
	 * Hide order column by default
	 *
	 * @param  array $hidden Array of hidden post types
	 * @since  1.9
	 * @return array
	 */
	public function filter_hidden_columns( $hidden ) {
		if ( empty( $_GET['post_type'] ) || 'redirect_rule' !== $_GET['post_type'] ) {
			return $hidden;
		}

		$hidden[] = 'menu_order';

		return $hidden;
	}

	/**
	 * Remove quick edit
	 *
	 * @param  array   $actions Array of actions
	 * @param  WP_Post $post Post object
	 * @since  1.8
	 * @return array
	 */
	public function filter_disable_quick_edit( $actions = array(), $post ) {
		if ( 'redirect_rule' === get_post_type( $post ) && isset( $actions['inline hide-if-no-js'] ) ) {
			unset( $actions['inline hide-if-no-js'] );
			unset( $actions['view'] );
		}

		return $actions;
	}

	/**
	 * We don't need core's fancy search functionality since we provide our own.
	 *
	 * @param  \WP_Query $query WP Query object
	 */
	public function disable_core_search( $query ) {
		if ( $query->is_search() && 'redirect_rule' === $query->get( 'post_type' ) ) {
			// Store a reference to the search term for later use.
			$this->redirect_search_term = $query->get( 's' );
			// Don't let core build it's search clauses since we override them.
			$query->set( 's', '' );
		}
	}

	/**
	 * Build custom JOIN + WHERE clauses to do a more direct search through meta.
	 *
	 * @param  array    $clauses Array of SQL clauses
	 * @param  WP_Query $query WP_Query object
	 * @return array
	 */
	public function filter_search_clauses( $clauses, $query ) {
		global $wpdb;

		if ( $this->redirect_search_term ) {
			$search_term      = $this->redirect_search_term;
			$search_term_like = '%' . $wpdb->esc_like( $search_term ) . '%';

			$query->set( 's', $this->redirect_search_term );
			unset( $this->redirect_search_term );

			$clauses['distinct'] = 'DISTINCT';

			$clauses['join'] .= " LEFT JOIN $wpdb->postmeta AS pm ON ($wpdb->posts.ID = pm.post_id) ";

			$clauses['where'] = $wpdb->prepare(
				"AND (
					(
						pm.meta_value LIKE %s
						AND pm.meta_key = '_redirect_rule_from'
					) OR (
						pm.meta_value LIKE %s
						AND pm.meta_key = '_redirect_rule_to'
					)
				)
				AND $wpdb->posts.post_type = 'redirect_rule'
				AND $wpdb->posts.post_status IN ( 'publish', 'future', 'draft', 'pending' )
				",
				$search_term_like,
				$search_term_like
			);
		}

		return $clauses;
	}

	/**
	 * Swap tools logo for plugin logo
	 *
	 * @since 1.1
	 * @uses plugins_url
	 * @return void
	 */
	public function action_print_logo_css() {
		if ( $this->is_plugin_page() ) {
			?>
			<style type="text/css">
				#visibility, .view-switch, .posts .inline-edit-col-left .inline-edit-group, #preview-action {
					display: none;
				}
				#cleancoded_redirect_rule_from {
					width: 60%;
				}
			</style>
			<?php
		}
	}

	/**
	 * Limit the bulk actions available in the Manage Redirects view
	 *
	 * @param  array $actions Array of actions
	 * @since 1.0
	 * @return array
	 */
	public function filter_bulk_actions( $actions ) {

		// No bulk editing at this time
		unset( $actions['edit'] );

		return $actions;
	}

	/**
	 * Whether or not this is an admin page specific to the plugin
	 *
	 * @since 1.1
	 * @uses get_post_type
	 * @return bool
	 */
	private function is_plugin_page() {
		return (bool) ( get_post_type() === 'redirect_rule' || ( isset( $_GET['post_type'] ) && 'redirect_rule' === $_GET['post_type'] ) );
	}

	/**
	 * Echoes admin message if redirect chains exist
	 *
	 * @since 1.0
	 * @uses apply_filters
	 * @return void
	 */
	public function action_redirect_chain_alert() {
		global $hook_suffix;
		if ( $this->is_plugin_page() ) {

			/**
			 * check_for_possible_redirect_loops() runs in best case Theta(n^2) so if you have 100 redirects, this method
			 * will be running slow. Let's disable it by default.
			 */
			if ( apply_filters( 'cleancoded_check_for_possible_redirect_loops', false ) ) {
				if ( cleancoded_check_for_possible_redirect_loops() ) {
					?>
					<div class="updated">
						<p><?php esc_html_e( 'cleancoded Redirect URL Warning: Possible redirect loops and/or chains have been created.', 'redirect-url' ); ?></p>
					</div>
					<?php
				}
			}
			if ( cleancoded_max_redirects_reached() ) {

				if ( 'post-new.php' === $hook_suffix ) {
					?>
					<style type="text/css">#post { display: none; }</style>
					<?php
				}
				?>
				<div class="error">
					<p><?php esc_html_e( 'cleancoded Redirect URL Error: You have reached the maximum allowable number of redirects', 'redirect-url' ); ?></p>
				</div>
				<?php
			}
		}
	}

	/**
	 * Filters title out for redirect from in post manager
	 *
	 * @since 1.0
	 * @param string $title Admin title
	 * @param int    $post_id Post ID
	 * @uses is_admin, get_post_meta
	 * @return string
	 */
	public function filter_admin_title( $title, $post_id = 0 ) {
		if ( ! is_admin() ) {
			return $title;
		}

		$redirect = get_post( $post_id );
		if ( empty( $redirect ) ) {
			return $title;
		}

		if ( 'redirect_rule' !== $redirect->post_type ) {
			return $title;
		}

		$redirect_from = get_post_meta( $post_id, '_redirect_rule_from', true );
		if ( ! empty( $redirect_from ) ) {
			return $redirect_from;
		}

		return $title;
	}

	/**
	 * Customizes updated messages for redirects
	 *
	 * @since 1.0
	 * @param array $messages Array of messages
	 * @uses esc_url, get_permalink, add_query_var, wp_post_revision_title
	 * @return array
	 */
	public function filter_redirect_updated_messages( $messages ) {
		global $post, $post_ID;

		$messages['redirect_rule'] = array(
			0  => '', // Unused. Messages start at index 1.
			1  => sprintf( esc_html__( 'Redirect rule updated.', 'redirect-url' ), esc_url( get_permalink( $post_ID ) ) ),
			2  => esc_html__( 'Custom field updated.', 'redirect-url' ),
			3  => esc_html__( 'Custom field deleted.', 'redirect-url' ),
			4  => esc_html__( 'Redirect rule updated.', 'redirect-url' ),
			/* translators: %s: date and time of the revision */
			5  => isset( $_GET['revision'] ) ? sprintf( esc_html__( 'Redirect rule restored to revision from %s', 'redirect-url' ), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
			6  => sprintf( esc_html__( 'Redirect rule published.', 'redirect-url' ), esc_url( get_permalink( $post_ID ) ) ),
			7  => esc_html__( 'Redirect rule saved.', 'redirect-url' ),
			8  => sprintf( esc_html__( 'Redirect rule submitted.', 'redirect-url' ), esc_url( add_query_arg( 'preview', 'true', get_permalink( $post_ID ) ) ) ),
			9  => sprintf(
				esc_html__( 'Redirect rule scheduled for: %1$s.', 'redirect-url' ),
				// translators: Publish box date format, see http://php.net/date
				date_i18n( esc_html__( 'M j, Y @ G:i' ), strtotime( $post->post_date ) ),
				esc_url( get_permalink( $post_ID ) )
			),
			10 => sprintf( esc_html__( 'Redirect rule draft updated.', 'redirect-url' ), esc_url( add_query_arg( 'preview', 'true', get_permalink( $post_ID ) ) ) ),
		);

		return $messages;
	}

	/**
	 * Clear redirect cache if appropriate post type is transitioned
	 *
	 * @since 1.0
	 * @param string  $new_status New post status
	 * @param string  $old_status Old post status
	 * @param WP_Post $post Post object
	 * @return void
	 */
	public function action_transition_post_status( $new_status, $old_status, $post ) {
		if ( ! is_object( $post ) ) {
			return;
		}

		// recreate redirect cache
		if ( 'redirect_rule' === $post->post_type ) {
			cleancoded_flush_cache();
		}
	}

	/**
	 * Displays custom columns on redirect manager screen
	 *
	 * @since 1.0
	 * @param string $column Column name
	 * @param int    $post_id Post Id
	 * @uses get_post_meta, esc_html, admin_url
	 * @return void
	 */
	public function action_custom_redirect_columns( $column, $post_id ) {
		if ( 'cleancoded_redirect_rule_to' === $column ) {
			echo esc_html( get_post_meta( $post_id, '_redirect_rule_to', true ) );
		} elseif ( 'cleancoded_redirect_rule_status_code' === $column ) {
			echo absint( get_post_meta( $post_id, '_redirect_rule_status_code', true ) );
		} elseif ( 'menu_order' === $column ) {
			global $post;
			echo esc_html( $post->menu_order );
		}
	}

	/**
	 * Add new columns to manage redirect screen
	 *
	 * @since 1.0
	 * @param array $columns Array columns
	 * @return array
	 */
	public function filter_redirect_columns( $columns ) {
		$columns['cleancoded_redirect_rule_to']          = esc_html__( 'Redirect To', 'redirect-url' );
		$columns['cleancoded_redirect_rule_status_code'] = esc_html__( 'HTTP Status Code', 'redirect-url' );
		$columns['menu_order']                    = esc_html__( 'Order', 'redirect-url' );

		// Change the title column
		$columns['title'] = esc_html__( 'Redirect From', 'redirect-url' );

		// Move date column to the back
		unset( $columns['date'] );
		$columns['date'] = esc_html__( 'Date', 'redirect-url' );

		return $columns;
	}

	/**
	 * Allow menu_order column to be sortable.
	 *
	 * @param array $columns Array of columns
	 * @since 1.9
	 * @return array
	 */
	public function filter_redirect_sortable_columns( $columns ) {
		$columns['menu_order'] = 'menu_order';
		return $columns;
	}

	/**
	 * Saves meta info for redirect rules
	 *
	 * @since 1.0
	 * @param int $post_id Post ID
	 * @uses current_user_can, get_post_type, wp_verify_nonce, update_post_meta, delete_post_meta
	 * @return void
	 */
	public function action_save_post( $post_id ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'revision' === get_post_type( $post_id ) ) {
			return;
		}

		// Update post meta for redirect rules
		if ( ! empty( $_POST['cleancoded_redirect_nonce'] ) && wp_verify_nonce( $_POST['cleancoded_redirect_nonce'], 'cleancoded-save-redirect-meta' ) && current_user_can( 'edit_post', $post_id ) ) {

			if ( ! empty( $_POST['cleancoded_redirect_rule_from_regex'] ) ) {
				$allow_regex = (bool) $_POST['cleancoded_redirect_rule_from_regex'];
				update_post_meta( $post_id, '_redirect_rule_from_regex', $allow_regex );
			} else {
				$allow_regex = false;
				delete_post_meta( $post_id, '_redirect_rule_from_regex' );
			}

			if ( ! empty( $_POST['cleancoded_redirect_rule_from'] ) ) {
				update_post_meta( $post_id, '_redirect_rule_from', cleancoded_sanitize_redirect_from( $_POST['cleancoded_redirect_rule_from'], $allow_regex ) );
			} else {
				delete_post_meta( $post_id, '_redirect_rule_from' );
			}

			if ( ! empty( $_POST['cleancoded_redirect_rule_to'] ) ) {
				update_post_meta( $post_id, '_redirect_rule_to', cleancoded_sanitize_redirect_to( $_POST['cleancoded_redirect_rule_to'] ) );
			} else {
				delete_post_meta( $post_id, '_redirect_rule_to' );
			}

			if ( ! empty( $_POST['cleancoded_redirect_rule_status_code'] ) ) {
				update_post_meta( $post_id, '_redirect_rule_status_code', absint( $_POST['cleancoded_redirect_rule_status_code'] ) );
			} else {
				delete_post_meta( $post_id, '_redirect_rule_status_code' );
			}

			if ( ! empty( $_POST['cleancoded_redirect_rule_notes'] ) ) {
				update_post_meta( $post_id, '_redirect_rule_notes', sanitize_text_field( $_POST['cleancoded_redirect_rule_notes'] ) );
			} else {
				delete_post_meta( $post_id, '_redirect_rule_notes' );
			}

			/**
			 * This fixes an important bug where the redirect cache was not up-to-date. Previously the cache was only being
			 * updated on transition_post_status which gets called BEFORE save post. But since save_post is where all the important
			 * redirect info is saved, updating the cache before it is not sufficient.
			 */
			cleancoded_flush_cache();
		}
	}

	/**
	 * Get required capability for managing redirects
	 *
	 * @return string
	 */
	protected function get_redirect_capability() {
		$redirect_capability = 'cleancoded_manage_redirects';

		$roles = array( 'administrator' );

		foreach ( $roles as $role ) {
			$role = get_role( $role );

			if ( empty( $role ) || $role->has_cap( $redirect_capability ) ) {
				continue;
			}

			$role->add_cap( $redirect_capability );
		}

		return apply_filters( 'cleancoded_restrict_to_capability', $redirect_capability );
	}

	/**
	 * Registers post types for plugin
	 *
	 * @since 1.0
	 * @uses register_post_type, _x, plugins_url, apply_filters
	 * @return void
	 */
	public function action_register_post_types() {
		$redirect_labels = array(
			'name'               => esc_html_x( 'cleancoded Redirect URL', 'post type general name', 'redirect-url' ),
			'singular_name'      => esc_html_x( 'Redirect', 'post type singular name', 'redirect-url' ),
			'add_new'            => _x( 'Create Redirect Rule', 'redirect rule', 'redirect-url' ),
			'add_new_item'       => esc_html__( 'cleancoded Redirect URL', 'redirect-url' ),
			'edit_item'          => esc_html__( 'Edit Redirect Rule', 'redirect-url' ),
			'new_item'           => esc_html__( 'New Redirect Rule', 'redirect-url' ),
			'all_items'          => esc_html__( 'cleancoded Redirect URL', 'redirect-url' ),
			'view_item'          => esc_html__( 'View Redirect Rule', 'redirect-url' ),
			'search_items'       => esc_html__( 'Search Redirects', 'redirect-url' ),
			'not_found'          => esc_html__( 'No redirect rules found.', 'redirect-url' ),
			'not_found_in_trash' => esc_html__( 'No redirect rules found in trash.', 'redirect-url' ),
			'parent_item_colon'  => '',
			'menu_name'          => esc_html__( 'cleancoded Redirect URL', 'redirect-url' ),
		);

		$redirect_capability = $this->get_redirect_capability();

		$capabilities = array(
			'edit_post'          => $redirect_capability,
			'read_post'          => $redirect_capability,
			'delete_post'        => $redirect_capability,
			'delete_posts'       => $redirect_capability,
			'edit_posts'         => $redirect_capability,
			'edit_others_posts'  => $redirect_capability,
			'publish_posts'      => $redirect_capability,
			'read_private_posts' => $redirect_capability,
		);

		$redirect_args = array(
			'labels'               => $redirect_labels,
			'public'               => false,
			'publicly_queryable'   => true,
			'show_ui'              => true,
			'show_in_menu'         => 'tools.php',
			'query_var'            => false,
			'rewrite'              => false,
			'capability_type'      => 'post',
			'capabilities'         => $capabilities,
			'has_archive'          => false,
			'hierarchical'         => false,
			'register_meta_box_cb' => array( $this, 'action_redirect_rule_metabox' ),
			'menu_position'        => 80,
			'supports'             => array( 'page-attributes' ),
		);
		register_post_type( 'redirect_rule', $redirect_args );
	}

	/**
	 * Registers meta boxes for redirect rule post type
	 *
	 * @since 1.0
	 * @uses add_meta_box
	 * @return void
	 */
	public function action_redirect_rule_metabox() {
		add_meta_box( 'redirect_settings', esc_html__( 'Redirect Settings', 'redirect-url' ), array( $this, 'redirect_rule_metabox' ), 'redirect_rule', 'normal', 'core' );
	}

	/**
	 * Echoes HTML for redirect rule meta box
	 *
	 * @since 1.0
	 * @param WP_Post $post Post object
	 * @uses wp_nonce_field, get_post_meta, esc_attr, selected
	 * @return void
	 */
	public function redirect_rule_metabox( $post ) {
		wp_nonce_field( 'cleancoded-save-redirect-meta', 'cleancoded_redirect_nonce' );

		$redirect_from  = get_post_meta( $post->ID, '_redirect_rule_from', true );
		$redirect_to    = get_post_meta( $post->ID, '_redirect_rule_to', true );
		$redirect_notes = get_post_meta( $post->ID, '_redirect_rule_notes', true );
		$status_code    = get_post_meta( $post->ID, '_redirect_rule_status_code', true );
		$enable_regex   = get_post_meta( $post->ID, '_redirect_rule_from_regex', true );

		if ( empty( $status_code ) ) {
			$status_code = apply_filters( 'cleancoded_default_direct_status', 302 );
		}
		?>
		<p>
			<label for="cleancoded_redirect_rule_from"><strong><?php esc_html_e( '* Redirect From:', 'redirect-url' ); ?></strong></label><br />
			<input type="text" name="cleancoded_redirect_rule_from" id="cleancoded_redirect_rule_from" value="<?php echo esc_attr( $redirect_from ); ?>" />
			<input type="checkbox" name="cleancoded_redirect_rule_from_regex" id="cleancoded_redirect_rule_from_regex" <?php checked( true, (bool) $enable_regex ); ?> value="1" />
			<label for="cleancoded_redirect_rule_from_regex"><?php esc_html_e( 'Enable Regular Expressions (advanced)', 'redirect-url' ); ?></label>
		</p>
		<p class="description"><?php esc_html_e( 'This path should be relative to the root of this WordPress installation (or the sub-site, if you are running a multi-site). Appending a (*) wildcard character will match all requests with the base. Warning: Enabling regular expressions will disable wildcards and completely change the way the * symbol is interpretted.', 'redirect-url' ); ?></p>

		<p>
			<label for="cleancoded_redirect_rule_to"><strong><?php esc_html_e( '* Redirect To:', 'redirect-url' ); ?></strong></label><br />
			<input class="widefat" type="text" name="cleancoded_redirect_rule_to" id="cleancoded_redirect_rule_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
		</p>
		<p class="description"><?php esc_html_e( 'This can be a URL or a path relative to the root of your website (not your WordPress installation). Ending with a (*) wildcard character will append the request match to the redirect.', 'redirect-url' ); ?></p>

		<p>
			<label for="cleancoded_redirect_rule_status_code"><strong><?php esc_html_e( '* HTTP Status Code:', 'redirect-url' ); ?></strong></label>
			<select name="cleancoded_redirect_rule_status_code" id="cleancoded_redirect_rule_status_code">
				<?php foreach ( cleancoded_get_valid_status_codes() as $code ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $status_code, $code ); ?>><?php echo esc_html( $code . ' ' . $this->status_code_labels[ $code ] ); ?></option>
				<?php endforeach; ?>
			</select>
			<em><?php esc_html_e( "If you don't know what this is, leave it as is.", 'redirect-url' ); ?></em>
		</p>

		<p>
			<label for="cleancoded_redirect_rule_notes"><strong><?php esc_html_e( 'Notes:', 'redirect-url' ); ?></strong></label>
			<textarea name="cleancoded_redirect_rule_notes" id="cleancoded_redirect_rule_notes" class="widefat"><?php echo esc_attr( $redirect_notes ); ?></textarea>
			<em><?php esc_html_e( 'Optionally leave notes on this redirect e.g. why was it created.', 'redirect-url' ); ?></em>
		</p>
		<?php
	}

	/**
	 * Return a permalink for a redirect post, which is the "redirect from"
	 * URL for that redirect.
	 *
	 * @since 1.7
	 * @param string $permalink The permalink
	 * @param object $post A Post object
	 * @uses home_url, get_post_meta
	 * @return string The permalink
	 */
	public function filter_post_type_link( $permalink, $post ) {
		if ( 'redirect_rule' !== $post->post_type ) {
			return $permalink;
		}

		// We can't do anything to provide a permalink
		// for regex enabled redirects.
		if ( get_post_meta( $post->ID, '_redirect_rule_from_regex', true ) ) {
			return $permalink;
		}

		// We can't do anything if there is a wildcard in the redirect from
		$redirect_from = get_post_meta( $post->ID, '_redirect_rule_from', true );
		if ( false !== strpos( $redirect_from, '*' ) ) {
			return $permalink;
		}

		// Use default permalink if no $redirect_from exists - this prevents duplicate GUIDs
		if ( empty( $redirect_from ) ) {
			return $permalink;
		}

		return home_url( $redirect_from );
	}

	/**
	 * Return singleton instance of class
	 *
	 * @return object
	 * @since 1.8
	 */
	public static function factory() {
		static $instance = false;

		if ( ! $instance ) {
			$instance = new self();
			$instance->setup();
		}

		return $instance;
	}
}
