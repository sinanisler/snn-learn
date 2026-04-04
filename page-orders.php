<?php

/**
 * Simple Page Ordering for functions.php
 * Drag and drop reordering of pages and hierarchical post types
 */

if ( ! class_exists( 'Simple_Page_Ordering' ) ) :

class Simple_Page_Ordering {

	public static function get_instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
			self::add_actions();
		}
		return $instance;
	}

	public function __construct() {}

	public static function add_actions() {
		add_action( 'load-edit.php', array( __CLASS__, 'load_edit_screen' ) );
		add_action( 'wp_ajax_simple_page_ordering', array( __CLASS__, 'ajax_simple_page_ordering' ) );
		add_action( 'wp_ajax_reset_simple_page_ordering', array( __CLASS__, 'ajax_reset_simple_page_ordering' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_api_init' ) );
	}

	/**
	 * Returns the list of post types allowed for ordering, or null if the
	 * setting has never been saved (meaning: fall back to original logic).
	 */
	public static function get_allowed_post_types() {
		$allowed = get_option( 'spo_allowed_post_types', null );
		if ( null === $allowed ) {
			return null; // Not configured yet — use legacy sortable detection.
		}
		return (array) $allowed;
	}

	private static function is_post_type_sortable( $post_type = 'post' ) {
		$allowed = self::get_allowed_post_types();

		if ( null !== $allowed ) {
			$sortable = in_array( $post_type, $allowed, true );
		} else {
			$sortable = ( post_type_supports( $post_type, 'page-attributes' ) || is_post_type_hierarchical( $post_type ) );
		}

		return apply_filters( 'simple_page_ordering_is_sortable', $sortable, $post_type );
	}

	/**
	 * Save page-ordering settings from the snn-learn settings form POST.
	 * Call this inside the existing snn_learn_settings_page() POST handler.
	 */
	public static function handle_settings_save() {
		$raw = isset( $_POST['spo_allowed_post_types'] ) ? (array) wp_unslash( $_POST['spo_allowed_post_types'] ) : array();
		$public_post_types = array_keys( get_post_types( array( 'public' => true ) ) );
		$clean = array_values( array_intersect( array_map( 'sanitize_key', $raw ), $public_post_types ) );
		update_option( 'spo_allowed_post_types', $clean );
	}

	/**
	 * Render the post-type checkboxes section.
	 * Call this inside the existing snn-learn settings <form> before submit_button().
	 */
	public static function render_settings_section() {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$allowed    = (array) get_option( 'spo_allowed_post_types', array() );
		?>
		<hr style="margin:32px 0 24px;border-color:#e5e7eb">
		<h2 style="font-size:15px;font-weight:700;color:#111827;margin:0 0 4px">Page Ordering</h2>
		<p style="color:#6b7280;font-size:13px;margin:0 0 20px">
			Select which post types get drag-and-drop ordering in the admin list view.
			Only checked types will show the drag handle and the &ldquo;Sort by Order&rdquo; link.
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Enabled Post Types</th>
				<td>
					<?php foreach ( $post_types as $post_type ) : ?>
						<label style="display:block;margin-bottom:6px;">
							<input type="checkbox"
								name="spo_allowed_post_types[]"
								value="<?php echo esc_attr( $post_type->name ); ?>"
								<?php checked( in_array( $post_type->name, $allowed, true ) ); ?>>
							<strong><?php echo esc_html( $post_type->label ); ?></strong>
							<code style="font-size:11px;color:#666;margin-left:4px;"><?php echo esc_html( $post_type->name ); ?></code>
						</label>
					<?php endforeach; ?>
					<p class="description" style="margin-top:8px;">
						If nothing is checked, the default behaviour applies (post types that support <code>page-attributes</code> or are hierarchical).
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function load_edit_screen() {
		$screen    = get_current_screen();
		$post_type = $screen->post_type;

		if ( ! self::is_post_type_sortable( $post_type ) || ! self::check_edit_others_caps( $post_type ) ) {
			return;
		}

		add_filter( 'views_' . $screen->id, array( __CLASS__, 'sort_by_order_link' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_action( 'wp', array( __CLASS__, 'wp' ) );
		add_action( 'admin_head', array( __CLASS__, 'admin_head' ) );
	}

	public static function filter_query( $query ) {
		if ( ! $query->is_main_query() ) {
			return;
		}

		$is_simple_page_ordering = isset( $_GET['id'] ) && 'simple-page-ordering' === $_GET['id'];
		if ( $is_simple_page_ordering ) {
			$query->set( 'posts_per_page', -1 );
		}
	}

	public static function wp() {
		$orderby   = get_query_var( 'orderby' );
		$screen    = get_current_screen();
		$post_type = $screen->post_type ?? 'post';

		if ( ( is_string( $orderby ) && 0 === strpos( $orderby, 'menu_order' ) ) || ( isset( $orderby['menu_order'] ) && 'ASC' === $orderby['menu_order'] ) ) {
			add_action( 'admin_footer', array( __CLASS__, 'admin_scripts' ) );
			add_action( 'admin_head', array( __CLASS__, 'admin_styles' ) );
		}
	}

	public static function admin_styles() {
		?>
		<style>
		body.folded .spo-updating-row .check-column { padding-left: 9px; }
		.spo-updating-row .check-column .spinner { display: inline-block; margin: 0; }
		.spo-updating { opacity: 0.5; pointer-events: none; }
		.spo-dragging { opacity: 0.5; background: #f0f0f1; }
		.spo-drag-over {
			position: relative;
		}
		.spo-drag-over::before {
			content: '';
			position: absolute;
			top: -2px;
			left: 0;
			right: 0;
			height: 4px;
			background: #2271b1;
			box-shadow: 0 0 8px rgba(34, 113, 177, 0.5);
			z-index: 999;
			border-radius: 2px;
			animation: spo-pulse 0.5s ease-in-out infinite alternate;
		}
		@keyframes spo-pulse {
			from { opacity: 0.7; }
			to { opacity: 1; }
		}
		.wp-list-table tbody tr { cursor: move; }
		.wp-list-table tbody tr:hover { background: #f6f7f7; }
		</style>
		<?php
	}

	public static function admin_scripts() {
		$screen = get_current_screen();
		$post_type = $screen->post_type ?? 'post';
		$nonce = wp_create_nonce( 'simple-page-ordering-nonce' );
		?>
		<script>
		(function() {
			'use strict';
			
			var spoData = {
				nonce: '<?php echo esc_js( $nonce ); ?>',
				confirmMsg: <?php echo wp_json_encode( sprintf( __( 'Are you sure you want to reset the ordering of the "%s" post type?' ), $post_type ) ); ?>,
				ajaxUrl: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>'
			};
			
			var draggedElement = null;
			var isUpdating = false;
			
			function updateSimpleOrderingCallback(response) {
				if (response === 'children') {
					window.location.reload();
					return;
				}
				
				try {
					var changes = JSON.parse(response);
					var newPos = changes.new_pos;
					
					for (var key in newPos) {
						if (key === 'next') continue;
						
						var inlineKey = document.getElementById('inline_' + key);
						if (inlineKey !== null && newPos.hasOwnProperty(key)) {
							var domMenuOrder = inlineKey.querySelector('.menu_order');
							
							if (undefined !== newPos[key].menu_order) {
								if (domMenuOrder !== null) {
									domMenuOrder.textContent = newPos[key].menu_order;
								}
								
								var domPostParent = inlineKey.querySelector('.post_parent');
								if (domPostParent !== null) {
									domPostParent.textContent = newPos[key].post_parent;
								}
								
								var postTitle = null;
								var domPostTitle = inlineKey.querySelector('.post_title');
								if (domPostTitle !== null) {
									postTitle = domPostTitle.innerHTML;
									postTitle = postTitle.replace(/<img[^>]*class="emoji"[^>]*alt="([^"]*)"[^>]*>/g, '$1');
								}
								
								var dashes = 0;
								while (dashes < newPos[key].depth) {
									postTitle = '&mdash; ' + postTitle;
									dashes++;
								}
								
								var domRowTitle = inlineKey.parentNode.querySelector('.row-title');
								if (domRowTitle !== null && postTitle !== null) {
									domRowTitle.innerHTML = postTitle;
								}
							} else if (domMenuOrder !== null) {
								domMenuOrder.textContent = newPos[key];
							}
						}
					}
					
					if (changes.next) {
						var formData = new FormData();
						formData.append('action', 'simple_page_ordering');
						formData.append('id', changes.next.id);
						formData.append('previd', changes.next.previd);
						formData.append('nextid', changes.next.nextid);
						formData.append('start', changes.next.start);
						formData.append('_wpnonce', spoData.nonce);
						formData.append('excluded', JSON.stringify(changes.next.excluded));
						
						fetch(spoData.ajaxUrl, {
							method: 'POST',
							body: formData
						})
						.then(function(response) { return response.text(); })
						.then(updateSimpleOrderingCallback);
					} else {
						var updatingRows = document.querySelectorAll('.spo-updating-row');
						updatingRows.forEach(function(row) {
							row.classList.remove('spo-updating-row');
							var spinner = row.querySelector('.check-column .spinner');
							if (spinner) {
								spinner.classList.remove('is-active');
							}
						});
						
						var tbody = document.querySelector('.wp-list-table tbody');
						if (tbody) {
							tbody.classList.remove('spo-updating');
						}
						isUpdating = false;
					}
				} catch (e) {
					console.error('Error processing response:', e);
					window.location.reload();
				}
			}
			
			function initDragAndDrop() {
				var tbody = document.querySelector('.wp-list-table tbody');
				if (!tbody) return;

				var rows = tbody.querySelectorAll('tr');
				var currentDropTarget = null;

				rows.forEach(function(row) {
					if (row.classList.contains('inline-edit-row')) return;

					row.setAttribute('draggable', 'true');

					row.addEventListener('dragstart', function(e) {
						if (isUpdating) {
							e.preventDefault();
							return;
						}

						if (e.target.tagName === 'INPUT' ||
							e.target.tagName === 'TEXTAREA' ||
							e.target.tagName === 'SELECT' ||
							e.target.tagName === 'BUTTON' ||
							e.target.tagName === 'A') {
							e.preventDefault();
							return;
						}

						draggedElement = this;
						this.classList.add('spo-dragging');
						e.dataTransfer.effectAllowed = 'move';
						e.dataTransfer.setData('text/html', this.innerHTML);
					});

					row.addEventListener('dragend', function() {
						this.classList.remove('spo-dragging');
						currentDropTarget = null;

						var allRows = tbody.querySelectorAll('tr');
						allRows.forEach(function(r) {
							r.classList.remove('spo-drag-over');
						});
					});

					row.addEventListener('dragover', function(e) {
						if (e.preventDefault) {
							e.preventDefault();
						}
						e.dataTransfer.dropEffect = 'move';

						if (this !== draggedElement && this !== currentDropTarget) {
							var allRows = tbody.querySelectorAll('tr');
							allRows.forEach(function(r) {
								r.classList.remove('spo-drag-over');
							});

							this.classList.add('spo-drag-over');
							currentDropTarget = this;
						}

						return false;
					});

					row.addEventListener('dragenter', function(e) {
						e.preventDefault();
					});

					row.addEventListener('dragleave', function(e) {
						if (e.target === this && !this.contains(e.relatedTarget)) {
							this.classList.remove('spo-drag-over');
							if (currentDropTarget === this) {
								currentDropTarget = null;
							}
						}
					});

					row.addEventListener('drop', function(e) {
						if (e.stopPropagation) {
							e.stopPropagation();
						}

						if (draggedElement !== this) {
							var allRows = Array.from(tbody.querySelectorAll('tr:not(.inline-edit-row)'));
							var draggedIndex = allRows.indexOf(draggedElement);
							var targetIndex = allRows.indexOf(this);

							if (draggedIndex !== -1 && targetIndex !== -1) {
								if (draggedIndex < targetIndex) {
									this.parentNode.insertBefore(draggedElement, this.nextSibling);
								} else {
									this.parentNode.insertBefore(draggedElement, this);
								}

								handleReorder(draggedElement);
								fixRowColors();
							}
						}

						this.classList.remove('spo-drag-over');
						currentDropTarget = null;
						return false;
					});
				});

				document.addEventListener('keydown', function(e) {
					if (e.key === 'Escape' && draggedElement) {
						draggedElement.classList.remove('spo-dragging');
						draggedElement = null;
					}
				});
			}
			
			function handleReorder(row) {
				if (isUpdating) return;
				
				isUpdating = true;
				var tbody = row.parentNode;
				tbody.classList.add('spo-updating');
				row.classList.add('spo-updating-row');
				
				var spinner = row.querySelector('.check-column');
				if (spinner) {
					spinner.classList.add('spinner', 'is-active');
				}
				
				var postId = row.id.replace('post-', '');
				var prevId = false;
				var nextId = false;
				
				var prevRow = row.previousElementSibling;
				while (prevRow && prevRow.classList.contains('inline-edit-row')) {
					prevRow = prevRow.previousElementSibling;
				}
				if (prevRow) {
					prevId = prevRow.id.replace('post-', '');
				}
				
				var nextRow = row.nextElementSibling;
				while (nextRow && nextRow.classList.contains('inline-edit-row')) {
					nextRow = nextRow.nextElementSibling;
				}
				if (nextRow) {
					nextId = nextRow.id.replace('post-', '');
				}
				
				var formData = new FormData();
				formData.append('action', 'simple_page_ordering');
				formData.append('id', postId);
				formData.append('previd', prevId);
				formData.append('nextid', nextId);
				formData.append('_wpnonce', spoData.nonce);
				
				fetch(spoData.ajaxUrl, {
					method: 'POST',
					body: formData
				})
				.then(function(response) { return response.text(); })
				.then(updateSimpleOrderingCallback)
				.catch(function(error) {
					console.error('Error:', error);
					window.location.reload();
				});
			}
			
			function fixRowColors() {
				var rows = document.querySelectorAll('tr.iedit');
				rows.forEach(function(row, index) {
					if (index % 2 === 0) {
						row.classList.add('alternate');
					} else {
						row.classList.remove('alternate');
					}
				});
			}
			
			// Reset button handler
			document.addEventListener('DOMContentLoaded', function() {
				initDragAndDrop();
				
				var resetBtn = document.getElementById('simple-page-ordering-reset');
				if (resetBtn) {
					resetBtn.addEventListener('click', function(e) {
						e.preventDefault();
						var postType = this.getAttribute('data-posttype');
						
						if (window.confirm(spoData.confirmMsg)) {
							var formData = new FormData();
							formData.append('action', 'reset_simple_page_ordering');
							formData.append('post_type', postType);
							formData.append('_wpnonce', spoData.nonce);
							
							fetch(spoData.ajaxUrl, {
								method: 'POST',
								body: formData
							})
							.then(function() {
								window.location.reload();
							});
						}
					});
				}
			});
		})();
		</script>
		<?php
	}

	public static function admin_head() {
		$screen = get_current_screen();
		$post_type = $screen->post_type ?? 'post';

		$screen->add_help_tab( array(
			'id'      => 'simple_page_ordering_help_tab',
			'title'   => 'Simple Page Ordering',
			'content' => sprintf(
				'<p>%s</p><a href="#" id="simple-page-ordering-reset" data-posttype="%s">%s</a>',
				'To reposition an item, simply drag and drop the row by "clicking and holding" it anywhere (outside of the links and form controls) and moving it to its new position.',
				esc_attr( get_query_var( 'post_type' ) ),
				sprintf( 'Reset %s order', $post_type )
			),
		) );
	}

	public static function ajax_simple_page_ordering() {
		if ( empty( $_POST['id'] ) || ( ! isset( $_POST['previd'] ) && ! isset( $_POST['nextid'] ) ) ) {
			die( -1 );
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'simple-page-ordering-nonce' ) ) {
			die( -1 );
		}

		$post_id  = (int) $_POST['id'];
		$previd   = empty( $_POST['previd'] ) ? false : (int) $_POST['previd'];
		$nextid   = empty( $_POST['nextid'] ) ? false : (int) $_POST['nextid'];
		$start    = empty( $_POST['start'] ) ? 1 : (int) $_POST['start'];
		$excluded = empty( $_POST['excluded'] ) ? array( $_POST['id'] ) : array_filter( (array) json_decode( $_POST['excluded'] ), 'intval' );

		$post = get_post( $post_id );
		if ( ! $post || ! self::check_edit_others_caps( $post->post_type ) ) {
			die( -1 );
		}

		$result = self::page_ordering( $post_id, $previd, $nextid, $start, $excluded );
		if ( is_wp_error( $result ) ) {
			die( -1 );
		}

		die( wp_json_encode( $result ) );
	}

	public static function ajax_reset_simple_page_ordering() {
		global $wpdb;

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'simple-page-ordering-nonce' ) ) {
			die( -1 );
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '';
		if ( empty( $post_type ) || ! self::check_edit_others_caps( $post_type ) ) {
			die( -1 );
		}

		$wpdb->update( $wpdb->posts, array( 'menu_order' => 0 ), array( 'post_type' => $post_type ), array( '%d' ), array( '%s' ) );
		die( 0 );
	}

	public static function page_ordering( $post_id, $previd, $nextid, $start, $excluded ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid', 'Missing mandatory parameters.' );
		}

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			error_reporting( 0 );
		}

		global $wp_version;

		$previd   = empty( $previd ) ? false : (int) $previd;
		$nextid   = empty( $nextid ) ? false : (int) $nextid;
		$start    = empty( $start ) ? 1 : (int) $start;
		$excluded = empty( $excluded ) ? array( $post_id ) : array_filter( (array) $excluded, 'intval' );

		$new_pos = array();
		$return_data = new stdClass();

		do_action( 'simple_page_ordering_pre_order_posts', $post, $start );

		$parent_id = $post->post_parent;
		$next_post_parent = $nextid ? wp_get_post_parent_id( $nextid ) : false;

		if ( $previd === $next_post_parent ) {
			$parent_id = $next_post_parent;
		} elseif ( $next_post_parent !== $parent_id ) {
			$prev_post_parent = $previd ? wp_get_post_parent_id( $previd ) : false;
			if ( $prev_post_parent !== $parent_id ) {
				$parent_id = ( false !== $prev_post_parent ) ? $prev_post_parent : $next_post_parent;
			}
		}

		if ( $next_post_parent !== $parent_id ) {
			$nextid = false;
		}

		$max_sortable_posts = (int) apply_filters( 'simple_page_ordering_limit', 50 );
		if ( $max_sortable_posts < 5 ) {
			$max_sortable_posts = 50;
		}

		$post_stati = get_post_stati( array( 'show_in_admin_all_list' => true ) );

		$siblings_query = array(
			'depth'                  => 1,
			'posts_per_page'         => $max_sortable_posts,
			'post_type'              => $post->post_type,
			'post_status'            => $post_stati,
			'post_parent'            => $parent_id,
			'post__not_in'           => $excluded,
			'orderby'                => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'suppress_filters'       => true,
			'ignore_sticky_posts'    => true,
		);

		if ( version_compare( $wp_version, '4.0', '<' ) ) {
			$siblings_query['orderby'] = 'menu_order title';
			$siblings_query['order']   = 'ASC';
		}

		$siblings = new WP_Query( $siblings_query );

		remove_action( 'post_updated', 'wp_save_post_revision' );

		foreach ( $siblings->posts as $sibling ) :
			if ( $sibling->ID === $post->ID ) {
				continue;
			}

			if ( $nextid === $sibling->ID ) {
				wp_update_post( array(
					'ID'          => $post->ID,
					'menu_order'  => $start,
					'post_parent' => $parent_id,
				) );

				$ancestors = get_post_ancestors( $post->ID );
				$new_pos[ $post->ID ] = array(
					'menu_order'  => $start,
					'post_parent' => $parent_id,
					'depth'       => count( $ancestors ),
				);

				$start++;
			}

			if ( isset( $new_pos[ $post->ID ] ) && $sibling->menu_order >= $start ) {
				$return_data->next = false;
				break;
			}

			if ( $sibling->menu_order !== $start ) {
				wp_update_post( array(
					'ID'         => $sibling->ID,
					'menu_order' => $start,
				) );
			}
			$new_pos[ $sibling->ID ] = $start;
			$start++;

			if ( ! $nextid && $previd === $sibling->ID ) {
				wp_update_post( array(
					'ID'          => $post->ID,
					'menu_order'  => $start,
					'post_parent' => $parent_id,
				) );

				$ancestors = get_post_ancestors( $post->ID );
				$new_pos[ $post->ID ] = array(
					'menu_order'  => $start,
					'post_parent' => $parent_id,
					'depth'       => count( $ancestors ),
				);
				$start++;
			}

		endforeach;

		if ( ! isset( $return_data->next ) && $siblings->max_num_pages > 1 ) {
			$return_data->next = array(
				'id'       => $post->ID,
				'previd'   => $previd,
				'nextid'   => $nextid,
				'start'    => $start,
				'excluded' => array_merge( array_keys( $new_pos ), $excluded ),
			);
		} else {
			$return_data->next = false;
		}

		do_action( 'simple_page_ordering_ordered_posts', $post, $new_pos );

		if ( ! $return_data->next ) {
			$children = new WP_Query( array(
				'posts_per_page'         => 1,
				'post_type'              => $post->post_type,
				'post_status'            => $post_stati,
				'post_parent'            => $post->ID,
				'fields'                 => 'ids',
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'ignore_sticky'          => true,
				'no_found_rows'          => true,
			) );

			if ( $children->have_posts() ) {
				return 'children';
			}
		}

		$return_data->new_pos = $new_pos;
		return $return_data;
	}

	public static function sort_by_order_link( $views ) {
		$class = ( get_query_var( 'orderby' ) === 'menu_order title' ) ? 'current' : '';
		$query_string = remove_query_arg( array( 'orderby', 'order' ) );
		
		if ( ! is_post_type_hierarchical( get_post_type() ) ) {
			$query_string = add_query_arg( 'orderby', 'menu_order title', $query_string );
			$query_string = add_query_arg( 'order', 'asc', $query_string );
			$query_string = add_query_arg( 'id', 'simple-page-ordering', $query_string );
		}
		
		$views['byorder'] = sprintf( '<a href="%s" class="%s">%s</a>', esc_url( $query_string ), $class, 'Sort by Order' );
		return $views;
	}

	private static function check_edit_others_caps( $post_type ) {
		$post_type_object = get_post_type_object( $post_type );
		$edit_others_cap = empty( $post_type_object ) ? 'edit_others_' . $post_type . 's' : $post_type_object->cap->edit_others_posts;
		return apply_filters( 'simple_page_ordering_edit_rights', current_user_can( $edit_others_cap ), $post_type );
	}

	public static function rest_api_init() {
		register_rest_route( 'simple-page-ordering/v1', 'page_ordering', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_page_ordering' ),
			'permission_callback' => array( __CLASS__, 'rest_page_ordering_permissions_check' ),
			'args'                => array(
				'id'      => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
				'previd'  => array( 'required' => true, 'type' => array( 'boolean', 'integer' ) ),
				'nextid'  => array( 'required' => true, 'type' => array( 'boolean', 'integer' ) ),
				'start'   => array( 'default' => 1, 'type' => 'integer' ),
				'exclude' => array( 'default' => array(), 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			),
		) );
	}

	public static function rest_page_ordering_permissions_check( $request ) {
		$post_id = $request->get_param( 'id' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$post_type = get_post_type( $post_id );
		$post_type_obj = get_post_type_object( $post_type );

		if ( ! $post_type || empty( $post_type_obj ) || empty( $post_type_obj->show_in_rest ) ) {
			return false;
		}

		if ( ! self::is_post_type_sortable( $post_type ) ) {
			return new WP_Error( 'not_enabled', 'This post type is not sortable.' );
		}

		return true;
	}

	public static function rest_page_ordering( $request ) {
		$post_id  = (int) $request->get_param( 'id' );
		$previd   = empty( $request->get_param( 'previd' ) ) ? false : (int) $request->get_param( 'previd' );
		$nextid   = empty( $request->get_param( 'nextid' ) ) ? false : (int) $request->get_param( 'nextid' );
		$start    = empty( $request->get_param( 'start' ) ) ? 1 : (int) $request->get_param( 'start' );
		$excluded = empty( $request->get_param( 'excluded' ) ) ? array( $request->get_param( 'id' ) ) : array_filter( (array) json_decode( $request->get_param( 'excluded' ) ), 'intval' );

		if ( false === $post_id || ( false === $previd && false === $nextid ) ) {
			return new WP_Error( 'invalid', 'Missing mandatory parameters.' );
		}

		$page_ordering = self::page_ordering( $post_id, $previd, $nextid, $start, $excluded );

		if ( is_wp_error( $page_ordering ) ) {
			return $page_ordering;
		}

		return new WP_REST_Response( array(
			'status'        => 200,
			'response'      => 'success',
			'body_response' => $page_ordering,
		) );
	}
}

Simple_Page_Ordering::get_instance();

endif;
