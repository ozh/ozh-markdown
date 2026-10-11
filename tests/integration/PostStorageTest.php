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
	 * Create an administrator and make them the current user.
	 *
	 * @return int New user ID.
	 */
	private function login_as_administrator(): int {
		$user_id = (int) wp_insert_user([
			'user_login' => 'ozhmd_admin_' . uniqid(),
			'user_pass'  => 'password',
			'user_email' => uniqid() . '@example.org',
			'role'       => 'administrator',
		]);

		wp_set_current_user($user_id);

		return $user_id;
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
	public function test_a_post_without_meta_but_with_content_is_legacy(): void {
		$post_id = $this->make_post('<p>old html</p>');

		$this->assertNull($this->storage->stored_markdown($post_id));
		$this->assertSame('legacy', ozhmd_post_state($this->storage->stored_markdown($post_id), '<p>old html</p>'));
	}

	/**
	 * A brand new post converts on save without any opt in.
	 *
	 * @return void
	 */
	public function test_a_brand_new_post_converts_without_opting_in(): void {
		$this->login_as_administrator();

		$post_id = $this->make_post();

		$_POST = [
			'ozhmd_markdown' => "# Hello\n\nSome *emphasis*.",
			'ozhmd_nonce'    => wp_create_nonce('ozhmd_save_' . $post_id),
		];

		$this->storage->store_submitted_markdown($post_id, get_post($post_id));
		$this->storage->convert_to_post_content($post_id, get_post($post_id));

		$_POST = [];

		$this->assertSame("# Hello\n\nSome *emphasis*.", $this->storage->stored_markdown($post_id));
		$this->assertStringContainsString('<h1>Hello</h1>', get_post($post_id)->post_content);
	}

	/**
	 * A legacy post saved without opting in keeps its HTML byte for byte.
	 *
	 * @return void
	 */
	public function test_a_legacy_post_saved_without_opting_in_is_not_converted(): void {
		$this->login_as_administrator();

		$original = '<p>Old HTML with some_underscores.</p>';
		$post_id  = $this->make_post($original);

		$_POST = [
			'ozhmd_markdown' => $original,
			'ozhmd_nonce'    => wp_create_nonce('ozhmd_save_' . $post_id),
		];

		$this->storage->store_submitted_markdown($post_id, get_post($post_id));
		$this->storage->convert_to_post_content($post_id, get_post($post_id));

		$_POST = [];

		$this->assertNull($this->storage->stored_markdown($post_id), 'No meta means it stayed legacy.');
		$this->assertSame($original, get_post($post_id)->post_content);
	}

	/**
	 * Ticking the convert box backs up the original and converts from then on.
	 *
	 * @return void
	 */
	public function test_opting_in_backs_up_the_original_and_converts(): void {
		$this->login_as_administrator();

		$original = '<p>Old HTML.</p>';
		$post_id  = $this->make_post($original);

		$_POST = [
			'ozhmd_markdown' => "# Now markdown",
			'ozhmd_nonce'    => wp_create_nonce('ozhmd_save_' . $post_id),
			'ozhmd_convert'  => '1',
		];

		$this->storage->store_submitted_markdown($post_id, get_post($post_id));
		$this->storage->convert_to_post_content($post_id, get_post($post_id));

		$_POST = [];

		$this->assertSame($original, get_post_meta($post_id, OZHMD_ORIGINAL_META_KEY, true));
		$this->assertSame('# Now markdown', $this->storage->stored_markdown($post_id));
		$this->assertStringContainsString('<h1>Now markdown</h1>', get_post($post_id)->post_content);
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
		$this->assertSame('markdown', ozhmd_post_state($this->storage->stored_markdown($post_id), ''));
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

	/**
	 * Create a user with the given role and return its ID.
	 *
	 * @param string $role Role to give the user.
	 * @return int New user ID.
	 */
	private function make_user(string $role): int {
		return (int) wp_insert_user([
			'user_login' => 'ozhmd_' . $role . '_' . uniqid(),
			'user_pass'  => 'password',
			'user_email' => uniqid() . '@example.org',
			'role'       => $role,
		]);
	}

	/**
	 * Markup a contributor wrote is not published by an administrator's save.
	 *
	 * The stored Markdown is never filtered, only the HTML made from it. KSES
	 * keyed on the current user alone would let any save by an administrator,
	 * even a Quick Edit of the title, publish the contributor's raw markup.
	 *
	 * @return void
	 */
	public function test_a_contributors_markup_is_not_published_by_an_administrators_save(): void {
		$contributor = $this->make_user('contributor');
		wp_set_current_user($contributor);

		$post_id = $this->make_post();
		update_post_meta($post_id, OZHMD_POST_META_KEY, 'Hello <img src="x" onerror="alert(1)"><script>alert(2)</script>');

		wp_set_current_user($this->make_user('administrator'));

		$this->storage->convert_to_post_content($post_id, get_post($post_id));

		$content = get_post($post_id)->post_content;

		$this->assertStringContainsString('Hello', $content);
		$this->assertStringNotContainsString('onerror', $content);
		$this->assertStringNotContainsString('<script', $content);
	}

	/**
	 * Backslashes survive the form save, into the meta and into post_content.
	 *
	 * WordPress slashes $_POST, and both update_post_meta() and wp_update_post()
	 * strip slashes from what they are given, so anything unslashed once by
	 * hand and then passed on loses its backslashes.
	 *
	 * @return void
	 */
	public function test_backslashes_survive_the_form_save(): void {
		$this->login_as_administrator();

		$markdown = "Path C:\\Users\\ozh\n\n```\nprintf(\"a\\n\");\n```";
		$post_id  = $this->make_post();

		$_POST = [
			'ozhmd_markdown' => wp_slash($markdown),
			'ozhmd_nonce'    => wp_create_nonce('ozhmd_save_' . $post_id),
		];

		$this->storage->store_submitted_markdown($post_id, get_post($post_id));
		$this->storage->convert_to_post_content($post_id, get_post($post_id));

		$_POST = [];

		$this->assertSame($markdown, $this->storage->stored_markdown($post_id));
		$this->assertStringContainsString('C:\\Users\\ozh', get_post($post_id)->post_content);
		$this->assertStringContainsString('printf(&quot;a\\n&quot;);', get_post($post_id)->post_content);
	}

	/**
	 * Backslashes survive a legacy save, and the backup taken on converting.
	 *
	 * @return void
	 */
	public function test_backslashes_survive_a_legacy_save_and_its_backup(): void {
		$this->login_as_administrator();

		$original = '<p>Path C:\\Users\\ozh</p>';
		$post_id  = $this->make_post(wp_slash($original));
		$edited   = '<p>Edited C:\\Users\\ozh</p>';

		$this->assertSame($original, get_post($post_id)->post_content, 'The fixture itself must keep its backslashes.');

		$_POST = [
			'ozhmd_markdown' => wp_slash($edited),
			'ozhmd_nonce'    => wp_create_nonce('ozhmd_save_' . $post_id),
		];

		$this->storage->store_submitted_markdown($post_id, get_post($post_id));

		$this->assertSame($edited, get_post($post_id)->post_content);

		$_POST = [
			'ozhmd_markdown' => wp_slash('# Now markdown'),
			'ozhmd_nonce'    => wp_create_nonce('ozhmd_save_' . $post_id),
			'ozhmd_convert'  => '1',
		];

		$this->storage->store_submitted_markdown($post_id, get_post($post_id));

		$_POST = [];

		$this->assertSame($edited, get_post_meta($post_id, OZHMD_ORIGINAL_META_KEY, true));
	}

	/**
	 * Markdown sent over REST is converted by that same request.
	 *
	 * The REST controller writes meta after save_post has fired, so the
	 * conversion has to wait for wp_after_insert_post to see it.
	 *
	 * @return void
	 */
	public function test_markdown_sent_over_rest_is_converted(): void {
		$this->login_as_administrator();

		$post_id = $this->make_post();
		$request = new WP_REST_Request('POST', '/wp/v2/posts/' . $post_id);
		$request->set_body_params(['meta' => [OZHMD_POST_META_KEY => '# Sent over REST']]);

		$response = rest_do_request($request);

		$this->assertSame(200, $response->get_status());
		$this->assertSame('# Sent over REST', $this->storage->stored_markdown($post_id));
		$this->assertStringContainsString('<h1>Sent over REST</h1>', get_post($post_id)->post_content);
	}
}
