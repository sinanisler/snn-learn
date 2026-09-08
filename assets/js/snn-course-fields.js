/**
 * SNN Learn — Course Fields
 *
 * Three separate jobs on three different screens:
 *   1. the post editor  — repeater rows, the SNN media picker, WP media picker
 *   2. the posts list   — feeding stored values into Quick Edit
 *   3. the settings page — add / reorder / remove field definitions
 */
( function () {
	'use strict';

	var CONFIG = window.SNN_CF || {};

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

	function api( path ) {
		return fetch( CONFIG.restUrl + path, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': CONFIG.nonce }
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function formatDuration( seconds ) {
		if ( ! seconds ) {
			return '';
		}
		var total = Math.round( seconds );
		var m = Math.floor( total / 60 );
		var s = total % 60;
		return m + ':' + ( s < 10 ? '0' : '' ) + s;
	}

	// ==========================================================
	// SNN media library picker
	// ==========================================================

	var modal = null;
	var modalState = { mode: 'video', onPick: null, search: '', items: [] };

	function buildModal() {
		if ( modal ) {
			return modal;
		}

		modal = document.createElement( 'div' );
		modal.className = 'snn-cf-modal';
		modal.innerHTML =
			'<div class="snn-cf-modal-backdrop"></div>' +
			'<div class="snn-cf-modal-box" role="dialog" aria-modal="true" aria-label="Choose from media library">' +
				'<div class="snn-cf-modal-head">' +
					'<strong class="snn-cf-modal-title">Choose Video</strong>' +
					'<input type="search" class="snn-cf-modal-search" placeholder="Search media…">' +
					'<button type="button" class="button snn-cf-modal-close">Close</button>' +
				'</div>' +
				'<div class="snn-cf-modal-body"><p class="snn-cf-modal-msg">Loading…</p></div>' +
			'</div>';

		document.body.appendChild( modal );

		$( '.snn-cf-modal-close', modal ).addEventListener( 'click', closeModal );
		$( '.snn-cf-modal-backdrop', modal ).addEventListener( 'click', closeModal );

		var searchInput = $( '.snn-cf-modal-search', modal );
		var searchTimer = null;
		searchInput.addEventListener( 'input', function () {
			clearTimeout( searchTimer );
			searchTimer = setTimeout( function () {
				modalState.search = searchInput.value.trim();
				loadItems();
			}, 250 );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && modal.classList.contains( 'is-open' ) ) {
				closeModal();
			}
		} );

		return modal;
	}

	function openModal( mode, onPick ) {
		buildModal();

		modalState.mode = mode;
		modalState.onPick = onPick;
		modalState.search = '';

		$( '.snn-cf-modal-title', modal ).textContent = 'vtt' === mode ? 'Choose a .vtt Subtitle' : 'Choose a Video';
		$( '.snn-cf-modal-search', modal ).value = '';

		modal.classList.add( 'is-open' );
		loadItems();
		$( '.snn-cf-modal-search', modal ).focus();
	}

	function closeModal() {
		if ( modal ) {
			modal.classList.remove( 'is-open' );
		}
	}

	function loadItems() {
		var body = $( '.snn-cf-modal-body', modal );
		body.innerHTML = '<p class="snn-cf-modal-msg">Loading…</p>';

		var query = 'items?per_page=100';
		if ( modalState.search ) {
			query += '&search=' + encodeURIComponent( modalState.search );
		}

		api( query ).then( function ( data ) {
			if ( ! data || ! data.items ) {
				body.innerHTML = '<p class="snn-cf-modal-msg">Could not load the media library.</p>';
				return;
			}

			var items = data.items;

			// Only items that actually have a subtitle track can fill a .vtt field.
			if ( 'vtt' === modalState.mode ) {
				items = items.filter( function ( item ) {
					return item.r2_vtt_url || item.vtt_url;
				} );
			} else {
				items = items.filter( function ( item ) {
					return item.playback_url;
				} );
			}

			modalState.items = items;
			renderItems( items, body );
		} ).catch( function () {
			body.innerHTML = '<p class="snn-cf-modal-msg">Could not reach the media library.</p>';
		} );
	}

	function renderItems( items, body ) {
		if ( ! items.length ) {
			body.innerHTML = 'vtt' === modalState.mode
				? '<p class="snn-cf-modal-msg">No media with a generated .vtt yet. Transcribe a video in the Media Library first.</p>'
				: '<p class="snn-cf-modal-msg">No media found.</p>';
			return;
		}

		var grid = document.createElement( 'div' );
		grid.className = 'snn-cf-modal-grid';

		items.forEach( function ( item ) {
			var card = document.createElement( 'button' );
			card.type = 'button';
			card.className = 'snn-cf-media-card';
			card.innerHTML =
				'<span class="snn-cf-media-thumb"' +
					( item.poster_url ? ' style="background-image:url(' + esc( item.poster_url ) + ')"' : '' ) +
				'></span>' +
				'<span class="snn-cf-media-name">' + esc( item.original_name || item.filename ) + '</span>' +
				'<span class="snn-cf-media-meta">' +
					esc( [ formatDuration( item.duration ), item.filesize_h ].filter( Boolean ).join( ' · ' ) ) +
				'</span>';

			card.addEventListener( 'click', function () {
				if ( modalState.onPick ) {
					modalState.onPick( item );
				}
				closeModal();
			} );

			grid.appendChild( card );
		} );

		body.innerHTML = '';
		body.appendChild( grid );
	}

	/** The label suggested for a subtitle track: the file name without extension. */
	function trackLabel( item ) {
		return String( item.original_name || item.filename || '' ).replace( /\.[^.]+$/, '' );
	}

	// ==========================================================
	// Post editor — pickers and previews
	// ==========================================================

	function pickerInputFor( button ) {
		return $( '.snn-cf-picker-input', button.closest( '.snn-cf-picker' ) );
	}

	function refreshPreview( input ) {
		var preview = input.closest( '.snn-cf-input' )
			? $( '[data-preview-for="' + input.id + '"]', input.closest( '.snn-cf-input' ) )
			: null;

		if ( ! preview ) {
			return;
		}

		var value = input.value.trim();
		if ( ! value ) {
			preview.innerHTML = '';
			return;
		}

		if ( /\.(jpe?g|png|gif|webp|avif|svg)(\?|$)/i.test( value ) ) {
			preview.innerHTML = '<img src="' + esc( value ) + '" alt="">';
		} else {
			preview.innerHTML = '<span class="snn-cf-preview-url">' + esc( value.split( '/' ).pop() ) + '</span>';
		}
	}

	function bindEditor( root ) {
		$$( '.snn-cf-picker-input', root ).forEach( refreshPreview );
	}

	document.addEventListener( 'click', function ( event ) {
		// ---- SNN media library picker ----
		var pick = event.target.closest( '.snn-cf-pick' );
		if ( pick ) {
			event.preventDefault();
			var input = pickerInputFor( pick );

			openModal( pick.dataset.mode, function ( item ) {
				if ( 'vtt' === pick.dataset.mode ) {
					input.value = item.r2_vtt_url || item.vtt_url || '';

					// Subtitle rows pair a .vtt with a track name — fill an
					// empty label with the file name so the row is usable
					// straight away.
					var row = input.closest( '.snn-cf-row' );
					var label = row ? row.querySelectorAll( 'input[type="text"], textarea' )[ 1 ] : null;
					if ( label && ! label.value.trim() ) {
						label.value = trackLabel( item );
					}
				} else {
					input.value = item.playback_url || '';
					autofillSubtitle( item );
				}

				refreshPreview( input );
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				touchField( input );
			} );
			return;
		}

		// ---- WordPress media picker ----
		var wpPick = event.target.closest( '.snn-cf-wp-media' );
		if ( wpPick && window.wp && window.wp.media ) {
			event.preventDefault();
			var wpInput = pickerInputFor( wpPick );
			var frame = window.wp.media( { title: 'Select File', multiple: false } );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				wpInput.value = 'id' === wpPick.dataset.return ? attachment.id : attachment.url;
				refreshPreview( wpInput );
			} );

			frame.open();
			return;
		}

		// ---- Collapse: one field ----
		var head = event.target.closest( '.snn-cf-head' );
		if ( head ) {
			event.preventDefault();
			var headField = head.closest( '.snn-cf-field' );
			setOpen( headField, ! isOpen( headField ) );
			return;
		}

		// ---- Collapse: whole group ----
		var toggleAll = event.target.closest( '.snn-cf-toggle-all' );
		if ( toggleAll ) {
			event.preventDefault();
			var open = '1' === toggleAll.dataset.open;
			$$( '.snn-cf-field', toggleAll.closest( '.postbox, .snn-cf-metabox' ) || document ).forEach( function ( f ) {
				setOpen( f, open );
			} );
			return;
		}

		// ---- Generate from transcript ----
		var generateBtn = event.target.closest( '.snn-cf-generate' );
		if ( generateBtn ) {
			event.preventDefault();
			generate( generateBtn );
			return;
		}

		// ---- Repeater: add row ----
		var add = event.target.closest( '.snn-cf-add' );
		if ( add ) {
			event.preventDefault();
			addRepeaterRow( add.closest( '.snn-cf-field' ) );
			return;
		}

		// ---- Repeater: remove row ----
		var remove = event.target.closest( '.snn-cf-remove' );
		if ( remove ) {
			event.preventDefault();
			var rows = remove.closest( '.snn-cf-rows' );
			remove.closest( '.snn-cf-row' ).remove();
			reindexRows( rows );
			touchField( rows );
		}
	} );

	/** Clones the last row, blanks it, and appends it with the next index. */
	function addRepeaterRow( field ) {
		var rows = $( '.snn-cf-rows', field );
		var last = rows.lastElementChild;
		if ( ! last ) {
			return;
		}

		var clone = last.cloneNode( true );

		$$( 'input, textarea, select', clone ).forEach( function ( el ) {
			if ( 'checkbox' === el.type || 'radio' === el.type ) {
				el.checked = false;
			} else if ( 'hidden' !== el.type ) {
				el.value = '';
			}
			el.removeAttribute( 'id' );
		} );
		$$( '.snn-cf-preview', clone ).forEach( function ( el ) {
			el.innerHTML = '';
		} );

		rows.appendChild( clone );
		reindexRows( rows );

		var first = $( 'input:not([type=hidden]), textarea', clone );
		if ( first ) {
			first.focus();
		}
	}

	/**
	 * Renumbers snn_cf[slug][N][col] after a row is added or removed.
	 *
	 * Anchored at the end so a numeric field slug cannot be renumbered by
	 * mistake — every repeater input ends in [N][a] or [N][b].
	 */
	function reindexRows( rows ) {
		$$( '.snn-cf-row', rows ).forEach( function ( row, index ) {
			$$( '[name]', row ).forEach( function ( el ) {
				el.name = el.name.replace( /\[\d+\]\[([ab])\]$/, '[' + index + '][$1]' );
			} );
		} );
	}

	/**
	 * Drops a picked video's subtitle track into the subtitles field.
	 *
	 * Only when that field is still empty. Appending a fourth track to an
	 * existing en/tr/de set is worse than doing nothing, so an author who has
	 * already curated the list keeps what they have.
	 */
	function autofillSubtitle( item ) {
		var url = item.r2_vtt_url || item.vtt_url;
		if ( ! url || ! CONFIG.subtitleField ) {
			return;
		}

		var field = $( '.snn-cf-field[data-slug="' + CONFIG.subtitleField + '"]' );
		if ( ! field ) {
			return;
		}

		var filled = $$( '.snn-cf-row input[type="text"]', field ).some( function ( input ) {
			return input.value.trim() !== '';
		} );
		if ( filled ) {
			return;
		}

		var firstRow = $$( '.snn-cf-row', field )[ 0 ];
		if ( ! firstRow ) {
			return;
		}

		var boxes = firstRow.querySelectorAll( 'input[type="text"]' );
		if ( boxes[ 0 ] ) {
			boxes[ 0 ].value = url;
		}
		if ( boxes[ 1 ] ) {
			boxes[ 1 ].value = CONFIG.subtitleLabel || 'en';
		}

		touchField( boxes[ 0 ] );
		flashField( field, 'Subtitle track added from the picked video.' );
	}

	// ==========================================================
	// Collapsing
	// ==========================================================

	function setOpen( field, open ) {
		var head = $( '.snn-cf-head', field );
		var body = $( '.snn-cf-body', field );
		if ( ! head || ! body ) {
			return;
		}
		head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		body.hidden = ! open;
		field.classList.toggle( 'is-open', !! open );
	}

	function isOpen( field ) {
		return 'true' === $( '.snn-cf-head', field ).getAttribute( 'aria-expanded' );
	}

	/** Recomputes the collapsed header summary after an edit. */
	function refreshSummary( field ) {
		var el = $( '.snn-cf-summary', field );
		if ( ! el ) {
			return;
		}

		var repeater = '1' === field.dataset.repeater;
		var type = field.dataset.type;
		var text = '';

		if ( 'true_false' === type ) {
			var box = $( 'input[type="checkbox"]', field );
			text = box && box.checked ? 'Yes' : 'No';
		} else if ( repeater ) {
			var filled = $$( '.snn-cf-row', field ).filter( function ( row ) {
				return $$( 'input:not([type=hidden]), textarea', row ).some( function ( input ) {
					return input.value.trim() !== '';
				} );
			} ).length;
			text = filled ? filled + ( 1 === filled ? ' row' : ' rows' ) : '';
		} else {
			var values = $$( 'input:not([type=hidden]), textarea', field ).map( function ( input ) {
				return input.value.trim();
			} ).filter( Boolean );

			text = values.join( ' · ' );
			if ( /^https?:\/\//i.test( text ) ) {
				text = decodeURIComponent( text.split( '?' )[ 0 ].split( '/' ).pop() );
			}
			text = text.replace( /\s+/g, ' ' );
			if ( text.length > 60 ) {
				text = text.slice( 0, 60 ) + '…';
			}
		}

		el.textContent = text || 'empty';
		el.classList.toggle( 'is-empty', ! text );
	}

	/** Marks a field as edited: refresh its header summary. */
	function touchField( el ) {
		var field = el && el.closest ? el.closest( '.snn-cf-field' ) : null;
		if ( field ) {
			refreshSummary( field );
		}
	}

	function flashField( field, message ) {
		var status = $( '.snn-cf-status', field );
		if ( ! status ) {
			return;
		}
		status.textContent = message;
		status.className = 'snn-cf-status is-ok';
		setTimeout( function () {
			if ( status.textContent === message ) {
				status.textContent = '';
				status.className = 'snn-cf-status';
			}
		}, 6000 );
	}

	// ==========================================================
	// Generation from the lesson transcript
	// ==========================================================

	/** The video URL currently in the form, saved or not. */
	function currentVideoUrl() {
		var field = $( '.snn-cf-field[data-type="video"]' );
		if ( ! field ) {
			return '';
		}
		var input = $( '.snn-cf-picker-input', field );
		return input ? input.value.trim() : '';
	}

	function generate( button ) {
		var field = button.closest( '.snn-cf-field' );
		var status = $( '.snn-cf-status', field );
		var text = $( '.snn-cf-generate-text', button );
		var original = text.textContent;

		button.disabled = true;
		text.textContent = 'Generating…';
		status.className = 'snn-cf-status is-busy';
		status.textContent = 'Reading the transcript… this can take up to a minute on a long lesson.';

		fetch( CONFIG.generateUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CONFIG.nonce
			},
			body: JSON.stringify( {
				post_id: CONFIG.postId,
				field: button.dataset.slug,
				video_url: currentVideoUrl()
			} )
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( data ) {
			if ( ! data || ! data.success ) {
				throw new Error( ( data && data.message ) || 'Generation failed.' );
			}
			fillRows( field, data.rows );
			status.className = 'snn-cf-status is-ok';
			status.textContent = 'Filled ' + data.rows.length +
				( 1 === data.rows.length ? ' row' : ' rows' ) + '. Review before saving.';
		} ).catch( function ( err ) {
			status.className = 'snn-cf-status is-error';
			status.textContent = err.message || 'Generation failed.';
		} ).then( function () {
			button.disabled = false;
			text.textContent = original;
		} );
	}

	/**
	 * Replaces a field's rows with generated ones.
	 *
	 * The result goes into the form, never straight to post meta — the author
	 * edits what came back and saves the post themselves.
	 */
	function fillRows( field, rows ) {
		var container = $( '.snn-cf-rows', field );
		var template = container.firstElementChild;
		if ( ! template ) {
			return;
		}

		var blank = template.cloneNode( true );
		$$( 'input:not([type=hidden]), textarea', blank ).forEach( function ( el ) {
			el.value = '';
			el.removeAttribute( 'id' );
		} );

		container.innerHTML = '';

		rows.forEach( function ( row ) {
			var node = blank.cloneNode( true );
			var boxes = $$( 'input:not([type=hidden]), textarea', node );
			if ( boxes[ 0 ] ) {
				boxes[ 0 ].value = row[ 0 ] || '';
			}
			if ( boxes[ 1 ] ) {
				boxes[ 1 ].value = row[ 1 ] || '';
			}
			container.appendChild( node );
		} );

		reindexRows( container );
		refreshSummary( field );
		setOpen( field, true );
	}

	// ==========================================================
	// Quick Edit — copy the row's stored values into the form
	// ==========================================================

	function bindQuickEdit() {
		if ( ! window.inlineEditPost || ! window.inlineEditPost.edit ) {
			return;
		}

		var original = window.inlineEditPost.edit;

		window.inlineEditPost.edit = function ( id ) {
			var result = original.apply( this, arguments );

			var postId = 'object' === typeof id ? this.getId( id ) : id;
			if ( ! postId ) {
				return result;
			}

			var holder = $( '#post-' + postId + ' .snn-cf-qe-data' );
			var form = $( '#edit-' + postId );
			if ( ! holder || ! form ) {
				return result;
			}

			var values;
			try {
				values = JSON.parse( holder.dataset.values || '{}' );
			} catch ( e ) {
				return result;
			}

			Object.keys( values ).forEach( function ( slug ) {
				var input = form.querySelector( '[name="snn_cf[' + slug + ']"]' );
				if ( ! input ) {
					return;
				}
				if ( 'checkbox' === input.type ) {
					input.checked = '1' === String( values[ slug ] );
				} else {
					input.value = values[ slug ];
				}
			} );

			return result;
		};
	}

	// ==========================================================
	// Settings page — the field registry editor
	// ==========================================================

	function bindSettings() {
		var list = $( '#snn-cf-field-list' );
		var template = $( '#snn-cf-field-template' );
		if ( ! list || ! template ) {
			return;
		}

		var addButton = $( '#snn-cf-add-field' );
		var empty = $( '.snn-cf-empty' );

		function setEditorOpen( editor, open ) {
			var toggle = $( '.snn-cf-field-toggle', editor );
			var body = $( '.snn-cf-field-body', editor );
			if ( ! toggle || ! body ) {
				return;
			}
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			body.hidden = ! open;
			editor.classList.toggle( 'is-open', !! open );
		}

		function isEditorOpen( editor ) {
			return 'true' === $( '.snn-cf-field-toggle', editor ).getAttribute( 'aria-expanded' );
		}

		/** Rebuilds the collapsed bar summary from the row's own inputs. */
		function refreshEditorMeta( editor ) {
			var meta = $( '.snn-cf-field-meta', editor );
			if ( ! meta ) {
				return;
			}

			function val( suffix ) {
				var el = editor.querySelector( '[name$="[' + suffix + ']"]' );
				return el ? el.value.trim() : '';
			}

			function flag( suffix ) {
				var boxes = editor.querySelectorAll( 'input[type="checkbox"][name$="[' + suffix + ']"]' );
				return boxes.length ? boxes[ boxes.length - 1 ].checked : false;
			}

			var typeSelect = $( '.snn-cf-type-select', editor );
			var parts = [];

			if ( val( 'slug' ) ) {
				parts.push( val( 'slug' ) );
			}
			if ( typeSelect ) {
				parts.push( typeSelect.options[ typeSelect.selectedIndex ].text );
			}
			if ( flag( 'repeater' ) ) {
				parts.push( 'repeater' );
			}
			if ( flag( 'quick_edit' ) ) {
				parts.push( 'quick edit' );
			}
			if ( flag( 'ai_enabled' ) ) {
				parts.push( 'AI' );
			}
			if ( val( 'width' ) ) {
				parts.push( val( 'width' ) + '%' );
			}

			meta.textContent = parts.join( ' · ' );
		}

		function refreshEmpty() {
			if ( empty ) {
				empty.hidden = list.children.length > 0;
			}
		}

		/** Field definitions post as snn_cf_fields[N][...] — N must be unique. */
		function reindexFields() {
			$$( '.snn-cf-field-editor', list ).forEach( function ( editor, index ) {
				editor.dataset.index = index;
				$$( '[name^="snn_cf_fields["]', editor ).forEach( function ( el ) {
					el.name = el.name.replace( /^snn_cf_fields\[[^\]]*\]/, 'snn_cf_fields[' + index + ']' );
				} );
			} );
			refreshEmpty();
		}

		addButton.addEventListener( 'click', function () {
			var html = template.innerHTML.replace( /__i__/g, String( Date.now() ) );
			var holder = document.createElement( 'div' );
			holder.innerHTML = html;

			var editor = $( '.snn-cf-field-editor', holder );
			list.appendChild( editor );
			reindexFields();

			setEditorOpen( editor, true );
			editor.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			var firstInput = $( 'input[type="text"]', editor );
			if ( firstInput ) {
				firstInput.focus();
			}
		} );

		list.addEventListener( 'click', function ( event ) {
			var editor = event.target.closest( '.snn-cf-field-editor' );
			if ( ! editor ) {
				return;
			}

			// The toggle sits inside the bar, so it is checked before the
			// move/remove buttons that share it.
			if ( event.target.closest( '.snn-cf-field-toggle' ) ) {
				event.preventDefault();
				setEditorOpen( editor, ! isEditorOpen( editor ) );
				return;
			}

			if ( event.target.closest( '.snn-cf-move-up' ) ) {
				event.preventDefault();
				if ( editor.previousElementSibling ) {
					list.insertBefore( editor, editor.previousElementSibling );
					reindexFields();
				}
			} else if ( event.target.closest( '.snn-cf-move-down' ) ) {
				event.preventDefault();
				if ( editor.nextElementSibling ) {
					list.insertBefore( editor.nextElementSibling, editor );
					reindexFields();
				}
			} else if ( event.target.closest( '.snn-cf-delete' ) ) {
				event.preventDefault();
				var title = $( '.snn-cf-field-title', editor ).textContent;
				if ( window.confirm( 'Remove the "' + title + '" field?\n\nExisting post meta is left untouched.' ) ) {
					editor.remove();
					reindexFields();
				}
			}
		} );

		$$( '.snn-cf-editor-toggle-all' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var open = '1' === button.dataset.open;
				$$( '.snn-cf-field-editor', list ).forEach( function ( editor ) {
					setEditorOpen( editor, open );
				} );
			} );
		} );

		// The collapsed bar mirrors the row's own inputs, so keep it honest
		// while the author types.
		list.addEventListener( 'input', function ( event ) {
			var editor = event.target.closest( '.snn-cf-field-editor' );
			if ( ! editor ) {
				return;
			}
			if ( event.target.classList.contains( 'snn-cf-label-input' ) ) {
				$( '.snn-cf-field-title', editor ).textContent = event.target.value || 'New Field';
			}
			refreshEditorMeta( editor );
		} );

		list.addEventListener( 'change', function ( event ) {
			var editor = event.target.closest( '.snn-cf-field-editor' );
			if ( editor ) {
				refreshEditorMeta( editor );
			}
		} );

		var restore = $( '.snn-cf-restore' );
		if ( restore ) {
			restore.addEventListener( 'click', function ( event ) {
				if ( ! window.confirm( 'Replace every field below with the shipped defaults?' ) ) {
					event.preventDefault();
				}
			} );
		}

		refreshEmpty();
	}

	// ==========================================================

	document.addEventListener( 'DOMContentLoaded', function () {
		bindEditor( document );
		bindQuickEdit();
		bindSettings();

		// PHP renders the summaries; recompute once so a value the server
		// summarised differently (an unsaved draft, say) still lines up.
		$$( '.snn-cf-field' ).forEach( refreshSummary );
	} );

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.classList && event.target.classList.contains( 'snn-cf-picker-input' ) ) {
			refreshPreview( event.target );
		}
		touchField( event.target );
	} );

	// Debounced so a long textarea does not recompute a summary per keystroke.
	var summaryTimer = null;
	document.addEventListener( 'input', function ( event ) {
		if ( ! event.target.closest || ! event.target.closest( '.snn-cf-field' ) ) {
			return;
		}
		clearTimeout( summaryTimer );
		summaryTimer = setTimeout( function () {
			touchField( event.target );
		}, 300 );
	} );
} )();
