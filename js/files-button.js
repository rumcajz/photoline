/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 *
 * Adds a "Photos Timeline" button to the Files app, next to Gallery's, which
 * opens the timeline of the folder currently shown.
 */
(function () {
	'use strict';

	function init() {
		var filesApp = document.getElementById('filesApp');
		var controls = document.getElementById('controls');
		if (!filesApp || !controls || document.getElementById('isPublic') && document.getElementById('isPublic').value) {
			return; // not the logged-in Files view (e.g. a public share link)
		}
		var button = document.createElement('a');
		button.id = 'photos-timeline-button';
		button.className = 'button view-switcher';
		var label = window.t ? window.t('photos_timeline', 'Open this folder in Photos Timeline') : 'Open this folder in Photos Timeline';
		button.title = label;
		button.setAttribute('aria-label', label);
		var img = document.createElement('img');
		img.className = 'svg';
		img.alt = '';
		img.src = OC.imagePath('photos_timeline', 'timeline-dark.svg');
		button.appendChild(img);

		function update() {
			var dir = '/';
			if (window.OCA && OCA.Files && OCA.Files.App && OCA.Files.App.fileList) {
				dir = OCA.Files.App.fileList.getCurrentDirectory();
			} else if (window.FileList) {
				dir = FileList.getCurrentDirectory();
			}
			button.href = OC.generateUrl('/apps/photos_timeline/') + '?folder=' + encodeURIComponent(String(dir).replace(/^\/+/, ''));
		}

		update();
		// Gallery prepends its button too; keep ours right next to it.
		var gallery = document.getElementById('gallery-button');
		if (gallery) {
			gallery.parentNode.insertBefore(button, gallery);
		} else {
			controls.insertBefore(button, controls.firstChild);
		}
		var list = document.getElementById('fileList');
		if (list && window.$) {
			$(list).on('updated changeDirectory', update);
		}
		window.addEventListener('popstate', update);
		button.addEventListener('mousedown', update);
		button.addEventListener('focus', update);
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
