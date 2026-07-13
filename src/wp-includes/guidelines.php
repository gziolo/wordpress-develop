<?php
/**
 * Guidelines API: the guideline consumer layer over the wp_knowledge primitive.
 *
 * @package WordPress
 * @subpackage Guidelines
 * @since 7.2.0
 */

/**
 * Retrieves the registered guideline scopes, keyed by slug.
 *
 * Each scope is a section of guidance backed by a `guideline`-typed `wp_knowledge`
 * row with a `guideline-{scope}` slug. Filter {@see 'wp_guideline_scopes'} to add
 * or remove scopes for a site.
 *
 * The `blocks` scope is the exception: it has no single row. Its guidance lives in
 * per-block `guideline-block-*` rows so each block type can carry its own.
 *
 * @since 7.2.0
 *
 * @return array {
 *     Slug-keyed map of guideline scopes.
 *
 *     @type array ...$0 {
 *         Data for a single scope.
 *
 *         @type string $title       Human-readable scope title.
 *         @type string $description Human-readable scope description.
 *         @type int    $order       Sort order for the scope.
 *     }
 * }
 * @phpstan-return array<non-empty-string, array{title: string, description: string, order: int}>
 */
function wp_guideline_scopes(): array {
	/**
	 * Filters the guideline scopes available on this site.
	 *
	 * @since 7.2.0
	 *
	 * @param array $scopes Slug-keyed map of guideline scopes. See wp_guideline_scopes().
	 * @phpstan-param array<non-empty-string, array{title: string, description: string, order: int}> $scopes
	 */
	return apply_filters(
		'wp_guideline_scopes',
		array(
			'site'       => array(
				'title'       => __( 'Site' ),
				'description' => __( "Describe your site's purpose, goals, and primary audience." ),
				'order'       => 10,
			),
			'copy'       => array(
				'title'       => __( 'Copy' ),
				'description' => __( 'Set your writing standards for tone, voice, style, and formatting.' ),
				'order'       => 20,
			),
			'images'     => array(
				'title'       => __( 'Images' ),
				'description' => __( 'Outline your style, dimensions, formats, mood and aesthetic preferences.' ),
				'order'       => 30,
			),
			'blocks'     => array(
				'title'       => __( 'Blocks' ),
				'description' => __( 'Create tailored guidelines for specific block types.' ),
				'order'       => 40,
			),
			'additional' => array(
				'title'       => __( 'Additional' ),
				'description' => __( 'Add additional guidelines.' ),
				'order'       => 50,
			),
		)
	);
}

/**
 * Returns the maximum length, in characters, of a guideline row's content.
 *
 * @since 7.2.0
 *
 * @return int Maximum number of characters allowed in guideline content.
 */
function wp_guideline_max_length(): int {
	/**
	 * Filters the maximum length, in characters, of a guideline row's content.
	 *
	 * @since 7.2.0
	 *
	 * @param int $max_length Maximum number of characters. Default 5000.
	 */
	return (int) apply_filters( 'wp_guideline_max_length', 5000 );
}

/**
 * Maps a guideline row slug to the scope that owns it.
 *
 * Use this to tell whether a `wp_knowledge` row is a guideline and, if so, which
 * scope it belongs to. Per-block rows (`guideline-block-*`) belong to the `blocks`
 * scope. A null return means the slug is not a registered scope, so callers can
 * treat it as a recognized-guideline check.
 *
 * @since 7.2.0
 * @access private
 *
 * @param string $slug Post slug.
 * @return string|null Scope key, or null if the slug is not a registered scope.
 */
function wp_guideline_scope_from_slug( string $slug ): ?string {
	if ( ! str_starts_with( $slug, 'guideline-' ) ) {
		return null;
	}

	$scopes = wp_guideline_scopes();

	// Per-block rows belong to the blocks scope when it is registered.
	if ( str_starts_with( $slug, 'guideline-block-' ) && strlen( $slug ) > strlen( 'guideline-block-' ) ) {
		return isset( $scopes['blocks'] ) ? 'blocks' : null;
	}

	$scope = substr( $slug, strlen( 'guideline-' ) );

	return isset( $scopes[ $scope ] ) ? $scope : null;
}

