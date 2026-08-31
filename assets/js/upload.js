/**
 * GitHub style file insertion for the Markdown editor.
 *
 * Dropping or pasting images uploads them and replaces an in-flight
 * placeholder with the finished Markdown. Each file gets its own placeholder
 * and is replaced independently, so one failure cannot disturb the others.
 *
 * Dropping a text file inlines its contents as a fenced code block instead,
 * with a language taken from the extension, or from the contents when the name
 * gives nothing away. That path is local: the file is read in the browser and
 * nothing is uploaded.
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
	 * Insert a block level construct at the cursor, on its own line.
	 *
	 * A fence opened halfway through a line is not a fence, so a newline goes
	 * in front unless the cursor already sits at the start of one. Another
	 * follows the block, so whatever came after the cursor keeps its own line.
	 *
	 * @param {object} target Editor handle from resolveTarget().
	 * @param {string} text Block to insert.
	 * @return {void}
	 */
	function insertBlockAtCursor(target, text) {
		var atLineStart;

		if (target.editor) {
			atLineStart = 0 === target.editor.getCursor('from').ch;
		} else {
			var start = target.textarea.selectionStart;

			atLineStart = 0 === start || '\n' === target.textarea.value.charAt(start - 1);
		}

		insertAtCursor(target, (atLineStart ? '' : '\n') + text + '\n');

		if (target.editor) {
			target.editor.save();
		}
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
	 * Work out the fence language for a file, sniffing when the name will not say.
	 *
	 * A shell script called "updatewp" carries no extension at all, so the
	 * contents have to answer. Only the head of the file is read for that: a
	 * dropped video is then refused without being pulled into memory whole.
	 *
	 * @param {File} file File to inspect.
	 * @return {Promise} Resolves to a fence info string, or null when binary.
	 */
	function resolveLanguage(file) {
		var language = lib.textFileLanguage(file.name);

		if (null !== language) {
			return window.Promise.resolve(language);
		}

		return file.slice(0, lib.SNIFF_BYTES).text().then(lib.sniffTextLanguage);
	}

	/**
	 * Read a text file and insert it as a fenced code block.
	 *
	 * Nothing is uploaded: the file never leaves the browser, and the media
	 * library gains no attachment.
	 *
	 * @param {object} target Editor handle from resolveTarget().
	 * @param {File} file File to read.
	 * @return {Promise} Resolves once the block has been inserted, or the error shown.
	 */
	function insertTextFile(target, file) {
		// Checked before anything is read, so an oversized file is refused on
		// its metadata alone. Images skip this: they upload, and the server
		// decides what it will take.
		if (file.size > lib.resolveMaxTextBytes(config.maxTextBytes)) {
			showError(file.name + ': ' + config.i18n.tooLarge);

			return window.Promise.resolve();
		}

		return resolveLanguage(file).then(function (language) {
			if (null === language) {
				showError(file.name + ': ' + config.i18n.notAccepted);

				return;
			}

			return file.text().then(function (content) {
				insertBlockAtCursor(target, lib.buildCodeFence(content, language));
			});
		}).catch(function () {
			showError(file.name + ': ' + config.i18n.readFailed);
		});
	}

	/**
	 * Handle a list of dropped files in the order they were given.
	 *
	 * Images upload and everything else goes down the text path, which inlines
	 * it as a code block or reports an error once it has looked at it.
	 *
	 * One sequential chain rather than parallel work, and one chain for both
	 * kinds rather than one per kind, so a drop mixing a screenshot and a
	 * snippet inserts them the way they were dropped.
	 *
	 * @param {object} target Editor handle from resolveTarget().
	 * @param {File[]} files Files to handle.
	 * @return {void}
	 */
	function handleFiles(target, files) {
		files.reduce(function (chain, file) {
			return chain.then(function () {
				return lib.isImageFile(file.type) ? uploadFile(target, file) : insertTextFile(target, file);
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

		// Blob.text() is what reads a dropped text file. It arrived later than
		// fetch, so it gets its own guard: an old browser disables the whole
		// handler rather than throwing halfway through a drop.
		if (!container || !lib || !config || !window.fetch || !window.Blob || !window.Blob.prototype.text) {
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
				handleFiles(target, Array.prototype.slice.call(files));
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
				handleFiles(target, files);
			}
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
