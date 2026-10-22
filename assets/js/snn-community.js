/**
 * SNN Learn — Community front end.
 *
 * Topic list, topic view, replies and the rich-text composer.
 *
 * Images are optimised in the visitor's browser before they are sent:
 *   1. the file is checked (JPG, PNG, GIF or WebP, 5 MB max),
 *   2. it is drawn on a canvas, scaled down to the configured width,
 *   3. it is encoded as WebP (transparency is kept, nothing is flattened),
 *   4. the WebP is uploaded. The server checks it again and stores it.
 * Canvas WebP encoding is not available everywhere; then the original file is
 * sent and the server converts it.
 *
 * Config comes from window.SNN_CM (wp_add_inline_script in community.php).
 */
(function () {
  'use strict';

  var C = window.SNN_CM || {};
  var ALLOWED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
  var COLORS = ['#111827', '#dc2626', '#ea580c', '#ca8a04', '#16a34a', '#0891b2', '#2563eb', '#9333ea'];
  var SIZES = { '2': '13px', '5': '18px', '6': '22px' };
  var MARK = '#010101';   // the editor's "default colour" swatch

  /* ---------- helpers ---------- */

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = String(s == null ? '' : s);
    return d.innerHTML;
  }

  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', C.nonce);
    Object.keys(data || {}).forEach(function (k) {
      var v = data[k];
      if (v instanceof Blob) fd.append(k, v, v.name || 'image.webp');
      else fd.append(k, v == null ? '' : v);
    });
    return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (text) {
        var j;
        try { j = JSON.parse(text); } catch (e) {
          // A bad nonce or a server error returns plain text, not JSON.
          throw new Error('The server did not respond. Please reload the page and try again.');
        }
        if (!j || !j.success) {
          var err = new Error((j && j.data && j.data.message) || 'Something went wrong. Please try again.');
          err.login = !!(j && j.data && j.data.login);
          throw err;
        }
        return j.data;
      });
  }

  var toastTimer;
  function toast(html) {
    var t = document.querySelector('.snn-cm-toast');
    if (!t) {
      t = document.createElement('div');
      t.className = 'snn-cm-toast';
      t.setAttribute('role', 'status');
      document.body.appendChild(t);
    }
    t.innerHTML = html;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.remove(); }, 4500);
  }

  function needLogin() {
    toast('Please <a href="' + esc(C.loginUrl) + '">log in</a> or <a href="' + esc(C.registerUrl) + '">register</a> to join in.');
  }

  function bump(section, delta) {
    var n = section.querySelector('.snn-cm-comments-count');
    if (n) n.textContent = Math.max(0, (parseInt(n.textContent, 10) || 0) + delta);
  }

  function lightbox(src) {
    var o = document.createElement('div');
    o.className = 'snn-cm-lightbox';
    o.innerHTML = '<img alt="">';
    o.querySelector('img').src = src;
    function close() { o.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    o.addEventListener('click', close);
    document.addEventListener('keydown', onKey);
    document.body.appendChild(o);
  }

  /* ---------- image optimiser (browser side) ---------- */

  /** Scale (w, h) down so the width is at most maxW. Never scales up. */
  function targetSize(w, h, maxW) {
    if (!maxW || w <= maxW) return { width: w, height: h };
    return { width: maxW, height: Math.max(1, Math.round(h * maxW / w)) };
  }

  /**
   * Resolve { blob, name, width, height } as WebP, or null when this browser
   * cannot encode WebP (the caller then sends the original file).
   * Rejects with a readable Error for files that are not allowed.
   */
  function optimize(file) {
    return new Promise(function (resolve, reject) {
      if (ALLOWED.indexOf(file.type) < 0) {
        reject(new Error('Only JPG, PNG, GIF and WebP images are allowed.'));
        return;
      }
      if (file.size > C.maxBytes) {
        reject(new Error('Each image must be 5 MB or smaller.'));
        return;
      }

      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var size = targetSize(img.naturalWidth, img.naturalHeight, C.imageWidth);
        var canvas = document.createElement('canvas');
        canvas.width = size.width;
        canvas.height = size.height;

        var ctx = canvas.getContext('2d');
        // Transparent by default. No white fill, so PNG and WebP transparency survives.
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        URL.revokeObjectURL(url);

        canvas.toBlob(function (blob) {
          if (blob && blob.type === 'image/webp') {
            var base = (file.name || 'image').replace(/\.[^.]+$/, '') || 'image';
            resolve({ blob: blob, name: base + '.webp', width: canvas.width, height: canvas.height });
          } else {
            resolve(null);
          }
        }, 'image/webp', (C.imageQuality || 80) / 100);
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        reject(new Error('This image could not be read. Try another file.'));
      };
      img.src = url;
    });
  }

  /** Optimise, upload, resolve the server's { url, width, height, bytes }. */
  async function uploadOptimized(file) {
    var out = await optimize(file);
    var upload = out ? new File([out.blob], out.name, { type: 'image/webp' }) : file;
    return post('snn_cm_upload', { file: upload });
  }

  /* ---------- rich-text editor ---------- */

  function unwrap(el) {
    var p = el.parentNode;
    while (el.firstChild) p.insertBefore(el.firstChild, el);
    p.removeChild(el);
  }

  /** Browsers write <font> for colour and size. Turn them into spans with CSS, as the roadmap does. */
  function normalize(area) {
    area.querySelectorAll('font').forEach(function (f) {
      var st = '';
      var c = (f.getAttribute('color') || '').toLowerCase();
      var sz = f.getAttribute('size');
      if (c && c !== MARK) st += 'color:' + c + ';';
      if (sz && SIZES[sz]) st += 'font-size:' + SIZES[sz] + ';';
      if (!st) { unwrap(f); return; }
      var s = document.createElement('span');
      s.setAttribute('style', st);
      while (f.firstChild) s.appendChild(f.firstChild);
      f.parentNode.replaceChild(s, f);
    });
    area.querySelectorAll('span').forEach(function (s) {
      if (!s.getAttribute('style')) unwrap(s);
    });
  }

  function makeEditor(host, opts) {
    opts = opts || {};
    var ed = document.createElement('div');
    ed.className = 'snn-cm-ed';

    var swatches = COLORS.map(function (c) {
      return '<button type="button" data-color="' + c + '" style="background:' + c + '" title="' + c + '"></button>';
    }).join('') + '<button type="button" data-color="' + MARK + '" title="Default colour" style="background:linear-gradient(135deg,#fff 45%,#dc2626 45%,#dc2626 55%,#fff 55%)"></button>';

    var widths = [25, 50, 75, 100].map(function (w) {
      return '<button type="button" data-width="' + w + '">' + w + '%</button>';
    }).join('');

    ed.innerHTML =
      '<div class="snn-cm-ed-bar" role="toolbar" aria-label="Formatting">' +
        '<button type="button" data-cmd="undo" title="Undo (Ctrl+Z)">&#8630;</button>' +
        '<button type="button" data-cmd="redo" title="Redo (Ctrl+Y)">&#8631;</button>' +
        '<span class="snn-cm-ed-sep"></span>' +
        '<button type="button" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>' +
        '<button type="button" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>' +
        '<button type="button" data-cmd="underline" title="Underline (Ctrl+U)"><u>U</u></button>' +
        '<button type="button" data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>' +
        '<span class="snn-cm-ed-sep"></span>' +
        '<button type="button" data-cmd="insertUnorderedList" title="Bullet list">&bull;</button>' +
        '<button type="button" data-cmd="insertOrderedList" title="Numbered list">1.</button>' +
        '<button type="button" data-cmd="blockquote" title="Quote">&#10077;</button>' +
        '<span class="snn-cm-ed-sep"></span>' +
        '<span class="snn-cm-ed-colors">' +
          '<button type="button" class="snn-cm-ed-colorbtn" title="Text colour" aria-label="Text colour"><span class="snn-cm-ed-a">A</span></button>' +
          '<span class="snn-cm-ed-swatches">' + swatches + '</span>' +
        '</span>' +
        '<select title="Text size" aria-label="Text size"><option value="">Size</option><option value="2">Small</option><option value="7">Normal</option><option value="5">Large</option><option value="6">Huge</option></select>' +
        (C.images ? '<span class="snn-cm-ed-sep"></span><button type="button" class="snn-cm-ed-img" title="Add images (JPG, PNG, GIF or WebP)" aria-label="Add images">&#128444;</button>' : '') +
        '<span class="snn-cm-ed-sep"></span>' +
        '<button type="button" data-cmd="removeFormat" title="Clear formatting">&#10005;</button>' +
      '</div>' +
      '<div class="snn-cm-imgtools" hidden><span>Image size:</span>' + widths + '<button type="button" data-remove>Remove image</button></div>' +
      '<div class="snn-cm-ed-area" data-lenis-prevent contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="' + esc(opts.placeholder || 'Share your thoughts…') + '"></div>' +
      '<input type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>' +
      '<div class="snn-cm-ed-foot">' +
        '<span class="snn-cm-ed-msg" aria-live="polite"></span>' +
        (C.images ? '<span class="snn-cm-ed-count"></span>' : '') +
        (opts.cancel ? '<button type="button" class="snn-cm-btn is-ghost snn-cm-ed-cancel">Cancel</button>' : '') +
        '<button type="button" class="snn-cm-btn snn-cm-ed-submit">' + esc(opts.submit || 'Post') + '</button>' +
      '</div>';
    host.appendChild(ed);

    var area = ed.querySelector('.snn-cm-ed-area');
    var msg = ed.querySelector('.snn-cm-ed-msg');
    var btn = ed.querySelector('.snn-cm-ed-submit');
    var tools = ed.querySelector('.snn-cm-imgtools');
    var fileInput = ed.querySelector('input[type=file]');
    var countEl = ed.querySelector('.snn-cm-ed-count');
    var colors = ed.querySelector('.snn-cm-ed-colors');
    var sizeSel = ed.querySelector('select');
    var uploading = false;
    var selected = null;
    var saved = null;

    if (opts.html) area.innerHTML = opts.html;

    /* undo / redo: snapshots of the content, like the snn-brx editor */
    var history = [area.innerHTML];
    var hi = 0;
    var snapTimer;
    function snapshot() {
      var h = area.innerHTML;
      if (history[hi] === h) return;
      history = history.slice(0, hi + 1);
      history.push(h);
      if (history.length > 100) history.shift();
      hi = history.length - 1;
    }
    function goTo(i) {
      hi = i;
      area.innerHTML = history[hi];
      selected = null;
      tools.hidden = true;
      updateCount();
    }
    function undo() { if (hi > 0) goTo(hi - 1); }
    function redo() { if (hi < history.length - 1) goTo(hi + 1); }

    function setMsg(text, ok) {
      msg.textContent = text || '';
      msg.classList.toggle('is-ok', !!ok);
    }
    function countImages() { return area.querySelectorAll('img').length; }
    function updateCount() {
      if (countEl) countEl.textContent = countImages() + ' / ' + C.maxImages + ' images';
    }

    function saveSel() {
      var s = window.getSelection();
      if (s.rangeCount && area.contains(s.anchorNode)) saved = s.getRangeAt(0).cloneRange();
    }
    function restoreSel() {
      area.focus();
      if (saved) {
        var s = window.getSelection();
        s.removeAllRanges();
        s.addRange(saved);
      }
    }
    function closestIn(selector) {
      var s = window.getSelection();
      if (!s.rangeCount) return null;
      var n = s.getRangeAt(0).startContainer;
      if (n.nodeType === 3) n = n.parentNode;
      var f = n && n.closest ? n.closest(selector) : null;
      return f && area.contains(f) ? f : null;
    }

    function exec(cmd, val) {
      restoreSel();
      try { document.execCommand('styleWithCSS', false, false); } catch (e) {}
      document.execCommand(cmd, false, val || null);
      normalize(area);
      saveSel();
      snapshot();
      state();
    }

    function state() {
      ed.querySelectorAll('[data-cmd]').forEach(function (b) {
        var c = b.dataset.cmd;
        if (['undo', 'redo', 'blockquote', 'removeFormat'].indexOf(c) > -1) return;
        var on = false;
        try { on = document.queryCommandState(c); } catch (e) {}
        b.classList.toggle('is-on', on);
      });
    }

    /** Images are half the editor width when added; the size buttons change that. */
    function setImgWidth(img, pct) {
      img.style.width = pct + '%';
      img.removeAttribute('height');
    }

    function insertImage(src, w, h) {
      restoreSel();
      var img = document.createElement('img');
      img.className = 'snn-cm-img';
      img.src = src;
      img.alt = '';
      img.width = w;
      img.height = h;
      img.loading = 'lazy';
      img.decoding = 'async';
      setImgWidth(img, 50);

      var s = window.getSelection();
      var range = (s.rangeCount && area.contains(s.anchorNode)) ? s.getRangeAt(0) : null;
      if (range) {
        range.deleteContents();
        range.insertNode(img);
        range.setStartAfter(img);
        range.collapse(true);
        s.removeAllRanges();
        s.addRange(range);
      } else {
        area.appendChild(img);
      }
      // A paragraph after the image keeps typing going below it.
      var p = document.createElement('p');
      p.appendChild(document.createElement('br'));
      img.after(p);

      snapshot();
      saveSel();
      updateCount();
    }

    /** Upload files one by one. Errors are shown per file; the rest still go through. */
    async function addFiles(list) {
      var files = Array.prototype.slice.call(list || []);
      if (!files.length || uploading) return;
      if (!C.images) { setMsg('Image uploads are turned off.'); return; }

      var room = C.maxImages - countImages();
      if (room <= 0) { setMsg('Up to ' + C.maxImages + ' images per post.'); return; }
      if (files.length > room) {
        setMsg('Only ' + room + ' more image' + (room > 1 ? 's' : '') + ' fit in this post.');
        files = files.slice(0, room);
      }

      uploading = true;
      btn.disabled = true;
      var added = 0;
      var lastError = '';
      for (var i = 0; i < files.length; i++) {
        setMsg('Optimising ' + (i + 1) + ' / ' + files.length + ': ' + files[i].name);
        try {
          var r = await uploadOptimized(files[i]);
          insertImage(r.url, r.width, r.height);
          added++;
        } catch (err) {
          lastError = err.message;
        }
      }
      uploading = false;
      btn.disabled = false;

      if (lastError) setMsg(lastError);
      else setMsg(added + ' image' + (added > 1 ? 's' : '') + ' added.', true);
    }

    /* events */
    area.addEventListener('keyup', function () { saveSel(); state(); });
    area.addEventListener('mouseup', function () { saveSel(); state(); });
    area.addEventListener('input', function () {
      setMsg('');
      clearTimeout(snapTimer);
      snapTimer = setTimeout(snapshot, 400);
    });

    area.addEventListener('keydown', function (e) {
      var mod = e.ctrlKey || e.metaKey;
      var k = (e.key || '').toLowerCase();
      if (mod && e.key === 'Enter') { e.preventDefault(); btn.click(); return; }
      if (mod && k === 'z' && !e.shiftKey) { e.preventDefault(); undo(); return; }
      if (mod && (k === 'y' || (k === 'z' && e.shiftKey))) { e.preventDefault(); redo(); return; }
      // Enter starts a new paragraph, except inside lists and quotes where the browser's own rules work.
      if (e.key === 'Enter' && !e.shiftKey && !closestIn('li, blockquote')) {
        e.preventDefault();
        document.execCommand('insertParagraph');
        snapshot();
      }
    });

    // Paste: images are optimised like uploads; text is pasted as plain paragraphs.
    area.addEventListener('paste', function (e) {
      var cd = e.clipboardData || window.clipboardData;
      if (!cd) return;
      var images = Array.prototype.filter.call(cd.files || [], function (f) { return f.type.indexOf('image/') === 0; });
      e.preventDefault();
      if (images.length) { addFiles(images); return; }
      var text = cd.getData('text/plain');
      var html = text.split(/\n+/).map(function (l) { return l.trim() ? '<p>' + esc(l.trim()) + '</p>' : ''; }).join('');
      document.execCommand('insertHTML', false, html);
      snapshot();
    });

    // Drop image files onto the editor.
    area.addEventListener('dragover', function (e) {
      if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') > -1) e.preventDefault();
    });
    area.addEventListener('drop', function (e) {
      var files = e.dataTransfer && e.dataTransfer.files;
      if (files && files.length) { e.preventDefault(); addFiles(files); }
    });

    // Toolbar
    ed.querySelectorAll('[data-cmd]').forEach(function (b) {
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () {
        var cmd = b.dataset.cmd;
        if (cmd === 'undo') { undo(); return; }
        if (cmd === 'redo') { redo(); return; }
        if (cmd === 'blockquote') {
          var q = closestIn('blockquote');
          if (q) {
            while (q.firstChild) q.parentNode.insertBefore(q.firstChild, q);
            q.remove();
            snapshot();
          } else {
            exec('formatBlock', 'blockquote');
          }
          return;
        }
        exec(cmd);
      });
    });

    var colorBtn = colors.querySelector('.snn-cm-ed-colorbtn');
    colorBtn.addEventListener('mousedown', function (e) { e.preventDefault(); });
    colorBtn.addEventListener('click', function () { colors.classList.toggle('is-open'); });
    colors.querySelectorAll('[data-color]').forEach(function (b) {
      b.addEventListener('click', function () {
        exec('foreColor', b.dataset.color);
        colors.classList.remove('is-open');
      });
    });
    sizeSel.addEventListener('change', function () {
      if (sizeSel.value) exec('fontSize', sizeSel.value);
      sizeSel.value = '';
    });

    var imgBtn = ed.querySelector('.snn-cm-ed-img');
    if (imgBtn) {
      imgBtn.addEventListener('mousedown', function (e) { e.preventDefault(); });
      imgBtn.addEventListener('click', function () { saveSel(); fileInput.click(); });
    }
    fileInput.addEventListener('change', function () {
      addFiles(fileInput.files);
      fileInput.value = '';
    });

    // Image tools: click an image to resize or remove it, as the snn-brx editor does.
    // The size button for the selected image's current width is highlighted.
    function markWidth() {
      var cur = selected ? Math.round(parseFloat(selected.style.width)) : NaN;
      tools.querySelectorAll('[data-width]').forEach(function (b) {
        b.classList.toggle('is-on', Number(b.dataset.width) === cur);
      });
    }
    area.addEventListener('click', function (e) {
      var img = e.target.closest('img');
      if (selected) selected.classList.remove('is-selected');
      selected = img && area.contains(img) ? img : null;
      if (selected) selected.classList.add('is-selected');
      tools.hidden = !selected;
      markWidth();
    });
    tools.addEventListener('mousedown', function (e) { e.preventDefault(); });
    tools.addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b || !selected) return;
      if (b.dataset.width) {
        setImgWidth(selected, b.dataset.width);
        markWidth();
        snapshot();
      } else if (b.hasAttribute('data-remove')) {
        selected.remove();
        selected = null;
        tools.hidden = true;
        snapshot();
        updateCount();
      }
    });

    if (opts.cancel) ed.querySelector('.snn-cm-ed-cancel').addEventListener('click', opts.cancel);

    btn.addEventListener('click', function () {
      if (uploading) return;
      var api = this.__api;
      if (api.empty()) { setMsg('Write something first.'); return; }
      api.busy(true);
      setMsg('');
      // Wrapping in a Promise turns a synchronous throw from onSubmit into a rejection.
      new Promise(function (resolve) { resolve(opts.onSubmit(api)); })
        .catch(function (err) {
          setMsg(err.message);
          if (err.login) needLogin();
        })
        .then(function () { api.busy(false); });
    });

    var api = {
      el: ed,
      area: area,
      html: function () { normalize(area); return area.innerHTML; },
      empty: function () { return !area.textContent.replace(/ /g, ' ').trim() && !area.querySelector('img'); },
      clear: function () { area.innerHTML = ''; history = ['']; hi = 0; selected = null; tools.hidden = true; updateCount(); },
      error: setMsg,
      busy: function (b) { btn.disabled = b; },
      focus: function () { area.focus(); }
    };
    btn.__api = api;

    updateCount();
    return api;
  }

  /* ---------- thread helpers ---------- */

  /** Hide edit and delete buttons when the edit window closes while the page is open. */
  function initDeadlines(root) {
    root.querySelectorAll('.snn-cm-c[data-until]').forEach(function (li) {
      var ms = li.dataset.until * 1000 - Date.now();
      var hide = function () {
        li.querySelectorAll(':scope > .snn-cm-c-main > .snn-cm-c-actions .snn-cm-act-edit, :scope > .snn-cm-c-main > .snn-cm-c-actions .snn-cm-act-delete')
          .forEach(function (x) { x.remove(); });
      };
      if (ms <= 0) hide();
      else if (ms < 2147483647) setTimeout(hide, ms);
    });
  }

  function initThread(view) {
    var sec = view.querySelector('.snn-cm-comments');
    if (!sec) return;
    var postId = sec.dataset.post;
    var host = sec.querySelector(':scope > .snn-cm-composer');
    if (host) {
      makeEditor(host, {
        placeholder: 'Write a reply…',
        submit: 'Reply',
        onSubmit: function (api) {
          return post('snn_cm_reply', { post_id: postId, content: api.html() }).then(function (d) {
            var list = sec.querySelector('.snn-cm-clist');
            list.insertAdjacentHTML('beforeend', d.html);
            var none = sec.querySelector('.snn-cm-empty');
            if (none) none.remove();
            api.clear();
            bump(sec, 1);
            api.error(d.approved ? 'Posted.' : 'Posted. It will show once a moderator has approved it.', true);
            if (list.lastElementChild) list.lastElementChild.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          });
        }
      });
    }
    initDeadlines(view);
  }

  /* ---------- one community instance ---------- */

  function initRoot(root) {
    if (root.dataset.snnCmReady) return;
    if (!root.querySelector('.snn-cm-list')) return;   // the locked box has the same class but no list
    root.dataset.snnCmReady = '1';

    var state = { cat: root.dataset.cat || '', page: 1, topic: 0, search: '' };
    var listSeq = 0;   // the newest list request wins, so a slow old answer cannot overwrite a newer search
    var side = root.querySelector('.snn-cm-side-list');
    var main = root.querySelector('.snn-cm-main');
    var heading = root.querySelector('.snn-cm-heading');
    var listView = root.querySelector('[data-view=list]');
    var threadView = root.querySelector('[data-view=thread]');
    var list = root.querySelector('.snn-cm-list');
    var moreWrap = root.querySelector('.snn-cm-more-wrap');
    var emptyMsg = root.querySelector('.snn-cm-empty-list');

    var newView = document.createElement('div');
    newView.className = 'snn-cm-view snn-cm-new-view';
    newView.dataset.view = 'new';
    newView.hidden = true;
    main.appendChild(newView);

    function show(name) {
      listView.hidden = name !== 'list';
      threadView.hidden = name !== 'thread';
      newView.hidden = name !== 'new';
    }

    function catName(slug) {
      var c = (C.categories || []).filter(function (x) { return x.slug === slug; })[0];
      return c ? c.name : 'Latest topics';
    }

    function setHeading() {
      if (!heading) return;
      heading.textContent = state.search ? 'Search: "' + state.search + '"' : catName(state.cat);
    }

    function setUrl(id) {
      var u = new URL(window.location.href);
      if (id) u.searchParams.set('snn_topic', id);
      else u.searchParams.delete('snn_topic');
      history.pushState({ snnTopic: id || 0 }, '', u.pathname + u.search + u.hash);
    }

    function fetchList(page, reset) {
      var seq = ++listSeq;
      return post('snn_cm_list', { category: state.cat, search: state.search, page: page }).then(function (d) {
        if (seq !== listSeq) return;   // a newer list request has started since
        if (reset) list.innerHTML = '';
        list.insertAdjacentHTML('beforeend', d.html);
        state.page = page;
        if (d.sidebar !== null && d.sidebar !== undefined) side.innerHTML = d.sidebar;
        if (moreWrap) moreWrap.hidden = !d.has_more;
        if (emptyMsg) {
          emptyMsg.textContent = state.search ? 'No topics match your search.' : 'No topics here yet.';
          emptyMsg.hidden = list.children.length > 0;
        }
      }).catch(function (e) { toast(esc(e.message)); });
    }

    function openThread(id, push) {
      state.topic = id;
      show('thread');
      threadView.innerHTML = '<div class="snn-cm-loading">Loading…</div>';
      if (push) setUrl(id);
      return post('snn_cm_thread', { post_id: id }).then(function (d) {
        if (state.topic !== id) return;   // the reader moved on before this came back
        threadView.innerHTML = d.html;
        initThread(threadView);
      }).catch(function (e) {
        if (state.topic !== id) return;
        if (e.login) {
          closeThread(false);
          needLogin();
        } else {
          threadView.innerHTML = '<p class="snn-cm-empty">' + esc(e.message) + '</p>';
        }
      });
    }

    function closeThread(push) {
      state.topic = 0;
      show('list');
      if (push) setUrl(0);
    }

    function openNew() {
      if (!C.canPost) { needLogin(); return; }
      show('new');
      newView.innerHTML = '';

      // Start on the category being read, or on the default when reading "All discussions".
      var preselect = state.cat || C.defaultCategory || '';
      var options = (C.categories || []).map(function (c) {
        return '<option value="' + esc(c.slug) + '"' + (c.slug === preselect ? ' selected' : '') + '>' + esc(c.name) + '</option>';
      }).join('');

      var form = document.createElement('div');
      form.className = 'snn-cm-form';
      form.innerHTML =
        '<div><button type="button" class="snn-cm-back">Back to discussions</button></div>' +
        '<h2 class="snn-cm-heading">New topic</h2>' +
        '<label class="snn-cm-field"><span>Title</span><input class="snn-cm-input" type="text" maxlength="150" placeholder="What do you want to talk about?"></label>' +
        '<label class="snn-cm-field"><span>Category</span><select class="snn-cm-select">' + options + '</select></label>' +
        '<div class="snn-cm-field"><span>Message</span><div class="snn-cm-editor-host"></div></div>';
      newView.appendChild(form);

      var titleInput = form.querySelector('.snn-cm-input');
      var catSelect = form.querySelector('.snn-cm-select');
      makeEditor(form.querySelector('.snn-cm-editor-host'), {
        placeholder: 'Share the details…',
        submit: 'Publish topic',
        cancel: function () { closeThread(false); show('list'); },
        onSubmit: function (api) {
          var title = titleInput.value.trim();
          if (title.length < 3) throw new Error('Titles need at least 3 characters.');
          return post('snn_cm_topic', { title: title, category: catSelect.value, content: api.html() }).then(function (d) {
            if (d.pending) {
              toast('Thanks! Your topic is waiting for moderator approval.');
              show('list');
              return;
            }
            fetchList(1, true);
            openThread(d.id, true);
          });
        }
      });
      titleInput.focus();
    }

    /* search: the small box at the foot of the sidebar. Runs after a short pause in typing, on Enter, or when cleared. */
    var searchInput = root.querySelector('.snn-cm-search-input');
    var searchTimer;
    function runSearch() {
      var q = searchInput ? searchInput.value.trim() : '';
      if (q === state.search) return;
      state.search = q;
      closeThread(!!state.topic);   // results replace an open topic; the address bar follows
      show('list');
      setHeading();
      fetchList(1, true);
    }
    if (searchInput) {
      searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(runSearch, 300);
      });
      searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); clearTimeout(searchTimer); runSearch(); }
        if (e.key === 'Escape' && searchInput.value) { searchInput.value = ''; clearTimeout(searchTimer); runSearch(); }
      });
      searchInput.addEventListener('search', runSearch);   // the clear cross in some browsers
    }

    /* profile cards: click an avatar or name. Capture phase so the row link and thread handlers never see it. */
    if (C.cards) {
      root.addEventListener('click', function (e) {
        var u = e.target.closest('.snn-cm-user');
        if (!u) return;
        e.preventDefault();
        e.stopPropagation();
        openCard(u);
      }, true);
      root.addEventListener('keydown', function (e) {
        var u = e.target.closest('.snn-cm-user');
        if (!u || (e.key !== 'Enter' && e.key !== ' ')) return;
        e.preventDefault();
        e.stopPropagation();
        openCard(u);
      }, true);
    }

    /* clicks */
    root.addEventListener('click', function (e) {
      var cat = e.target.closest('.snn-cm-cat');
      if (cat) {
        e.preventDefault();
        state.cat = cat.dataset.slug || '';
        root.dataset.cat = state.cat;
        side.querySelectorAll('.snn-cm-cat').forEach(function (b) { b.classList.toggle('is-active', b === cat); });
        setHeading();
        closeThread(true);
        fetchList(1, true);
        return;
      }
      if (e.target.closest('.snn-cm-new')) { openNew(); return; }
      if (e.target.closest('.snn-cm-more')) { fetchList(state.page + 1, false); return; }

      var row = e.target.closest('.snn-cm-row');
      if (row && !e.metaKey && !e.ctrlKey && !e.shiftKey && e.button === 0) {
        e.preventDefault();
        openThread(parseInt(row.dataset.id, 10), true);
        return;
      }
      if (e.target.closest('.snn-cm-back')) {
        e.preventDefault();
        if (newView.hidden === false) { show('list'); return; }
        closeThread(true);
        return;
      }

      var img = e.target.closest('.snn-cm-body img');
      if (img) lightbox(img.src);
    });

    /* topic tools, then reply, edit, delete and mute inside a topic */
    threadView.addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b) return;
      if (b.dataset.topicAct) { topicAction(b); return; }
      if (b.dataset.likeType) { toggleLike(b); return; }
      var li = b.closest('.snn-cm-c');
      if (!li) return;
      var sec = threadView.querySelector('.snn-cm-comments');
      var main = li.querySelector(':scope > .snn-cm-c-main');
      var id = li.dataset.id;

      // Answer a reply. Answers sit one level deep: answering an answer files it under the same top-level reply,
      // starting with @name so it is clear who it is for.
      if (b.classList.contains('snn-cm-act-reply')) {
        var top = li.parentElement.closest('.snn-cm-c') || li;
        var topMain = top.querySelector(':scope > .snn-cm-c-main');
        var open = topMain.querySelector(':scope > .snn-cm-answer');
        if (open) open.remove();
        var holder = document.createElement('div');
        holder.className = 'snn-cm-composer snn-cm-answer';
        topMain.appendChild(holder);
        var ed = makeEditor(holder, {
          html: top === li ? '' : '<p>@' + esc(b.dataset.name) + '&nbsp;</p>',
          placeholder: 'Reply to ' + b.dataset.name + '…',
          submit: 'Reply',
          cancel: function () { holder.remove(); },
          onSubmit: function (api) {
            return post('snn_cm_reply', { post_id: sec.dataset.post, parent: id, content: api.html() }).then(function (d) {
              var kids = topMain.querySelector(':scope > .snn-cm-children');
              if (!kids) {
                kids = document.createElement('ol');
                kids.className = 'snn-cm-children';
                holder.before(kids);
              }
              kids.insertAdjacentHTML('beforeend', d.html);
              holder.remove();
              bump(sec, 1);
              toast(d.approved ? 'Posted.' : 'Posted. It will show once a moderator has approved it.');
              initDeadlines(kids);
            });
          }
        });
        ed.focus();
        var range = document.createRange();
        range.selectNodeContents(ed.area);
        range.collapse(false);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        return;
      }

      if (b.classList.contains('snn-cm-act-edit')) {
        var body = main.querySelector(':scope > .snn-cm-c-body');
        if (body.hidden) return;
        body.hidden = true;
        var editHolder = document.createElement('div');
        editHolder.className = 'snn-cm-composer';
        body.after(editHolder);
        makeEditor(editHolder, {
          html: body.innerHTML,
          submit: 'Save',
          cancel: function () { editHolder.remove(); body.hidden = false; },
          onSubmit: function (api) {
            return post('snn_cm_comment_edit', { comment_id: id, content: api.html() }).then(function (d) {
              body.innerHTML = d.html;
              body.hidden = false;
              editHolder.remove();
              // The label carries the date, so readers can see when it changed.
              var head = main.querySelector(':scope > .snn-cm-c-head');
              var tag = head ? head.querySelector('.snn-cm-edited') : null;
              if (head && !tag) {
                tag = document.createElement('span');
                tag.className = 'snn-cm-edited';
                head.appendChild(tag);
              }
              if (tag) tag.textContent = d.edited;
            });
          }
        }).focus();
        return;
      }

      if (b.classList.contains('snn-cm-act-mute')) {
        var on = b.dataset.muted !== '1';
        b.disabled = true;
        post('snn_cm_user_mute', { user_id: b.dataset.user, on: on ? 1 : 0 }).then(function () {
          b.dataset.muted = on ? '1' : '0';
          b.textContent = on ? 'Unmute author' : 'Mute author';
          toast(on ? 'Muted. They can still read, but cannot post.' : 'Unmuted.');
        }).catch(function (err) {
          toast(esc(err.message));
        }).then(function () {
          b.disabled = false;
        });
        return;
      }

      if (b.classList.contains('snn-cm-act-delete')) {
        if (!window.confirm('Delete this reply?')) return;
        b.disabled = true;
        post('snn_cm_comment_delete', { comment_id: id }).then(function () {
          // The server moves answers up a level, so they stay on the page as top-level replies.
          li.querySelectorAll(':scope > .snn-cm-c-main > .snn-cm-children > .snn-cm-c').forEach(function (kid) { li.before(kid); });
          li.remove();
          bump(sec, -1);
          if (!sec.querySelector('.snn-cm-clist > li') && !sec.querySelector('.snn-cm-empty')) {
            sec.querySelector('.snn-cm-clist').insertAdjacentHTML('afterend', '<p class="snn-cm-empty">No replies yet.</p>');
          }
        }).catch(function (err) {
          b.disabled = false;
          toast(esc(err.message));
        });
      }
    });

    /* Heart on a topic or reply. The button is disabled for anyone who cannot like. */
    function toggleLike(b) {
      b.disabled = true;
      post('snn_cm_like', { type: b.dataset.likeType, id: b.dataset.likeId }).then(function (d) {
        b.classList.toggle('is-on', d.on);
        b.setAttribute('aria-pressed', d.on ? 'true' : 'false');
        b.querySelector('.snn-cm-like-n').textContent = d.count;
      }).catch(function (err) {
        toast(esc(err.message));
        if (err.login) needLogin();
      }).then(function () {
        b.disabled = false;
      });
    }

    /* Topic tools: edit, delete, pin, lock and mute. The server decides who sees each button. */
    function topicAction(b) {
      var act = b.dataset.topicAct;
      var id = state.topic;
      var inner = threadView.querySelector('.snn-cm-thread-inner');
      if (act === 'edit') { editTopic(inner); return; }

      if (act === 'delete') {
        if (!window.confirm('Delete this topic? It will disappear from the forum.')) return;
        b.disabled = true;
        post('snn_cm_topic_delete', { post_id: id }).then(function () {
          toast('Topic deleted.');
          closeThread(true);
          fetchList(1, true);
        }).catch(function (err) {
          b.disabled = false;
          toast(esc(err.message));
        });
        return;
      }

      if (act === 'mute') {
        var on = b.dataset.muted !== '1';
        post('snn_cm_user_mute', { user_id: b.dataset.user, on: on ? 1 : 0 }).then(function () {
          openThread(id, false);
        }).catch(function (err) {
          toast(esc(err.message));
        });
        return;
      }

      // pin_category, pin_all, unpin, lock, unlock: pins change the list, so it reloads too.
      b.disabled = true;
      post('snn_cm_topic_mod', { post_id: id, op: act }).then(function () {
        openThread(id, false);
        fetchList(1, true);
      }).catch(function (err) {
        b.disabled = false;
        toast(esc(err.message));
      });
    }

    /* Edit the open topic: title, category and message, in the same editor as replies. */
    function editTopic(inner) {
      var id = state.topic;
      var head = inner.querySelector('.snn-cm-thread-head');
      var art = inner.querySelector('.snn-cm-topic-body');
      var tools = inner.querySelector('.snn-cm-tools');
      var current = inner.dataset.cat || '';
      var options = (C.categories || []).map(function (c) {
        return '<option value="' + esc(c.slug) + '"' + (c.slug === current ? ' selected' : '') + '>' + esc(c.name) + '</option>';
      }).join('');

      var form = document.createElement('div');
      form.className = 'snn-cm-form snn-cm-topic-edit';
      form.innerHTML =
        '<label class="snn-cm-field"><span>Title</span><input class="snn-cm-input" type="text" maxlength="150"></label>' +
        '<label class="snn-cm-field"><span>Category</span><select class="snn-cm-select">' + options + '</select></label>' +
        '<div class="snn-cm-field"><span>Message</span><div class="snn-cm-editor-host"></div></div>';
      var titleInput = form.querySelector('.snn-cm-input');
      var catSelect = form.querySelector('.snn-cm-select');
      titleInput.value = inner.querySelector('.snn-cm-thread-title').textContent;

      head.hidden = true;
      art.hidden = true;
      if (tools) tools.hidden = true;
      inner.insertBefore(form, head);

      function close() {
        form.remove();
        head.hidden = false;
        art.hidden = false;
        if (tools) tools.hidden = false;
      }

      makeEditor(form.querySelector('.snn-cm-editor-host'), {
        html: art.querySelector('.snn-cm-body').innerHTML,
        submit: 'Save changes',
        cancel: close,
        onSubmit: function (api) {
          var title = titleInput.value.trim();
          if (title.length < 3) throw new Error('Titles need at least 3 characters.');
          return post('snn_cm_topic_edit', { post_id: id, title: title, category: catSelect.value, content: api.html() }).then(function () {
            openThread(id, false);
            fetchList(1, true);
          });
        }
      });
      titleInput.focus();
    }

    /* browser back and forward */
    window.addEventListener('popstate', function () {
      var id = parseInt(new URL(window.location.href).searchParams.get('snn_topic'), 10) || 0;
      if (id) openThread(id, false);
      else closeThread(false);
    });

    /* open a topic from the URL on first load */
    var first = parseInt(new URL(window.location.href).searchParams.get('snn_topic'), 10) || 0;
    if (first) openThread(first, false);
  }

  /* ---------- profile card ---------- */

  var cardCache = {};
  var cardEl = null;
  var cardFor = null;

  function closeCard() {
    if (!cardEl) return;
    cardEl.remove();
    cardEl = cardFor = null;
    document.removeEventListener('click', onCardOutside, true);
    document.removeEventListener('keydown', onCardKey);
    window.removeEventListener('resize', closeCard);
    window.removeEventListener('scroll', placeCard, true);
  }
  function onCardOutside(e) {
    if (cardEl && !cardEl.contains(e.target) && !e.target.closest('.snn-cm-user')) closeCard();
  }
  function onCardKey(e) { if (e.key === 'Escape') closeCard(); }

  function placeCard() {
    if (!cardEl || !cardFor) return;
    if (!document.contains(cardFor)) { closeCard(); return; }
    var r = cardFor.getBoundingClientRect();
    var w = cardEl.offsetWidth, h = cardEl.offsetHeight;
    var left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8));
    var top = r.bottom + 8;
    if (top + h > window.innerHeight - 8 && r.top - h - 8 > 8) top = r.top - h - 8;
    cardEl.style.left = left + 'px';
    cardEl.style.top = top + 'px';
  }

  function cardHtml(d) {
    function stat(n, label) { return '<div class="snn-cm-card-stat"><b>' + esc(n) + '</b><span>' + label + '</span></div>'; }
    return '<div class="snn-cm-card-head">' +
        '<img src="' + esc(d.avatar) + '" alt="" width="56" height="56">' +
        '<div><strong>' + esc(d.name) + '</strong>' +
        (d.mod ? '<span class="snn-cm-tag is-pin">Moderator</span>' : '') +
        (d.muted ? '<span class="snn-cm-tag is-lock">Muted</span>' : '') +
        '<span class="snn-cm-card-joined">Joined ' + esc(d.joined) + '</span></div>' +
      '</div>' +
      '<div class="snn-cm-card-stats">' +
        stat(d.topics, d.topics === 1 ? 'Topic' : 'Topics') +
        stat(d.replies, d.replies === 1 ? 'Reply' : 'Replies') +
        (d.hearts == null ? '' : stat(d.hearts, d.hearts === 1 ? 'Heart' : 'Hearts')) +
      '</div>';
  }

  function openCard(el) {
    var id = el.dataset.user;
    if (cardEl && cardFor && cardFor.dataset.user === id) { closeCard(); return; }
    closeCard();
    cardEl = document.createElement('div');
    cardEl.className = 'snn-cm-card';
    cardEl.setAttribute('role', 'dialog');
    var root = el.closest('.snn-cm');
    if (root) cardEl.style.setProperty('--snn-cm-accent', getComputedStyle(root).getPropertyValue('--snn-cm-accent').trim());
    cardEl.innerHTML = cardCache[id] ? cardHtml(cardCache[id]) : '<div class="snn-cm-card-load">Loading…</div>';
    document.body.appendChild(cardEl);
    cardFor = el;
    placeCard();
    document.addEventListener('click', onCardOutside, true);
    document.addEventListener('keydown', onCardKey);
    window.addEventListener('resize', closeCard);
    window.addEventListener('scroll', placeCard, true);
    if (cardCache[id]) return;

    var mine = cardEl;
    post('snn_cm_user_card', { user: id }).then(function (d) {
      cardCache[id] = d;
      if (cardEl !== mine) return;
      mine.innerHTML = cardHtml(d);
      placeCard();
    }).catch(function (err) {
      if (cardEl === mine) { mine.innerHTML = '<div class="snn-cm-card-load">' + esc(err.message) + '</div>'; placeCard(); }
    });
  }

  function boot() {
    document.querySelectorAll('.snn-cm').forEach(initRoot);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

  // Exposed for tests (see tests/community/).
  window.SnnCommunity = { targetSize: targetSize, optimize: optimize };
})();
