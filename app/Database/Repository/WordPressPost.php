<?php

namespace Intraxia\Gistpen\Database\Repository;

use Exception;
use Intraxia\Gistpen\Model\Blob;
use Intraxia\Gistpen\Model\Commit;
use Intraxia\Gistpen\Model\Language;
use Intraxia\Gistpen\Model\Repo;
use Intraxia\Gistpen\Model\State;
use Intraxia\Jaxion\Axolotl\Collection;
use Intraxia\Jaxion\Axolotl\GuardedPropertyException;
use Intraxia\Jaxion\Axolotl\Model;
use Intraxia\Jaxion\Axolotl\PropertyDoesNotExistException;
use Intraxia\Jaxion\Contract\Axolotl\EntityManager;
use Intraxia\Jaxion\Contract\Axolotl\UsesWordPressPost;
use WP_Error;
use WP_Query;

/**
 * Repository for managing Model backed by a WordPress Post.
 */
class WordPressPost extends AbstractRepository {

	/**
	 * {@inheritDoc}
	 *
	 * @param string $class
	 * @param int    $id
	 * @param array  $params
	 *
	 * @return WP_Error|Model
	 */
	public function find( $class, $id, array $params = array() ) {
		$post_type = $class::get_post_type();
		$post      = get_post( $id );

		if ( ! $post || $post->post_type !== $post_type ) {
			return new WP_Error(
				'invalid_data',
				sprintf(
					/* translators: %s: Post ID. */
					__( 'post id %s is invalid', 'wp-gistpen' ),
					$id
				)
			);
		}

		if ( Blob::class === $class && 0 === $post->post_parent ) {
			return new WP_Error(
				'invalid_data',
				sprintf(
					/* translators: %s: Post ID. */
					__( 'post id %s is invalid', 'wp-gistpen' ),
					$id
				)
			);
		}

		$model = new $class( array( Model::OBJECT_KEY => $post ) );
		$table = array();

		foreach ( $model->get_table_keys() as $key ) {
			if ( 'states' === $key ) {
				$table[ $key ] = new Collection( State::class );
			}

			// @todo handle related keys specially for now.
			if ( in_array( $key, array( 'blobs', 'language', 'states' ), true ) ) {
				continue;
			}

			$value = $table[ $key ] = get_post_meta( $id, $this->make_meta_key( $key ), true );

			// @todo enable custom getter/setter in models
			if ( 'sync' === $key && ! $value ) {
				$table[ $key ] = 'off';
			}

			// Fallback for legacy metadata
			// @todo move to migration
			if ( 'state_ids' === $key ) {
				$value = get_post_meta( $id, '_wpgp_commit_meta', true );

				if ( is_array( $value ) && isset( $value['state_ids'] ) ) {
					$model->set_attribute(
						$key,
						$value['state_ids']
					);

					delete_metadata( 'post', $id, '_wpgp_commit_meta' . true );
				}
			}
		}

		$model->set_attribute( Model::TABLE_KEY, $table );

		return $this->fill_relations( $model, $params );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $class
	 * @param array  $params
	 *
	 * @return Collection
	 */
	public function find_by( $class, array $params = array() ) {
		$post_type     = $class::get_post_type();
		$parent_search = 'post_parent__in';

		if ( Blob::class === $class ) {
			$parent_search = 'post_parent__not_in';
		}

		$query_args = array(
			'post_type'    => $post_type,
			$parent_search => array( 0 ),
			'fields'       => 'ids',
		);

		if ( Commit::class === $class ) {
			$query_args['post_parent'] = $params['repo_id'];
		}

		if ( \Intraxia\Gistpen\Model\Blob::class === $class && isset( $params['repo_id'] ) ) {
			$query_args['post_parent'] = $params['repo_id'];
		}

		if ( State::class === $class ) {
			$query_args['post_parent'] = $params['blob_id'];
		}

		if ( isset( $params['gist_id'] ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => $this->make_meta_key( 'gist_id' ),
					'value' => $params['gist_id'],
				),
			);
		}

		// Whitelist params send to WP_Query.
		foreach ( array( 'post_status', 'order', 'orderby', 'offset', 's', 'limit', 'language' ) as $param ) {
			if ( isset( $params[ $param ] ) ) {
				$value = $params[ $param ];

				switch ( $param ) {
					case 'language':
						// TODO abstract related by term query
						$query_args['tax_query'] = [
							[
								'taxonomy' => Language::get_taxonomy(),
								'field'    => 'slug',
								'terms'    => $value,
							],
						];
						break;
					case 'limit':
						$query_args['posts_per_page'] = $value;
						break;
					default:
						$query_args[ $param ] = $value;
						break;
				}
			}
		}

		$collection = new Collection( $class );
		$query      = new WP_Query( $query_args );

		foreach ( $query->get_posts() as $id ) {
			$model = $this->find( $class, $id, $params );

			if ( ! is_wp_error( $model ) ) {
				$collection = $collection->add( $model );
			}
		}

		// @todo this is dumb and bad
		$collection->query = $query;

		return $collection;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $class
	 * @param array  $data
	 * @param array  $options
	 */
	public function create( $class, array $data = array(), array $options = array() ) {
		$model = new $class();

		/**
		 * Set aside the `blobs` key for use.
		 */
		if ( isset( $data['blobs'] ) ) {
			if ( is_array( $data['blobs'] ) ) {
				$blobs_data = $data['blobs'];
			}

			unset( $data['blobs'] );
		}

		/**
		 * Set aside the `language` key for use.
		 */
		if ( isset( $data['language'] ) ) {
			if ( is_array( $data['language'] ) ) {
				$language_data = $data['language'];
			}

			unset( $data['language'] );
		}

		$unguarded = isset( $options['unguarded'] ) && $options['unguarded'];

		if ( $unguarded ) {
			$model->unguard();
		}

		foreach ( $data as $key => $value ) {
			$model->set_attribute( $key, $value );
		}

		if ( $unguarded ) {
			$model->reguard();
		}

		$result = wp_insert_post( (array) $model->get_underlying_wp_object(), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$model->set_attribute( Model::OBJECT_KEY, get_post( $result ) );

		foreach ( $model->get_table_attributes() as $key => $attribute ) {
			$result = update_metadata(
				'post',
				$model->get_primary_id(),
				$this->make_meta_key( $key ),
				$attribute
			);
		}

		if ( isset( $blobs_data ) ) {
			$blobs = new Collection( Blob::class );

			foreach ( $blobs_data as $blob_data ) {
				$blob_data['repo_id'] = $model->get_primary_id();
				$blob_data['status']  = $model->get_attribute( 'status' );

				$blob = $this->create( Blob::class, $blob_data, array(
					'unguarded' => true,
				) );

				if ( ! is_wp_error( $blob ) ) {
					$blobs = $blobs->add( $blob );
				}
			}

			$model->set_attribute( 'blobs', $blobs );
		}

		if ( isset( $language_data ) ) {
			$language = $this->em->find_by( Language::class, array( 'slug' => $language_data['slug'] ) );

			if ( count( $language ) === 0 ) {
				$language = $this->em->create( Language::class, $language_data );

				if ( is_wp_error( $language ) ) {
					return $language;
				}
			} else {
				$language = $language->first();
			}

			$model->set_attribute( 'language', $language );

			wp_set_object_terms( $model->get_primary_id(), $model->language->slug, Language::get_taxonomy(), false );
		}

		return $model;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Model $model
	 * @return Model
	 */
	public function persist( Model $model ) {
		$result = $model->get_primary_id() ?
			wp_update_post( $model->get_underlying_wp_object(), true ) :
			wp_insert_post( (array) $model->get_underlying_wp_object(), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$model->set_attribute( Model::OBJECT_KEY, get_post( $result ) );

		foreach ( $model->get_table_attributes() as $key => $value ) {
			if ( in_array( $key, array( 'blobs', 'language', 'repo' ), true ) ) {
				continue;
			}

			if ( $model->get_original_attribute( $key ) !== $value ) {
				update_metadata(
					'post',
					$model->get_primary_id(),
					"_{$this->prefix}_{$key}",
					$value
				);
			}
		}

		// Handle blobs
		if ( $model instanceof Repo && $model->blobs ) {
			$deleted_blobs = $model->get_original_attribute( 'blobs' )
				->filter( function ( Model $original_blob ) use ( &$model ) {
					foreach ( $model->blobs as $blob ) {
						if ( $blob->get_primary_id() === $original_blob->get_primary_id() ) {
							return false;
						}
					}

					return true;
				} );

			foreach ( $model->blobs as $blob ) {
				$blob->unguard();
				$blob->repo_id = $model->get_primary_id();
				$blob->status  = $model->get_attribute( 'status' );
				$blob->reguard();

				$this->persist( $blob );
			}

			foreach ( $deleted_blobs as $deleted_blob ) {
				wp_trash_post( $deleted_blob->get_primary_id() );
			}
		}

		if ( $model instanceof Blob || $model instanceof State ) {
			if ( $model instanceof Blob ) {
				$model->unguard();
				// @TODO(mAAdhaTTah) I mean... where does this really go?
				$model->status = get_post( $model->repo_id )->post_status;
				$model->reguard();
			}

			if ( $model->language ) {
				// @TODO(mAAdhaTTah) dedupe from create
				if ( is_string( $model->language ) ) {
					$language = $this->em->find_by( Language::class, [
						'slug' => $model->language,
					] );

					if ( count( $language ) === 0 ) {
						$language = $this->em->create( Language::class, [
							'slug' => $model->language,
						] );

						if ( is_wp_error( $language ) ) {
							return $language;
						}
					} else {
						$language = $language->first();
					}

					$model->language = $language;
				}

				wp_set_object_terms(
					$model->get_primary_id(),
					$model->language->slug,
					Language::get_taxonomy(),
					false
				);
			}
		}

		if ( $model instanceof Commit && $model->states ) {
			$states = new Collection( State::class );

			foreach ( $model->states as $state ) {
				$state = $this->persist( $state );

				if ( ! is_wp_error( $state ) ) {
					$states = $states->add( $state );
				}
			}

			$state_ids = $states->map(function ( State $state ) {
				return $state->ID;
			} )->to_array();

			update_metadata(
				'post',
				$model->get_primary_id(),
				"_{$this->prefix}_state_ids",
				$state_ids
			);
		}

		return $this->find( get_class( $model ), $model->get_primary_id() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Model $model
	 * @param bool  $force
	 * @return Model
	 */
	public function delete( Model $model, $force = false ) {
		$id = $model->get_primary_id();

		if ( ! $id ) {
			return new WP_Error( __( 'Repo does not exist in the database.', 'wp-gistpen' ) );
		}

		$result = wp_delete_post( $id, $force );

		if ( ! $result ) {
			return new WP_Error( __( 'Failed to delete Repo from the Database.', 'wp-gistpen' ) );
		}

		if ( $model instanceof Repo ) {
			foreach ( $model->blobs as $blob ) {
				$this->em->delete( $blob, $force );
			}
		}

		return $model;
	}
}
