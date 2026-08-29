/**
 * GitHub style image insertion for the Markdown editor.
 *
 * Dropping or pasting images uploads them and replaces an in-flight
 * placeholder with the finished Markdown. Each file gets its own placeholder
 * and is replaced independently, so one failure cannot disturb the others.
 *
 * All string manipulation lives in ozhmd-upload-lib.js, which is unit tested.
 * This file is the DOM and network wiring around it.
 */
(function () {
	'use strict';

	var lib = window.ozhmdUploadLib;
	var config = window.ozhmdUpload;

	/**
	 * Insert text into the editor at the cursor.
	 *
	 * Uses the CodeMirror instance when present so undo history and cursor
	 * position survive, and falls back to the plain textarea otherwise.
	 *
	 * @param {object} target Editor handle from resolveTarget().
	 * @param {string} text Text to insert.
	 * @return {void}
	 */
	function insertAtCursor(target, text) {
		if (target.editor) {
			target.editor.replaceSelection(text);

			return;
		}

		var el = target.textarea;
		var start = el.selectionStart;
		var end = el.selectionEnd;

		el.value = el.value.slice(0, start) + text + el.value.slice(end);
		el.selectionStart = el.selectionEnd = start + text.length;
	}

	/**
	 * Replace the first literal occurrence of a placeholder.
	 *
	 * @param {object} target Editor handle from resolveTarget().
	 * @param {string} placeholder Exact placeholder text.
	 * @param {string} replacement Text to put in its place.
	 * @return {void}
	 */
	function replacePlaceholder(target, placeholder, replacement) {
		if (target.editor) {
			target.editor.setValue(lib.replacePlaceholder(target.editor.getValue(), placeholder, replacement));
			target.editor.save();

			return;
		}

		target.textarea.value = lib.replacePlaceholder(target.textarea.value, placeholder, replacement);
	}

	/**
	 * Show a dismissible error above the editor.
	 *
	 * @param {string} message Message to display.
	 * @return {void}
	 */
	function showError(message) {
		var container = document.querySelector('[data-ozhmd-errors]');

		if (!container) {
			return;
		}

		var notice = document.createElement('div');

		notice.className = 'notice notice-error is-dismissible ozhmd-upload-error';

		var text = document.createElement('p');

		// textContent, never innerHTML: the message can contain a file name.
		text.textContent = message;
		notice.appendChild(text);

		var dismiss = document.createElement('button');

		dismiss.type = 'button';
		dismiss.className = 'notice-dismiss';
		dismiss.addEventListener('click', function () {
			notice.remove();
		});
		notice.appendChild(dismiss);

		container.appendChild(notice);
	}

	/**
	 * Upload one file and swap its placeholder for the result.
	 *
	 * @param {object} target Editor handle from resolveTarget().
	 * @param {File} file File to upload.
	 * @return {Promise} Resolves once the placeholder has been replaced.
	 */
	function uploadFile(target, file) {
		var placeholder = lib.buildPlaceholder(file.name);

		insertAtCursor(target, placeholder);

		var body = new FormData();

		body.append('file', file);
		body.append('post_id', config.postId);

		return window.fetch(config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce },
			body: body
		}).then(function (response) {
			return response.json().then(function (payload) {
				if (!response.ok) {
					throw new Error(payload && payload.message ? payload.message : config.i18n.uploadFailed);
				}

				return payload;
			});
		}).then(function (payload) {
			replacePlaceholder(target, placeholder, lib.buildMarkdownImage(payload.alt, payload.url));
		}).catch(function (error) {
			replacePlaceholder(target, placeholder, '');
			showError(file.name + ': ' + error.message);
		});
	}

	/**
	 * Upload a list of files in the order they were given.
	 *
	 * Sequential rather than parallel, so the placeholders are replaced in the
	 * order the files were dropped.
	 *
	 * @param {object} target Editor handle from resolveTarget().
	 * @param {File[]} files Files to upload.
	 * @return {void}
	 */
	function uploadAll(target, files) {
		var images = [];
		var rejected = [];

		files.forEach(function (file) {
			if (lib.isImageFile(file.type)) {
				images.push(file);
			} else {
				rejected.push(file.name);
			}
		});

		rejected.forEach(function (name) {
			showError(name + ': ' + config.i18n.notAnImage);
		});

		images.reduce(function (chain, file) {
			return chain.then(function () {
				return uploadFile(target, file);
			});
		}, window.Promise.resolve());
	}

	/**
	 * Resolve the editor to insert into.
	 *
	 * @return {object|null} Handle with textarea and optional editor, or null.
	 */
	function resolveTarget() {
		var textarea = document.getElementById('ozhmd-markdown');

		if (!textarea) {
			return null;
		}

		return {
			textarea: textarea,
			editor: window.ozhmdCodeMirror || null
		};
	}

	/**
	 * Wire the drop and paste handlers.
	 *
	 * @return {void}
	 */
	function init() {
		var container = document.querySelector('[data-ozhmd-editor]');

		if (!container || !lib || !config || !window.fetch) {
			return;
		}

		container.addEventListener('dragover', function (event) {
			event.preventDefault();
			container.classList.add('ozhmd-dragging');
		});

		container.addEventListener('dragleave', function () {
			container.classList.remove('ozhmd-dragging');
		});

		container.addEventListener('drop', function (event) {
			var files = event.dataTransfer && event.dataTransfer.files;

			container.classList.remove('ozhmd-dragging');

			if (!files || 0 === files.length) {
				return;
			}

			event.preventDefault();

			var target = resolveTarget();

			if (target) {
				uploadAll(target, Array.prototype.slice.call(files));
			}
		});

		container.addEventListener('paste', function (event) {
			var items = event.clipboardData && event.clipboardData.files;

			if (!items || 0 === items.length) {
				return;
			}

			// Only intervene when the clipboard actually carries an image.
			// Pasting text must keep its normal behaviour.
			var files = Array.prototype.slice.call(items).filter(function (file) {
				return lib.isImageFile(file.type);
			});

			if (0 === files.length) {
				return;
			}

			event.preventDefault();

			var target = resolveTarget();

			if (target) {
				uploadAll(target, files);
			}
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
