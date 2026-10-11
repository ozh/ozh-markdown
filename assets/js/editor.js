/**
 * Split pane Markdown editor.
 *
 * Attaches CodeMirror to the textarea, renders a debounced preview with
 * marked.js, highlights code blocks in the preview with Prism, and keeps the
 * two panes scrolled to the same place.
 *
 * The textarea remains the real form field. CodeMirror writes back into it on
 * every change, so the no-JavaScript path and the enhanced path submit through
 * exactly the same input.
 */
(function () {
	'use strict';

	var DEBOUNCE_MS = 300;

	// Rebuilding the anchor table reads layout from both panes, so a resize is
	// given longer to settle than a keystroke is.
	var RESIZE_DEBOUNCE_MS = 150;

	// How close to the bottom edge of the preview the cursor's block may sit
	// before it counts as off screen and the preview is moved to it.
	var CURSOR_MARGIN_PX = 40;

	// How far one press of an arrow key moves each of the two handles: pixels
	// of height for the one under the editor, points of the row's width for the
	// one between the panes.
	var RESIZE_STEP_PX = 32;
	var SPLIT_STEP_PERCENT = 5;

	// Embeds are let through so the preview shows what an author with
	// unfiltered_html will publish. DOMPurify still vets every attribute,
	// including the src, and srcdoc is never allowed.
	var PURIFY_CONFIG = {
		ADD_TAGS: ['iframe'],
		ADD_ATTR: ['allow', 'allowfullscreen', 'frameborder', 'scrolling']
	};

	/**
	 * Make rendered Markdown safe to put in the page.
	 *
	 * marked passes raw HTML straight through, and the Markdown shown here is
	 * the stored source, which no one has filtered. Unsanitized, a payload a
	 * contributor stored would run in the browser of whoever opens the post,
	 * administrators included, before anything is saved.
	 *
	 * Fails closed: without a working DOMPurify the HTML is shown as text.
	 *
	 * @param {string} html HTML produced by marked.
	 * @param {HTMLElement} preview Preview container.
	 * @return {void}
	 */
	function setPreviewHtml(html, preview) {
		var purify = window.DOMPurify;

		if (!purify || !purify.isSupported) {
			preview.textContent = html;

			return;
		}

		preview.innerHTML = purify.sanitize(html, PURIFY_CONFIG);
	}

	/**
	 * Render Markdown into the preview pane and highlight its code blocks.
	 *
	 * @param {string} markdown Raw Markdown source.
	 * @param {HTMLElement} preview Preview container.
	 * @return {void}
	 */
	function renderPreview(markdown, preview) {
		setPreviewHtml(window.marked.parse(markdown), preview);

		// Prism activates match-braces and line-numbers per block, from a class
		// on the <pre>. The server adds the same classes on the front end.
		var config = window.ozhmdEditor || {};
		var classes = config.preClasses || [];

		Array.prototype.forEach.call(preview.querySelectorAll('pre > code'), function (code) {
			code.parentNode.classList.add.apply(code.parentNode.classList, classes);

			// marked closes every fence with "\n</code></pre>". Inside a <pre>
			// that newline is content, so the block ends on an empty line that
			// the line numbers plugin does not count: a blank row, unnumbered,
			// under the last line of code.
			code.textContent = code.textContent.replace(/\n$/, '');
		});

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
	 * Tie the two panes together so that scrolling either one moves the other.
	 *
	 * Every top level Markdown block is measured in both panes, giving a table
	 * of positions known to be the same place in the document. A scroll
	 * position between two of them is interpolated. The arithmetic lives in
	 * ozhmd-scroll-lib.js; what is here is the measuring and the wiring.
	 *
	 * @param {object} editor CodeMirror instance.
	 * @param {HTMLElement} preview Preview container, itself the scroll box.
	 * @param {HTMLElement} container Editor container, which carries the view class.
	 * @return {object} Handle exposing rebuild(), for callers that change the
	 *                  preview, and remeasure(), for callers that change its size.
	 */
	function createScrollSync(editor, preview, container) {
		var lib = window.ozhmdScrollLib;
		var anchors = [];

		// Set while one pane is being moved to match the other. The scroll
		// event that move provokes would otherwise be read as a real one and
		// sent straight back, leaving the two panes shoving each other.
		var syncing = false;

		/**
		 * Furthest each pane can be scrolled.
		 *
		 * @return {object} Maximum scroll position of each pane.
		 */
		function limits() {
			var info = editor.getScrollInfo();

			return {
				source: Math.max(0, info.height - info.clientHeight),
				preview: Math.max(0, preview.scrollHeight - preview.clientHeight)
			};
		}

		/**
		 * Whether both panes are on screen and worth syncing.
		 *
		 * The view button hides one of them, and a hidden pane has no geometry
		 * to measure or to scroll.
		 *
		 * @return {boolean} True when both are visible.
		 */
		function bothVisible() {
			return !container.classList.contains('ozhmd-view-source') &&
				!container.classList.contains('ozhmd-view-preview');
		}

		/**
		 * Whether there is anything to do at all.
		 *
		 * The sync toggle is written to the container by the toolbar, and is
		 * read rather than stored so there is one copy of the answer. Absent
		 * means on, so the editor still syncs if the markup predates the
		 * toggle.
		 *
		 * @return {boolean} True when the panes should be kept together.
		 */
		function active() {
			return 'off' !== container.getAttribute('data-ozhmd-sync') && bothVisible();
		}

		/**
		 * Measure both panes and rebuild the anchor table.
		 *
		 * @return {void}
		 */
		function rebuild() {
			if (!window.marked.lexer || !active()) {
				return;
			}

			var lines = lib.tokenLines(window.marked.lexer(editor.getValue()));
			var blocks = preview.children;
			var ends = limits();
			var table = [{ source: 0, preview: 0 }];

			// One block, one element, matched by position. That holds until the
			// source contains raw HTML, where a single token can render to two
			// elements, or to a comment and no element at all. Counting is
			// enough to catch it, and a table of just the two ends is a plain
			// percentage map, which is the honest thing to fall back to.
			if (lines.length === blocks.length) {
				// getBoundingClientRect is viewport relative, so the preview's
				// own top is taken back off to land in its scroll coordinates,
				// the space its scrollTop is expressed in.
				var base = preview.getBoundingClientRect().top - preview.scrollTop;

				for (var i = 0; i < lines.length; i++) {
					var pair = {
						source: editor.charCoords({ line: lines[i], ch: 0 }, 'local').top,
						preview: blocks[i].getBoundingClientRect().top - base
					};
					var previous = table[table.length - 1];

					// Interpolating needs the table to climb on both sides. A
					// block measuring level with the one before it would break
					// that, and gains nothing by being there.
					if (pair.source > previous.source && pair.preview > previous.preview) {
						table.push(pair);
					}
				}
			}

			var last = table[table.length - 1];

			// Pins the two bottoms together, so the end of the document is
			// reached in both panes at once.
			if (ends.source > last.source && ends.preview > last.preview) {
				table.push(ends);
			}

			anchors = table;
		}

		/**
		 * Move the pane opposite the one that was scrolled.
		 *
		 * @param {string} from Pane that was scrolled, 'source' or 'preview'.
		 * @return {void}
		 */
		function syncFrom(from) {
			if (syncing || !active()) {
				return;
			}

			var pos = 'source' === from ? editor.getScrollInfo().top : preview.scrollTop;
			var target = lib.mapScroll(anchors, from, pos);

			syncing = true;

			if ('source' === from) {
				preview.scrollTop = target;
			} else {
				editor.scrollTo(null, target);
			}

			// Released only once the browser has dispatched the scroll event
			// the assignment above just caused.
			window.requestAnimationFrame(function () {
				syncing = false;
			});
		}

		/**
		 * Bring the block being typed in into view in the preview.
		 *
		 * The preview is left alone while the cursor's own block is already on
		 * screen, so it does not twitch on every keystroke. When it does move,
		 * the block is put at the same height in the preview as the cursor sits
		 * at in the source, which is where the eye is already looking.
		 *
		 * @return {void}
		 */
		function syncCursor() {
			if (syncing || !active()) {
				return;
			}

			var top = editor.charCoords(editor.getCursor(), 'local').top;
			var offset = top - editor.getScrollInfo().top;
			var target = lib.mapScroll(anchors, 'source', top);
			var seen = preview.scrollTop;

			if (target >= seen && target <= seen + preview.clientHeight - CURSOR_MARGIN_PX) {
				return;
			}

			syncing = true;
			preview.scrollTop = target - offset;

			window.requestAnimationFrame(function () {
				syncing = false;
			});
		}

		/**
		 * Take the measurements again and put the panes back together.
		 *
		 * Anything that changes the size of either pane invalidates the table,
		 * because the bottom anchor is derived from how much of the document
		 * each pane can show at once, and leaves the preview sitting at a
		 * position that no longer matches the source.
		 *
		 * @return {void}
		 */
		function remeasure() {
			rebuild();
			syncFrom('source');
		}

		// Without the lib there is nothing to map with, so the panes are left
		// to scroll independently rather than badly.
		if (!lib) {
			return { rebuild: function () {}, remeasure: function () {} };
		}

		editor.on('scroll', function () {
			syncFrom('source');
		});

		editor.on('cursorActivity', syncCursor);

		preview.addEventListener('scroll', function () {
			syncFrom('preview');
		});

		// A resize rewraps both panes, so every measurement in the table is
		// stale, and so is the place the preview is sitting at.
		window.addEventListener('resize', debounce(remeasure, RESIZE_DEBOUNCE_MS));

		// Both toggles live on the container: the view button swaps its classes
		// and the sync button sets its attribute. Either can turn syncing back
		// on, and both leave the anchors stale when they do, the view because
		// a hidden pane had no geometry to measure and the toggle because the
		// document may have been edited while it was off.
		//
		// The drag target outline is a class on this same element, so the state
		// is compared rather than the attributes: otherwise every dragenter and
		// dragleave over the editor would remeasure both panes.
		if (window.MutationObserver) {
			var wasActive = active();

			new window.MutationObserver(function () {
				var isActive = active();

				if (isActive === wasActive) {
					return;
				}

				wasActive = isActive;

				if (!isActive) {
					return;
				}

				remeasure();
			}).observe(container, {
				attributes: true,
				attributeFilter: ['class', 'data-ozhmd-sync']
			});
		}

		return { rebuild: rebuild, remeasure: remeasure };
	}

	/**
	 * The browser's storage, or nothing when it is not to be had.
	 *
	 * Reading window.localStorage is itself what throws when the browser has
	 * been told to refuse storage to the site, before any key is asked for.
	 *
	 * @return {object|null} Storage object, or null.
	 */
	function localStorageOrNull() {
		try {
			return window.localStorage;
		} catch (e) {
			return null;
		}
	}

	/**
	 * Wire a handle so that pressing and dragging it reports the pointer.
	 *
	 * Pointer events rather than mouse events, so a mouse, a pen and a finger
	 * are one path, and the capture is what keeps a drag alive once the pointer
	 * has left the few pixels of the handle it started on.
	 *
	 * @param {HTMLElement} handle Element to drag.
	 * @param {HTMLElement} container Editor container, marked while dragging.
	 * @param {Function} onMove Given each pointer event of the drag.
	 * @param {Function} onEnd Called once the drag is over.
	 * @return {void}
	 */
	function createDrag(handle, container, onMove, onEnd) {
		var dragging = false;

		handle.addEventListener('pointerdown', function (event) {
			dragging = true;
			handle.setPointerCapture(event.pointerId);
			container.classList.add('ozhmd-resizing');

			// Otherwise the pointer starts selecting the text it is dragged
			// across on its way over a pane.
			event.preventDefault();
		});

		handle.addEventListener('pointermove', function (event) {
			if (dragging) {
				onMove(event);
			}
		});

		/**
		 * End a drag, however it ended.
		 *
		 * @param {PointerEvent} event The event that ended it.
		 * @return {void}
		 */
		function release(event) {
			if (!dragging) {
				return;
			}

			dragging = false;
			container.classList.remove('ozhmd-resizing');

			if (handle.hasPointerCapture(event.pointerId)) {
				handle.releasePointerCapture(event.pointerId);
			}

			onEnd();
		}

		handle.addEventListener('pointerup', release);
		handle.addEventListener('pointercancel', release);
	}

	/**
	 * Wire a pair of arrow keys to step the handle they are pressed on.
	 *
	 * A control that only answers to a pointer cannot be used at all by anyone
	 * working from the keyboard, and this one is in the middle of the editor.
	 *
	 * @param {HTMLElement} handle Element to listen on.
	 * @param {string} back Key that makes it smaller.
	 * @param {string} forward Key that makes it larger.
	 * @param {number} step How far one press moves it.
	 * @param {Function} onStep Given the signed step.
	 * @return {void}
	 */
	function createArrowKeys(handle, back, forward, step, onStep) {
		handle.addEventListener('keydown', function (event) {
			var delta = 0;

			if (back === event.key) {
				delta = -step;
			} else if (forward === event.key) {
				delta = step;
			} else {
				return;
			}

			// The arrow keys scroll the page by default, and the handle would
			// slide out from under the focus as it went.
			event.preventDefault();

			onStep(delta);
		});
	}

	/**
	 * Let the handle under the editor drag it taller or shorter.
	 *
	 * The height is written as a custom property on the container rather than
	 * as an inline height, which is what lets the stylesheet take it back on a
	 * narrow screen, where the panes stack and one shared height would halve
	 * into two cramped rows. The bounds and the storage are in
	 * ozhmd-resize-lib.js; what is here is the pointer and the keyboard.
	 *
	 * @param {HTMLElement} container Editor container, sized by the property.
	 * @param {HTMLElement} handle The resize bar.
	 * @param {Function} onLive Called on every step of a drag.
	 * @param {Function} onSettled Called once a resize is over.
	 * @return {void}
	 */
	function createResizer(container, handle, onLive, onSettled) {
		var lib = window.ozhmdResizeLib;

		// No handle to grab, or no bounds to respect. Either way the editor
		// keeps the height the stylesheet gives it, and the handle stays
		// hidden rather than sitting there doing nothing.
		if (!lib || !handle) {
			return;
		}

		var storage = localStorageOrNull();
		var stored = lib.readHeight(storage, window.innerHeight);

		/**
		 * Give the editor a height.
		 *
		 * @param {number} height Height in pixels, already clamped.
		 * @return {void}
		 */
		function apply(height) {
			container.style.setProperty('--ozhmd-editor-height', height + 'px');
		}

		/**
		 * Height the editor ended up with.
		 *
		 * Measured rather than remembered, so the stylesheet's own floor is
		 * what gets stored when a drag runs into it.
		 *
		 * @return {number} Height in pixels.
		 */
		function current() {
			return container.getBoundingClientRect().height;
		}

		/**
		 * Remember the height and let the editor settle into it.
		 *
		 * @return {void}
		 */
		function settle() {
			lib.writeHeight(storage, current());
			onSettled();
		}

		if (null !== stored) {
			apply(stored);
		}

		createDrag(handle, container, function (event) {
			var top = container.getBoundingClientRect().top;

			apply(lib.clamp(event.clientY - top, window.innerHeight));
			onLive();
		}, settle);

		createArrowKeys(handle, 'ArrowUp', 'ArrowDown', RESIZE_STEP_PX, function (delta) {
			apply(lib.clamp(current() + delta, window.innerHeight));
			settle();
		});

		// Only now: until this point there was nothing behind it.
		handle.hidden = false;
	}

	/**
	 * Let the divider between the panes drag them wider or narrower.
	 *
	 * Only the source pane's share is written; the preview takes what is left,
	 * so the two always add up to the row. Nothing is stored: the split is
	 * scratch, reset to even panes on every load, and the share held here is
	 * the same value the stylesheet starts from.
	 *
	 * @param {HTMLElement} container Editor container, the row being divided.
	 * @param {HTMLElement} splitter The divider between the panes.
	 * @param {Function} onLive Called on every step of a drag.
	 * @param {Function} onSettled Called once a resize is over.
	 * @return {void}
	 */
	function createSplitter(container, splitter, onLive, onSettled) {
		var lib = window.ozhmdResizeLib;

		if (!lib || !splitter) {
			return;
		}

		var share = lib.DEFAULT_SPLIT;

		/**
		 * Give the source pane a share of the row.
		 *
		 * @param {number} percent Share, already bounded.
		 * @return {void}
		 */
		function apply(percent) {
			share = percent;
			container.style.setProperty('--ozhmd-source-width', percent + '%');
		}

		createDrag(splitter, container, function (event) {
			var row = container.getBoundingClientRect();

			apply(lib.splitAt(event.clientX, row.left, row.width));
			onLive();
		}, onSettled);

		createArrowKeys(splitter, 'ArrowLeft', 'ArrowRight', SPLIT_STEP_PERCENT, function (delta) {
			apply(lib.clampSplit(share + delta));
			onSettled();
		});

		splitter.hidden = false;
	}

	/**
	 * Remeasure once any images in the preview have loaded.
	 *
	 * An image takes up no height until it arrives, so every block below it is
	 * recorded in the wrong place if the table is built before then.
	 *
	 * @param {HTMLElement} preview Preview container.
	 * @param {Function} rebuild Callback that remeasures both panes.
	 * @return {void}
	 */
	function rebuildAfterImages(preview, rebuild) {
		Array.prototype.forEach.call(preview.querySelectorAll('img'), function (image) {
			if (image.complete) {
				return;
			}

			image.addEventListener('load', rebuild, { once: true });
			image.addEventListener('error', rebuild, { once: true });
		});
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
		var handle = document.querySelector('[data-ozhmd-resize]');
		var splitter = document.querySelector('[data-ozhmd-split]');

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
			viewportMargin: Infinity,

			// CodeMirror otherwise types into a hidden 1px textarea and paints
			// the text as inert divs, which no browser spellchecks. Making the
			// visible text the input field is what gives us the red squiggles
			// and the "Spelling" entry in the right click menu.
			inputStyle: 'contenteditable',
			spellcheck: true,

			// Hands every dropped file to upload.js instead of CodeMirror.
			// CodeMirror's own onDrop reads each file with FileReader and
			// inserts the text at the drop position, with no size limit and no
			// way for us to veto it: its listener is on the editor element,
			// inside [data-ozhmd-editor], so it runs before the uploader's and
			// preventDefault() there comes too late. A big file was being
			// inserted anyway, right after the uploader had refused it.
			//
			// An empty array is truthy and matches no MIME type, so
			// CodeMirror skips every file before reading it.
			allowDropFileTypes: []
		});

		// Published so upload.js can insert at the cursor through the same
		// instance, keeping undo history and cursor position intact.
		window.ozhmdCodeMirror = editor;

		var sync = createScrollSync(editor, preview, container);

		/**
		 * Repaint the source pane at its new size, mid drag.
		 *
		 * CodeMirror paints what fits in its own box and measures nothing
		 * outside it, so it has to be told when that box changes. This runs on
		 * every pointer move, so it does no more than that.
		 *
		 * @return {void}
		 */
		function resized() {
			editor.refresh();
		}

		/**
		 * Put the panes back together once a resize is over.
		 *
		 * Every anchor tying the two panes together is a measurement taken at
		 * the old size, and a narrower pane wraps its lines differently, so the
		 * table is rebuilt rather than adjusted. It lexes the document, which
		 * is why it waits for the drag to finish.
		 *
		 * @return {void}
		 */
		function settled() {
			editor.refresh();
			sync.remeasure();
		}

		createResizer(container, handle, resized, settled);
		createSplitter(container, splitter, resized, settled);

		/**
		 * Render the preview and remeasure what the panes are tied together by.
		 *
		 * Rebuilding has to follow the render: every anchor is a position in
		 * the HTML that has just been replaced.
		 *
		 * @return {void}
		 */
		function refresh() {
			renderPreview(editor.getValue(), preview);
			sync.rebuild();
			rebuildAfterImages(preview, sync.rebuild);

			// Anything that reads the rendered preview, the stats line being
			// the one thing that does, hangs off this rather than off the
			// editor's own changes. There is then one debounce rather than two
			// racing, and nothing downstream can describe a preview that has
			// not been rendered yet.
			container.dispatchEvent(new window.CustomEvent('ozhmd:preview'));
		}

		var update = debounce(refresh, DEBOUNCE_MS);

		editor.on('change', function () {
			// Keep the underlying textarea in sync so a submit at any moment
			// posts the current text, not the text as of the last blur.
			editor.save();
			update();
		});

		refresh();
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
