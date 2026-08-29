<?php

declare(strict_types=1);

require_once __DIR__ . '/class-ozhmd-integration-test-case.php';

/**
 * The post save pipeline: Markdown into meta, HTML into post_content.
 */
final class PostStorageTest extends Ozhmd_Integration_Test_Case {

	/**
	 * Storage under test.
	 *
	 * @var Ozhmd_Post_Storage
	 */
	private Ozhmd_Post_Storage $storage;

	/**
	 * Build a storage service wired to real converters.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$factory   = new Ozhmd_Converter_Factory();
		$converter = new Ozhmd_Converter($factory->create());

		$this->storage = new Ozhmd_Post_Storage($converter, new Ozhmd_Post_Types());
	}

	/**
	 * Create a draft post and return its ID.
	 *
	 * @param string $content Initial post_content.
	 * @return int New post ID.
	 */
	private function make_post(string $content = ''): int {
		return (int) wp_insert_post([
			'post_title'   => 'Storage test',
			'post_content' => $content,
			'post_status'  => 'draft',
		]);
	}

	/**
	 * A post with no meta at all reports itself as legacy.
	 *
	 * @return void
	 */
	public function test_a_post_without_meta_is_legacy(): void {
		$post_id = $this->make_post('<p>old html</p>');

		$this->assertNull($this->storage->stored_markdown($post_id));
		$this->assertSame('legacy', ozhmd_post_state($this->storage->stored_markdown($post_id)));
	}

	/**
	 * An empty stored value is a Markdown post with an empty body, not a legacy one.
	 *
	 * @return void
	 */
	public function test_an_empty_stored_value_is_not_legacy(): void {
		$post_id = $this->make_post();
		update_post_meta($post_id, OZHMD_POST_META_KEY, '');

		$this->assertSame('', $this->storage->stored_markdown($post_id));
		$this->assertSame('markdown', ozhmd_post_state($this->storage->stored_markdown($post_id)));
	}

	/**
	 * Stored Markdown is converted into post_content on save.
	 *
	 * @return void
	 */
	public function test_conversion_writes_html_into_post_content(): void {
		$post_id = $this->make_post();
		update_post_meta($post_id, OZHMD_POST_META_KEY, "# Title\n\nSome *emphasis*.");

		$this->storage->convert_to_post_content($post_id, get_post($post_id));

		$content = get_post($post_id)->post_content;

		$this->assertStringContainsString('<h1>Title</h1>', $content);
		$this->assertStringContainsString('<em>emphasis</em>', $content);
	}

	/**
	 * A fenced block keeps the language class Prism needs.
	 *
	 * @return void
	 */
	public function test_conversion_emits_a_prism_language_class(): void {
		$post_id = $this->make_post();
		update_post_meta($post_id, OZHMD_POST_META_KEY, "```php\necho 1;\n```");

		$this->storage->convert_to_post_content($post_id, get_post($post_id));

		$this->assertStringContainsString('<pre><code class="language-php">', get_post($post_id)->post_content);
	}

	/**
	 * An author with unfiltered_html keeps raw HTML embeds through conversion.
	 *
	 * @return void
	 */
	public function test_conversion_preserves_embeds_for_a_privileged_author(): void {
		wp_set_current_user((int) wp_insert_user([
			'user_login' => 'ozhmd_admin_' . uniqid(),
			'user_pass'  => 'password',
			'user_email' => uniqid() . '@example.org',
			'role'       => 'administrator',
		]));

		$post_id = $this->make_post();
		update_post_meta($post_id, OZHMD_POST_META_KEY, '<iframe src="https://example.com/embed"></iframe>');

		$this->storage->convert_to_post_content($post_id, get_post($post_id));

		$this->assertStringContainsString('<iframe src="https://example.com/embed">', get_post($post_id)->post_content);
	}

