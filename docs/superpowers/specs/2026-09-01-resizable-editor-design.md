# A Resizable Editor: Design

Date: 2026-09-01
Status: approved, implementing directly (no separate plan document)

## 1. Overview

The editor stops sizing itself to its content and takes a height of its own: `60vh` by
default, dragged to anything the author likes with a handle along its bottom edge, and
remembered in `localStorage` for the next post. A second handle, the divider between the
two panes, drags them wider and narrower against each other. That one is deliberately
not remembered: it goes back to even panes on every load. Section 8 covers it.

Today the two panes are sized independently. CodeMirror grows with the text between a
`400px` floor and an `80vh` ceiling, and the preview grows with the rendered HTML under a
ceiling of `80vh`. Two consequences follow. A long post fills four fifths of the window,
leaving the publish controls and the rest of the edit screen off screen. A short one
leaves the two columns at visibly different heights, because only one of them has a floor.

One height for the container settles both. The panes become equal by construction, the
editor leaves room for the screen it sits on, and the author decides how much room that
is.

## 2. Decisions taken during brainstorming

| # | Question | Decision |
|---|---|---|
| 1 | What does the handle resize | The whole editor. One handle across the bottom of the container, moving both panes together, so they stay level and the scroll sync keeps comparing like with like. |
| 2 | Fixed height or a ceiling | Fixed. The editor is exactly the set height, so a short post shows empty space below the text and a drag always has a visible effect. A ceiling would leave the handle inert until the content outgrew it. |
| 3 | Is the height remembered | Yes, in `localStorage`, one key, per browser. No option row and no REST call, which is the shape the plugin settled on when the settings page was removed. |
| 4 | Narrow screens | No resizing below `850px`. The panes stack there, and one shared height would halve into two cramped rows. See section 3.2. |
| 5 | Keyboard | Arrow keys move the handle. A drag-only control is unreachable without a pointer, and the WordPress admin is not the place to ship that. |

## 3. Layout

### 3.1 The container owns the height

```css
.ozhmd-editor {
	height: var(--ozhmd-editor-height, 60vh);
	min-height: 200px;
}
```

The height travels as a custom property, set inline on the container by JavaScript, and
never as an inline `height`. That distinction is what keeps the file's "no `!important`
anywhere" rule intact: the narrow-screen rule in section 3.2 has to beat the applied
height, and a normal rule beats a normal rule, while nothing but `!important` beats an
inline declaration. The default lives in the `var()` fallback, so a browser with no stored
value and a browser with no JavaScript at all both get `60vh` from the stylesheet alone.

The `min-height` is a floor for the short-window case: `60vh` of a `700px` laptop window
is `420px`, but `60vh` of a phone-sized viewport is not an editor. It matches the drag
floor in section 4.

Both panes then fill that height rather than setting their own:

- `.ozhmd-preview` loses `max-height: 80vh` and becomes a flex item of its pane,
  `flex: 1` with `min-width: 0` and `min-height: 0`, keeping `overflow-y: auto`. Filling
  the pane that way needs no height named on it, and the two lifted floors are what make
  a long line or a tall document scroll inside the box rather than push it open. It is
  already the scroll box the sync code drives.
- `.ozhmd-editor .CodeMirror` loses `height: auto` and `min-height: 400px` for
  `height: 100%`, and `.ozhmd-editor .CodeMirror-scroll` loses both of its heights for
  `min-height: 0; max-height: none`. CodeMirror sizes from its own box, so a definite
  height on the element is what makes it fill the pane and scroll inside it.

`.ozhmd-pane-source` is already `display: flex`; its CodeMirror child takes `flex: 1` and
`min-width: 0` so it fills the pane's width as well as its height.

### 3.2 Below 850px

The existing breakpoint stacks the panes into a column. A single height on the container
would be split between them, so `60vh` of editor would be two panes of `30vh`. The rules
under that media query therefore restore today's behaviour:

```css
@media screen and (max-width: 850px) {
	.ozhmd-editor {
		flex-direction: column;
		height: auto;
	}

	.ozhmd-editor .CodeMirror {
		height: auto;
	}

	.ozhmd-editor .CodeMirror-scroll {
		min-height: 400px;
		max-height: 80vh;
	}

	.ozhmd-preview {
		max-height: 40vh;
	}

	.ozhmd-resize,
	.ozhmd-split {
		display: none;
	}
}
```

