# Ozh' Markdown Plugin: Foundation Implementation Plan (1 of 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the plugin skeleton, all three test harnesses, every pure function, and the two Markdown converters, so that later plans have a tested foundation to build on.

**Architecture:** Pure logic lives in prefixed free functions in a single WordPress-free file, tested by plain PHPUnit with no bootstrap. Anything with dependencies becomes a small class whose constructor only stores injected dependencies and whose hooks are registered by a separate `register_hooks()`. A single `ozhmd_bootstrap()` on `plugins_loaded` wires everything together.

**Tech Stack:** PHP 8.0+ (running on 8.5), `league/commonmark` 2.10, PHPUnit 11.5, `wp-phpunit` 7.1, `yoast/phpunit-polyfills` 4.0, Node's built-in `node:test`.

**Source design:** `docs/superpowers/specs/2026-08-22-ozh-markdown-design.md`. Read it before starting.

## Scope of this plan

This is plan 1 of 4. It delivers working, testable software on its own: an activatable plugin with a settings page and a fully tested conversion core.

Delivered here: repository scaffolding, Composer manifests, unit / integration / JS test harnesses, all pure functions from design §4, both converters from design §5, plugin bootstrap and activation, post type eligibility (the query half), and the settings page.

Explicitly deferred to later plans, so nothing below is a gap:

- Plan 2: post meta and save pipeline, legacy passthrough and opt-in conversion, revisions, disabling Gutenberg and TinyMCE, meta box, CodeMirror, preview, Prism in the preview pane.
- Plan 3: conditional front-end Prism, REST upload endpoint, drag / drop / paste.
- Plan 4: comment path, front-end comment preview, readme, manual test pass.

## Global Constraints

Every task's requirements implicitly include this section.

- **Naming, verbatim:** plugin name `Ozh' Markdown Plugin`; text domain and slug `ozh-markdown`; function prefix `ozhmd_`; class prefix `Ozhmd_`; filter and action prefix `ozhmd_`; post meta key `_ozhmd_markdown`; comment meta key `_ozhmd_markdown`; single option array `ozhmd_options`.
- **PHP floor:** 8.0. Do not use syntax newer than 8.0 (no `readonly` properties, no enums, no `json_validate()`).
- **Typed signatures everywhere:** parameter types, return types, nullable types (`?int`) wherever the code allows. `void` where nothing is returned.
- **Opening brace on the same line**, for functions, classes and control structures.
- **DocBlocks before every class, method and function**, with `@param` and `@return`. Code comments in English.
- **No inline section-header comments** like `// Helpers` above groups of functions. DocBlocks serve that purpose.
- **No `declare(strict_types=1)` in plugin files.** WordPress passes loosely typed values into hook callbacks (post IDs as numeric strings, for one), and coercive mode absorbs that. Test files may use it, since they control their own inputs.
- **Formatting:** tabs for indentation in PHP and JS. No spaces immediately inside parentheses. Files end with a trailing blank line.
- **Shared hosting assumptions:** no `shell_exec`, no assumption Composer runs on the production host, no write access outside uploads and the plugin directory.
- **No static state, no singletons.** No logic in constructors beyond storing injected dependencies.
- **Commits:** the repo owner's standing instruction is never to commit unless explicitly asked. Each task ends with the exact command and message to use, but **do not run `git commit`** unless the owner has asked for it in this session. Staging with `git add` is fine.

## File structure

| File | Responsibility | Task |
|---|---|---|
| `tools/dev.sh` | Run a command inside the `dev-web` container at the plugin root. | 1 |
| `composer.json` | Runtime dependency: `league/commonmark` only. | 1 |
| `tests/tools/composer.json` | Dev-only tooling. Its `vendor/` is gitignored and never ships. | 1 |
| `phpunit-unit.xml.dist` | Plain PHPUnit suite, no WordPress. | 1 |
| `tests/unit/bootstrap.php` | Loads autoloaders and the WordPress-free code. | 1 |
| `includes/ozhmd-functions.php` | Every pure function. Grows across tasks 1, 2, 3. | 1, 2, 3 |
| `assets/js/lib/ozhmd-upload-lib.js` | Pure JS helpers, browser global and CommonJS module. | 4 |
| `tests/js/upload-lib.test.js` | `node:test` suite for the above. | 4 |
| `includes/class-ozhmd-converter-factory.php` | Builds the two configured converters. | 5 |
| `includes/class-ozhmd-converter.php` | Holds the two injected converters. No configuration. | 5 |
| `ozh-markdown.php` | Plugin header, constants, requires, bootstrap, activation. | 6 |
| `phpunit-integration.xml.dist` | wp-phpunit suite. | 7 |
| `tests/integration/wp-tests-config.php` | Points the WordPress test suite at a dedicated database. | 7 |
| `tests/integration/bootstrap.php` | Loads the WordPress test library and the plugin. | 7 |
| `includes/class-ozhmd-post-types.php` | Eligibility query. Hook registration arrives in plan 2. | 8 |
| `includes/class-ozhmd-settings.php` | Options page. | 9 |

---

### Task 1: Dev wrapper, Composer manifests, unit harness, post type eligibility

**Files:**
- Create: `tools/dev.sh`
- Create: `composer.json`
- Create: `tests/tools/composer.json`
- Create: `phpunit-unit.xml.dist`
- Create: `tests/unit/bootstrap.php`
- Create: `includes/ozhmd-functions.php`
- Modify: `.gitignore`
- Test: `tests/unit/PostTypesTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `ozhmd_default_excluded_post_types(): array`, `ozhmd_is_excluded_post_type(string $post_type, array $excluded): bool`, `ozhmd_eligible_post_types(array $editor_post_types, array $excluded): array`. Also the command `./tools/dev.sh <cmd>`, used by every later task to run PHP inside the container.

- [ ] **Step 1: Create the container wrapper**

`tools/dev.sh`:

```bash
#!/usr/bin/env bash
#
# Run a command inside the dev-web container, at the plugin root.
# Usage: ./tools/dev.sh composer install
#        ./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-unit.xml.dist

set -euo pipefail

PLUGIN_DIR=/var/www/html/planetozh.com/blog/wp-content/plugins/ozh-markdown

exec docker exec -i dev-web bash --login -c "cd ${PLUGIN_DIR} && $(printf '%q ' "$@")"
```

Then make it executable:

```bash
chmod +x tools/dev.sh
```

- [ ] **Step 2: Verify the wrapper reaches the plugin directory**

Run: `./tools/dev.sh pwd`
Expected: `/var/www/html/planetozh.com/blog/wp-content/plugins/ozh-markdown`

- [ ] **Step 3: Create the Composer manifests**

`composer.json` (runtime only, this is what ships):

```json
{
	"name": "ozh/ozh-markdown",
	"description": "Minimal split-pane Markdown editor for WordPress.",
	"type": "wordpress-plugin",
	"license": "GPL-2.0-or-later",
	"require": {
		"php": ">=8.0",
		"league/commonmark": "^2.10"
	},
	"config": {
		"optimize-autoloader": true,
		"sort-packages": true
	}
}
```

`tests/tools/composer.json` (dev only, never ships, `vendor/` gitignored):

```json
{
	"name": "ozh/ozh-markdown-devtools",
	"description": "Development-only tooling for Ozh' Markdown Plugin. Never shipped.",
	"license": "GPL-2.0-or-later",
	"require": {
		"phpunit/phpunit": "^11.5",
		"yoast/phpunit-polyfills": "^4.0",
		"wp-phpunit/wp-phpunit": "^7.1"
	},
	"config": {
		"allow-plugins": false
	}
}
```

- [ ] **Step 4: Install both dependency sets**

Run:

```bash
./tools/dev.sh composer install --no-dev --optimize-autoloader
./tools/dev.sh composer install -d tests/tools
```

Expected: `vendor/league/commonmark` exists; `tests/tools/vendor/bin/phpunit` exists.

Verify: `./tools/dev.sh tests/tools/vendor/bin/phpunit --version`
Expected: `PHPUnit 11.5.x`

- [ ] **Step 5: Add the PHPUnit cache directory to .gitignore**

In `.gitignore`, replace the line `/tests/.phpunit.result.cache` with:

```
/tests/.phpunit.cache/
```

- [ ] **Step 6: Create the unit test configuration and bootstrap**

`phpunit-unit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
		 xsi:noNamespaceSchemaLocation="tests/tools/vendor/phpunit/phpunit/phpunit.xsd"
		 bootstrap="tests/unit/bootstrap.php"
		 cacheDirectory="tests/.phpunit.cache"
		 colors="true"
		 failOnWarning="true"
		 failOnRisky="true"
		 beStrictAboutOutputDuringTests="true">
	<testsuites>
		<testsuite name="unit">
			<directory>tests/unit</directory>
		</testsuite>
	</testsuites>
	<source>
		<include>
			<directory suffix=".php">includes</directory>
		</include>
	</source>
</phpunit>
```

`tests/unit/bootstrap.php`:

```php
<?php
/**
 * Bootstrap for the plain PHPUnit suite.
 *
 * Loads the development autoloader, the runtime autoloader, and the plugin's
 * WordPress-free code. No WordPress bootstrap and no database: everything
 * reachable from here must be pure PHP.
 */

$ozhmd_dev_autoload = __DIR__ . '/../tools/vendor/autoload.php';

if (!file_exists($ozhmd_dev_autoload)) {
	fwrite(STDERR, "Dev tooling missing. Run: ./tools/dev.sh composer install -d tests/tools\n");
	exit(1);
}

