<?php
namespace Intraxia\Gistpen\Http;

use Intraxia\Gistpen\Database\EntityManager;
use Intraxia\Jaxion\Contract\Axolotl\EntityManager as Database;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class RepoController
 *
 * @package Intraxia\Gistpen
 * @subpackage Http
 * @since 1.0.0
 */
class RepoController {
	/**
	 * Database interface service.
	 *
	 * @var Database
	 */
	protected $database;

	/**
	 * RepoController constructor.
	 *
	 * @param Database $database
	 */
	public function __construct( Database $database ) {
		$this->database = $database;
	}

	/**
	 * Retrieves a collection of Repos based on the provided params.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function index( WP_REST_Request $request ) {
		$collection = $this->database->find_by( EntityManager::REPO_CLASS, $request->get_params() );

		if ( is_wp_error( $collection ) ) {
			$collection->add_data( array( 'status' => 500 ) );

			return $collection;
		}

		return new WP_REST_Response( $collection->serialize(), 200 );
	}

	/**
	 * Creates a new Repo based on the provided params and returns it.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$model = $this->database->create( EntityManager::REPO_CLASS, $request->get_params() );

		if ( is_wp_error( $model ) ) {
			$model->add_data( array( 'status' => 500 ) );

			return $model;
		}

		return new WP_REST_Response( $model->serialize(), 201 );
	}

	/**
	 * Retrieves a Repo for the provided id.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function view( WP_REST_Request $request ) {
		$model = $this->database->find( EntityManager::REPO_CLASS, $request->get_param( 'id' ) );

		if ( is_wp_error( $model ) ) {
			$model->add_data( array( 'status' => 404 ) );

			return $model;
		}

		return new WP_REST_Response( $model->serialize(), 200 );
	}

	/**
	 * Updates all of the model's properties with the request params.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$model = $this->database->find( EntityManager::REPO_CLASS, $request->get_param( 'id' ) );

		if ( is_wp_error( $model ) ) {
			$model->add_data( array( 'status' => 404 ) );

			return $model;
		}

		$model->refresh( $request->get_json_params() );

		$model = $this->database->persist( $model );

		if ( is_wp_error( $model ) ) {
			$model->add_data( array( 'status' => 500 ) );

			return $model;
		}

		return new WP_REST_Response( $model->serialize(), 200 );
	}

	/**
	 * Applies a patch to the model's properties of the request params.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply( WP_REST_Request $request ) {
		$model = $this->database->find( EntityManager::REPO_CLASS, $request->get_param( 'id' ) );

		if ( is_wp_error( $model ) ) {
			$model->add_data( array( 'status' => 404 ) );

			return $model;
		}

		$model->merge( $request->get_json_params() );

		$model = $this->database->persist( $model );

		if ( is_wp_error( $model ) ) {
			$model->add_data( array( 'status' => 500 ) );

			return $model;
		}

		return new WP_REST_Response( $model->serialize(), 200 );
	}

	/**
	 * Sends the model to the trash or deletes it from the database.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function trash( WP_REST_Request $request ) {
		$model = $this->database->find( EntityManager::REPO_CLASS, $request->get_param( 'id' ) );

		if ( is_wp_error( $model ) ) {
			$model->add_data( array( 'status' => 404 ) );

			return $model;
		}

		$result = $this->database->delete( $model, false );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );

			return $result;
		}

		return new WP_REST_Response( null, 204 );
	}
}
