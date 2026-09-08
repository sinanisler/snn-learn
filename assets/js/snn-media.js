/**
 * SNN Learn — Media Library front end.
 *
 * Three cooperating pieces:
 *   1. an upload lane   — chunked, strictly one file at a time
 *   2. a processing lane — thumbnail → MP3 (ffmpeg.wasm) → subtitles → R2,
 *                          also one item at a time
 *   3. the library view  — listing, tagging, manual buttons, preview
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

	// The log is a history, not a transcript of this page view: it survives
	// reloads so a failure noticed tomorrow can still be traced back. Only the
	// most recent LOG_KEEP entries are held, so the store stays small.
	var LOG_KEY = 'snnMediaLog';
	var LOG_KEEP = 100;
	var logEntries = [];

	function loadLog() {
		try {
			var raw = window.localStorage.getItem( LOG_KEY );
			var parsed = raw ? JSON.parse( raw ) : [];
			if ( Array.isArray( parsed ) ) {
				logEntries = parsed.slice( -LOG_KEEP );
			}
		} catch ( e ) {
			// A private window, a full quota, or a value from an older build —
			// none of which is worth failing the whole screen over.
			logEntries = [];
		}
	}

	function saveLog() {
		try {
			window.localStorage.setItem( LOG_KEY, JSON.stringify( logEntries ) );
		} catch ( e ) {}
	}

	function logLineEl( entry ) {
		var line = document.createElement( 'div' );
		line.className = 'snn-log-line' + ( entry.kind ? ' is-' + entry.kind : '' );
		line.textContent = entry.at + '  ' + entry.message;
		return line;
	}

	function renderLog() {
		if ( ! logEl ) {
			return;
		}
		logEl.innerHTML = '';
		if ( ! logEntries.length ) {
			logEl.innerHTML = '<div class="snn-log-line is-muted">No log entries yet.</div>';
			return;
		}
		var frag = document.createDocumentFragment();
		logEntries.forEach( function ( entry ) {
			frag.appendChild( logLineEl( entry ) );
		} );
		logEl.appendChild( frag );
		logEl.scrollTop = logEl.scrollHeight;
	}

	function log( message, kind ) {
		var now = new Date();
		var entry = {
			at: now.toLocaleDateString() + ' ' + now.toTimeString().slice( 0, 8 ),
			message: String( message ),
			kind: kind || ''
		};

		logEntries.push( entry );
		if ( logEntries.length > LOG_KEEP ) {
			logEntries = logEntries.slice( -LOG_KEEP );
		}
		saveLog();

		if ( ! logEl ) {
			return;
		}
		// Appending one node beats re-rendering 100 on every pipeline message.
		var muted = logEl.querySelector( '.is-muted' );
		if ( muted ) {
			logEl.innerHTML = '';
		}
		logEl.appendChild( logLineEl( entry ) );
		while ( logEl.children.length > LOG_KEEP ) {
			logEl.removeChild( logEl.firstChild );
		}
		logEl.scrollTop = logEl.scrollHeight;
	}

	loadLog();
	renderLog();

	var logClearBtn = $( '#snn-log-clear' );
	if ( logClearBtn ) {
		logClearBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( 'Clear the stored log history?' ) ) {
				return;
			}
			logEntries = [];
			saveLog();
			renderLog();
			log( 'Log history cleared.', 'info' );
		} );
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
			if ( CFG.ffmpegMissing && CFG.ffmpegMissing.length ) {
				throw new Error(
					'These ffmpeg.wasm files are missing from the plugin: ' + CFG.ffmpegMissing.join( ', ' ) +
					'. Re-deploy the assets/ffmpeg/ folder.'
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
	 * writeFile. WORKERFS reads from the File/Blob on demand, so a multi-gigabyte
	 * lesson does not need a matching amount of WebAssembly heap just to be
	 * opened — which is what used to make large uploads fail.
	 *
	 * @param {Blob}     blob      Source video (a File where possible).
	 * @param {string}   extension Container extension, used to name the input.
	 * @param {Function} onStatus  Progress reporter.
	 * @return {Promise<Blob>} The encoded MP3.
	 */
	async function extractMp3( blob, extension, onStatus ) {
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
	// Thumbnails — one frame, via a plain <video> and a canvas
	// ==========================================================

	/**
	 * Grabs a single frame and encodes it as a small JPEG.
	 *
	 * This deliberately does not go through ffmpeg.wasm: the browser already
	 * has a hardware video decoder, and asking it for one frame is instant and
	 * costs no 30 MB wasm download. ffmpeg stays where it is genuinely needed —
	 * re-encoding a whole audio track.
	 *
	 * @param {File|Blob|string} source A file still in memory, or a URL to seek.
	 * @return {Promise<Blob>} JPEG blob.
	 */
	function captureThumbnail( source ) {
		return new Promise( function ( resolve, reject ) {
			var video = document.createElement( 'video' );
			var objectUrl = null;
			var settled = false;
			var timer = null;

			function cleanup() {
				clearTimeout( timer );
				video.removeAttribute( 'src' );
				try {
					video.load();
				} catch ( e ) {}
				if ( objectUrl ) {
					URL.revokeObjectURL( objectUrl );
				}
			}

			function fail( message ) {
				if ( settled ) {
					return;
				}
				settled = true;
				cleanup();
				reject( new Error( message ) );
			}

			function done( blob ) {
				if ( settled ) {
					return;
				}
				settled = true;
				cleanup();
				resolve( blob );
			}

			function draw() {
				if ( settled ) {
					return;
				}
				var width = video.videoWidth;
				var height = video.videoHeight;
				if ( ! width || ! height ) {
					fail( 'The browser reported no video dimensions, so no frame could be captured.' );
					return;
				}

				var target = Math.min( CFG.thumbWidth || 480, width );
				var canvas = document.createElement( 'canvas' );
				canvas.width = target;
				canvas.height = Math.max( 1, Math.round( ( height / width ) * target ) );

				try {
					canvas.getContext( '2d' ).drawImage( video, 0, 0, canvas.width, canvas.height );
					// A cross-origin frame taints the canvas and toBlob throws a
					// SecurityError — which is why a local copy is preferred.
					canvas.toBlob( function ( blob ) {
						if ( blob && blob.size ) {
							done( blob );
						} else {
							fail( 'The captured frame could not be encoded as a JPEG.' );
						}
					}, 'image/jpeg', 0.72 );
				} catch ( err ) {
					fail( 'The frame could not be read: ' + describeError( err ) +
						' (a remote video needs CORS headers allowing this site).' );
				}
			}

			video.muted = true;
			video.playsInline = true;
			video.preload = 'auto';

			video.addEventListener( 'error', function () {
				fail( 'The browser could not decode this video, so no thumbnail was made.' );
			} );

			video.addEventListener( 'loadeddata', function () {
				var wanted = CFG.thumbSeek > 0 ? CFG.thumbSeek : 1;
				var duration = isFinite( video.duration ) ? video.duration : 0;

				// A clip shorter than the configured offset is sampled at its
				// midpoint instead of failing to seek past its own end.
				var seek = duration > 0 ? Math.min( wanted, duration / 2 ) : wanted;

				if ( seek <= 0.05 ) {
					draw();
					return;
				}
				video.addEventListener( 'seeked', draw, { once: true } );
				try {
					video.currentTime = seek;
				} catch ( e ) {
					draw();
				}
			}, { once: true } );

			if ( 'string' === typeof source ) {
				video.crossOrigin = 'anonymous';
				video.src = source;
			} else {
				objectUrl = URL.createObjectURL( source );
				video.src = objectUrl;
			}

			timer = setTimeout( function () {
				fail( 'Timed out waiting for a frame to decode.' );
			}, 45000 );
		} );
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

			// The tag choice is captured now: changing the picker mid-batch must
			// not retroactively re-tag files already queued under it.
			uploadQueue.push( {
				file: file,
				ext: ext,
				tagIds: selectedUploadTagIds(),
				row: renderQueueRow( file )
			} );
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

			if ( item.tags && item.tags.length ) {
				log( 'Tagged ' + item.original_name + ' with ' + item.tags.map( function ( tag ) {
					return tag.name;
				} ).join( ', ' ) + '.', 'ok' );
			}

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
					original_name: file.name,
					// Only the final chunk creates the row, but sending this
					// every time keeps the request shape uniform.
					tag_ids: job.tagIds.join( ',' )
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
			if ( CFG.thumbAuto ) {
				steps.push( 'thumb' );
			}
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

		// ---- Poster frame, straight from the browser's video decoder ----
		if ( 'thumb' === step ) {
			if ( 'done' === item.thumb_status && ! force ) {
				return item;
			}

			// A same-origin source is preferred: a canvas drawn from a
			// cross-origin video cannot be read back at all.
			var thumbSource = sourceFile || item.local_url || item.playback_url;
			if ( ! thumbSource ) {
				throw new Error( 'There is no readable copy of this video to grab a frame from.' );
			}

			try {
				status( 'Grabbing thumbnail…' );
				var jpeg = await captureThumbnail( thumbSource );
				var stored = await api( 'thumb', {
					method: 'POST',
					body: form( { id: item.id, thumb: new File( [ jpeg ], 'thumb.jpg', { type: 'image/jpeg' } ) } )
				} );
				log( 'Thumbnail ready for ' + name + ' (' + formatBytes( jpeg.size ) + ').', 'ok' );
				return stored.item;
			} catch ( err ) {
				// Record it, so the row shows "error" rather than sitting on
				// "queued" and being retried by every sweep forever.
				await api( 'thumb', { method: 'POST', body: form( { id: item.id, status: 'error' } ) } ).catch( function () {} );
				throw err;
			}
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
			if ( ! item.local_exists ) {
				log( 'Skipping R2 sync for ' + name + ' — the local copy is gone, so there is nothing to upload.', 'warn' );
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
	var tagFilterEl = $( '#snn-tagfilter' );
	var tagPickerEl = $( '#snn-upload-tagpicker' );

	var state = { page: 1, perPage: 25, search: '', tag: 0, items: [], total: 0, tags: [] };

	// Which rows the editor has opened. Held out here so a refresh triggered by
	// a background lane does not snap every open row shut again.
	var expanded = {};

	// Tags to stamp on the next upload. Remembered across reloads because a
	// batch of lessons is usually dropped in over several sittings.
	var UPLOAD_TAGS_KEY = 'snnMediaUploadTags';
	var uploadTagIds = {};

	function loadUploadTags() {
		try {
			var raw = window.localStorage.getItem( UPLOAD_TAGS_KEY );
			( raw ? JSON.parse( raw ) : [] ).forEach( function ( id ) {
				uploadTagIds[ id ] = true;
			} );
		} catch ( e ) {}
	}

	function selectedUploadTagIds() {
		return Object.keys( uploadTagIds )
			.filter( function ( id ) {
				return uploadTagIds[ id ] && tagById( id );
			} )
			.map( Number );
	}

	function saveUploadTags() {
		try {
			window.localStorage.setItem( UPLOAD_TAGS_KEY, JSON.stringify( selectedUploadTagIds() ) );
		} catch ( e ) {}
	}

	function tagById( id ) {
		id = parseInt( id, 10 );
		return state.tags.filter( function ( tag ) {
			return tag.id === id;
		} )[ 0 ] || null;
	}

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

	/**
	 * Ink for a chip filled with `hex`: black, unless the fill is dark enough
	 * that black would disappear into it.
	 *
	 * Tag colours are picked freely, so neither ink works on its own — pale
	 * yellow needs black, navy needs white. This is the sRGB relative
	 * luminance, the same measure the WCAG contrast ratio is built on.
	 */
	function tagInk( hex ) {
		var m = /^#([0-9a-f]{6})$/i.exec( String( hex || '' ) );
		if ( ! m ) {
			return '#111827';
		}
		var channels = [ 0, 2, 4 ].map( function ( at ) {
			var v = parseInt( m[ 1 ].substr( at, 2 ), 16 ) / 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} );
		var luminance = 0.2126 * channels[ 0 ] + 0.7152 * channels[ 1 ] + 0.0722 * channels[ 2 ];
		return luminance > 0.36 ? '#111827' : '#ffffff';
	}

	/** A tag chip. `extra` carries the data attributes the caller needs. */
	function tagChip( tag, opts ) {
		opts = opts || {};
		return '<span class="snn-tag' + ( opts.on ? ' is-on' : '' ) + ( opts.button ? ' is-button' : '' ) + '"' +
			( opts.attrs || '' ) +
			' style="--snn-tag-color:' + esc( tag.color ) + ';--snn-tag-ink:' + tagInk( tag.color ) + '">' +
			esc( tag.name ) +
			( opts.count && tag.uses !== undefined ? ' <span class="snn-tag-count">' + tag.uses + '</span>' : '' ) +
			'</span>';
	}

	// ---------- Tag filter above the listing ----------

	function renderTagFilter() {
		if ( ! tagFilterEl ) {
			return;
		}
		if ( ! state.tags.length ) {
			tagFilterEl.innerHTML = '<span class="snn-muted snn-tagfilter-empty">No tags yet — ' +
				'<button type="button" class="snn-linkbtn" data-open-tags>create one</button> to group your videos.</span>';
			return;
		}

		tagFilterEl.innerHTML =
			'<span class="snn-tagfilter-label">Filter:</span>' +
			'<span class="snn-tag is-button' + ( state.tag ? '' : ' is-on' ) +
				'" data-filter="0" style="--snn-tag-color:#6b7280;--snn-tag-ink:#ffffff">All</span>' +
			state.tags.map( function ( tag ) {
				return tagChip( tag, {
					on: state.tag === tag.id,
					button: true,
					count: true,
					attrs: ' data-filter="' + tag.id + '"'
				} );
			} ).join( '' ) +
			'<button type="button" class="snn-linkbtn snn-tagfilter-manage" data-open-tags>Manage tags</button>';
	}

	// ---------- Tag picker in the uploader ----------

	function renderTagPicker() {
		if ( ! tagPickerEl ) {
			return;
		}
		if ( ! state.tags.length ) {
			tagPickerEl.innerHTML = '<span class="snn-muted">no tags defined yet</span>';
			return;
		}
		tagPickerEl.innerHTML = state.tags.map( function ( tag ) {
			return tagChip( tag, {
				on: !! uploadTagIds[ tag.id ],
				button: true,
				attrs: ' data-upload-tag="' + tag.id + '"'
			} );
		} ).join( '' );
	}

	// ---------- Library listing ----------

	function thumbHtml( item ) {
		if ( item.poster_url ) {
			return '<img src="' + esc( item.poster_url ) + '" alt="" loading="lazy">';
		}
		var label = 'error' === item.thumb_status ? 'no frame'
			: ( 'done' === item.thumb_status ? '…' : 'no thumb' );
		return '<span class="snn-thumb-empty">' + esc( label ) + '</span>';
	}

	function renderItems() {
		if ( ! state.items.length ) {
			itemsEl.innerHTML = '<p class="snn-muted snn-empty">' +
				( state.search || state.tag
					? 'Nothing matches that filter.'
					: 'No videos yet — open the uploader above to add some.' ) +
				'</p>';
			paginationEl.innerHTML = '';
			return;
		}

		itemsEl.innerHTML = state.items.map( function ( item ) {
			var canConvertHere = item.local_exists;
			var open = !! expanded[ item.id ];
			var itemTagIds = ( item.tags || [] ).map( function ( tag ) {
				return tag.id;
			} );

			return '<div class="snn-item' + ( open ? ' is-open' : '' ) + '" data-id="' + item.id + '">' +

				// ----- always-visible summary line -----
				'<div class="snn-item-head" data-toggle="' + item.id + '">' +
					'<div class="snn-thumb"' + ( item.playback_url ? ' data-act="preview" data-id="' + item.id + '" title="Preview"' : '' ) + '>' +
						thumbHtml( item ) +
					'</div>' +
					'<div class="snn-item-main">' +
						'<p class="snn-item-name">' + esc( item.original_name ) + '</p>' +
						'<div class="snn-item-sub">' +
							'<span>' + esc( item.filesize_h ) + '</span>' +
							'<span>' + esc( item.uploaded_date ) + '</span>' +
							( item.uploaded_by ? '<span>by ' + esc( item.uploaded_by ) + '</span>' : '' ) +
							( item.mp3_size_h ? '<span>MP3 ' + esc( item.mp3_size_h ) + '</span>' : '' ) +
							( item.local_exists ? '' : '<span>local copy removed</span>' ) +
							'<span class="snn-item-progress"></span>' +
						'</div>' +
						( item.tags && item.tags.length
							? '<div class="snn-item-tags">' + item.tags.map( function ( tag ) {
								return tagChip( tag, {} );
							} ).join( '' ) + '</div>'
							: '' ) +
						'<div class="snn-item-badges">' +
							badge( 'Thumb', item.thumb_status ) +
							badge( 'MP3', item.mp3_status ) +
							badge( 'VTT', item.vtt_status ) +
							badge( 'R2', item.r2_status ) +
						'</div>' +
					'</div>' +
					'<span class="snn-item-chevron" aria-hidden="true">&#9662;</span>' +
				'</div>' +

				// ----- details, hidden until the row is opened -----
				'<div class="snn-item-body"' + ( open ? '' : ' hidden' ) + '>' +
					( item.error_msg ? '<div class="snn-item-error">' + esc( item.error_msg ) + '</div>' : '' ) +
					'<div class="snn-item-urls">' +
						urlRow( 'Video', item.playback_url ) +
						urlRow( 'Subtitle', item.r2_vtt_url || item.vtt_url ) +
						urlRow( 'Audio', item.r2_mp3_url || item.mp3_url ) +
						urlRow( 'Thumbnail', item.poster_url ) +
					'</div>' +
					'<div class="snn-item-tagedit">' +
						'<span class="snn-item-tagedit-label">Tags</span>' +
						( state.tags.length
							? state.tags.map( function ( tag ) {
								return tagChip( tag, {
									on: itemTagIds.indexOf( tag.id ) !== -1,
									button: true,
									attrs: ' data-item-tag="' + tag.id + '" data-id="' + item.id + '"'
								} );
							} ).join( '' )
							: '<span class="snn-muted">no tags defined yet</span>' ) +
						'<button type="button" class="snn-linkbtn" data-open-tags>Manage tags</button>' +
					'</div>' +
					'<div class="snn-item-actions">' +
						( item.playback_url ? '<button type="button" class="snn-btn snn-btn-ghost snn-btn-sm" data-act="preview" data-id="' + item.id + '">Preview</button>' : '' ) +
						'<button type="button" class="snn-btn snn-btn-ghost snn-btn-sm" data-act="thumb" data-id="' + item.id + '">' + ( item.thumb_status === 'done' ? 'Redo thumbnail' : 'Make thumbnail' ) + '</button>' +
						( canConvertHere ? '<button type="button" class="snn-btn snn-btn-ghost snn-btn-sm" data-act="mp3" data-id="' + item.id + '">' + ( item.mp3_status === 'done' ? 'Redo MP3' : 'Make MP3' ) + '</button>' : '' ) +
						'<button type="button" class="snn-btn snn-btn-ghost snn-btn-sm" data-act="vtt" data-id="' + item.id + '"' + ( item.mp3_status === 'done' ? '' : ' disabled title="An MP3 is needed first"' ) + '>' + ( item.vtt_status === 'done' ? 'Redo subtitles' : 'Make subtitles' ) + '</button>' +
						( item.local_exists ? '<button type="button" class="snn-btn snn-btn-ghost snn-btn-sm" data-act="r2" data-id="' + item.id + '"' + ( CFG.r2Configured ? '' : ' disabled title="R2 is not configured"' ) + '>' + ( item.r2_status === 'synced' ? 'Re-sync to R2' : 'Sync to R2' ) + '</button>' : '' ) +
						'<button type="button" class="snn-btn snn-btn-danger snn-btn-sm" data-act="delete" data-id="' + item.id + '">Delete</button>' +
					'</div>' +
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
			api( 'items?page=' + state.page + '&per_page=' + state.perPage +
				'&tag=' + state.tag + '&search=' + encodeURIComponent( state.search ) )
				.then( function ( data ) {
					state.items = data.items;
					state.total = data.total;
					state.tags = data.tags || [];
					totalEl.textContent = '(' + data.total + ')';
					renderTagFilter();
					renderTagPicker();
					renderItems();
					renderTagManager();
				} )
				.catch( function ( err ) {
					itemsEl.innerHTML = '<p class="snn-muted snn-empty">Could not load the library: ' + esc( describeError( err ) ) + '</p>';
				} );
		}, immediate ? 0 : 250 );
	}

	/** Swaps one item in place, so a tag edit does not reload the whole page. */
	function replaceItem( item ) {
		state.items = state.items.map( function ( row ) {
			return row.id === item.id ? item : row;
		} );
		renderItems();
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

	// The uploader starts closed, so a file dragged over the page opens it
	// rather than bouncing off a collapsed panel.
	var uploadPanel = $( '#snn-upload-panel' );
	if ( uploadPanel ) {
		window.addEventListener( 'dragenter', function ( event ) {
			if ( event.dataTransfer && Array.prototype.indexOf.call( event.dataTransfer.types || [], 'Files' ) !== -1 ) {
				uploadPanel.open = true;
			}
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

	if ( tagFilterEl ) {
		tagFilterEl.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-open-tags]' ) ) {
				openTagManager();
				return;
			}
			var chip = event.target.closest( '[data-filter]' );
			if ( ! chip ) {
				return;
			}
			state.tag = parseInt( chip.dataset.filter, 10 ) || 0;
			state.page = 1;
			refreshLibrary( true );
		} );
	}

	if ( tagPickerEl ) {
		tagPickerEl.addEventListener( 'click', function ( event ) {
			var chip = event.target.closest( '[data-upload-tag]' );
			if ( ! chip ) {
				return;
			}
			var id = parseInt( chip.dataset.uploadTag, 10 );
			uploadTagIds[ id ] = ! uploadTagIds[ id ];
			saveUploadTags();
			renderTagPicker();
		} );
	}

	var manageTagsBtn = $( '#snn-manage-tags' );
	if ( manageTagsBtn ) {
		manageTagsBtn.addEventListener( 'click', openTagManager );
	}

	var expandAllBtn = $( '#snn-expand-all' );
	if ( expandAllBtn ) {
		expandAllBtn.addEventListener( 'click', function () {
			// "Expand all" applies to what is on screen; the button then offers
			// the opposite so it is never a dead end.
			var anyClosed = state.items.some( function ( item ) {
				return ! expanded[ item.id ];
			} );
			state.items.forEach( function ( item ) {
				if ( anyClosed ) {
					expanded[ item.id ] = true;
				} else {
					delete expanded[ item.id ];
				}
			} );
			expandAllBtn.textContent = anyClosed ? 'Collapse all' : 'Expand all';
			renderItems();
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
				return item.thumb_status !== 'done' || item.mp3_status !== 'done' ||
					item.vtt_status !== 'done' ||
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
				enqueueProcessing( item, null, [ 'thumb', 'mp3', 'vtt', 'r2' ], false );
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
			if ( event.target.closest( '[data-open-tags]' ) ) {
				openTagManager();
				return;
			}

			var tagChipEl = event.target.closest( '[data-item-tag]' );
			if ( tagChipEl ) {
				toggleItemTag( parseInt( tagChipEl.dataset.id, 10 ), parseInt( tagChipEl.dataset.itemTag, 10 ) );
				return;
			}

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

			// The whole summary line is the toggle, except for the controls
			// sitting on it — the thumbnail opens the preview instead.
			if ( ! btn ) {
				var head = event.target.closest( '[data-toggle]' );
				if ( head ) {
					var toggleId = parseInt( head.dataset.toggle, 10 );
					if ( expanded[ toggleId ] ) {
						delete expanded[ toggleId ];
					} else {
						expanded[ toggleId ] = true;
					}
					var row = head.parentNode;
					row.classList.toggle( 'is-open', !! expanded[ toggleId ] );
					$( '.snn-item-body', row ).hidden = ! expanded[ toggleId ];
					return;
				}
			}

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
				delete expanded[ item.id ];
				log( 'Deleted ' + item.original_name + ' (video, MP3, subtitles, thumbnail, and any R2 copies).', 'ok' );
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
	// Tags — per-item assignment and the tag manager
	// ==========================================================

	function toggleItemTag( itemId, tagId ) {
		var item = state.items.filter( function ( row ) {
			return row.id === itemId;
		} )[ 0 ];
		var tag = tagById( tagId );
		if ( ! item || ! tag ) {
			return;
		}

		var ids = ( item.tags || [] ).map( function ( row ) {
			return row.id;
		} );
		var at = ids.indexOf( tagId );
		var adding = at === -1;

		if ( adding ) {
			ids.push( tagId );
		} else {
			ids.splice( at, 1 );
		}

		api( 'item-tags', { method: 'POST', body: form( { id: itemId, tag_ids: ids.join( ',' ) } ) } )
			.then( function ( data ) {
				replaceItem( data.item );
				log( ( adding ? 'Tagged ' : 'Untagged ' ) + item.original_name +
					( adding ? ' with "' : ' from "' ) + tag.name + '".', 'ok' );
				// Counts on the filter chips are now stale.
				refreshTagList();
			} )
			.catch( function ( err ) {
				log( 'Could not change tags on ' + item.original_name + ': ' + describeError( err ), 'err' );
			} );
	}

	/** Re-reads the tag vocabulary without disturbing the listing. */
	function refreshTagList() {
		return api( 'tags' ).then( function ( data ) {
			state.tags = data.tags || [];
			renderTagFilter();
			renderTagPicker();
			renderTagManager();
		} ).catch( function () {} );
	}

	var tagModal = $( '#snn-tag-modal' );
	var tagListEl = $( '#snn-tag-list' );
	var tagNewForm = $( '#snn-tag-new' );
	var tagNameEl = $( '#snn-tag-name' );
	var tagColorEl = $( '#snn-tag-color' );
	var tagErrorEl = $( '#snn-tag-error' );

	function tagError( message ) {
		if ( ! tagErrorEl ) {
			return;
		}
		tagErrorEl.textContent = message || '';
		tagErrorEl.hidden = ! message;
	}

	function renderTagManager() {
		if ( ! tagListEl ) {
			return;
		}
		if ( ! state.tags.length ) {
			tagListEl.innerHTML = '<p class="snn-muted">No tags yet. Add the first one below.</p>';
			return;
		}
		tagListEl.innerHTML = state.tags.map( function ( tag ) {
			return '<div class="snn-tag-row" data-tag-id="' + tag.id + '">' +
				'<input type="color" class="snn-tag-color" value="' + esc( tag.color ) + '" aria-label="Colour for ' + esc( tag.name ) + '">' +
				'<input type="text" class="snn-input snn-tag-name" value="' + esc( tag.name ) + '" maxlength="120">' +
				'<span class="snn-muted snn-tag-uses">' + tag.uses + ' video' + ( 1 === tag.uses ? '' : 's' ) + '</span>' +
				'<button type="button" class="snn-btn snn-btn-ghost snn-btn-sm" data-tag-save>Save</button>' +
				'<button type="button" class="snn-btn snn-btn-danger snn-btn-sm" data-tag-delete>Delete</button>' +
			'</div>';
		} ).join( '' );
	}

	function openTagManager() {
		if ( ! tagModal ) {
			return;
		}
		tagError( '' );
		renderTagManager();
		tagModal.hidden = false;
		if ( tagNameEl ) {
			tagNameEl.focus();
		}
	}

	function closeTagManager() {
		if ( tagModal ) {
			tagModal.hidden = true;
		}
	}

	if ( tagModal ) {
		tagModal.addEventListener( 'click', function ( event ) {
			if ( event.target.hasAttribute( 'data-close' ) ) {
				closeTagManager();
				return;
			}

			var row = event.target.closest( '[data-tag-id]' );
			if ( ! row ) {
				return;
			}
			var id = parseInt( row.dataset.tagId, 10 );
			var tag = tagById( id );

			if ( event.target.closest( '[data-tag-save]' ) ) {
				var name = $( '.snn-tag-name', row ).value;
				var color = $( '.snn-tag-color', row ).value;
				tagError( '' );
				api( 'tag', { method: 'POST', body: form( { id: id, name: name, color: color } ) } )
					.then( function ( data ) {
						state.tags = data.tags || [];
						log( 'Updated tag "' + data.name + '".', 'ok' );
						renderTagFilter();
						renderTagPicker();
						renderTagManager();
						// Chips on the listing carry the old name and colour.
						refreshLibrary( true );
					} )
					.catch( function ( err ) {
						tagError( describeError( err ) );
						log( 'Could not update that tag: ' + describeError( err ), 'err' );
					} );
				return;
			}

			if ( event.target.closest( '[data-tag-delete]' ) ) {
				if ( ! tag || ! window.confirm( 'Delete the tag "' + tag.name + '"?\n\nIt is removed from ' + tag.uses + ' video(s). The videos themselves are not touched.' ) ) {
					return;
				}
				api( 'tag?id=' + id, { method: 'DELETE' } )
					.then( function ( data ) {
						state.tags = data.tags || [];
						delete uploadTagIds[ id ];
						saveUploadTags();
						if ( state.tag === id ) {
							state.tag = 0;
						}
						log( 'Deleted tag "' + data.name + '".', 'ok' );
						renderTagManager();
						refreshLibrary( true );
					} )
					.catch( function ( err ) {
						tagError( describeError( err ) );
						log( 'Could not delete that tag: ' + describeError( err ), 'err' );
					} );
			}
		} );
	}

	if ( tagNewForm ) {
		tagNewForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			tagError( '' );
			api( 'tag', { method: 'POST', body: form( { name: tagNameEl.value, color: tagColorEl.value } ) } )
				.then( function ( data ) {
					state.tags = data.tags || [];
					tagNameEl.value = '';
					log( 'Created tag "' + data.name + '".', 'ok' );
					renderTagManager();
					renderTagFilter();
					renderTagPicker();
					renderItems();
					tagNameEl.focus();
				} )
				.catch( function ( err ) {
					tagError( describeError( err ) );
					log( 'Could not create that tag: ' + describeError( err ), 'err' );
				} );
		} );
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
			if ( 'Escape' !== event.key ) {
				return;
			}
			if ( ! modal.hidden ) {
				closeModal();
			} else if ( tagModal && ! tagModal.hidden ) {
				closeTagManager();
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

	loadUploadTags();
	refreshLibrary( true );
} )();
