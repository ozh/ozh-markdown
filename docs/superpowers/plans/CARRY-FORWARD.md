# Carry-forward notes for plans 2 to 4

Written at the end of plan 1 (foundation). These are not defects in the
committed code. They are assumptions the next plans must honour, and traps
that will cost real debugging time if rediscovered the hard way.

## For plan 2 (post path and editor)

**The save path must go through WordPress's KSES gate.** Plan 1's trusted
converter now empties `disallowed_raw_html`, so `<script>` and `<iframe>`
survive conversion. That is correct and necessary, since legacy posts contain
embeds. It is safe **because** WordPress runs `content_save_pre` and
`wp_filter_post_kses` for any user lacking `unfiltered_html`.

So: write `post_content` with `wp_update_post()`, never with a direct `$wpdb`
query, and never call `kses_remove_filters()` broadly. If the save path
bypasses that filter, a Contributor's `[x](javascript:...)` or raw `<script>`
reaches `post_content` intact. Worth an explicit integration test: a
Contributor saving hostile Markdown, asserting the stored HTML is sanitized.

**`ozhmd_bootstrap()` needs restructuring.** It currently constructs the
converter, passes it to `do_action('ozhmd_loaded')` and drops it. Plan 2's
storage and revisions services both need it injected. Consider dropping the
action while restructuring: it is not in the design, has no consumer, and
hands third parties an object exposing both trust levels. An action prefix is
a compatibility commitment once released.

**`register_post_meta()` is the first thing plan 2 calls.** `$wp_meta_keys` is
now restored by the harness, so that is covered.

**`Ozhmd_Post_Types` has no `register_hooks()` yet.** The
`use_block_editor_for_post_type` and `remove_post_type_support` wiring lands in
plan 2. Design section 16 flags that as needing an integration test rather than
an assumption, especially now that `classic-editor` is being deactivated and
this plugin becomes solely responsible for keeping Gutenberg off.

**`BootstrapTest::test_isolation_the_post_does_not_leak_into_the_next_test`
asserts the whole database contains exactly one post.** That makes it a
suite-wide canary the moment plan 2 starts creating posts. Expect it to be the
first test to fail if any rollback regresses. That is a feature, not a
nuisance.

## For plan 3 (front end, Prism, upload)

**`WP_Scripts` and `WP_Styles` are cloned shallowly.** The clone protects
`queue`, `done`, and newly registered handles. It does **not** protect against
in-place mutation of a handle that already existed at snapshot time:
`wp_script_add_data()`, `wp_style_add_data()` and `set_translations()` mutate
the `_WP_Dependency` object, which the snapshot and the live global still
share. If plan 3's conditional-Prism tests call any of those on a pre-existing
handle such as `jquery`, deepen the clone or the leak will be order dependent.

**Uploads are redirected to a scratch directory** by
`Ozhmd_Integration_Test_Case::ozhmd_redirect_uploads_to_scratch_dir()`. This
matters: `WP_CONTENT_DIR` deliberately points at the live blog's tree so theme
and plugin resolution works, so without the redirect `media_handle_upload()`
would leave orphan files in the production media directory. Anything else that
writes to disk needs the same treatment.

**`WP_REST_Server` state is not isolated.** Plan 3 registers the
`ozhmd/v1/upload` route and will want `rest_do_request()`. A simple global
restore will not cover it; handle it explicitly when the route lands.

**`$wp_rewrite` is not restored.** Currently harmless only by coincidence:
`WP_Post_Type::add_rewrite_rules()` requires `is_admin()` or a non-empty
permalink structure, and the harness has neither. Do not rely on that.

## For plan 4 (comments)

**Inject only the untrusted converter into the comments service.** `Ozhmd_Converter`
exposes both trust levels on one object, so the boundary currently reduces to
picking the right method name at each call site. The comments service has no
business being able to reach `to_html_trusted()`. Passing it the single
converter it needs restores the separation the design intended.

## General

**DDL escapes the transaction.** Any test creating or altering a table causes
an implicit commit in MariaDB, silently escaping the rollback. No plan needs a
custom table today.

**Admin includes are per-test, by design.** Call
`$this->require_admin_includes()` in tests touching the Settings API or meta
boxes. It is deliberately not in the bootstrap: loading admin includes globally
would mask production code that forgets to require them.
