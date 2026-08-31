# Dropping Text Files Into the Editor: Design

Date: 2026-08-30
Status: approved, implementing directly (no separate plan document)
Extends: `2026-08-22-ozh-markdown-design.md`, section on drag and drop upload

## 1. Overview

The drag and drop handler currently accepts images and refuses everything else with a
notice. This adds a second accepted kind: a text file is read in the browser and its
contents are inserted at the cursor inside a fenced code block, with a language hint taken
from the extension, or from the contents when the name gives nothing away. Only a file
that looks binary once read is refused.

Nothing is uploaded for a text file. There is no request, no media library entry, no
server involvement of any sort. The whole feature lives in the two existing JS files.

## 2. Decisions taken during brainstorming

| # | Question | Decision |
|---|---|---|
| 1 | What counts as a text file | An extension allowlist, not the MIME type. Browsers report `text/plain` for `.txt` and `.md`, an empty string for `.php`, `.py` and `.ini`, and `application/json` for `.json`, so the reported type is not usable as the rule. The allowlist doubles as the language map. |
| 2 | What gets inserted | The fence and nothing else. No file name line, no caption, no file name in the info string. |
| 3 | Size limit | 100 KB (102400 bytes), on the text path only. A text file is inlined into the post itself, so the question is what belongs in a post, not what the site can store. Images are unaffected: they upload, and the server decides. |
| 4 | Paste | Unchanged. Paste keeps intercepting images only. |
| 5 | Names the allowlist misses | Not a refusal. `updatewp` and `phpunit.xml.dist` are droppable files, so the name gets two more chances before the contents decide. See section 3.3. |
| 6 | What is refused | Only what looks binary once read. The extension never refuses a file on its own. |

## 3. Pure helpers

All three go in `assets/js/lib/ozhmd-upload-lib.js`, alongside the existing helpers, and
are covered by `tests/js/upload-lib.test.js`. None of them touches the DOM, the network,
or any state: the sniffing helper is handed a string the caller has already read.

### 3.1 `textFileLanguage(filename)`

Returns the fence info string for the file, with a deliberate three-way result:

- a language name (`'php'`, `'json'`, …) for an extension with a bundled Prism language
- `''` for a recognised extension with no language, so the fence opens bare
- `null` when the name decides nothing

`null` is not a refusal, only the end of what the name can tell us: the caller then sniffs
the contents. An empty string is a successful match and must not be confused with it, so
callers test `null` explicitly rather than truthiness.

Before giving up, an unrecognised extension is retried with its meta suffixes stripped:
`.dist`, `.sample`, `.example`, `.default`, `.template`, `.tmpl`, `.bak`, `.orig`, `.old`,
`.save`, `.local`, `.in`. These describe the file's role rather than its contents, so
`phpunit.xml.dist` resolves through `phpunit.xml` to `markup`, and `wp-config.php.dist.bak`
through to `php`. Stripping repeats and never consumes the part before the first dot, so
`notes.dist` reduces to `notes` and returns `null`.

Matching is case insensitive. A name whose only dot is the leading one (`.htaccess`) is
matched on the whole name, not treated as extensionless.

The map is drawn from the languages the Prism bundle actually ships
(`PRISM_LANGUAGES` in `tools/fetch-vendor.sh`), so no fence can name a language the
highlighter does not have:

| Extensions | Info string |
|---|---|
| `php` | `php` |
| `js`, `mjs` | `javascript` |
| `css` | `css` |
| `html`, `htm`, `xml` | `markup` |
| `sh`, `bash`, `zsh` | `bash` |
| `py` | `python` |
| `json` | `json` |
| `sql` | `sql` |
| `pl`, `pm` | `perl` |
| `ini` | `ini` |
| `.htaccess`, `conf` | `apacheconf` |
| `txt`, `log`, `md`, `markdown`, `csv` | `''` (bare fence) |

No `svg`, even though `markup` would cover it. Browsers report `image/svg+xml` for a
dropped `.svg`, so it is classified as an image before the map is consulted and goes down
the upload path, which is what a site that permits SVG uploads expects.

### 3.2 `sniffTextLanguage(sample)`

Takes the decoded head of a file whose name decided nothing, and returns the same kind of
value one step further on: a language name, `''` for a bare fence, or `null` when the file
is binary. This is the only thing in the feature that refuses a file.

`file.text()` decodes as UTF-8 whatever it is handed, so bytes that are not valid UTF-8
come back as U+FFFD. The binary test follows from that: a null byte settles it alone, and
otherwise the sample is binary when more than a tenth of it is replacement characters or
C0 control characters other than tab, newline, carriage return and form feed. A tenth sits
far above the one or two replacement characters a multibyte character cut in half at the
end of the slice can produce, and far below what any binary format scores.

Anything that passes is accepted. A leading `#!` line is read for its interpreter, through
`env` and past its options and `VAR=value` assignments, and mapped to a bundled language
(`sh`, `bash`, `zsh`, `ksh`, `dash`, `python`, `python2`, `python3`, `php`, `perl`,
`node`); an unknown interpreter is not a refusal, it just leaves the fence bare. A leading
`<?php` gives `php`. Everything else gets a bare fence.

