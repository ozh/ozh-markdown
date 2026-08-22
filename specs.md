# Ozh' Markdown Plugin - Specs

## Goal

Replace the default WordPress editing experience with a minimal split-pane Markdown editor: raw Markdown on the left, live HTML preview on the right. No blocks, no WYSIWYG, no bloat.

Scope covers **all post types** (posts, pages, and any registered custom post type) **and comments**.

The final published content must be plain HTML stored in `post_content` / `comment_content`, independent of the plugin (no lock-in: if the plugin is deactivated, existing content still renders correctly).

## Naming

- Plugin name: **Ozh' Markdown Plugin**
- Text domain / slug: `ozh-markdown`
- Function prefix: `ozhmd_`
- Class prefix: `Ozhmd_`
- Filter/action prefix: `ozhmd_`
- Post meta key: `_ozhmd_markdown`
- Comment meta key: `_ozhmd_markdown`
- Option name: single option array `ozhmd_options`

## Target environment

- Shared hosting, no root/sudo access, no shell access assumed on the production server.
- Standard WordPress plugin (PHP, no external services, no build step required at runtime).
- Assume PHP 8.0+ unless told otherwise (typed signatures are used throughout, see conventions).
- No Node/Composer step should be required to *use* the plugin once installed. Dependencies must be committed to the repo (vendored), not installed on the fly on the host.

### Development environment

Development happens against a local Docker LAMP stack, not the production shared host:

- Blog reachable at `https://127.0.0.1/planetozh.com/blog/`.
- Shell inside the container: `docker exec -it dev-web bash --login`, then `cd planetozh.com/blog`.
- Stack definition: `~/dev/docker-compose.yml`.
- Plugin path inside the container: `wp-content/plugins/ozh-markdown/`.
- Composer, Node and the database are available *in the container* for development and testing only. None of them may become a runtime requirement of the plugin.

## Part 1 - Post editing (all post types)

### Behavior

1. For every registered post type that supports the `editor` feature, disable the block editor (`use_block_editor_for_post_type` filter, returning `false`) and disable TinyMCE, replacing the content editing area with the custom editor UI.
2. Two-column layout:
   - Left: a `<textarea>` enhanced with CodeMirror (syntax highlighting, line numbers).
   - Right: live-rendered HTML preview, updated on keystroke, debounced (~300ms).
3. Raw Markdown is stored as-is in post meta `_ozhmd_markdown`.
4. On save (`save_post`), Markdown is converted to HTML server-side in PHP and written to `post_content`. This is the published/rendered/RSS content. Conversion must also run when a post is saved via the REST API or XML-RPC, not only from `wp-admin`.
5. When editing an existing post with no `_ozhmd_markdown` (legacy content), show the existing `post_content` as-is in the textarea. Do not attempt HTML-to-Markdown conversion; just never lose data.
6. Progressive enhancement: if JS fails to load, the plain `<textarea>` must still work and save correctly. Raw Markdown must never be lost.

### Excluded post types

Some post types must never get the Markdown editor even though they support `editor`. Exclude at minimum: `attachment`, `revision`, `nav_menu_item`, `wp_block`, `wp_template`, `wp_template_part`, `wp_navigation`, `wp_global_styles`, `customize_changeset`, `oembed_cache`, `user_request`. Provide a filter `ozhmd_excluded_post_types` so the list can be extended from a theme or another plugin.

## Part 2 - Drag & drop image upload (posts and pages only)

GitHub-style image insertion in the post editor. **Not available on comments**, front end or back end.

### Behavior

1. Dropping one or more image files onto the Markdown textarea uploads them to the WordPress media library and inserts the corresponding Markdown at the cursor position.
2. While an upload is in flight, insert a placeholder at the cursor, GitHub-style:
   `![Uploading filename.png...]()`
   On success, replace that exact placeholder with `![filename](https://example.com/wp-content/uploads/.../filename.png)`. On failure, replace it with nothing and surface a dismissible error notice naming the file.
