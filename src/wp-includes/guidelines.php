<?php
/**
 * Guidelines API: Public functions for the guideline consumer layer.
 *
 * Guidelines are a consumer of the `wp_knowledge` storage primitive. Each
 * guideline is a `wp_knowledge` row that carries the `guideline` type term and a
 * `guideline-{scope}` slug. This file holds the scope registry, the scope
 * resolver, the content length limit, and the single REST insert callback that
 * shapes a guideline row: for a recognized scope slug it sets the guideline type,
 * sets the title, and caps the content, all in one place.
 *
 * @package WordPress
 * @subpackage Guidelines
 * @since 7.1.0
 */

/**
 * Retrieves the registered guideline scopes, keyed by slug.
 *
 * A scope groups guideline content under a stable key. Each scope is backed by
 * at most one `guideline`-typed `wp_knowledge` row whose slug is
 * `guideline-{scope}`. Rows are created on first save. Plugins can register or
 * remove scopes via the {@see 'wp_guideline_scopes'} filter.
 *
 * The `blocks` scope is the one exception. It has no single `guideline-blocks`
 * row. Its guidelines are stored per block in `guideline-block-*` rows.
 *
 * @since 7.1.0
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
	 * @since 7.1.0
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
 * @since 7.1.0
 *
 * @return int Maximum number of characters allowed in guideline content.
 */
function wp_guideline_max_length(): int {
	/**
	 * Filters the maximum length, in characters, of a guideline row's content.
	 *
	 * @since 7.1.0
	 *
	 * @param int $max_length Maximum number of characters. Default 5000.
	 */
	return (int) apply_filters( 'wp_guideline_max_length', 5000 );
}

/**
 * Resolves a registry scope key from a guideline row slug.
 *
 * Returns the scope key for a `guideline-{scope}` slug that matches a registered
 * scope. Per-block rows (`guideline-block-{block}`) resolve to the `blocks` scope
 * when it is registered. Returns null for unknown scopes, and for block rows when
 * the `blocks` scope is not registered.
 *
 * @since 7.1.0
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
 * Shapes a guideline row on the REST insert path.
 *
 * Hooked to the `rest_pre_insert_wp_knowledge` filter. This is the single place
 * that shapes a guideline row, so every guideline-specific change is applied
 * uniformly and nothing else needs to. Two gates decide whether the row is shaped:
 * the slug must map to a registered scope (see wp_guideline_scope_from_slug(),
 * which resolves both `guideline-{scope}` and per-block `guideline-block-*` rows),
 * and, if the request selects any `wp_knowledge_type` terms, the `guideline` term
 * must be among them. A row that fails either gate is left untouched, as is any
 * row written outside REST. When both gates pass:
 *
 * - The `guideline` type is set when the request selects no term. The standard
 *   REST term handling assigns it after insert.
 * - A single-row scope takes its registry title in the site locale. The multi-row
 *   `blocks` scope keeps each row's block-name title.
 * - Content is reduced to plain text and capped at wp_guideline_max_length().
 *
 * @since 7.1.0
 * @access private
 *
 * @param stdClass        $prepared_post Prepared post object.
 * @param WP_REST_Request $request       Request object.
 * @return stdClass Prepared post object.
 */
function wp_guideline_prepare_rest_row( $prepared_post, $request ) {
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

	// This is a guideline row when the request selects no type, in which case the
	// server assigns it the guideline type, or when the type it selects includes
	// the guideline term. Any other selection means the row is not a guideline, so
	// it is left untouched.
	$selected_terms = $request['wp_knowledge_type'];
	$guideline_term = term_exists( 'guideline', 'wp_knowledge_type' );
	$guideline_id   = is_array( $guideline_term ) ? (int) $guideline_term['term_id'] : 0;

	if ( empty( $selected_terms ) ) {
		// Assign the guideline type, creating the term on first use.
		if ( 0 === $guideline_id ) {
			$created = wp_insert_term( 'guideline', 'wp_knowledge_type' );
			if ( is_wp_error( $created ) ) {
				return $prepared_post;
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