The block sits last in the stylesheet rather than beside the layout it undoes. Every rule
in it is one class deep, as are the rules it has to beat, so source order is what decides
between them.

`height: auto` here overrides the custom property's use, not the property itself: the
value stays set on the element, so widening the window past the breakpoint restores the
dragged height without JavaScript doing anything.

## 4. The handle

`Ozhmd_Editor_UI::render()` emits it immediately after the container, as its sibling:

```php
printf(
	'<div class="ozhmd-resize" data-ozhmd-resize role="separator" aria-orientation="horizontal" tabindex="0" aria-label="%s" hidden></div>',
	esc_attr__('Drag to resize the editor', 'ozh-markdown')
);
```

Rendered in PHP so the label is translatable alongside every other string in the class,
and so `EditorUiTest` can assert it the way it asserts the toolbar. Shipped `hidden`, with
the attribute removed by `editor.js` once it has a CodeMirror instance: a browser with
JavaScript disabled falls through to the plain textarea and must not be offered a control
that cannot work. The same reasoning already hides the preview pane in
`.ozhmd-editor-plain`.

A `role="separator"` with `tabindex="0"` is the ARIA pattern for a pane splitter, and the
one screen reader users will have met elsewhere. It is a `div` rather than a `button`
because it is not a thing to press.

Sibling rather than child, because `.ozhmd-editor` is the flex row that lays out the two
panes: a full-width child would become a third column. Wrapping the panes in an inner row
so the handle could live inside the frame was the alternative, and it buys one rounded
corner at the price of restructuring markup that the view switcher, the drop target and
the scroll sync all read. The handle sits under the editor instead, which is where a
textarea's own grip sits.

It is full width, around `10px` tall, `cursor: ns-resize`, in the same `#dcdcde` as the
pane divider, with a short grip drawn in the middle and a darker shade on hover and on
focus.

### 4.1 Dragging

Pointer events, not mouse events: `pointerdown` on the handle calls
`setPointerCapture()`, and mouse, pen and touch then run the same path, with the capture
keeping the drag alive when the pointer leaves the handle. `pointermove` computes

```
height = event.clientY - container.getBoundingClientRect().top
```

clamped by the library, and writes the custom property. `pointerup` and `pointercancel`
release the capture and finish the drag.

The clamp is `200px` at the bottom and the viewport height at the top. The floor matches
the container's `min-height`, so the CSS and the drag agree rather than one silently
correcting the other. The ceiling stops a drag that runs past the bottom of the window,
where the page scrolls under the pointer and the editor would otherwise grow without
limit.

`user-select: none` goes on the container for the duration of the drag, via a class, so
sweeping the pointer over the source pane does not select its text.

### 4.2 Keyboard

With the handle focused, `ArrowUp` and `ArrowDown` move the height by `32px` and
`preventDefault()` so the page does not scroll instead. Each press is a complete
adjustment: it stores and settles the same way a released drag does.

### 4.3 After a resize

Two things are recomputed when a drag ends or a key press lands:

- `editor.refresh()`, because CodeMirror measures its viewport from its own box and will
  otherwise leave the new space unpainted.
- `sync.remeasure()`, a rebuild followed by a resync, because the scroll sync's bottom anchor comes
  from `clientHeight` on both sides. `createScrollSync()` already returns a `rebuild`
  handle and already does exactly this pair on a window resize; the resizer reaches the
  same work through a callback rather than growing its own copy.

Storing the height also happens here rather than on every `pointermove`, so a drag writes
to `localStorage` once.

## 5. Code shape

The split the plugin already uses is kept: pure logic in a `lib` file with tests, DOM and
event wiring in the top-level file.

`assets/js/lib/ozhmd-resize-lib.js` exposes `window.ozhmdResizeLib`:

- `clamp(height, viewport)` returns the height held between `200px` and the viewport
  height. It is the single definition of the bounds, used by the drag, by the keyboard and
  by the load path.
- `readHeight(storage, viewport)` reads the stored value, parses it, rejects anything that
  is not a positive finite number, clamps what survives against the viewport it is being
  restored into, and returns `null` when there is nothing usable. Re-clamping on read is
  what stops a height saved on a large monitor from overflowing a small laptop window.
