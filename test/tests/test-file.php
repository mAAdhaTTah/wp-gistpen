<?php

/**
 * @group objects
 * @group file
 */
class WP_Gistpen_File_Test extends WP_Gistpen_UnitTestCase {

	public $file_obj;
	public $file;

	function setUp() {
		parent::setUp();
		$this->file_obj = $this->factory->gistpen->create_and_get( array( 'post_parent' => $this->factory->gistpen->create() ) );
		$this->file = new WP_Gistpen_File( $this->file_obj, $this->mock_lang, $this->mock_post  );

		$this->mock_lang
			->expects( $this->any() )
			->method( '__get' )
			->with( $this->anything() );
	}

	function test_get_post_object() {
		$this->assertInstanceOf('WP_Post', $this->file->file);
	}

	function test_get_slug() {
		$this->assertContains( 'post-title', $this->file->slug );
	}

	function test_get_filename_with_extension() {
		$this->assertContains( 'post-title', $this->file->filename );
		$this->assertContains( '.', $this->file->filename );
		$this->assertNotContains( ' ', $this->file->filename );
	}

	function test_get_code() {
		$this->assertContains( 'Post content', $this->file->code );
	}

	function test_get_post_content() {
		// @todo write better tests
		// needs to test if valid HTML
		// make sure it contains code + filename somewhere
		$this->assertContains( '<div id="wp-gistpenfile-' . $this->file_obj->post_name . '">',  $this->file->post_content );
	}

	function test_get_shortcode_content() {
		// @todo write better tests
		// needs to test if valid HTML
		// make sure it contains code + filename somewhere
		// add test for highlight
		$this->assertContains( '<div id="wp-gistpenfile-' . $this->file_obj->post_name . '">',  $this->file->shortcode_content );
	}

	function test_update_post() {
		$this->file->slug = 'New slug';
		$this->file->code = 'echo $code';
		$this->mock_lang
			->expects($this->once())
			->method('update_post')
			->will($this->returnValue(true));

		$this->file->update_post();

		$this->assertEquals( 'new-slug', $this->file->file->post_name );
		$this->assertEquals( 'echo $code', $this->file->file->post_content );
	}

	function tearDown() {
		parent::tearDown();
	}
}
