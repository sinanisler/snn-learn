<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =============================================================================
 * SNN LEARN — COURSE MANAGEMENT
 * page-courses.php
 * =============================================================================
 *
 * A dedicated admin page for managing courses, chapters, and lessons via a
 * drag-and-drop tree UI. It uses the same single-post-type hierarchy model as
 * the rest of the plugin:
 *
 *   course  = post_parent = 0          (top-level)
 *   chapter = post_parent = course ID   (direct child of a course)
 *   lesson  = post_parent = chapter ID  (child of a chapter)
 *
 * Features:
 *   • Auto-discovery of existing courses (top-level posts of the configured
 *     course post type) on first load so the list never starts empty.
 *   • Drag-and-drop reordering with automatic menu_order persistence via AJAX.
 *   • Inline quick-create for new courses via a modal dialog.
 *   • Inline rename — click on any title to edit it in place.
 *   • Collapse/expand chapter groups.
 *   • All admin assets (Tailwind + Chart.js) already loaded by snn-learn.php.
 * =============================================================================
 */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'snn-learn',
        'Manage Courses',
        'Manage Courses',
        'edit_posts',
        'snn-learn-courses',
        'snn_learn_courses_page'
    );
} );

// ----------------------------------------------------------------
// Fetch all nodes for the JS tree
// ----------------------------------------------------------------
add_action( 'wp_ajax_snn_learn_get_tree', function () {
    check_ajax_referer( 'snn_learn_course_tree' );

    $pt         = snn_learn_get( 'course_post_type' );
    $all_posts  = get_posts( [
        'post_type'      => $pt,
        'post_status'    => [ 'publish', 'draft', 'private' ],
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
        'sortable'       => true,
        'no_found_rows'  => true,
    ] );

    // Group by parent
    $by_parent = [];
    foreach ( $all_posts as $p ) {
        $by_parent[ (int) $p->post_parent ][] = $p;
    }

    // Build flat list for JSON (avoids circular reference)
    $nodes = [];
    foreach ( $all_posts as $p ) {
        $nodes[] = [
            'id'        => (int) $p->ID,
            'parent_id' => (int) $p->post_parent,
            'title'     => $p->post_title,
            'slug'      => $p->post_name,
            'status'    => $p->post_status,
            'menu_order'=> (int) $p->menu_order,
        ];
    }

    wp_send_json_success( $nodes );
} );

// ----------------------------------------------------------------
// Save a new course
// ----------------------------------------------------------------
add_action( 'wp_ajax_snn_learn_create_course', function () {
    check_ajax_referer( 'snn_learn_course_tree' );

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $pt    = snn_learn_get( 'course_post_type' );
    $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );

    if ( ! trim( $title ) ) {
        wp_send_json_error( 'Title required' );
    }

    $id = wp_insert_post( [
        'post_type'    => $pt,
        'post_title'   => $title,
        'post_status'   => 'publish',
        'menu_order'   => 999999,
    ] );

    if ( $id && ! is_wp_error( $id ) ) {
        wp_send_json_success( [ 'id' => $id, 'title' => $title ] );
    } else {
        wp_send_json_error( $id->get_error_message() );
    }
} );

