<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The text file size limit is written in two places and must agree.
 *
 * PHP owns the real value and sends it to the browser. The JavaScript keeps a
 * fallback for when that value fails to arrive, because comparing a file size
 * against a missing limit is false for every file and removes the cap without
 * saying so. A drifted fallback would quietly enforce the wrong number, so the
 * two are compared here by reading both files.
 */
final class TextSizeLimitTest extends TestCase {

	/**
	 * Read a constant's integer value out of a source file.
	 *
	 * @param string $path    File to read, relative to the plugin root.
	 * @param string $pattern Regex with the value in the first group.
	 * @return int Value found.
	 */
	private function readConstant(string $path, string $pattern): int {
		$source = file_get_contents(OZHMD_DIR . $path);

		$this->assertIsString($source, $path . ' is readable');
		$this->assertSame(1, preg_match($pattern, $source, $matches), 'found the constant in ' . $path);

		return (int) $matches[1];
	}

	/**
	 * The JavaScript fallback matches the PHP constant.
	 *
	 * @return void
	 */
	public function test_the_javascript_fallback_matches_the_php_constant(): void {
		$php = $this->readConstant(
			'includes/class-ozhmd-editor-ui.php',
			'/const\s+MAX_TEXT_BYTES\s*=\s*(\d+)\s*;/'
		);

		$js = $this->readConstant(
			'assets/js/lib/ozhmd-upload-lib.js',
			'/var\s+DEFAULT_MAX_TEXT_BYTES\s*=\s*(\d+)\s*;/'
		);

		$this->assertSame(
			$php,
			$js,
			'Ozhmd_Editor_UI::MAX_TEXT_BYTES and DEFAULT_MAX_TEXT_BYTES in ozhmd-upload-lib.js have drifted'
		);
	}
}