3. Multiple files dropped at once must each get their own placeholder and be replaced independently, in the order they were dropped. A failure on one file must not affect the others.
4. Non-image files are rejected client-side before upload, with a clear message. Do not silently ignore them.
5. Clipboard paste of an image (`paste` event with image data in `DataTransfer`) should follow the exact same code path as drop. GitHub supports both and users expect it; treat it as part of this feature, not a separate one.
6. Uploaded images are attached to the current post (`post_parent`) when the post already has an ID; for a brand new unsaved post, upload unattached and let WordPress handle it.

### Server side

- Use `media_handle_upload()` / `wp_handle_upload()` - never write into the uploads directory manually.
- Endpoint: a REST route (`ozhmd/v1/upload`) rather than `admin-ajax.php`.
- **Capability check**: `current_user_can('upload_files')`, plus `current_user_can('edit_post', $post_id)` when a post ID is supplied. Never trust the post ID from the request without checking.
- **Nonce check**: standard REST nonce (`wp_rest`), verified on every request.
- Respect the site's allowed MIME types (`get_allowed_mime_types()`); do not widen them.
- Return a JSON payload with the attachment ID, the URL, and the alt text to use. Return a proper `WP_Error` with an HTTP status on failure - do not return a 200 with an error string in the body.

### Out of scope for this feature

- No image resizing, cropping or editing UI.
- No drag & drop of non-image media (video, PDF, zip).
- No reordering or gallery building.

## Part 3 - Comments

Comments are a different beast from posts: input is untrusted, written on the front end, and WordPress applies its own sanitization pipeline. Handle them accordingly.

### Behavior

1. **Storage**: store the raw Markdown in comment meta `_ozhmd_markdown`, and write the converted, sanitized HTML into `comment_content` - mirroring the post logic, so the "no lock-in" rule holds for comments too.
2. **Conversion point**: hook into comment save (`preprocess_comment` and/or `wp_insert_comment` / `edit_comment`) so that conversion happens for front-end submissions, admin replies, and admin comment edits alike.
3. **Admin comment editing**: on the Edit Comment screen, load `_ozhmd_markdown` into the textarea (falling back to `comment_content` if absent), and re-run conversion on save.
4. **Front-end live preview**: optional, off by default (a settings toggle). When enabled, render a preview pane below the comment form using the same `marked.js` bundle. Keep it lightweight - do not enqueue the editor JS on pages where the comment form isn't displayed, and never enqueue CodeMirror on the front end.

### Security requirements for comments (important)

Markdown converters pass raw HTML through by default. For comments that is a stored-XSS vector, so the comment conversion path must be stricter than the post path:

- Configure CommonMark with `html_input => 'strip'` and `allow_unsafe_links => false` for the **comment** converter instance. Posts may keep raw HTML enabled since they're authored by trusted users, but comments must not.
- After conversion, always run the result through WordPress's own comment sanitization (`wp_filter_comment` / `wp_kses` with the comment-appropriate allowed tags) rather than trusting the converter alone. Belt and braces.
- WordPress's default allowed comment tags do **not** permit a `class` attribute on `<code>` / `<pre>`, so KSES will silently strip `class="language-php"` and Prism will not highlight code blocks in comments. Extend the allowed tags (via `wp_kses_allowed_html`, `$context === 'pre_comment_content'` / `'comment_content'`) to allow `class` on `pre` and `code` **only**, and only for values matching `^language-[a-z0-9+-]+$`. Do not open up `class` generally.
- Add `rel="nofollow ugc"` to links generated inside comment content.
- Never bypass or short-circuit WordPress's existing comment filters, moderation, or spam checks (Akismet etc. must still see the content).
- Use two separate, distinctly-configured converter instances (trusted / untrusted) rather than one shared instance with runtime flags, to make it impossible to accidentally use the permissive config on comment input.

## Technical stack