// ----------------------------------------------------------------
// Rename a course/chapter/lesson
// ----------------------------------------------------------------
add_action( 'wp_ajax_snn_learn_rename_node', function () {
    check_ajax_referer( 'snn_learn_course_tree' );

    if ( ! current_user_can( 'edit_post', (int) ( $_POST['id'] ?? 0 ) ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $id    = (int) ( $_POST['id'] ?? 0 );
    $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );

    if ( ! $id || ! trim( $title ) ) {
        wp_send_json_error( 'Invalid input' );
    }

    wp_update_post( [ 'ID' => $id, 'post_title' => $title ] );
    wp_send_json_success();
} );

// ----------------------------------------------------------------
// Delete a course/chapter/lesson (and cascade children silently)
// ----------------------------------------------------------------
add_action( 'wp_ajax_snn_learn_delete_node', function () {
    check_ajax_referer( 'snn_learn_course_tree' );

    $id = (int) ( $_POST['id'] ?? 0 );
    if ( ! $id || ! current_user_can( 'delete_post', $id ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    // Collect all descendants so we can show a warning for courses
    $pt    = snn_learn_get( 'course_post_type' );
    $post  = get_post( $id );

    if ( ! $post ) {
        wp_send_json_error( 'Post not found' );
    }

    // For courses, check if there are active enrollments
    if ( $post->post_parent == 0 ) {
        global $wpdb;
        $t    = $wpdb->prefix . 'snn_learn_enrollments';
        $cnt  = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE course_id = %d",
            $id
        ) );
        if ( $cnt > 0 ) {
            wp_send_json_error( "This course has $cnt enrollment(s). Delete them from the Dashboard first." );
            return;
        }
    }

    // Delete descendants recursively
    $delete_ids = [];
    $queue = [ $id ];
    while ( ! empty( $queue ) ) {
        $curr = array_shift( $queue );
        $children = get_posts( [
            'post_type'      => $pt,
            'post_parent'    => $curr,
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );
        foreach ( $children as $c ) {
            $queue[] = $c;
        }
        $delete_ids[] = $curr;
    }

    foreach ( $delete_ids as $del_id ) {
        wp_delete_post( $del_id, true );
    }

    wp_send_json_success( [ 'deleted' => count( $delete_ids ) ] );
} );

// ----------------------------------------------------------------
// Reorder: called after drag-and-drop — persists new menu_order
// ----------------------------------------------------------------
add_action( 'wp_ajax_snn_learn_reorder_nodes', function () {
    check_ajax_referer( 'snn_learn_course_tree' );

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $ordering = isset( $_POST['ordering'] ) ? (array) wp_unslash( $_POST['ordering'] ) : [];

    foreach ( $ordering as $item ) {
        $item = array_map( 'intval', (array) $item );
        if ( empty( $item['id'] ) ) continue;

        $post_parent = isset( $item['parent_id'] ) ? $item['parent_id'] : 0;
        $menu_order  = isset( $item['order'] )     ? $item['order']     : 0;

        wp_update_post( [
            'ID'          => $item['id'],
            'menu_order'  => $menu_order,
            'post_parent' => $post_parent,
        ] );
    }

    wp_send_json_success();
} );

// ----------------------------------------------------------------
// Page render
// ----------------------------------------------------------------
function snn_learn_courses_page() {
    $pt = snn_learn_get( 'course_post_type' );
    $pt_obj = get_post_type_object( $pt );
    $pt_label = $pt_obj ? $pt_obj->label : ucfirst( $pt );
    ?>
    <div class="snn-learn-courses wrap" style="max-width:1100px">
        <div class="p-6">

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
                <div>
                    <h1 style="margin:0;font-size:22px;font-weight:800;color:#111827">Manage Courses</h1>
                    <p style="margin:4px 0 0;color:#6b7280;font-size:13px">
                        Post type: <code style="background:#eff6ff;color:#2563eb;padding:2px 6px;border-radius:4px"><?= esc_html( $pt ) ?></code>
                        &mdash; drag items to reorder. Click any title to rename. Use the + buttons to add chapters/lessons.
                    </p>
                </div>
                <div style="display:flex;gap:8px">
                    <button id="snn-cm-add-course" class="button button-primary" style="background:#2563eb;font-weight:600">
                        + New Course
                    </button>
                </div>
            </div>

            <!-- Tree container -->
            <div id="snn-cm-tree" style="background:#fff;border-radius:10px;border:1px solid #e5e7eb;overflow:hidden">
                <div style="padding:40px 24px;text-align:center;color:#9ca3af;font-size:14px" id="snn-cm-loading">
                    Loading&hellip;
                </div>
            </div>

            <!-- Empty state (hidden until no items) -->
            <div id="snn-cm-empty" style="display:none;background:#fff;border-radius:10px;border:1px solid #e5e7eb;padding:40px 24px;text-align:center">
                <div style="font-size:40px;margin-bottom:12px">&#127891;</div>
                <h3 style="margin:0 0 6px;color:#374151;font-size:16px;font-weight:700">No courses yet</h3>
                <p style="margin:0 0 20px;color:#6b7280;font-size:13px">Click <strong>+ New Course</strong> to create your first course.</p>
                <button id="snn-cm-add-first" class="button button-primary" style="background:#2563eb">+ New Course</button>
            </div>

        </div>
    </div>

    <!-- New Course modal -->
    <div id="snn-cm-modal" style="display:none;position:fixed;inset:0;z-index:100000;align-items:center;justify-content:center;background:rgba(0,0,0,0.4)"
         onclick="if(event.target===this)document.getElementById('snn-cm-modal').style.display='none'">
        <div style="background:#fff;border-radius:10px;padding:28px;width:420px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
            <h3 style="margin:0 0 20px;font-size:16px;font-weight:700;color:#111827">Create New Course</h3>
            <input type="text" id="snn-cm-modal-title" placeholder="Course title" autofocus
                   style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;margin-bottom:14px;box-sizing:border-box;font-size:14px"
                   onkeydown="if(event.key==='Enter')document.getElementById('snn-cm-modal-save').click()">
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button onclick="document.getElementById('snn-cm-modal').style.display='none'"
                        style="padding:9px 16px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:13px">Cancel</button>
                <button id="snn-cm-modal-save" class="button button-primary"
                        style="background:#2563eb;padding:9px 16px;border:none;border-radius:6px;color:#fff;cursor:pointer;font-size:13px;font-weight:600">
                    Create Course
                </button>
            </div>
        </div>
    </div>

    <!-- Context action bar (appears on selection) -->
    <div id="snn-cm-actionbar" style="display:none;position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#1f2937;color:#fff;padding:10px 20px;border-radius:50px;box-shadow:0 4px 20px rgba(0,0,0,0.25);z-index:99999;font-size:13px;gap:12px;align-items:center"
         class="snn-cm-actionbar">
        <span id="snn-cm-sel-label" style="font-weight:600"></span>
        <div style="display:flex;gap:8px">
            <button id="snn-cm-action-add-ch"  class="button" style="background:#374151;border:none;color:#fff;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px">+ Add Chapter</button>
            <button id="snn-cm-action-add-less" class="button" style="background:#374151;border:none;color:#fff;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px">+ Add Lesson</button>
            <button id="snn-cm-action-rename"  class="button" style="background:#374151;border:none;color:#fff;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px">Rename</button>
            <button id="snn-cm-action-delete"  class="button" style="background:#dc2626;border:none;color:#fff;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px">Delete</button>
            <button onclick="document.getElementById('snn-cm-actionbar').style.display='none'"
                    style="background:transparent;border:none;color:#9ca3af;cursor:pointer;font-size:16px;padding:0 4px">&#10005;</button>
        </div>
    </div>

    <script>
    (function() {
        'use strict';

        var nonce   = <?= wp_json_encode( wp_create_nonce( 'snn_learn_course_tree' ) ) ?>;
        var postType = <?= wp_json_encode( $pt ) ?>;
        var isRTL    = <?= wp_json_encode( is_rtl() ) ?>;
        var ajaxUrl  = <?= wp_json_encode( admin_url( 'admin-ajax.php' ) ) ?>;

        var nodes        = [];
        var selectedId  = null;
        var dragSrc      = null;
        var dragGhost   = null;

        // ------------------------------------------------------------
        // Build the tree in memory
        // ------------------------------------------------------------
        function buildTree() {
            var byParent = {};
            nodes.forEach(function(n) {
                var pid = n.parent_id || 0;
                if (!byParent[pid]) byParent[pid] = [];
                byParent[pid].push(n);
            });
            Object.keys(byParent).forEach(function(pid) {
                byParent[pid].sort(function(a, b) { return a.menu_order - b.menu_order; });
            });
            return byParent;
        }

        // ------------------------------------------------------------
        // Render HTML
        // ------------------------------------------------------------
        function render() {
            var byParent = buildTree();
            var treeEl   = document.getElementById('snn-cm-tree');
            var loadingEl = document.getElementById('snn-cm-loading');
            var emptyEl  = document.getElementById('snn-cm-empty');

            if (nodes.length === 0) {
                treeEl.innerHTML = '';
                loadingEl.style.display = 'none';
                emptyEl.style.display = 'block';
                return;
            }

            emptyEl.style.display = 'none';
            loadingEl.style.display = 'none';

            var html = '<div style="padding:0 4px">';
            html += renderLevel(byParent, 0, 0);
            html += '</div>';
            treeEl.innerHTML = html;

            attachDragListeners();
        }

        function renderLevel(byParent, parentId, depth) {
            var items = byParent[parentId] || [];
            if (!items.length) return '';

            var indent = depth * 24;
            var html   = '<ul style="list-style:none;margin:0;padding:0">';

            items.forEach(function(node) {
                var isCourse = node.parent_id === 0;
                var isChapter = !isCourse && !byParent[node.parent_id];
                var type = isCourse ? 'course' : (isChapter ? 'chapter' : 'lesson');

                var typeColor = type === 'course' ? '#2563eb' : type === 'chapter' ? '#7c3aed' : '#059669';
                var typeBg    = type === 'course' ? '#eff6ff' : type === 'chapter' ? '#f5f3ff' : '#ecfdf5';
                var statusColor = node.status === 'publish' ? '#16a34a' : node.status === 'draft' ? '#d97706' : '#9ca3af';

                var dragHandle = '<span class="snn-drag-handle" draggable="true" data-id="' + node.id + '" style="cursor:grab;padding:4px 6px;color:#d1d5db;font-size:14px;line-height:1" title="Drag to reorder">&#9776;</span>';

                html += '<li class="snn-cm-node" data-id="' + node.id + '" data-type="' + type + '" style="margin-bottom:4px">';
                html += '<div class="snn-cm-node-inner" data-id="' + node.id + '" style="display:flex;align-items:center;gap:6px;padding:10px 12px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;transition:box-shadow .15s;user-select:none"';
                html += ' onmouseenter="this.style.boxShadow=\'0 2px 8px rgba(0,0,0,0.08)\'"';
                html += ' onmouseleave="this.style.boxShadow=\'\'">';

                html += dragHandle;

                // Expand/collapse toggle for courses with children
                var children = byParent[node.id] || [];
                if (children.length > 0) {
                    html += '<button class="snn-cm-toggle" data-id="' + node.id + '" style="background:none;border:none;cursor:pointer;color:#6b7280;font-size:12px;padding:0;line-height:1;width:20px;height:20px;text-align:center;border-radius:4px">&#9658;</button>';
                } else {
                    html += '<span style="width:20px;display:inline-block"></span>';
                }

                // Status dot
                html += '<span style="width:8px;height:8px;border-radius:50%;background:' + statusColor + ';flex-shrink:0" title="' + node.status + '"></span>';

                // Type badge
                html += '<span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;background:' + typeBg + ';color:' + typeColor + ';flex-shrink:0;text-transform:uppercase">' + type + '</span>';

                // Title (click to select, dblclick to rename)
                html += '<span class="snn-cm-title" data-id="' + node.id + '" contenteditable="false"';
                html += ' ondblclick="snnCMStartRename(this)"';
                html += ' style="flex:1;font-size:14px;font-weight:' + (isCourse ? '700' : '500') + ';color:#111827;cursor:pointer;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"';
                html += '>' + escHtml(node.title) + '</span>';

                // Quick-action buttons
                html += '<div style="display:flex;gap:4px;opacity:0;transition:opacity .15s" class="snn-cm-quick-actions">';
                if (isCourse || isChapter) {
                    html += '<button class="button button-secondary snn-cm-action" data-action="add" data-id="' + node.id + '" data-for="' + (isCourse ? 'chapter' : 'lesson') + '" style="font-size:11px;padding:2px 8px;height:auto;color:#2563eb;border-color:#bfdbfe">+ ' + (isCourse ? 'Chapter' : 'Lesson') + '</button>';
                }
                html += '<button class="button button-secondary snn-cm-action" data-action="rename" data-id="' + node.id + '" style="font-size:11px;padding:2px 8px;height:auto;color:#6b7280;border-color:#e5e7eb">Rename</button>';
                html += '<button class="button button-secondary snn-cm-action" data-action="delete" data-id="' + node.id + '" style="font-size:11px;padding:2px 8px;height:auto;color:#dc2626;border-color:#fca5a5">Delete</button>';
                html += '</div>';
                html += '</div>'; // .snn-cm-node-inner

                // Recurse for children
                var childHtml = renderLevel(byParent, node.id, depth + 1);
                if (childHtml) {
                    html += '<li class="snn-cm-children" data-parent="' + node.id + '" style="padding-left:24px;display:none">' + childHtml + '</li>';
                }

                html += '</li>';
            });

            html += '</ul>';
            return html;
        }

        function escHtml(str) {
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // ------------------------------------------------------------
        // Drag & Drop
        // ------------------------------------------------------------
        function attachDragListeners() {
            document.querySelectorAll('.snn-drag-handle').forEach(function(h) {
                h.addEventListener('dragstart', function(e) {
                    dragSrc = h;
                    h.style.opacity = '0.4';
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', h.dataset.id);
                });
                h.addEventListener('dragend', function(e) {
                    h.style.opacity = '1';
                    dragSrc = null;
                    document.querySelectorAll('.snn-drag-over').forEach(function(el) {
                        el.classList.remove('snn-drag-over');
                    });
                });
            });

            document.querySelectorAll('.snn-cm-node-inner').forEach(function(inner) {
                inner.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    var li = inner.closest('.snn-cm-node');
                    if (li && li !== dragSrc) {
                        li.classList.add('snn-drag-over');
                    }
                });

                inner.addEventListener('dragleave', function(e) {
                    if (!inner.contains(e.relatedTarget)) {
                        inner.closest('.snn-cm-node').classList.remove('snn-drag-over');
                    }
                });

                inner.addEventListener('drop', function(e) {
                    e.preventDefault();
                    var li = inner.closest('.snn-cm-node');
                    if (!li) return;

                    var srcId   = parseInt(dragSrc ? dragSrc.dataset.id : 0);
                    var targetId = parseInt(li.dataset.id);

                    if (srcId && targetId && srcId !== targetId) {
                        moveNode(srcId, targetId, li);
                    }
                    li.classList.remove('snn-drag-over');
                });
            });
        }

        // Move a node to be a sibling after the target
        function moveNode(srcId, targetId, targetLi) {
            var srcNode = nodes.find(function(n) { return n.id === srcId; });
            var tgtNode = nodes.find(function(n) { return n.id === targetId; });
            if (!srcNode || !tgtNode) return;

            // Determine new parent_id and sibling order
            var newParentId = tgtNode.parent_id;
            var newOrder    = tgtNode.menu_order + 1;

            // Re-calculate menu_order for all siblings in the target level
            // Move src to after target
            srcNode.parent_id = newParentId;
            srcNode.menu_order = newOrder;

            // Increment all subsequent siblings
            nodes.forEach(function(n) {
                if (n.id !== srcId && n.parent_id === newParentId && n.menu_order >= newOrder) {
                    n.menu_order++;
                }
            });

            persistOrder();
        }

        function persistOrder() {
            var ordering = nodes.map(function(n) {
                return { id: n.id, parent_id: n.parent_id, order: n.menu_order };
            });

            var fd = new FormData();
            fd.append('action', 'snn_learn_reorder_nodes');
            fd.append('_wpnonce', nonce);
            ordering.forEach(function(item) {
                fd.append('ordering[]', JSON.stringify(item));
            });

            fetch(ajaxUrl, { method: 'POST', body: fd });

            render();
        }

        // ------------------------------------------------------------
        // Expand/Collapse
        // ------------------------------------------------------------
        document.getElementById('snn-cm-tree').addEventListener('click', function(e) {
            var toggle = e.target.closest('.snn-cm-toggle');
            if (toggle) {
                var id    = parseInt(toggle.dataset.id);
                var childLi = document.querySelector('.snn-cm-children[data-parent="' + id + '"]');
                if (childLi) {
                    var isHidden = childLi.style.display === 'none';
                    childLi.style.display = isHidden ? 'block' : 'none';
                    toggle.innerHTML = isHidden ? '&#9660;' : '&#9658;';
                }
                return;
            }

            // Quick action buttons
            var actionBtn = e.target.closest('.snn-cm-action');
            if (actionBtn) {
                var act = actionBtn.dataset.action;
                var id  = parseInt(actionBtn.dataset.id);
                if (act === 'add') {
                    var forType = actionBtn.dataset.for; // 'chapter' or 'lesson'
                    promptCreateChild(id, forType);
                } else if (act === 'rename') {
                    var titleEl = document.querySelector('.snn-cm-title[data-id="' + id + '"]');
                    if (titleEl) snnCMStartRename(titleEl);
                } else if (act === 'delete') {
                    confirmDelete(id);
                }
                return;
            }

            // Node click — select
            var inner = e.target.closest('.snn-cm-node-inner');
            if (inner && !e.target.closest('.snn-drag-handle') && !e.target.closest('.snn-cm-toggle') && !e.target.closest('.snn-cm-action')) {
                var id = parseInt(inner.dataset.id);
                selectNode(id);
            }
        });

        // ------------------------------------------------------------
        // Selection
        // ------------------------------------------------------------
        function selectNode(id) {
            selectedId = id;
            var node = nodes.find(function(n) { return n.id === id; });
            if (!node) return;

            // Highlight
            document.querySelectorAll('.snn-cm-node-inner').forEach(function(el) {
                el.style.background = (parseInt(el.dataset.id) === id) ? '#eff6ff' : '#fff';
                el.style.borderColor = (parseInt(el.dataset.id) === id) ? '#93c5fd' : '#e5e7eb';
            });

            // Show action bar
            var bar = document.getElementById('snn-cm-actionbar');
            var lbl = document.getElementById('snn-cm-sel-label');
            var isCourse = node.parent_id === 0;
            lbl.textContent = (isCourse ? 'Course' : node.parent_id === 0 ? 'Chapter' : 'Lesson') + ': ' + node.title;
            bar.style.display = 'flex';
        }

        document.getElementById('snn-cm-action-add-ch').addEventListener('click', function() {
            if (selectedId) promptCreateChild(selectedId, 'chapter');
        });
        document.getElementById('snn-cm-action-add-less').addEventListener('click', function() {
            if (selectedId) promptCreateChild(selectedId, 'lesson');
        });
        document.getElementById('snn-cm-action-rename').addEventListener('click', function() {
            if (selectedId) {
                var titleEl = document.querySelector('.snn-cm-title[data-id="' + selectedId + '"]');
                if (titleEl) snnCMStartRename(titleEl);
                document.getElementById('snn-cm-actionbar').style.display = 'none';
            }
        });
        document.getElementById('snn-cm-action-delete').addEventListener('click', function() {
            if (selectedId) confirmDelete(selectedId);
        });

        // ------------------------------------------------------------
        // Create child (chapter or lesson)
        // ------------------------------------------------------------
        function promptCreateChild(parentId, forType) {
            var label = forType === 'chapter' ? 'Chapter' : 'Lesson';
            var title = prompt('Enter ' + label + ' title:');
            if (!title || !title.trim()) return;

            var node = nodes.find(function(n) { return n.id === parentId; });
            var parentId2 = forType === 'chapter' ? parentId : (node && node.parent_id !== 0 ? node.id : parentId);

            var fd = new FormData();
            fd.append('action', 'snn_learn_create_course');
            fd.append('_wpnonce', nonce);
            fd.append('title', title);
            fd.append('post_parent', parentId2);

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success && d.data && d.data.id) {
                        nodes.push({
                            id: d.data.id,
                            parent_id: parentId2,
                            title: d.data.title,
                            slug: '',
                            status: 'publish',
                            menu_order: 999999
                        });
                        render();
                    }
                });
        }

        // ------------------------------------------------------------
        // Rename
        // ------------------------------------------------------------
        function snnCMStartRename(titleEl) {
            var id = parseInt(titleEl.dataset.id);
            var currentText = titleEl.textContent;
            titleEl.contentEditable = 'true';
            titleEl.style.outline = '2px solid #2563eb';
            titleEl.style.borderRadius = '4px';
            titleEl.style.padding = '2px 6px';

            var finish = function(save) {
                titleEl.contentEditable = 'false';
                titleEl.style.outline = 'none';
                titleEl.style.borderRadius = '0';
                titleEl.style.padding = '0';

                if (save) {
                    var newTitle = titleEl.textContent.trim();
                    if (newTitle && newTitle !== currentText) {
                        titleEl.textContent = newTitle;
                        var node = nodes.find(function(n) { return n.id === id; });
                        if (node) node.title = newTitle;

                        var fd = new FormData();
                        fd.append('action', 'snn_learn_rename_node');
                        fd.append('_wpnonce', nonce);
                        fd.append('id', id);
                        fd.append('title', newTitle);
                        fetch(ajaxUrl, { method: 'POST', body: fd });
                    } else {
                        titleEl.textContent = currentText;
                    }
                } else {
                    titleEl.textContent = currentText;
                }
            };

            titleEl.addEventListener('blur', function() { finish(true); });
            titleEl.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); titleEl.blur(); }
                if (e.key === 'Escape') { titleEl.textContent = currentText; titleEl.blur(); }
            });
            titleEl.focus();
            // Move cursor to end
            var range = document.createRange();
            range.selectNodeContents(titleEl);
            range.collapse(false);
        }

        // ------------------------------------------------------------
        // Delete
        // ------------------------------------------------------------
        function confirmDelete(id) {
            var node = nodes.find(function(n) { return n.id === id; });
            if (!node) return;

            var type = node.parent_id === 0 ? 'course' : node.parent_id === 0 ? 'chapter' : 'lesson';
            var msg = 'Delete "' + node.title + '"?';
            if (type === 'course') {
                msg += ' This will also delete all chapters and lessons.';
            }

            if (!confirm(msg)) return;

            var fd = new FormData();
            fd.append('action', 'snn_learn_delete_node');
            fd.append('_wpnonce', nonce);
            fd.append('id', id);

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success) {
                        // Remove node and all children from local array
                        var toRemove = findDescendants(id);
                        toRemove.push(id);
                        nodes = nodes.filter(function(n) { return toRemove.indexOf(n.id) === -1; });
                        selectedId = null;
                        document.getElementById('snn-cm-actionbar').style.display = 'none';
                        render();
                    } else {
                        alert('Error: ' + (d.data || 'Could not delete.'));
                    }
                });
        }

        function findDescendants(parentId) {
            var kids = nodes.filter(function(n) { return n.parent_id === parentId; });
            var result = [];
            kids.forEach(function(k) {
                result.push(k.id);
                result = result.concat(findDescendants(k.id));
            });
            return result;
        }

        // ------------------------------------------------------------
        // Modal — New Course
        // ------------------------------------------------------------
        function showNewCourseModal() {
            var modal = document.getElementById('snn-cm-modal');
            var input = document.getElementById('snn-cm-modal-title');
            modal.style.display = 'flex';
            input.value = '';
            setTimeout(function() { input.focus(); }, 50);
        }

        document.getElementById('snn-cm-add-course').addEventListener('click', showNewCourseModal);
        document.getElementById('snn-cm-add-first') && document.getElementById('snn-cm-add-first').addEventListener('click', showNewCourseModal);

        document.getElementById('snn-cm-modal-save').addEventListener('click', function() {
            var title = document.getElementById('snn-cm-modal-title').value.trim();
            if (!title) return;

            var fd = new FormData();
            fd.append('action', 'snn_learn_create_course');
            fd.append('_wpnonce', nonce);
            fd.append('title', title);
            fd.append('post_parent', 0);

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success && d.data && d.data.id) {
                        nodes.push({
                            id: d.data.id,
                            parent_id: 0,
                            title: d.data.title,
                            slug: '',
                            status: 'publish',
                            menu_order: 999999
                        });
                        document.getElementById('snn-cm-modal').style.display = 'none';
                        render();
                    } else {
                        alert('Error: ' + (d.data || 'Could not create course.'));
                    }
                });
        });

        // ------------------------------------------------------------
        // Hover reveal quick actions
        // ------------------------------------------------------------
        document.getElementById('snn-cm-tree').addEventListener('mouseover', function(e) {
            var inner = e.target.closest('.snn-cm-node-inner');
            if (inner) {
                inner.querySelectorAll('.snn-cm-quick-actions').forEach(function(el) {
                    el.style.opacity = '1';
                });
            }
        });
        document.getElementById('snn-cm-tree').addEventListener('mouseout', function(e) {
            var inner = e.target.closest('.snn-cm-node-inner');
            if (inner && !inner.contains(e.relatedTarget)) {
                inner.querySelectorAll('.snn-cm-quick-actions').forEach(function(el) {
                    el.style.opacity = '0';
                });
            }
        });

        // ------------------------------------------------------------
        // Initial load
        // ------------------------------------------------------------
        (function() {
            var fd = new FormData();
            fd.append('action', 'snn_learn_get_tree');
            fd.append('_wpnonce', nonce);

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success && Array.isArray(d.data)) {
                        nodes = d.data;
                    } else {
                        nodes = [];
                    }
                    render();
                })
                .catch(function() {
                    nodes = [];
                    render();
                });
        })();

    })();
    </script>

    <style>
    .snn-cm-node-inner[draggable] { cursor: grab; }
    .snn-cm-node-inner[draggable]:active { cursor: grabbing; }
    .snn-drag-over > .snn-cm-node-inner {
        box-shadow: 0 0 0 2px #93c5fd !important;
        background: #eff6ff !important;
    }
    .snn-cm-node-inner:hover .snn-cm-quick-actions { opacity: 1 !important; }
    .snn-cm-toggle { transition: transform .2s; }
    </style>
    <?php
}