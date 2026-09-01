/**
 * Tests for the toolbar's two stateful buttons.
 *
 * The formatting actions are pure transforms, tested in toolbar-lib.test.js.
 * The view and sync buttons change no text at all: they write state onto the
 * editor container, which editor.js then reads. That handover is what is
 * checked here, in both directions, because the sync toggle reads a state and
 * writes its opposite and there is nothing about that a reader can verify by
 * eye.
 *
 * toolbar.js runs inside an IIFE, so it is booted over a fake DOM and its
 * buttons are clicked.
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.join(__dirname, '..', '..');
const TOOLBAR = path.join(ROOT, 'assets', 'js', 'toolbar.js');
const TOOLBAR_LIB = path.join(ROOT, 'assets', 'js', 'lib', 'ozhmd-toolbar-lib.js');

/**
 * Boot toolbar.js over a fake DOM and hand back a way to press its buttons.
 *
 * @return {object} The container, and a click function taking an action name.
 */
function boot() {
	const classes = new Set();
	const attributes = { 'data-ozhmd-sync': 'on' };

	const container = {
		classList: {
			add: (name) => classes.add(name),
			remove: (...names) => names.forEach((name) => classes.delete(name)),
			contains: (name) => classes.has(name)
		},
		getAttribute: (name) => (name in attributes ? attributes[name] : null),
		setAttribute: (name, value) => {
			attributes[name] = value;
		}
	};

	// One fake button per action, each remembering what was set on it.
	const buttons = {};

	/**
	 * The button for an action, created on first use.
	 *
	 * @param {string} action Action name.
	 * @return {object} Fake button element.
	 */
	function button(action) {
		if (!buttons[action]) {
			const own = { 'data-ozhmd-action': action };

			// Only the sync button carries a pair of glyphs, mirroring what
			// render_toolbar() emits for a button with an icon_off.
			if ('sync' === action) {
				own['data-ozhmd-icon-on'] = 'lock';
				own['data-ozhmd-icon-off'] = 'unlock';
			}

			const glyphClasses = new Set(['dashicons', 'dashicons-lock']);

			buttons[action] = {
				glyphClasses,
				getAttribute: (name) => (name in own ? own[name] : null),
				setAttribute: (name, value) => {
					own[name] = value;
				},
				closest: () => buttons[action],
				querySelector: (selector) => ('.dashicons' === selector
					? {
						classList: {
							add: (...names) => names.forEach((n) => glyphClasses.add(n)),
							remove: (...names) => names.forEach((n) => glyphClasses.delete(n))
						}
					}
					: null)
			};
		}

		return buttons[action];
	}

	let onClick = () => {};

	const toolbar = {
		addEventListener: (name, fn) => {
			if ('click' === name) {
				onClick = fn;
			}
		}
	};

	const window = {
		ozhmdToolbarLib: require(TOOLBAR_LIB),
		document: null
	};

	const document = {
		readyState: 'complete',
		querySelector: (selector) => {
			if ('[data-ozhmd-toolbar]' === selector) {
				return toolbar;
			}

			if ('[data-ozhmd-editor]' === selector) {
				return container;
			}

			return null;
		},
		getElementById: () => null,
		addEventListener: () => {}
	};

	window.window = window;
	window.document = document;

	vm.runInNewContext(fs.readFileSync(TOOLBAR, 'utf8'), window, { filename: TOOLBAR });

	return {
		container,
		button,
		click: (action) => onClick({
			target: button(action),
			preventDefault: () => {}
		})
	};
}

test('the sync button turns syncing off, then on again', () => {
	const { container, click } = boot();

	assert.strictEqual(container.getAttribute('data-ozhmd-sync'), 'on', 'starts tied together');

	click('sync');
	assert.strictEqual(container.getAttribute('data-ozhmd-sync'), 'off');

	click('sync');
	assert.strictEqual(container.getAttribute('data-ozhmd-sync'), 'on');
});

test('the sync button reports its state to assistive technology', () => {
	const { button, click } = boot();

	click('sync');
	assert.strictEqual(button('sync').getAttribute('aria-pressed'), 'false');

	click('sync');
	assert.strictEqual(button('sync').getAttribute('aria-pressed'), 'true');
});

test('the sync button swaps the padlock open and shut', () => {
	const { button, click } = boot();

	assert.ok(button('sync').glyphClasses.has('dashicons-lock'), 'starts locked');

	click('sync');
	assert.ok(button('sync').glyphClasses.has('dashicons-unlock'), 'now open');
	assert.ok(!button('sync').glyphClasses.has('dashicons-lock'), 'and only open');

	click('sync');
	assert.ok(button('sync').glyphClasses.has('dashicons-lock'), 'shut again');
	assert.ok(!button('sync').glyphClasses.has('dashicons-unlock'), 'and only shut');
});

test('the swap leaves the dashicons base class in place', () => {
	const { button, click } = boot();

	click('sync');

	// Removing this would drop the icon font and leave an empty square.
	assert.ok(button('sync').glyphClasses.has('dashicons'));
});

test('a button with no icon pair is left alone by the swap', () => {
	const { button, click } = boot();

	// The view button has no data-ozhmd-icon-on, so nothing should be swapped
	// onto it and nothing removed from it.
	const before = new Set(button('view').glyphClasses);

	click('view');

	assert.deepStrictEqual(new Set(button('view').glyphClasses), before);
});

test('the sync button leaves the view alone', () => {
	const { container, click } = boot();

	click('sync');

	assert.ok(!container.classList.contains('ozhmd-view-source'));
	assert.ok(!container.classList.contains('ozhmd-view-preview'));
});

test('the view button cycles the panes without touching the sync state', () => {
	const { container, click } = boot();

	click('view');
	assert.ok(container.classList.contains('ozhmd-view-source'), 'source only');

	click('view');
	assert.ok(container.classList.contains('ozhmd-view-preview'), 'preview only');

	click('view');
	assert.ok(!container.classList.contains('ozhmd-view-source'), 'back to both');
	assert.ok(!container.classList.contains('ozhmd-view-preview'), 'back to both');

	assert.strictEqual(container.getAttribute('data-ozhmd-sync'), 'on', 'sync untouched');
});