`SNIFF_BYTES` (4096) is exported alongside, so the caller knows how much to slice.

### 3.3 Name first, contents second

The two helpers form one sequence, and the order matters. The extension is consulted
first because it is free and because it is the better signal when present: a `.py` file
is Python whether or not it opens with a shebang. Only when the name yields `null` does
the file get opened, and then only its first `SNIFF_BYTES` bytes, so a dropped video is
refused without being pulled into memory whole. The full read happens after the sniff has
approved it.

### 3.4 `buildCodeFence(content, language)`

Returns the complete fenced block, without a trailing newline. It normalises the content
first:

- CRLF and lone CR become LF
- a leading UTF-8 BOM is stripped
- trailing whitespace and newlines are trimmed, so the closing fence is not preceded by
  blank lines

The opening delimiter is three backticks, unless the content contains a run of three or
more backticks at the start of a line; in that case the delimiter is one backtick longer
than the longest such run. This is the CommonMark rule, and without it dropping a
Markdown file that itself contains fences closes the block early and spills the rest of
the file into the post as live Markdown.

## 4. Wiring in `upload.js`

`uploadAll()` today partitions the drop into images and rejects, then runs the images
through a sequential promise chain. It becomes `handleFiles()`, an ordered walk: every
file is reduced into a single chain in drop order, each step either uploading an image or
going down the text path. Dropping a screenshot and a snippet together then lands them in
the order they were dropped rather than images first, and errors appear in that order too
rather than all at the front.

Classification cannot happen up front any more, because deciding about a file may require
reading it. `insertTextFile()` therefore owns the whole decision. The size check comes
first, on `file.size`, so anything over the limit is refused on its metadata without a
single byte being read. Then `resolveLanguage()` returns the name's answer immediately when
it has one, or slices `SNIFF_BYTES` and sniffs; a `null` from either reports through
`showError()` and inserts nothing.

Text files read through `file.text()`, which returns a promise. `init()` gains a guard for
it next to the existing `window.fetch` check, so an old browser disables the whole handler
rather than throwing mid-drop.

Insertion reuses `insertAtCursor()`. The fence must start on its own line, so a newline is
prefixed when the cursor is not already at the start of one, and one is appended after the
closing fence. A read failure reports through `showError()` like an upload failure does.

## 5. Strings

`notAnImage` no longer describes the rule, since a text file is now accepted too, and the
refusal is about contents rather than type. It is replaced, in
`includes/class-ozhmd-editor-ui.php`, by:

> is not an image, and does not look like a text file, so it was not inserted.

A second string covers the size refusal, built with `size_format()` so the limit is
written once and the message reads in the site's own units:

> is larger than 100 KB, so it was not inserted.

A second string covers a failed read:

> could not be read.

## 6. Tests

Added to `tests/js/upload-lib.test.js`:

- `textFileLanguage()`: a language hit, a bare-fence hit, an unknown extension returning
  `null`, uppercase extensions, a name with no dot, a name with several dots, `.htaccess`,
  the empty-string versus `null` distinction stated explicitly, single and repeated meta
  suffixes, and a name that reduces to nothing but a meta suffix
- `sniffTextLanguage()`: a plain shebang, one through `env`, one through `env` with
  options and assignments, an unknown interpreter, a leading `<?php`, plain prose, the
  empty sample, a null byte, a sample dense in replacement characters, one dense in
  control characters, a single truncation artifact tolerated, and tabs and newlines left
  innocent
- `buildCodeFence()`: a plain block, a language-less block, content containing a triple
  backtick run, content containing a quadruple run, CRLF input, a BOM, and trailing blank
  lines

## 7. Where the limit lives

`Ozhmd_Editor_UI::MAX_TEXT_BYTES` is the single definition, passed to the browser as
`maxTextBytes` in the `wp_localize_script()` payload and used by `size_format()` to build
the message. Nothing in the JavaScript hard codes the number, so changing the constant
changes both the behaviour and the wording.

The check is client side only, and that is sufficient here: the text path never reaches the
server, so there is nothing to enforce at the other end. The REST upload endpoint keeps its
own server side validation for images, untouched by this feature.

The JavaScript keeps `DEFAULT_MAX_TEXT_BYTES` as a fallback, and it is not redundant.
`wp_localize_script()` stringifies its payload, so the limit arrives as `"102400"`, and if
it ever fails to arrive at all the comparison is made against `undefined`, which is false
for every file size. The cap would then vanish without a word and the first symptom would
be a browser freezing on a file it should have refused. `resolveMaxTextBytes()` converts
the configured value and falls back when it is not a positive finite number.
`tests/unit/TextSizeLimitTest.php` reads both files and fails if the two constants drift.

The two limits are unrelated and should stay that way. `SNIFF_BYTES` bounds how much of an
unknown file is read to decide what it is; `MAX_TEXT_BYTES` bounds what may be inlined at
all. A file can pass the first and fail the second, and in fact the size check runs first,
so an oversized file is never sniffed.
