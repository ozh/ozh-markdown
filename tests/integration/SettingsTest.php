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

	/**
	 * The option array every consumer reads is stored under 'ozhmd_options'.
	 *
	 * @return void
	 */
	public function test_the_option_name_matches_the_spec(): void {
		$this->assertSame('ozhmd_options', Ozhmd_Settings::OPTION_NAME);
	}

	/**
	 * Constructing the class touches neither admin_menu nor admin_init, so it
	 * stays instantiable in a test without reaching the hook system.
	 *
	 * @return void
	 */
	public function test_the_constructor_registers_no_hooks(): void {
		remove_all_actions('admin_menu');
		remove_all_actions('admin_init');

		new Ozhmd_Settings(new Ozhmd_Post_Types());

		$this->assertFalse(has_action('admin_menu'));
		$this->assertFalse(has_action('admin_init'));
	}

	/**
	 * register_hooks() wires add_page() to admin_menu and register_settings()
	 * to admin_init.
	 *
	 * @return void
	 */
	public function test_register_hooks_registers_the_admin_hooks(): void {
		$this->settings->register_hooks();

		$this->assertNotFalse(has_action('admin_menu', [$this->settings, 'add_page']));
		$this->assertNotFalse(has_action('admin_init', [$this->settings, 'register_settings']));
	}

	/**
	 * With no option row stored at all, every toggle reads back as its
	 * documented default.
	 *
	 * @return void
	 */
	public function test_option_falls_back_to_the_default_when_unset(): void {
		$this->assertTrue($this->settings->option('comments_enabled'));
		$this->assertFalse($this->settings->option('comment_preview'));
		$this->assertFalse($this->settings->option('prism_line_numbers'));
	}

	/**
	 * A value present in the option row wins over the default.
	 *
	 * @return void
	 */
	public function test_option_reads_a_stored_value(): void {
		update_option(Ozhmd_Settings::OPTION_NAME, ['comments_enabled' => false]);

		$this->assertFalse($this->settings->option('comments_enabled'));
	}

	/**
	 * A key absent from an otherwise-stored option row falls back to its own
	 * default rather than reading as false by omission.
	 *
	 * @return void
	 */
	public function test_option_fills_a_missing_key_from_the_defaults(): void {
		update_option(Ozhmd_Settings::OPTION_NAME, ['comments_enabled' => false]);

		$this->assertFalse($this->settings->option('comment_preview'));
	}

	/**
	 * A key with no corresponding default is not a known option, so it reads
	 * false rather than raising an error.
	 *
	 * @return void
	 */
	public function test_option_returns_false_for_an_unknown_key(): void {
		$this->assertFalse($this->settings->option('no_such_option'));
	}

	/**
	 * A non-array value in the option row, the shape a hand edit could leave
	 * behind, does not break option(); the defaults win instead.
	 *
	 * @return void
	 */
	public function test_option_survives_a_corrupted_stored_value(): void {
		update_option(Ozhmd_Settings::OPTION_NAME, 'not an array');

		$this->assertTrue($this->settings->option('comments_enabled'));
	}

	/**
	 * sanitize() runs the payload through ozhmd_sanitize_options(), dropping
	 * unknown keys and coercing every known one to a boolean.
	 *
	 * @return void
	 */
	public function test_sanitize_delegates_to_the_pure_function(): void {
		$clean = $this->settings->sanitize(['comments_enabled' => '1', 'evil' => 'x']);

		$this->assertSame(
			['comments_enabled' => true, 'comment_preview' => false, 'prism_line_numbers' => false],
			$clean
		);
	}

	/**
	 * A payload that is not even an array still yields one boolean entry per
	 * known option key, none of it fatal.
	 *
	 * @return void
	 */
	public function test_sanitize_tolerates_a_non_array_payload(): void {
		$clean = $this->settings->sanitize('nonsense');

		$this->assertSame(array_keys(ozhmd_default_options()), array_keys($clean));
	}

	/**
	 * The rendered page includes a named checkbox field for each of the three
	 * toggles.
	 *
	 * @return void
	 */
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

	/**
	 * The rendered page lists eligible post types such as post and page, and
	 * omits one excluded by default, such as wp_block.
	 *
	 * @return void
	 */
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