require_once $ozhmd_dev_autoload;
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/ozhmd-functions.php';
```

- [ ] **Step 7: Write the failing test**

`tests/unit/PostTypesTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PostTypesTest extends TestCase {

	public function test_default_exclusions_cover_every_post_type_named_in_the_spec(): void {
		$excluded = ozhmd_default_excluded_post_types();

		foreach ([
			'attachment',
			'revision',
			'nav_menu_item',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_navigation',
			'wp_global_styles',
			'customize_changeset',
			'oembed_cache',
			'user_request',
		] as $post_type) {
			$this->assertContains($post_type, $excluded);
		}
	}

	public function test_is_excluded_post_type_finds_a_listed_type(): void {
		$this->assertTrue(ozhmd_is_excluded_post_type('revision', ['post', 'revision']));
	}

	public function test_is_excluded_post_type_rejects_an_unlisted_type(): void {
		$this->assertFalse(ozhmd_is_excluded_post_type('post', ['revision']));
	}

	public function test_is_excluded_post_type_is_case_sensitive(): void {
		$this->assertFalse(ozhmd_is_excluded_post_type('Revision', ['revision']));
	}

	public function test_is_excluded_post_type_does_not_coerce_types(): void {
		$this->assertFalse(ozhmd_is_excluded_post_type('0', ['revision']));
	}

	public function test_eligible_post_types_removes_exclusions(): void {
		$this->assertSame(
			['post', 'page'],
			ozhmd_eligible_post_types(['post', 'revision', 'page', 'wp_block'], ['revision', 'wp_block'])
		);
	}

	public function test_eligible_post_types_preserves_order_and_reindexes_keys(): void {
		$eligible = ozhmd_eligible_post_types(['page', 'revision', 'post'], ['revision']);

		$this->assertSame([0, 1], array_keys($eligible));
		$this->assertSame(['page', 'post'], $eligible);
	}

	public function test_eligible_post_types_with_nothing_excluded_returns_everything(): void {
		$this->assertSame(['post', 'page'], ozhmd_eligible_post_types(['post', 'page'], []));
	}

	public function test_eligible_post_types_with_everything_excluded_returns_empty(): void {
		$this->assertSame([], ozhmd_eligible_post_types(['post'], ['post']));
	}
}
```

- [ ] **Step 8: Run the test to verify it fails**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-unit.xml.dist`
Expected: FAIL, with errors like `Call to undefined function ozhmd_default_excluded_post_types()`. Note the bootstrap will also fail on the missing `includes/ozhmd-functions.php`, which is expected at this point.

- [ ] **Step 9: Write the minimal implementation**

`includes/ozhmd-functions.php`:

```php
<?php
/**
 * Pure helper functions.
 *
 * Nothing in this file may call a WordPress function, read a superglobal, echo
 * output, or depend on request context. Everything here takes arguments and
 * returns a value, so it can be tested without a WordPress bootstrap.
 *
 * There is deliberately no ABSPATH guard here. The file declares functions and
 * has no side effects, so loading it directly does nothing, and a guard would
 * stop the unit test bootstrap from loading it without WordPress.
 */

/**
 * Post types that must never receive the Markdown editor, even when they
 * support the 'editor' feature.
 *
 * @return string[] Post type names.
 */
function ozhmd_default_excluded_post_types(): array {
	return [
		'attachment',
		'revision',
		'nav_menu_item',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'wp_global_styles',
		'customize_changeset',
		'oembed_cache',
		'user_request',
	];
}

/**
 * Tell whether a post type appears in an exclusion list.
 *
 * @param string   $post_type Post type name to test.
 * @param string[] $excluded  Excluded post type names.
 * @return bool True when the post type is excluded.
 */
function ozhmd_is_excluded_post_type(string $post_type, array $excluded): bool {
	return in_array($post_type, $excluded, true);
}

/**
 * Remove excluded post types from a list of candidates.
 *
 * @param string[] $editor_post_types Post types supporting the editor feature.
 * @param string[] $excluded          Excluded post type names.
 * @return string[] Eligible post types, original order preserved, keys reindexed.
 */
function ozhmd_eligible_post_types(array $editor_post_types, array $excluded): array {
	$eligible = [];

	foreach ($editor_post_types as $post_type) {
		if (!ozhmd_is_excluded_post_type($post_type, $excluded)) {
			$eligible[] = $post_type;
		}
	}

	return $eligible;
}
```

- [ ] **Step 10: Run the test to verify it passes**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-unit.xml.dist`
Expected: PASS, 9 tests, 0 failures.

- [ ] **Step 11: Stage the work**

```bash
git add tools/dev.sh composer.json composer.lock vendor tests/tools/composer.json \
        phpunit-unit.xml.dist tests/unit/bootstrap.php tests/unit/PostTypesTest.php \
        includes/ozhmd-functions.php .gitignore
```

Commit message to use once the owner asks for commits:

```
feat: scaffold plugin, test harness and post type eligibility
```

---

### Task 2: Code block detection and language class filtering

**Files:**
- Modify: `includes/ozhmd-functions.php` (append)
- Test: `tests/unit/CodeBlockTest.php`
- Test: `tests/unit/LanguageClassTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `ozhmd_content_has_code_block(string $html): bool`, `ozhmd_is_valid_language_class(string $class): bool`, `ozhmd_filter_language_classes(string $html): string`.

- [ ] **Step 1: Write the failing tests**

`tests/unit/CodeBlockTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CodeBlockTest extends TestCase {

	public function test_detects_a_plain_code_block(): void {
		$this->assertTrue(ozhmd_content_has_code_block('<pre><code>echo 1;</code></pre>'));
	}

	public function test_detects_a_code_block_with_a_language_class(): void {
		$this->assertTrue(ozhmd_content_has_code_block('<pre><code class="language-php">echo 1;</code></pre>'));
	}

	public function test_detects_a_code_block_with_attributes_on_the_pre_tag(): void {
		$this->assertTrue(ozhmd_content_has_code_block('<pre class="line-numbers"><code>x</code></pre>'));
	}

	public function test_detects_a_code_block_across_whitespace_and_newlines(): void {
		$this->assertTrue(ozhmd_content_has_code_block("<pre>\n\t<code>x</code></pre>"));
	}

	public function test_detection_is_case_insensitive(): void {
		$this->assertTrue(ozhmd_content_has_code_block('<PRE><CODE>x</CODE></PRE>'));
	}

	public function test_ignores_a_pre_without_a_code_child(): void {
		$this->assertFalse(ozhmd_content_has_code_block('<pre>just preformatted</pre>'));
	}

	public function test_ignores_inline_code(): void {
		$this->assertFalse(ozhmd_content_has_code_block('<p>use <code>echo</code> here</p>'));
	}

	public function test_ignores_a_tag_merely_starting_with_pre(): void {
		$this->assertFalse(ozhmd_content_has_code_block('<preface><code>x</code></preface>'));
	}

	public function test_empty_content_has_no_code_block(): void {
		$this->assertFalse(ozhmd_content_has_code_block(''));
	}
}
```

`tests/unit/LanguageClassTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LanguageClassTest extends TestCase {

	public function test_accepts_a_simple_language_class(): void {
		$this->assertTrue(ozhmd_is_valid_language_class('language-php'));
	}

	public function test_accepts_digits_plus_and_hyphen(): void {
		$this->assertTrue(ozhmd_is_valid_language_class('language-c++'));
		$this->assertTrue(ozhmd_is_valid_language_class('language-objective-c'));
		$this->assertTrue(ozhmd_is_valid_language_class('language-h2'));
	}

	public function test_rejects_an_empty_language_name(): void {
		$this->assertFalse(ozhmd_is_valid_language_class('language-'));
	}

	public function test_rejects_a_different_prefix(): void {
		$this->assertFalse(ozhmd_is_valid_language_class('lang-php'));
		$this->assertFalse(ozhmd_is_valid_language_class('Language-php'));
	}

	public function test_rejects_uppercase_in_the_language_name(): void {
		$this->assertFalse(ozhmd_is_valid_language_class('language-PHP'));
	}

	public function test_rejects_an_injected_attribute(): void {
		$this->assertFalse(ozhmd_is_valid_language_class('language-php" onmouseover="alert(1)'));
		$this->assertFalse(ozhmd_is_valid_language_class('language-php onmouseover=alert(1)'));
	}

	public function test_rejects_a_trailing_newline_bypass(): void {
		// PCRE's $ matches before a trailing newline, so the pattern must be
		// anchored with \z or a crafted value slips through.
		$this->assertFalse(ozhmd_is_valid_language_class("language-php\n"));
		$this->assertFalse(ozhmd_is_valid_language_class("language-php\nevil"));
	}

	public function test_rejects_leading_or_trailing_whitespace(): void {
		$this->assertFalse(ozhmd_is_valid_language_class(' language-php'));
		$this->assertFalse(ozhmd_is_valid_language_class('language-php '));
	}

	public function test_keeps_a_valid_class_untouched(): void {
		$this->assertSame(
			'<code class="language-php">x</code>',
			ozhmd_filter_language_classes('<code class="language-php">x</code>')
		);
	}

	public function test_drops_a_hostile_token_but_keeps_the_language(): void {
		$this->assertSame(
			'<code class="language-php">x</code>',
			ozhmd_filter_language_classes('<code class="language-php evil">x</code>')
		);
	}

	public function test_removes_the_attribute_entirely_when_nothing_survives(): void {
		$this->assertSame(
			'<code>x</code>',
			ozhmd_filter_language_classes('<code class="evil">x</code>')
		);
	}

	public function test_filters_pre_and_code_independently(): void {
		$this->assertSame(
			'<pre><code class="language-js">x</code></pre>',
			ozhmd_filter_language_classes('<pre class="line-numbers"><code class="language-js">x</code></pre>')
		);
	}

	public function test_handles_single_quoted_attributes(): void {
		$this->assertSame(
			'<code class="language-php">x</code>',
			ozhmd_filter_language_classes("<code class='language-php evil'>x</code>")
		);
	}

	public function test_leaves_other_tags_alone(): void {
		$this->assertSame(
			'<div class="anything"><span class="x">y</span></div>',
			ozhmd_filter_language_classes('<div class="anything"><span class="x">y</span></div>')
		);
	}

	public function test_leaves_non_class_attributes_alone(): void {
		$this->assertSame(
			'<code id="a" class="language-php">x</code>',
			ozhmd_filter_language_classes('<code id="a" class="language-php">x</code>')
		);
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-unit.xml.dist`
Expected: FAIL with `Call to undefined function ozhmd_content_has_code_block()`.