- **Client-side preview**: `marked.js`, bundled locally (no CDN dependency). Visual only - nothing is sent server-side to build the preview.
- **Server-side conversion (source of truth)**: `league/commonmark`, vendored in the plugin.
- **Editor widget**: CodeMirror 5 or 6, bundled locally. Admin only. Used for the Markdown source pane: syntax highlighting, line numbers, cursor/selection/undo handling.
- **Code block highlighting**: `Prism.js`, bundled locally. Used in two places: inside the editor's live preview pane, and on the front end for published content.

These two libraries have distinct roles and are not interchangeable: CodeMirror is the text editor, Prism colorizes static `<pre><code>` output. Do not attempt to use one for the other's job.

Note that the client-side (`marked.js`) and server-side (`league/commonmark`) renderers will not agree 100% on edge cases. The preview is an approximation; the PHP conversion is authoritative. Document this in the readme rather than trying to make them byte-identical.

### Prism integration details

- CommonMark emits fenced code blocks as `<pre><code class="language-php">`, which is exactly the markup Prism expects. No post-processing or class rewriting is needed.
- Bundle a fixed set of languages rather than the Prism autoloader (the autoloader fetches grammar files on demand, which means extra HTTP requests and a CDN or path dependency). Default set: `markup`, `css`, `clike`, `javascript`, `php`, `bash`, `sql`, `json`, `yaml`, `diff`, `markdown`, `ini`. Expose a filter `ozhmd_prism_languages` so the set can be changed, and document how to regenerate the bundle from the Prism download page.
- **Front-end enqueue must be conditional**: only load Prism on pages whose rendered content (post content or comments) actually contains a code block. Do not enqueue it site-wide. A simple check for `<pre><code` in the content being rendered is sufficient - err on the side of loading it when unsure, but never load it on pages with no code at all.
- Bundle both a light and a dark Prism theme, and switch between them via `prefers-color-scheme` (or a theme-provided body class if one is present). The theme CSS must be overridable by the active WordPress theme - enqueue it with a low priority and avoid `!important`.
- In the editor preview pane, re-run `Prism.highlightAllUnder()` on the preview container after each debounced re-render, not `Prism.highlightAll()` on the whole document.
- Prism's Line Numbers plugin is optional; if included, keep it behind a settings toggle (default off) since it adds markup and CSS weight.

## Suggested file structure

```
ozh-markdown/
  ozh-markdown.php                   (plugin header, bootstrap, hooks)
  includes/
    class-ozhmd-editor-ui.php        (admin editor screen rendering, post types)
    class-ozhmd-uploader.php         (REST upload endpoint, capability checks)
    class-ozhmd-comment-editor.php   (comment form + admin comment screen)
    class-ozhmd-converter.php        (wraps league/commonmark, trusted/untrusted instances)
    class-ozhmd-frontend.php         (conditional Prism enqueue on the front end)
    class-ozhmd-settings.php         (options page)
  assets/
    js/
      editor.js                      (CodeMirror init + debounced preview + Prism)
      upload.js                      (drag & drop / paste handling)
      comment-preview.js             (front-end preview, marked.js + Prism)
      vendor/
        marked.min.js
        codemirror/...
        prism/
          prism.min.js               (bundled language set)
    css/
      editor.css
      vendor/
        prism/
          prism-light.min.css
          prism-dark.min.css
  tests/
    bootstrap.php
    ...
  vendor/                            (composer deps, committed)
  readme.txt
```

## Settings

