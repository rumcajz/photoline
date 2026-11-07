/**
 * PhotoLine
 *
 * @license AGPL-3.0
 *
 * The timeline page: justified rows grouped by day, drawn only where visible,
 * a year/month scrubber, filters, folder settings and a full-screen viewer.
 * No third-party code; talks only to this app's API and ownCloud's WebDAV.
 */
(function () {
	'use strict';

	var APP = 'photos_timeline';
	var GAP = 4;
	var HEADER_H = 44;
	var SMALL_BATCH = 60;
	var TINY_BATCH = 300;
	var PARALLEL = 3;
	var MAX_SMALL_URLS = 1500;
	var PLAYABLE = ['video/mp4', 'video/webm', 'video/quicktime', 'video/ogg'];
	var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	var DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

	function tr(s, vars) {
		return (window.t ? window.t(APP, s, vars) : s);
	}

	function api(path) {
		return OC.generateUrl('/apps/' + APP + '/api/v1/' + path);
	}

	function request(url, opts) {
		opts = opts || {};
		var headers = Object.assign({ requesttoken: OC.requestToken }, opts.headers || {});
		return fetch(url, Object.assign({}, opts, { headers: headers, credentials: 'same-origin' })).then(function (r) {
			if (r.status === 401 || r.status === 403) {
				// Session expired: let ownCloud show its login page.
				window.location.reload();
				throw new Error('Not logged in');
			}
			if (!r.ok) {
				throw new Error('HTTP ' + r.status);
			}
			return r;
		});
	}

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) {
			e.className = cls;
		}
		if (text !== undefined) {
			e.textContent = text;
		}
		return e;
	}

	function pad(n) {
		return (n < 10 ? '0' : '') + n;
	}

	// "taken" is wall-clock time stored as seconds, so it is read in UTC.
	function dateParts(ts) {
		var d = new Date(ts * 1000);
		return { y: d.getUTCFullYear(), m: d.getUTCMonth(), d: d.getUTCDate(), wd: d.getUTCDay(), h: d.getUTCHours(), mi: d.getUTCMinutes() };
	}

	function dayLabel(ts) {
		var p = dateParts(ts);
		return DAYS[p.wd] + ', ' + p.d + ' ' + MONTHS[p.m].slice(0, 3) + ' ' + p.y;
	}

	function fullDate(ts) {
		var p = dateParts(ts);
		return DAYS[p.wd] + ', ' + p.d + ' ' + MONTHS[p.m] + ' ' + p.y + ', ' + pad(p.h) + ':' + pad(p.mi);
	}

	function duration(sec) {
		if (!sec) {
			return '';
		}
		var h = Math.floor(sec / 3600);
		var m = Math.floor(sec % 3600 / 60);
		var s = sec % 60;
		return (h ? h + ':' + pad(m) : m) + ':' + pad(s);
	}

	function davUrl(path) {
		return OC.linkToRemoteBase('webdav') + '/' + path.split('/').map(encodeURIComponent).join('/');
	}

	function colorCss(c) {
		if (c === undefined || c < 0) {
			return '';
		}
		return '#' + ('000000' + c.toString(16)).slice(-6);
	}

	// ---------------------------------------------------------------- state

	var S = {
		scope: 'mine',      // 'mine' | 'folder'
		folder: '',
		types: 'all',
		from: '',
		to: '',
		settings: null,
		data: null,         // response of /items
		items: [],          // item arrays (see ApiController::items)
		layout: null,
		width: 0,
		loadSeq: 0
	};

	// item field accessors
	var F = { id: 0, taken: 1, w: 2, h: 3, kind: 4, color: 5, dir: 6, name: 7, ver: 8, dur: 9, thumb: 10, mime: 11, also: 12 };

	function itemPath(it) {
		var dir = S.data.dirs[it[F.dir]];
		return (dir ? dir + '/' : '') + it[F.name];
	}

	// ------------------------------------------------------------- elements

	var E = {};

	function bindElements() {
		var root = document.querySelector('.ptl-app');
		E.root = root;
		E.crumbs = root.querySelector('.ptl-crumbs');
		E.scroll = root.querySelector('.ptl-scroll');
		E.canvas = root.querySelector('.ptl-canvas');
		E.message = root.querySelector('.ptl-message');
		E.scrubber = root.querySelector('.ptl-scrubber');
		E.scrubLabels = root.querySelector('.ptl-scrub-labels');
		E.scrubPos = root.querySelector('.ptl-scrub-pos');
		E.bubble = root.querySelector('.ptl-scrub-bubble');
		E.from = root.querySelector('.ptl-from');
		E.to = root.querySelector('.ptl-to');
		E.clear = root.querySelector('.ptl-clear');
		E.seg = root.querySelectorAll('.ptl-seg button');
		E.settingsBtn = root.querySelector('.ptl-settings-btn');
		E.filterToggle = root.querySelector('.ptl-filter-toggle');
		E.filters = root.querySelector('.ptl-filters');
		E.status = root.querySelector('.ptl-status');
	}

	// ------------------------------------------------------------ URL state

	function readUrl() {
		var q = new URLSearchParams(window.location.search);
		if (q.has('folder')) {
			S.scope = 'folder';
			S.folder = (q.get('folder') || '').replace(/^\/+|\/+$/g, '');
		}
		S.types = ['all', 'photos', 'videos'].indexOf(q.get('types')) >= 0 ? q.get('types') : '';
		S.from = /^\d{4}-\d{2}-\d{2}$/.test(q.get('from') || '') ? q.get('from') : '';
		S.to = /^\d{4}-\d{2}-\d{2}$/.test(q.get('to') || '') ? q.get('to') : '';
	}

	function writeUrl() {
		var q = new URLSearchParams();
		if (S.scope === 'folder') {
			q.set('folder', S.folder);
		}
		if (S.types && S.types !== 'all') {
			q.set('types', S.types);
		}
		if (S.from) {
			q.set('from', S.from);
		}
		if (S.to) {
			q.set('to', S.to);
		}
		var qs = q.toString();
		history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
	}

	// ------------------------------------------------------------- toolbar

	function renderCrumbs() {
		E.crumbs.textContent = '';
		var mine = el('a', 'ptl-crumb' + (S.scope === 'mine' ? ' ptl-current' : ''), tr('My timeline'));
		mine.href = OC.generateUrl('/apps/' + APP + '/');
		E.crumbs.appendChild(mine);
		if (S.scope !== 'folder') {
			return;
		}
		var sep = el('span', 'ptl-sep', '|');
		sep.setAttribute('aria-hidden', 'true');
		E.crumbs.appendChild(sep);
		var parts = S.folder ? S.folder.split('/') : [];
		var all = [{ name: tr('All files'), path: '' }];
		parts.forEach(function (p, i) {
			all.push({ name: p, path: parts.slice(0, i + 1).join('/') });
		});
		all.forEach(function (c, i) {
			if (i > 0) {
				var s = el('span', 'ptl-sep', '›');
				s.setAttribute('aria-hidden', 'true');
				E.crumbs.appendChild(s);
			}
			var a = el('a', 'ptl-crumb' + (i === all.length - 1 ? ' ptl-current' : ''), c.name);
			a.href = OC.generateUrl('/apps/' + APP + '/') + '?folder=' + encodeURIComponent(c.path);
			if (i === all.length - 1) {
				a.setAttribute('aria-current', 'page');
			}
			E.crumbs.appendChild(a);
		});
		var files = el('a', 'ptl-open-files', tr('Open in Files'));
		files.href = OC.generateUrl('/apps/files/') + '?dir=' + encodeURIComponent('/' + S.folder);
		E.crumbs.appendChild(files);
	}

	function renderFilters() {
		E.seg.forEach(function (b) {
			b.setAttribute('aria-pressed', String(b.getAttribute('data-types') === (S.types || 'all')));
		});
		E.from.value = S.from;
		E.to.value = S.to;
		E.clear.hidden = !S.from && !S.to;
		E.settingsBtn.hidden = S.scope !== 'mine';
	}

	var saveTimer = null;

	function filtersChanged() {
		renderFilters();
		writeUrl();
		load();
		if (S.scope === 'mine') {
			clearTimeout(saveTimer);
			saveTimer = setTimeout(function () {
				request(api('settings'), {
					method: 'PUT',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ types: S.types || 'all', from: S.from || null, to: S.to || null })
				}).catch(function () {});
			}, 800);
		}
	}

	function bindToolbar() {
		E.seg.forEach(function (b) {
			b.addEventListener('click', function () {
				S.types = b.getAttribute('data-types');
				filtersChanged();
			});
		});
		[E.from, E.to].forEach(function (input) {
			input.addEventListener('change', function () {
				var v = input.value;
				if (v && !/^\d{4}-\d{2}-\d{2}$/.test(v)) {
					return;
				}
				S.from = E.from.value;
				S.to = E.to.value;
				if (S.from && S.to && S.from > S.to) {
					// Swapped limits: take them the other way round.
					var x = S.from;
					S.from = S.to;
					S.to = x;
				}
				filtersChanged();
			});
		});
		E.clear.addEventListener('click', function () {
			S.from = '';
			S.to = '';
			filtersChanged();
		});
		E.filterToggle.addEventListener('click', function () {
			var open = !E.root.classList.contains('ptl-filters-open');
			E.root.classList.toggle('ptl-filters-open', open);
			E.filterToggle.setAttribute('aria-expanded', String(open));
			setTimeout(resize, 0);
		});
		E.settingsBtn.addEventListener('click', openSettings);
	}

	// ---------------------------------------------------------------- data

	function load() {
		var seq = ++S.loadSeq;
		var q = new URLSearchParams({
			scope: S.scope, folder: S.folder, types: S.types || 'all', from: S.from, to: S.to
		});
		showMessage(tr('Loading…'), true);
		var t0 = performance.now();
		return request(api('items') + '?' + q.toString()).then(function (r) {
			return r.json();
		}).then(function (data) {
			if (seq !== S.loadSeq) {
				return;
			}
			S.data = data;
			S.items = data.items;
			window.__ptlTiming = { items: Math.round(performance.now() - t0), count: data.items.length };
			E.scroll.scrollTop = 0;
			Thumbs.reset();
			relayout(true);
			if (!S.items.length) {
				if (S.scope === 'mine' && !data.folders.length) {
					showMessage(tr('Choose the folders that make up your timeline.'), false, tr('Choose folders'), openSettings);
				} else {
					showMessage(tr('No photos or videos here.'));
				}
			} else {
				hideMessage();
				announce(tr('{n} photos and videos', { n: S.items.length.toLocaleString() }));
			}
		}).catch(function (e) {
			if (seq === S.loadSeq) {
				showMessage(e.message === 'HTTP 404' ? tr('This folder does not exist.') : tr('Could not load the timeline. Please reload the page.'));
			}
		});
	}

	function showMessage(text, busy, buttonText, onClick) {
		E.message.textContent = '';
		E.message.appendChild(el('p', '', text));
		if (buttonText) {
			var b = el('button', 'primary', buttonText);
			b.type = 'button';
			b.addEventListener('click', onClick);
			E.message.appendChild(b);
		}
		E.message.classList.toggle('ptl-busy', !!busy);
		E.message.hidden = false;
		if (!busy) {
			E.canvas.style.height = '0px';
			E.canvas.textContent = '';
			S.layout = null;
			renderScrubber();
		}
	}

	function hideMessage() {
		E.message.hidden = true;
	}

	function announce(text) {
		E.status.textContent = text;
	}

	// --------------------------------------------------------------- layout

	function targetHeight(width) {
		if (width < 500) {
			return 110;
		}
		if (width < 900) {
			return 150;
		}
		return 190;
	}

	/**
	 * Justified rows per day. Returns rows (with absolute top) and the month
	 * marks used by the scrubber.
	 */
	function computeLayout(width) {
		var items = S.items;
		var th = targetHeight(width);
		var rows = [];
		var months = [];
		var y = 0;
		var i = 0;
		var lastMonth = null;
		while (i < items.length) {
			var day = Math.floor(items[i][F.taken] / 86400);
			var p = dateParts(items[i][F.taken]);
			var monthKey = p.y * 12 + p.m;
			if (monthKey !== lastMonth) {
				months.push({ top: y, y: p.y, m: p.m });
				lastMonth = monthKey;
			}
			rows.push({ header: true, top: y, height: HEADER_H, label: dayLabel(items[i][F.taken]) });
			y += HEADER_H;
			// collect the day
			var start = i;
			while (i < items.length && Math.floor(items[i][F.taken] / 86400) === day) {
				i++;
			}
			var j = start;
			while (j < i) {
				var row = [];
				var sumAspect = 0;
				while (j < i) {
					var it = items[j];
					var a = Math.min(Math.max(it[F.w] / Math.max(1, it[F.h]), 0.4), 4);
					row.push({ idx: j, a: a });
					sumAspect += a;
					j++;
					if (sumAspect * th + (row.length - 1) * GAP >= width) {
						break;
					}
				}
				var full = sumAspect * th + (row.length - 1) * GAP >= width;
				var h = full ? (width - (row.length - 1) * GAP) / sumAspect : th;
				var x = 0;
				var cells = row.map(function (c) {
					var w = c.a * h;
					var cell = { idx: c.idx, left: x, width: w };
					x += w + GAP;
					return cell;
				});
				if (full && cells.length) {
					// absorb rounding so the row ends exactly at the edge
					var last = cells[cells.length - 1];
					last.width = width - last.left;
				}
				rows.push({ top: y, height: Math.round(h), cells: cells });
				y += Math.round(h) + GAP;
			}
		}
		return { rows: rows, months: months, height: y + 40, width: width };
	}

	function relayout(force) {
		// the canvas width excludes the scroll area's padding (room for the scrubber)
		var width = Math.floor(E.canvas.clientWidth - 1);
		if (!S.items.length) {
			return;
		}
		if (!force && S.layout && Math.abs(width - S.layout.width) < 2) {
			render();
			return;
		}
		// keep the same item in view after a resize
		var anchor = firstVisibleItem();
		S.layout = computeLayout(width);
		E.canvas.style.height = S.layout.height + 'px';
		E.canvas.textContent = '';
		rendered = new Map();
		if (anchor !== null && !force) {
			scrollToItem(anchor);
		}
		renderScrubber();
		render();
	}

	// ------------------------------------------------------------ rendering

	var rendered = new Map(); // row index -> element

	function findRow(y) {
		var rows = S.layout.rows;
		var lo = 0;
		var hi = rows.length - 1;
		while (lo < hi) {
			var mid = (lo + hi + 1) >> 1;
			if (rows[mid].top <= y) {
				lo = mid;
			} else {
				hi = mid - 1;
			}
		}
		return lo;
	}

	function visibleRange(extra) {
		var top = E.scroll.scrollTop;
		var h = E.scroll.clientHeight;
		return { from: findRow(Math.max(0, top - h * extra)), to: findRow(top + h * (1 + extra)) };
	}

	function firstVisibleItem() {
		if (!S.layout) {
			return null;
		}
		var r = findRow(E.scroll.scrollTop);
		for (var k = r; k < S.layout.rows.length; k++) {
			if (S.layout.rows[k].cells) {
				return S.items[S.layout.rows[k].cells[0].idx][F.id];
			}
		}
		return null;
	}

	function scrollToItem(fileId) {
		var rows = S.layout.rows;
		for (var k = 0; k < rows.length; k++) {
			var cells = rows[k].cells;
			if (cells) {
				for (var c = 0; c < cells.length; c++) {
					if (S.items[cells[c].idx][F.id] === fileId) {
						E.scroll.scrollTop = rows[k].top - HEADER_H;
						return;
					}
				}
			}
		}
	}

	function render() {
		if (!S.layout) {
			return;
		}
		var range = visibleRange(1.5);
		rendered.forEach(function (node, k) {
			if (k < range.from || k > range.to) {
				node.remove();
				rendered.delete(k);
			}
		});
		for (var k = range.from; k <= range.to; k++) {
			if (!rendered.has(k)) {
				var node = buildRow(S.layout.rows[k]);
				E.canvas.appendChild(node);
				rendered.set(k, node);
			}
		}
		updateScrubPos();
		Thumbs.schedule();
	}

	function buildRow(row) {
		var node = el('div', row.header ? 'ptl-day' : 'ptl-row');
		node.style.top = row.top + 'px';
		node.style.height = row.height + 'px';
		if (row.header) {
			node.appendChild(el('h2', '', row.label));
			return node;
		}
		node.setAttribute('role', 'presentation');
		row.cells.forEach(function (c) {
			node.appendChild(buildTile(S.items[c.idx], c));
		});
		return node;
	}

	/** 2: no thumbnail possible; 4: a video waiting for its first playback. */
	function noThumb(it) {
		return it[F.thumb] === 2 || it[F.thumb] === 4;
	}

	function buildTile(it, cell) {
		var b = el('button', 'ptl-tile');
		b.type = 'button';
		b.setAttribute('role', 'listitem');
		b.style.left = cell.left + 'px';
		b.style.width = cell.width + 'px';
		b.style.backgroundColor = colorCss(it[F.color]);
		b.dataset.idx = cell.idx;
		b.dataset.id = it[F.id];
		var video = it[F.kind] === 2;
		b.setAttribute('aria-label', (video ? tr('Video') : tr('Photo')) + ' ' + it[F.name] + ', ' + fullDate(it[F.taken]));
		var url = Thumbs.urlFor(it);
		if (url) {
			var img = el('img');
			img.alt = '';
			img.decoding = 'async';
			img.src = url;
			b.appendChild(img);
			b.classList.add(Thumbs.isSmall(it) ? 'ptl-loaded' : 'ptl-tiny');
		} else if (noThumb(it)) {
			b.classList.add(video ? 'ptl-video-tile' : 'ptl-broken');
		}
		if (video) {
			var badge = el('span', 'ptl-badge', duration(it[F.dur]));
			badge.setAttribute('aria-hidden', 'true');
			b.appendChild(badge);
		}
		return b;
	}

	/** Called by the thumbnail loader when images arrive. */
	function refreshTiles(ids) {
		var set = new Set(ids);
		rendered.forEach(function (node) {
			node.querySelectorAll('.ptl-tile').forEach(function (tile) {
				var id = Number(tile.dataset.id);
				if (!set.has(id)) {
					return;
				}
				var it = S.items[Number(tile.dataset.idx)];
				var url = Thumbs.urlFor(it);
				if (!url) {
					if (noThumb(it) || Thumbs.isBroken(it)) {
						tile.classList.add(it[F.kind] === 2 ? 'ptl-video-tile' : 'ptl-broken');
					}
					return;
				}
				tile.classList.remove('ptl-video-tile', 'ptl-broken');
				var img = tile.querySelector('img');
				if (!img) {
					img = el('img');
					img.alt = '';
					img.decoding = 'async';
					tile.insertBefore(img, tile.firstChild);
				}
				if (img.src !== url) {
					img.src = url;
				}
				var small = Thumbs.isSmall(it);
				tile.classList.toggle('ptl-loaded', small);
				tile.classList.toggle('ptl-tiny', !small);
			});
		});
	}

	// ----------------------------------------------------------- thumbnails

	/**
	 * Loads thumbnails in packs: visible tiles first, then the next screen.
	 * Images are kept in the browser's Cache Storage (per user), so a second
	 * visit needs no network at all.
	 */
	var Thumbs = (function () {
		var small = new Map();   // id -> object URL
		var tiny = new Map();
		var broken = new Set();
		var pending = new Set(); // "s:id" in flight
		var retryAt = new Map(); // id -> time for thumbnails still being made
		var active = 0;
		var cachePromise = null;
		var lastScroll = { t: 0, y: 0, v: 0 };
		var idleTimer = null;

		function cacheName() {
			return 'ptl-thumbs-v1-' + OC.getCurrentUser().uid;
		}

		function cache() {
			if (!cachePromise) {
				cachePromise = (window.caches ? caches.open(cacheName()).then(function (c) {
					// Remove caches left by other users of this browser.
					caches.keys().then(function (keys) {
						keys.forEach(function (k) {
							if (k.indexOf('ptl-thumbs-') === 0 && k !== cacheName()) {
								caches.delete(k);
							}
						});
					});
					return c;
				}) : Promise.resolve(null)).catch(function () {
					return null;
				});
			}
			return cachePromise;
		}

		function key(size, it) {
			return OC.generateUrl('/apps/' + APP + '/cache/') + size + '/' + it[F.id] + '-' + it[F.ver];
		}

		function urlFor(it) {
			return small.get(it[F.id]) || tiny.get(it[F.id]) || null;
		}

		function isSmall(it) {
			return small.has(it[F.id]);
		}

		function isBroken(it) {
			return broken.has(it[F.id]);
		}

		function store(map, id, blob) {
			var old = map.get(id);
			if (old) {
				URL.revokeObjectURL(old);
			}
			map.set(id, URL.createObjectURL(blob));
			if (map === small && small.size > MAX_SMALL_URLS) {
				// Forget the oldest images that are not on screen.
				var visible = visibleIds();
				var it = small.keys();
				while (small.size > MAX_SMALL_URLS * 0.8) {
					var k = it.next();
					if (k.done) {
						break;
					}
					if (!visible.has(k.value)) {
						URL.revokeObjectURL(small.get(k.value));
						small.delete(k.value);
					}
				}
			}
		}

		function visibleIds() {
			var out = new Set();
			if (!S.layout) {
				return out;
			}
			var r = visibleRange(0.5);
			for (var k = r.from; k <= r.to; k++) {
				var row = S.layout.rows[k];
				if (row.cells) {
					row.cells.forEach(function (c) {
						out.add(S.items[c.idx][F.id]);
					});
				}
			}
			return out;
		}

		function wanted(extra) {
			var list = [];
			if (!S.layout) {
				return list;
			}
			var r = visibleRange(extra);
			// visible rows first, then below, then above
			var cur = findRow(E.scroll.scrollTop);
			var order = [];
			for (var k = cur; k <= r.to; k++) {
				order.push(k);
			}
			for (var k2 = cur - 1; k2 >= r.from; k2--) {
				order.push(k2);
			}
			order.forEach(function (k) {
				var row = S.layout.rows[k];
				if (row.cells) {
					row.cells.forEach(function (c) {
						list.push(S.items[c.idx]);
					});
				}
			});
			return list;
		}

		function needs(size, it) {
			if (noThumb(it) || broken.has(it[F.id])) {
				return false;
			}
			var map = size === 's' ? small : tiny;
			if (map.has(it[F.id]) || pending.has(size + ':' + it[F.id])) {
				return false;
			}
			if (size === 't' && small.has(it[F.id])) {
				return false;
			}
			var at = retryAt.get(it[F.id]);
			return !(at && at > Date.now());
		}

		function schedule() {
			var now = performance.now();
			var y = E.scroll.scrollTop;
			if (now - lastScroll.t > 0) {
				lastScroll.v = Math.abs(y - lastScroll.y) / (now - lastScroll.t);
			}
			lastScroll = { t: now, y: y, v: lastScroll.v };
			// While flinging or scrubbing, only the tiny versions are worth fetching.
			var fast = lastScroll.v > 3;
			pump('t', fast ? 0.3 : 1);
			clearTimeout(idleTimer);
			idleTimer = setTimeout(function () {
				lastScroll.v = 0;
				pump('s', 1);
			}, fast ? 180 : 40);
		}

		function pump(size, extra) {
			var batchSize = size === 's' ? SMALL_BATCH : TINY_BATCH;
			var list = wanted(extra).filter(function (it) {
				return needs(size, it);
			});
			if (!list.length) {
				return;
			}
			// Claimed while we look in the browser's own cache, so the next
			// scroll frame does not look them up again.
			list.forEach(function (it) {
				pending.add(size + ':' + it[F.id]);
			});
			cache().then(function (c) {
				if (!c) {
					return list;
				}
				return Promise.all(list.map(function (it) {
					return c.match(key(size, it)).then(function (resp) {
						if (!resp) {
							return it;
						}
						return resp.blob().then(function (blob) {
							store(size === 's' ? small : tiny, it[F.id], blob);
							return null;
						});
					}).catch(function () {
						return it;
					});
				})).then(function (rest) {
					var hit = list.filter(function (it, i) {
						return rest[i] === null;
					});
					if (hit.length) {
						refreshTiles(hit.map(function (it) {
							return it[F.id];
						}));
					}
					return rest.filter(Boolean);
				});
			}).then(function (missing) {
				list.forEach(function (it) {
					pending.delete(size + ':' + it[F.id]);
				});
				missing = missing.filter(function (it) {
					return needs(size, it);
				});
				for (var i = 0; i < missing.length && active < PARALLEL + (size === 't' ? 1 : 0); i += batchSize) {
					fetchPack(size, missing.slice(i, i + batchSize));
				}
			}).catch(function () {
				list.forEach(function (it) {
					pending.delete(size + ':' + it[F.id]);
				});
			});
		}

		function fetchPack(size, list) {
			active++;
			list.forEach(function (it) {
				pending.add(size + ':' + it[F.id]);
			});
			var byId = new Map(list.map(function (it) {
				return [it[F.id], it];
			}));
			var ids = list.map(function (it) {
				return it[F.id];
			});
			var seq = S.loadSeq;
			request(api('thumbs') + '?s=' + size + '&ids=' + ids.join(',')).then(function (r) {
				return r.arrayBuffer();
			}).then(function (buf) {
				if (seq !== S.loadSeq) {
					return;
				}
				var view = new DataView(buf);
				var magic = String.fromCharCode(view.getUint8(0), view.getUint8(1), view.getUint8(2), view.getUint8(3));
				if (magic !== 'PTL1') {
					throw new Error('Bad pack');
				}
				var hlen = view.getUint32(4);
				var header = JSON.parse(new TextDecoder().decode(new Uint8Array(buf, 8, hlen)));
				var off = 8 + hlen;
				var got = [];
				var returned = new Set();
				return cache().then(function (c) {
					header.forEach(function (h) {
						var id = h[0];
						var len = h[1];
						var it = byId.get(id);
						if (it && h.length > 2 && (len > 0 || h[2] !== 0)) {
							it[F.thumb] = h[2]; // the server's current state
						}
						returned.add(id);
						if (!it) {
							off += len;
							return;
						}
						if (len > 0) {
							var blob = new Blob([new Uint8Array(buf, off, len)], { type: 'image/webp' });
							store(size === 's' ? small : tiny, id, blob);
							if (c) {
								c.put(key(size, it), new Response(blob, { headers: { 'Content-Type': 'image/webp' } })).catch(function () {});
							}
							retryAt.delete(id);
						} else if (it[F.thumb] === 1) {
							broken.add(id);
						} else if (noThumb(it)) {
							retryAt.delete(id);
						} else {
							// Not made yet: try again a little later.
							retryAt.set(id, Date.now() + 15000);
						}
						got.push(id);
						off += len;
					});
					// Ids the server did not return are not the user's: never ask again.
					ids.forEach(function (id) {
						if (!returned.has(id)) {
							broken.add(id);
							got.push(id);
						}
					});
					refreshTiles(got);
				});
			}).catch(function () {
				list.forEach(function (it) {
					retryAt.set(it[F.id], Date.now() + 10000);
				});
			}).then(function () {
				active--;
				list.forEach(function (it) {
					pending.delete(size + ':' + it[F.id]);
				});
				if (seq === S.loadSeq) {
					schedule();
				}
			});
		}

		/** A thumbnail was just made for this item (e.g. from playback): fetch it. */
		function invalidate(it) {
			broken.delete(it[F.id]);
			retryAt.delete(it[F.id]);
			schedule();
		}

		function reset() {
			pending.clear();
			retryAt.clear();
			broken.clear();
		}

		function clearCache() {
			if (window.caches) {
				caches.keys().then(function (keys) {
					keys.forEach(function (k) {
						if (k.indexOf('ptl-thumbs-') === 0) {
							caches.delete(k);
						}
					});
				});
			}
		}

		return {
			urlFor: urlFor,
			isSmall: isSmall,
			isBroken: isBroken,
			invalidate: invalidate,
			schedule: schedule,
			reset: reset,
			clearCache: clearCache,
			cachedSmall: function (id) {
				return small.get(id) || tiny.get(id) || null;
			}
		};
	})();

	// ------------------------------------------------------------- scrubber

	function renderScrubber() {
		E.scrubLabels.textContent = '';
		var L = S.layout;
		if (!L || !L.months.length || L.height <= E.scroll.clientHeight * 1.5) {
			E.scrubber.hidden = true;
			return;
		}
		E.scrubber.hidden = false;
		var railH = E.scrubber.clientHeight;
		var lastY = -100;
		var lastYear = null;
		L.months.forEach(function (m) {
			if (m.y === lastYear) {
				return;
			}
			lastYear = m.y;
			var y = m.top / L.height * railH;
			if (y - lastY < 18) {
				// too close to the previous label: show a dot instead
				var dot = el('span', 'ptl-scrub-dot');
				dot.style.top = y + 'px';
				E.scrubLabels.appendChild(dot);
				return;
			}
			var lab = el('span', 'ptl-scrub-year', String(m.y));
			lab.style.top = Math.max(y, 8) + 'px'; // keep the first label inside the rail
			E.scrubLabels.appendChild(lab);
			lastY = y;
		});
		updateScrubPos();
	}

	function updateScrubPos() {
		var L = S.layout;
		if (!L || E.scrubber.hidden) {
			return;
		}
		var railH = E.scrubber.clientHeight;
		E.scrubPos.style.top = (E.scroll.scrollTop / L.height * railH) + 'px';
	}

	function monthAt(contentY) {
		var ms = S.layout.months;
		var lo = 0;
		var hi = ms.length - 1;
		while (lo < hi) {
			var mid = (lo + hi + 1) >> 1;
			if (ms[mid].top <= contentY) {
				lo = mid;
			} else {
				hi = mid - 1;
			}
		}
		return ms[lo];
	}

	function bindScrubber() {
		var dragging = false;

		function contentYFor(clientY) {
			var r = E.scrubber.getBoundingClientRect();
			var f = Math.min(1, Math.max(0, (clientY - r.top) / r.height));
			return f * S.layout.height;
		}

		function showBubble(clientY) {
			if (!S.layout) {
				return;
			}
			var r = E.scrubber.getBoundingClientRect();
			var m = monthAt(contentYFor(clientY));
			E.bubble.textContent = MONTHS[m.m] + ' ' + m.y;
			E.bubble.style.top = Math.min(r.height - 16, Math.max(16, clientY - r.top)) + 'px';
			E.bubble.hidden = false;
		}

		function jump(clientY) {
			if (!S.layout) {
				return;
			}
			var y = contentYFor(clientY);
			// snap to the start of the month under the pointer
			E.scroll.scrollTop = monthAt(y).top;
		}

		E.scrubber.addEventListener('pointerdown', function (e) {
			dragging = true;
			E.scrubber.setPointerCapture(e.pointerId);
			E.root.classList.add('ptl-scrubbing');
			showBubble(e.clientY);
			jump(e.clientY);
			e.preventDefault();
		});
		E.scrubber.addEventListener('pointermove', function (e) {
			showBubble(e.clientY);
			if (dragging) {
				jump(e.clientY);
			}
		});
		function end() {
			dragging = false;
			E.root.classList.remove('ptl-scrubbing');
			E.bubble.hidden = true;
		}
		E.scrubber.addEventListener('pointerup', end);
		E.scrubber.addEventListener('pointercancel', end);
		E.scrubber.addEventListener('pointerleave', function () {
			if (!dragging) {
				E.bubble.hidden = true;
			}
		});
	}

	// ---------------------------------------------------------- full screen

	/**
	 * When the server has no ffmpeg, a video's thumbnail is the first frame the
	 * browser shows when the user presses play. It is sent once; the server
	 * checks and re-encodes it, and accepts it only for the user's own video.
	 */
	function rememberFirstFrame(v, it) {
		var sent = false;
		function grab() {
			if (sent || !v.videoWidth || !v.videoHeight) {
				return;
			}
			sent = true;
			var scale = Math.min(1, 640 / v.videoHeight, 1280 / v.videoWidth);
			var c = document.createElement('canvas');
			c.width = Math.max(16, Math.round(v.videoWidth * scale));
			c.height = Math.max(16, Math.round(v.videoHeight * scale));
			try {
				c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
			} catch (e) {
				return;
			}
			c.toBlob(function (blob) {
				if (!blob) {
					return;
				}
				request(api('videoframe/' + it[F.id]), {
					method: 'POST',
					body: blob,
					headers: { 'Content-Type': 'image/jpeg' },
				}).then(function () {
					it[F.thumb] = 1;
					Thumbs.invalidate(it);
				}).catch(function () {
					// already has one, or the frame was not usable: nothing to do
				});
			}, 'image/jpeg', 0.9);
		}
		v.addEventListener('playing', function onPlay() {
			v.removeEventListener('playing', onPlay);
			if (v.readyState >= 2) {
				grab(); // the frame on screen right now: the first one
			} else if (v.requestVideoFrameCallback) {
				v.requestVideoFrameCallback(grab);
			} else {
				setTimeout(grab, 100);
			}
		});
	}

	var Viewer = (function () {
		var box = null;
		var idx = -1;
		var largeCache = new Map(); // id -> object URL
		var touch = null;
		var lastFocus = null;

		function build() {
			box = el('div', 'ptl-viewer');
			box.setAttribute('role', 'dialog');
			box.setAttribute('aria-modal', 'true');
			box.setAttribute('aria-label', tr('Photo viewer'));
			box.innerHTML =
				'<div class="ptl-v-stage"></div>' +
				'<button type="button" class="ptl-v-prev" aria-label=""></button>' +
				'<button type="button" class="ptl-v-next" aria-label=""></button>' +
				'<div class="ptl-v-bar">' +
				'<div class="ptl-v-info"><div class="ptl-v-date"></div><div class="ptl-v-name"></div><div class="ptl-v-dir"></div><div class="ptl-v-also"></div></div>' +
				'<div class="ptl-v-actions"><a class="ptl-v-files"></a><a class="ptl-v-download"></a>' +
				'<button type="button" class="ptl-v-close"></button></div></div>';
			box.querySelector('.ptl-v-prev').setAttribute('aria-label', tr('Previous'));
			box.querySelector('.ptl-v-next').setAttribute('aria-label', tr('Next'));
			box.querySelector('.ptl-v-files').textContent = tr('Show in Files');
			box.querySelector('.ptl-v-download').textContent = tr('Download original');
			var close = box.querySelector('.ptl-v-close');
			close.textContent = tr('Close');
			close.addEventListener('click', hide);
			box.querySelector('.ptl-v-prev').addEventListener('click', function () {
				go(-1);
			});
			box.querySelector('.ptl-v-next').addEventListener('click', function () {
				go(1);
			});
			// Capture phase: a focused video player would otherwise swallow Escape.
			box.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && !document.fullscreenElement) {
					e.preventDefault();
					hide();
				}
			}, true);
			box.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') {
					return; // handled above
				} else if (e.key === 'ArrowLeft') {
					go(-1);
				} else if (e.key === 'ArrowRight') {
					go(1);
				} else if (e.key === 'Tab') {
					trapFocus(e);
				}
			});
			var stage = box.querySelector('.ptl-v-stage');
			stage.addEventListener('touchstart', function (e) {
				if (e.touches.length === 1) {
					touch = { x: e.touches[0].clientX, y: e.touches[0].clientY };
				}
			}, { passive: true });
			stage.addEventListener('touchend', function (e) {
				if (!touch) {
					return;
				}
				var dx = e.changedTouches[0].clientX - touch.x;
				var dy = e.changedTouches[0].clientY - touch.y;
				touch = null;
				if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) {
					go(dx < 0 ? 1 : -1);
				} else if (dy > 90 && Math.abs(dy) > Math.abs(dx) * 1.5) {
					hide();
				}
			});
			stage.addEventListener('click', function (e) {
				if (e.target === stage) {
					hide();
				}
			});
			document.body.appendChild(box);
		}

		function trapFocus(e) {
			var f = Array.prototype.filter.call(box.querySelectorAll('button, a[href], video'), function (n) {
				return n.offsetParent !== null;
			});
			if (!f.length) {
				return;
			}
			var first = f[0];
			var last = f[f.length - 1];
			if (e.shiftKey && document.activeElement === first) {
				last.focus();
				e.preventDefault();
			} else if (!e.shiftKey && document.activeElement === last) {
				first.focus();
				e.preventDefault();
			}
		}

		function open(i) {
			if (!box) {
				build();
			}
			lastFocus = document.activeElement;
			box.classList.add('ptl-open');
			document.documentElement.classList.add('ptl-noscroll');
			show(i);
			box.querySelector('.ptl-v-close').focus();
		}

		function hide() {
			if (!box) {
				return;
			}
			box.classList.remove('ptl-open');
			document.documentElement.classList.remove('ptl-noscroll');
			var stage = box.querySelector('.ptl-v-stage');
			stage.textContent = '';
			if (lastFocus && document.body.contains(lastFocus)) {
				lastFocus.focus();
			}
			idx = -1;
		}

		function go(d) {
			var n = idx + d;
			if (n >= 0 && n < S.items.length) {
				show(n);
				// keep the grid in step, so closing returns to the same place
				scrollToItem(S.items[n][F.id]);
			}
		}

		function show(i) {
			idx = i;
			var it = S.items[i];
			var stage = box.querySelector('.ptl-v-stage');
			stage.textContent = '';
			if (!box.contains(document.activeElement)) {
				// the focused video was just removed: keep the keys working in the viewer
				box.querySelector('.ptl-v-close').focus({ preventScroll: true });
			}
			var path = itemPath(it);
			var mime = S.data.mimes[it[F.mime]];
			if (it[F.kind] === 2) {
				if (PLAYABLE.indexOf(mime) >= 0) {
					var v = el('video');
					v.controls = true;
					v.preload = 'metadata';
					v.playsInline = true;
					v.src = davUrl(path);
					stage.appendChild(v);
					// Chrome's own player buttons keep every key (and click) once used,
					// so Escape would no longer close the viewer. Media events still
					// fire: after each one, focus goes back to the video element itself.
					var refocus = function () {
						if (document.activeElement === v && !document.fullscreenElement) {
							setTimeout(function () {
								v.blur();
								v.focus({ preventScroll: true });
							}, 0);
						}
					};
					['play', 'pause', 'seeked', 'volumechange', 'ratechange'].forEach(function (name) {
						v.addEventListener(name, refocus);
					});
					if (it[F.thumb] !== 1) {
						rememberFirstFrame(v, it);
					}
				} else {
					var p = el('div', 'ptl-v-noplay');
					p.appendChild(el('p', '', tr('This video format cannot be played in the browser.')));
					var dl = el('a', 'button', tr('Download to watch'));
					dl.href = davUrl(path);
					dl.setAttribute('download', it[F.name]);
					p.appendChild(dl);
					stage.appendChild(p);
				}
			} else {
				var img = el('img', 'ptl-v-img');
				img.alt = it[F.name];
				var placeholder = Thumbs.cachedSmall(it[F.id]);
				if (placeholder) {
					img.src = placeholder;
					img.classList.add('ptl-v-low');
				}
				stage.appendChild(img);
				stage.classList.add('ptl-v-loading');
				large(it).then(function (url) {
					if (idx === i) {
						img.src = url;
						img.classList.remove('ptl-v-low');
						stage.classList.remove('ptl-v-loading');
					}
				}).catch(function () {
					if (idx === i) {
						stage.classList.remove('ptl-v-loading');
						if (!placeholder) {
							stage.appendChild(el('p', 'ptl-v-error', tr('This photo could not be shown.')));
						}
					}
				});
			}
			box.querySelector('.ptl-v-date').textContent = fullDate(it[F.taken]);
			box.querySelector('.ptl-v-name').textContent = it[F.name];
			var dir = S.data.dirs[it[F.dir]];
			box.querySelector('.ptl-v-dir').textContent = '/' + dir;
			var also = it[F.also];
			box.querySelector('.ptl-v-also').textContent = also && also.length ? tr('Also in: {dirs}', { dirs: also.map(function (d) {
				return '/' + d;
			}).join(', ') }) : '';
			box.querySelector('.ptl-v-files').href = OC.generateUrl('/apps/files/') + '?dir=' + encodeURIComponent('/' + dir) + '&scrollto=' + encodeURIComponent(it[F.name]);
			var d = box.querySelector('.ptl-v-download');
			d.href = davUrl(path);
			d.setAttribute('download', it[F.name]);
			box.querySelector('.ptl-v-prev').hidden = i <= 0;
			box.querySelector('.ptl-v-next').hidden = i >= S.items.length - 1;
			// get the neighbours ready, one after the other, once this one is shown
			var current = it[F.kind] === 1 ? large(it) : Promise.resolve();
			current.catch(function () {}).then(function () {
				var next = S.items[i + 1];
				if (idx === i && next && next[F.kind] === 1) {
					return large(next);
				}
			}).catch(function () {}).then(function () {
				var prev = S.items[i - 1];
				if (idx === i && prev && prev[F.kind] === 1) {
					large(prev).catch(function () {});
				}
			});
		}

		function largeEdge() {
			var px = Math.max(window.screen.width, window.screen.height) * (window.devicePixelRatio || 1);
			return px <= 1100 ? 1024 : (px <= 1700 ? 1600 : 2048);
		}

		function large(it) {
			var id = it[F.id];
			if (largeCache.has(id)) {
				return largeCache.get(id);
			}
			var p = request(api('large/' + id) + '?w=' + largeEdge() + '&v=' + it[F.ver]).then(function (r) {
				return r.blob();
			}).then(function (b) {
				return URL.createObjectURL(b);
			});
			largeCache.set(id, p);
			p.catch(function () {
				largeCache.delete(id);
			});
			if (largeCache.size > 40) {
				var firstKey = largeCache.keys().next().value;
				largeCache.get(firstKey).then(function (u) {
					URL.revokeObjectURL(u);
				}).catch(function () {});
				largeCache.delete(firstKey);
			}
			return p;
		}

		return { open: open, isOpen: function () {
			return idx >= 0;
		} };
	})();

	// ------------------------------------------------------------- settings

	function openSettings() {
		var overlay = el('div', 'ptl-modal');
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.setAttribute('aria-labelledby', 'ptl-settings-title');
		var panel = el('div', 'ptl-panel');
		overlay.appendChild(panel);
		var h = el('h2', '', tr('Folders in My timeline'));
		h.id = 'ptl-settings-title';
		panel.appendChild(h);
		panel.appendChild(el('p', 'ptl-hint', tr('Tick the folders to include. Subfolders are always included. When the same photo is in several folders, it is shown once, from the folder highest in this list.')));

		var selected = (S.settings && S.settings.folders ? S.settings.folders.slice() : []);
		var list = el('ol', 'ptl-selected');
		panel.appendChild(list);
		var treeTitle = el('h3', '', tr('All folders'));
		panel.appendChild(treeTitle);
		var tree = el('ul', 'ptl-tree');
		panel.appendChild(tree);
		var actions = el('div', 'ptl-panel-actions');
		var cancel = el('button', '', tr('Cancel'));
		cancel.type = 'button';
		var save = el('button', 'primary', tr('Save'));
		save.type = 'button';
		actions.appendChild(cancel);
		actions.appendChild(save);
		panel.appendChild(actions);

		function renderSelected() {
			list.textContent = '';
			if (!selected.length) {
				list.appendChild(el('li', 'ptl-empty', tr('No folders selected.')));
			}
			selected.forEach(function (p, i) {
				var li = el('li');
				li.appendChild(el('span', 'ptl-path', '/' + p));
				var up = el('button', 'ptl-up', '↑');
				up.type = 'button';
				up.setAttribute('aria-label', tr('Move up'));
				up.disabled = i === 0;
				up.addEventListener('click', function () {
					selected.splice(i - 1, 0, selected.splice(i, 1)[0]);
					renderSelected();
				});
				var rm = el('button', 'ptl-rm', '×');
				rm.type = 'button';
				rm.setAttribute('aria-label', tr('Remove {folder}', { folder: '/' + p }));
				rm.addEventListener('click', function () {
					selected.splice(i, 1);
					renderSelected();
					syncChecks();
				});
				li.appendChild(up);
				li.appendChild(rm);
				list.appendChild(li);
			});
		}

		function syncChecks() {
			tree.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
				cb.checked = selected.indexOf(cb.value) >= 0;
			});
		}

		function loadLevel(path, ul) {
			ul.appendChild(el('li', 'ptl-loading', tr('Loading…')));
			request(api('folders') + '?path=' + encodeURIComponent(path)).then(function (r) {
				return r.json();
			}).then(function (data) {
				ul.textContent = '';
				if (!data.folders.length) {
					ul.appendChild(el('li', 'ptl-empty', tr('No subfolders')));
				}
				data.folders.forEach(function (f) {
					var li = el('li');
					var row = el('div', 'ptl-tree-row');
					var exp = el('button', 'ptl-expand', '▸');
					exp.type = 'button';
					exp.setAttribute('aria-expanded', 'false');
					exp.setAttribute('aria-label', tr('Show subfolders of {folder}', { folder: f.name }));
					var label = el('label');
					var cb = el('input');
					cb.type = 'checkbox';
					cb.value = f.path;
					cb.checked = selected.indexOf(f.path) >= 0;
					cb.addEventListener('change', function () {
						var at = selected.indexOf(f.path);
						if (cb.checked && at < 0) {
							selected.push(f.path);
						} else if (!cb.checked && at >= 0) {
							selected.splice(at, 1);
						}
						renderSelected();
					});
					label.appendChild(cb);
					label.appendChild(el('span', '', f.name));
					label.appendChild(el('span', 'ptl-count', f.media ? f.media.toLocaleString() : ''));
					row.appendChild(exp);
					row.appendChild(label);
					li.appendChild(row);
					var sub = el('ul');
					sub.hidden = true;
					li.appendChild(sub);
					exp.addEventListener('click', function () {
						var open = sub.hidden;
						sub.hidden = !open;
						exp.textContent = open ? '▾' : '▸';
						exp.setAttribute('aria-expanded', String(open));
						if (open && !sub.dataset.loaded) {
							sub.dataset.loaded = '1';
							loadLevel(f.path, sub);
						}
					});
					ul.appendChild(li);
				});
			}).catch(function () {
				ul.textContent = '';
				ul.appendChild(el('li', 'ptl-empty', tr('Could not load folders.')));
			});
		}

		function close() {
			overlay.remove();
			document.removeEventListener('keydown', onKey, true);
			E.settingsBtn.focus();
		}

		function onKey(e) {
			if (e.key === 'Escape') {
				e.stopPropagation();
				close();
			}
		}

		cancel.addEventListener('click', close);
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) {
				close();
			}
		});
		save.addEventListener('click', function () {
			save.disabled = true;
			request(api('settings'), {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ folders: selected, types: S.types || 'all', from: S.from || null, to: S.to || null })
			}).then(function (r) {
				return r.json();
			}).then(function (s) {
				S.settings = s;
				close();
				load();
			}).catch(function () {
				save.disabled = false;
				OC.Notification && OC.Notification.showTemporary(tr('Could not save the folders.'));
			});
		});
		document.addEventListener('keydown', onKey, true);
		renderSelected();
		loadLevel('', tree);
		document.body.appendChild(overlay);
		cancel.focus();
	}

	// ----------------------------------------------------------------- init

	var resizeTimer = null;

	function resize() {
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(function () {
			relayout(false);
			renderScrubber();
		}, 100);
	}

	function bindCanvas() {
		var ticking = false;
		E.scroll.addEventListener('scroll', function () {
			if (!ticking) {
				ticking = true;
				requestAnimationFrame(function () {
					ticking = false;
					render();
				});
			}
		}, { passive: true });
		E.canvas.addEventListener('click', function (e) {
			var tile = e.target.closest('.ptl-tile');
			if (tile) {
				Viewer.open(Number(tile.dataset.idx));
			}
		});
		window.addEventListener('resize', resize);
		// Clear cached thumbnails when logging out from this page.
		var logout = document.getElementById('logout');
		if (logout) {
			logout.addEventListener('click', function () {
				Thumbs.clearCache();
			});
		}
	}

	function init() {
		bindElements();
		readUrl();
		bindToolbar();
		bindCanvas();
		bindScrubber();
		renderCrumbs();
		// The page carries the user's settings, so the timeline loads at once.
		var s = null;
		try {
			s = JSON.parse(E.root.getAttribute('data-settings') || 'null');
		} catch (e) {
			s = null;
		}
		S.settings = s;
		if (s && S.scope === 'mine' && !window.location.search) {
			// Last used filters of My timeline
			S.types = s.types || 'all';
			S.from = s.from || '';
			S.to = s.to || '';
		}
		S.types = S.types || 'all';
		renderFilters();
		writeUrl();
		load();
		if (E.root.getAttribute('data-cron-warning') && window.OC && OC.Notification) {
			OC.Notification.showTemporary(tr('PhotoLine: background jobs are not set to "Cron" (Admin settings). Thumbnails will be made slowly.'), { timeout: 12 });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
