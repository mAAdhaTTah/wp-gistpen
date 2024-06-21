<?php

namespace Intraxia\Jaxion\Test\Unit\Listener;

use Intraxia\Gistpen\Listener\Database;
use Intraxia\Gistpen\Model\Blob;
use Intraxia\Gistpen\Model\Commit;
use Intraxia\Gistpen\Model\Language;
use Intraxia\Gistpen\Model\Repo;
use Intraxia\Gistpen\Model\State;
use Intraxia\Gistpen\Test\Unit\TestCase;
use Intraxia\Jaxion\Contract\Axolotl\EntityManager;
use Mockery\Mock;

class DatabaseTest extends TestCase {
	/**
	 * @var Mock|EntityManager
	 */
	protected $em;

	/**
	 * @var Database
	 */
	protected $database;

	/**
	 * @var Repo
	 */
	protected $repo;

	/**
	 * {@inheritDoc}
	 */
	public function setUp() {
		parent::setUp();

		$this->em       = $this->app->make( EntityManager::class );
	}

	public function test_should_save_when_no_commits_saved() {
		$this->create_post_and_children( false );

		$commits = $this->em->find_by( Commit::class, array(
			'repo_id' => $this->repo->ID,
			'with'    => 'states',
		) );

		$this->assertCount( 0, $commits );

		/** @var Repo $repo */
		$repo = $this->em->find( Repo::class, $this->repo->ID, array(
			'with' => array(
				'blobs' => array(
					'with' => 'language,',
				),
			),
		) );

		$this->em->persist( $repo );

		$commits = $this->em->find_by( Commit::class, array(
			'repo_id' => $repo->ID,
			'with'    => 'states',
		) );

		$this->assertCount( 1, $commits );

		/** @var Commit $commit */
		$commit = $commits->first();

		$this->assertEquals( $repo->ID, $commit->repo_id );
		$this->assertEquals( $repo->description, $commit->description );
		$this->assertCount( 3, $commit->states );
		$this->assertEquals( $commit->states->map( function ( $model ) {
			return $model->ID;
		} )->to_array(), $commit->state_ids );

		/** @var State $state */
		foreach ( $commit->states as $state ) {
			/** @var Blob $blob */
			$blob = $this->em->find( Blob::class, $state->blob_id );

			$this->assertEquals( $blob->ID, $state->blob_id );
			$this->assertEquals( $blob->filename, $state->filename );
			$this->assertEquals( $blob->code, $state->code );
			$this->assertEquals( $blob->language, $state->language );
		}
	}

	public function test_should_not_save_new_commit_if_unchanged() {
		$this->create_post_and_children( true );

		/** @var Repo $repo */
		$repo             = $this->em->find( Repo::class, $this->repo->ID, array(
			'with' => array(
				'blobs' => array(
					'with' => 'language',
				),
			),
		) );
		$existing_commits = $this->em->find_by( Commit::class, array(
			'repo_id' => $repo->ID,
			'with'    => 'states',
		) );

		$this->em->persist( $repo );

		$commits = $this->em->find_by( Commit::class, array(
			'repo_id' => $repo->ID,
			'with'    => 'states',
		) );

		$this->assertEquals( $existing_commits, $commits );
	}

	public function test_should_save_commit_if_description_changes() {
		$this->create_post_and_children( true );

		/** @var Repo $repo */
		$repo = $this->em->find( Repo::class, $this->repo->ID, array(
			'with' => array(
				'blobs' => array(
					'with' => 'language',
				),
			),
		) );

		$repo->description = 'New Description';

		$this->em->persist( $repo );

		$commits = $this->em->find_by( Commit::class, array(
			'repo_id' => $repo->ID,
			'with'    => 'states',
			'orderby' => 'ID',
			'order'   => 'DESC',

		) );

		$this->assertCount( 2, $commits );

		/** @var Commit $commit */
		$commit = $commits->first();

		$this->assertEquals( $repo->description, $commit->description );

		foreach ( $repo->blobs as $blob ) {
			$states = $this->em->find_by( State::class, array(
				'blob_id' => $blob->ID,
			) );

			$this->assertCount( 1, $states );
		}
	}

