<?php

declare( strict_types=1 );

/**
 * Tests for the core/settings-get ability shipped with the Abilities API.
 *
 * @covers wp_register_core_abilities
 * @covers register_initial_settings
 * @covers WP_Abilities_Settings
 *
 * @group abilities-api
 */
class Tests_Abilities_API_WpRegisterCoreSettingsGetAbility extends WP_UnitTestCase {

	/**
	 * Registered settings to restore after these tests.
	 *
	 * @var array|null
	 */
	private static $registered_settings_backup;

	/**
	 * Makes core abilities and a custom setting available to these tests.
	 *
	 * The test bootstrap has already registered core settings during init.
	 *
	 * @since 7.2.0
	 */
	public static function wpSetUpBeforeClass(): void {
		global $wp_registered_settings;
		self::$registered_settings_backup = $wp_registered_settings;

		// Include a custom setting to verify that plugins can expose settings too.
		self::run_on_init(
			static function () {
				register_setting(
					'general',
					'core_settings_get_ability_test_option',
					array(
						'type'              => 'integer',
						'label'             => 'Custom Ability Setting',
						'description'       => 'A custom setting exposed through the Abilities API.',
						'show_in_abilities' => true,
						'default'           => 42,
					)
				);
			}
		);

		// Temporarily remove the unhook functions so we can register core abilities.
		remove_action( 'wp_abilities_api_categories_init', '_unhook_core_ability_categories_registration', 1 );
		remove_action( 'wp_abilities_api_init', '_unhook_core_abilities_registration', 1 );

		add_action( 'wp_abilities_api_categories_init', 'wp_register_core_ability_categories' );
		add_action( 'wp_abilities_api_init', 'wp_register_core_abilities' );
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );

		/*
		 * Restore the hooks right away instead of after the class.
		 * The first test of a run snapshots the hooks and every test resets them to that snapshot,
		 * so changes left here would leak into every later test whenever this class runs first.
		 */
		remove_action( 'wp_abilities_api_categories_init', 'wp_register_core_ability_categories' );
		remove_action( 'wp_abilities_api_init', 'wp_register_core_abilities' );
		add_action( 'wp_abilities_api_categories_init', '_unhook_core_ability_categories_registration', 1 );
		add_action( 'wp_abilities_api_init', '_unhook_core_abilities_registration', 1 );
	}

	/**
	 * Restores settings and removes test abilities so they do not affect other tests.
	 *
	 * @since 7.2.0
	 */
	public static function wpTearDownAfterClass(): void {
		foreach ( wp_get_abilities() as $ability ) {
			wp_unregister_ability( $ability->get_name() );
		}
		foreach ( wp_get_ability_categories() as $ability_category ) {
			wp_unregister_ability_category( $ability_category->get_slug() );
		}

		unregister_setting( 'general', 'core_settings_get_ability_test_option' );

		global $wp_registered_settings;
		$wp_registered_settings = self::$registered_settings_backup;
	}

	/**
	 * Provides the init context required to register settings used by a test.
	 *
	 * The test bootstrap has already completed init. Simulating its context avoids
	 * rerunning unrelated callbacks attached to that action.
	 *
	 * @param callable $callback The registration callback.
	 */
	private static function run_on_init( callable $callback ): void {
		global $wp_current_filter;

		$wp_current_filter[] = 'init';
		try {
			$callback();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Core settings metadata must be available without starting a REST API request.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_registration_runs_on_init(): void {
		$this->assertSame( 10, has_action( 'init', 'register_initial_settings' ) );
		$this->assertFalse( has_action( 'rest_api_init', 'register_initial_settings' ) );
	}

	/**
	 * Refreshes the ability to include settings added or changed by a test.
	 */
	private function register_ability(): void {
		if ( wp_has_ability( 'core/settings-get' ) ) {
			wp_unregister_ability( 'core/settings-get' );
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new WP_Abilities_Settings() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Uses an administrator account with permission to read settings.
	 */
	private function become_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * The ability exposes core settings registered during init by the test bootstrap.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_exposes_registered_core_settings(): void {
		$ability = wp_get_ability( 'core/settings-get' );

		$this->assertArrayHasKey( 'title', $ability->get_output_schema()['properties'], 'The output schema should describe the site title, registered during init.' );

		$this->become_admin();
		$result = $ability->execute( array( 'fields' => array( 'title' ) ) );

		$this->assertArrayHasKey( 'title', $result, 'The site title should be returned, registered during init.' );
	}

	/**
	 * Registering core metadata must not change the options saved by admin forms.
	 *
	 * @ticket 64605
	 */
	public function test_register_preserves_new_allowed_options(): void {
		global $new_allowed_options;

		$backup   = $new_allowed_options;
		$expected = array( 'general' => array( 'my_custom_option' ) );
		try {
			$new_allowed_options = $expected;
			self::run_on_init( 'register_initial_settings' );

			$this->assertSame( $expected, $new_allowed_options );
		} finally {
			$new_allowed_options = $backup;
		}
	}

	/**
	 * A registration filter can exclude a core setting from the ability schemas.
	 *
	 * @ticket 64605
	 */
	public function test_registration_filter_can_hide_a_core_setting(): void {
		global $wp_registered_settings;

		$backup = $wp_registered_settings;
		$filter = static function ( $args, $defaults, $group, $name ) {
			if ( 'blogname' === $name ) {
				$args['show_in_abilities'] = false;
			}
			return $args;
		};

		add_filter( 'register_setting_args', $filter, 10, 4 );
		try {
			self::run_on_init( 'register_initial_settings' );
			$this->register_ability();
			$ability = wp_get_ability( 'core/settings-get' );

			$this->assertArrayNotHasKey( 'title', $ability->get_output_schema()['properties'] );
			$this->assertNotContains( 'title', $ability->get_input_schema()['properties']['fields']['items']['enum'] );
		} finally {
			remove_filter( 'register_setting_args', $filter, 10 );
			$wp_registered_settings = $backup;
			$this->register_ability();
		}
	}

	/**
	 * A filter can expose a plugin setting with a custom public name and label.
	 *
	 * The ability must use the filtered metadata in its schema and results.
	 *
	 * @ticket 64605
	 */
	public function test_registration_filter_controls_plugin_setting_schema(): void {
		$option = 'filtered_ability_setting';
		$filter = static function ( $args, $defaults, $group, $name ) use ( $option ) {
			if ( $option === $name ) {
				$args['show_in_abilities'] = array( 'name' => 'public_setting' );
				$args['label']             = 'Filtered label';
			}
			return $args;
		};

		add_filter( 'register_setting_args', $filter, 10, 4 );
		try {
			self::run_on_init(
				static function () use ( $option ) {
					register_setting( 'general', $option, array( 'default' => 'plugin value' ) );
				}
			);
			$this->register_ability();
			$this->become_admin();
			$ability = wp_get_ability( 'core/settings-get' );

			$this->assertSame( 'Filtered label', $ability->get_output_schema()['properties']['public_setting']['title'] );
			$this->assertSame( array( 'public_setting' => 'plugin value' ), $ability->execute( array( 'fields' => array( 'public_setting' ) ) ) );
		} finally {
			remove_filter( 'register_setting_args', $filter, 10 );
			unregister_setting( 'general', $option );
			$this->register_ability();
		}
	}

	/**
	 * The settings ability is not registered when no settings are exposed to it.
	 *
	 * @ticket 64605
	 */
	public function test_settings_abilities_are_not_registered_without_exposed_settings(): void {
		global $wp_registered_settings;

		$registered_settings_backup = $wp_registered_settings;
		$wp_registered_settings     = array();

		try {
			$this->register_ability();

			$this->assertFalse( wp_has_ability( 'core/settings-get' ), 'The settings ability should not be registered when no setting is exposed.' );
		} finally {
			$wp_registered_settings = $registered_settings_backup;

			// Register the ability again for the tests that follow.
			$this->register_ability();
		}
	}

	/**
	 * The ability is registered in the `site` category and flagged read-only.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_ability_is_registered(): void {
		$ability = wp_get_ability( 'core/settings-get' );

		$this->assertInstanceOf( WP_Ability::class, $ability, 'The settings ability should be registered.' );
		$this->assertSame( 'core/settings-get', $ability->get_name(), 'The registered ability should use the expected name.' );
		$this->assertSame( 'Get Settings', $ability->get_label(), 'The settings ability should use a verb-first label.' );
		$this->assertSame( 'site', $ability->get_category(), 'The settings ability should use the site category.' );
		$this->assertTrue( $ability->get_meta_item( 'public', false ), 'The settings ability should be marked public.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The settings ability should be exposed over REST.' );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertTrue( $annotations['readonly'], 'The settings ability should be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'The settings ability should not be marked destructive.' );
	}

	/**
	 * Settings exposed with `show_in_abilities => true` use the same names as in the
	 * REST API settings endpoint.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_uses_rest_api_setting_names(): void {
		$properties = wp_get_ability( 'core/settings-get' )->get_output_schema()['properties'];

		foreach ( get_registered_settings() as $option_name => $args ) {
			if ( empty( $args['show_in_abilities'] ) || empty( $args['show_in_rest'] ) ) {
				continue;
			}

			$rest_name = is_array( $args['show_in_rest'] ) && ! empty( $args['show_in_rest']['name'] ) ? $args['show_in_rest']['name'] : $option_name;
			$this->assertArrayHasKey( $rest_name, $properties, "The {$option_name} setting should use its REST API name." );
		}
	}

	/**
	 * A setting exposed with `show_in_abilities => true` reuses its REST API name and schema,
	 * while an array is used instead of the REST API arguments.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_inherits_rest_api_exposure(): void {
		self::run_on_init(
			static function () {
				register_setting(
					'general',
					'core_settings_get_inherit_test_option',
					array(
						'show_in_rest'      => array(
							'name'   => 'inherited_name',
							'schema' => array( 'enum' => array( 'a', 'b' ) ),
						),
						'show_in_abilities' => true,
					)
				);
				register_setting(
					'general',
					'core_settings_get_override_test_option',
					array(
						'show_in_rest'      => array(
							'name' => 'rest_name',
						),
						'show_in_abilities' => array(
							'name' => 'ability_name',
						),
					)
				);
			}
		);

		try {
			$this->register_ability();
			$properties = wp_get_ability( 'core/settings-get' )->get_output_schema()['properties'];

			$this->assertSame( array( 'a', 'b' ), $properties['inherited_name']['enum'], 'A setting exposed with true should reuse its REST API schema.' );
			$this->assertArrayHasKey( 'ability_name', $properties, 'A setting exposed with an array should use the name from that array.' );
			$this->assertArrayNotHasKey( 'rest_name', $properties, 'A setting exposed with an array should not use its REST API name.' );
		} finally {
			unregister_setting( 'general', 'core_settings_get_inherit_test_option' );
			unregister_setting( 'general', 'core_settings_get_override_test_option' );
			$this->register_ability();
		}
	}

	/**
	 * The input schema exposes optional `group` and `fields` filters.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_input_schema_exposes_group_and_fields_filters(): void {
		$schema = wp_get_ability( 'core/settings-get' )->get_input_schema();

		$this->assertSame( 'object', $schema['type'], 'The settings ability input schema should describe an object.' );
		$this->assertSame( array(), $schema['default'], 'The input should default to empty, which returns every exposed setting.' );
		$this->assertArrayNotHasKey( 'oneOf', $schema, 'The input schema should not model exclusive modes.' );

		$this->assertContains( 'general', $schema['properties']['group']['enum'], 'The group enum should offer the general group.' );
		$this->assertContains( 'reading', $schema['properties']['group']['enum'], 'The group enum should offer the reading group.' );

		$this->assertContains( 'title', $schema['properties']['fields']['items']['enum'], 'The fields enum should offer the site title.' );
		$this->assertContains( 'posts_per_page', $schema['properties']['fields']['items']['enum'], 'The fields enum should offer posts_per_page.' );
		$this->assertContains( 'page_for_privacy_policy', $schema['properties']['fields']['items']['enum'], 'The fields enum should offer page_for_privacy_policy.' );
		$this->assertTrue( $schema['properties']['fields']['uniqueItems'], 'The fields option should reject duplicate names.' );
	}

	/**
	 * Without input the ability returns a flat map of correctly typed setting values.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_returns_flat_typed_values(): void {
		$this->become_admin();

		update_option( 'blogname', 'My Test Site' );
		update_option( 'posts_per_page', 7 );
		update_option( 'use_smilies', '1' );

		$result = wp_get_ability( 'core/settings-get' )->execute( array() );

		$this->assertIsArray( $result, 'The ability should return the settings.' );
		$this->assertSame( 'My Test Site', $result['title'], 'The site title should be returned as a string under its REST API name.' );
		$this->assertSame( 7, $result['posts_per_page'], 'An integer setting should be returned as an integer.' );
		$this->assertTrue( $result['use_smilies'], 'A boolean setting should be returned as a boolean.' );
	}

	/**
	 * The `group` filter narrows the response to a single settings group.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_filters_by_group(): void {
		$this->become_admin();

		$result = wp_get_ability( 'core/settings-get' )->execute( array( 'group' => 'reading' ) );

		$this->assertArrayHasKey( 'posts_per_page', $result, 'A setting of the requested group should be returned.' );
		$this->assertArrayNotHasKey( 'title', $result, 'A setting of another group should be left out.' );
	}

	/**
	 * The `fields` filter narrows the response to the requested setting names.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_filters_by_fields(): void {
		$this->become_admin();

		$result = wp_get_ability( 'core/settings-get' )->execute( array( 'fields' => array( 'title', 'posts_per_page' ) ) );

		$this->assertEqualSets( array( 'title', 'posts_per_page' ), array_keys( $result ), 'Only the requested settings should be returned.' );
	}

	/**
	 * Supplying both `group` and `fields` narrows the response to their intersection.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_combines_group_and_fields_filters(): void {
		$this->become_admin();

		// `title` is in the `general` group and `posts_per_page` in `reading`; only the
		// latter satisfies both filters.
		$result = wp_get_ability( 'core/settings-get' )->execute(
			array(
				'group'  => 'reading',
				'fields' => array( 'title', 'posts_per_page' ),
			)
		);

		$this->assertEqualSets( array( 'posts_per_page' ), array_keys( $result ), 'Only the requested setting of the requested group should be returned.' );
	}

	/**
	 * Input passed as an object is filtered like input passed as an array.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_filters_object_input(): void {
		$this->become_admin();

		$result = wp_get_ability( 'core/settings-get' )->execute( (object) array( 'group' => 'reading' ) );

		$this->assertArrayHasKey( 'posts_per_page', $result, 'A setting of the requested group should be returned for object input.' );
		$this->assertArrayNotHasKey( 'title', $result, 'A setting of another group should be left out for object input.' );
	}

	/**
	 * A `fields` list passed as a comma-separated string is filtered like a `fields` array.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_filters_fields_passed_as_a_string(): void {
		$this->become_admin();

		$result = wp_get_ability( 'core/settings-get' )->execute( array( 'fields' => 'title,posts_per_page' ) );

		$this->assertEqualSets( array( 'title', 'posts_per_page' ), array_keys( $result ), 'A comma-separated fields string should select the requested settings.' );
	}

	/**
	 * Users without `manage_options` cannot run the ability.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = wp_get_ability( 'core/settings-get' )->execute( array() );

		$this->assertWPError( $result, 'A user without manage_options should be refused.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'The refusal should use the invalid permissions error.' );
	}

	/**
	 * A setting registered with `show_in_abilities` (for example by a plugin) is exposed by the ability.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_exposes_a_custom_registered_setting(): void {
		$ability = wp_get_ability( 'core/settings-get' );

		// Present in both the input `fields` enum and the output schema built at registration.
		$this->assertContains( 'core_settings_get_ability_test_option', $ability->get_input_schema()['properties']['fields']['items']['enum'], 'A custom setting should be offered in the fields enum.' );
		$this->assertArrayHasKey( 'core_settings_get_ability_test_option', $ability->get_output_schema()['properties'], 'A custom setting should be described in the output schema.' );

		// And returned, correctly typed, by execute.
		$this->become_admin();
		update_option( 'core_settings_get_ability_test_option', 7 );

		$result = $ability->execute( array( 'fields' => array( 'core_settings_get_ability_test_option' ) ) );

		$this->assertSame( array( 'core_settings_get_ability_test_option' => 7 ), $result, 'A custom setting should be returned with its typed value.' );
	}

	/**
	 * A setting shown in the REST API without `show_in_abilities` is not exposed.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_skips_a_setting_only_shown_in_rest(): void {
		$option = 'core_settings_get_ability_rest_only_test_option';

		register_setting(
			'general',
			$option,
			array(
				'show_in_rest' => true,
			)
		);

		try {
			$this->register_ability();

			$this->assertArrayNotHasKey( $option, wp_get_ability( 'core/settings-get' )->get_output_schema()['properties'], 'A setting only shown in the REST API should not be exposed.' );
		} finally {
			unregister_setting( 'general', $option );
			$this->register_ability();
		}
	}

	/**
	 * A value that does not match its schema is left out instead of failing the whole call.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_drops_values_that_fail_their_schema(): void {
		$this->become_admin();

		// sanitize_option() only coerces '0' and '' to 'closed', so this out-of-enum value sticks.
		update_option( 'default_ping_status', 'not-a-valid-status' );

		$result = wp_get_ability( 'core/settings-get' )->execute( array() );

		$this->assertNotWPError( $result, 'One bad value must not fail the whole ability.' );
		$this->assertArrayHasKey( 'title', $result, 'The other settings should still be returned.' );
		$this->assertArrayNotHasKey( 'default_ping_status', $result, 'Only the bad value should be left out.' );
	}

	/**
	 * Stored values are validated against their schema before and after sanitizing, and left out
	 * when it rejects them.
	 *
	 * @ticket 64605
	 *
	 * @dataProvider data_stored_values
	 *
	 * @param string      $type     The setting type.
	 * @param mixed       $stored   The stored option value.
	 * @param string|null $expected The value as JSON, or null when it is left out.
	 * @param array       $schema   Optional. The `show_in_abilities` schema of the setting. Default empty array.
	 */
	public function test_core_settings_get_reads_stored_values( string $type, $stored, ?string $expected, array $schema = array() ): void {
		// A numeric name, which PHP turns into an integer array key, must still match `fields`.
		$option = '123';

		self::run_on_init(
			static function () use ( $option, $type, $schema ) {
				register_setting(
					'general',
					$option,
					array(
						'type'              => $type,
						'show_in_abilities' => array( 'schema' => $schema ),
					)
				);
			}
		);
		update_option( $option, $stored );

		try {
			$this->register_ability();
			$this->become_admin();

			$result = wp_get_ability( 'core/settings-get' )->execute( array( 'fields' => array( $option ) ) );
		} finally {
			unregister_setting( 'general', $option );
			$this->register_ability();
		}

		$this->assertSame( $expected, isset( $result[ $option ] ) ? wp_json_encode( $result[ $option ] ) : null, 'The stored value should be read as expected, or left out.' );
	}

	/**
	 * Provides stored values that need type conversion or fail schema validation.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: string|null, 3?: array<string, mixed>}> Stored values, and the JSON they are read as.
	 */
	public static function data_stored_values(): array {
		return array(
			'"false" for a boolean'               => array( 'boolean', 'false', 'false' ),
			'an empty string for a boolean'       => array( 'boolean', '', 'false' ),
			'a stdClass for an object'            => array(
				'object',
				(object) array( 'a' => 1 ),
				'{"a":1}',
				array( 'properties' => array( 'a' => array( 'type' => 'integer' ) ) ),
			),
			'an undeclared property in an object' => array( 'object', array( 'a' => 1 ), null ),
			'an empty array for an object'        => array( 'object', array(), '{}' ),
			'a list with gaps for an array'       => array(
				'array',
				array(
					0 => 'a',
					2 => 'b',
				),
				'["a","b"]',
			),
			'a numeric string for an integer'     => array( 'integer', '7', '7' ),
			'a non-numeric string for an integer' => array( 'integer', 'abc', null ),
			'an email that sanitizing breaks'     => array( 'string', '%ab@x.co', null, array( 'format' => 'email' ) ),
		);
	}

	/**
	 * A setting of a type the settings endpoint does not support is not exposed.
	 *
	 * @ticket 64605
	 */
	public function test_core_settings_get_skips_a_setting_with_an_unsupported_type(): void {
		$option = 'core_settings_get_ability_type_test_option';

		self::run_on_init(
			static function () use ( $option ) {
				register_setting(
					'general',
					$option,
					array(
						'type'              => 'foo',
						'show_in_abilities' => true,
					)
				);
			}
		);
		update_option( $option, 'value' );

		try {
			$this->register_ability();
			$this->become_admin();

			$ability = wp_get_ability( 'core/settings-get' );

			$this->assertArrayNotHasKey( $option, $ability->get_output_schema()['properties'], 'A setting of an unsupported type should not be described in the output schema.' );
			$this->assertArrayNotHasKey( $option, $ability->execute( array() ), 'A setting of an unsupported type should not be returned.' );
		} finally {
			unregister_setting( 'general', $option );
			$this->register_ability();
		}
	}
}
