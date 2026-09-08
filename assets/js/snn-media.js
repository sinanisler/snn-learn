/**
 * SNN Learn — Media Library front end.
 *
 * Three cooperating pieces:
 *   1. an upload lane   — chunked, strictly one file at a time
 *   2. a processing lane — MP3 (ffmpeg.wasm) → subtitles → R2, also one at a time
 *   3. the library view  — listing, manual buttons, preview
 *
 * The two lanes are independent on purpose: post-processing a finished video
 * must never hold up the next upload.
 */
( function () {
	'use strict';

	var CFG = window.SNN_MEDIA;
	if ( ! CFG ) {
		return;
	}

	// ==========================================================
	// Small helpers
	// ==========================================================

	function $( sel, root ) {
		return ( root || document ).querySelector( sel );
	}

	function esc( str ) {
		return String( str == null ? '' : str ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function formatBytes( bytes ) {
		if ( ! bytes ) {
			return '0 B';
		}
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = Math.floor( Math.log( bytes ) / Math.log( 1024 ) );
		i = Math.min( i, units.length - 1 );
		return ( bytes / Math.pow( 1024, i ) ).toFixed( i === 0 ? 0 : 1 ) + ' ' + units[ i ];
	}

	function formatEta( seconds ) {
		if ( ! isFinite( seconds ) || seconds < 0 ) {
			return '';
		}
		if ( seconds < 60 ) {
			return Math.ceil( seconds ) + 's left';
		}
		if ( seconds < 3600 ) {
			return Math.floor( seconds / 60 ) + 'm ' + Math.round( seconds % 60 ) + 's left';
		}
		return Math.floor( seconds / 3600 ) + 'h ' + Math.ceil( ( seconds % 3600 ) / 60 ) + 'm left';
	}

	/**
	 * Builds a full endpoint URL.
	 *
	 * With plain permalinks `restUrl` is already `…?rest_route=/…`, so a query
	 * string in `path` has to be merged rather than appended with a second `?`.
	 */
	function endpoint( path ) {
		var split = path.indexOf( '?' );
		var route = split === -1 ? path : path.slice( 0, split );
		var query = split === -1 ? '' : path.slice( split + 1 );
		var url = CFG.restUrl + route;

		if ( query ) {
			url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + query;
		}
		return url;
	}

	/** REST call with the WP nonce attached. Rejects with a readable message. */
	function api( path, options ) {
		options = options || {};
		var headers = options.headers || {};
		headers[ 'X-WP-Nonce' ] = CFG.nonce;

		return fetch( endpoint( path ), {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: options.body
		} ).then( function ( response ) {
			return response.json().catch( function () {
				throw new Error( 'The server returned a response that could not be read (HTTP ' + response.status + ').' );
			} ).then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( ( data && data.message ) || 'Request failed with HTTP ' + response.status + '.' );
				}
				return data;
			} );
		} );
	}

	function form( fields ) {
		var fd = new FormData();
		Object.keys( fields ).forEach( function ( key ) {
			if ( fields[ key ] !== undefined && fields[ key ] !== null ) {
				fd.append( key, fields[ key ] );
			}
		} );
		return fd;
	}

	// ==========================================================
	// Log
	// ==========================================================

	var logEl = $( '#snn-log' );

	function log( message, kind ) {
		if ( ! logEl ) {
			return;
		}
		var line = document.createElement( 'div' );
		line.className = 'snn-log-line' + ( kind ? ' is-' + kind : '' );
		var time = new Date().toTimeString().slice( 0, 8 );
		line.textContent = time + '  ' + message;
		logEl.appendChild( line );
		logEl.scrollTop = logEl.scrollHeight;
	}

	// ==========================================================
	// ffmpeg.wasm — lazy, single shared instance
	// ==========================================================

	var ffmpegPromise = null;

	// The last stretch of ffmpeg's own stderr. ffmpeg reports the real reason a
	// conversion failed here and nowhere else, so failures quote it back.
	var ffmpegLog = [];
	var FFMPEG_LOG_KEEP = 40;

	function loadScript( url ) {
		return new Promise( function ( resolve, reject ) {
			var tag = document.createElement( 'script' );
			tag.src = url;
			tag.onload = resolve;
			tag.onerror = function () {
				reject( new Error( 'Could not load ' + url + '. Check that the plugin\'s assets/ffmpeg/ folder was deployed.' ) );
			};
			document.head.appendChild( tag );
		} );
	}

	/**
	 * Turns anything thrown into a readable sentence.
	 *
	 * ffmpeg.wasm rejects with whatever its worker posted back rather than with
	 * an Error (classes.js does `rejects[id](data)`), so `err.message` is very
	 * often undefined. Reporting that verbatim is how a real failure ends up
	 * displayed as a generic "could not convert" with no cause attached.
	 */
	function describeError( err ) {
		if ( ! err ) {
			return 'Unknown failure (no error detail was reported).';
		}
		if ( typeof err === 'string' ) {
			return err;
		}
		if ( err.message ) {
			return String( err.message );
		}
		if ( err.name || err.code || err.errno ) {
			// Emscripten filesystem errors look like { name, code, errno }.
			return 'ffmpeg error ' + ( err.name || err.code || err.errno );
		}
		try {
			var json = JSON.stringify( err );
			if ( json && '{}' !== json ) {
				return json.slice( 0, 300 );
			}
		} catch ( e ) {}
		return String( err );
	}

	/** The tail of ffmpeg's log, for appending to an error message. */
	function ffmpegLogTail( lines ) {
		var tail = ffmpegLog.slice( -( lines || 6 ) ).join( ' | ' );
		return tail ? ' — ffmpeg said: ' + tail : '';
	}

	function getFFmpeg( onStatus ) {
		if ( ffmpegPromise ) {
			return ffmpegPromise;
		}

		ffmpegPromise = ( async function () {
			if ( CFG.ffmpegBundled && CFG.ffmpegMissing && CFG.ffmpegMissing.length ) {
				throw new Error(
					'These ffmpeg.wasm files are missing from the plugin: ' + CFG.ffmpegMissing.join( ', ' ) +
					'. Re-deploy assets/ffmpeg/, or set a custom ffmpeg URL in Media Settings.'
				);
			}

			if ( onStatus ) {
				onStatus( 'Loading ffmpeg.wasm…' );
			}
			log( 'Loading ffmpeg.wasm from ' + CFG.ffmpegBase, 'info' );

			if ( ! window.FFmpegWASM ) {
				await loadScript( CFG.ffmpegBase + '/ffmpeg.js' );
			}
			if ( ! window.FFmpegWASM || ! window.FFmpegWASM.FFmpeg ) {
				throw new Error( 'ffmpeg.js loaded but did not define FFmpegWASM.FFmpeg — the file may be truncated or the wrong build.' );
			}

			var ffmpeg = new window.FFmpegWASM.FFmpeg();

			ffmpeg.on( 'log', function ( entry ) {
				if ( ! entry || ! entry.message ) {
					return;
				}
				ffmpegLog.push( entry.message );
				if ( ffmpegLog.length > FFMPEG_LOG_KEEP ) {
					ffmpegLog.shift();
				}
			} );

			// Both files are served from this origin, so they load as ordinary
			// URLs. No blob copies, and no classWorkerURL: ffmpeg.js resolves
			// its worker chunk relative to its own URL, which is same-origin
			// too. Cross-origin worker construction is blocked outright, which
			// is the failure mode this arrangement avoids.
			await ffmpeg.load( {
				coreURL: CFG.ffmpegCore + '/ffmpeg-core.js',
				wasmURL: CFG.ffmpegCore + '/ffmpeg-core.wasm'
			} );

			log( 'ffmpeg.wasm ready.', 'ok' );
			return ffmpeg;
		} )().catch( function ( err ) {
			ffmpegPromise = null; // allow a later retry
			throw new Error( 'ffmpeg.wasm could not start: ' + describeError( err ) );
		} );

		return ffmpegPromise;
	}

	/**
	 * Extracts a low-bitrate mono MP3 from a video, entirely in the browser.
	 *
	 * The source is mounted through WORKERFS rather than copied in with
	 * writeFile. WORKERFS reads from the File/Blob on demand, so a 2 GB lesson
	 * no longer needs 2 GB of WebAssembly heap just to be opened — which is the
	 * limit large uploads used to hit.
	 *
	 * @param {Blob}     blob      Source video (a File where possible).
	 * @param {string}   extension Container extension, used to name the input.
	 * @param {Function} onStatus  Progress reporter.
	 * @return {Promise<Blob>} The encoded MP3.
	 */
	async function extractMp3( blob, extension, onStatus ) {
		var maxBytes = CFG.mp3MaxSourceMb * 1024 * 1024;
		if ( maxBytes > 0 && blob.size > maxBytes ) {
			throw new Error(
				'This video is ' + formatBytes( blob.size ) + ', above the ' + CFG.mp3MaxSourceMb +
				' MB browser-conversion limit. Raise it in Media Settings, or skip the MP3 for this file.'
			);
		}

		var ffmpeg = await getFFmpeg( onStatus );

		var mountPoint = '/snn';
		var inputName = 'source.' + ( extension || 'mp4' );
		var inputPath = mountPoint + '/' + inputName;
		var output = 'out.mp3';
		var mounted = false;

		var onProgress = function ( event ) {
			if ( onStatus && event && typeof event.progress === 'number' ) {
				var pct = Math.max( 0, Math.min( 100, Math.round( event.progress * 100 ) ) );
				onStatus( 'Extracting audio… ' + pct + '%', pct );
			}
		};
		ffmpeg.on( 'progress', onProgress );
		ffmpegLog = [];

		try {
			if ( onStatus ) {
				onStatus( 'Preparing video…' );
			}

			await ffmpeg.createDir( mountPoint );

			// WORKERFS takes real Files directly; anything else goes in as a
			// named blob. Either way nothing is copied into wasm memory.
			var mountData = ( typeof File !== 'undefined' && blob instanceof File )
				? { files: [ new File( [ blob ], inputName, { type: blob.type } ) ] }
				: { blobs: [ { name: inputName, data: blob } ] };

			await ffmpeg.mount( 'WORKERFS', mountData, mountPoint );
			mounted = true;

			if ( onStatus ) {
				onStatus( 'Extracting audio…' );
			}

			// `-map a` fails loudly on a video with no audio track, rather than
			// quietly producing a zero-byte file.
			var code = await ffmpeg.exec( [
				'-i', inputPath,
				'-map', 'a',
				'-vn', '-sn', '-dn',
				'-ac', '1',
				'-ar', String( CFG.mp3SampleRate || '22050' ),
				'-c:a', 'libmp3lame',
				'-b:a', String( CFG.mp3Bitrate || '32k' ),
				'-f', 'mp3',
				output
			] );

			// exec RESOLVES with the exit code — it does not reject on failure,
			// so this check is the only thing standing between a failed run and
			// a confusing filesystem error from the readFile below.
			if ( 0 !== code ) {
				var hasAudio = ffmpegLog.some( function ( line ) {
					return /Stream #\d+:\d+.*: Audio:/.test( line );
				} );
				if ( ! hasAudio ) {
					throw new Error( 'This video has no audio track, so there is nothing to transcribe.' );
				}
				throw new Error( 'ffmpeg exited with code ' + code + ffmpegLogTail() );
			}

			var data = await ffmpeg.readFile( output );
			if ( ! data || ! data.length ) {
				throw new Error( 'ffmpeg produced an empty audio file' + ffmpegLogTail() );
			}

			return new Blob( [ data.buffer || data ], { type: 'audio/mpeg' } );
		} finally {
			ffmpeg.off( 'progress', onProgress );
			// Always release the mount and the output, or the next file in the
			// queue starts against a dirty filesystem.
			if ( mounted ) {
				try {
					await ffmpeg.unmount( mountPoint );
				} catch ( e ) {}
			}
			try {
				await ffmpeg.deleteDir( mountPoint );
			} catch ( e ) {}
			try {
				await ffmpeg.deleteFile( output );
			} catch ( e ) {}
		}
	}

	// ==========================================================
	// Upload lane — one file at a time
	// ==========================================================

	var uploadQueue = [];
	var uploading = false;

	var dropzone = $( '#snn-dropzone' );
	var fileInput = $( '#snn-file-input' );
	var queueWrap = $( '#snn-queue-wrap' );
	var queueEl = $( '#snn-queue' );
	var queueSummary = $( '#snn-queue-summary' );

	function acceptFiles( files ) {
		var accepted = 0;

		files.forEach( function ( file ) {
			var ext = ( file.name.split( '.' ).pop() || '' ).toLowerCase();

			if ( CFG.allowedExts.indexOf( ext ) === -1 ) {
				log( 'Skipped ' + file.name + ' — ".' + ext + '" is not an allowed type.', 'err' );
				return;
			}
			if ( CFG.maxFileMb > 0 && file.size > CFG.maxFileMb * 1024 * 1024 ) {
				log( 'Skipped ' + file.name + ' — larger than the ' + CFG.maxFileMb + ' MB limit.', 'err' );
				return;
			}

			uploadQueue.push( { file: file, ext: ext, row: renderQueueRow( file ) } );
			accepted++;
		} );

		if ( accepted ) {
			queueWrap.hidden = false;
			updateQueueSummary();
			runUploadLane();
		}
	}

	function renderQueueRow( file ) {
		var row = document.createElement( 'div' );
		row.className = 'snn-queue-item';
		row.innerHTML =
			'<div>' +
				'<div class="snn-queue-name">' + esc( file.name ) + '</div>' +
				'<div class="snn-queue-meta">' +
					'<span>' + formatBytes( file.size ) + '</span>' +
					'<span class="snn-queue-pct">0%</span>' +
					'<span class="snn-queue-speed"></span>' +
					'<span class="snn-queue-eta"></span>' +
				'</div>' +
				'<div class="snn-progress"><div class="snn-progress-fill"></div></div>' +
			'</div>' +
			'<span class="snn-badge snn-badge-wait snn-queue-status">Queued</span>';
		queueEl.appendChild( row );
		return row;
	}

	function setQueueStatus( row, text, badgeClass, stateClass ) {
		var badge = $( '.snn-queue-status', row );
		badge.textContent = text;
		badge.className = 'snn-badge ' + badgeClass + ' snn-queue-status';
		row.className = 'snn-queue-item' + ( stateClass ? ' ' + stateClass : '' );
	}

	function updateQueueSummary() {
		var pending = uploadQueue.length + ( uploading ? 1 : 0 );
		queueSummary.textContent = pending
			? pending + ' file' + ( pending === 1 ? '' : 's' ) + ' remaining'
			: 'All uploads finished';
	}

	function runUploadLane() {
		if ( uploading || ! uploadQueue.length ) {
			updateQueueSummary();
			return;
		}
		uploading = true;
		var job = uploadQueue.shift();
		updateQueueSummary();

		uploadOne( job ).then( function ( item ) {
			setQueueStatus( job.row, 'Uploaded', 'snn-badge-ok', 'is-done' );
			log( 'Uploaded ' + job.file.name + ' → ' + item.filename, 'ok' );

			// Hand the in-memory File to the processing lane so the MP3 can be
			// made without downloading the video back from the server.
			enqueueProcessing( item, job.file );
			refreshLibrary();
		} ).catch( function ( err ) {
			setQueueStatus( job.row, 'Failed', 'snn-badge-err', 'is-error' );
			$( '.snn-queue-eta', job.row ).textContent = describeError( err );
			log( 'Upload failed for ' + job.file.name + ': ' + describeError( err ), 'err' );
		} ).then( function () {
			uploading = false;
			runUploadLane();
		} );
	}

	/** Sends one file to the server as an ordered series of chunks. */
	function uploadOne( job ) {
		var file = job.file;
		var row = job.row;
		var fill = $( '.snn-progress-fill', row );
		var pctEl = $( '.snn-queue-pct', row );
		var speedEl = $( '.snn-queue-speed', row );
		var etaEl = $( '.snn-queue-eta', row );

		var chunkSize = CFG.chunkSize;
		var totalChunks = Math.max( 1, Math.ceil( file.size / chunkSize ) );
		var uploadId = 'snn' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 9 );
		var startedAt = Date.now();
		var confirmed = 0;

		setQueueStatus( row, 'Uploading', 'snn-badge-work', '' );
		log( 'Uploading ' + file.name + ' (' + formatBytes( file.size ) + ', ' + totalChunks + ' chunks)', 'info' );

		function paint( bytes ) {
			var pct = file.size ? Math.min( 100, Math.round( ( bytes / file.size ) * 100 ) ) : 100;
			fill.style.width = pct + '%';
			pctEl.textContent = pct + '%';

			var elapsed = ( Date.now() - startedAt ) / 1000;
			if ( elapsed > 0.4 && bytes > 0 ) {
				var speed = bytes / elapsed;
				speedEl.textContent = formatBytes( speed ) + '/s';
				var remaining = file.size - bytes;
				etaEl.textContent = remaining > 0 ? '· ' + formatEta( remaining / speed ) : '';
			}
		}

		function sendChunk( index ) {
			return new Promise( function ( resolve, reject ) {
				var start = index * chunkSize;
				var end = Math.min( start + chunkSize, file.size );
				var fd = form( {
					chunk: file.slice( start, end ),
					chunk_index: index,
					total_chunks: totalChunks,
					file_id: uploadId,
					original_name: file.name
				} );

				var xhr = new XMLHttpRequest();
				xhr.open( 'POST', endpoint( 'chunk' ), true );
				xhr.setRequestHeader( 'X-WP-Nonce', CFG.nonce );
				xhr.withCredentials = true;
				xhr.timeout = 600000;

				xhr.upload.onprogress = function ( event ) {
					if ( event.lengthComputable ) {
						paint( confirmed + event.loaded );
					}
				};
				xhr.onload = function () {
					var data;
					try {
						data = JSON.parse( xhr.responseText );
					} catch ( e ) {
						reject( new Error( 'The server sent an unreadable response (HTTP ' + xhr.status + ').' ) );
						return;
					}
					if ( xhr.status < 200 || xhr.status >= 300 || ! data.success ) {
						reject( new Error( data.message || 'Chunk ' + ( index + 1 ) + ' was rejected.' ) );
						return;
					}
					confirmed = end;
					paint( confirmed );
					resolve( data );
				};
				xhr.onerror = function () {
					reject( new Error( 'Network error while sending chunk ' + ( index + 1 ) + '.' ) );
				};
				xhr.ontimeout = function () {
					reject( new Error( 'Chunk ' + ( index + 1 ) + ' timed out.' ) );
				};

				xhr.send( fd );
			} );
		}

		// Chunks must arrive in order — the server appends them blindly.
		return ( async function () {
			for ( var i = 0; i < totalChunks; i++ ) {
				var result = await sendChunk( i );
				if ( result.done ) {
					paint( file.size );
					speedEl.textContent = '';
					etaEl.textContent = '';
					return result.item;
				}
			}
			throw new Error( 'The upload finished without the server confirming the file.' );
		} )();
	}

	// ==========================================================
	// Processing lane — MP3 → subtitles → R2, one item at a time
	// ==========================================================

	var processQueue = [];
	var processing = false;
	var queuedIds = {};

	/**
	 * Adds an item to the processing lane.
	 *
	 * @param {Object}   item       Library item.
	 * @param {File}     sourceFile The original File, when it is still in memory.
	 * @param {string[]} steps      Steps to run ('mp3' | 'vtt' | 'r2'), or null
	 *                              to derive them from the automation settings.
	 * @param {boolean}  force      Re-run steps that are already finished.
	 */
	function enqueueProcessing( item, sourceFile, steps, force ) {
		if ( queuedIds[ item.id ] ) {
			log( item.original_name + ' is already queued.', 'warn' );
			return;
		}
		queuedIds[ item.id ] = true;
		processQueue.push( {
			item: item,
			file: sourceFile || null,
			steps: steps || null,
			force: !! force
		} );
		runProcessLane();
	}

	function runProcessLane() {
		if ( processing || ! processQueue.length ) {
			return;
		}
		processing = true;
		var job = processQueue.shift();

		processOne( job ).catch( function ( err ) {
			log( 'Processing stopped for ' + job.item.original_name + ': ' + describeError( err ), 'err' );
		} ).then( function () {
			delete queuedIds[ job.item.id ];
			processing = false;
			refreshLibrary();
			runProcessLane();
		} );
	}

	/** Runs whichever pipeline steps this item still needs, in order. */
	async function processOne( job ) {
		var steps = job.steps;

		if ( ! steps ) {
			steps = [];
			if ( CFG.mp3Auto ) {
				steps.push( 'mp3' );
			}
			if ( CFG.vttAuto ) {
				steps.push( 'vtt' );
			}
			if ( CFG.r2AutoSync ) {
				steps.push( 'r2' );
			}
		}

		var item = job.item;

		for ( var i = 0; i < steps.length; i++ ) {
			// A step failing is not fatal to the rest: a video with no audio
			// track should still reach R2, for instance.
			try {
				item = await runStep( steps[ i ], item, job.file, job.force );
			} catch ( err ) {
				log( 'Step "' + steps[ i ] + '" failed for ' + item.original_name + ': ' + describeError( err ), 'err' );
			}
		}

		setItemStatus( item.id, '' );
	}

	/**
	 * Performs one pipeline step and returns the item as the server now sees it.
	 *
	 * @param {string} step       'mp3' | 'vtt' | 'r2'
	 * @param {Object} item       Current item state.
	 * @param {File}   sourceFile In-memory original, when available.
	 * @param {bool}   force      Run even when the step is already done.
	 */
	async function runStep( step, item, sourceFile, force ) {
		var name = item.original_name;

		function status( text, pct ) {
			setItemStatus( item.id, text, pct );
		}

		// ---- MP3 via ffmpeg.wasm, in this browser ----
		if ( 'mp3' === step ) {
			if ( 'done' === item.mp3_status && ! force ) {
				return item;
			}
			if ( ! sourceFile && ! item.local_exists ) {
				throw new Error( 'The video is no longer stored on this server, so it cannot be converted here.' );
			}

			try {
				await api( 'mp3-status', { method: 'POST', body: form( { id: item.id, status: 'processing' } ) } );

				var source = sourceFile;
				if ( ! source ) {
					status( 'Downloading video…' );
					log( 'Fetching ' + item.filename + ' back from the server for conversion.', 'info' );
					var response = await fetch( item.local_url, { credentials: 'same-origin' } );
					if ( ! response.ok ) {
						throw new Error( 'Could not read the stored video (HTTP ' + response.status + ').' );
					}
					source = await response.blob();
				}

				log( 'Extracting audio from ' + name + '…', 'info' );
				var mp3 = await extractMp3( source, item.extension, status );

				status( 'Uploading MP3…' );
				var saved = await api( 'mp3', {
					method: 'POST',
					body: form( { id: item.id, mp3: new File( [ mp3 ], 'audio.mp3', { type: 'audio/mpeg' } ) } )
				} );
				log( 'MP3 ready for ' + name + ' (' + formatBytes( mp3.size ) + ').', 'ok' );
				return saved.item;
			} catch ( err ) {
				// Record the failure server-side so the badge survives a reload.
				await api( 'mp3-status', {
					method: 'POST',
					body: form( { id: item.id, status: 'error', message: describeError( err ) } )
				} ).catch( function () {} );
				throw err;
			}
		}

		// ---- Subtitles via OpenRouter, on the server ----
		if ( 'vtt' === step ) {
			if ( 'done' === item.vtt_status && ! force ) {
				return item;
			}
			if ( ! CFG.sttConfigured ) {
				log( 'Skipping subtitles for ' + name + ' — no OpenRouter API key is configured.', 'warn' );
				return item;
			}
			if ( 'done' !== item.mp3_status ) {
				log( 'Skipping subtitles for ' + name + ' — they are transcribed from the MP3, which is not ready.', 'warn' );
				return item;
			}

			status( 'Transcribing…' );
			log( 'Transcribing ' + name + ' via OpenRouter…', 'info' );
			var transcribed = await api( 'transcribe', { method: 'POST', body: form( { id: item.id } ) } );
			log( 'Subtitles ready for ' + name + ' (' + transcribed.cues + ' cues).', 'ok' );
			return transcribed.item;
		}

		// ---- Cloudflare R2 ----
		if ( 'r2' === step ) {
			if ( 'synced' === item.r2_status && ! force ) {
				return item;
			}
			if ( ! CFG.r2Configured ) {
				log( 'Skipping R2 sync for ' + name + ' — R2 is not configured.', 'warn' );
				return item;
			}

			status( 'Syncing to R2…' );
			log( 'Syncing ' + name + ' to Cloudflare R2…', 'info' );
			var synced = await api( 'r2-sync', { method: 'POST', body: form( { id: item.id } ) } );
			log( 'Synced ' + name + ' to R2.', 'ok' );
			return synced.item;
		}

		return item;
	}

	// Live step labels are held here as well as painted, so a library refresh
	// triggered by another lane does not wipe the row that is still working.
	var liveStatus = {};

	/** Paints a live step label onto one library row, if it is on screen. */
	function setItemStatus( id, text ) {
		if ( text ) {
			liveStatus[ id ] = text;
		} else {
			delete liveStatus[ id ];
		}
		paintItemStatus( id );
	}

	function paintItemStatus( id ) {
		var el = document.querySelector( '.snn-item[data-id="' + id + '"]' );
		if ( ! el ) {
			return;
		}
		var text = liveStatus[ id ] || '';
		var slot = $( '.snn-item-progress', el );
		if ( slot ) {
			slot.textContent = text;
		}
		el.classList.toggle( 'is-busy', !! text );
	}

	function repaintAllStatuses() {
		Object.keys( liveStatus ).forEach( paintItemStatus );
	}

	// ==========================================================
	// Library view
	// ==========================================================

	var itemsEl = $( '#snn-items' );
	var totalEl = $( '#snn-total' );
	var searchEl = $( '#snn-search' );
	var paginationEl = $( '#snn-pagination' );

	var state = { page: 1, perPage: 25, search: '', items: [], total: 0 };

	var STATUS_BADGES = {
		done: 'snn-badge-ok',
		synced: 'snn-badge-ok',
		processing: 'snn-badge-work',
		syncing: 'snn-badge-work',
		queued: 'snn-badge-wait',
		pending: 'snn-badge-off',
		error: 'snn-badge-err'
	};

	function badge( label, status ) {
		return '<span class="snn-badge ' + ( STATUS_BADGES[ status ] || 'snn-badge-off' ) + '">' +
			esc( label ) + ': ' + esc( status ) + '</span>';
	}

	function urlRow( label, url ) {
		if ( ! url ) {
			return '';
		}
		return '<div class="snn-url-row"><span class="snn-url-label">' + esc( label ) + '</span>' +
			'<code class="snn-copy" data-url="' + esc( url ) + '" title="Click to copy">' + esc( url ) + '</code></div>';
	}

	function renderItems() {
		if ( ! state.items.length ) {
			itemsEl.innerHTML = '<p class="snn-muted snn-empty">' +
				( state.search ? 'Nothing matches that search.' : 'No videos yet — drop some above to get started.' ) +
				'</p>';
			paginationEl.innerHTML = '';
			return;
		}

		itemsEl.innerHTML = state.items.map( function ( item ) {
			var canConvertHere = item.local_exists;

			return '<div class="snn-item" data-id="' + item.id + '">' +
				'<div class="snn-item-top">' +
					'<div>' +
						'<p class="snn-item-name">' + esc( item.original_name ) + '</p>' +
						'<div class="snn-item-sub">' +
							'<span>' + esc( item.filesize_h ) + '</span>' +
							'<span>' + esc( item.uploaded_date ) + '</span>' +
							( item.uploaded_by ? '<span>by ' + esc( item.uploaded_by ) + '</span>' : '' ) +
							( item.mp3_size_h ? '<span>MP3 ' + esc( item.mp3_size_h ) + '</span>' : '' ) +
							( item.local_exists ? '' : '<span>local copy removed</span>' ) +
							'<span class="snn-item-progress"></span>' +
						'</div>' +
						'<div class="snn-item-badges">' +
							badge( 'MP3', item.mp3_status ) +
							badge( 'VTT', item.vtt_status ) +
							badge( 'R2', item.r2_status ) +
						'</div>' +
					'</div>' +
					'<div class="snn-item-actions">' +
						( item.playback_url ? '<button type="button" class="snn-btn snn-btn-ghost snn-btn-sm" data-act="preview" data-id="' + item.id + '">Preview</button>' : '' ) +
						( canConvertHere ? '<button type="button" class="snn-btn snn-btn-ghost snn-btn-sm" data-act="mp3" data-id="' + item.id + '">' + ( item.mp3_status === 'done' ? 'Redo MP3' : 'Make MP3' ) + '</button>' : '' ) +
						'<button type="button" class="snn-btn snn-btn-ghost snn-btn-sm" data-act="vtt" data-id="' + item.id + '"' + ( item.mp3_status === 'done' ? '' : ' disabled title="An MP3 is needed first"' ) + '>' + ( item.vtt_status === 'done' ? 'Redo subtitles' : 'Make subtitles' ) + '</button>' +
						( item.local_exists ? '<button type="button" class="snn-btn snn-btn-ghost snn-btn-sm" data-act="r2" data-id="' + item.id + '"' + ( CFG.r2Configured ? '' : ' disabled title="R2 is not configured"' ) + '>' + ( item.r2_status === 'synced' ? 'Re-sync to R2' : 'Sync to R2' ) + '</button>' : '' ) +
						'<button type="button" class="snn-btn snn-btn-danger snn-btn-sm" data-act="delete" data-id="' + item.id + '">Delete</button>' +
					'</div>' +
				'</div>' +
				( item.error_msg ? '<div class="snn-item-error">' + esc( item.error_msg ) + '</div>' : '' ) +
				'<div class="snn-item-urls">' +
					urlRow( 'Video', item.playback_url ) +
					urlRow( 'Subtitle', item.r2_vtt_url || item.vtt_url ) +
					urlRow( 'Audio', item.r2_mp3_url || item.mp3_url ) +
				'</div>' +
			'</div>';
		} ).join( '' );

		renderPagination();
		repaintAllStatuses();
	}

	function renderPagination() {
		var pages = Math.ceil( state.total / state.perPage );
		if ( pages <= 1 ) {
			paginationEl.innerHTML = '';
			return;
		}
		paginationEl.innerHTML =
			'<button type="button" class="snn-btn snn-btn-ghost snn-btn-sm" data-page="' + ( state.page - 1 ) + '"' + ( state.page <= 1 ? ' disabled' : '' ) + '>Previous</button>' +
			'<span class="snn-muted">Page ' + state.page + ' of ' + pages + '</span>' +
			'<button type="button" class="snn-btn snn-btn-ghost snn-btn-sm" data-page="' + ( state.page + 1 ) + '"' + ( state.page >= pages ? ' disabled' : '' ) + '>Next</button>';
	}

	var refreshTimer = null;

	function refreshLibrary( immediate ) {
		clearTimeout( refreshTimer );
		refreshTimer = setTimeout( function () {
			api( 'items?page=' + state.page + '&per_page=' + state.perPage + '&search=' + encodeURIComponent( state.search ) )
				.then( function ( data ) {
					state.items = data.items;
					state.total = data.total;
					totalEl.textContent = '(' + data.total + ')';
					renderItems();
				} )
				.catch( function ( err ) {
					itemsEl.innerHTML = '<p class="snn-muted snn-empty">Could not load the library: ' + esc( describeError( err ) ) + '</p>';
				} );
		}, immediate ? 0 : 250 );
	}

	// ==========================================================
	// Events
	// ==========================================================

	if ( dropzone ) {
		[ 'dragenter', 'dragover' ].forEach( function ( type ) {
			dropzone.addEventListener( type, function ( event ) {
				event.preventDefault();
				dropzone.classList.add( 'is-over' );
			} );
		} );
		[ 'dragleave', 'dragend' ].forEach( function ( type ) {
			dropzone.addEventListener( type, function () {
				dropzone.classList.remove( 'is-over' );
			} );
		} );
		dropzone.addEventListener( 'drop', function ( event ) {
			event.preventDefault();
			dropzone.classList.remove( 'is-over' );
			acceptFiles( Array.prototype.slice.call( event.dataTransfer.files ) );
		} );
	}

	// A drop anywhere else on the page should not navigate away from wp-admin.
	[ 'dragover', 'drop' ].forEach( function ( type ) {
		window.addEventListener( type, function ( event ) {
			if ( dropzone && ! dropzone.contains( event.target ) ) {
				event.preventDefault();
			}
		} );
	} );

	var browseBtn = $( '#snn-browse' );
	if ( browseBtn ) {
		browseBtn.addEventListener( 'click', function () {
			fileInput.click();
		} );
	}
	if ( fileInput ) {
		fileInput.addEventListener( 'change', function ( event ) {
			acceptFiles( Array.prototype.slice.call( event.target.files ) );
			event.target.value = '';
		} );
	}

	if ( searchEl ) {
		var searchTimer = null;
		searchEl.addEventListener( 'input', function () {
			clearTimeout( searchTimer );
			searchTimer = setTimeout( function () {
				state.search = searchEl.value.trim();
				state.page = 1;
				refreshLibrary( true );
			}, 300 );
		} );
	}

	var refreshBtn = $( '#snn-refresh' );
	if ( refreshBtn ) {
		refreshBtn.addEventListener( 'click', function () {
			refreshLibrary( true );
		} );
	}

	var processAllBtn = $( '#snn-process-all' );
	if ( processAllBtn ) {
		processAllBtn.addEventListener( 'click', function () {
			var pending = state.items.filter( function ( item ) {
				return item.mp3_status !== 'done' || item.vtt_status !== 'done' ||
					( CFG.r2Configured && item.r2_status !== 'synced' );
			} );
			if ( ! pending.length ) {
				log( 'Nothing pending on this page.', 'info' );
				return;
			}
			log( 'Queued ' + pending.length + ' item(s) for processing.', 'info' );
			pending.forEach( function ( item ) {
				// A manual sweep runs every step regardless of the automation
				// toggles, but still skips work that is already done.
				enqueueProcessing( item, null, [ 'mp3', 'vtt', 'r2' ], false );
			} );
		} );
	}

	if ( paginationEl ) {
		paginationEl.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '[data-page]' );
			if ( ! btn || btn.disabled ) {
				return;
			}
			state.page = parseInt( btn.dataset.page, 10 );
			refreshLibrary( true );
		} );
	}

	if ( itemsEl ) {
		itemsEl.addEventListener( 'click', function ( event ) {
			var copy = event.target.closest( '.snn-copy' );
			if ( copy ) {
				navigator.clipboard.writeText( copy.dataset.url ).then( function () {
					var original = copy.textContent;
					copy.textContent = 'Copied to clipboard';
					setTimeout( function () {
						copy.textContent = original;
					}, 1200 );
				} );
				return;
			}

			var btn = event.target.closest( '[data-act]' );
			if ( ! btn || btn.disabled ) {
				return;
			}
			var id = parseInt( btn.dataset.id, 10 );
			var item = state.items.filter( function ( row ) {
				return row.id === id;
			} )[ 0 ];
			if ( ! item ) {
				return;
			}
			handleAction( btn.dataset.act, item );
		} );
	}

	function handleAction( action, item ) {
		if ( 'preview' === action ) {
			openModal( item );
			return;
		}

		if ( 'delete' === action ) {
			if ( ! window.confirm( 'Delete "' + item.original_name + '" permanently?\n\nThe video, its MP3 and its subtitles are removed from this server and from R2.' ) ) {
				return;
			}
			api( 'item?id=' + item.id, { method: 'DELETE' } ).then( function () {
				log( 'Deleted ' + item.original_name + '.', 'ok' );
				refreshLibrary( true );
			} ).catch( function ( err ) {
				log( 'Delete failed: ' + describeError( err ), 'err' );
				window.alert( 'Delete failed: ' + describeError( err ) );
			} );
			return;
		}

		// Manual buttons run exactly one step, forced, and still go through the
		// lane so an item can never have two pipelines running at once.
		enqueueProcessing( item, null, [ action ], true );
	}

	// ==========================================================
	// Preview modal
	// ==========================================================

	var modal = $( '#snn-modal' );
	var modalVideo = $( '#snn-modal-video' );
	var modalTitle = $( '#snn-modal-title' );
	var modalMeta = $( '#snn-modal-meta' );

	function openModal( item ) {
		modalTitle.textContent = item.original_name;
		modalVideo.innerHTML = '';
		modalVideo.src = item.playback_url;

		var vtt = item.r2_vtt_url || item.vtt_url;
		if ( vtt ) {
			var track = document.createElement( 'track' );
			track.kind = 'subtitles';
			track.label = 'Auto subtitles';
			track.src = vtt;
			track.default = true;
			modalVideo.appendChild( track );
		}

		modalMeta.innerHTML =
			esc( item.filesize_h ) + ' · uploaded ' + esc( item.uploaded_date ) +
			( item.r2_synced_at ? ' · synced to R2 ' + esc( item.r2_synced_at ) : '' ) +
			( vtt ? '' : ' · no subtitles yet' );

		modal.hidden = false;
	}

	function closeModal() {
		modal.hidden = true;
		modalVideo.pause();
		modalVideo.removeAttribute( 'src' );
		modalVideo.innerHTML = '';
		modalVideo.load();
	}

	if ( modal ) {
		modal.addEventListener( 'click', function ( event ) {
			if ( event.target.hasAttribute( 'data-close' ) ) {
				closeModal();
			}
		} );
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! modal.hidden ) {
				closeModal();
			}
		} );
	}

	// Guard against losing an in-flight upload or conversion to a stray reload.
	window.addEventListener( 'beforeunload', function ( event ) {
		if ( uploading || processing ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	// ==========================================================
	// Boot
	// ==========================================================

	log( 'Media library ready. Chunk size ' + formatBytes( CFG.chunkSize ) +
		', MP3 ' + ( CFG.mp3Auto ? 'automatic' : 'manual' ) +
		', subtitles ' + ( CFG.vttAuto ? 'automatic' : 'manual' ) +
		', R2 ' + ( CFG.r2AutoSync ? 'automatic' : 'manual' ) + '.', 'info' );

	refreshLibrary( true );
} )();
