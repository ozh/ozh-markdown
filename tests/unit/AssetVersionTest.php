<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cache busting version stamped on the plugin's own assets.
 *
 * A fixed version string means an edited file keeps its old URL, so browsers
 * keep serving the copy they already hold. These tests pin the behaviour that
 * prevents it: the version has to follow the file's contents.
 */
final class AssetVersionTest extends TestCase {

	/**
	 * Temporary asset file backing the tests.
	 *
	 * @var string
	 */
	private string $file = '';

	/**
	 * Remove the temporary file.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ('' !== $this->file && file_exists($this->file)) {
			unlink($this->file);
		}

		parent::tearDown();
	}

	/**
	 * Create a temporary file under the plugin directory.
	 *
	 * @param int $mtime Modification time to stamp on it.
	 * @return string Path relative to the plugin root.
	 */
	private function makeAsset(int $mtime): string {
		$relative  = 'assets/ozhmd-test-asset-' . getmypid() . '.js';
		$this->file = OZHMD_DIR . $relative;

		file_put_contents($this->file, 'x');
		touch($this->file, $mtime);
		clearstatcache(true, $this->file);

		return $relative;
	}

	/**
	 * The version carries the plugin version so releases still bust caches.
	 *
	 * @return void
	 */
	public function test_the_version_starts_with_the_plugin_version(): void {
		$this->assertStringStartsWith(
			OZHMD_VERSION,
			ozhmd_asset_version($this->makeAsset(1700000000))
		);
	}

	/**
	 * Touching the file changes the version.
	 *
	 * @return void
	 */
	public function test_editing_the_file_changes_the_version(): void {
		$relative = $this->makeAsset(1700000000);
		$before   = ozhmd_asset_version($relative);

		touch($this->file, 1700000001);
		clearstatcache(true, $this->file);

		$this->assertNotSame($before, ozhmd_asset_version($relative));
	}

	/**
	 * An unchanged file keeps the same version.
	 *
	 * @return void
	 */
	public function test_an_unchanged_file_keeps_its_version(): void {
		$relative = $this->makeAsset(1700000000);

		$this->assertSame(ozhmd_asset_version($relative), ozhmd_asset_version($relative));
	}

	/**
	 * A missing file falls back to the plugin version rather than failing.
	 *
	 * @return void
	 */
	public function test_a_missing_file_falls_back_to_the_plugin_version(): void {
		$this->assertSame(OZHMD_VERSION, ozhmd_asset_version('assets/js/does-not-exist.js'));
	}
}
