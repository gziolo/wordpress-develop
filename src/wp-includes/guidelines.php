<?php
/**
 * Guidelines API: Public functions for the guideline consumer layer.
 *
 * Guidelines are a consumer of the `wp_knowledge` storage primitive. Each
 * guideline is a `wp_knowledge` row that carries the `guideline` type term and a
 * `guideline-{scope}` slug. This file holds the scope registry, the slug
 * helpers, the content length limit, the structural normalizers applied on every
 * write path (the type reservation and the scope-title re-stamp), and the REST
 * content sanitizer that shapes untrusted input at the request boundary.
 *
 * @package WordPress
 * @subpackage Guidelines
 * @since 7.1.0
 */

/**
 * Retrieves the registered guideline scopes, keyed by slug.
 *
 * Scopes are the sections shown on a Guidelines management screen. Each scope is
 * backed by at most one `guideline`-typed `wp_knowledge` row whose slug is
 * `guideline-{scope}`. Plugins can register their own scopes via the
 * {@see 'wp_guideline_scopes'} filter. The registry carries identity and
 * presentation only. Rows are created on first save.
 *
 * @since 7.1.0
 *
 * @return array {
 *     Slug-keyed map of guideline scopes.
 *
 *     @type array ...$0 {
 *         Data for a single scope.
 *
 *         @type string $title       Human-readable section title.
 *         @type string $description Human-readable section description.
 *         @type int    $order       Sort order on a Guidelines screen.
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
 * Returns the scope key for `guideline-{scope}` slugs that match a registered
 * scope. Returns null for block rows (`guideline-block-*`) and unknown scopes.
 *
 * @since 7.1.0
 * @access private
 *
 * @param string $slug Post slug.
 * @return string|null Scope key, or null if the slug is not a registered scope.
 */
function wp_guideline_scope_from_slug( string $slug ): ?string {
	if ( 0 !== strpos( $slug, 'guideline-' ) || 0 === strpos( $slug, 'guideline-block-' ) ) {
		return null;
	}

	$scope  = substr( $slug, strlen( 'guideline-' ) );
	$scopes = wp_guideline_scopes();

	return isset( $scopes[ $scope ] ) ? $scope : null;
}

/**
 * Reserves the `guideline` type term for guideline rows on save.
 *
 * Hooked to the `save_post_wp_knowledge` action at a priority before the
 * knowledge primitive's default-term fallback (see
 * wp_knowledge_ensure_default_type_term()). Rows whose slug begins with
 * `guideline-` are forced onto the `guideline` type, so the prefix is reserved
 * for guideline-typed rows. Once the term is assigned the primitive fallback
 * sees a term and leaves the row alone.
 *
 * @since 7.1.0
 * @access private
 *
 * @param int $post_id Saved post ID.
 */
function wp_guideline_reserve_type_term( int $post_id ): void {
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post || 0 !== strpos( $post->post_name, 'guideline-' ) ) {
		return;
	}

	/*
	 * Resolve to a term ID up front, creating the term on first use. The
	 * site-locale label is applied by wp_knowledge_maybe_map_term_label() on the
	 * wp_insert_term_data filter.
	 */
	$term = term_exists( 'guideline', 'wp_knowledge_type' );
	if ( ! $term ) {
		$term = wp_insert_term( 'guideline', 'wp_knowledge_type' );
		if ( is_wp_error( $term ) ) {
			return;
		}
	}

	wp_set_object_terms( $post_id, (int) $term['term_id'], 'wp_knowledge_type' );
}

/**
 * Re-stamps a guideline row's title from the scope registry on save.
 *
 * Hooked to the `wp_insert_post_data` filter so the invariant holds no matter
 * how the row is written, not just over REST. For a `guideline-{scope}` slug that
 * matches a registered scope, the title is set from wp_guideline_scopes() in the
 * site locale, replacing any caller-provided title. Block rows
 * (`guideline-block-*`) and unknown scopes are left untouched, so they keep their
 * given title. The filter runs after wp_unique_post_slug(), so a suffixed
 * duplicate slug no longer matches a scope and keeps its title.
 *
 * @since 7.1.0
 * @access private
 *
 * @param array $data    Slashed, sanitized post data about to be written.
 * @param array $postarr Sanitized (and slashed) array of post data as passed.
 * @return array Possibly modified post data.
 */
function wp_guideline_restamp_scope_title( $data, $postarr ) {
	if ( ! isset( $data['post_type'] ) || 'wp_knowledge' !== $data['post_type'] ) {
		return $data;
	}

	$scope = wp_guideline_scope_from_slug( isset( $data['post_name'] ) ? (string) $data['post_name'] : '' );
	if ( null === $scope ) {
		return $data;
	}

	$switched_locale = switch_to_locale( get_locale() );
	$scopes          = wp_guideline_scopes();
	if ( $switched_locale ) {
		restore_previous_locale();
	}

	if ( isset( $scopes[ $scope ]['title'] ) ) {
		// $data is slashed at this filter, so slash the registry title to match.
		$data['post_title'] = wp_slash( $scopes[ $scope ]['title'] );
	}

	return $data;
}

/**
 * Sanitizes guideline content on the REST insert path.
 *
 * Hooked to the `rest_pre_insert_wp_knowledge` filter. For rows whose slug begins
 * with `guideline-`, the content is reduced to plain text and capped at
 * wp_guideline_max_length(). This shapes untrusted client input, so it lives at
 * the REST boundary. The type term and the scope title are structural invariants
 * applied on every write path (see wp_guideline_reserve_type_term() and
 * wp_guideline_restamp_scope_title()).
 *
 * Slug uniqueness is left to WordPress. The first save of a scope keeps its exact
 * slug, later saves reuse that row by ID, and any other row with the same desired
 * slug is suffixed by wp_unique_post_slug(). A Guidelines screen reads the
 * published row by its exact slug, so suffixed rows are ignored.
 *
 * @since 7.1.0
 * @access private
 *
 * @param stdClass        $prepared_post Prepared post object.
 * @param WP_REST_Request $request       Request object.
 * @return stdClass Prepared post object.
 */
function wp_guideline_sanitize_rest_content( $prepared_post, $request ) {
	if ( ! isset( $prepared_post->post_content ) ) {
		return $prepared_post;
	}

	$slug = '';
	if ( ! empty( $prepared_post->post_name ) ) {
		$slug = $prepared_post->post_name;
	} elseif ( ! empty( $prepared_post->ID ) ) {
		$existing = get_post( $prepared_post->ID );
		if ( $existing instanceof WP_Post ) {
			$slug = $existing->post_name;
		}
	}

	if ( 0 !== strpos( (string) $slug, 'guideline-' ) ) {
		return $prepared_post;
	}

	$content = sanitize_textarea_field( $prepared_post->post_content );
	$max     = wp_guideline_max_length();
	if ( mb_strlen( $content, 'UTF-8' ) > $max ) {
		$content = mb_substr( $content, 0, $max, 'UTF-8' );
	}
	$prepared_post->post_content = $content;

	return $prepared_post;
}