	/**
	 * WordPress KSES still strips embeds for an author without unfiltered_html.
	 *
	 * This is the gate the trusted converter deliberately relies on: it emits
	 * raw HTML, and WordPress decides who is allowed to store it.
	 *
	 * @return void
	 */
	public function test_kses_still_strips_embeds_for_an_unprivileged_author(): void {
		wp_set_current_user((int) wp_insert_user([
			'user_login' => 'ozhmd_contrib_' . uniqid(),
			'user_pass'  => 'password',
			'user_email' => uniqid() . '@example.org',
			'role'       => 'contributor',
		]));

		$post_id = $this->make_post();
		update_post_meta($post_id, OZHMD_POST_META_KEY, '<iframe src="https://evil.example/x"></iframe>');

		$this->storage->convert_to_post_content($post_id, get_post($post_id));

		$this->assertStringNotContainsString('<iframe', get_post($post_id)->post_content);
	}

	/**
	 * A legacy post's existing HTML is never rewritten by the conversion hook.
	 *
	 * @return void
	 */
	public function test_a_legacy_post_is_left_alone(): void {
		$original = "<p>Legacy html with some_underscores and *asterisks*.</p>";
		$post_id  = $this->make_post($original);

		$this->storage->convert_to_post_content($post_id, get_post($post_id));

		$this->assertSame($original, get_post($post_id)->post_content);
	}

	/**
	 * Autosaves are skipped, so a draft in progress cannot trigger a rewrite.
	 *
	 * @return void
	 */
	public function test_an_autosave_is_skipped(): void {
		$author_id = (int) wp_insert_user([
			'user_login' => 'ozhmd_author_' . uniqid(),
			'user_pass'  => 'password',
			'user_email' => uniqid() . '@example.org',
			'role'       => 'administrator',
		]);
		wp_set_current_user($author_id);

		$post_id = (int) wp_insert_post([
			'post_title'   => 'Storage test',
			'post_content' => '<p>untouched</p>',
			'post_status'  => 'draft',
			'post_author'  => $author_id,
		]);
		update_post_meta($post_id, OZHMD_POST_META_KEY, '# Would convert');

		require_once ABSPATH . 'wp-admin/includes/post.php';

		$autosave = wp_create_post_autosave([
			'post_ID'      => $post_id,
			'post_title'   => 'Storage test',
			'post_content' => 'autosaved',
			'post_type'    => 'post',
		]);

		$this->assertNotWPError($autosave, 'The autosave fixture itself must succeed.');

		$autosave_id = (int) $autosave;

		$this->storage->convert_to_post_content($autosave_id, get_post($autosave_id));

		$this->assertSame('<p>untouched</p>', get_post($post_id)->post_content);
	}

	/**
	 * Fail the test when a value is a WP_Error, reporting its message.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $message Context for the failure.
	 * @return void
	 */
	private function assertNotWPError($value, string $message = ''): void {
		if (is_wp_error($value)) {
			$this->fail($message . ' WP_Error: ' . $value->get_error_message());
		}

		$this->assertTrue(true);
	}

	/**
	 * An excluded post type is never touched, even with Markdown meta present.
	 *
	 * @return void
	 */
	public function test_an_excluded_post_type_is_not_converted(): void {
		$block_id = (int) wp_insert_post([
			'post_title'   => 'A reusable block',
			'post_content' => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
			'post_type'    => 'wp_block',
		]);
		update_post_meta($block_id, OZHMD_POST_META_KEY, '# Should not apply');

		$this->storage->convert_to_post_content($block_id, get_post($block_id));

		$this->assertStringContainsString('<!-- wp:paragraph -->', get_post($block_id)->post_content);
	}

	/**
	 * Converting twice with unchanged Markdown does not keep rewriting the post.
	 *
	 * @return void
	 */
	public function test_conversion_is_idempotent(): void {
		$post_id = $this->make_post();
		update_post_meta($post_id, OZHMD_POST_META_KEY, '# Stable');

		$this->storage->convert_to_post_content($post_id, get_post($post_id));
		$first = get_post($post_id)->post_content;

		$this->storage->convert_to_post_content($post_id, get_post($post_id));

		$this->assertSame($first, get_post($post_id)->post_content);
	}

	/**
	 * The meta key is exposed to REST so clients can send Markdown.
	 *
	 * @return void
	 */
	public function test_the_markdown_meta_is_registered_for_rest(): void {
		$this->storage->register_meta();

		$registered = get_registered_meta_keys('post', 'post');

		$this->assertArrayHasKey(OZHMD_POST_META_KEY, $registered);
		$this->assertTrue($registered[OZHMD_POST_META_KEY]['show_in_rest']);
	}
}
