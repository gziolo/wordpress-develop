<?php
/**
 * Unit tests covering WP_REST_Guideline_Scopes_Controller.
 *
 * @package WordPress
 * @subpackage REST_API
 *
 * @group guidelines
 * @group restapi
 *
 * @covers WP_REST_Guideline_Scopes_Controller
 */
class Tests_REST_WpRestGuidelineScopesController extends WP_Test_REST_TestCase {

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
	 * @ticket 65476
	 */
	public function test_route_is_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/wp/v2/knowledge/guideline-scopes', $routes );
	}

	/**
	 * Reads require the knowledge read capability, so logged-out users are denied.
	 *
	 * @ticket 65476
	 */
	public function test_read_requires_knowledge_read_capability() {
		wp_set_current_user( 0 );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/knowledge/guideline-scopes' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Administrators receive the default scopes keyed by slug with title,
	 * description, and order fields.
	 *
	 * @ticket 65476
	 */
	public function test_get_items_returns_default_scopes_for_admin() {
		wp_set_current_user( self::$admin_id );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/knowledge/guideline-scopes' ) );
		$this->assertSame( 200, $response->get_status() );

		$data  = $response->get_data();
		$slugs = wp_list_pluck( $data, 'slug' );
		$this->assertSame( array( 'site', 'copy', 'images', 'additional' ), $slugs );

		$this->assertArrayHasKey( 'title', $data[0] );
		$this->assertArrayHasKey( 'description', $data[0] );
		$this->assertArrayHasKey( 'order', $data[0] );
		$this->assertSame( 'Site', $data[0]['title'] );
		$this->assertIsInt( $data[0]['order'] );
	}

	/**
	 * The wp_guideline_scopes filter is reflected in the response, so plugins
	 * can register additional scopes.
	 *
	 * @ticket 65476
	 */
	public function test_filter_adds_scope() {
		wp_set_current_user( self::$admin_id );

		$callback = static function ( $scopes ) {
			$scopes['custom'] = array(
				'title'       => 'Custom',
				'description' => 'Custom scope.',
				'order'       => 99,
			);
			return $scopes;
		};
		add_filter( 'wp_guideline_scopes', $callback );

		$data  = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/knowledge/guideline-scopes' ) )->get_data();
		$slugs = wp_list_pluck( $data, 'slug' );

		remove_filter( 'wp_guideline_scopes', $callback );

		$this->assertContains( 'custom', $slugs );
	}
}
