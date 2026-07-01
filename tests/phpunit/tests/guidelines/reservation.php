<?php
/**
 * Tests for guideline-row shaping on write.
 *
 * Covers the single REST insert callback that, for a recognized scope slug, forces
 * the guideline type, sets the title, and caps the content. Also covers slug
 * uniqueness handling.
 *
 * @package WordPress
 * @subpackage Guidelines
 *
 * @group guidelines
 * @group restapi
 */
class Tests_Guidelines_Reservation extends WP_Test_REST_TestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected static $admin_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Creates a knowledge row via REST.
	 *
	 * @param array $body Request body params.
	 * @return WP_REST_Response Response object.
	 */
	private function create_row( array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wp/v2/knowledge' );
		$request->set_body_params( $body );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A REST write with a recognized scope slug gets the guideline type and, with
	 * no collision, keeps its exact slug.
	 *
	 * @ticket 65476
	 * @covers ::wp_guideline_prepare_rest_row
	 */
	public function test_sets_guideline_type_for_scope_slug() {
		wp_set_current_user( self::$admin_id );

		$response = $this->create_row(
			array(
				'slug'    => 'guideline-copy',
				'content' => 'Use active voice.',
				'status'  => 'publish',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$id = $response->get_data()['id'];

		$this->assertSame( 'guideline-copy', get_post( $id )->post_name );

		$terms = wp_get_object_terms( $id, 'wp_knowledge_type', array( 'fields' => 'slugs' ) );
		$this->assertSame( array( 'guideline' ), $terms );
	}

	/**
	 * A REST write whose slug is not a registered scope is left untouched.
	 *
	 * @ticket 65476
	 * @covers ::wp_guideline_prepare_rest_row
	 */
	public function test_does_not_set_type_for_unrecognized_slug() {
		wp_set_current_user( self::$admin_id );

		$response = $this->create_row(
			array(
				'slug'    => 'guideline-nope',
				'content' => 'Text.',
				'status'  => 'publish',
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$terms = wp_get_object_terms( $response->get_data()['id'], 'wp_knowledge_type', array( 'fields' => 'slugs' ) );
		$this->assertNotContains( 'guideline', $terms );
	}

	/**
	 * A REST write with a per-block slug gets the guideline type when the blocks
	 * scope is registered.
	 *
	 * @ticket 65476
	 * @covers ::wp_guideline_prepare_rest_row
	 */
	public function test_sets_guideline_type_for_block_slug() {
		wp_set_current_user( self::$admin_id );

		$response = $this->create_row(
			array(
				'slug'    => 'guideline-block-core-paragraph',
				'title'   => 'core/paragraph',
				'content' => 'Keep paragraphs short.',
				'status'  => 'publish',
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$terms = wp_get_object_terms( $response->get_data()['id'], 'wp_knowledge_type', array( 'fields' => 'slugs' ) );
		$this->assertSame( array( 'guideline' ), $terms );
	}

	/**
	 * A recognized slug whose selected type does not include the guideline term is
	 * not a guideline row. It is left untouched: the type is kept and the scope
	 * title is not stamped.
	 *
	 * @ticket 65476
	 * @covers ::wp_guideline_prepare_rest_row
	 */
	public function test_leaves_row_when_selected_type_excludes_guideline() {
		wp_set_current_user( self::$admin_id );

		$note = term_exists( 'note', 'wp_knowledge_type' );
		if ( ! $note ) {
			$note = wp_insert_term( 'note', 'wp_knowledge_type' );
		}
		$note_id = (int) $note['term_id'];

		$response = $this->create_row(
			array(
				'slug'              => 'guideline-site',
				'title'             => 'My title',
				'content'           => 'Text.',
				'status'            => 'publish',
				'wp_knowledge_type' => array( $note_id ),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$post = get_post( $response->get_data()['id'] );

		$terms = wp_get_object_terms( $post->ID, 'wp_knowledge_type', array( 'fields' => 'slugs' ) );
		$this->assertSame( array( 'note' ), $terms );
		$this->assertSame( 'My title', $post->post_title );
	}

	/**
	 * A recognized slug whose selected type includes the guideline term is a
	 * guideline row, so it is shaped: the scope title is stamped.
	 *
	 * @ticket 65476
	 * @covers ::wp_guideline_prepare_rest_row
	 */
	public function test_shapes_row_when_selected_type_includes_guideline() {
		wp_set_current_user( self::$admin_id );

		// A first guideline write creates the guideline term.
		$this->create_row(
			array(
				'slug'    => 'guideline-copy',
				'content' => 'Seed.',
				'status'  => 'publish',
			)
		);
		$guideline_id = (int) term_exists( 'guideline', 'wp_knowledge_type' )['term_id'];

		$response = $this->create_row(
			array(
				'slug'              => 'guideline-images',
				'title'             => 'Bogus client title',
				'content'           => 'Square images only.',
				'status'            => 'publish',
				'wp_knowledge_type' => array( $guideline_id ),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Images', get_post( $response->get_data()['id'] )->post_title );
	}

	/**
	 * A second create with an already-used `guideline-` slug is not rejected.
	 * WordPress suffixes the slug, and the published row keeps the exact slug.
	 *
	 * @ticket 65476
	 * @covers ::wp_guideline_prepare_rest_row
	 */
	public function test_duplicate_slug_is_suffixed_not_rejected() {
		wp_set_current_user( self::$admin_id );

		$first = $this->create_row(
			array(
				'slug'    => 'guideline-site',
				'content' => 'First.',
				'status'  => 'publish',
			)
		);
		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 'guideline-site', get_post( $first->get_data()['id'] )->post_name );

		$second = $this->create_row(
			array(
				'slug'    => 'guideline-site',
				'content' => 'Second.',
				'status'  => 'publish',
			)
		);
		$this->assertSame( 201, $second->get_status() );
		$this->assertSame( 'guideline-site-2', get_post( $second->get_data()['id'] )->post_name );
	}

	/**
	 * A content-only update of an existing row succeeds and stores the new content.
	 *
	 * @ticket 65476
	 * @covers ::wp_guideline_prepare_rest_row
	 */
	public function test_content_only_update_succeeds() {
		wp_set_current_user( self::$admin_id );

		$created = $this->create_row(
			array(
				'slug'    => 'guideline-copy',
				'content' => 'First.',
				'status'  => 'publish',
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$id = $created->get_data()['id'];

		$request = new WP_REST_Request( 'POST', '/wp/v2/knowledge/' . $id );
		$request->set_body_params( array( 'content' => 'Second.' ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Second.', get_post( $id )->post_content );
	}

	/**
	 * Single-row scope titles are set from wp_guideline_scopes(), ignoring any
	 * client-provided title.
	 *
	 * @ticket 65476
	 * @covers ::wp_guideline_prepare_rest_row
	 */
	public function test_sets_scope_title_from_registry() {
		wp_set_current_user( self::$admin_id );

		$response = $this->create_row(
			array(
				'slug'    => 'guideline-images',
				'title'   => 'Bogus client title',
				'content' => 'Square images only.',
				'status'  => 'publish',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Images', get_post( $response->get_data()['id'] )->post_title );
	}

	/**
	 * Per-block rows keep the client-provided canonical block name as the title,
	 * because the blocks scope is multi-row.
	 *
	 * @ticket 65476
	 * @covers ::wp_guideline_prepare_rest_row
	 */
	public function test_block_row_keeps_canonical_title() {
		wp_set_current_user( self::$admin_id );

		$response = $this->create_row(
			array(
				'slug'    => 'guideline-block-core-paragraph',
				'title'   => 'core/paragraph',
				'content' => 'Keep paragraphs short.',
				'status'  => 'publish',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'core/paragraph', get_post( $response->get_data()['id'] )->post_title );
	}

	/**
	 * Content is reduced to plain text and capped at the guideline length.
	 *
	 * @ticket 65476
	 * @covers ::wp_guideline_prepare_rest_row
	 */
	public function test_content_sanitized_and_capped() {
		wp_set_current_user( self::$admin_id );

		$long = str_repeat( 'a', 6000 );

		$response = $this->create_row(
			array(
				'slug'    => 'guideline-additional',
				'content' => '<script>alert(1)</script>' . $long,
				'status'  => 'publish',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$content = get_post( $response->get_data()['id'] )->post_content;

		$this->assertStringNotContainsString( '<script', $content );
		$this->assertLessThanOrEqual( 5000, mb_strlen( $content, 'UTF-8' ) );
	}

	/**
	 * When the guideline term cannot be created, the write is rejected with the
	 * error instead of saving an untyped row.
	 *
	 * @ticket 65476
	 * @covers ::wp_guideline_prepare_rest_row
	 */
	public function test_rejects_write_when_guideline_term_cannot_be_created() {
		wp_set_current_user( self::$admin_id );

		// Force term creation to fail so the guideline term cannot be created.
		add_filter(
			'pre_insert_term',
			static function () {
				return new WP_Error( 'test_term_blocked', 'No terms today.' );
			}
		);

		$response = $this->create_row(
			array(
				'slug'    => 'guideline-copy',
				'content' => 'Use active voice.',
				'status'  => 'publish',
			)
		);

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'rest_cannot_create_guideline_type', $response->get_data()['code'] );
	}
}
