/**
 * Formatting toolbar for the Markdown editor.
 *
 * Each action wraps, prefixes or replaces the current selection. The pure
 * transforms live in ozhmd-toolbar-lib.js and are unit tested; this file is the
 * DOM wiring, the keyboard shortcuts, and the Add Media integration.
 */
(function () {
	'use strict';

	var lib = window.ozhmdToolbarLib;

	/**
	 * Read the current selection from whichever editor is active.
	 *
	 * @return {object|null} Editor handle, or null when there is none.
	 */
	function resolveEditor() {
		var textarea = document.getElementById('ozhmd-markdown');

		if (!textarea) {
			return null;
		}

		var cm = window.ozhmdCodeMirror || null;

		return {
			/**
			 * Currently selected text.
			 *
			 * @return {string} The selection, empty when nothing is selected.
			 */
			getSelection: function () {
				return cm ? cm.getSelection() : textarea.value.slice(textarea.selectionStart, textarea.selectionEnd);
			},

			/**
			 * Replace the selection and place the cursor.
			 *
			 * @param {object} result Result from a lib transform.
			 * @return {void}
			 */
			replaceSelection: function (result) {
				if (cm) {
					var from = cm.getCursor('from');

					cm.replaceSelection(result.text);

					if (result.select) {
						// Reselect the placeholder so typing overwrites it.
						var head = cm.posFromIndex(cm.indexFromPos(from) + result.select[0]);
						var tail = cm.posFromIndex(cm.indexFromPos(from) + result.select[1]);

						cm.setSelection(head, tail);
					}

					cm.focus();
					cm.save();

					return;
				}

				var start = textarea.selectionStart;

				textarea.value = textarea.value.slice(0, start) + result.text + textarea.value.slice(textarea.selectionEnd);

				if (result.select) {
					textarea.selectionStart = start + result.select[0];
					textarea.selectionEnd = start + result.select[1];
				} else {
					textarea.selectionStart = textarea.selectionEnd = start + result.text.length;
				}

				textarea.focus();
			},

			/**
			 * Insert text at the cursor, replacing any selection.
			 *
			 * @param {string} text Text to insert.
			 * @return {void}
			 */
			insert: function (text) {
				this.replaceSelection({ text: text, select: null });
			}
		};
	}

	/**
	 * Apply a named action to the current selection.
	 *
	 * @param {string} action Action name from the toolbar.
	 * @return {void}
	 */
	function apply(action) {
		var editor = resolveEditor();

		if (!editor || !lib.transforms[action]) {
			return;
		}

		editor.replaceSelection(lib.transforms[action](editor.getSelection()));
	}

	/**
	 * Route WordPress media insertions into the Markdown editor.
	 *
	 * The Add Media modal hands back HTML. Converting the common image and
	 * link cases keeps the source Markdown rather than dropping raw HTML into
	 * it; anything else is inserted unchanged, which is still valid.
	 *
	 * @return {void}
	 */
	function interceptMediaInsert() {
		var original = window.send_to_editor;

		window.send_to_editor = function (html) {
			var editor = resolveEditor();

			if (!editor) {
				if (original) {
					original.apply(this, arguments);
				}

				return;
			}

			editor.insert(lib.htmlToMarkdown(html));
		};

		// wpActiveEditor tells the media modal which editor asked for it.
		window.wpActiveEditor = 'ozhmd-markdown';
	}

	/**
	 * Wire the toolbar buttons and keyboard shortcuts.
	 *
	 * @return {void}
	 */
	function init() {
		var toolbar = document.querySelector('[data-ozhmd-toolbar]');

		if (!toolbar || !lib) {
			return;
		}

		toolbar.addEventListener('click', function (event) {
			var button = event.target.closest('[data-ozhmd-action]');

			if (!button) {
				return;
			}

			event.preventDefault();
			apply(button.getAttribute('data-ozhmd-action'));
		});

		interceptMediaInsert();

		var shortcuts = { b: 'bold', i: 'italic', k: 'link' };

		document.addEventListener('keydown', function (event) {
			if (!event.ctrlKey && !event.metaKey) {
				return;
			}

			var action = shortcuts[event.key.toLowerCase()];

			if (!action) {
				return;
			}

			var textarea = document.getElementById('ozhmd-markdown');
			var withinEditor = document.activeElement === textarea ||
				(document.activeElement && document.activeElement.closest('[data-ozhmd-editor]'));

			if (!withinEditor) {
				return;
			}

			event.preventDefault();
			apply(action);
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
