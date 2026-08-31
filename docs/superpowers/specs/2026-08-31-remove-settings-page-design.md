# Removing the Settings Page: Design

Date: 2026-08-31
Status: approved, implementing directly (no separate plan document)
Supersedes: the `## Settings` section of `specs.md` and of
`2026-08-22-ozh-markdown-design.md`

## 1. Overview

The plugin loses its settings page and, with it, every option it ever stored. Prism line
numbers, until now a toggle defaulting to off, become unconditional.

This is subtraction, not a move: no option is relocated to a filter, a constant or a
`wp-config.php` define. After this change the plugin calls `get_option()`,
`add_option()` and `register_setting()` nowhere at all, and holds no row in the options
table.

The reasoning is the one already written into `specs.md` for post types, applied to the
rest of the plugin: "the plugin applies everywhere by design", and fine-grained control is
a filter for the one person who needs it rather than a checkbox for everyone who does not.
Three toggles, two of which drove a feature that was never built, were not paying for the
page that carried them.

## 2. Decisions taken during brainstorming

| # | Question | Decision |
|---|---|---|
| 1 | Page or no page | No page. The whole options layer goes, not just its UI. |
| 2 | Line numbers | On, always. Not a constant, not a filter, not a toggle. |
| 3 | Where do the options go | Nowhere. Nothing replaces them. |
| 4 | The read-only post type list | Goes with the page. `ozhmd_excluded_post_types` stays documented in `specs.md`, which is where someone changing it is already looking. |
| 5 | The existing `ozhmd_options` row | Deleted by hand on the one site that has one. No `uninstall.php` for an option that will never be written again. See section 6. |
| 6 | The two comment toggles | Removed. This decides part of an unbuilt feature by omission, so the decision is written down rather than left implied. See section 5. |

## 3. What is deleted

- `includes/class-ozhmd-settings.php`, entirely.
- In `ozh-markdown.php`: the `require_once` for that file, the `$settings` construction,
  its injection into `Ozhmd_Editor_UI` and `Ozhmd_Frontend`, and both `ozhmd_activate()`
  and its `register_activation_hook()` call. That hook existed only to seed
  `ozhmd_options`; nothing else in the plugin needs activation work, so an empty
  activation callback would be worse than none.
- In `includes/ozhmd-functions.php`: `ozhmd_default_options()` and
  `ozhmd_sanitize_options()`.
- `tests/unit/OptionsTest.php` and `tests/integration/SettingsTest.php`.

## 4. What changes shape

### 4.1 `ozhmd_prism_pre_classes()`

Loses its parameter:

```php
function ozhmd_prism_pre_classes(): array {
	return ['match-braces', 'line-numbers'];
}
```

The function survives the loss of the thing it was branching on, and deliberately. It has
two callers that must not drift: `Ozhmd_Frontend::add_prism_classes()` decorates published
content, and the `preClasses` payload in `Ozhmd_Editor_UI::enqueue_assets()` decorates the
editor's preview pane. A preview that highlighted differently from the published page
would be a worse bug than the duplication is a cost, so the list stays defined once.

Inlining the array at both call sites, or reducing the function to a constant, would both
lose that. It stays a function because `ozhmd_add_pre_classes()` takes a `string[]` and the
unit tests call it directly.

The vendored Prism bundle already ships the `line-numbers` plugin and its CSS
(`PRISM_PLUGINS` in `tools/fetch-vendor.sh`), so nothing under `assets/` changes.

### 4.2 Constructors

`Ozhmd_Editor_UI::__construct()` drops its third parameter and the `$settings` property,
keeping `$post_types` and `$storage`.

`Ozhmd_Frontend::__construct()` had exactly one job, storing the settings. It goes, along
with the property and the class DocBlock line describing it, and `Ozhmd_Frontend` is
constructed with no arguments. The `$enqueued` flag is initialised at its declaration
already, so nothing is left for a constructor to do.

## 5. Consequence for Part 3

`comments_enabled` and `comment_preview` described a feature that does not exist yet.
Deleting them settles two things in advance, and both are recorded in `specs.md` rather
than rediscovered when Part 3 is built:

- Markdown for comments is unconditional. When comments land, they are Markdown.
- The front-end live preview under the comment form is a design question for Part 3, not a
  runtime setting. It either ships or it does not.

The security requirements for the comment path are untouched by any of this. Nothing in
section 3 of `specs.md` depended on a toggle.

## 6. The orphaned option row

An `ozhmd_options` row exists on any site that activated a previous build. Once the
activation hook is gone, nothing reads it, writes it, or removes it.

It is deleted by hand on the one site that has one:

```
wp option delete ozhmd_options
```

Shipping `uninstall.php` to clean up an option the plugin no longer creates would be
permanent code serving a single row on a single unreleased install. The row is inert: it
costs one autoloaded entry until it is deleted, and nothing reads it in the meantime.

## 7. Documentation

`specs.md` is the living document and is updated:

- The `## Settings` section is rewritten. Instead of listing three toggles it states that
  the plugin has no settings page and no options, gives the reasoning from section 1, and
  points at `ozhmd_excluded_post_types` as the one supported point of control.
- Under `### Prism integration details`, the line reading "Prism's Line Numbers plugin is
  optional; if included, keep it behind a settings toggle (default off)" becomes a
  statement that line numbers are always on.
- `## Suggested build order` step 1 mentions scaffolding a settings page; that reference
  goes.

`docs/superpowers/specs/2026-08-22-ozh-markdown-design.md` and
`docs/superpowers/plans/2026-08-23-ozh-markdown-01-foundation.md` are dated records of what
was decided and done at the time. They are not edited. This document supersedes their
settings sections and says so in its header.

## 8. Tests

Two files are deleted (section 3). The rest are adjusted:

- `tests/unit/PreClassesTest.php`: `test_line_numbers_are_opt_in()` is replaced by
  `test_line_numbers_are_always_on()`, asserting
  `['match-braces', 'line-numbers'] === ozhmd_prism_pre_classes()`. The name matters: the
  test now guards a decision rather than a mechanism, so it should fail loudly if someone
  reintroduces a condition. The other tests in the file exercise
  `ozhmd_add_pre_classes()` with explicit class lists and are untouched.
- `tests/integration/BootstrapTest.php`: `test_activation_seeds_the_default_options()` and
  `test_activation_does_not_overwrite_existing_options()` are deleted, and the
  `ozhmd_default_options()` assertion inside `test_pure_functions_are_available()` is
  dropped.
- `tests/integration/EditorUiTest.php` and `tests/integration/FrontendTest.php`: the
  constructor calls lose their `Ozhmd_Settings` argument.

No new test is needed for the absence of the page. `BootstrapTest` already asserts which
classes the plugin declares, and a `class_exists('Ozhmd_Settings')` assertion inverted into
a negative would only restate the deletion.

Verification is all three suites: the unit suite (no WordPress bootstrap), the integration
suite (`wp-phpunit`), and the node JS tests. The JS is untouched by this change and is run
to confirm exactly that.