	public function test_should_save_commit_if_blob_added() {
		$this->create_post_and_children( true );

		/** @var Repo $repo */
		$repo = $this->em->find( Repo::class, $this->repo->ID, array(
			'with' => array(
				'blobs' => array(
					'with' => 'language',
				),
			),
		) );

		$blob = new Blob();

		$blob->unguard();
		$blob->code     = 'some new php code';
		$blob->filename = 'new-slug.php';
		$blob->language = $this->em
			->find_by( Language::class, array( 'slug' => 'php' ) )
			->first();
		$blob->repo_id  = $repo->ID;
		$blob->reguard();

		$repo->blobs = $repo->blobs->add( $blob );

		$this->em->persist( $repo );

		$commits = $this->em->find_by( Commit::class, array(
			'repo_id' => $repo->ID,
			'with'    => 'states',
			'orderby' => 'ID',
			'order'   => 'DESC',
		) );

		$this->assertCount( 2, $commits );

		/** @var Commit $commit */
		$commit = $commits->first();

		$this->assertCount( 4, $commit->states );

		$states = $this->em->find_by( State::class, array(
			'blob_id' => $blob->ID,
		) );

		$this->assertCount( 1, $states );

		$state = $states->first();

		$this->assertEquals( $blob->ID, $state->blob_id );
		$this->assertEquals( $blob->filename, $state->filename );
		$this->assertEquals( $blob->code, $state->code );
		$this->assertEquals( $blob->language, $state->language );

		foreach ( $repo->blobs->remove_at( 3 ) as $blob ) {
			$states = $this->em->find_by( State::class, array(
				'blob_id' => $blob->ID,
			) );

			$this->assertCount( 1, $states );
		}
	}

	public function test_should_save_commit_if_blob_removed() {
		$this->create_post_and_children( true );

		/** @var Repo $repo */
		$repo = $this->em->find( Repo::class, $this->repo->ID, array(
			'with' => array(
				'blobs' => array(
					'with' => 'language',
				),
			),
		) );

		/** @var Blob $removed_blob */
		$removed_blob = $repo->blobs->at( 2 );

		$repo->blobs = $repo->blobs->remove_at( 2 );

		$this->em->persist( $repo );

		$commits = $this->em->find_by( Commit::class, array(
			'repo_id' => $repo->ID,
			'with'    => 'states',
			'orderby' => 'ID',
			'order'   => 'DESC',
		) );

		$this->assertCount( 2, $commits );

		/** @var Commit $commit */
		$commit = $commits->first();

		$this->assertCount( 2, $commit->states );

		$this->assertFalse( $commit->states->contains( function ( State $state ) use ( $removed_blob ) {
			return $state->blob_id === $removed_blob->ID;
		} ) );

		foreach ( $repo->blobs as $blob ) {
			$states = $this->em->find_by( State::class, array(
				'blob_id' => $blob->ID,
			) );

			$this->assertCount( 1, $states );
		}
	}

	public function test_should_save_if_blob_added_and_removed() {
		$this->create_post_and_children( true );

		/** @var Repo $repo */
		$repo = $this->em->find( Repo::class, $this->repo->ID, array(
			'with' => array(
				'blobs' => array(
					'with' => 'language',
				),
			),
		) );

		$blob = new Blob();

		$blob->unguard();
		$blob->code     = 'some new php code';
		$blob->filename = 'new-slug.php';
		$blob->language = $this->em
			->find_by( Language::class, array( 'slug' => 'php' ) )
			->first();
		$blob->repo_id  = $repo->ID;
		$blob->reguard();

		/** @var Blob $removed_blob */
		$removed_blob = $repo->blobs->at( 2 );

		$repo->blobs = $repo->blobs->remove_at( 2 )->add( $blob );

		$this->em->persist( $repo );

		$commits = $this->em->find_by( Commit::class, array(
			'repo_id' => $repo->ID,
			'with'    => 'states',
			'orderby' => 'date',
			'order'   => 'DESC',
		) );

		$this->assertCount( 2, $commits );

		/** @var Commit $commit */
		$commit = $commits->first();

		$this->assertCount( 3, $commit->states );
		$this->assertFalse( $commit->states->contains( function ( State $state ) use ( $removed_blob ) {
			return $state->blob_id === $removed_blob->ID;
		} ) );

		$states = $this->em->find_by( State::class, array(
			'blob_id' => $blob->ID,
		) );

		$this->assertCount( 1, $states );

		$state = $states->first();

		$this->assertEquals( $blob->ID, $state->blob_id );
		$this->assertEquals( $blob->filename, $state->filename );
		$this->assertEquals( $blob->code, $state->code );
		$this->assertEquals( $blob->language, $state->language );

		foreach ( $repo->blobs->remove_at( 2 ) as $blob ) {
			$states = $this->em->find_by( State::class, array(
				'blob_id' => $blob->ID,
			) );

			$this->assertCount( 1, $states );
		}
	}