- [ ] **Step 3: Write the minimal implementation**

Append to `includes/ozhmd-functions.php`:

```php
/**
 * Tell whether rendered HTML contains a fenced code block.
 *
 * Matches an opening <pre> followed by a <code>, tolerating attributes on
 * either tag and any whitespace between them. Deliberately permissive: a false
 * positive costs one stylesheet, a false negative costs unhighlighted code.
 *
 * @param string $html Rendered HTML to inspect.
 * @return bool True when a code block is present.
 */
function ozhmd_content_has_code_block(string $html): bool {
	return 1 === preg_match('#<pre\b[^>]*>\s*<code\b#i', $html);
}

/**
 * Tell whether a class token is a Prism language class.
 *
 * Anchored with \z rather than $, because $ also matches immediately before a
 * trailing newline, which would let a crafted value through.
 *
 * @param string $class Single class token, already split on whitespace.
 * @return bool True when the token matches language-<slug>.
 */
function ozhmd_is_valid_language_class(string $class): bool {
	return 1 === preg_match('/\A language- [a-z0-9+-]+ \z/x', $class);
}

/**
 * Strip every class token that is not a language class from <pre> and <code>.
 *
 * Belt and braces: the untrusted converter already strips raw HTML, so the only
 * class attributes reaching this function are ones CommonMark generated from
 * fence info strings. It runs anyway, because KSES can allow an attribute but
 * cannot pattern-match its value.
 *
 * @param string $html HTML to filter.
 * @return string HTML with only language-* classes left on pre and code tags.
 */
function ozhmd_filter_language_classes(string $html): string {
	$filtered = preg_replace_callback(
		'#<(pre|code)\b([^>]*)>#i',
		static function (array $tag_match): string {
			$attributes = preg_replace_callback(
				'/\bclass\s*=\s*(["\'])(.*?)\1/is',
				static function (array $class_match): string {
					$tokens = preg_split('/\s+/', trim($class_match[2]), -1, PREG_SPLIT_NO_EMPTY);
					$kept   = array_filter($tokens ?: [], 'ozhmd_is_valid_language_class');

					return [] === $kept ? '' : 'class="' . implode(' ', $kept) . '"';
				},
				$tag_match[2]
			);

			return '<' . $tag_match[1] . rtrim((string) $attributes) . '>';
		},
		$html
	);

	return null === $filtered ? $html : $filtered;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-unit.xml.dist`
Expected: PASS, 33 tests total (9 from Task 1, 9 in CodeBlockTest, 15 in LanguageClassTest), 0 failures.

- [ ] **Step 5: Stage the work**

```bash
git add includes/ozhmd-functions.php tests/unit/CodeBlockTest.php tests/unit/LanguageClassTest.php
```

Commit message to use once the owner asks for commits:

```
feat: add code block detection and language class filtering
```

---

### Task 3: Post state, upload helpers and option defaults

