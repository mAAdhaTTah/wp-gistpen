<?php
namespace Intraxia\Gistpen\Test\Integration\Http\Blob;

use WP_REST_Request;
use Intraxia\Gistpen\Model\Blob;
use Intraxia\Gistpen\Model\Language;
use Intraxia\Gistpen\Model\Repo;
use Intraxia\Gistpen\Test\Integration\TestCase;

class CreateTest extends TestCase {

	/** @var Repo */
	public $repo;

	/** @var Blob */
	public $blob;

	public function setUp() {
		parent::setUp();
		$this->repo = $this->fm->create( Repo::class );
		$this->blob = $this->fm->instance( Blob::class );
	}

	public function test_requires_admin() {
		$this->set_role( 'subscriber' );
		$request = new WP_REST_Request( 'POST', "/intraxia/v1/gistpen/repos/{$this->repo->ID}/blobs" );
		$request->set_body_params( [
			'filename' => $this->blob->filename,
			'code'     => $this->blob->code,
		] );

		$response = $this->server->dispatch( $request );

		$this->assertResponseStatus( $response, 401 );
	}

	public function test_returns_error_when_filename_missing() {
		$this->set_role( 'administrator' );
		$request = new WP_REST_Request( 'POST', "/intraxia/v1/gistpen/repos/{$this->repo->ID}/blobs" );
		$request->set_body_params( [] );

		$response = $this->server->dispatch( $request );

		$this->assertResponseStatus( $response, 400 );
		$this->assertResponseData( $response, [
			'code'    => 'rest_missing_callback_param',
			'message' => 'Missing parameter(s): filename',
			'data'    => [
				'status' => 400,
				'params' => [ 'filename' ],
			],
		] );
	}

	public function test_returns_404_on_nonexistant_repo() {
		$this->set_role( 'administrator' );
		$request = new WP_REST_Request( 'POST', '/intraxia/v1/gistpen/repos/12342341/blobs' );
		$request->set_body_params( [
			'filename' => $this->blob->filename,
		] );

		$response = $this->server->dispatch( $request );

		$this->assertResponseStatus( $response, 404 );
		$this->assertResponseData( $response, [
			'code'    => 'invalid_data',
			'message' => 'post id 12342341 is invalid',
			'data'    => [
				'status' => 404,
			],
		] );
	}

	public function test_saves_blob_with_filename_to_repo() {
		$this->set_role( 'administrator' );
		$request = new WP_REST_Request( 'POST', "/intraxia/v1/gistpen/repos/{$this->repo->ID}/blobs" );
		$request->set_body_params( [
			'filename' => $this->blob->filename,
		] );

		$response = $this->server->dispatch( $request );

		$repo = $this->app->make( 'database' )
			->find( Repo::class, $this->repo->ID, [
				'with' => [
					'blobs'   => [
						'with' => [
							'language' => [],
						],
					],
					'commits' => [
						'with' => [
							'states' => [],
						],
					],
				],
			] );

		$blob = $repo->blobs->last();

		$this->assertResponseStatus( $response, 201 );
		$this->assertResponseData( $response, [
			'ID'       => $blob->ID,
			'size'     => $blob->size,
			'raw_url'  => $blob->raw_url,
			'edit_url' => $blob->edit_url,
			'filename' => $blob->filename,
			'code'     => $blob->code,
			'language' => [
				'ID'           => $blob->language->ID,
				'display_name' => $blob->language->display_name,
				'slug'         => $blob->language->slug,
			],
		] );
		$this->assertSame( $blob->filename, $this->blob->filename );
		$this->assertCount( 2, $repo->commits );
	}

	public function test_saves_blob_with_filename_code_and_language() {
		$this->set_role( 'administrator' );
		$request = new WP_REST_Request( 'POST', "/intraxia/v1/gistpen/repos/{$this->repo->ID}/blobs" );
		$request->set_body_params( [
			'filename' => $this->blob->filename,
			'code'     => $this->blob->code,
			'language' => $this->blob->language->slug,
		] );

		$response = $this->server->dispatch( $request );

		$repo = $this->app->make( 'database' )
			->find( Repo::class, $this->repo->ID, [
				'with' => [
					'blobs' => [
						'with' => [
							'language' => [],
						],
					],
				],
			] );

		$blob = $repo->blobs->last();

		$this->assertResponseStatus( $response, 201 );
		$this->assertResponseData( $response, [
			'ID'       => $blob->ID,
			'size'     => $blob->size,
			'raw_url'  => $blob->raw_url,
			'edit_url' => $blob->edit_url,
			'filename' => $this->blob->filename,
			'code'     => $blob->code,
			'language' => [
				'ID'           => $blob->language->ID,
				'display_name' => $blob->language->display_name,
				'slug'         => $this->blob->language->slug,
			],
		] );
		$this->assertSame( $blob->filename, $this->blob->filename );
		$this->assertSame( $blob->code, $this->blob->code );
		$this->assertSame( $blob->language->slug, $this->blob->language->slug );
	}
}
