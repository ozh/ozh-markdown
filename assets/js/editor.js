/**
 * Split pane Markdown editor.
 *
 * Attaches CodeMirror to the textarea, renders a debounced preview with
 * marked.js, and highlights code blocks in the preview with Prism.
 *
 * The textarea remains the real form field. CodeMirror writes back into it on
 * every change, so the no-JavaScript path and the enhanced path submit through
 * exactly the same input.
 */
(function () {
	'use strict';

	var DEBOUNCE_MS = 300;

	/**
	 * Render Markdown into the preview pane and highlight its code blocks.
	 *
	 * @param {string} markdown Raw Markdown source.
	 * @param {HTMLElement} preview Preview container.
	 * @return {void}
	 */
	function renderPreview(markdown, preview) {
		preview.innerHTML = window.marked.parse(markdown);

		if (window.Prism) {
			window.Prism.highlightAllUnder(preview);
		}
	}

	/**
	 * Wrap a function so it runs only after calls stop for a given delay.
	 *
	 * @param {Function} fn Function to defer.
	 * @param {number} wait Milliseconds of quiet required.
	 * @return {Function} Debounced wrapper.
	 */
	function debounce(fn, wait) {
		var timer = null;

		return function () {
			var args = arguments;

			window.clearTimeout(timer);
			timer = window.setTimeout(function () {
				fn.apply(null, args);
			}, wait);
		};
	}

	/**
	 * Start the editor once the DOM is ready.
	 *
	 * @return {void}
	 */
	function init() {
		var container = document.querySelector('[data-ozhmd-editor]');
		var textarea = document.getElementById('ozhmd-markdown');
		var preview = document.querySelector('[data-ozhmd-preview]');

		if (!container || !textarea || !preview) {
			return;
		}

		// Without CodeMirror or marked the plain textarea still works and still
		// saves, so failing quietly here is the correct fallback.
		if (!window.CodeMirror || !window.marked) {
			renderPlainNotice(container);

			return;
		}

		var editor = window.CodeMirror.fromTextArea(textarea, {
			mode: 'markdown',
			lineNumbers: true,
			lineWrapping: true,
			viewportMargin: Infinity
		});

		var update = debounce(function () {
			renderPreview(editor.getValue(), preview);
		}, DEBOUNCE_MS);

		editor.on('change', function () {
			// Keep the underlying textarea in sync so a submit at any moment
			// posts the current text, not the text as of the last blur.
			editor.save();
			update();
		});

		renderPreview(editor.getValue(), preview);
	}

	/**
	 * Mark the container as unenhanced so the CSS can lay it out sensibly.
	 *
	 * @param {HTMLElement} container Editor container.
	 * @return {void}
	 */
	function renderPlainNotice(container) {
		container.classList.add('ozhmd-editor-plain');
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
