<?php
namespace Intraxia\Gistpen\Listener;

use Intraxia\Gistpen\Database\EntityManager as EM;
use Intraxia\Gistpen\Model\Blob;
use Intraxia\Gistpen\Model\Commit;
use Intraxia\Gistpen\Model\Repo;
use Intraxia\Gistpen\Model\State;
use Intraxia\Jaxion\Axolotl\Collection;
use Intraxia\Jaxion\Contract\Axolotl\EntityManager;
use Intraxia\Jaxion\Contract\Core\HasActions;

/**
 * Database listener service.
 */
class Database implements HasActions {

	/**
	 * Database service.
	 *
	 * @var EntityManager
	 */
	private $em;

	/**
	 * Database constructor.
	 *
	 * @param EntityManager $em
	 */
	public function __construct( EntityManager $em ) {
		$this->em = $em;
	}

	/**
	 * Checks if the Repo has changed and creates a new commit if it has.
	 *
	 * @param Repo $repo
	 */
	public function add_repo_commit( Repo $repo ) {
		$commits = $this->em->find_by( Commit::class, array(
			'repo_id' => $repo->ID,
			'with'    => array(
				'states' => array(
					'with' => 'language',
				),
			),
			'orderby' => 'date',
			'order'   => 'DESC',
		) );

		if ( $commits->count() > 0 && $this->matches_last_commit( $repo, $commits->first() ) ) {
			return;
		}

		$new_commit = new Commit( array(
			'repo_id'     => $repo->ID,
			'description' => $repo->description,
			'author'      => get_current_user_id(),
		) );

		// This is the first commit.
		if ( $commits->count() === 0 ) {
			$states = new Collection( State::class );

			foreach ( $repo->blobs as $blob ) {
				$states = $states->add( $this->blob_to_state( $blob ) );
			}

			$new_commit->set_attribute( 'states', $states );

			$this->em->persist( $new_commit );

			return;
		}

		$prev_commit = $commits->first();

		// Get all the blobs that don't have a matching state in the previous commit.
		// These blobs are new.
		$added_states = $repo->blobs->filter( function ( Blob $blob ) use ( $prev_commit ) {
			return ! $prev_commit->states->contains( function ( State $state ) use ( $blob ) {
				return $state->blob_id === $blob->ID;
			} );
		} )
			// Save the blob's state.
			->map( function ( Blob $blob ) {
				return $this->em->persist( $this->blob_to_state( $blob ) );
			} );

		// Remove all the states that don't match an existing blob.
		$states = $prev_commit->states->filter( function ( State $state ) use ( $repo ) {
			return $repo->blobs->contains( function ( Blob $blob ) use ( $state ) {
				return $state->blob_id === $blob->ID;
			} );
		} )
			->map( function ( State $state ) use ( $repo ) {
				$blob = $repo->blobs->find( function ( Blob $blob ) use ( $state ) {
					return $state->blob_id === $blob->ID;
				} );

				switch ( true ) {
					// Create a new state for blobs that have changed.
					case $blob->filename !== $state->filename:
					case $blob->code !== $state->code:
					case $blob->language->slug !== $state->language->slug:
						return $this->em->persist( $this->blob_to_state( $blob ) );
					// Otherwise, keep it.
					default:
						return $state;
				}
			} )->merge( $added_states );

		$new_commit->state_ids = $states->map( function ( State $state ) {
			return $state->ID;
		} )->to_array();

		$this->em->persist( $new_commit );
	}

	/**
	 * Checks if the Repo has changed and creates a new commit if it has.
	 *
	 * @param Blob $blob
	 */
	public function add_blob_commit( Blob $blob ) {
		$this->add_repo_commit( $this->em->find( Repo::class, $blob->repo_id, [
			'with' => [
				'blobs' => [
					'with' => 'language',
				],
			],
		] ) );
	}

	/**
	 * Remove the action hook to save a post revision
	 *
	 * We're going to be handling this ourselves
	 *
	 * @param  int $post_id
	 *
	 * @since  0.5.0
	 */
	public function remove_revision_save( $post_id ) {
		if ( 'gistpen' === get_post_type( $post_id ) ) {
			remove_action( 'post_updated', 'wp_save_post_revision', 10 );
		}
	}

