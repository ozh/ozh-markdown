# Live Document Stats Under the Editor: Design

Date: 2026-09-01
Status: approved, implemented directly (no separate plan document)
Extends: `2026-08-22-ozh-markdown-design.md`, section on post editing

## 1. Overview

A quiet line of text under the editor, refreshed as you type, reading:

> 1,284 words · 17 words per sentence (longest: 98 words) · 3 links · 2 code blocks · 6 min read

All of it about the post a reader will get rather than the Markdown you are typing: syntax,
link targets and code blocks count for nothing in the prose. Reading time is words divided
by 220 per minute.

The links and the code blocks are left out when there are none, so a post of pure prose
reads as three segments rather than five, two of them reporting what it does not contain.

The counting and the wording are pure functions in a new lib, tested in Node. The only
browser-dependent part is pulling the prose out of the preview pane, which is a handful of
lines in a new `stats.js`. The server contributes an empty element and the translatable
strings.

## 2. Decisions taken during brainstorming

| # | Question | Decision |
|---|---|---|
| 1 | What text is measured | The rendered prose, not the Markdown source. `**bold**` is one word, a fenced block is nothing, a link's URL is nothing. Reading time then means what it says. |
| 2 | Number of lines | Dropped. It was in the original ask, and it does not belong beside three stats about the rendered post. |
| 3 | Where the prose comes from | The preview DOM, which `marked` has already rendered. Not a second Markdown stripper of our own: it would be a worse parser standing next to a good one, and it would disagree with the preview the user is looking at. |
| 4 | Wording | One line, dot separated, in small grey text. Not a labelled grid. `words per sentence` rather than `avg sentence`, because it says what the average is an average of. |
| 5 | Where it sits | Below the resize grip, so the grip stays touching the panes it resizes. |
| 6 | Reading speed | 220 words per minute, as a constant in the lib. No setting: the settings page was removed in `2026-08-31-remove-settings-page-design.md` and nothing here justifies bringing one back. |
| 7 | Longest sentence, added after the first round | The average hides the one sentence worth rewriting: a post can average 17 words and still hold a 60-word monster. It is the only stat here anyone acts on immediately, and it falls out of counting each sentence separately. |
| 8 | Links and code blocks, added after the first round | Both are free from the preview, which is already in hand, and both catch a real mistake: a post that references no sources when you thought it referenced three. Code blocks are worth naming on a blog that strips them from the prose count in the first place. |
| 9 | What was rejected | Readability scores (Flesch and the like) need syllable counts, and syllable counting is a vowel-group heuristic that is wrong on exactly the words this blog uses most: names, technical terms, acronyms. The result would be an authoritative-looking number built on a bad estimate, tuned for English while the rest of the strings go through `__()`, saying nothing about what to change. Longest sentence is the same signal, honestly measured. Passive-voice and adverb counts are a linter with opinions, not a stat. Characters and paragraphs change no decision. |
| 10 | How many segments | Five is the ceiling, and only reached by a post with both links and code in it; a post of pure prose is three. Past that the line stops being glanceable and becomes a dashboard, which is ignored. The longest sentence is folded into the average as a parenthetical rather than taking a segment of its own, since it qualifies that number rather than standing alongside it. |

## 3. `assets/js/lib/ozhmd-stats-lib.js`

UMD, like the other three libs: `window.ozhmdStatsLib` in the browser, CommonJS under
`node --test`. No DOM, no storage, no state. It is handed a string of prose and gives back
numbers, then turns numbers into a line of text.

The split between the two functions is arithmetic versus presentation. `count()` never
rounds and never picks a word; `format()` never counts.

### 3.1 `WORDS_PER_MINUTE`

`220`, exported so the tests assert against the constant rather than restating it.

### 3.2 `count(text)`

Returns `{ words, sentences, wordsPerSentence, longestSentence, minutes }`.

Every sentence is counted on its own and the totals fall out of that, which is what makes
the longest one knowable. This is equivalent to counting the whole text at once, because a
terminator only ends a sentence when whitespace or the end of the text follows it, so
splitting can never cut a word in half.

**Words.** The text is split on runs of whitespace, and a token counts as a word when it
contains at least one letter or digit, tested with `/[\p{L}\p{N}]/u`. So `well-known` is
one word, `it's` is one word, and a stray `·` or `—` between two words is not a word at
all. Non-Latin scripts count as words on the same rule, which is right for Greek or
Cyrillic and wrong for Chinese, where there are no spaces; that is a limitation, not a bug
to work around here.