/**
 * Normalizes a guideline row as it is written over REST.
 *
 * Hooked to `rest_pre_insert_wp_knowledge`. Guideline rows are only created and
 * edited over REST, so this one callback owns every guideline-specific change.
 * Keeping them in one place makes it clear they apply to guideline rows and
 * nothing else.
 *
 * A row counts as a guideline only when its slug maps to a registered scope and it
 * is typed (or left for the server to type) as `guideline`. A request that types
 * the row as something else is left alone, so a guideline slug cannot be
 * repurposed. For a guideline row the callback fills in the `guideline` type when
 * the request omits it, applies the scope title (block rows keep their own), and
 * caps the content length.
 *
 * @since 7.2.0
 * @access private
 *
 * @param stdClass|WP_Error $prepared_post Prepared post, or WP_Error from a prior filter.
 * @param WP_REST_Request   $request       Request object.
 * @return stdClass|WP_Error Prepared post object, or a WP_Error to reject the write.
 */
function wp_guideline_prepare_rest_row( $prepared_post, $request ) {
	// A prior filter may have rejected the write. Pass the error through untouched.
	if ( is_wp_error( $prepared_post ) ) {
		return $prepared_post;
	}

	// Resolve the target slug from the request, or from the existing row on an
	// update that does not send one.
	$slug = '';
	if ( ! empty( $prepared_post->post_name ) ) {
		$slug = (string) $prepared_post->post_name;
	} elseif ( ! empty( $prepared_post->ID ) ) {
		$existing = get_post( $prepared_post->ID );
		if ( $existing instanceof WP_Post ) {
			$slug = (string) $existing->post_name;
		}
	}

	$scope = wp_guideline_scope_from_slug( $slug );
	if ( null === $scope ) {
		return $prepared_post;
	}

	// Assign the guideline type when the request selects none. If it selects a
	// type, it must include the guideline term, or the row is not a guideline.
	$selected_terms = $request['wp_knowledge_type'];
	$guideline_term = term_exists( 'guideline', 'wp_knowledge_type' );
	$guideline_id   = is_array( $guideline_term ) ? (int) $guideline_term['term_id'] : 0;

	if ( empty( $selected_terms ) ) {
		// Assign the guideline type, creating the term on first use. Reject the
		// write if the term cannot be created.
		if ( 0 === $guideline_id ) {
			$created = wp_insert_term( 'guideline', 'wp_knowledge_type' );
			if ( is_wp_error( $created ) ) {
				return new WP_Error(
					'rest_cannot_create_guideline_type',
					__( 'The guideline type could not be created.' ),
					array( 'status' => 500 )
				);
			}
			$guideline_id = (int) $created['term_id'];
		}
		$request['wp_knowledge_type'] = array( $guideline_id );
	} elseif ( 0 === $guideline_id || ! in_array( $guideline_id, array_map( 'intval', (array) $selected_terms ), true ) ) {
		return $prepared_post;
	}

	// A single-row scope takes its registry title in the site locale. The blocks
	// scope is multi-row, so its per-block rows keep their block-name title.
	if ( 'blocks' !== $scope ) {
		$switched_locale = switch_to_locale( get_locale() );
		$scopes          = wp_guideline_scopes();
		if ( $switched_locale ) {
			restore_previous_locale();
		}

		if ( isset( $scopes[ $scope ]['title'] ) ) {
			$prepared_post->post_title = $scopes[ $scope ]['title'];
		}
	}

	// Reduce content to plain text and cap its length.
	if ( isset( $prepared_post->post_content ) ) {
		$content = sanitize_textarea_field( $prepared_post->post_content );
		$max     = wp_guideline_max_length();
		if ( mb_strlen( $content, 'UTF-8' ) > $max ) {
			$content = mb_substr( $content, 0, $max, 'UTF-8' );
		}
		$prepared_post->post_content = $content;
	}

	return $prepared_post;
}
