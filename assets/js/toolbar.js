/**
 * Formatting toolbar for the Markdown editor.
 *
 * Each action wraps, prefixes or replaces the current selection, or, for the
 * heading, rewrites the whole lines that selection spans. The pure
 * transforms live in ozhmd-toolbar-lib.js and are unit tested; this file is the
 * DOM wiring, the keyboard shortcuts, and the Add Media integration.
 */
(function () {
	'use strict';

	var lib = window.ozhmdToolbarLib;

	// Which pane the eye button is currently showing, and the class each state
	// puts on the editor container. Both panes is the default, and needs no
	// class of its own.
	var view = 'both';
	var VIEW_CLASSES = { both: '', source: 'ozhmd-view-source', preview: 'ozhmd-view-preview' };

	/**
	 * Show the next combination of panes.
	 *
	 * @return {void}
	 */
	function cycleView() {
		var container = document.querySelector('[data-ozhmd-editor]');

		if (!container) {
			return;
		}

		view = lib.nextView(view);

		container.classList.remove(VIEW_CLASSES.source, VIEW_CLASSES.preview);

		if (VIEW_CLASSES[view]) {
			container.classList.add(VIEW_CLASSES[view]);
		}

		// CodeMirror cannot measure a hidden pane, so it comes back with a
		// stale gutter and cursor unless it is told to look again.
		if (window.ozhmdCodeMirror && 'preview' !== view) {
			window.ozhmdCodeMirror.refresh();
		}
	}

	/**
	 * Show a toggle's other glyph, for the buttons that carry two.
	 *
	 * A padlock that opens says more at this size than the same padlock at a
	 * lower opacity, which reads as disabled rather than off.
	 *
	 * @param {HTMLElement} button Button holding the pair.
	 * @param {boolean} on Which of the two to show.
	 * @return {void}
	 */
	function swapIcon(button, on) {
		var names = [
			button.getAttribute('data-ozhmd-icon-on'),
			button.getAttribute('data-ozhmd-icon-off')
		];
		var glyph = button.querySelector('.dashicons');

		if (!names[0] || !names[1] || !glyph) {
			return;
		}

		glyph.classList.remove('dashicons-' + names[0], 'dashicons-' + names[1]);
		glyph.classList.add('dashicons-' + (on ? names[0] : names[1]));
	}

	/**
	 * Tie the panes' scrolling together, or let them move independently.
	 *
	 * The state is kept on the container rather than in here, because that is
	 * where editor.js reads it. Neither file has to know about the other.
	 *
	 * @param {HTMLElement} button The toggle that was pressed.
	 * @return {void}
	 */
	function toggleSync(button) {
		var container = document.querySelector('[data-ozhmd-editor]');

		if (!container) {
			return;
		}

		var enabled = 'off' === container.getAttribute('data-ozhmd-sync');

		container.setAttribute('data-ozhmd-sync', enabled ? 'on' : 'off');

		if (button) {
			button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
			swapIcon(button, enabled);
		}
	}

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

		/**
		 * Bounds of the whole lines the selection spans.
		 *
		 * @return {object} CodeMirror positions, or textarea offsets.
		 */
		function blockBounds() {
			if (cm) {
				var to = cm.getCursor('to');

				return {
					start: { line: cm.getCursor('from').line, ch: 0 },
					end: { line: to.line, ch: cm.getLine(to.line).length }
				};
			}

			var value = textarea.value;
			var after = value.indexOf('\n', textarea.selectionEnd);

			return {
				start: value.lastIndexOf('\n', textarea.selectionStart - 1) + 1,
				end: -1 === after ? value.length : after
			};
		}

		/**
		 * Whether the selection stays within a single line.
		 *
		 * @return {boolean} True when it does.
		 */
		function withinOneLine() {
			if (cm) {
				return cm.getCursor('from').line === cm.getCursor('to').line;
			}

			return -1 === textarea.value.slice(textarea.selectionStart, textarea.selectionEnd).indexOf('\n');
		}

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
			 * Whole lines the selection spans, the unit line transforms work on.
			 *
			 * @return {string} The lines, joined by newlines.
			 */
			getBlock: function () {
				var bounds = blockBounds();

				if (cm) {
					return cm.getRange(bounds.start, bounds.end);
				}

				return textarea.value.slice(bounds.start, bounds.end);
			},

			/**
			 * Replace those lines, leaving the caret on the same text.
			 *
			 * A caret on a single line is moved by however much that line grew,
			 * so clicking a button twice in a row acts on the same spot. A
			 * selection spanning several lines is reapplied over the new block
			 * for the same reason.
			 *
			 * @param {object} result Result from a lib line transform.
			 * @return {void}
			 */
			replaceBlock: function (result) {
				var bounds = blockBounds();
				var oneLine = withinOneLine();

				if (cm) {
					var from = cm.getCursor('from');
					var to = cm.getCursor('to');

					cm.replaceRange(result.text, bounds.start, bounds.end);

					var base = cm.indexFromPos(bounds.start);

					if (result.select) {
						cm.setSelection(
							cm.posFromIndex(base + result.select[0]),
							cm.posFromIndex(base + result.select[1])
						);
					} else if (oneLine) {
						cm.setSelection(
							{ line: from.line, ch: Math.max(0, from.ch + result.shift) },
							{ line: to.line, ch: Math.max(0, to.ch + result.shift) }
						);
					} else {
						cm.setSelection(bounds.start, cm.posFromIndex(base + result.text.length));
					}

					cm.focus();
					cm.save();

					return;
				}

				var start = textarea.selectionStart;
				var end = textarea.selectionEnd;

				textarea.value = textarea.value.slice(0, bounds.start) +
					result.text +
					textarea.value.slice(bounds.end);

				if (result.select) {
					textarea.selectionStart = bounds.start + result.select[0];
					textarea.selectionEnd = bounds.start + result.select[1];
				} else if (oneLine) {
					textarea.selectionStart = Math.max(bounds.start, start + result.shift);
					textarea.selectionEnd = Math.max(bounds.start, end + result.shift);
				} else {
					textarea.selectionStart = bounds.start;
					textarea.selectionEnd = bounds.start + result.text.length;
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
	 * View and sync are the two buttons that leave the text alone, so they are
	 * answered here before an editor is even looked for.
	 *
	 * @param {string} action Action name from the toolbar.
	 * @param {HTMLElement} [button] Button that was pressed, for the toggles.
	 * @return {void}
	 */
	function apply(action, button) {
		if ('view' === action) {
			cycleView();

			return;
		}

		if ('sync' === action) {
			toggleSync(button);

			return;
		}

		var editor = resolveEditor();

		if (!editor) {
			return;
		}

		if (lib.lineTransforms[action]) {
			editor.replaceBlock(lib.lineTransforms[action](editor.getBlock()));

			return;
		}

		if (!lib.transforms[action]) {
			return;
		}

		editor.replaceSelection(lib.transforms[action](editor.getSelection()));
	}

	/**
	 * Carry a list on to the next line when enter is pressed inside one.
	 *
	 * Enter keeps its usual meaning everywhere else, and returning Pass is how
	 * CodeMirror is told to go and do that. A bare marker is cleared instead of
	 * repeated, which is how a writer leaves the list.
	 *
	 * @param {object} cm CodeMirror instance.
	 * @return {object|undefined} CodeMirror.Pass to fall through, or nothing.
	 */
	function continueList(cm) {
		if (cm.somethingSelected() || cm.listSelections().length > 1) {
			return window.CodeMirror.Pass;
		}

		var cursor = cm.getCursor();
		var line = cm.getLine(cursor.line);
		var item = lib.listContinuation(line);

		// A cursor sitting inside the marker itself is not yet writing the
		// item, so enter there splits the line as it normally would.
		if (!item || cursor.ch < item.markerLength) {
			return window.CodeMirror.Pass;
		}

		if (item.empty) {
			cm.replaceRange('', { line: cursor.line, ch: 0 }, { line: cursor.line, ch: line.length });

			return;
		}

		cm.replaceSelection('\n' + item.prefix);
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
			apply(button.getAttribute('data-ozhmd-action'), button);
		});

		interceptMediaInsert();

		// editor.js runs first, so the instance is already there. A keymap
		// added this way is consulted before CodeMirror's own bindings.
		if (window.ozhmdCodeMirror && window.CodeMirror) {
			window.ozhmdCodeMirror.addKeyMap({ Enter: continueList });
		}

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
