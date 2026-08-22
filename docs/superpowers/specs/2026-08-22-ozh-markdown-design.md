# Ozh' Markdown Plugin: Design

Date: 2026-08-22
Status: approved, ready for implementation planning
Source requirements: `specs.md` (authoritative on *what*; this document is authoritative on *how*)

## 1. Overview

A WordPress plugin replacing the block editor with a split-pane Markdown editor: CodeMirror on the left, live HTML preview on the right. Raw Markdown lives in post meta, generated HTML lives in `post_content`, so deactivating the plugin leaves every published post rendering correctly. The same storage model applies to comments, with a stricter conversion path because comment input is untrusted.

Scope: all post types supporting `editor` (minus an exclusion list), plus comments, plus drag and drop image upload on the post editor only.

## 2. Decisions taken during brainstorming

These resolve ambiguities or gaps in `specs.md`. Where they conflict with a literal reading of the spec, this document wins.

| # | Question | Decision |
|---|---|---|
| 1 | Version control | The plugin is its own git repository, rooted at `wp-content/plugins/ozh-markdown/`, independent of the planetozh.com site repo. |
| 2 | PHP test strategy | Both layers, pure-first. The majority of tests are plain PHPUnit with no WordPress bootstrap; a thin wp-phpunit suite covers hook wiring and WordPress-dependent behaviour. |
| 3 | CodeMirror version | CodeMirror 5. It ships as browser globals, so vendoring is a file copy with no bundler and no build step, ever. |
| 4 | JS test strategy | Node's built-in `node:test` runner, zero npm dependencies. Pure JS helpers live in a separate lib file usable both as a browser global and as a Node module. |
| 5 | Editor layout | Standard meta box inside the normal classic edit screen. WordPress' own Screen Options > 1 column toggle provides full width. No custom fullscreen mode. |
| 6 | Revisions | Markdown is revisioned alongside content and restored on `wp_restore_post_revision`, regenerating `post_content` from the restored Markdown. |
| 7 | Legacy posts | Passthrough until explicitly opted in. See section 6.2. |

## 3. Architectural approach

The spec's hard constraint is testability, and its concrete demand is that logic be separable from WordPress wiring. The chosen split:

- **Pure logic becomes prefixed free functions** in one WordPress-free file. Predicates, string transforms, validators and small builders take arguments and return values, read no globals, call no WordPress functions. This is where the bulk of the test suite points, and it runs with no bootstrap.
- **Anything with dependencies or hooks becomes a small class** with an explicit constructor storing injected dependencies and a separate `register_hooks(): void`. Constructors contain no logic. No singletons, no static state.

Rejected alternatives: wrapping every one-line predicate in its own class (ceremony without benefit, and each instance then needs injecting), and keeping logic inline in the service classes (mixes "decide" with "do", which the spec forbids).

### 3.1 Bootstrap

`ozh-markdown.php` contains the plugin header, constants, `require` statements, and a single `ozhmd_bootstrap(): void` hooked to `plugins_loaded`. That function constructs the services, injects the converter, and calls `register_hooks()` on each. Nothing else in the plugin adds hooks at construction time, so every class can be instantiated in a test without touching the hook system.

Activation and deactivation hooks do the minimum: seed default options on activation, and nothing destructive on deactivation. Uninstall is out of scope for now (no `uninstall.php`); post meta must survive deactivation by design.

### 3.2 File tree

```
ozh-markdown/
  ozh-markdown.php                       plugin header, constants, bootstrap
  includes/
    ozhmd-functions.php                  all pure, WordPress-free logic
    class-ozhmd-converter.php            holds the two injected converters
    class-ozhmd-converter-factory.php    builds the two configured environments
    class-ozhmd-post-types.php           eligibility, exclusions, disabling Gutenberg/TinyMCE
    class-ozhmd-editor-ui.php            meta box render, admin asset enqueue
    class-ozhmd-post-storage.php         meta read/write, conversion into post_content
    class-ozhmd-revisions.php            Markdown in revisions, restore handling
    class-ozhmd-uploader.php             REST upload route, capability and nonce checks
    class-ozhmd-comments.php             comment conversion, storage, admin edit screen, KSES
    class-ozhmd-frontend.php             conditional Prism enqueue on the front end
    class-ozhmd-settings.php             options page
  assets/
    js/
      editor.js                          CodeMirror init, debounced preview, Prism
      upload.js                          drag/drop/paste wiring (DOM only)
      comment-preview.js                 front-end comment preview
      lib/
        ozhmd-upload-lib.js              pure helpers, browser global + Node module
      vendor/
        marked.min.js
        codemirror/
        prism/prism.min.js
    css/
      editor.css
      vendor/prism/prism-light.min.css
      vendor/prism/prism-dark.min.css
  tests/
    unit/                                plain PHPUnit, no WordPress
    integration/                         wp-phpunit, needs a database
    js/                                  node:test
    tools/composer.json                  dev-only tooling, gitignored vendor
  tools/
    fetch-vendor.sh                      reproducible JS vendoring
    VERSIONS.md                          exact vendored versions
  vendor/                                composer --no-dev, committed
  docs/superpowers/specs/                design documents
  composer.json
  readme.txt
```

