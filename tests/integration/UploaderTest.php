<?php

declare(strict_types=1);

require_once __DIR__ . '/class-ozhmd-integration-test-case.php';

/**
 * The REST upload endpoint, with an emphasis on who is refused.
 */
final class UploaderTest extends Ozhmd_Integration_Test_Case {

	/**
	 * Uploader under test.
	 *
	 * @var Ozhmd_Uploader
	 */
	private Ozhmd_Uploader $uploader;

	/**
	 * Build the uploader and register its route.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->uploader = new Ozhmd_Uploader();

		rest_get_server();
		$this->uploader->register_route();
	}

	/**
	 * Create a user with the given role and make them current.
	 *
	 * @param string $role Role to assign.
	 * @return int New user ID.
	 */
	private function login_as(string $role): int {
		$user_id = (int) wp_insert_user([
			'user_login' => 'ozhmd_' . $role . '_' . uniqid(),
			'user_pass'  => 'password',
			'user_email' => uniqid() . '@example.org',
			'role'       => $role,
		]);

		wp_set_current_user($user_id);

		return $user_id;
	}

	/**
	 * Build a request carrying an optional post ID.
	 *
	 * @param int $post_id Post to attach to, 0 for unattached.
	 * @return WP_REST_Request Prepared request.
	 */
	private function request(int $post_id = 0): WP_REST_Request {
		$request = new WP_REST_Request('POST', Ozhmd_Uploader::NAMESPACE . Ozhmd_Uploader::ROUTE);
		$request->set_param('post_id', $post_id);

		return $request;
	}

	/**
	 * The route is registered under the namespace the client expects.
	 *
	 * @return void
	 */
	public function test_the_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey('/ozhmd/v1/upload', $routes);
	}

	/**
	 * A logged out request is refused.
	 *
	 * @return void
	 */
	public function test_a_logged_out_request_is_refused(): void {
		wp_set_current_user(0);

		$result = $this->uploader->can_upload($this->request());

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ozhmd_cannot_upload', $result->get_error_code());
	}

	/**
	 * A subscriber, who cannot upload files, is refused.
	 *
	 * @return void
	 */
	public function test_a_subscriber_is_refused(): void {
		$this->login_as('subscriber');

		$result = $this->uploader->can_upload($this->request());

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ozhmd_cannot_upload', $result->get_error_code());
	}

	/**
	 * An author who may upload is allowed when no post is named.
	 *
	 * @return void
	 */
	public function test_an_author_is_allowed(): void {
		$this->login_as('author');

		$this->assertTrue($this->uploader->can_upload($this->request()));
	}

	/**
	 * Supplying somebody else's post ID is refused, even for an uploader.
	 *
	 * @return void
	 */
	public function test_attaching_to_a_post_the_user_cannot_edit_is_refused(): void {
		$owner_id = $this->login_as('author');

		$post_id = (int) wp_insert_post([
			'post_title'  => 'Owned by someone else',
			'post_status' => 'publish',
			'post_author' => $owner_id,
		]);

		// A different author, who may upload but may not touch that post.
		$this->login_as('author');

		$result = $this->uploader->can_upload($this->request($post_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ozhmd_cannot_edit_post', $result->get_error_code());
	}

	/**
	 * Attaching to a post the user owns is allowed.
	 *
	 * @return void
	 */
	public function test_attaching_to_an_own_post_is_allowed(): void {
		$user_id = $this->login_as('author');

		$post_id = (int) wp_insert_post([
			'post_title'  => 'My own post',
			'post_status' => 'draft',
			'post_author' => $user_id,
		]);

		$this->assertTrue($this->uploader->can_upload($this->request($post_id)));
	}

	/**
	 * A request with no file attached is rejected with a 400.
	 *
	 * @return void
	 */
	public function test_a_request_without_a_file_is_rejected(): void {
		$this->login_as('administrator');

		$result = $this->uploader->handle($this->request());

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ozhmd_no_file', $result->get_error_code());
		$this->assertSame(400, $result->get_error_data()['status']);
	}

	/**
	 * A non image file is rejected before WordPress ever stores it.
	 *
	 * @return void
	 */
	public function test_a_non_image_is_rejected(): void {
		$this->login_as('administrator');

		$request = $this->request();
		$request->set_file_params([
			'file' => [
				'name'     => 'notes.txt',
				'type'     => 'text/plain',
				'tmp_name' => '/tmp/whatever',
				'error'    => 0,
				'size'     => 10,
			],
		]);

		$result = $this->uploader->handle($request);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ozhmd_not_an_image', $result->get_error_code());
		$this->assertSame(415, $result->get_error_data()['status']);
	}

	/**
	 * A file claiming to be an image by MIME but not by name is rejected.
	 *
	 * The browser supplied type is attacker controlled, so the file name is
	 * what decides.
	 *
	 * @return void
	 */
	public function test_a_spoofed_content_type_is_rejected(): void {
		$this->login_as('administrator');

		$request = $this->request();
		$request->set_file_params([
			'file' => [
				'name'     => 'payload.php',
				'type'     => 'image/png',
				'tmp_name' => '/tmp/whatever',
				'error'    => 0,
				'size'     => 10,
			],
		]);

		$result = $this->uploader->handle($request);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ozhmd_not_an_image', $result->get_error_code());
	}

	/**
	 * An SVG is rejected while the site does not allow that type.
	 *
	 * @return void
	 */
	public function test_an_svg_is_rejected_when_the_site_disallows_it(): void {
		$this->login_as('administrator');

		$this->assertArrayNotHasKey('svg', get_allowed_mime_types());

		$request = $this->request();
		$request->set_file_params([
			'file' => [
				'name'     => 'vector.svg',
				'type'     => 'image/svg+xml',
				'tmp_name' => '/tmp/whatever',
				'error'    => 0,
				'size'     => 10,
			],
		]);

		$result = $this->uploader->handle($request);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ozhmd_not_an_image', $result->get_error_code());
	}
}