**Sentences.** The text is split on one or more of `.` `!` `?` `…` followed by whitespace
or the end of the string, and the sentences are the segments that still contain a word.
So `Hi!! Bye.` is two, not three, and text with no terminator at all is one sentence rather
than none. `e.g.` and `Dr. Smith` will over-count, because no rule that fits in a
tokeniser gets those right; a heuristic that guessed would be wrong in less predictable
places.

**`wordsPerSentence`.** `words / sentences`, unrounded. `0` when there are no words, so
nothing ever divides by zero.

**`longestSentence`.** Words in the longest of them, `0` when there are none.

**`minutes`.** `words / WORDS_PER_MINUTE`, unrounded. A float on purpose: the difference
between "half a minute" and "no minutes" is the formatter's to make, and it cannot make it
from a number that has already been rounded to `0`.

### 3.3 `format(stats, i18n)`

Returns the line to display. Given the counts from `count()`, optionally carrying `links`
and `codeBlocks` merged in by the caller, and a bag of templates from the server, each
holding one `%s`.

- Words are grouped for readability with `toLocaleString()`.
- `wordsPerSentence` is rounded to the nearest whole number. `longestSentence` is already
  whole.
- `minutes` is rounded to the nearest whole number, except that a document with words in
  it but under half a minute of them reads `< 1 min read` rather than `0 min read`.
- `links` and `codeBlocks` are omitted entirely at zero, and absent keys count as zero.
- Everything derived from the prose (the average, the longest, the reading time) is omitted
  when there are no words. What the post *contains* is not: a post that is one code block
  and nothing else reads `0 words · 1 code block`, which is true and useful, rather than
  `0 words` alone or five zeroes.
- Singular templates are used at exactly one, so it reads `1 word`, `1 word per sentence
  (longest: 1 word)`, `1 link`, `1 code block`. There is no singular for the reading time:
  `min` does not inflect.

Segment order is prose, then contents, then reading time, which reads as a summary and so
goes last.

`i18n` keys: `words`, `wordsOne`, `perSentence`, `perSentenceOne`, `longest`, `longestOne`,
`links`, `linksOne`, `codeBlocks`, `codeBlocksOne`, `minutes`, `minutesUnder`, `separator`.
A missing bag falls back to untranslated English rather than rendering `undefined`, because
a stats line is not worth an exception in the editor.

## 4. `assets/js/stats.js`

The wiring, in the shape of `toolbar.js` and `upload.js`: find the elements, bail quietly
if they or the lib are absent, listen, write.

**Getting the prose.** The preview element is cloned, every `<pre>` in the clone is
removed, and `textContent` is read off what is left. Cloning is what keeps the visible
preview untouched. Removing `<pre>` drops fenced and indented code, which is not prose and
would inflate every one of the three numbers. Inline `` `code` `` stays, because it reads
as part of the sentence around it. Images contribute nothing already, having no text.

Every `<br>` in the clone is then replaced by a newline. `marked` writes a newline between
block elements, so paragraphs and headings come apart on their own, but a hard line break
does not: `<p>One<br>Two</p>` reads back as `OneTwo`, one word where the author wrote two.

**The links and the code blocks** are counted off the live preview with `querySelectorAll`,
not off the clone: neither survives the trip into prose, since the blocks are removed on the
way and an anchor keeps its text but loses its href. They are merged onto the object
`count()` returned and handed to `format()` together.

`stats.js` also counts once at the end of its own `init()`. The editor renders from a
`DOMContentLoaded` handler registered before this one, so the first `ozhmd:preview` is
dispatched before there is anything listening for it; without the extra call the line would
sit empty until the first keystroke.

**When it runs.** `editor.js` renders the preview inside a private `refresh()`. It gains
one line at the end of that function: dispatch a `ozhmd:preview` `CustomEvent` on the
editor container. `stats.js` listens for it on the container.

This is the whole of the interface between the two. It reuses the render's existing 300 ms
debounce, so the stats can never describe a preview that has not been rendered yet, and it
leaves `stats.js` knowing nothing about CodeMirror. The alternatives were both worse: a
`MutationObserver` on the preview would also fire for Prism's own rewriting of every code
block, and a second debounce hung off `editor.on('change')` would race the first and show
counts one keystroke stale whenever it won.

`upload.js` and `toolbar.js` insert text through CodeMirror, which fires `change`, which
runs `refresh()`, so they get correct stats without knowing this feature exists.

## 5. Markup, styling and strings

`Ozhmd_Editor_UI::render()` emits, after `render_resize_handle()`:

```html
<div class="ozhmd-stats" data-ozhmd-stats></div>
```

Empty from the server. With no JavaScript the plain textarea is what saves and there is
nothing to count, so `.ozhmd-stats:empty { display: none }` takes it out of the flow
entirely, the same way `.ozhmd-upload-errors` already handles having nothing to say.

Styled small and grey, left aligned under the panes, with enough top margin to sit clear of
the resize grip. Nothing shifts when the first word is typed, because an empty post already
reads `0 words`: the element is only ever empty for the instant between the editor drawing
and its first render.

No `aria-live`. The text changes on every keystroke, and a live region would read the whole
line out again each time, which would make the editor unusable with a screen reader. The
numbers are there to be read when wanted, not announced.

The strings are registered in `enqueue_assets()`:

```php
wp_localize_script('ozhmd-stats', 'ozhmdStats', ['i18n' => [
    'words'          => __('%s words', 'ozh-markdown'),
    'wordsOne'       => __('%s word', 'ozh-markdown'),
    'perSentence'    => __('%s words per sentence', 'ozh-markdown'),
    'perSentenceOne' => __('%s word per sentence', 'ozh-markdown'),
    'longest'        => __('(longest: %s words)', 'ozh-markdown'),
    'longestOne'     => __('(longest: %s word)', 'ozh-markdown'),
    'links'          => __('%s links', 'ozh-markdown'),
    'linksOne'       => __('%s link', 'ozh-markdown'),
    'codeBlocks'     => __('%s code blocks', 'ozh-markdown'),
    'codeBlocksOne'  => __('%s code block', 'ozh-markdown'),
    'minutes'        => __('%s min read', 'ozh-markdown'),
    'minutesUnder'   => __('< 1 min read', 'ozh-markdown'),
    'separator'      => __(' · ', 'ozh-markdown'),
]]);
```

Two scripts are enqueued alongside the existing ones: `ozhmd-stats-lib` with no
dependencies, and `ozhmd-stats` depending on `['ozhmd-stats-lib', 'ozhmd-editor']`, both
versioned through `ozhmd_asset_version()` like everything else.

`WORDS_PER_MINUTE` is not passed from PHP. It is a property of the counting, it lives in
the lib, and the tests assert against it there.

## 6. Tests

`tests/js/stats-lib.test.js`, in the style of the existing four: `node:test`, plain
`assert`, a sentence per case saying what is being pinned and why.

`count()`:

- plain prose, words and sentences both
- punctuation-only tokens are not words
- a hyphenated word and a contraction are one word each
- runs of spaces, tabs and newlines separate words the same way
- the empty string and a whitespace-only string give zero words
- a sentence terminator with no space after it, as in `3.5`, does not end a sentence
- `Hi!! Bye.` is two sentences, not three
- text with no terminator is one sentence
- an ellipsis ends a sentence
- `wordsPerSentence` and `minutes` are zero, not `NaN`, for an empty document
- `WORDS_PER_MINUTE` words is exactly one minute
- the longest sentence is found whether it falls first or last, and is zero for an empty
  document

`format()`:

- the full line, five segments with the longest riding on the average
- links and code blocks are absent from the line at zero
- `1 word`, `1 word per sentence`, `(longest: 1 word)`, `1 link` and `1 code block` all
  take the singular
- a document under half a minute reads `< 1 min read`
- zero words is `0 words` alone, with no separator in it
- zero words with a code block still reports the block
- a large count is grouped
- a missing `i18n` bag still produces a readable line

The templates the tests use are markers (`(LONGEST %s)`, `%s LINK`) rather than the real
English strings, because several of the real ones read alike and a test asserting on those
would pass just as well if `format()` reached for the wrong one of a pair.

One test goes in `tests/js/editor-preview.test.js`, whose harness already boots `editor.js`
over a stub DOM: that a render dispatches `ozhmd:preview` on the container. It belongs on
that side of the seam, because a listener that is never called is the failure mode and only
the dispatching side can prove it does not happen. The three existing `editor.js` harnesses
each gain a `dispatchEvent` stub and a `CustomEvent` constructor, since all of them render
on boot.

Extracting the prose from the DOM is not tested. There is no jsdom in this project and no
`package.json` to add one to, and a stub faithful enough to catch the `<br>` case would be
a reimplementation of `textContent` rather than a test of it. The same line is already
drawn around `createScrollSync()`'s measuring, which is untested while `ozhmd-scroll-lib`'s
arithmetic is covered thoroughly. The lib holds everything else worth pinning.