The spec's suggested `class-ozhmd-editor-ui.php` is split three ways (`post-types`, `editor-ui`, `post-storage`) because it was doing three independently testable jobs. `class-ozhmd-comment-editor.php` is renamed `class-ozhmd-comments.php` since it covers the whole comment path, not just the editor.

## 4. Pure function inventory

Everything in `includes/ozhmd-functions.php`. All typed, all DocBlocked, none touching `$_POST`, `get_option()` or any WordPress API. This list is the primary unit test target.

| Function | Purpose |
|---|---|
| `ozhmd_default_excluded_post_types(): array` | The exclusion list from the spec, as data. |
| `ozhmd_is_excluded_post_type(string $post_type, array $excluded): bool` | Membership test. |
| `ozhmd_eligible_post_types(array $editor_post_types, array $excluded): array` | Set subtraction, preserving order. |
| `ozhmd_content_has_code_block(string $html): bool` | Detects `<pre><code` allowing arbitrary whitespace and attributes between the tags. Drives conditional Prism enqueue. |
| `ozhmd_is_valid_language_class(string $class): bool` | `^language-[a-z0-9+-]+$`, anchored, case sensitive. |
| `ozhmd_filter_language_classes(string $html): string` | Rewrites `class` attributes on `pre`/`code`, keeping only tokens passing the validator, dropping the attribute entirely if none survive. |
| `ozhmd_post_state(?string $markdown): string` | Returns `legacy` when meta is null, `markdown` otherwise. Single source of truth for the passthrough rule. |
| `ozhmd_alt_from_filename(string $filename): string` | Filename minus extension, used as image alt text. |
| `ozhmd_build_markdown_image(string $alt, string $url): string` | `![alt](url)`, escaping `]` and `)` in the inputs. |
| `ozhmd_upload_placeholder(string $filename): string` | `![Uploading filename.png...]()`. |
| `ozhmd_is_allowed_image_mime(string $mime, array $allowed_mimes): bool` | True only when the MIME is `image/*` **and** present in the site's allowed list. Never widens. |
| `ozhmd_default_options(): array` | Option defaults as data. |
| `ozhmd_sanitize_options(array $input, array $defaults): array` | Coerces the settings payload to booleans, ignores unknown keys. |

Mirror helpers in `assets/js/lib/ozhmd-upload-lib.js`: `buildPlaceholder`, `buildMarkdownImage`, `replacePlaceholder`, `isImageFile`. `replacePlaceholder` replaces the **first literal occurrence only**, never as a regex, so filenames containing regex metacharacters cannot break it.

## 5. Converters

`Ozhmd_Converter` stores two `League\CommonMark\ConverterInterface` instances handed to its constructor and exposes `to_html_trusted(string $markdown): string` and `to_html_untrusted(string $markdown): string`. It contains no configuration. `Ozhmd_Converter_Factory` builds the configured instances, so tests inject fakes into the former and assert real behaviour against the latter.

Two instances, distinctly configured, never one instance with runtime flags. The untrusted config cannot be reached from the post path and vice versa.

**Trusted (posts)**

- `html_input => 'allow'`, `allow_unsafe_links => true`
- CommonMark core plus the GitHub Flavored Markdown extension

**Untrusted (comments)**

- `html_input => 'strip'`, `allow_unsafe_links => false`
- CommonMark core plus GFM
- `max_nesting_level => 20`, to bound pathological input
- A `DocumentParsedEvent` listener that appends `nofollow` and `ugc` to `attributes/rel` on every `Link` node, so every generated link carries `rel="nofollow ugc"` regardless of host

