<?php
/**
 * Tests for the guideline scopes registry and slug helpers.
 *
 * @package WordPress
 * @subpackage Guidelines
 *
 * @group guidelines
 */
class Tests_Guidelines_Scopes extends WP_UnitTestCase {

	/**
	 * @ticket 65476
	 * @covers ::wp_guideline_scopes
	 */
	public function test_default_scopes_are_registered() {
		$scopes = wp_guideline_scopes();

		$this->assertSame( array( 'site', 'copy', 'images', 'blocks', 'additional' ), array_keys( $scopes ) );

		foreach ( $scopes as $scope ) {
			$this->assertArrayHasKey( 'title', $scope );
			$this->assertArrayHasKey( 'description', $scope );
			$this->assertArrayHasKey( 'order', $scope );
			$this->assertIsInt( $scope['order'] );
		}

		$this->assertSame( 'Site', $scopes['site']['title'] );
	}

	/**
	 * @ticket 65476
	 * @covers ::wp_guideline_scopes
	 */
	public function test_scopes_are_filterable() {
		add_filter(
			'wp_guideline_scopes',
			static function ( $scopes ) {
				$scopes['custom'] = array(
					'title'       => 'Custom',
					'description' => 'Custom scope.',
					'order'       => 99,
				);
				return $scopes;
			}
		);

		$scopes = wp_guideline_scopes();

		$this->assertArrayHasKey( 'custom', $scopes );
		$this->assertSame( 'Custom', $scopes['custom']['title'] );
	}

	/**
	 * @ticket 65476
	 * @covers ::wp_guideline_max_length
	 */
	public function test_max_length_defaults_to_5000() {
		$this->assertSame( 5000, wp_guideline_max_length() );
	}

	/**
	 * @ticket 65476
	 * @covers ::wp_guideline_max_length
	 */
	public function test_max_length_is_filterable() {
		add_filter(
			'wp_guideline_max_length',
			static function () {
				return 10;
			}
		);

		$max = wp_guideline_max_length();

		$this->assertSame( 10, $max );
	}

	public function data_scope_from_slug(): array {
		return array(
			'registry scope'   => array( 'guideline-site', 'site' ),
			'another scope'    => array( 'guideline-images', 'images' ),
			'blocks scope'     => array( 'guideline-blocks', 'blocks' ),
			'block row'        => array( 'guideline-block-core_paragraph', 'blocks' ),
			'empty block name' => array( 'guideline-block-', null ),
			'unknown scope'    => array( 'guideline-nope', null ),
			'bare prefix'      => array( 'guideline-', null ),
			'not a guideline'  => array( 'note-site', null ),
		);
	}

	/**
	 * @ticket 65476
	 * @covers ::wp_guideline_scope_from_slug
	 *
	 * @dataProvider data_scope_from_slug
	 *
	 * @param string      $slug     Post slug.
	 * @param string|null $expected Expected scope key.
	 */
	public function test_scope_from_slug( $slug, $expected ) {
		$this->assertSame( $expected, wp_guideline_scope_from_slug( $slug ) );
	}

	/**
	 * Per-block rows resolve to the blocks scope only while it is registered.
	 *
	 * @ticket 65476
	 * @covers ::wp_guideline_scope_from_slug
	 */
	public function test_block_row_scope_requires_blocks_scope() {
		$this->assertSame( 'blocks', wp_guideline_scope_from_slug( 'guideline-block-core_paragraph' ) );

		add_filter(
			'wp_guideline_scopes',
			static function ( $scopes ) {
				unset( $scopes['blocks'] );
				return $scopes;
			}
		);

		$this->assertNull( wp_guideline_scope_from_slug( 'guideline-block-core_paragraph' ) );
	}

	/**
	 * A registered scope keyed under `block-` wins over the per-block namespace.
	 *
	 * Real per-block slugs always encode the block namespace separator as `_`
	 * (`core/paragraph` becomes `guideline-block-core_paragraph`), so a scope key
	 * made only of hyphens like `block-editor-media-instructions` never matches a
	 * real block row. That is why the collision is very unlikely in practice.
	 *
	 * @ticket 65476
	 * @covers ::wp_guideline_scope_from_slug
	 */
	public function test_registered_scope_wins_over_block_namespace() {
		$slug = 'guideline-block-editor-media-instructions';

		// Before the scope exists the slug looks like a per-block row, so it is
		// swallowed by the blocks scope.
		$this->assertSame( 'blocks', wp_guideline_scope_from_slug( $slug ) );

		add_filter(
			'wp_guideline_scopes',
			static function ( $scopes ) {
				$scopes['block-editor-media-instructions'] = array(
					'title'       => 'Media instructions',
					'description' => '',
					'order'       => 60,
				);
				return $scopes;
			}
		);

		// Once registered, the exact scope key wins and resolves to itself.
		$this->assertSame( 'block-editor-media-instructions', wp_guideline_scope_from_slug( $slug ) );

		// A real per-block row keeps the namespace separator as `_`, so it never
		// collides with such a scope key and still resolves to the blocks scope.
		$this->assertSame( 'blocks', wp_guideline_scope_from_slug( 'guideline-block-core_paragraph' ) );
	}
}