A single settings page (Settings > Ozh' Markdown):
- Toggle: enable Markdown for comments (default: on).
- Toggle: enable front-end live preview under the comment form (default: off).
- Toggle: Prism line numbers (default: off).
- Read-only informational list of the post types currently handled, so the admin can see what's covered.

No per-post-type opt-in checkboxes: the plugin applies everywhere by design. Fine-grained control is done through the `ozhmd_excluded_post_types` filter.

## Coding conventions

- **Typed signatures everywhere**, with the opening brace on the same line:
  ```php
  function ozhmd_some_name(string $url): string {
  ```
  Use parameter types, return types, and nullable types (`?int`) wherever the code allows it. `void` return types where nothing is returned.
- DocBlocks before every class and function, with `@param` and `@return`.
- Code comments in English.
- No inline "section header" comments like `// Helpers` above groups of functions - DocBlocks serve that purpose.
- Files end with a trailing blank line.
- Shared hosting assumptions throughout: no `shell_exec`, no assumption that Composer runs on the production server, no write access assumed outside the uploads/plugin directories.

## Testability - this drives the design

**Everything will be unit tested.** This is a hard architectural constraint, not an afterthought, and it dictates how the code is structured:

- **Short functions, one job each.** If a function needs more than a couple of `@param` lines to describe, or mixes "decide" with "do", split it. A function that both determines whether a post type is eligible *and* removes the editor support is two functions.
- **Separate logic from WordPress wiring.** Hook callbacks should be thin: they receive WP's arguments, call a testable function, and return. The logic being called should not read globals, echo output, or depend on a request context.
- **Pure functions wherever possible.** Anything that transforms a string, validates a MIME type, matches a `language-*` class, builds a Markdown image tag, or decides whether content contains a code block should be a pure function taking arguments and returning a value - no `$_POST`, no `get_option()` inside.
- **Inject dependencies rather than instantiating them inline.** The converter instances in particular must be injectable so tests can substitute them.
- **No logic in constructors** beyond storing injected dependencies. Constructors that add hooks make classes untestable in isolation; use a separate `init()` / `register_hooks()` method.
- **Avoid static state and singletons.** They persist between tests and cause order-dependent failures.
- Use PHPUnit with the WordPress test suite (`wp-phpunit`) for integration-level tests, and plain PHPUnit for the pure functions - the latter should be the majority and should run without a WordPress bootstrap at all.
- Every bug fixed gets a regression test.

Test coverage should specifically include, at minimum: the untrusted converter stripping `<script>`, the `language-*` regex accepting valid and rejecting crafted values, MIME type rejection in the uploader, the placeholder-replacement logic, post type eligibility including the exclusion list, and the code-block detection used for conditional Prism enqueuing.

## Non-goals / explicitly out of scope

- No WYSIWYG toggle, no rich text fallback.
- No custom media browser UI beyond the drag & drop described above.
- No real-time collaborative editing.
- No HTML-to-Markdown migration of existing content.
- No block editor compatibility layer - bypassing blocks is the entire point.

## Suggested build order

1. Scaffold plugin skeleton, activation/deactivation hooks, settings page, PHPUnit setup (test harness first, so everything after it is written test-first).
2. `class-ozhmd-converter.php`: the two converter instances (trusted/untrusted) with their distinct configs. Build and test this first - everything else depends on it.
3. Post path: `_ozhmd_markdown` meta, `save_post` conversion into `post_content`, including REST/XML-RPC saves.
4. Admin editor UI: disable Gutenberg + TinyMCE for all eligible post types, render the textarea meta box.
5. Client-side: CodeMirror init + debounced marked.js preview; verify the no-JS fallback.
6. Prism in the preview pane (`highlightAllUnder` after each re-render).
7. Front end: conditional Prism enqueue, light/dark theme switching.
8. Drag & drop upload: REST endpoint with capability and nonce checks first, then the client-side drop/paste handling and placeholder replacement.
9. Comment path: conversion on save, comment meta storage, admin Edit Comment screen, sanitization pipeline including the `language-*` class allowance.
10. Optional front-end comment preview behind its settings toggle.
11. Manual test pass: new post, legacy post, custom post type, REST API save, front-end comment, admin comment reply, admin comment edit, comment containing raw `<script>` (must be stripped), comment containing a link (must get `nofollow ugc`), post with a fenced PHP block (highlighted in preview *and* on the front end), comment with a fenced block (class survives KSES), page with no code at all (Prism must **not** be enqueued), single image drop, multi-image drop, image paste, non-image drop (rejected), drop on a brand new unsaved post, drop as a user without `upload_files` (rejected server-side).