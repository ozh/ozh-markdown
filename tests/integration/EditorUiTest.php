<?php

declare(strict_types=1);

require_once __DIR__ . '/class-ozhmd-integration-test-case.php';

/**
 * The editor screen: classic editor removal, meta box, and toolbar markup.
 */
final class EditorUiTest extends Ozhmd_Integration_Test_Case {

	/**
	 * Post type service under test.
	 *
	 * @var Ozhmd_Post_Types
	 */
	private Ozhmd_Post_Types $post_types;

	/**
	 * Editor UI under test.
	 *
	 * @var Ozhmd_Editor_UI
	 */
	private Ozhmd_Editor_UI $editor;

	/**
	 * Build the services the edit screen depends on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->post_types = new Ozhmd_Post_Types();

		$factory = new Ozhmd_Converter_Factory();
		$storage = new Ozhmd_Post_Storage(new Ozhmd_Converter($factory->create()), $this->post_types);

		$this->editor = new Ozhmd_Editor_UI($this->post_types, $storage);
	}

	/**
	 * Editor support is dropped so edit-form-advanced.php stops rendering
	 * the classic content box.
	 *
	 * @return void
	 */
	public function test_the_classic_editor_is_removed_for_posts(): void {
		$this->assertTrue(post_type_supports('post', 'editor'));

		$this->post_types->remove_default_editor('post');

		$this->assertFalse(post_type_supports('post', 'editor'));
	}

	/**
	 * The same holds for pages, which the bug report named specifically.
	 *
	 * @return void
	 */
	public function test_the_classic_editor_is_removed_for_pages(): void {
		$this->post_types->remove_default_editor('page');

		$this->assertFalse(post_type_supports('page', 'editor'));
	}

	/**
	 * An excluded post type keeps its editor.
	 *
	 * @return void
	 */
	public function test_an_excluded_post_type_keeps_its_editor(): void {
		$this->post_types->remove_default_editor('wp_block');

		$this->assertTrue(post_type_supports('wp_block', 'editor'));
	}

	/**
	 * Removal runs after registration, so the meta box still lands.
	 *
	 * Ordering is the whole point: eligible() reads editor support, so a
	 * removal running first would leave nothing eligible and no meta box.
	 *
	 * @return void
	 */
	public function test_the_meta_box_is_registered_before_support_is_removed(): void {
		global $wp_meta_boxes;

		$this->require_admin_includes();

		$this->post_types->register_hooks();
		$this->editor->register_hooks();

		do_action('add_meta_boxes', 'post', null);

		$this->assertArrayHasKey('ozhmd_editor', $wp_meta_boxes['post']['normal']['high']);
		$this->assertFalse(post_type_supports('post', 'editor'));
	}

	/**
	 * Assets still enqueue after the classic editor has been removed.
	 *
	 * This is the real edit screen order: edit-form-advanced.php fires
	 * add_meta_boxes at line 264, which strips editor support, and
	 * admin_enqueue_scripts only later at line 418. A previous version asked
	 * eligible() at that point, got nothing back because it reads editor
	 * support, and enqueued no assets at all.
	 *
	 * @return void
	 */
	public function test_assets_enqueue_after_the_editor_has_been_removed(): void {
		$this->require_admin_includes();

		// Calls the hook callbacks directly rather than firing add_meta_boxes.
		// The plugin's bootstrap already has its own Ozhmd_Post_Types hooked,
		// and it would strip support before this instance saw the action, so
		// the action route would exercise two instances where production has
		// exactly one.
		$this->post_types->remove_default_editor('post');

		$this->assertFalse(post_type_supports('post', 'editor'), 'Precondition: support must be gone.');
		$this->assertContains('post', $this->post_types->eligible(), 'post must stay eligible after the strip.');

		set_current_screen('post');
		$GLOBALS['current_screen']->post_type = 'post';

		$this->editor->enqueue_assets('post-new.php');

		foreach (['ozhmd-editor', 'ozhmd-toolbar', 'ozhmd-upload', 'ozhmd-codemirror', 'ozhmd-marked', 'ozhmd-prism'] as $handle) {
			$this->assertTrue(wp_script_is($handle, 'enqueued'), 'not enqueued: ' . $handle);
		}
	}

	/**
	 * Every toolbar button carries an action, an icon and a label.
	 *
	 * @return void
	 */
	public function test_every_toolbar_button_is_fully_described(): void {
		foreach ($this->editor->toolbar_buttons() as $button) {
			$this->assertNotSame('', $button['action']);
			$this->assertNotSame('', $button['icon']);
			$this->assertNotSame('', $button['label']);
		}
	}

	/**
	 * The rendered screen carries the toolbar, the textarea and the nonce.
	 *
	 * @return void
	 */
	public function test_the_rendered_editor_contains_its_parts(): void {
		$this->require_admin_includes();

		$post_id = (int) wp_insert_post([
			'post_title'  => 'Render check',
			'post_status' => 'draft',
		]);

		ob_start();

		try {
			$this->editor->render(get_post($post_id));
		} finally {
			$html = (string) ob_get_clean();
		}

		$this->assertStringContainsString('data-ozhmd-toolbar', $html);
		$this->assertStringContainsString('data-ozhmd-editor', $html);
		$this->assertStringContainsString('data-ozhmd-preview', $html);
		$this->assertStringContainsString('name="ozhmd_markdown"', $html);
		$this->assertStringContainsString('name="ozhmd_nonce"', $html);

		foreach ($this->editor->toolbar_buttons() as $button) {
			$this->assertStringContainsString('data-ozhmd-action="' . $button['action'] . '"', $html);
		}
	}
}