`ExternalLinkExtension` is deliberately **not** used: its config schema supports only `nofollow`, `noopener` and `noreferrer`, with no `ugc` option, and it applies `rel` based on host matching rather than unconditionally. Verified against league/commonmark 2.10.0. The listener uses the same `$link->data->append('attributes/rel', ...)` mechanism that extension uses internally, which the renderer joins with spaces.

Both use GFM deliberately: `marked.js` is GFM by default, so matching it server-side narrows the preview/output divergence the spec acknowledges. The readme still documents that the preview is an approximation and PHP is authoritative.

## 6. Post path

### 6.1 Save pipeline

One conversion point, so there is one thing to test.

1. `save_post` priority 10, admin form path only: if `$_POST['ozhmd_markdown']` is set and the nonce and `edit_post` capability check out, write it verbatim to `_ozhmd_markdown`.
2. `save_post` priority 20, all paths: read `_ozhmd_markdown`. If absent, do nothing (passthrough, see 6.2). If present, convert with the trusted converter and, when the result differs from the stored `post_content`, write it back behind a re-entrancy guard.

REST and XML-RPC saves get conversion for free: both land on `save_post`, and the REST controller has already written registered meta by the time priority 20 runs. Autosaves and revisions are skipped at the top of both callbacks.

`_ozhmd_markdown` is registered with `register_post_meta()` (`show_in_rest`, `single`, `auth_callback` requiring `edit_post`) for every eligible post type, so REST clients can send Markdown.

### 6.2 Legacy content and post state

A post with no `_ozhmd_markdown` is in `legacy` state. Step 2 above skips it entirely, so its `post_content` is stored exactly as typed, unconverted, byte for byte. An untouched archive is never rewritten, and no bulk migration exists.

The meta box for a legacy post loads `post_content` verbatim into the editor and shows an inline notice with a **Convert to Markdown** button. That action:

1. copies the current `post_content` to `_ozhmd_original_content` (a one-time backup, never overwritten),
2. writes the same string to `_ozhmd_markdown`,
3. converts and updates `post_content`.

From then on the post is in `markdown` state and follows the normal pipeline. The three states are explicit and separately testable, rather than one implicit transition triggered by an accidental save. This matters because legacy WordPress content stores no `<p>` tags and relies on `wpautop`, so a silent conversion would reflow paragraphs, turn `some_file_name` into emphasis, and reinterpret four-space-indented HTML as a code block.

### 6.3 Revisions

`_ozhmd_markdown` is added to the revisioned fields so each revision carries the Markdown that produced its `post_content`. On `wp_restore_post_revision`, the revision's Markdown is copied back to the parent and `post_content` is regenerated from it, keeping the two in sync. Without this, restoring a revision would leave stale Markdown against restored HTML.

## 7. Editor UI

`Ozhmd_Post_Types` computes eligibility (post types supporting `editor`, minus `ozhmd_excluded_post_types`) and applies it: `use_block_editor_for_post_type` returns false, `remove_post_type_support(..., 'editor')` drops the TinyMCE box. Deciding and doing are separate methods.

`Ozhmd_Editor_UI` registers a high-priority `normal` meta box rendering a two-column layout: a `<textarea name="ozhmd_markdown">` on the left, an empty preview container on the right, plus the nonce field. The textarea is populated from `_ozhmd_markdown`, falling back to `post_content`.

Progressive enhancement is structural, not decorative: the textarea is the real form field and posts correctly with JavaScript disabled. `editor.js` attaches CodeMirror to it and keeps the underlying textarea synced on change, so the no-JS path and the enhanced path submit through the same field.

The preview updates on a 300ms debounce via `marked.js`, then calls `Prism.highlightAllUnder(previewContainer)`, never `highlightAll()`.

## 8. Upload

`POST ozhmd/v1/upload`, registered on `rest_api_init`.

- Nonce: standard `wp_rest` via `X-WP-Nonce`, verified on every request.
- Permission callback: `current_user_can('upload_files')`, and additionally `current_user_can('edit_post', $post_id)` when a post ID is supplied. The post ID from the request is never trusted.
- MIME: validated with `ozhmd_is_allowed_image_mime()` against `get_allowed_mime_types()`. The allowed set is never widened.
- Upload via `media_handle_upload()` only, attached to `$post_id` when present and unattached (0) for a brand new post.
- Success returns `{ id, url, alt }`. Failure returns a `WP_Error` with a real HTTP status, never a 200 carrying an error string.

