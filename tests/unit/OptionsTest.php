<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Plugin option defaults and sanitization.
 */
final class OptionsTest extends TestCase {

	/**
	 * Default options match the specification.
	 *
	 * @return void
	 */
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

	/**
	 * Unknown keys are dropped during sanitization.
	 *
	 * @return void
	 */
	public function test_sanitize_drops_unknown_keys(): void {
		$clean = ozhmd_sanitize_options(['evil' => 'yes'], ozhmd_default_options());

		$this->assertArrayNotHasKey('evil', $clean);
	}

	/**
	 * Sanitization always returns every known key.
	 *
	 * @return void
	 */
	public function test_sanitize_always_returns_every_known_key(): void {
		$clean = ozhmd_sanitize_options([], ozhmd_default_options());

		$this->assertSame(
			['comments_enabled', 'comment_preview', 'prism_line_numbers'],
			array_keys($clean)
		);
	}

	/**
	 * Missing keys become false in sanitization.
	 *
	 * @return void
	 */
	public function test_a_missing_key_becomes_false(): void {
		// An unchecked checkbox is simply absent from the payload.
		$clean = ozhmd_sanitize_options([], ozhmd_default_options());

		$this->assertFalse($clean['comments_enabled']);
	}

	/**
	 * Checkbox values become true in sanitization.
	 *
	 * @return void
	 */
	public function test_checkbox_values_become_true(): void {
		$clean = ozhmd_sanitize_options(['comments_enabled' => '1'], ozhmd_default_options());

		$this->assertTrue($clean['comments_enabled']);
	}

	/**
	 * Zero string becomes false in sanitization.
	 *
	 * @return void
	 */
	public function test_a_zero_string_becomes_false(): void {
		$clean = ozhmd_sanitize_options(['comments_enabled' => '0'], ozhmd_default_options());

		$this->assertFalse($clean['comments_enabled']);
	}

	/**
	 * All sanitized values are real booleans.
	 *
	 * @return void
	 */
	public function test_every_value_is_a_real_boolean(): void {
		$clean = ozhmd_sanitize_options(['comment_preview' => 'on'], ozhmd_default_options());

		foreach ($clean as $value) {
			$this->assertIsBool($value);
		}
	}
}
