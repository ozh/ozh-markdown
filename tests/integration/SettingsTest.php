<?php

declare(strict_types=1);

require_once __DIR__ . '/class-ozhmd-integration-test-case.php';

/**
 * Options page registration and option reading.
 */
final class SettingsTest extends Ozhmd_Integration_Test_Case {

	/**
	 * Settings page under test.
	 *
	 * @var Ozhmd_Settings
	 */
	private Ozhmd_Settings $settings;

	/**
	 * Build a fresh settings page against a clean option row.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = new Ozhmd_Settings(new Ozhmd_Post_Types());
		delete_option(Ozhmd_Settings::OPTION_NAME);
	}

	/**
	 * Create an administrator and make them the current user.
	 *
	 * The WordPress test factories are not available in this harness, so
	 * tests create fixtures with the real WordPress functions.
	 *
	 * @return int New user ID.
	 */
	private function login_as_administrator(): int {
		$user_id = wp_insert_user([
			'user_login' => 'ozhmd_admin_' . uniqid(),
			'user_pass'  => 'password',
			'user_email' => uniqid() . '@example.org',
			'role'       => 'administrator',
		]);

		wp_set_current_user($user_id);

		return $user_id;
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
		// register_settings() and render_page() reach add_settings_section(),
		// do_settings_sections() and submit_button(), none of which a
		// front-end WordPress boot loads. This test loads them itself, same
		// as BootstrapTest::test_isolation_a_meta_box_is_registered().
		require_once ABSPATH . 'wp-admin/includes/template.php';

		$this->login_as_administrator();
		$this->settings->register_settings();

		ob_start();
		$this->settings->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString('ozhmd_options[comments_enabled]', $html);
		$this->assertStringContainsString('ozhmd_options[comment_preview]', $html);
		$this->assertStringContainsString('ozhmd_options[prism_line_numbers]', $html);
	}

	public function test_the_page_lists_the_handled_post_types(): void {
		// Same admin-only dependency as test_the_page_renders_a_field_for_every_toggle.
		require_once ABSPATH . 'wp-admin/includes/template.php';

		$this->login_as_administrator();
		$this->settings->register_settings();

		ob_start();
		$this->settings->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString('post', $html);
		$this->assertStringContainsString('page', $html);
		$this->assertStringNotContainsString('wp_block', $html);
	}
}
