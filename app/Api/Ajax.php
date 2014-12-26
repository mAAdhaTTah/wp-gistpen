<?php
namespace WP_Gistpen\Api;

use WP_Gistpen\Controller\Sync;
use WP_Gistpen\Facade\Database;
use WP_Gistpen\Facade\Adapter;

/**
 * This class handles all of the AJAX responses
 *
 * @package    Ajax
 * @author     James DiGioia <jamesorodig@gmail.com>
 * @link       http://jamesdigioia.com/wp-gistpen/
 * @since      0.4.0
 */
class Ajax {

	/**
	 * The ID of this plugin.
	 *
	 * @since    0.5.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    0.5.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Slug for the nonce field
	 *
	 * @var string
	 * @since  0.4.0
	 */
	private $nonce_field;

	/**
	 * Database Facade object
	 *
	 * @var Database
	 * @since 0.5.0
	 */
	public $database;

	/**
	 * Adapter Facade object
	 *
	 * @var Adapter
	 * @since  0.5.0
	 */
	public $adapter;

	/**
	 * Sync object
	 *
	 * @var Sync
	 * @since  0.5.0
	 */
	public $sync;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    0.5.0
	 * @var      string    $plugin_name       The name of this plugin.
	 * @var      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;
		$this->nonce_field = '_ajax_wp_gistpen';

		$this->database = new Database( $plugin_name, $version );
		$this->adapter = new Adapter( $plugin_name, $version );

		$this->sync = new Sync( $plugin_name, $version );

	}

	/**
	 * Embed the nonce in the head of the editor
	 *
	 * @return string    AJAX nonce
	 * @since  0.2.0
	 */
	public function embed_nonce() {
		wp_nonce_field( $this->nonce_field, $this->nonce_field, false );
	}

	/**
	 * Checks nonce and user permissions for AJAX reqs
	 *
	 * @return Sends error and halts execution if anything doesn't check out
	 * @since  0.4.0
	 */
	public function check_security() {
		// Check the nonce
		if ( ! isset($_POST['nonce']) || ! wp_verify_nonce( $_POST['nonce'], $this->nonce_field ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce check failed.', $this->plugin_name ) ) );
		}

		// Check if user has proper permisissions
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( "User doesn't have proper permisissions.", $this->plugin_name ) ) );
		}
	}

	/**
	 * Returns 5 most recent Gistpens
	 * or Gistpens matching search term
	 *
	 * @return string JSON-encoded array of post objects
	 * @since 0.4.0
	 */
	public function get_gistpens() {
		$this->check_security();

		if ( isset( $_POST['gistpen_search_term'] ) ) {
			$results = $this->database->query()->by_string( $_POST['gistpen_search_term'] );
		} else {
			$results = $this->database->query()->by_recent();
		}

		wp_send_json_success( array(
			'gistpens' => $results,
		) );
	}

	/**
	 * Responds to AJAX request to create new Gistpen
	 *
	 * @since  0.2.0
	 */
	public function create_gistpen() {
		$this->check_security();

		$zip_data = array(
			'description' => $_POST['wp-gistfile-description'],
			'status'      => $_POST['post_status'],
		);
		$zip = $this->adapter->build( 'zip' )->by_array( $zip_data );

		$file_data = array(
			'slug' => $_POST['wp-gistpenfile-slug'],
			'code' => $_POST['wp-gistpenfile-code'],
		);
		$file = $this->adapter->build( 'file' )->by_array( $file_data );

		$language = $this->adapter->build( 'language' )->by_slug( $_POST['wp-gistpenfile-language'] );
		$file->set_language( $language );

		$zip->add_file( $file );

		$result = $this->database->persist()->by_zip( $zip );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'id' => $result ) );
	}

	/**
	 * AJAX hook to save Gistpen in the editor
	 *
	 * @since 0.5.0
	 */
	public function save_gistpen() {
		$this->check_security();

		$zip_data = $_POST['zip'];

		if ( 'auto-draft' === $zip_data['status'] ) {
			$zip_data['status'] = 'draft';
		}

		$zip = $this->adapter->build( 'zip' )->by_array( $zip_data );

		foreach ( $zip_data['files'] as $file_data ) {
			$file = $this->adapter->build( 'file' )->by_array( $file_data );
			$file->set_language( $this->adapter->build( 'language' )->by_slug( $file_data['language'] ) );
			$zip->add_file( $file );
		}

		$result = $this->database->persist( 'head' )->by_zip( $zip );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'code'    => 'error',
				'message' => $result->get_error_message(),
			) );
		}

		wp_send_json_success( array(
			'code'    => 'updated',
			'message' => __( "Successfully updated Gistpen ", $this->plugin_name ) . $result,
		) );
	}

	/**
	 * Retrieves the ACE editor theme from the user meta
	 *
	 * @since 0.5.0
	 */
	public function get_ace_theme() {
		$this->check_security();

		wp_send_json_success( array( 'theme' => get_user_meta( get_current_user_id(), '_wpgp_ace_theme', true ) ) );
	}

	/**
	 * Saves the ACE editor theme to the user meta
	 *
	 * @since     0.4.0
	 */
	public function save_ace_theme() {
		$this->check_security();

		$result = update_user_meta( get_current_user_id(), '_wpgp_ace_theme', $_POST['theme'] );

		if ( ! $result ) {
			wp_send_json_error();
		}

		wp_send_json_success();
	}

	/**
	 * AJAX hook to get JSON of Gistpen IDs missing Gist IDs
	 *
	 * @since 0.5.0
	 */
	public function get_gistpens_missing_gist_id() {
		$this->check_security();

		$result = $this->database->query( 'head' )->missing_gist_id();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( empty( $result ) ) {
			wp_send_json_error( array( 'message' => __( 'No Gistpens missing Gist IDs.', $this->plugin_name ) ) );
		}

		wp_send_json_success( array( 'ids' => $result ) );
	}

	/**
	 * AJAX hook to trigger export of Gistpen
	 *
	 * @since 0.5.0
	 */
	public function create_gist_from_gistpen_id() {
		$this->check_security();

		// @todo escape this
		// cast to integer?
		$id = intval( $_POST['gistpen_id'] );

		if ( 0 === $id ) {
			wp_send_json_error( array(
				'code'    => 'error',
				'message' => __( 'Invalid Gistpen ID.', $this->plugin_name ),
			) );
		}

		$result = $this->sync->export_gistpen( $id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'code'    => 'error',
				'message' => $result->get_error_message(),
			) );
		}

		sleep( 1 );

		wp_send_json_success( array(
			'code'    => 'success',
			'message' => __( 'Successfully exported Gistpen: ', $this->plugin_name ) . $result->get_description(),
		) );
	}
}
