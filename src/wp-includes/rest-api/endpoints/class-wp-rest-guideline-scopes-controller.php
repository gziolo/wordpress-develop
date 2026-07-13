<?php
/**
 * REST API: WP_REST_Guideline_Scopes_Controller class
 *
 * @package WordPress
 * @subpackage REST_API
 * @since 7.2.0
 */

/**
 * Core controller used to read the guideline scopes registry via the REST API.
 *
 * This is a read-only registry endpoint beside the knowledge data routes, the
 * same species as `/wp/v2/statuses`. It has no write paths and carries no data
 * semantics. A Guidelines screen preloads it, then reads and writes the scope
 * rows through the standard `/wp/v2/knowledge` collection.
 *
 * @since 7.2.0
 *
 * @see WP_REST_Controller
 */
class WP_REST_Guideline_Scopes_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @since 7.2.0
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
		$this->rest_base = 'knowledge/guideline-scopes';
	}

	/**
	 * Registers the routes for guideline scopes.
	 *
	 * @since 7.2.0
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks whether the current user can read guideline scopes.
	 *
	 * Gated on the knowledge read capability, matching the data routes.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( 'read_knowledge_items' ) ) {
			return new WP_Error(
				'rest_cannot_read',
				__( 'Sorry, you are not allowed to view guideline scopes.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves all registered guideline scopes.
	 *
	 * Labels are resolved at request time, in the request locale.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function get_items( $request ) {
		$data = array();

		foreach ( wp_guideline_scopes() as $slug => $scope ) {
			$item   = $this->prepare_item_for_response( array_merge( array( 'slug' => $slug ), $scope ), $request );
			$data[] = $this->prepare_response_for_collection( $item );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Prepares a single guideline scope for response.
	 *
	 * @since 7.2.0
	 *
	 * @param array           $item    Scope data with a `slug` key.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $item, $request ) {
		$fields = $this->get_fields_for_response( $request );
		$data   = array();

		if ( rest_is_field_included( 'slug', $fields ) ) {
			$data['slug'] = $item['slug'];
		}
		if ( rest_is_field_included( 'title', $fields ) ) {
			$data['title'] = isset( $item['title'] ) ? $item['title'] : '';
		}
		if ( rest_is_field_included( 'description', $fields ) ) {
			$data['description'] = isset( $item['description'] ) ? $item['description'] : '';
		}
		if ( rest_is_field_included( 'order', $fields ) ) {
			$data['order'] = isset( $item['order'] ) ? (int) $item['order'] : 0;
		}

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );

		return rest_ensure_response( $data );
	}

	/**
	 * Retrieves the guideline scope schema, conforming to JSON Schema.
	 *
	 * @since 7.2.0
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'guideline-scope',
			'type'       => 'object',
			'properties' => array(
				'slug'        => array(
					'description' => __( 'An alphanumeric identifier for the scope.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'title'       => array(
					'description' => __( 'The title for the scope.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'description' => array(
					'description' => __( 'A human-readable description of the scope.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'order'       => array(
					'description' => __( 'The sort order of the scope on a Guidelines screen.' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
