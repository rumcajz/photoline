/**
 * YearRoll
 *
 * @license AGPL-3.0
 *
 * Adds a "YearRoll" button to the Files app, next to Gallery's, which
 * opens the timeline of the folder currently shown.
 */
(function () {
	'use strict';

	function currentDir() {
		try {
			var fl = window.OCA && OCA.Files && OCA.Files.App && OCA.Files.App.fileList;
			if (fl && typeof fl.getCurrentDirectory === 'function') {
				return fl.getCurrentDirectory();
			}
		} catch (e) {
			// fall back to the address below
		}
		var dir = new URLSearchParams(window.location.search).get('dir');
		return dir || '/';
	}

	function init() {
		var isPublic = document.getElementById('isPublic');
		if (!document.getElementById('filesApp') || (isPublic && isPublic.value)) {
			return; // not the logged-in Files view (e.g. a public share link)
		}
		var controls = document.querySelector('#app-content-files #controls') || document.getElementById('controls');
		if (!controls || document.getElementById('photos-timeline-button')) {
			return;
		}
		var button = document.createElement('a');
		button.id = 'photos-timeline-button';
		button.className = 'button view-switcher';
		var label = window.t ? window.t('photos_timeline', 'Open this folder in YearRoll') : 'Open this folder in YearRoll';
		button.title = label;
		button.setAttribute('aria-label', label);
		var img = document.createElement('img');
		img.className = 'svg';
		img.alt = '';
		img.src = OC.imagePath('photos_timeline', 'timeline-dark.svg');
		button.appendChild(img);

		function update() {
			var dir = String(currentDir()).replace(/^\/+/, '');
			button.href = OC.generateUrl('/apps/photos_timeline/') + '?folder=' + encodeURIComponent(dir);
		}

		update();
		// Next to Gallery's button (both float right).
		var gallery = document.getElementById('gallery-button');
		if (gallery && gallery.parentNode === controls) {
			controls.insertBefore(button, gallery.nextSibling);
		} else {
			controls.insertBefore(button, controls.firstChild);
		}
		// The folder can change without a page load: refresh the link whenever it may be used.
		['mouseenter', 'mousedown', 'focus', 'touchstart'].forEach(function (ev) {
			button.addEventListener(ev, update, { passive: true });
		});
		window.addEventListener('popstate', update);
		if (window.$) {
			$('#app-content-files').on('changeDirectory', function () {
				setTimeout(update, 0);
			});
		}
	}

	if (window.$) {
		// after the Files app (and Gallery) have set up their controls
		$(document).ready(function () {
			setTimeout(init, 0);
		});
	} else {
		document.addEventListener('DOMContentLoaded', init);
	}
})();
