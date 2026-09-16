/**
 * SNN Learn — Course Builder
 *
 * Two views on one admin screen:
 *   list   — every course as a card, search, create a course
 *   course — the chapter → lesson tree: drag and drop, inline add / rename,
 *            status, row menus, warnings, and a side panel that edits a post's
 *            details and course fields without leaving the tree
 *
 * State lives in `state`; every change re-renders from it. The server stays
 * the source of truth: reorders send the whole course order and the response
 * replaces local state unless another move is already queued behind it.
 */
( function () {
	'use strict';

	var C = window.SNN_BUILDER || {};
	var app = document.getElementById( 'snb-app' );
	if ( ! app ) {
		return;
	}

	var STATUS = {
		publish: 'Published',
		draft: 'Draft',
		pending: 'Pending',
		private: 'Private',
		future: 'Scheduled'
	};
	var SETTABLE = [ 'publish', 'draft', 'pending', 'private' ];

	var state = {
		view: 'list',
		courses: [],
		search: '',
		course: null,
		collapsed: {},
		undo: [],
		focus: C.initialFocus || 0,
		newStatus: readPref( 'snb-new-status', 'draft' )
	};

	// ==========================================================
	// Utilities
	// ==========================================================

	function $( sel, root ) {
		return ( root || document ).querySelector( sel );
	}

	function $$( sel, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) );
	}

	function esc( str ) {
		return String( str == null ? '' : str ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function plural( n, one, many ) {
		return n + ' ' + ( 1 === n ? one : many );
	}

	function duration( seconds, clock ) {
		seconds = Math.max( 0, Math.round( seconds || 0 ) );
		var h = Math.floor( seconds / 3600 );
		var m = Math.floor( ( seconds % 3600 ) / 60 );
		var s = seconds % 60;

		if ( clock ) {
			return ( h ? h + ':' + pad( m ) : m ) + ':' + pad( s );
		}
		if ( h ) {
			return m ? h + 'h ' + m + 'm' : h + 'h';
		}
		if ( m ) {
			return m + 'm';
		}
		return seconds ? s + 's' : '';
	}

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	function readPref( key, fallback ) {
		try {
			return window.localStorage.getItem( key ) || fallback;
		} catch ( e ) {
			return fallback;
		}
	}

	function writePref( key, value ) {
		try {
			window.localStorage.setItem( key, value );
		} catch ( e ) {}
	}

	function api( method, path, body ) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.nonce }
		};

		if ( body instanceof FormData ) {
			opts.body = body;
		} else if ( body ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}

		return fetch( C.restUrl + path, opts ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( ( data && data.message ) || 'Request failed (' + response.status + ').' );
				}
				return data;
			} );
		} );
	}

	// ---- Toasts ----

	var toastHost = document.createElement( 'div' );
	toastHost.className = 'snb-toasts';
	document.body.appendChild( toastHost );

	function toast( message, type ) {
		var el = document.createElement( 'div' );
		el.className = 'snb-toast' + ( type ? ' is-' + type : '' );
		el.setAttribute( 'role', 'error' === type ? 'alert' : 'status' );
		el.textContent = message;
		toastHost.appendChild( el );
		setTimeout( function () {
			el.classList.add( 'is-leaving' );
			setTimeout( function () {
				el.remove();
			}, 250 );
		}, 'error' === type ? 6000 : 3000 );
	}

	// ---- Save indicator ----

	var pending = 0;

	function busy( promise ) {
		pending++;
		paintSaveState();
		return promise.then( function ( value ) {
			pending--;
			paintSaveState( true );
			return value;
		}, function ( err ) {
			pending--;
			paintSaveState();
			throw err;
		} );
	}

	function paintSaveState( justSaved ) {
		var el = $( '.snb-save-state', app );
		if ( ! el ) {
			return;
		}
		if ( pending ) {
			el.textContent = 'Saving…';
			el.className = 'snb-save-state is-busy';
		} else if ( justSaved ) {
			el.textContent = 'All changes saved';
			el.className = 'snb-save-state is-ok';
		}
	}

	// ==========================================================
	// Routing
	// ==========================================================

	function courseUrl( id ) {
		return C.pageUrl + ( id ? '&course=' + id : '' );
	}

	function go( courseId, push ) {
		if ( ! panelCanClose() ) {
			return;
		}
		closePanel( true );
		if ( push ) {
			window.history.pushState( { course: courseId || 0 }, '', courseUrl( courseId ) );
		}
		if ( courseId ) {
			loadCourse( courseId );
		} else {
			loadCourses();
		}
	}

	window.addEventListener( 'popstate', function ( event ) {
		var id = event.state && event.state.course ? event.state.course : 0;
		closePanel( true );
		if ( id ) {
			loadCourse( id );
		} else {
			loadCourses();
		}
	} );

	// ==========================================================
	// Course list
	// ==========================================================

	function loadCourses() {
		state.view = 'list';
		state.course = null;
		state.undo = [];
		app.innerHTML = '<p class="snb-loading">Loading courses…</p>';

		api( 'GET', 'courses' ).then( function ( data ) {
			state.courses = data.courses || [];
			renderList();
		} ).catch( function ( err ) {
			app.innerHTML = '<div class="snb-notice is-error">' + esc( err.message ) + '</div>';
		} );
	}

	function renderList() {
		var setup = C.postTypeReady ? '' :
			'<div class="snb-notice is-warning">The <code>' + esc( C.postType ) + '</code> post type is not registered. ' +
			'Register it on the <a href="' + esc( C.fieldsUrl ) + '">Course Fields</a> screen first.</div>';

		app.innerHTML =
			'<div class="snb-head">' +
				'<div class="snb-head-main">' +
					'<h2 class="snb-title">Course Builder</h2>' +
					'<p class="snb-sub">' + plural( state.courses.length, 'course', 'courses' ) + '</p>' +
				'</div>' +
				'<div class="snb-head-actions">' +
					'<a class="button" href="' + esc( C.listUrl ) + '">WordPress list</a>' +
				'</div>' +
			'</div>' +
			setup +
			'<div class="snb-toolbar">' +
				'<input type="search" class="snb-search" placeholder="Search courses…" value="' + esc( state.search ) + '" aria-label="Search courses">' +
				'<span class="snb-spacer"></span>' +
				'<form class="snb-new-course">' +
					'<input type="text" name="title" placeholder="New course title" aria-label="New course title" required>' +
					'<button type="submit" class="button button-primary">Create course</button>' +
				'</form>' +
			'</div>' +
			'<div class="snb-grid"></div>';

		renderCards();
	}

	function renderCards() {
		var grid = $( '.snb-grid', app );
		if ( ! grid ) {
			return;
		}

		var q = state.search.toLowerCase();
		var courses = state.courses.filter( function ( c ) {
			return ! q || c.title.toLowerCase().indexOf( q ) !== -1;
		} );

		if ( ! courses.length ) {
			grid.innerHTML = '<p class="snb-empty">' + ( state.courses.length ? 'No course matches that search.' : 'No courses yet — create the first one above.' ) + '</p>';
			return;
		}

		grid.innerHTML = courses.map( function ( c ) {
			var stats = [ plural( c.all_chapters, 'chapter', 'chapters' ), plural( c.all_lessons, 'lesson', 'lessons' ) ];
			if ( c.duration ) {
				stats.push( duration( c.duration ) );
			}

			return '<a class="snb-card" href="' + esc( courseUrl( c.id ) ) + '" data-course="' + c.id + '">' +
				'<span class="snb-card-thumb"' + ( c.thumb ? ' style="background-image:url(' + esc( c.thumb ) + ')"' : '' ) + '>' +
					( c.thumb ? '' : '<span>' + esc( ( c.title || '?' ).charAt( 0 ).toUpperCase() ) + '</span>' ) +
				'</span>' +
				'<span class="snb-card-body">' +
					'<span class="snb-card-title">' + esc( c.title || '(no title)' ) + '</span>' +
					'<span class="snb-card-stats">' + esc( stats.join( ' · ' ) ) + '</span>' +
					'<span class="snb-card-foot">' +
						'<span class="snb-pill is-' + esc( c.status ) + '">' + esc( STATUS[ c.status ] || c.status ) + '</span>' +
						'<span class="snb-card-date">Updated ' + esc( c.modified ) + '</span>' +
					'</span>' +
				'</span>' +
			'</a>';
		} ).join( '' );
	}

	// ==========================================================
	// Course view
	// ==========================================================

	function loadCourse( id ) {
		state.view = 'course';
		if ( ! state.course || state.course.id !== id ) {
			state.undo = [];
			app.innerHTML = '<p class="snb-loading">Loading course…</p>';
		}

		return api( 'GET', 'course/' + id ).then( function ( tree ) {
			state.course = tree;
			renderCourse();
		} ).catch( function ( err ) {
			app.innerHTML =
				'<div class="snb-notice is-error">' + esc( err.message ) + '</div>' +
				'<p><a href="' + esc( courseUrl( 0 ) ) + '" data-nav="list">← All courses</a></p>';
		} );
	}

	function reloadCourse() {
		return state.course ? loadCourse( state.course.id ) : Promise.resolve();
	}

	function allLessons( course ) {
		var out = [];
		course.chapters.forEach( function ( ch ) {
			out = out.concat( ch.lessons );
		} );
		return out;
	}

	function findNode( id ) {
		var course = state.course;
		if ( ! course ) {
			return null;
		}
		if ( course.id === id ) {
			return { node: course, kind: 'course', parent: null };
		}
		for ( var i = 0; i < course.chapters.length; i++ ) {
			var ch = course.chapters[ i ];
			if ( ch.id === id ) {
				return { node: ch, kind: 'chapter', parent: course, index: i };
			}
			for ( var j = 0; j < ch.lessons.length; j++ ) {
				if ( ch.lessons[ j ].id === id ) {
					return { node: ch.lessons[ j ], kind: 'lesson', parent: ch, index: j, chapterIndex: i };
				}
			}
		}
		return null;
	}

	function warnings( course ) {
		var out = [];
		var noVideo = [];

		course.chapters.forEach( function ( ch ) {
			if ( ! ch.lessons.length ) {
				out.push( { id: ch.id, text: '“' + ch.title + '” has no lessons. Visitors opening it are sent back to the course page.' } );
			}

			var hidden = ch.lessons.filter( function ( l ) {
				return 'publish' === l.status;
			} ).length;
			if ( 'publish' !== ch.status && hidden ) {
				out.push( { id: ch.id, text: '“' + ch.title + '” is ' + ( STATUS[ ch.status ] || ch.status ).toLowerCase() + ', so its ' + plural( hidden, 'published lesson is', 'published lessons are' ) + ' hidden from learners.' } );
			}

			ch.lessons.forEach( function ( l ) {
				if ( 'publish' === l.status && ! l.has_video ) {
					noVideo.push( l );
				}
			} );
		} );

		if ( noVideo.length ) {
			out.push( { id: noVideo[ 0 ].id, text: plural( noVideo.length, 'published lesson has', 'published lessons have' ) + ' no video: ' + noVideo.slice( 0, 3 ).map( function ( l ) {
				return '“' + l.title + '”';
			} ).join( ', ' ) + ( noVideo.length > 3 ? '…' : '' ) } );
		}

		if ( course.too_deep ) {
			out.push( { id: 0, text: plural( course.too_deep, 'item is', 'items are' ) + ' nested below a lesson and invisible to learners. Move them from the WordPress list (“Chapters & lessons too”).' } );
		}

		return out;
	}

	function statusSelect( node, kind ) {
		var options = SETTABLE.slice();
		if ( SETTABLE.indexOf( node.status ) === -1 ) {
			options.unshift( node.status );
		}
		return '<select class="snb-status is-' + esc( node.status ) + '" data-status-for="' + node.id + '" data-kind="' + kind + '" aria-label="Status">' +
			options.map( function ( s ) {
				var disabled = ( 'publish' === s && ! C.canPublish ) || SETTABLE.indexOf( s ) === -1;
				return '<option value="' + s + '"' + ( s === node.status ? ' selected' : '' ) + ( disabled && s !== node.status ? ' disabled' : '' ) + '>' + esc( STATUS[ s ] || s ) + '</option>';
			} ).join( '' ) +
		'</select>';
	}

	function lessonBadges( l ) {
		var out = [];
		out.push( l.has_video
			? '<span class="snb-badge is-ok" title="Has a video">Video</span>'
			: '<span class="snb-badge is-missing" title="No video yet">No video</span>' );
		if ( l.subtitles ) {
			out.push( '<span class="snb-badge is-ok" title="' + plural( l.subtitles, 'subtitle track', 'subtitle tracks' ) + '">CC</span>' );
		}
		if ( l.duration ) {
			out.push( '<span class="snb-badge" title="Length">' + esc( duration( l.duration, true ) ) + '</span>' );
		}
		if ( l.free_preview ) {
			out.push( '<span class="snb-badge is-free" title="Guests can watch this lesson">Free</span>' );
		}
		if ( l.ai_total ) {
			var full = l.ai_done === l.ai_total;
			out.push( '<span class="snb-badge ' + ( full ? 'is-ok' : ( l.ai_done ? 'is-partial' : 'is-missing' ) ) + '" title="Generated fields filled">AI ' + l.ai_done + '/' + l.ai_total + '</span>' );
		}
		return out.join( '' );
	}

	function lessonRow( l, chapterIndex, index ) {
		return '<li class="snb-lesson is-' + esc( l.status ) + '" data-id="' + l.id + '" data-kind="lesson" draggable="true" tabindex="0">' +
			'<span class="snb-handle" title="Drag to reorder" aria-hidden="true"></span>' +
			'<span class="snb-num">' + ( chapterIndex + 1 ) + '.' + ( index + 1 ) + '</span>' +
			'<span class="snb-name" title="Double-click to rename">' + esc( l.title || '(no title)' ) + '</span>' +
			'<span class="snb-badges">' + lessonBadges( l ) + '</span>' +
			statusSelect( l, 'lesson' ) +
			'<span class="snb-row-actions">' +
				'<button type="button" class="button button-small" data-action="panel">Edit</button>' +
				'<button type="button" class="snb-icon-btn" data-action="menu" aria-label="More actions" title="More actions">⋯</button>' +
			'</span>' +
		'</li>';
	}

	function chapterBlock( ch, index ) {
		var collapsed = !! state.collapsed[ ch.id ];
		var total = ch.lessons.reduce( function ( sum, l ) {
			return sum + ( l.duration || 0 );
		}, 0 );
		var meta = [ plural( ch.lessons.length, 'lesson', 'lessons' ) ];
		if ( total ) {
			meta.push( duration( total ) );
		}

		return '<li class="snb-chapter is-' + esc( ch.status ) + ( collapsed ? ' is-collapsed' : '' ) + '" data-id="' + ch.id + '" data-kind="chapter">' +
			'<div class="snb-chapter-head" tabindex="0">' +
				'<span class="snb-handle" data-drag="chapter" title="Drag to reorder" aria-hidden="true"></span>' +
				'<button type="button" class="snb-caret" data-action="toggle" aria-expanded="' + ( collapsed ? 'false' : 'true' ) + '" aria-label="Show or hide lessons"></button>' +
				'<span class="snb-num">' + ( index + 1 ) + '</span>' +
				'<span class="snb-name" title="Double-click to rename">' + esc( ch.title || '(no title)' ) + '</span>' +
				'<span class="snb-meta">' + esc( meta.join( ' · ' ) ) + '</span>' +
				statusSelect( ch, 'chapter' ) +
				'<span class="snb-row-actions">' +
					'<button type="button" class="button button-small" data-action="panel">Details</button>' +
					'<button type="button" class="snb-icon-btn" data-action="menu" aria-label="More actions" title="More actions">⋯</button>' +
				'</span>' +
			'</div>' +
			'<div class="snb-chapter-body">' +
				'<ol class="snb-lessons" data-chapter="' + ch.id + '">' +
					ch.lessons.map( function ( l, j ) {
						return lessonRow( l, index, j );
					} ).join( '' ) +
					'<li class="snb-drop-empty">Drop lessons here</li>' +
				'</ol>' +
				'<form class="snb-add" data-parent="' + ch.id + '">' +
					'<input type="text" placeholder="+ Add lesson — type a title, press Enter" aria-label="New lesson title in ' + esc( ch.title ) + '">' +
					'<button type="button" class="button-link" data-action="paste-lessons">Paste a list</button>' +
				'</form>' +
			'</div>' +
		'</li>';
	}

	function renderCourse() {
		var course = state.course;
		var lessons = allLessons( course );
		var published = lessons.filter( function ( l ) {
			return 'publish' === l.status;
		} ).length;
		var total = lessons.reduce( function ( sum, l ) {
			return sum + ( l.duration || 0 );
		}, 0 );

		var stats = [
			plural( course.chapters.length, 'chapter', 'chapters' ),
			plural( lessons.length, 'lesson', 'lessons' ) + ( lessons.length ? ' (' + published + ' published)' : '' )
		];
		if ( total ) {
			stats.push( duration( total ) );
		}

		var warns = warnings( course );

		// A rename in progress would be wiped by the redraw; the data is
		// already in state, so the next render catches up.
		if ( document.activeElement && document.activeElement.classList.contains( 'snb-rename' ) ) {
			return;
		}

		var activeId = document.activeElement && document.activeElement.closest && document.activeElement.closest( '[data-id]' )
			? document.activeElement.closest( '[data-id]' ).dataset.id
			: null;
		var activeAdd = document.activeElement && document.activeElement.closest && document.activeElement.closest( '.snb-add' )
			? document.activeElement.closest( '.snb-add' ).dataset.parent
			: null;

		app.innerHTML =
			'<div class="snb-head">' +
				'<div class="snb-head-main">' +
					'<a class="snb-back" href="' + esc( courseUrl( 0 ) ) + '" data-nav="list">← All courses</a>' +
					'<div class="snb-title-row" data-id="' + course.id + '" data-kind="course">' +
						'<button type="button" class="snb-course-thumb" data-action="panel" title="Course details and featured image">' + thumbPreview( course.thumb ) + '</button>' +
						'<h2 class="snb-title"><span class="snb-name" title="Double-click to rename">' + esc( course.title || '(no title)' ) + '</span></h2>' +
						statusSelect( course, 'course' ) +
					'</div>' +
					'<p class="snb-sub">' + esc( stats.join( ' · ' ) ) + '</p>' +
				'</div>' +
				'<div class="snb-head-actions" data-id="' + course.id + '" data-kind="course">' +
					'<span class="snb-save-state"></span>' +
					'<button type="button" class="button" data-action="panel">Course details</button>' +
					'<a class="button" href="' + esc( course.permalink ) + '" target="_blank" rel="noopener">View</a>' +
					'<a class="button" href="' + esc( course.edit_url ) + '">Edit in WordPress</a>' +
					'<button type="button" class="snb-icon-btn" data-action="menu" aria-label="More course actions" title="More actions">⋯</button>' +
				'</div>' +
			'</div>' +
			( warns.length
				? '<details class="snb-warnings"' + ( warns.length <= 3 ? ' open' : '' ) + '>' +
					'<summary>' + plural( warns.length, 'thing needs', 'things need' ) + ' attention</summary>' +
					'<ul>' + warns.map( function ( w ) {
						return '<li>' + ( w.id ? '<button type="button" class="button-link" data-focus="' + w.id + '">' + esc( w.text ) + '</button>' : esc( w.text ) ) + '</li>';
					} ).join( '' ) + '</ul>' +
				'</details>'
				: '' ) +
			'<div class="snb-toolbar">' +
				'<button type="button" class="button" data-action="expand-all">Expand all</button>' +
				'<button type="button" class="button" data-action="collapse-all">Collapse all</button>' +
				'<button type="button" class="button" data-action="undo"' + ( state.undo.length ? '' : ' disabled' ) + '>Undo move</button>' +
				'<span class="snb-spacer"></span>' +
				'<label class="snb-new-status">New items as ' +
					'<select data-action="new-status">' +
						'<option value="draft"' + ( 'draft' === state.newStatus ? ' selected' : '' ) + '>Draft</option>' +
						( C.canPublish ? '<option value="publish"' + ( 'publish' === state.newStatus ? ' selected' : '' ) + '>Published</option>' : '' ) +
					'</select>' +
				'</label>' +
				'<button type="button" class="button" data-action="outline">Paste outline</button>' +
			'</div>' +
			( course.chapters.length
				? ''
				: '<div class="snb-empty-course"><strong>No chapters yet.</strong> Add the first chapter below, or use <button type="button" class="button-link" data-action="outline">Paste outline</button> to create a whole course structure at once.</div>' ) +
			'<ol class="snb-chapters">' + course.chapters.map( chapterBlock ).join( '' ) + '</ol>' +
			'<form class="snb-add snb-add-chapter" data-parent="' + course.id + '">' +
				'<input type="text" placeholder="+ Add chapter — type a title, press Enter" aria-label="New chapter title">' +
			'</form>' +
			'<p class="snb-hint">Drag rows by their handle, or focus a row and press <kbd>Alt</kbd>+<kbd>↑</kbd>/<kbd>↓</kbd>. Double-click a title (or press <kbd>F2</kbd>) to rename.</p>';

		if ( state.panel ) {
			app.classList.add( 'has-panel' );
			var current = $( '[data-id="' + state.panel.id + '"]', app );
			if ( current ) {
				current.classList.add( 'is-selected' );
			}
		}

		if ( activeAdd ) {
			var add = $( '.snb-add[data-parent="' + activeAdd + '"] input', app );
			if ( add ) {
				add.focus();
			}
		} else if ( activeId ) {
			var row = $( '.snb-lesson[data-id="' + activeId + '"], .snb-chapter[data-id="' + activeId + '"] > .snb-chapter-head', app );
			if ( row ) {
				row.focus( { preventScroll: true } );
			}
		}

		if ( state.focus ) {
			focusItem( state.focus );
			state.focus = 0;
		}
	}

	function focusItem( id ) {
		var found = findNode( id );
		if ( ! found ) {
			return;
		}
		if ( 'lesson' === found.kind && state.collapsed[ found.parent.id ] ) {
			delete state.collapsed[ found.parent.id ];
			renderCourse();
		}
		var el = 'lesson' === found.kind
			? $( '.snb-lesson[data-id="' + id + '"]', app )
			: $( '.snb-chapter[data-id="' + id + '"] > .snb-chapter-head', app );
		if ( ! el ) {
			return;
		}
		el.scrollIntoView( { block: 'center', behavior: 'smooth' } );
		el.classList.add( 'is-flash' );
		el.focus( { preventScroll: true } );
		setTimeout( function () {
			el.classList.remove( 'is-flash' );
		}, 1600 );
	}

	// ==========================================================
	// Order: snapshots, moves, undo, saving
	// ==========================================================

	function snapshot() {
		return state.course.chapters.map( function ( ch ) {
			return {
				id: ch.id,
				lessons: ch.lessons.map( function ( l ) {
					return l.id;
				} )
			};
		} );
	}

	function applySnapshot( snap ) {
		var chapters = {};
		var lessons = {};
		state.course.chapters.forEach( function ( ch ) {
			chapters[ ch.id ] = ch;
			ch.lessons.forEach( function ( l ) {
				lessons[ l.id ] = l;
			} );
		} );

		state.course.chapters = snap.filter( function ( entry ) {
			return chapters[ entry.id ];
		} ).map( function ( entry ) {
			var ch = chapters[ entry.id ];
			ch.lessons = entry.lessons.filter( function ( id ) {
				return lessons[ id ];
			} ).map( function ( id ) {
				return lessons[ id ];
			} );
			return ch;
		} );
	}

	function sameOrder( a, b ) {
		return JSON.stringify( a ) === JSON.stringify( b );
	}

	/** Moves a chapter to `index`, or a lesson into `chapterId` at `index`. */
	function moveItem( kind, id, chapterId, index ) {
		var before = snapshot();
		var found = findNode( id );
		if ( ! found || found.kind !== kind ) {
			return;
		}

		if ( 'chapter' === kind ) {
			var list = state.course.chapters;
			list.splice( found.index, 1 );
			list.splice( Math.max( 0, Math.min( index, list.length ) ), 0, found.node );
		} else {
			var target = findNode( chapterId );
			if ( ! target || 'chapter' !== target.kind ) {
				return;
			}
			found.parent.lessons.splice( found.index, 1 );
			target.node.lessons.splice( Math.max( 0, Math.min( index, target.node.lessons.length ) ), 0, found.node );
			delete state.collapsed[ chapterId ];
		}

		if ( sameOrder( before, snapshot() ) ) {
			renderCourse();
			return;
		}

		state.undo.push( before );
		if ( state.undo.length > 30 ) {
			state.undo.shift();
		}
		renderCourse();
		saveOrder();
	}

	var orderInFlight = false;
	var orderQueued = false;

	function saveOrder() {
		if ( orderInFlight ) {
			orderQueued = true;
			return;
		}
		orderInFlight = true;
		var courseId = state.course.id;

		busy( api( 'POST', 'reorder', { course_id: courseId, chapters: snapshot() } ) ).then( function ( tree ) {
			// Permalinks change with parents; take the server's copy unless a
			// newer local move is waiting to be sent.
			if ( ! orderQueued && state.course && state.course.id === courseId ) {
				state.course = tree;
				renderCourse();
			}
		} ).catch( function ( err ) {
			orderQueued = false;
			state.undo = [];
			toast( err.message, 'error' );
			reloadCourse();
		} ).then( function () {
			orderInFlight = false;
			if ( orderQueued ) {
				orderQueued = false;
				saveOrder();
			}
		} );
	}

	function undo() {
		var snap = state.undo.pop();
		if ( ! snap ) {
			return;
		}
		applySnapshot( snap );
		renderCourse();
		saveOrder();
	}

	/** Alt+Up / Alt+Down on a focused row. */
	function nudge( id, direction ) {
		var found = findNode( id );
		if ( ! found ) {
			return;
		}

		if ( 'chapter' === found.kind ) {
			var to = found.index + direction;
			if ( to >= 0 && to < state.course.chapters.length ) {
				moveItem( 'chapter', id, null, to );
			}
			return;
		}

		var chapters = state.course.chapters;
		var ci = found.chapterIndex;
		var li = found.index + direction;

		if ( li >= 0 && li < found.parent.lessons.length ) {
			moveItem( 'lesson', id, found.parent.id, li );
		} else if ( direction < 0 && ci > 0 ) {
			moveItem( 'lesson', id, chapters[ ci - 1 ].id, chapters[ ci - 1 ].lessons.length );
		} else if ( direction > 0 && ci < chapters.length - 1 ) {
			moveItem( 'lesson', id, chapters[ ci + 1 ].id, 0 );
		}
	}

	// ==========================================================
	// Drag and drop
	// ==========================================================

	var drag = null;
	var indicator = document.createElement( 'li' );
	indicator.className = 'snb-drop-indicator';
	var expandTimer = null;

	// Chapters are only draggable from their handle, so selecting text in a
	// chapter title or using its controls never starts a drag.
	var pressedOn = null;

	app.addEventListener( 'mousedown', function ( event ) {
		pressedOn = event.target;
		var handle = event.target.closest( '[data-drag="chapter"]' );
		if ( handle ) {
			handle.closest( '.snb-chapter' ).setAttribute( 'draggable', 'true' );
		}
	} );
	document.addEventListener( 'mouseup', clearChapterDraggable );

	function clearChapterDraggable() {
		$$( '.snb-chapter[draggable]', app ).forEach( function ( el ) {
			el.removeAttribute( 'draggable' );
		} );
	}

	app.addEventListener( 'dragstart', function ( event ) {
		var el = event.target;
		if ( ! el.classList || ! ( el.classList.contains( 'snb-lesson' ) || el.classList.contains( 'snb-chapter' ) ) ) {
			return;
		}
		// Rows hold selects and buttons; never start a drag from those.
		if ( pressedOn && pressedOn.closest && pressedOn.closest( 'select, input, button, a' ) ) {
			event.preventDefault();
			return;
		}

		drag = {
			kind: el.classList.contains( 'snb-lesson' ) ? 'lesson' : 'chapter',
			id: parseInt( el.dataset.id, 10 ),
			el: el
		};
		event.dataTransfer.effectAllowed = 'move';
		event.dataTransfer.setData( 'text/plain', String( drag.id ) );
		setTimeout( function () {
			el.classList.add( 'is-dragging' );
			app.classList.add( 'is-dragging-' + drag.kind );
		}, 0 );
	} );

	app.addEventListener( 'dragover', function ( event ) {
		if ( ! drag ) {
			return;
		}

		var list = null;
		if ( 'lesson' === drag.kind ) {
			list = event.target.closest( '.snb-lessons' );
			var chapter = event.target.closest( '.snb-chapter' );
			if ( ! list && chapter ) {
				list = $( '.snb-lessons', chapter );
				scheduleExpand( chapter );
			}
		} else {
			list = event.target.closest( '.snb-chapters' );
		}
		if ( ! list ) {
			return;
		}

		event.preventDefault();
		event.dataTransfer.dropEffect = 'move';

		var cls = 'lesson' === drag.kind ? 'snb-lesson' : 'snb-chapter';
		var items = Array.prototype.filter.call( list.children, function ( el ) {
			return el.classList.contains( cls ) && el !== drag.el;
		} );

		var before = null;
		for ( var i = 0; i < items.length; i++ ) {
			var rect = items[ i ].getBoundingClientRect();
			if ( event.clientY < rect.top + rect.height / 2 ) {
				before = items[ i ];
				break;
			}
		}

		if ( ! before && 'lesson' === drag.kind ) {
			before = $( ':scope > .snb-drop-empty', list );
		}
		if ( indicator.parentNode !== list || indicator.nextSibling !== before ) {
			list.insertBefore( indicator, before );
		}
	} );

	function scheduleExpand( chapter ) {
		if ( ! chapter.classList.contains( 'is-collapsed' ) || expandTimer ) {
			return;
		}
		expandTimer = setTimeout( function () {
			expandTimer = null;
			if ( drag && chapter.isConnected ) {
				delete state.collapsed[ chapter.dataset.id ];
				chapter.classList.remove( 'is-collapsed' );
			}
		}, 600 );
	}

	app.addEventListener( 'drop', function ( event ) {
		if ( ! drag || ! indicator.parentNode ) {
			return;
		}
		event.preventDefault();

		var list = indicator.parentNode;
		var cls = 'lesson' === drag.kind ? 'snb-lesson' : 'snb-chapter';
		var index = 0;
		for ( var n = list.firstElementChild; n && n !== indicator; n = n.nextElementSibling ) {
			if ( n.classList.contains( cls ) && n !== drag.el ) {
				index++;
			}
		}

		var move = { kind: drag.kind, id: drag.id, chapter: 'lesson' === drag.kind ? parseInt( list.dataset.chapter, 10 ) : null };
		endDrag();
		moveItem( move.kind, move.id, move.chapter, index );
	} );

	app.addEventListener( 'dragend', endDrag );

	function endDrag() {
		if ( drag && drag.el ) {
			drag.el.classList.remove( 'is-dragging' );
		}
		app.classList.remove( 'is-dragging-lesson', 'is-dragging-chapter' );
		indicator.remove();
		clearTimeout( expandTimer );
		expandTimer = null;
		clearChapterDraggable();
		drag = null;
	}

	// ==========================================================
	// Create, rename, status, duplicate, move, trash
	// ==========================================================

	function createItems( parentId, titles ) {
		return busy( api( 'POST', 'create', { parent_id: parentId, titles: titles, status: state.newStatus } ) ).then( function ( data ) {
			var created = data.created || [];
			var parent = findNode( parentId );

			if ( parent && 'chapter' === parent.kind ) {
				parent.node.lessons = parent.node.lessons.concat( created );
			} else if ( parent && 'course' === parent.kind ) {
				state.course.chapters = state.course.chapters.concat( created );
			}
			state.undo = [];
			return created;
		} );
	}

	function startRename( id ) {
		var found = findNode( id );
		if ( ! found ) {
			return;
		}

		var holder = 'course' === found.kind
			? $( '.snb-title-row .snb-name', app )
			: ( 'chapter' === found.kind
				? $( '.snb-chapter[data-id="' + id + '"] > .snb-chapter-head .snb-name', app )
				: $( '.snb-lesson[data-id="' + id + '"] .snb-name', app ) );
		if ( ! holder ) {
			return;
		}

		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'snb-rename';
		input.value = found.node.title;
		input.setAttribute( 'aria-label', 'Rename' );
		holder.replaceWith( input );
		input.focus();
		input.select();

		var done = false;
		function finish( save ) {
			if ( done ) {
				return;
			}
			done = true;
			var title = input.value.trim();
			input.blur();

			if ( ! save || ! title || title === found.node.title ) {
				renderCourse();
				return;
			}

			var old = found.node.title;
			found.node.title = title;
			renderCourse();

			busy( api( 'POST', 'update', { id: id, title: title } ) ).then( function ( node ) {
				mergeNode( node );
				renderCourse();
			} ).catch( function ( err ) {
				found.node.title = old;
				renderCourse();
				toast( err.message, 'error' );
			} );
		}

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				finish( true );
			} else if ( 'Escape' === event.key ) {
				event.preventDefault();
				finish( false );
			}
		} );
		input.addEventListener( 'blur', function () {
			finish( true );
		} );
	}

	/** Copies a fresh server node's fields into the local one, keeping children. */
	function mergeNode( fresh ) {
		var found = findNode( fresh.id );
		if ( ! found ) {
			return;
		}
		Object.keys( fresh ).forEach( function ( key ) {
			if ( 'lessons' !== key && 'chapters' !== key ) {
				found.node[ key ] = fresh[ key ];
			}
		} );
	}

	function changeStatus( id, kind, status, select ) {
		var found = findNode( id );
		if ( ! found ) {
			return;
		}

		var children = 'course' === kind ? allLessons( state.course ).concat( state.course.chapters ) : ( 'chapter' === kind ? found.node.lessons : [] );
		var differing = children.filter( function ( child ) {
			return child.status !== status;
		} ).length;
		var cascade = false;

		if ( differing && ( 'publish' === status || 'draft' === status ) ) {
			cascade = window.confirm(
				'Also set ' + plural( differing, 'item', 'items' ) + ' inside this ' + kind + ' to ' + STATUS[ status ].toLowerCase() + '?\n\n' +
				'OK — change them too.\nCancel — change only the ' + kind + '.'
			);
		}

		if ( select ) {
			select.disabled = true;
		}

		busy( api( 'POST', 'update', { id: id, status: status, cascade: cascade } ) ).then( function ( node ) {
			if ( cascade ) {
				return reloadCourse();
			}
			mergeNode( node );
			renderCourse();
		} ).catch( function ( err ) {
			toast( err.message, 'error' );
			renderCourse();
		} );
	}

	function duplicate( id, kind ) {
		busy( api( 'POST', 'duplicate', { id: id } ) ).then( function ( node ) {
			toast( 'Duplicated as a draft.' );
			if ( 'course' === kind ) {
				go( node.id, true );
			} else {
				state.focus = node.id;
				reloadCourse();
			}
		} ).catch( function ( err ) {
			toast( err.message, 'error' );
		} );
	}

	function trash( id, kind ) {
		var found = findNode( id );
		if ( ! found ) {
			return;
		}
		var inside = 'course' === kind
			? state.course.chapters.length + allLessons( state.course ).length
			: ( 'chapter' === kind ? found.node.lessons.length : 0 );

		var message = 'Move “' + found.node.title + '” to the trash?' +
			( inside ? '\n\n' + plural( inside, 'item inside it goes', 'items inside it go' ) + ' to the trash too.' : '' ) +
			'\n\nYou can restore it from the WordPress trash.';
		if ( ! window.confirm( message ) ) {
			return;
		}

		if ( state.panel && state.panel.id === id ) {
			closePanel( true );
		}

		busy( api( 'POST', 'trash', { id: id } ) ).then( function ( data ) {
			toast( plural( data.trashed, 'item', 'items' ) + ' moved to the trash.' );
			if ( 'course' === kind ) {
				go( 0, true );
			} else {
				state.undo = [];
				reloadCourse();
			}
		} ).catch( function ( err ) {
			toast( err.message, 'error' );
		} );
	}

	function moveDialog( id, kind ) {
		var found = findNode( id );
		if ( ! found ) {
			return;
		}

		var body =
			'<p class="snb-modal-lead">Move “' + esc( found.node.title ) + '” to the end of another ' + ( 'lesson' === kind ? 'chapter' : 'course' ) + '.</p>' +
			'<label class="snb-field"><span>Course</span><select name="course"><option value="">Loading…</option></select></label>' +
			( 'lesson' === kind ? '<label class="snb-field"><span>Chapter</span><select name="chapter" disabled><option value="">Pick a course first</option></select></label>' : '' );

		var dialog = modal( {
			title: 'lesson' === kind ? 'Move lesson' : 'Move chapter',
			body: body,
			confirm: 'Move',
			onConfirm: function ( root ) {
				var target = 'lesson' === kind ? $( '[name="chapter"]', root ).value : $( '[name="course"]', root ).value;
				if ( ! target ) {
					throw new Error( 'lesson' === kind ? 'Pick a chapter.' : 'Pick a course.' );
				}
				return busy( api( 'POST', 'move', { id: id, target_id: parseInt( target, 10 ) } ) ).then( function () {
					toast( 'Moved.' );
					state.undo = [];
					return reloadCourse();
				} );
			}
		} );

		var courseSelect = $( '[name="course"]', dialog );
		var chapterSelect = $( '[name="chapter"]', dialog );

		api( 'GET', 'courses' ).then( function ( data ) {
			var courses = ( data.courses || [] ).filter( function ( c ) {
				return 'lesson' === kind || c.id !== state.course.id;
			} );
			courseSelect.innerHTML = '<option value="">Choose…</option>' + courses.map( function ( c ) {
				return '<option value="' + c.id + '">' + esc( c.title ) + ( c.id === state.course.id ? ' (this course)' : '' ) + '</option>';
			} ).join( '' );
		} );

		if ( chapterSelect ) {
			courseSelect.addEventListener( 'change', function () {
				chapterSelect.disabled = true;
				if ( ! courseSelect.value ) {
					chapterSelect.innerHTML = '<option value="">Pick a course first</option>';
					return;
				}
				chapterSelect.innerHTML = '<option value="">Loading…</option>';
				api( 'GET', 'course/' + courseSelect.value ).then( function ( tree ) {
					var chapters = tree.chapters.filter( function ( ch ) {
						return ch.id !== found.parent.id;
					} );
					chapterSelect.innerHTML = chapters.length
						? '<option value="">Choose…</option>' + chapters.map( function ( ch ) {
							return '<option value="' + ch.id + '">' + esc( ch.title ) + '</option>';
						} ).join( '' )
						: '<option value="">No other chapters there</option>';
					chapterSelect.disabled = ! chapters.length;
				} );
			} );
		}
	}

	function pasteDialog( parentId, mode ) {
		var isOutline = 'outline' === mode;
		var parent = findNode( parentId );

		modal( {
			title: isOutline ? 'Paste outline' : 'Add lessons from a list',
			body: isOutline
				? '<p class="snb-modal-lead">One title per line. Lines with no indent become <strong>chapters</strong>; indented lines, or lines starting with <code>-</code>, become <strong>lessons</strong> in the chapter above. New items are added after the existing ones as ' + esc( STATUS[ state.newStatus ].toLowerCase() ) + '.</p>' +
					'<textarea class="snb-paste" rows="14" placeholder="Getting Started&#10;- Welcome&#10;- Installing the tools&#10;Core Concepts&#10;- Variables&#10;- Functions"></textarea>'
				: '<p class="snb-modal-lead">One lesson title per line, added to the end of “' + esc( parent ? parent.node.title : '' ) + '” as ' + esc( STATUS[ state.newStatus ].toLowerCase() ) + '.</p>' +
					'<textarea class="snb-paste" rows="12" placeholder="Welcome&#10;Installing the tools&#10;Your first project"></textarea>',
			confirm: 'Create',
			onConfirm: function ( root ) {
				var text = $( '.snb-paste', root ).value;
				if ( ! text.trim() ) {
					throw new Error( 'Paste at least one title.' );
				}

				if ( isOutline ) {
					return busy( api( 'POST', 'create', { parent_id: parentId, outline: text, status: state.newStatus } ) ).then( function ( data ) {
						toast( 'Created ' + plural( ( data.created || [] ).length, 'chapter', 'chapters' ) + '.' );
						state.undo = [];
						return reloadCourse();
					} );
				}

				var titles = text.split( /\r?\n/ ).map( function ( line ) {
					return line.replace( /^\s*[-*•]?\s*/, '' ).trim();
				} ).filter( Boolean );

				return createItems( parentId, titles ).then( function ( created ) {
					toast( 'Created ' + plural( created.length, 'lesson', 'lessons' ) + '.' );
					renderCourse();
				} );
			}
		} );
	}

	// ==========================================================
	// Popover menu and modal
	// ==========================================================

	var menuEl = null;

	function closeMenu() {
		if ( menuEl ) {
			menuEl.remove();
			menuEl = null;
		}
	}

	function openMenu( button, id, kind ) {
		closeMenu();
		var found = findNode( id );
		if ( ! found ) {
			return;
		}
		var node = found.node;
		var items = [];

		if ( 'course' !== kind ) {
			items.push( [ 'rename', 'Rename' ] );
		}
		if ( 'chapter' === kind ) {
			items.push( [ 'paste-lessons', 'Add lessons from a list…' ] );
		}
		if ( 'course' !== kind ) {
			items.push( [ 'up', 'Move up' ], [ 'down', 'Move down' ] );
			items.push( [ 'move', 'lesson' === kind ? 'Move to another chapter…' : 'Move to another course…' ] );
		}
		items.push( '-' );
		if ( 'course' !== kind ) {
			items.push( [ 'view', 'View on site', node.permalink ], [ 'edit', 'Edit in WordPress', node.edit_url ] );
		}
		items.push( [ 'duplicate', 'Duplicate' + ( 'lesson' === kind ? '' : ' with everything inside' ) ] );
		items.push( '-' );
		items.push( [ 'trash', 'Move to trash', null, 'is-danger' ] );

		menuEl = document.createElement( 'div' );
		menuEl.className = 'snb-menu';
		menuEl.setAttribute( 'role', 'menu' );
		menuEl.innerHTML = items.map( function ( item ) {
			if ( '-' === item ) {
				return '<hr>';
			}
			return item[ 2 ]
				? '<a role="menuitem" href="' + esc( item[ 2 ] ) + '"' + ( 'view' === item[ 0 ] ? ' target="_blank" rel="noopener"' : '' ) + '>' + esc( item[ 1 ] ) + '</a>'
				: '<button type="button" role="menuitem" data-menu="' + item[ 0 ] + '" class="' + ( item[ 3 ] || '' ) + '">' + esc( item[ 1 ] ) + '</button>';
		} ).join( '' );

		document.body.appendChild( menuEl );

		var rect = button.getBoundingClientRect();
		var width = menuEl.offsetWidth;
		var height = menuEl.offsetHeight;
		var top = rect.bottom + 4;
		if ( top + height > window.innerHeight - 8 ) {
			top = Math.max( 8, rect.top - height - 4 );
		}
		menuEl.style.top = ( top + window.scrollY ) + 'px';
		menuEl.style.left = ( Math.max( 8, rect.right - width ) + window.scrollX ) + 'px';

		var first = $( 'button, a', menuEl );
		if ( first ) {
			first.focus();
		}

		menuEl.addEventListener( 'click', function ( event ) {
			var action = event.target.closest( '[data-menu]' );
			if ( ! action ) {
				return;
			}
			closeMenu();
			switch ( action.dataset.menu ) {
				case 'rename': return startRename( id );
				case 'paste-lessons': return pasteDialog( id, 'lessons' );
				case 'up': return nudge( id, -1 );
				case 'down': return nudge( id, 1 );
				case 'move': return moveDialog( id, kind );
				case 'duplicate': return duplicate( id, kind );
				case 'trash': return trash( id, kind );
			}
		} );
	}

	document.addEventListener( 'mousedown', function ( event ) {
		if ( menuEl && ! menuEl.contains( event.target ) && ! event.target.closest( '[data-action="menu"]' ) ) {
			closeMenu();
		}
	} );

	function modal( opts ) {
		var root = document.createElement( 'div' );
		root.className = 'snb-modal';
		root.innerHTML =
			'<div class="snb-modal-backdrop"></div>' +
			'<form class="snb-modal-box" role="dialog" aria-modal="true" aria-label="' + esc( opts.title ) + '">' +
				'<h3>' + esc( opts.title ) + '</h3>' +
				'<div class="snb-modal-body">' + opts.body + '</div>' +
				'<p class="snb-modal-error" role="alert"></p>' +
				'<div class="snb-modal-actions">' +
					'<button type="button" class="button" data-modal="cancel">Cancel</button>' +
					'<button type="submit" class="button button-primary">' + esc( opts.confirm || 'OK' ) + '</button>' +
				'</div>' +
			'</form>';
		document.body.appendChild( root );

		var previous = document.activeElement;
		var form = $( 'form', root );
		var error = $( '.snb-modal-error', root );

		function close() {
			root.remove();
			document.removeEventListener( 'keydown', onKey );
			if ( previous && previous.isConnected ) {
				previous.focus();
			}
		}

		function onKey( event ) {
			if ( 'Escape' === event.key ) {
				close();
			}
		}

		document.addEventListener( 'keydown', onKey );
		$( '[data-modal="cancel"]', root ).addEventListener( 'click', close );
		$( '.snb-modal-backdrop', root ).addEventListener( 'click', close );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var submit = $( '[type="submit"]', form );
			error.textContent = '';
			submit.disabled = true;

			Promise.resolve().then( function () {
				return opts.onConfirm( root );
			} ).then( close, function ( err ) {
				error.textContent = err.message;
				submit.disabled = false;
			} );
		} );

		var focusable = $( 'textarea, select, input', root );
		( focusable || $( '[type="submit"]', root ) ).focus();

		return root;
	}

	// ==========================================================
	// Side panel
	// ==========================================================

	var panelEl = null;

	function panelCanClose() {
		return ! state.panel || ! state.panel.dirty || window.confirm( 'Discard unsaved changes to “' + state.panel.title + '”?' );
	}

	function closePanel( force ) {
		if ( ! force && ! panelCanClose() ) {
			return false;
		}
		if ( panelEl ) {
			panelEl.remove();
			panelEl = null;
		}
		state.panel = null;
		app.classList.remove( 'has-panel' );
		$$( '.is-selected', app ).forEach( function ( el ) {
			el.classList.remove( 'is-selected' );
		} );
		return true;
	}

	function openPanel( id ) {
		if ( state.panel && state.panel.id === id ) {
			return;
		}
		if ( ! closePanel() ) {
			return;
		}

		var found = findNode( id );
		if ( ! found ) {
			return;
		}
		var node = found.node;
		var kind = found.kind;

		state.panel = { id: id, kind: kind, title: node.title, dirty: false, fieldsLoaded: false };

		panelEl = document.createElement( 'aside' );
		panelEl.className = 'snb-panel';
		panelEl.setAttribute( 'aria-label', 'Edit ' + kind );
		panelEl.innerHTML =
			'<header class="snb-panel-head">' +
				'<div><span class="snb-kicker">' + esc( kind ) + '</span><h3>' + esc( node.title || '(no title)' ) + '</h3></div>' +
				'<button type="button" class="snb-icon-btn" data-panel="close" aria-label="Close panel" title="Close (Esc)">×</button>' +
			'</header>' +
			'<form class="snb-panel-body" novalidate>' +
				'<section class="snb-panel-section">' +
					'<label class="snb-field"><span>Title</span><input type="text" name="title" value="' + esc( node.title ) + '" required></label>' +
					'<div class="snb-field-row">' +
						'<label class="snb-field"><span>Slug</span><input type="text" name="slug" value="' + esc( node.slug ) + '" placeholder="generated on publish"></label>' +
						'<label class="snb-field"><span>Status</span>' + statusSelect( node, kind ).replace( 'class="snb-status', 'name="status" class="snb-status' ).replace( / data-status-for="\d+"/, '' ) + '</label>' +
					'</div>' +
					'<label class="snb-field"><span>Excerpt</span><textarea name="excerpt" rows="3">' + esc( node.excerpt ) + '</textarea></label>' +
					'<div class="snb-field">' +
						'<span>Featured image' + ( 'lesson' === kind ? ' <em class="snb-muted">(also the video poster)</em>' : '' ) + '</span>' +
						'<div class="snb-thumb-field">' +
							'<button type="button" class="snb-thumb-preview" data-panel="thumb-pick" aria-label="Choose featured image">' + thumbPreview( node.thumb ) + '</button>' +
							'<div class="snb-thumb-actions">' +
								'<button type="button" class="button" data-panel="thumb-pick">' + ( node.thumbnail_id ? 'Replace image' : 'Choose image' ) + '</button>' +
								'<button type="button" class="button-link snb-thumb-remove" data-panel="thumb-remove"' + ( node.thumbnail_id ? '' : ' hidden' ) + '>Remove</button>' +
							'</div>' +
						'</div>' +
						'<input type="hidden" name="thumbnail_id" value="' + ( node.thumbnail_id || 0 ) + '">' +
					'</div>' +
				'</section>' +
				'<div class="snb-panel-fields"><p class="snb-loading">Loading fields…</p></div>' +
			'</form>' +
			'<footer class="snb-panel-foot">' +
				'<span class="snb-panel-state" role="status"></span>' +
				'<a href="' + esc( node.edit_url ) + '">Edit in WordPress</a>' +
				'<a href="' + esc( node.permalink ) + '" target="_blank" rel="noopener">View</a>' +
				'<button type="button" class="button button-primary" data-panel="save">Save</button>' +
			'</footer>';

		document.body.appendChild( panelEl );
		app.classList.add( 'has-panel' );
		renderCourse();

		var form = $( 'form', panelEl );
		loadPanelFields( id );

		form.addEventListener( 'input', markDirty );
		form.addEventListener( 'change', markDirty );
		form.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '.snn-cf-add, .snn-cf-remove, .snn-cf-generate' ) ) {
				markDirty();
			}
		} );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			savePanel();
		} );

		$( '[data-panel="close"]', panelEl ).addEventListener( 'click', function () {
			closePanel();
		} );
		$( '[data-panel="save"]', panelEl ).addEventListener( 'click', savePanel );
		$$( '[data-panel="thumb-pick"]', panelEl ).forEach( function ( button ) {
			button.addEventListener( 'click', pickThumbnail );
		} );
		$( '[data-panel="thumb-remove"]', panelEl ).addEventListener( 'click', function () {
			setThumbnail( 0, '' );
		} );

		$( '[name="title"]', panelEl ).focus();
	}

	function thumbPreview( url ) {
		return url
			? '<img src="' + esc( url ) + '" alt="">'
			: '<span class="snb-thumb-empty">No image</span>';
	}

	/** Puts a picked (or removed) image into the panel form; saved with the rest. */
	function setThumbnail( id, url ) {
		if ( ! panelEl ) {
			return;
		}
		$( '[name="thumbnail_id"]', panelEl ).value = id || 0;
		$( '.snb-thumb-preview', panelEl ).innerHTML = thumbPreview( url );
		$( '.snb-thumb-remove', panelEl ).hidden = ! id;
		$( '.snb-thumb-actions [data-panel="thumb-pick"]', panelEl ).textContent = id ? 'Replace image' : 'Choose image';
		markDirty();
	}

	var thumbFrame = null;

	function pickThumbnail() {
		if ( ! window.wp || ! window.wp.media ) {
			toast( 'The WordPress media library is not available on this screen.', 'error' );
			return;
		}

		if ( ! thumbFrame ) {
			thumbFrame = window.wp.media( {
				title: 'Featured image',
				button: { text: 'Use as featured image' },
				library: { type: 'image' },
				multiple: false
			} );
			thumbFrame.on( 'select', function () {
				var image = thumbFrame.state().get( 'selection' ).first().toJSON();
				var sizes = image.sizes || {};
				var url = ( sizes.medium && sizes.medium.url ) || image.url;
				setThumbnail( image.id, url );
			} );
			// Preselect the current image so "Replace" opens where the author left off.
			thumbFrame.on( 'open', function () {
				var input = panelEl ? $( '[name="thumbnail_id"]', panelEl ) : null;
				var current = input ? parseInt( input.value, 10 ) : 0;
				thumbFrame.state().get( 'selection' ).reset( current ? [ window.wp.media.attachment( current ) ] : [] );
			} );
		}

		thumbFrame.open();
	}

	function markDirty( event ) {
		if ( ! state.panel || ( event && event.snbInit ) ) {
			return;
		}
		if ( ! state.panel.dirty ) {
			state.panel.dirty = true;
			panelState( 'Unsaved changes', 'is-dirty' );
		}
	}

	function panelState( text, cls ) {
		var el = panelEl ? $( '.snb-panel-state', panelEl ) : null;
		if ( el ) {
			el.textContent = text;
			el.className = 'snb-panel-state ' + ( cls || '' );
		}
	}

	function loadPanelFields( id ) {
		var holder = $( '.snb-panel-fields', panelEl );

		if ( ! C.fieldsEnabled ) {
			holder.innerHTML = '<p class="snb-muted">Course fields are turned off. Enable them on the <a href="' + esc( C.fieldsUrl ) + '">Course Fields</a> screen to edit videos, subtitles and more here.</p>';
			return;
		}

		// The field editor script reads the post id for "Generate from transcript".
		if ( window.SNN_CF ) {
			window.SNN_CF.postId = id;
		}

		api( 'GET', 'fields/' + id ).then( function ( data ) {
			if ( ! state.panel || state.panel.id !== id ) {
				return;
			}
			if ( ! data.html ) {
				holder.innerHTML = '<p class="snb-muted">No course fields apply to this ' + state.panel.kind + '. Pick where each field shows on the <a href="' + esc( C.fieldsUrl ) + '">Course Fields</a> screen.</p>';
				return;
			}
			holder.innerHTML = data.html;
			state.panel.fieldsLoaded = true;

			// Let the field script draw previews for values that came with the HTML.
			$$( '.snn-cf-picker-input', holder ).forEach( function ( input ) {
				var event = new Event( 'change', { bubbles: true } );
				event.snbInit = true;
				input.dispatchEvent( event );
			} );
		} ).catch( function ( err ) {
			holder.innerHTML = '<p class="snb-notice is-error">' + esc( err.message ) + '</p>';
		} );
	}

	function savePanel() {
		if ( ! state.panel || ! panelEl ) {
			return;
		}

		var panel = state.panel;
		var found = findNode( panel.id );
		var form = $( 'form', panelEl );
		var button = $( '[data-panel="save"]', panelEl );
		var title = $( '[name="title"]', form ).value.trim();

		if ( ! title ) {
			panelState( 'The title cannot be empty.', 'is-error' );
			$( '[name="title"]', form ).focus();
			return;
		}

		var details = { id: panel.id };
		var node = found ? found.node : {};
		if ( title !== node.title ) {
			details.title = title;
		}
		[ 'slug', 'excerpt', 'status' ].forEach( function ( key ) {
			var value = $( '[name="' + key + '"]', form ).value;
			if ( value !== ( node[ key ] || '' ) ) {
				details[ key ] = value;
			}
		} );
		var thumbnailId = parseInt( $( '[name="thumbnail_id"]', form ).value, 10 ) || 0;
		if ( thumbnailId !== ( node.thumbnail_id || 0 ) ) {
			details.thumbnail_id = thumbnailId;
		}

		button.disabled = true;
		panelState( 'Saving…', 'is-busy' );

		var chain = Object.keys( details ).length > 1
			? api( 'POST', 'update', details )
			: Promise.resolve( null );

		busy( chain.then( function ( fresh ) {
			if ( ! panel.fieldsLoaded ) {
				return fresh;
			}
			return api( 'POST', 'fields/' + panel.id, new FormData( form ) );
		} ) ).then( function ( fresh ) {
			if ( fresh ) {
				mergeNode( fresh );
			}
			if ( state.panel === panel ) {
				panel.dirty = false;
				panel.title = fresh ? fresh.title : panel.title;
				$( '.snb-panel-head h3', panelEl ).textContent = panel.title;
				if ( fresh && fresh.slug !== undefined ) {
					$( '[name="slug"]', form ).value = fresh.slug;
				}
				panelState( 'Saved', 'is-ok' );
			}
			renderCourse();
		} ).catch( function ( err ) {
			panelState( err.message, 'is-error' );
		} ).then( function () {
			button.disabled = false;
		} );
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( state.panel && state.panel.dirty ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	// ==========================================================
	// Event wiring
	// ==========================================================

	var clickTimer = null;

	app.addEventListener( 'click', function ( event ) {
		var t = event.target;

		var nav = t.closest( '[data-nav="list"]' );
		if ( nav ) {
			event.preventDefault();
			go( 0, true );
			return;
		}

		var card = t.closest( '.snb-card' );
		if ( card && ! event.metaKey && ! event.ctrlKey ) {
			event.preventDefault();
			go( parseInt( card.dataset.course, 10 ), true );
			return;
		}

		var focusBtn = t.closest( '[data-focus]' );
		if ( focusBtn ) {
			focusItem( parseInt( focusBtn.dataset.focus, 10 ) );
			return;
		}

		var actionEl = t.closest( '[data-action]' );
		var item = t.closest( '[data-id]' );
		var id = item ? parseInt( item.dataset.id, 10 ) : 0;
		var kind = item ? item.dataset.kind : '';

		if ( actionEl && 'SELECT' !== actionEl.tagName ) {
			var action = actionEl.dataset.action;
			event.preventDefault();

			switch ( action ) {
				case 'toggle':
					if ( state.collapsed[ id ] ) {
						delete state.collapsed[ id ];
					} else {
						state.collapsed[ id ] = true;
					}
					renderCourse();
					return;
				case 'expand-all':
					state.collapsed = {};
					renderCourse();
					return;
				case 'collapse-all':
					state.course.chapters.forEach( function ( ch ) {
						state.collapsed[ ch.id ] = true;
					} );
					renderCourse();
					return;
				case 'undo':
					undo();
					return;
				case 'outline':
					pasteDialog( state.course.id, 'outline' );
					return;
				case 'paste-lessons':
					pasteDialog( parseInt( actionEl.closest( '.snb-add' ).dataset.parent, 10 ), 'lessons' );
					return;
				case 'panel':
					openPanel( id );
					return;
				case 'menu':
					openMenu( actionEl, id, kind );
					return;
			}
		}

		// A plain click on a lesson row opens it; delayed so a double-click
		// can rename instead.
		if ( item && 'lesson' === kind && ! t.closest( 'select, input, button, a' ) ) {
			clearTimeout( clickTimer );
			clickTimer = setTimeout( function () {
				openPanel( id );
			}, 220 );
		}
	} );

	app.addEventListener( 'dblclick', function ( event ) {
		var name = event.target.closest( '.snb-name' );
		if ( ! name ) {
			return;
		}
		clearTimeout( clickTimer );
		var item = name.closest( '[data-id]' );
		if ( item ) {
			startRename( parseInt( item.dataset.id, 10 ) );
		}
	} );

	app.addEventListener( 'change', function ( event ) {
		var t = event.target;

		if ( t.matches( '[data-status-for]' ) ) {
			changeStatus( parseInt( t.dataset.statusFor, 10 ), t.dataset.kind, t.value, t );
			return;
		}
		if ( t.matches( '[data-action="new-status"]' ) ) {
			state.newStatus = t.value;
			writePref( 'snb-new-status', t.value );
		}
	} );

	app.addEventListener( 'input', function ( event ) {
		if ( event.target.classList.contains( 'snb-search' ) ) {
			state.search = event.target.value.trim();
			renderCards();
		}
	} );

	app.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		event.preventDefault();

		if ( form.classList.contains( 'snb-new-course' ) ) {
			var title = form.title.value.trim();
			if ( ! title ) {
				return;
			}
			var button = $( 'button', form );
			button.disabled = true;
			api( 'POST', 'create', { parent_id: 0, titles: [ title ], status: state.newStatus } ).then( function ( data ) {
				go( data.created[ 0 ].id, true );
			} ).catch( function ( err ) {
				button.disabled = false;
				toast( err.message, 'error' );
			} );
			return;
		}

		if ( form.classList.contains( 'snb-add' ) ) {
			var input = $( 'input', form );
			var value = input.value.trim();
			if ( ! value || input.disabled ) {
				return;
			}
			input.disabled = true;
			var parentId = parseInt( form.dataset.parent, 10 );

			createItems( parentId, [ value ] ).then( function ( created ) {
				input.value = '';
				input.disabled = false;
				var isChapter = parentId === state.course.id;
				renderCourse();

				// A new chapter goes straight to typing its first lesson; a new
				// lesson keeps focus in the same box for the next one.
				var next = isChapter
					? $( '.snb-add[data-parent="' + created[ 0 ].id + '"] input', app )
					: $( '.snb-add[data-parent="' + parentId + '"] input', app );
				if ( next ) {
					next.focus();
				}
			} ).catch( function ( err ) {
				input.disabled = false;
				input.focus();
				toast( err.message, 'error' );
			} );
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		// Save the side panel.
		if ( ( event.ctrlKey || event.metaKey ) && 's' === event.key.toLowerCase() && state.panel ) {
			event.preventDefault();
			savePanel();
			return;
		}

		if ( 'Escape' === event.key ) {
			if ( menuEl ) {
				closeMenu();
				return;
			}
			if ( state.panel && ! $( '.snb-modal' ) && ! $( '.snn-cf-modal.is-open' ) ) {
				closePanel();
			}
			return;
		}

		if ( 'course' !== state.view || ! app.contains( event.target ) ) {
			return;
		}

		var row = event.target.closest( '.snb-lesson, .snb-chapter-head' );
		if ( ! row || event.target.closest( 'input, select, textarea' ) ) {
			return;
		}
		var item = row.closest( '[data-id]' );
		var id = parseInt( item.dataset.id, 10 );

		if ( event.altKey && ( 'ArrowUp' === event.key || 'ArrowDown' === event.key ) ) {
			event.preventDefault();
			nudge( id, 'ArrowUp' === event.key ? -1 : 1 );
		} else if ( 'F2' === event.key ) {
			event.preventDefault();
			startRename( id );
		} else if ( 'Enter' === event.key && event.target === row ) {
			event.preventDefault();
			openPanel( id );
		}
	} );

	// ==========================================================
	// Boot
	// ==========================================================

	window.history.replaceState( { course: C.initialCourse || 0 }, '', courseUrl( C.initialCourse ) );

	if ( C.initialCourse ) {
		loadCourse( C.initialCourse );
	} else {
		loadCourses();
	}
} )();