- `writeHeight(storage, height)` stores it.

Both storage functions take the storage object as an argument rather than reaching for
`window.localStorage` themselves, which is what makes them testable, and both survive a
throw: Safari in private browsing throws on `setItem`, and an admin screen is not the
place to take an editor down over a preference.

`createResizer(editor, container, handle, onResize)` in `editor.js` sits beside
`createScrollSync()` and does the wiring: restore on load, the three pointer handlers, the
key handler, and the callback into `refresh` and `rebuild`. It is given the CodeMirror
instance and the elements rather than finding them, matching how the sync is constructed
in `init()`.

The lib is enqueued in `Ozhmd_Editor_UI::enqueue_assets()` next to `ozhmd-scroll-lib`, and
added to the `ozhmd-editor` dependency array.

## 6. Tests

`tests/js/resize-lib.test.js`, in the style of `scroll-lib.test.js`:

- `clamp()` holds the floor, holds the ceiling, and passes a value between them through.
- `readHeight()` returns `null` for absent, empty, non-numeric, zero and negative values,
  returns the stored number when it is usable, and re-clamps a stored height taller than
  the viewport it is read into.
- `readHeight()` and `writeHeight()` return rather than throw when the storage object
  throws, which is the private-browsing case.

`tests/integration/EditorUiTest.php` gains an assertion that `render()` emits the handle,
hidden, with its data attribute and its separator role.

The pointer wiring itself gets no test. It is a handful of listeners over a synthetic
event object, and the repository treats wiring the same way for the scroll sync and the
toolbar: the arithmetic is tested, the listeners are kept small enough to read.

Verification is all three suites, unit, integration and the node JS tests, plus loading a
post edit screen to drag the handle, resize the window across the `850px` breakpoint, and
reload to confirm the height comes back.

## 7. Documentation

`specs.md` describes the editor as a split pane with a live preview and says nothing about
how either pane is sized, so it needs no change. This document is the record of the height
model.

## 8. The splitter

Added after the height was built, and shaped to match it.

The divider between the panes becomes a real column of the flex row, written in the markup
between the two panes because that is where the row lays it out. Like the resize handle it
ships `hidden`, is a `role="separator"`, this time `aria-orientation="vertical"`, and is
unhidden by `editor.js`.

It is drawn as a plain 3px line in `#dcdcde`, the grey every other border in the file
uses, with no grip and no hover state: `cursor: ew-resize` is what says it can be dragged,
and anything more was decided against on sight. The row's `gap: 1px` goes with it, so the
divider is the only thing between the panes and its three pixels are the whole of what
shows; the stacked layout below `850px`, which has no divider, takes the gap back. The
outline is on `:focus-visible` rather than `:focus`, so a click drag does not leave a mark
on the line while a keyboard user still sees where they are.

Three pixels is a thin target for a pointer. That is the width asked for, and the cursor
changing across it is what makes it findable.

The source pane takes `flex: 0 0 var(--ozhmd-source-width, 50%)` and the preview takes
what is left with `flex: 1 1 auto`. Driving one side only is what guarantees the two
always add up to the row. Three states override it: showing a single pane hands that pane
`flex: 1 1 auto`, since a fixed basis with nothing to share the row with would leave the
rest of it empty; the stacked layout below `850px` returns the source pane to `flex: 0 0
auto` and hides the divider; and the no-JavaScript fallback hides it too.

Nothing is stored. The share lives in a closure, initialised to the same 50 the stylesheet
falls back to, moved by the drag and by the left and right arrow keys in steps of five
points, and clamped so neither pane falls under 15% of the row. `splitAt()` and
`clampSplit()` join the lib and are tested there, including the case where the row
measures zero wide and a share of it is not a number.

Both drags share their wiring: `createDrag()` holds the pointer capture, the dragging
flag and the `ozhmd-resizing` class, and `createArrowKeys()` holds the key matching and
the `preventDefault()`. `createResizer()` and `createSplitter()` are then each about
thirty lines of what is actually different between them.

Resizing a pane rewraps its lines, so the scroll anchors are as stale after a horizontal
drag as after a vertical one. Both call the same pair of callbacks: `editor.refresh()` on
every pointer move, which is cheap, and `refresh()` plus `sync.remeasure()` once the drag
ends, which lexes the document and is not.
