<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Helpers for image uploads and Markdown generation.
 */
final class UploadHelpersTest extends TestCase {

	/**
	 * Alt text removes the file extension.
	 *
	 * @return void
	 */
	public function test_alt_strips_the_extension(): void {
		$this->assertSame('photo', ozhmd_alt_from_filename('photo.png'));
	}

	/**
	 * Alt text removes the leading directory path.
	 *
	 * @return void
	 */
	public function test_alt_strips_a_leading_path(): void {
		$this->assertSame('my shot', ozhmd_alt_from_filename('a/b/my shot.jpeg'));
	}

	/**
	 * Alt text for a file with no extension is the filename itself.
	 *
	 * @return void
	 */
	public function test_alt_of_an_extensionless_name_is_the_name(): void {
		$this->assertSame('noext', ozhmd_alt_from_filename('noext'));
	}

	/**
	 * Alt text removes only the final extension.
	 *
	 * @return void
	 */
	public function test_alt_strips_only_the_last_extension(): void {
		$this->assertSame('archive.tar', ozhmd_alt_from_filename('archive.tar.gz'));
	}

	/**
	 * Plain Markdown image tag has correct syntax.
	 *
	 * @return void
	 */
	public function test_builds_a_plain_markdown_image(): void {
		$this->assertSame(
			'![cat](https://example.com/cat.png)',
			ozhmd_build_markdown_image('cat', 'https://example.com/cat.png')
		);
	}

	/**
	 * Alt text escapes closing brackets.
	 *
	 * @return void
	 */
	public function test_escapes_brackets_in_the_alt_text(): void {
		$this->assertSame(
			'![a\\]b](https://example.com/x.png)',
			ozhmd_build_markdown_image('a]b', 'https://example.com/x.png')
		);
	}

	/**
	 * URL encodes spaces and parentheses.
	 *
	 * @return void
	 */
	public function test_encodes_parentheses_and_spaces_in_the_url(): void {
		$this->assertSame(
			'![x](https://example.com/a%20%281%29.png)',
			ozhmd_build_markdown_image('x', 'https://example.com/a (1).png')
		);
	}

	/**
	 * Alt text escapes backslashes.
	 *
	 * @return void
	 */
	public function test_escapes_a_backslash_in_the_alt_text(): void {
		$this->assertSame(
			'![a\\\\b](https://example.com/x.png)',
			ozhmd_build_markdown_image('a\\b', 'https://example.com/x.png')
		);
	}

	/**
	 * Backslash-bracket payload cannot close alt text early.
	 *
	 * @return void
	 */
	public function test_a_backslash_bracket_payload_cannot_close_the_alt_text_early(): void {
		// Escaping only the brackets turns the existing "\]" into "\\]", which
		// CommonMark reads as an escaped backslash followed by a live bracket.
		// The alt text then ends early and the payload supplies its own URL.
		$alt = <<<'ALT'
			x\](https://evil.example/p)[y
			ALT;

		$expected = <<<'MARKDOWN'
			![x\\\](https://evil.example/p)\[y](https://example.com/cat.png)
			MARKDOWN;

		$this->assertSame($expected, ozhmd_build_markdown_image($alt, 'https://example.com/cat.png'));
	}

	/**
	 * Upload placeholder has correct format and filename.
	 *
	 * @return void
	 */
	public function test_builds_the_upload_placeholder(): void {
		$this->assertSame('![Uploading cat.png...]()', ozhmd_upload_placeholder('cat.png'));
	}

	/**
	 * Allowed MIME types are recognized.
	 *
	 * @return void
	 */
	public function test_allows_an_image_mime_the_site_permits(): void {
		$this->assertTrue(ozhmd_is_allowed_image_mime('image/png', ['png' => 'image/png']));
	}

	/**
	 * Extension key format in allowed map is ignored.
	 *
	 * @return void
	 */
	public function test_ignores_the_extension_keys_of_the_allowed_map(): void {
		$this->assertTrue(
			ozhmd_is_allowed_image_mime('image/jpeg', ['jpg|jpeg|jpe' => 'image/jpeg'])
		);
	}

	/**
	 * Non-permitted MIME types are rejected.
	 *
	 * @return void
	 */
	public function test_rejects_an_image_mime_the_site_does_not_permit(): void {
		$this->assertFalse(ozhmd_is_allowed_image_mime('image/svg+xml', ['png' => 'image/png']));
	}

	/**
	 * Non-image MIME types are rejected even when the site permits them.
	 *
	 * @return void
	 */
	public function test_rejects_a_non_image_even_when_permitted(): void {
		$this->assertFalse(ozhmd_is_allowed_image_mime('application/pdf', ['pdf' => 'application/pdf']));
	}

	/**
	 * MIME type comparison is case-insensitive.
	 *
	 * @return void
	 */
	public function test_mime_comparison_is_case_insensitive(): void {
		$this->assertTrue(ozhmd_is_allowed_image_mime('IMAGE/PNG', ['png' => 'image/png']));
	}

	/**
	 * MIME types merely containing image prefix are rejected.
	 *
	 * @return void
	 */
	public function test_rejects_a_mime_merely_containing_image(): void {
		$this->assertFalse(
			ozhmd_is_allowed_image_mime('application/image/png', ['x' => 'application/image/png'])
		);
	}

	/**
	 * All MIME types are rejected when nothing is allowed.
	 *
	 * @return void
	 */
	public function test_rejects_everything_when_nothing_is_allowed(): void {
		$this->assertFalse(ozhmd_is_allowed_image_mime('image/png', []));
	}
}