**Files:**
- Modify: `includes/ozhmd-functions.php` (append)
- Test: `tests/unit/PostStateTest.php`
- Test: `tests/unit/UploadHelpersTest.php`
- Test: `tests/unit/OptionsTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `ozhmd_post_state(?string $markdown): string`, `ozhmd_alt_from_filename(string $filename): string`, `ozhmd_build_markdown_image(string $alt, string $url): string`, `ozhmd_upload_placeholder(string $filename): string`, `ozhmd_is_allowed_image_mime(string $mime, array $allowed_mimes): bool`, `ozhmd_default_options(): array`, `ozhmd_sanitize_options(array $input, array $defaults): array`.

- [ ] **Step 1: Write the failing tests**

`tests/unit/PostStateTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PostStateTest extends TestCase {

	public function test_absent_markdown_means_legacy(): void {
		$this->assertSame('legacy', ozhmd_post_state(null));
	}

	public function test_present_markdown_means_markdown(): void {
		$this->assertSame('markdown', ozhmd_post_state('# Hello'));
	}

	public function test_empty_markdown_still_means_markdown(): void {
		// A converted post whose body was emptied is not a legacy post. Only a
		// missing meta key means legacy.
		$this->assertSame('markdown', ozhmd_post_state(''));
	}
}
```

`tests/unit/UploadHelpersTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UploadHelpersTest extends TestCase {

	public function test_alt_strips_the_extension(): void {
		$this->assertSame('photo', ozhmd_alt_from_filename('photo.png'));
	}

	public function test_alt_strips_a_leading_path(): void {
		$this->assertSame('my shot', ozhmd_alt_from_filename('a/b/my shot.jpeg'));
	}

	public function test_alt_of_an_extensionless_name_is_the_name(): void {
		$this->assertSame('noext', ozhmd_alt_from_filename('noext'));
	}

	public function test_alt_strips_only_the_last_extension(): void {
		$this->assertSame('archive.tar', ozhmd_alt_from_filename('archive.tar.gz'));
	}

	public function test_builds_a_plain_markdown_image(): void {
		$this->assertSame(
			'![cat](https://example.com/cat.png)',
			ozhmd_build_markdown_image('cat', 'https://example.com/cat.png')
		);
	}

	public function test_escapes_brackets_in_the_alt_text(): void {
		$this->assertSame(
			'![a\\]b](https://example.com/x.png)',
			ozhmd_build_markdown_image('a]b', 'https://example.com/x.png')
		);
	}

	public function test_encodes_parentheses_and_spaces_in_the_url(): void {
		$this->assertSame(
			'![x](https://example.com/a%20%281%29.png)',
			ozhmd_build_markdown_image('x', 'https://example.com/a (1).png')
		);
	}

	public function test_builds_the_upload_placeholder(): void {
		$this->assertSame('![Uploading cat.png...]()', ozhmd_upload_placeholder('cat.png'));
	}

	public function test_allows_an_image_mime_the_site_permits(): void {
		$this->assertTrue(ozhmd_is_allowed_image_mime('image/png', ['png' => 'image/png']));
	}

	public function test_ignores_the_extension_keys_of_the_allowed_map(): void {
		$this->assertTrue(
			ozhmd_is_allowed_image_mime('image/jpeg', ['jpg|jpeg|jpe' => 'image/jpeg'])
		);
	}

	public function test_rejects_an_image_mime_the_site_does_not_permit(): void {
		$this->assertFalse(ozhmd_is_allowed_image_mime('image/svg+xml', ['png' => 'image/png']));
	}

	public function test_rejects_a_non_image_even_when_permitted(): void {
		$this->assertFalse(ozhmd_is_allowed_image_mime('application/pdf', ['pdf' => 'application/pdf']));
	}

	public function test_mime_comparison_is_case_insensitive(): void {
		$this->assertTrue(ozhmd_is_allowed_image_mime('IMAGE/PNG', ['png' => 'image/png']));
	}

	public function test_rejects_a_mime_merely_containing_image(): void {
		$this->assertFalse(
			ozhmd_is_allowed_image_mime('application/image/png', ['x' => 'application/image/png'])
		);
	}

	public function test_rejects_everything_when_nothing_is_allowed(): void {
		$this->assertFalse(ozhmd_is_allowed_image_mime('image/png', []));
	}
}
```

`tests/unit/OptionsTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase {

	public function test_defaults_match_the_spec(): void {
		$this->assertSame(
			[
				'comments_enabled'   => true,
				'comment_preview'    => false,
				'prism_line_numbers' => false,
			],
			ozhmd_default_options()
		);
	}

	public function test_sanitize_drops_unknown_keys(): void {
		$clean = ozhmd_sanitize_options(['evil' => 'yes'], ozhmd_default_options());

		$this->assertArrayNotHasKey('evil', $clean);
	}

	public function test_sanitize_always_returns_every_known_key(): void {
		$clean = ozhmd_sanitize_options([], ozhmd_default_options());

		$this->assertSame(
			['comments_enabled', 'comment_preview', 'prism_line_numbers'],
			array_keys($clean)
		);
	}

	public function test_a_missing_key_becomes_false(): void {
		// An unchecked checkbox is simply absent from the payload.
		$clean = ozhmd_sanitize_options([], ozhmd_default_options());

		$this->assertFalse($clean['comments_enabled']);
	}

	public function test_checkbox_values_become_true(): void {
		$clean = ozhmd_sanitize_options(['comments_enabled' => '1'], ozhmd_default_options());

		$this->assertTrue($clean['comments_enabled']);
	}

	public function test_a_zero_string_becomes_false(): void {
		$clean = ozhmd_sanitize_options(['comments_enabled' => '0'], ozhmd_default_options());

		$this->assertFalse($clean['comments_enabled']);
	}

	public function test_every_value_is_a_real_boolean(): void {
		$clean = ozhmd_sanitize_options(['comment_preview' => 'on'], ozhmd_default_options());

		foreach ($clean as $value) {
			$this->assertIsBool($value);
		}
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-unit.xml.dist`
Expected: FAIL with `Call to undefined function ozhmd_post_state()`.

- [ ] **Step 3: Write the minimal implementation**

Append to `includes/ozhmd-functions.php`:

```php
/**
 * Editing state of a post, derived from its stored Markdown.
 *
 * A post with no _ozhmd_markdown meta is legacy and must never have its
 * post_content rewritten. An empty string is not the same as a missing key: it
 * means a converted post whose body was emptied.
 *
 * @param string|null $markdown Value of the _ozhmd_markdown meta, null when absent.
 * @return string Either 'legacy' or 'markdown'.
 */
function ozhmd_post_state(?string $markdown): string {
	return null === $markdown ? 'legacy' : 'markdown';
}

/**
 * Derive image alt text from an uploaded file name.
 *
 * @param string $filename Original file name, with or without a path.
 * @return string File name without its directory or its last extension.
 */
function ozhmd_alt_from_filename(string $filename): string {
	return pathinfo($filename, PATHINFO_FILENAME);
}

/**
 * Build a Markdown image tag.
 *
 * Escapes the characters that would otherwise terminate the alt text or the
 * URL, so a hostile file name cannot break out of the tag.
 *
 * @param string $alt Alt text.
 * @param string $url Image URL.
 * @return string Markdown image tag.
 */
function ozhmd_build_markdown_image(string $alt, string $url): string {
	$safe_alt = str_replace(['[', ']'], ['\[', '\]'], $alt);
	$safe_url = str_replace(['(', ')', ' '], ['%28', '%29', '%20'], $url);

	return '![' . $safe_alt . '](' . $safe_url . ')';
}

/**
 * Build the in-flight upload placeholder inserted at the cursor.
 *
 * Must stay byte-identical to buildPlaceholder() in ozhmd-upload-lib.js.
 *
 * @param string $filename Original file name.
 * @return string Placeholder Markdown.
 */
function ozhmd_upload_placeholder(string $filename): string {
	return '![Uploading ' . $filename . '...]()';
}

/**
 * Tell whether a MIME type may be uploaded as an image.
 *
 * True only when the type is an image and the site already allows it. Never
 * widens the site's allowed set.
 *
 * @param string   $mime          MIME type to test.
 * @param string[] $allowed_mimes Allowed types, as returned by get_allowed_mime_types().
 * @return bool True when the upload may proceed.
 */
function ozhmd_is_allowed_image_mime(string $mime, array $allowed_mimes): bool {
	$mime = strtolower($mime);

	if (0 !== strpos($mime, 'image/')) {
		return false;
	}

	return in_array($mime, array_map('strtolower', array_values($allowed_mimes)), true);
}

/**
 * Default plugin options.
 *
 * @return array<string, bool> Option keys mapped to their default values.
 */
function ozhmd_default_options(): array {
	return [
		'comments_enabled'   => true,
		'comment_preview'    => false,
		'prism_line_numbers' => false,
	];
}

/**
 * Coerce a submitted settings payload to the known option shape.
 *
 * The defaults array is used only as the whitelist of known keys. Unknown keys
 * are dropped and absent keys become false, because an unchecked checkbox is
 * simply missing from the payload rather than present and empty.
 *
 * @param array<string, mixed> $input    Raw submitted values.
 * @param array<string, bool>  $defaults Known option keys and their defaults.
 * @return array<string, bool> Sanitized options, one entry per known key.
 */
function ozhmd_sanitize_options(array $input, array $defaults): array {
	$clean = [];

	foreach ($defaults as $key => $default) {
		$clean[$key] = array_key_exists($key, $input) && (bool) $input[$key];
	}

	return $clean;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-unit.xml.dist`
Expected: PASS, 58 tests total (33 from earlier tasks, 3 in PostStateTest, 15 in UploadHelpersTest, 7 in OptionsTest), 0 failures.

- [ ] **Step 5: Stage the work**

```bash
git add includes/ozhmd-functions.php tests/unit/PostStateTest.php \
        tests/unit/UploadHelpersTest.php tests/unit/OptionsTest.php
```

Commit message to use once the owner asks for commits:

```
feat: add post state, upload helpers and option sanitization
```

---

### Task 4: JS helper library and node:test harness

**Files:**
- Create: `assets/js/lib/ozhmd-upload-lib.js`
- Test: `tests/js/upload-lib.test.js`

**Interfaces:**
- Consumes: nothing.
- Produces: `window.ozhmdUploadLib` in the browser and the CommonJS module `assets/js/lib/ozhmd-upload-lib.js` in Node, both exposing `buildPlaceholder(filename)`, `buildMarkdownImage(alt, url)`, `replacePlaceholder(haystack, needle, replacement)`, `isImageFile(type)`. Plan 3's `upload.js` consumes these.

Note: this task runs on the **host**, not in the container. The container has no Node.

- [ ] **Step 1: Write the failing test**

`tests/js/upload-lib.test.js`:

```js
const test = require('node:test');
const assert = require('node:assert');
const lib = require('../../assets/js/lib/ozhmd-upload-lib.js');

test('buildPlaceholder matches the PHP helper byte for byte', () => {
	assert.strictEqual(lib.buildPlaceholder('cat.png'), '![Uploading cat.png...]()');
});

test('buildMarkdownImage builds a plain image', () => {
	assert.strictEqual(
		lib.buildMarkdownImage('cat', 'https://example.com/cat.png'),
		'![cat](https://example.com/cat.png)'
	);
});

test('buildMarkdownImage escapes brackets in the alt text', () => {
	assert.strictEqual(
		lib.buildMarkdownImage('a]b', 'https://example.com/x.png'),
		'![a\\]b](https://example.com/x.png)'
	);
});

test('buildMarkdownImage encodes parentheses and spaces in the url', () => {
	assert.strictEqual(
		lib.buildMarkdownImage('x', 'https://example.com/a (1).png'),
		'![x](https://example.com/a%20%281%29.png)'
	);
});

test('replacePlaceholder replaces the first occurrence', () => {
	assert.strictEqual(lib.replacePlaceholder('a X b X c', 'X', 'Y'), 'a Y b X c');
});

test('replacePlaceholder leaves the text alone when the needle is absent', () => {
	assert.strictEqual(lib.replacePlaceholder('a b c', 'X', 'Y'), 'a b c');
});

test('replacePlaceholder treats regex metacharacters literally', () => {
	// A file named "a+b(1).png" produces a placeholder full of regex
	// metacharacters. Treating it as a pattern would corrupt the document.
	const placeholder = lib.buildPlaceholder('a+b(1).png');
	const text = 'before ' + placeholder + ' after';

	assert.strictEqual(
		lib.replacePlaceholder(text, placeholder, '![a+b(1)](u)'),
		'before ![a+b(1)](u) after'
	);
});

test('replacePlaceholder does not interpret $& in the replacement', () => {
	assert.strictEqual(lib.replacePlaceholder('a X b', 'X', '$&'), 'a $& b');
});

test('isImageFile accepts image types', () => {
	assert.strictEqual(lib.isImageFile('image/png'), true);
	assert.strictEqual(lib.isImageFile('IMAGE/PNG'), true);
});

test('isImageFile rejects non image types', () => {
	assert.strictEqual(lib.isImageFile('text/plain'), false);
	assert.strictEqual(lib.isImageFile('application/image/png'), false);
});

test('isImageFile rejects missing or non string types', () => {
	assert.strictEqual(lib.isImageFile(undefined), false);
	assert.strictEqual(lib.isImageFile(null), false);
	assert.strictEqual(lib.isImageFile(''), false);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run (on the host, from the plugin root): `node --test tests/js/`
Expected: FAIL with `Cannot find module '../../assets/js/lib/ozhmd-upload-lib.js'`.

- [ ] **Step 3: Write the minimal implementation**

`assets/js/lib/ozhmd-upload-lib.js`:

```js
/**
 * Pure helpers for the drag and drop uploader.
 *
 * Loaded as a browser global (window.ozhmdUploadLib) by the editor, and as a
 * CommonJS module by the Node test suite. No DOM access, no network, no state.
 */
(function (root, factory) {
	'use strict';

	var api = factory();

	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	} else {
		root.ozhmdUploadLib = api;
	}
})(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	/**
	 * Build the in-flight upload placeholder inserted at the cursor.
	 *
	 * Must stay byte-identical to ozhmd_upload_placeholder() in PHP.
	 *
	 * @param {string} filename Original file name.
	 * @return {string} Placeholder Markdown.
	 */
	function buildPlaceholder(filename) {
		return '![Uploading ' + filename + '...]()';
	}

	/**
	 * Build a Markdown image tag.
	 *
	 * Escapes the characters that would otherwise terminate the alt text or
	 * the URL, so a hostile file name cannot break out of the tag.
	 *
	 * @param {string} alt Alt text.
	 * @param {string} url Image URL.
	 * @return {string} Markdown image tag.
	 */
	function buildMarkdownImage(alt, url) {
		var safeAlt = String(alt).replace(/\[/g, '\\[').replace(/\]/g, '\\]');
		var safeUrl = String(url)
			.replace(/\(/g, '%28')
			.replace(/\)/g, '%29')
			.replace(/ /g, '%20');

		return '![' + safeAlt + '](' + safeUrl + ')';
	}

	/**
	 * Replace the first literal occurrence of a needle.
	 *
	 * Never treats the needle as a pattern and never interprets the
	 * replacement, so file names containing regex metacharacters or $&
	 * cannot corrupt the document.
	 *
	 * @param {string} haystack    Text to search.
	 * @param {string} needle      Exact substring to find.
	 * @param {string} replacement Text to insert in its place.
	 * @return {string} Text with the first occurrence replaced, unchanged when absent.
	 */
	function replacePlaceholder(haystack, needle, replacement) {
		var index = haystack.indexOf(needle);

		if (index === -1) {
			return haystack;
		}

		return haystack.slice(0, index) + replacement + haystack.slice(index + needle.length);
	}

	/**
	 * Tell whether a DataTransfer item type is an image.
	 *
	 * @param {*} type MIME type reported by the browser.
	 * @return {boolean} True when the type is an image.
	 */
	function isImageFile(type) {
		return typeof type === 'string' && type.toLowerCase().indexOf('image/') === 0;
	}

	return {
		buildPlaceholder: buildPlaceholder,
		buildMarkdownImage: buildMarkdownImage,
		replacePlaceholder: replacePlaceholder,
		isImageFile: isImageFile
	};
});
```

- [ ] **Step 4: Run the test to verify it passes**

Run (on the host): `node --test tests/js/`
Expected: PASS, 11 tests, 0 failures.

- [ ] **Step 5: Stage the work**

```bash
git add assets/js/lib/ozhmd-upload-lib.js tests/js/upload-lib.test.js
```

Commit message to use once the owner asks for commits:

```
feat: add pure JS upload helpers with node:test coverage
```

---

### Task 5: Converter factory and converter wrapper

**Files:**
- Create: `includes/class-ozhmd-converter-factory.php`
- Create: `includes/class-ozhmd-converter.php`
- Modify: `tests/unit/bootstrap.php`
- Test: `tests/unit/ConverterTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `Ozhmd_Converter_Factory::create_trusted(): MarkdownConverter`
  - `Ozhmd_Converter_Factory::create_untrusted(): MarkdownConverter`
  - `Ozhmd_Converter_Factory::mark_links_as_ugc(DocumentParsedEvent $event): void`
  - `new Ozhmd_Converter(ConverterInterface $trusted, ConverterInterface $untrusted)`
  - `Ozhmd_Converter::to_html_trusted(string $markdown): string`
  - `Ozhmd_Converter::to_html_untrusted(string $markdown): string`

All expected outputs below were verified against league/commonmark 2.10.0 on PHP 8.5 during planning.

- [ ] **Step 1: Load the new classes from the unit bootstrap**

In `tests/unit/bootstrap.php`, append after the existing `require_once` of `ozhmd-functions.php`:

```php
require_once __DIR__ . '/../../includes/class-ozhmd-converter-factory.php';
require_once __DIR__ . '/../../includes/class-ozhmd-converter.php';
```

- [ ] **Step 2: Write the failing test**

`tests/unit/ConverterTest.php`:

```php
<?php

declare(strict_types=1);

use League\CommonMark\ConverterInterface;
use League\CommonMark\Output\RenderedContentInterface;
use PHPUnit\Framework\TestCase;

final class ConverterTest extends TestCase {

	private Ozhmd_Converter_Factory $factory;

	protected function setUp(): void {
		parent::setUp();
		$this->factory = new Ozhmd_Converter_Factory();
	}

	public function test_trusted_converter_passes_raw_html_through(): void {
		$html = (string) $this->factory->create_trusted()->convert('<div class="x">hi</div>');

		$this->assertStringContainsString('<div class="x">hi</div>', $html);
	}

	public function test_trusted_converter_renders_gfm_tables(): void {
		$html = (string) $this->factory->create_trusted()->convert("| a | b |\n|---|---|\n| 1 | 2 |");

		$this->assertStringContainsString('<table>', $html);
	}

	public function test_untrusted_converter_strips_script_tags(): void {
		$html = (string) $this->factory->create_untrusted()->convert('hi <script>alert(1)</script> there');

		// html_input => strip removes the tags and keeps their text, which is
		// safe. Assert on the tag, not on the text it contained.
		$this->assertStringNotContainsString('<script', $html);
		$this->assertStringContainsString('hi', $html);
	}

	public function test_untrusted_converter_strips_arbitrary_raw_html(): void {
		$html = (string) $this->factory->create_untrusted()->convert('<img src=x onerror=alert(1)>');

		$this->assertStringNotContainsString('<img', $html);
		$this->assertStringNotContainsString('onerror', $html);
	}

	public function test_untrusted_converter_marks_links_nofollow_ugc(): void {
		$html = (string) $this->factory->create_untrusted()->convert('[a](https://e.com/x)');

		$this->assertStringContainsString('rel="nofollow ugc"', $html);
		$this->assertStringContainsString('href="https://e.com/x"', $html);
	}

	public function test_untrusted_converter_drops_unsafe_link_schemes(): void {
		$html = (string) $this->factory->create_untrusted()->convert('[a](javascript:alert(1))');

		$this->assertStringNotContainsString('javascript:', $html);
	}

	public function test_trusted_converter_does_not_mark_links_ugc(): void {
		$html = (string) $this->factory->create_trusted()->convert('[a](https://e.com/x)');

		$this->assertStringNotContainsString('ugc', $html);
	}

	public function test_both_converters_emit_prism_language_classes(): void {
		$markdown = "```php\necho 1;\n```";

		$this->assertStringContainsString(
			'<pre><code class="language-php">',
			(string) $this->factory->create_trusted()->convert($markdown)
		);
		$this->assertStringContainsString(
			'<pre><code class="language-php">',
			(string) $this->factory->create_untrusted()->convert($markdown)
		);
	}

	public function test_untrusted_converter_renders_gfm_strikethrough(): void {
		$html = (string) $this->factory->create_untrusted()->convert('~~x~~');

		$this->assertStringContainsString('<del>x</del>', $html);
	}

	public function test_factory_returns_a_new_instance_each_time(): void {
		$this->assertNotSame($this->factory->create_trusted(), $this->factory->create_trusted());
	}

	public function test_wrapper_delegates_to_the_injected_trusted_converter(): void {
		$converter = new Ozhmd_Converter(
			$this->stub_converter('TRUSTED'),
			$this->stub_converter('UNTRUSTED')
		);

		$this->assertSame('TRUSTED', $converter->to_html_trusted('anything'));
	}

	public function test_wrapper_delegates_to_the_injected_untrusted_converter(): void {
		$converter = new Ozhmd_Converter(
			$this->stub_converter('TRUSTED'),
			$this->stub_converter('UNTRUSTED')
		);

		$this->assertSame('UNTRUSTED', $converter->to_html_untrusted('anything'));
	}

	public function test_wrapper_passes_the_markdown_through_unchanged(): void {
		$rendered = $this->createMock(RenderedContentInterface::class);
		$rendered->method('__toString')->willReturn('out');

		$inner = $this->createMock(ConverterInterface::class);
		$inner->expects($this->once())
			->method('convert')
			->with('# in')
			->willReturn($rendered);

		$converter = new Ozhmd_Converter($inner, $this->stub_converter('other'));

		$this->assertSame('out', $converter->to_html_trusted('# in'));
	}

	/**
	 * Build a converter stub whose rendered output is a fixed string.
	 *
	 * @param string $output String the stub renders for any input.
	 * @return ConverterInterface Stubbed converter.
	 */
	private function stub_converter(string $output): ConverterInterface {
		$rendered = $this->createMock(RenderedContentInterface::class);
		$rendered->method('__toString')->willReturn($output);

		$converter = $this->createMock(ConverterInterface::class);
		$converter->method('convert')->willReturn($rendered);

		return $converter;
	}
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-unit.xml.dist`
Expected: FAIL with `Class "Ozhmd_Converter_Factory" not found`.

- [ ] **Step 4: Write the factory**

`includes/class-ozhmd-converter-factory.php`:

```php
<?php
/**
 * Builds the two distinctly configured Markdown converters.
 */

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Factory for the trusted and untrusted converter instances.
 *
 * Two separate instances with distinct configuration, never one instance with
 * runtime flags, so the permissive configuration cannot be reached from the
 * comment path by accident.
 */
class Ozhmd_Converter_Factory {

	/**
	 * Build the converter used for post content, authored by trusted users.
	 *
	 * Raw HTML is allowed through, because post authors are permitted to write
	 * it and legacy posts are full of it.
	 *
	 * @return MarkdownConverter Converter permitting raw HTML.
	 */
	public function create_trusted(): MarkdownConverter {
		$environment = new Environment([
			'html_input'         => 'allow',
			'allow_unsafe_links' => true,
		]);

		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new GithubFlavoredMarkdownExtension());

		return new MarkdownConverter($environment);
	}

	/**
	 * Build the converter used for comment content, authored by anyone.
	 *
	 * Raw HTML is stripped, unsafe link schemes are dropped, nesting is capped
	 * to bound pathological input, and every generated link is marked
	 * nofollow ugc.
	 *
	 * @return MarkdownConverter Converter safe for untrusted input.
	 */
	public function create_untrusted(): MarkdownConverter {
		$environment = new Environment([
			'html_input'         => 'strip',
			'allow_unsafe_links' => false,
			'max_nesting_level'  => 20,
		]);

		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new GithubFlavoredMarkdownExtension());
		$environment->addEventListener(DocumentParsedEvent::class, [$this, 'mark_links_as_ugc'], -100);

		return new MarkdownConverter($environment);
	}

	/**
	 * Append rel="nofollow ugc" to every link in a parsed document.
	 *
	 * ExternalLinkExtension is deliberately not used: its config schema offers
	 * only nofollow, noopener and noreferrer, with no ugc option, and it
	 * applies rel by host matching rather than unconditionally.
	 *
	 * @param DocumentParsedEvent $event Event carrying the parsed document.
	 * @return void
	 */
	public function mark_links_as_ugc(DocumentParsedEvent $event): void {
		foreach ($event->getDocument()->iterator() as $node) {
			if ($node instanceof Link) {
				$node->data->append('attributes/rel', 'nofollow');
				$node->data->append('attributes/rel', 'ugc');
			}
		}
	}
}
```

- [ ] **Step 5: Write the wrapper**

`includes/class-ozhmd-converter.php`:

```php
<?php
/**
 * Holds the two converter instances and exposes them by trust level.
 */

use League\CommonMark\ConverterInterface;

/**
 * Markdown conversion entry point.
 *
 * Carries no configuration of its own: both instances are injected, so tests
 * can substitute fakes and no caller can reconfigure a converter at runtime.
 */
class Ozhmd_Converter {

	/**
	 * Converter permitting raw HTML, used for post content.
	 *
	 * @var ConverterInterface
	 */
	private ConverterInterface $trusted;

	/**
	 * Converter stripping raw HTML, used for comment content.
	 *
	 * @var ConverterInterface
	 */
	private ConverterInterface $untrusted;

	/**
	 * Store the injected converters.
	 *
	 * @param ConverterInterface $trusted   Converter for trusted input.
	 * @param ConverterInterface $untrusted Converter for untrusted input.
	 */
	public function __construct(ConverterInterface $trusted, ConverterInterface $untrusted) {
		$this->trusted   = $trusted;
		$this->untrusted = $untrusted;
	}

	/**
	 * Convert Markdown written by a trusted author.
	 *
	 * @param string $markdown Raw Markdown.
	 * @return string Rendered HTML.
	 */
	public function to_html_trusted(string $markdown): string {
		return (string) $this->trusted->convert($markdown);
	}

	/**
	 * Convert Markdown written by an untrusted author.
	 *
	 * @param string $markdown Raw Markdown.
	 * @return string Rendered HTML.
	 */
	public function to_html_untrusted(string $markdown): string {
		return (string) $this->untrusted->convert($markdown);
	}
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-unit.xml.dist`
Expected: PASS, 71 tests total (58 from earlier tasks, 13 in ConverterTest), 0 failures.

- [ ] **Step 7: Stage the work**

```bash
git add includes/class-ozhmd-converter-factory.php includes/class-ozhmd-converter.php \
        tests/unit/bootstrap.php tests/unit/ConverterTest.php
```

Commit message to use once the owner asks for commits:

```
feat: add trusted and untrusted CommonMark converters
```

---

### Task 6: Plugin bootstrap and activation

**Files:**
- Create: `ozh-markdown.php`

**Interfaces:**
- Consumes: `Ozhmd_Converter_Factory`, `Ozhmd_Converter` from Task 5.
- Produces: constants `OZHMD_VERSION`, `OZHMD_FILE`, `OZHMD_DIR`, `OZHMD_URL`, `OZHMD_POST_META_KEY`, `OZHMD_ORIGINAL_META_KEY`, `OZHMD_COMMENT_META_KEY`; functions `ozhmd_bootstrap(): void` and `ozhmd_activate(): void`.

This task has no test of its own: it is verified by the integration harness in Task 7, which loads this file and asserts the plugin booted.

- [ ] **Step 1: Write the plugin file**

`ozh-markdown.php`:

```php
<?php
/**
 * Plugin Name: Ozh' Markdown Plugin
 * Plugin URI:  https://planetozh.com/
 * Description: Minimal split-pane Markdown editor for posts and comments. Markdown in, plain HTML out.
 * Version:     0.1.0
 * Author:      Ozh
 * License:     GPL-2.0-or-later
 * Text Domain: ozh-markdown
 * Requires PHP: 8.0
 *
 * @package Ozh_Markdown
 */

if (!defined('ABSPATH')) {
	exit;
}

define('OZHMD_VERSION', '0.1.0');
define('OZHMD_FILE', __FILE__);
define('OZHMD_DIR', plugin_dir_path(__FILE__));
define('OZHMD_URL', plugin_dir_url(__FILE__));
define('OZHMD_POST_META_KEY', '_ozhmd_markdown');
define('OZHMD_ORIGINAL_META_KEY', '_ozhmd_original_content');
define('OZHMD_COMMENT_META_KEY', '_ozhmd_markdown');

require_once OZHMD_DIR . 'vendor/autoload.php';
require_once OZHMD_DIR . 'includes/ozhmd-functions.php';
require_once OZHMD_DIR . 'includes/class-ozhmd-converter-factory.php';
require_once OZHMD_DIR . 'includes/class-ozhmd-converter.php';

/**
 * Construct the plugin services and register their hooks.
 *
 * Services are built here and injected into one another. No class registers a
 * hook from its constructor, so every one of them can be instantiated in a
 * test without touching the hook system.
 *
 * @return void
 */
function ozhmd_bootstrap(): void {
	$factory   = new Ozhmd_Converter_Factory();
	$converter = new Ozhmd_Converter($factory->create_trusted(), $factory->create_untrusted());

	/**
	 * Expose the converter to code loaded later in the build.
	 *
	 * @param Ozhmd_Converter $converter Configured converter pair.
	 */
	do_action('ozhmd_loaded', $converter);
}

add_action('plugins_loaded', 'ozhmd_bootstrap');

/**
 * Seed default options on activation.
 *
 * Existing options are never overwritten, so deactivating and reactivating the
 * plugin does not reset the settings.
 *
 * @return void
 */
function ozhmd_activate(): void {
	if (false === get_option('ozhmd_options')) {
		add_option('ozhmd_options', ozhmd_default_options());
	}
}

register_activation_hook(__FILE__, 'ozhmd_activate');
```

- [ ] **Step 2: Verify the file parses**

Run: `./tools/dev.sh php -l ozh-markdown.php`
Expected: `No syntax errors detected in ozh-markdown.php`

- [ ] **Step 3: Verify the unit suite still passes**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-unit.xml.dist`
Expected: PASS, 71 tests, 0 failures.

- [ ] **Step 4: Stage the work**

```bash
git add ozh-markdown.php
```

Commit message to use once the owner asks for commits:

```
feat: add plugin bootstrap and activation
```

---

### Task 7: wp-phpunit integration harness

**Files:**
- Create: `phpunit-integration.xml.dist`
- Create: `tests/integration/wp-tests-config.php`
- Create: `tests/integration/bootstrap.php`
- Test: `tests/integration/BootstrapTest.php`

**Interfaces:**
- Consumes: `ozh-markdown.php` from Task 6.
- Produces: a runnable integration suite. Later tasks and plans add test classes to `tests/integration/` and run them with the command from Step 5.

**Safety, read before running anything:** the WordPress test suite **drops and recreates every table** in the database it is pointed at. `DB_NAME` below is `ozhmd_tests`, which must never be the blog's own database. Verify the value before the first run.

- [ ] **Step 1: Create the test database**

Run:

```bash
docker exec -i dev-mariadb mariadb -uroot -e "CREATE DATABASE IF NOT EXISTS ozhmd_tests"
```

Verify it is reachable from the web container:

```bash
./tools/dev.sh php -r '$m = @new mysqli("mariadb", "root", "", "ozhmd_tests"); echo $m->connect_error ?: "OK";'
```

Expected: `OK`. If this prints a connection error instead, the MariaDB root account is not reachable from the web container, and that must be resolved before continuing.

- [ ] **Step 2: Create the WordPress test configuration**

`tests/integration/wp-tests-config.php`:

```php
<?php
/**
 * WordPress test suite configuration.
 *
 * Points the test suite at the local Docker stack and at a dedicated database.
 * The suite drops and recreates every table on boot, so DB_NAME must never be
 * the blog's own database.
 */

define('ABSPATH', '/var/www/html/planetozh.com/blog/');

define('DB_NAME', 'ozhmd_tests');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', 'mariadb');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Ozh Markdown Test Blog');
define('WP_PHP_BINARY', 'php');
define('WP_DEBUG', true);
```

- [ ] **Step 3: Create the integration bootstrap and configuration**

`tests/integration/bootstrap.php`:

```php
<?php
/**
 * Bootstrap for the wp-phpunit suite.
 *
 * Loads the WordPress test library, then the plugin, against the dedicated
 * test database configured in wp-tests-config.php.
 */

$ozhmd_dev_autoload = __DIR__ . '/../tools/vendor/autoload.php';

if (!file_exists($ozhmd_dev_autoload)) {
	fwrite(STDERR, "Dev tooling missing. Run: ./tools/dev.sh composer install -d tests/tools\n");
	exit(1);
}

require_once $ozhmd_dev_autoload;

putenv('WP_TESTS_CONFIG_FILE_PATH=' . __DIR__ . '/wp-tests-config.php');

$ozhmd_tests_dir = __DIR__ . '/../tools/vendor/wp-phpunit/wp-phpunit';

require_once $ozhmd_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
	require dirname(__DIR__, 2) . '/ozh-markdown.php';
});

require $ozhmd_tests_dir . '/includes/bootstrap.php';
```

`phpunit-integration.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
		 xsi:noNamespaceSchemaLocation="tests/tools/vendor/phpunit/phpunit/phpunit.xsd"
		 bootstrap="tests/integration/bootstrap.php"
		 cacheDirectory="tests/.phpunit.cache"
		 colors="true"
		 failOnWarning="true">
	<testsuites>
		<testsuite name="integration">
			<directory>tests/integration</directory>
		</testsuite>
	</testsuites>
	<source>
		<include>
			<directory suffix=".php">includes</directory>
		</include>
	</source>
</phpunit>
```

- [ ] **Step 4: Write the failing test**

`tests/integration/BootstrapTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Smoke test proving WordPress and the plugin both loaded.
 */
final class BootstrapTest extends WP_UnitTestCase {

	public function test_wordpress_is_loaded(): void {
		$this->assertTrue(defined('ABSPATH'));
		$this->assertTrue(function_exists('add_action'));
	}

	public function test_the_plugin_file_was_loaded(): void {
		$this->assertTrue(defined('OZHMD_VERSION'));
		$this->assertSame('_ozhmd_markdown', OZHMD_POST_META_KEY);
		$this->assertSame('_ozhmd_original_content', OZHMD_ORIGINAL_META_KEY);
	}

	public function test_the_bootstrap_ran(): void {
		$this->assertGreaterThan(0, did_action('ozhmd_loaded'));
	}

	public function test_pure_functions_are_available(): void {
		$this->assertTrue(function_exists('ozhmd_eligible_post_types'));
		$this->assertTrue(function_exists('ozhmd_default_options'));
	}

	public function test_activation_seeds_the_default_options(): void {
		delete_option('ozhmd_options');

		ozhmd_activate();

		$this->assertSame(ozhmd_default_options(), get_option('ozhmd_options'));
	}

	public function test_activation_does_not_overwrite_existing_options(): void {
		$existing = ['comments_enabled' => false, 'comment_preview' => true, 'prism_line_numbers' => true];
		update_option('ozhmd_options', $existing);

		ozhmd_activate();

		$this->assertSame($existing, get_option('ozhmd_options'));
	}
}
```

- [ ] **Step 5: Run the test to verify it fails**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-integration.xml.dist`
Expected: FAIL. Before `tests/integration/bootstrap.php` exists the run cannot start at all; once the files above are in place this step is the first real execution and should surface any database or path misconfiguration.

- [ ] **Step 6: Run the test to verify it passes**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-integration.xml.dist`
Expected: PASS, 6 tests, 0 failures, preceded by the WordPress test suite's installation output.

- [ ] **Step 7: Confirm the unit suite is unaffected**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-unit.xml.dist`
Expected: PASS, 71 tests, 0 failures.

- [ ] **Step 8: Stage the work**

```bash
git add phpunit-integration.xml.dist tests/integration/wp-tests-config.php \
        tests/integration/bootstrap.php tests/integration/BootstrapTest.php
```

Commit message to use once the owner asks for commits:

```
test: add wp-phpunit integration harness
```

---

### Task 8: Post type eligibility against the WordPress registry

**Files:**
- Create: `includes/class-ozhmd-post-types.php`
- Modify: `ozh-markdown.php` (add the `require_once`)
- Test: `tests/integration/PostTypesQueryTest.php`

**Interfaces:**
- Consumes: `ozhmd_eligible_post_types()` and `ozhmd_default_excluded_post_types()` from Task 1.
- Produces: `Ozhmd_Post_Types::eligible(): array`, and the filter `ozhmd_excluded_post_types`. Plan 2 adds `register_hooks()` to this same class to apply the eligibility; this task delivers only the query half, which the settings page in Task 9 consumes.

- [ ] **Step 1: Write the failing test**

`tests/integration/PostTypesQueryTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Eligibility resolved against the real WordPress post type registry.
 */
final class PostTypesQueryTest extends WP_UnitTestCase {

	private Ozhmd_Post_Types $post_types;

	public function set_up(): void {
		parent::set_up();
		$this->post_types = new Ozhmd_Post_Types();
	}

	public function test_core_content_types_are_eligible(): void {
		$eligible = $this->post_types->eligible();

		$this->assertContains('post', $eligible);
		$this->assertContains('page', $eligible);
	}

	public function test_excluded_types_are_absent_even_though_they_support_the_editor(): void {
		// wp_block supports 'editor', so its absence proves the exclusion list
		// is doing the work rather than the editor-support query.
		$this->assertContains('wp_block', get_post_types_by_support('editor'));
		$this->assertNotContains('wp_block', $this->post_types->eligible());
	}

	public function test_a_custom_post_type_supporting_the_editor_is_eligible(): void {
		register_post_type('ozhmd_book', ['supports' => ['title', 'editor']]);

		$this->assertContains('ozhmd_book', $this->post_types->eligible());

		unregister_post_type('ozhmd_book');
	}

	public function test_a_custom_post_type_without_editor_support_is_not_eligible(): void {
		register_post_type('ozhmd_link', ['supports' => ['title']]);

		$this->assertNotContains('ozhmd_link', $this->post_types->eligible());

		unregister_post_type('ozhmd_link');
	}

	public function test_the_filter_can_exclude_a_further_post_type(): void {
		$exclude_pages = static function (array $excluded): array {
			$excluded[] = 'page';

			return $excluded;
		};

		add_filter('ozhmd_excluded_post_types', $exclude_pages);
		$eligible = $this->post_types->eligible();
		remove_filter('ozhmd_excluded_post_types', $exclude_pages);

		$this->assertNotContains('page', $eligible);
		$this->assertContains('post', $eligible);
	}

	public function test_the_result_is_a_reindexed_list_of_strings(): void {
		$eligible = $this->post_types->eligible();

		$this->assertSame(range(0, count($eligible) - 1), array_keys($eligible));
		$this->assertContainsOnly('string', $eligible);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-integration.xml.dist`
Expected: FAIL with `Class "Ozhmd_Post_Types" not found`.

- [ ] **Step 3: Write the minimal implementation**

`includes/class-ozhmd-post-types.php`:

```php
<?php
/**
 * Resolves which post types receive the Markdown editor.
 */

/**
 * Post type eligibility.
 *
 * Thin wiring over ozhmd_eligible_post_types(): this class reads the WordPress
 * registry and applies the filter, the pure function does the deciding.
 */
class Ozhmd_Post_Types {

	/**
	 * Post types eligible for the Markdown editor.
	 *
	 * Every post type supporting the 'editor' feature, minus the built-in
	 * exclusion list as extended by the ozhmd_excluded_post_types filter.
	 *
	 * @return string[] Eligible post type names, reindexed.
	 */
	public function eligible(): array {
		$supporting = array_values(get_post_types_by_support('editor'));

		/**
		 * Filter the post types that never receive the Markdown editor.
		 *
		 * @param string[] $excluded Excluded post type names.
		 */
		$excluded = apply_filters('ozhmd_excluded_post_types', ozhmd_default_excluded_post_types());

		return ozhmd_eligible_post_types($supporting, (array) $excluded);
	}
}
```

- [ ] **Step 4: Load the class from the plugin file**

In `ozh-markdown.php`, add after the existing converter `require_once` lines:

```php
require_once OZHMD_DIR . 'includes/class-ozhmd-post-types.php';
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-integration.xml.dist`
Expected: PASS, 12 tests, 0 failures.

- [ ] **Step 6: Stage the work**

```bash
git add includes/class-ozhmd-post-types.php ozh-markdown.php tests/integration/PostTypesQueryTest.php
```

Commit message to use once the owner asks for commits:

```
feat: resolve eligible post types from the WordPress registry
```

---

### Task 9: Settings page

**Files:**
- Create: `includes/class-ozhmd-settings.php`
- Modify: `ozh-markdown.php` (require the class, construct it in `ozhmd_bootstrap()`)
- Test: `tests/integration/SettingsTest.php`

**Interfaces:**
- Consumes: `Ozhmd_Post_Types::eligible()` from Task 8, `ozhmd_default_options()` and `ozhmd_sanitize_options()` from Task 3.
- Produces: `Ozhmd_Settings::OPTION_NAME`, `new Ozhmd_Settings(Ozhmd_Post_Types $post_types)`, `Ozhmd_Settings::register_hooks(): void`, `Ozhmd_Settings::option(string $key): bool`, `Ozhmd_Settings::sanitize($input): array`. Plans 2 to 4 read feature toggles through `option()`.

- [ ] **Step 1: Write the failing test**

`tests/integration/SettingsTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Options page registration and option reading.
 */
final class SettingsTest extends WP_UnitTestCase {

	private Ozhmd_Settings $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = new Ozhmd_Settings(new Ozhmd_Post_Types());
		delete_option(Ozhmd_Settings::OPTION_NAME);
	}

	public function test_the_option_name_matches_the_spec(): void {
		$this->assertSame('ozhmd_options', Ozhmd_Settings::OPTION_NAME);
	}

	public function test_the_constructor_registers_no_hooks(): void {
		remove_all_actions('admin_menu');

		new Ozhmd_Settings(new Ozhmd_Post_Types());

		$this->assertFalse(has_action('admin_menu'));
	}

	public function test_register_hooks_registers_the_admin_hooks(): void {
		$this->settings->register_hooks();

		$this->assertNotFalse(has_action('admin_menu', [$this->settings, 'add_page']));
		$this->assertNotFalse(has_action('admin_init', [$this->settings, 'register_settings']));
	}

	public function test_option_falls_back_to_the_default_when_unset(): void {
		$this->assertTrue($this->settings->option('comments_enabled'));
		$this->assertFalse($this->settings->option('comment_preview'));
		$this->assertFalse($this->settings->option('prism_line_numbers'));
	}

	public function test_option_reads_a_stored_value(): void {
		update_option(Ozhmd_Settings::OPTION_NAME, ['comments_enabled' => false]);

		$this->assertFalse($this->settings->option('comments_enabled'));
	}

	public function test_option_fills_a_missing_key_from_the_defaults(): void {
		update_option(Ozhmd_Settings::OPTION_NAME, ['comments_enabled' => false]);

		$this->assertFalse($this->settings->option('comment_preview'));
	}

	public function test_option_returns_false_for_an_unknown_key(): void {
		$this->assertFalse($this->settings->option('no_such_option'));
	}

	public function test_option_survives_a_corrupted_stored_value(): void {
		update_option(Ozhmd_Settings::OPTION_NAME, 'not an array');

		$this->assertTrue($this->settings->option('comments_enabled'));
	}

	public function test_sanitize_delegates_to_the_pure_function(): void {
		$clean = $this->settings->sanitize(['comments_enabled' => '1', 'evil' => 'x']);

		$this->assertSame(
			['comments_enabled' => true, 'comment_preview' => false, 'prism_line_numbers' => false],
			$clean
		);
	}

	public function test_sanitize_tolerates_a_non_array_payload(): void {
		$clean = $this->settings->sanitize('nonsense');

		$this->assertSame(array_keys(ozhmd_default_options()), array_keys($clean));
	}

	public function test_the_page_renders_a_field_for_every_toggle(): void {
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		$this->settings->register_settings();

		ob_start();
		$this->settings->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString('ozhmd_options[comments_enabled]', $html);
		$this->assertStringContainsString('ozhmd_options[comment_preview]', $html);
		$this->assertStringContainsString('ozhmd_options[prism_line_numbers]', $html);
	}

	public function test_the_page_lists_the_handled_post_types(): void {
		wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
		$this->settings->register_settings();

		ob_start();
		$this->settings->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString('post', $html);
		$this->assertStringContainsString('page', $html);
		$this->assertStringNotContainsString('wp_block', $html);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-integration.xml.dist`
Expected: FAIL with `Class "Ozhmd_Settings" not found`.

- [ ] **Step 3: Write the minimal implementation**

`includes/class-ozhmd-settings.php`:

```php
<?php
/**
 * Settings page under Settings > Ozh' Markdown.
 */

/**
 * Plugin options page.
 *
 * All three toggles live in one option array. There are no per-post-type
 * checkboxes by design: fine-grained control is the ozhmd_excluded_post_types
 * filter, and the page shows the resolved list read-only.
 */
class Ozhmd_Settings {

	/**
	 * Name of the single option array holding every setting.
	 */
	public const OPTION_NAME = 'ozhmd_options';

	/**
	 * Resolver for the post types currently handled.
	 *
	 * @var Ozhmd_Post_Types
	 */
	private Ozhmd_Post_Types $post_types;

	/**
	 * Store the injected post type resolver.
	 *
	 * @param Ozhmd_Post_Types $post_types Eligibility resolver.
	 */
	public function __construct(Ozhmd_Post_Types $post_types) {
		$this->post_types = $post_types;
	}

	/**
	 * Register the admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action('admin_menu', [$this, 'add_page']);
		add_action('admin_init', [$this, 'register_settings']);
	}

	/**
	 * Add the options page to the Settings menu.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_options_page(
			__("Ozh' Markdown", 'ozh-markdown'),
			__("Ozh' Markdown", 'ozh-markdown'),
			'manage_options',
			'ozh-markdown',
			[$this, 'render_page']
		);
	}

	/**
	 * Register the setting, its section and its fields.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_NAME,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [$this, 'sanitize'],
				'default'           => ozhmd_default_options(),
			]
		);

		add_settings_section(
			'ozhmd_main',
			__('Behaviour', 'ozh-markdown'),
			'__return_false',
			'ozh-markdown'
		);

		$fields = [
			'comments_enabled'   => __('Enable Markdown for comments', 'ozh-markdown'),
			'comment_preview'    => __('Show a live preview under the comment form', 'ozh-markdown'),
			'prism_line_numbers' => __('Show line numbers on code blocks', 'ozh-markdown'),
		];

		foreach ($fields as $key => $label) {
			add_settings_field(
				$key,
				$label,
				[$this, 'render_checkbox'],
				'ozh-markdown',
				'ozhmd_main',
				['key' => $key, 'label' => $label]
			);
		}
	}

	/**
	 * Sanitize a submitted settings payload.
	 *
	 * @param mixed $input Raw submitted value, of any type.
	 * @return array<string, bool> Sanitized options.
	 */
	public function sanitize($input): array {
		return ozhmd_sanitize_options((array) $input, ozhmd_default_options());
	}

	/**
	 * Read one option, falling back to its default.
	 *
	 * Tolerates a missing, corrupted or partial stored value, so a hand-edited
	 * option row cannot break the plugin.
	 *
	 * @param string $key Option key.
	 * @return bool Option value, false for an unknown key.
	 */
	public function option(string $key): bool {
		$defaults = ozhmd_default_options();

		if (!array_key_exists($key, $defaults)) {
			return false;
		}

		$stored = get_option(self::OPTION_NAME, []);

		if (!is_array($stored) || !array_key_exists($key, $stored)) {
			return $defaults[$key];
		}

		return (bool) $stored[$key];
	}

	/**
	 * Render one checkbox field.
	 *
	 * @param array{key: string, label: string} $args Field arguments.
	 * @return void
	 */
	public function render_checkbox(array $args): void {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr(self::OPTION_NAME),
			esc_attr($args['key']),
			checked($this->option($args['key']), true, false),
			esc_html($args['label'])
		);
	}

	/**
	 * Render the options page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__("Ozh' Markdown", 'ozh-markdown') . '</h1>';

		echo '<form action="options.php" method="post">';
		settings_fields(self::OPTION_NAME);
		do_settings_sections('ozh-markdown');
		submit_button();
		echo '</form>';

		$this->render_post_types();

		echo '</div>';
	}

	/**
	 * Render the read-only list of post types currently handled.
	 *
	 * @return void
	 */
	public function render_post_types(): void {
		echo '<h2>' . esc_html__('Post types using the Markdown editor', 'ozh-markdown') . '</h2>';
		echo '<p>' . esc_html__('Change this list with the ozhmd_excluded_post_types filter.', 'ozh-markdown') . '</p>';
		echo '<ul>';

		foreach ($this->post_types->eligible() as $post_type) {
			echo '<li><code>' . esc_html($post_type) . '</code></li>';
		}

		echo '</ul>';
	}
}
```

- [ ] **Step 4: Load and construct the class**

In `ozh-markdown.php`, add after the `class-ozhmd-post-types.php` require:

```php
require_once OZHMD_DIR . 'includes/class-ozhmd-settings.php';
```

Then in `ozhmd_bootstrap()`, replace the body with:

```php
	$factory   = new Ozhmd_Converter_Factory();
	$converter = new Ozhmd_Converter($factory->create_trusted(), $factory->create_untrusted());

	$post_types = new Ozhmd_Post_Types();

	$settings = new Ozhmd_Settings($post_types);
	$settings->register_hooks();

	/**
	 * Expose the converter to code loaded later in the build.
	 *
	 * @param Ozhmd_Converter $converter Configured converter pair.
	 */
	do_action('ozhmd_loaded', $converter);
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-integration.xml.dist`
Expected: PASS, 24 tests, 0 failures.

- [ ] **Step 6: Run both suites and the JS suite**

Run:

```bash
./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-unit.xml.dist
./tools/dev.sh tests/tools/vendor/bin/phpunit -c phpunit-integration.xml.dist
node --test tests/js/
```

Expected: 71 unit tests pass, 24 integration tests pass, 11 JS tests pass.

- [ ] **Step 7: Verify the plugin activates in the real blog**

Activate **Ozh' Markdown Plugin** at `https://127.0.0.1/planetozh.com/blog/wp-admin/plugins.php`, then open Settings > Ozh' Markdown.

Expected: the page lists three checkboxes with `comments_enabled` checked and the other two unchecked, followed by a list of post types including `post` and `page` but not `wp_block`. No PHP notices anywhere on the page.

- [ ] **Step 8: Stage the work**

```bash
git add includes/class-ozhmd-settings.php ozh-markdown.php tests/integration/SettingsTest.php
```

Commit message to use once the owner asks for commits:

```
feat: add the settings page
```

---

## Plan self-review

**Spec coverage for this plan's scope.** Design §2 decisions 1 to 4 are realised by tasks 1, 4 and 7. §3.1 bootstrap is Task 6. §3.2's file tree is created incrementally and matches. Every function in §4 is implemented and tested across tasks 1, 2 and 3, except the JS mirrors, which are Task 4. §5 including the corrected UGC mechanism is Task 5, and §5.1's verified behaviours are each asserted. §11 settings is Task 9, including the read-only post type list, which needed Task 8's eligibility query. §13's three suites are stood up in tasks 1, 4 and 7.

Requirements deliberately not covered here, all belonging to later plans and listed in "Scope of this plan": design §6 post path, §7 editor UI, §8 upload, §9 comments, §10 front end, §12's JavaScript vendoring.

**Placeholder scan.** No TBD, TODO, "similar to Task N", or "add appropriate error handling". Every code step carries complete code. Every run step carries an exact command and an expected result.

**Type consistency.** `ozhmd_eligible_post_types()` is defined in Task 1 and consumed in Task 8 with matching arguments. `ozhmd_default_options()` and `ozhmd_sanitize_options()` are defined in Task 3 and consumed in Task 9 with matching signatures. `Ozhmd_Post_Types::eligible()` is defined in Task 8 and consumed in Task 9. `Ozhmd_Converter_Factory::create_trusted()` and `create_untrusted()` are defined in Task 5 and consumed in tasks 6 and 9. `Ozhmd_Settings::option()` is defined once and used by `render_checkbox()`. `buildPlaceholder` in Task 4 matches `ozhmd_upload_placeholder()` in Task 3 byte for byte, and both have a test asserting the exact string.

**Known assumption.** Task 7 Step 1 verifies MariaDB root is reachable from the web container before anything depends on it. That is the one environmental unknown in this plan, and it fails loudly and early rather than midway through.
