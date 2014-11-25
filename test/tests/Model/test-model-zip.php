<?php

use WP_Gistpen\Database\Query;
use WP_Gistpen\Model\Zip;
use WP_Gistpen\Model\File;
use WP_Gistpen\Model\Language;

/**
 * @group models
 */
class WP_Gistpen_Model_Zip_Test extends WP_Gistpen_UnitTestCase {

	function setUp() {
		parent::setUp();
		$this->zip = new Zip( WP_Gistpen::$plugin_name, WP_Gistpen::$version );
	}

	function test_get_set_description() {
		$this->zip->set_description( 'Post description' );

		$this->assertEquals( 'Post description', $this->zip->get_description() );
	}

	function test_get_files() {
		$this->assertCount( 0, $this->zip->get_files() );
	}

	function test_add_file() {
		$this->zip->add_file( $this->mock_file );

		$this->assertCount( 1, $this->zip->get_files() );
	}

	function test_get_set_ID() {
		$this->zip->set_ID( '123' );

		$this->assertEquals( 123, $this->zip->get_ID() );
	}

	function test_get_post_content() {
		$this->zip->add_file( $this->mock_file );

		$this->mock_file
			->expects( $this->once() )
			->method( 'get_post_content' )
			->will( $this->returnValue('Post content') );

		$this->assertContains( 'Post content', $this->zip->get_post_content() );
	}

	function test_get_shortcode_content() {
		$this->zip->add_file( $this->mock_file );

		$this->mock_file
			->expects( $this->once() )
			->method( 'get_shortcode_content' )
			->will( $this->returnValue('Shortcode content') );

		$this->assertContains( 'Shortcode content', $this->zip->get_shortcode_content() );
	}

	function tearDown() {
		parent::tearDown();
	}
}