	/**
	 * Deletes the related Blobs when a Repo gets deleted.
	 *
	 * Is this something that should be absorbed by Jaxion\Axolotl?
	 *
	 * @param  int $post_id post ID of the zip being deleted.
	 *
	 * @since  0.5.0
	 */
	public function delete_blobs( $post_id ) {
		$post = get_post( $post_id );

		if ( 'gistpen' === $post->post_type && 0 === $post->post_parent ) {
			$blobs = $this->em->find_by( \Intraxia\Gistpen\Model\Blob::class, array(
				'post_parent' => $post_id,
				'post_status' => 'any',
				'order'       => 'ASC',
				'orderby'     => 'date',
			) );

			/* Blob $blob */
			foreach ( $blobs as $blob ) {
				wp_delete_post( $blob->ID, true );
			}
		}
	}

	/**
	 * Allows empty Repo to save.
	 *
	 * @param  bool  $maybe_empty Whether post should be considered empty.
	 * @param  array $postarr Array of post data.
	 *
	 * @return bool                Result of empty check
	 * @since  0.5.0
	 */
	public function allow_empty_zip( $maybe_empty, $postarr ) {
		if ( 'gistpen' === $postarr['post_type'] && 0 === $postarr['post_parent'] ) {
			$maybe_empty = false;
		}

		return $maybe_empty;
	}

	/**
	 * Disables checking for changes when we save a post revision
	 *
	 * @param  bool     $check_for_changes whether we check for changes.
	 * @param  \WP_Post $last_revision previous revision object.
	 * @param  \WP_Post $post current revision.
	 *
	 * @return bool                        Whether we check for changes.
	 * @since  0.5.0
	 */
	public function disable_check_for_change( $check_for_changes, $last_revision, $post ) {
		if ( 'gistpen' === $post->post_type && 0 === $post->post_parent ) {
			$check_for_changes = false;
		}

		return $check_for_changes;
	}

	/**
	 * Provides the array of actions the class wants to register with WordPress.
	 *
	 * These actions are retrieved by the Loader class and used to register the
	 * correct service methods with WordPress.
	 *
	 * @return array[]
	 */
	public function action_hooks() {
		return array(
			array(
				'hook'   => 'wpgp.create.repo',
				'method' => 'add_repo_commit',
			),
			array(
				'hook'   => 'wpgp.persist.repo',
				'method' => 'add_repo_commit',
			),
			array(
				'hook'   => 'wpgp.create.blob',
				'method' => 'add_blob_commit',
			),
			array(
				'hook'   => 'wpgp.persist.blob',
				'method' => 'add_blob_commit',
			),
			array(
				'hook'     => 'post_updated',
				'method'   => 'remove_revision_save',
				'priority' => 9,
			),
			array(
				'hook'   => 'before_delete_post',
				'method' => 'delete_blobs',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array[]
	 */
	public function filter_hooks() {
		return array(
			array(
				'hook'   => 'wp_insert_post_empty_content',
				'method' => 'allow_empty_zip',
				'args'   => 2,
			),
			array(
				'hook'   => 'wp_save_post_revision_check_for_changes',
				'method' => 'disable_check_for_change',
				'args'   => 3,
			),
		);
	}

	/**
	 * Matches up the commit and repo and determines whether it has changed.
	 *
	 * @param Repo   $repo
	 * @param Commit $commit
	 *
	 * @return bool
	 */
	private function matches_last_commit( Repo $repo, Commit $commit ) {
		if ( $repo->description !== $commit->description ) {
			return false;
		}

		if ( $repo->blobs->count() !== $commit->states->count() ) {
			return false;
		}

		$has_changed_blobs = $repo->blobs->filter( function ( Blob $blob ) use ( $commit ) {
				return ! $commit->states->contains( function ( State $state ) use ( $blob ) {
					if ( $state->blob_id === $blob->ID &&
						$blob->filename === $state->filename &&
						$blob->code === $state->code &&
						$blob->language->slug === $state->language->slug
					) {
						return true;
					}

					return false;
				} );
		} )->count() > 0;

		if ( $has_changed_blobs ) {
			return false;
		}

		return true;
	}

	/**
	 * Map the Blob to a matching state.
	 *
	 * @param Blob $blob
	 *
	 * @return State
	 */
	private function blob_to_state( Blob $blob ) {
		return new State( array(
			'blob_id'  => $blob->ID,
			'filename' => $blob->filename,
			'code'     => $blob->code,
			'language' => $blob->language,
		) );
	}
}