Client side, `upload.js` handles `drop` and `paste` through the same code path. Each file gets its own placeholder inserted at the cursor, uploads independently and in order, and is replaced independently, so one failure cannot affect its siblings. Non-image files are rejected before upload with a visible message. Failures replace the placeholder with an empty string and raise a dismissible notice naming the file. All placeholder string manipulation is delegated to `ozhmd-upload-lib.js`, which is what the JS tests exercise.

## 9. Comment path

**Conversion.** `preprocess_comment` puts the raw Markdown into `$commentdata['comment_meta']['_ozhmd_markdown']` and the converted HTML into `comment_content`. Conversion happens before WordPress' own filters run, so Akismet, moderation and every core check see the final HTML exactly as they normally would. Nothing in the existing comment pipeline is bypassed or short-circuited.

**Admin editing.** The Edit Comment screen loads `_ozhmd_markdown` into the content textarea, falling back to `comment_content` when absent, mirroring the legacy fallback on posts. That textarea therefore holds Markdown, so on save the submitted value is written verbatim to `_ozhmd_markdown` and converted into `comment_content` by the same code path as a front-end submission. Admin replies, which go through `wp_insert_comment`, are covered by the `preprocess_comment` path above. No CodeMirror in the comment admin screen.

**Sanitization, in order:**

1. Untrusted converter, which strips raw HTML input outright.
2. `ozhmd_filter_language_classes()` over the result.
3. WordPress' own `wp_filter_comment` / KSES pipeline, with `class` allowed on `pre` and `code` only, via `wp_kses_allowed_html` for the `pre_comment_content` and `comment_content` contexts.

Step 2 is belt and braces rather than the load-bearing check. Because step 1 strips all raw HTML, the only `class` attributes that can exist in the output are the `language-*` ones CommonMark generated from fence info strings, so the KSES allowance is safe by construction. KSES cannot pattern-match attribute values, which is exactly why the allowance alone would be insufficient and step 2 exists.

**Front-end preview** is optional and off by default. When enabled, `comment-preview.js` renders a pane below the comment form using the same `marked.js` bundle. It is enqueued only on pages actually displaying a comment form. CodeMirror is never enqueued on the front end.

## 10. Front end

Prism loads only where there is code to highlight.

- Primary detection at `wp_enqueue_scripts`: scan the queried posts' `post_content` with `ozhmd_content_has_code_block()`. This runs before `wp_head`, so the CSS lands in the head, ahead of the theme's stylesheets, and stays overridable by the theme.
- Fallback for comments: a `comment_text` filter enqueues the same registered handles if a comment contains a code block and nothing was enqueued yet. WordPress prints late styles and scripts in the footer, so this works without breaking the primary path.

Scripts and styles are *registered* unconditionally and *enqueued* conditionally. A page with no fenced code anywhere enqueues neither, which is an explicit test case.

The Prism bundle carries a fixed language set (`markup`, `css`, `clike`, `javascript`, `php`, `bash`, `sql`, `json`, `yaml`, `diff`, `markdown`, `ini`), filterable through `ozhmd_prism_languages`, with regeneration documented in `tools/VERSIONS.md`. No autoloader. Light and dark themes are enqueued as two stylesheets carrying `(prefers-color-scheme: light)` and `(prefers-color-scheme: dark)` media attributes, and contain no `!important`. A theme that manages its own colour scheme can override those two media strings through an `ozhmd_prism_theme_media` filter (for instance forcing one to `all` and the other to `not all`) rather than fighting the media query. The Line Numbers plugin sits behind a settings toggle, default off.

## 11. Settings

One page at Settings > Ozh' Markdown, backed by a single `ozhmd_options` array through the Settings API with `ozhmd_sanitize_options()` as the sanitize callback.

| Key | Default | Meaning |
|---|---|---|
| `comments_enabled` | `true` | Markdown for comments. |
| `comment_preview` | `false` | Front-end live preview under the comment form. |
| `prism_line_numbers` | `false` | Prism Line Numbers plugin. |

Plus a read-only list of the post types currently handled. No per-post-type checkboxes: fine-grained control is the `ozhmd_excluded_post_types` filter.

### 5.1 Verified behaviour

Probed against the real libraries on PHP 8.5 during planning, and therefore safe to assert in tests:

| Input | Converter | Output |
|---|---|---|
| `<div class="x">hi</div>` | trusted | passes through unchanged |
| `hi <script>alert(1)</script> there` | untrusted | `<p>hi alert(1) there</p>` |
| `[a](https://e.com/x)` | untrusted | `<a rel="nofollow ugc" href="https://e.com/x">a</a>` |
| `[a](javascript:alert(1))` | untrusted | `<a rel="nofollow ugc">a</a>`, href dropped entirely |
| ` ```php ` fence | both | `<pre><code class="language-php">` |
| GFM table, `~~x~~` | both | `<table>`, `<del>` |

Note the second row: `html_input => 'strip'` removes the *tags* but keeps their text content, which is correct and safe. Tests must therefore assert that the output contains no `<script` substring, not that the text `alert(1)` is absent.

## 12. Dependencies and vendoring

`vendor/` is committed, produced by `composer install --no-dev --optimize-autoloader`, so no development package can ever ship. `league/commonmark` is the only runtime requirement. PHPUnit, wp-phpunit and the polyfills live in a separate `tests/tools/composer.json` with its own gitignored `vendor/`, which the test bootstrap loads alongside the runtime autoloader.

JavaScript dependencies are vendored as files, never fetched at runtime and never from a CDN: `marked.min.js`, CodeMirror 5 (core, Markdown mode, one theme), and a Prism bundle concatenated from the npm `prismjs` package for the language set above plus both themes. `tools/fetch-vendor.sh` makes the process reproducible and `tools/VERSIONS.md` records exact versions. The script runs on the host, where Node is available; it is a maintenance tool, never a requirement for using or testing the plugin.

## 13. Testing

Three suites, run independently.

**`tests/unit/`**, plain PHPUnit, no WordPress, no database, the majority of the suite. Covers every function in section 4, plus the real CommonMark converters (which have no WordPress dependency). Required coverage from the spec lands here: the untrusted converter stripping `<script>`, the `language-*` regex against valid and crafted values, MIME rejection, post type eligibility including exclusions, code block detection.

**`tests/integration/`**, wp-phpunit against the `dev-mariadb` container. Covers hook wiring, the `save_post` pipeline including a REST save, the legacy passthrough and opt-in conversion, revision save and restore, the comment pipeline through real KSES (including the `language-php` class surviving and a `<script>` being stripped end to end), the REST upload endpoint's capability and nonce rejections, and Prism being enqueued on a page with code and *not* on a page without.

**`tests/js/`**, `node:test` on the host. Covers placeholder building, first-literal-occurrence replacement including filenames with regex metacharacters, Markdown image building, and image type detection.

Every bug fixed gets a regression test.

## 14. Delivery milestones

1. Skeleton, activation hooks, settings page, all three test harnesses, converter factory and `Ozhmd_Converter` with their tests.
2. Post path: meta registration, save pipeline, legacy passthrough and opt-in, revisions, post type eligibility, meta box, CodeMirror, debounced preview, Prism in the preview pane, no-JS fallback verification.
3. Front end conditional Prism with light/dark themes, then the REST upload endpoint followed by drag, drop and paste handling.
4. Comment path end to end, optional front-end comment preview, readme, manual test pass.

## 15. Out of scope

As per the spec: no WYSIWYG toggle, no custom media browser, no collaborative editing, no HTML-to-Markdown migration, no block editor compatibility layer. Additionally no uninstall routine and no fullscreen editor mode.

**Deferred to a separate task:** migrating legacy posts that use `igsyntax-hiliter` markup, converting `[php]...[/php]` shortcodes to fenced ``` ```php ``` blocks. That plugin is being deactivated, so those posts will render their shortcodes raw until migrated. The passthrough rule in section 6.2 guarantees this plugin will not make that situation worse, and the readme documents the pending migration.

## 16. Known risks

- The client preview (`marked.js`) and the server converter (`league/commonmark`) will disagree on edge cases. Accepted and documented rather than engineered around; PHP is authoritative.
- With `classic-editor` deactivated, this plugin becomes solely responsible for keeping the block editor off, so `use_block_editor_for_post_type` returning false is an integration test, not an assumption.
- The Prism head-enqueue path depends on `$wp_query` being populated at `wp_enqueue_scripts`, which holds for normal template rendering but not for unusual custom loops. The `comment_text` fallback covers the miss, at the cost of footer-printed styles in that case.