	public function test_should_save_commit_if_blob_code_changes() {
		$this->create_post_and_children( true );

		/** @var Repo $repo */
		$repo = $this->em->find( Repo::class, $this->repo->ID, array(
			'with' => array(
				'blobs' => array(
					'with' => 'language',
				),
			),
		) );

		/** @var Blob $blob */
		$blob = $repo->blobs->at( 0 );

		$blob->code = 'some new php code';

		$this->em->persist( $repo );

		$commits = $this->em->find_by( Commit::class, array(
			'repo_id' => $repo->ID,
			'with'    => 'states',
			'orderby' => 'date',
			'order'   => 'DESC',
		) );

		$this->assertCount( 2, $commits );

		/** @var Commit $commit */
		$commit = $commits->first();

		/** @var State $state */
		foreach ( $commit->states as $state ) {
			if ( $state->blob_id === $blob->ID ) {
				$states = $this->em->find_by( State::class, array(
					'blob_id' => $blob->ID,
					// ID because the 2 commits are saving at the same time.
					'orderby' => 'ID',
					'order'   => 'DESC',
				) );

				$this->assertCount( 2, $states );
				$state = $states->first();

				$this->assertEquals( $blob->code, $state->code );
			} else {
				$states = $this->em->find_by( State::class, array(
					'blob_id' => $state->blob_id,
				) );

				$this->assertCount( 1, $states );
			}
		}
	}

	public function test_should_save_commit_if_blob_filename_changes() {
		$this->create_post_and_children( true );

		/** @var Repo $repo */
		$repo = $this->em->find( Repo::class, $this->repo->ID, array(
			'with' => array(
				'blobs' => array(
					'with' => 'language',
				),
			),
		) );

		/** @var Blob $blob */
		$blob = $repo->blobs->at( 0 );

		$blob->filename = 'new-slug.php';

		$this->em->persist( $repo );

		$commits = $this->em->find_by( Commit::class, array(
			'repo_id' => $repo->ID,
			'with'    => 'states',
			'orderby' => 'date',
			'order'   => 'DESC',
		) );

		$this->assertCount( 2, $commits );

		/** @var Commit $commit */
		$commit = $commits->first();

		/** @var State $state */
		foreach ( $commit->states as $state ) {
			if ( $state->blob_id === $blob->ID ) {
				$states = $this->em->find_by( State::class, array(
					'blob_id' => $blob->ID,
					// ID because the 2 commits are saving at the same time.
					'orderby' => 'ID',
					'order'   => 'DESC',
				) );

				$this->assertCount( 2, $states );
				$state = $states->first();

				$this->assertEquals( $blob->filename, $state->filename );
			} else {
				$states = $this->em->find_by( State::class, array(
					'blob_id' => $state->blob_id,
				) );

				$this->assertCount( 1, $states );
			}
		}
	}

	public function test_should_save_commit_if_blob_language_changes() {
		$this->create_post_and_children( true );

		/** @var Repo $repo */
		$repo = $this->em->find( Repo::class, $this->repo->ID, array(
			'with' => array(
				'blobs' => array(
					'with' => 'language',
				),
			),
		) );

		/** @var Blob $blob */
		$blob = $repo->blobs->at( 0 );

		wp_set_object_terms( $blob->ID, 'js', 'wpgp_language', false );

		$blob->language = $this->em
			->find_by( Language::class, array( 'slug' => 'js' ) )
			->first();

		$this->em->persist( $repo );

		$commits = $this->em->find_by( Commit::class, array(
			'repo_id' => $repo->ID,
			'with'    => 'states',
			'orderby' => 'date',
			'order'   => 'DESC',
		) );

		$this->assertCount( 2, $commits );

		/** @var Commit $commit */
		$commit = $commits->first();

		/** @var State $state */
		foreach ( $commit->states as $state ) {
			if ( $state->blob_id === $blob->ID ) {
				$states = $this->em->find_by( State::class, array(
					'blob_id' => $blob->ID,
					'with'    => 'language',
					// ID because the 2 commits are saving at the same time.
					'orderby' => 'ID',
					'order'   => 'DESC',
				) );

				$this->assertCount( 2, $states );
				$state = $states->first();

				$this->assertEquals( $blob->language->ID, $state->language->ID );
			} else {
				$states = $this->em->find_by( State::class, array(
					'blob_id' => $state->blob_id,
				) );

				$this->assertCount( 1, $states );
			}
		}
	}
}
