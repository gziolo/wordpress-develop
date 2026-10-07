<?php
/**
 * Abilities API: WP_Abilities_Settings class.
 *
 * @package WordPress
 * @subpackage Abilities API
 * @since 7.2.0
 */

declare( strict_types = 1 );

/**
 * Core class used to register settings-related abilities.
 *
 * Provides the read-only `core/settings-get` ability. Its schemas and results use
 * the same set of settings, selected when the ability is registered.
 *
 * Abilities are registered on `wp_abilities_api_init`, when the abilities registry
 * is first used after `init`. Core settings and plugin settings with `show_in_abilities`
 * must be registered on `init` or earlier so they are available at that point.
 * Filters that change setting arguments must be attached before the settings are
 * registered. Later changes are not reflected in an already registered ability.
 *
 * This class is part of WordPress' internal implementation of the core abilities and is
 * not part of the public API. It may be changed or removed at any time without notice.
 * Do not use it directly or rely on its existence.
 *
 * @since 7.2.0
 *
 * @access private
 */
final class WP_Abilities_Settings {

	/**
	 * The ability category used for settings abilities.
	 *
	 * @since 7.2.0
	 * @var string
	 */
	private const CATEGORY = 'site';

	/**
	 * Settings available to the ability when it was registered.
	 *
	 * Keeping this set unchanged ensures that the ability returns only settings
	 * described by its schemas.
	 *
	 * @since 7.2.0
	 * @var array<string, array{option: string, group: string, schema: array<string, mixed>}>
	 */
	private $exposed_settings = array();

	/**
	 * Registers all settings abilities.
	 *
	 * Must run on the `wp_abilities_api_init` hook. Registers nothing when no setting is
	 * exposed to abilities.
	 *
	 * @since 7.2.0
	 */
	public function register(): void {
		$this->exposed_settings = $this->get_exposed_settings();
		if ( empty( $this->exposed_settings ) ) {
			return;
		}

		$this->register_settings_get();
	}

	/**
	 * Registers the read-only `core/settings-get` ability.
	 *
	 * @since 7.2.0
	 */
	private function register_settings_get(): void {
		$groups = array_values( array_unique( array_filter( array_column( $this->exposed_settings, 'group' ) ) ) );

		wp_register_ability(
			'core/settings-get',
			array(
				'label'               => __( 'Get Settings' ),
				'description'         => __( 'Returns WordPress settings as a flat map of setting name to value. By default returns all settings exposed to abilities, or optionally a subset filtered by settings group, by setting name, or both. A setting whose value does not match its schema is left out. Requires an authenticated user who can manage options.' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_settings_get_input_schema( $groups, array_map( 'strval', array_keys( $this->exposed_settings ) ) ),
				'output_schema'       => $this->get_settings_get_output_schema(),
				'execute_callback'    => array( $this, 'execute_settings_get' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'public'      => true,
				),
			)
		);
	}

	/**
	 * Executes the `core/settings-get` ability.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed> Map of exposed setting name to current value.
	 */
	public function execute_settings_get( $input = array() ): array {
		$input  = rest_sanitize_object( $input );
		$group  = isset( $input['group'] ) && is_string( $input['group'] ) ? $input['group'] : '';
		$fields = rest_sanitize_array( $input['fields'] ?? array() );

		$result = array();
		foreach ( $this->exposed_settings as $exposed_name => $setting ) {
			if ( '' !== $group && $setting['group'] !== $group ) {
				continue;
			}
			if ( ! empty( $fields ) && ! in_array( (string) $exposed_name, $fields, true ) ) {
				continue;
			}

			$value = get_option( $setting['option'] );

			// WordPress stores false as '', which the boolean schema rejects.
			if ( '' === $value && 'boolean' === $setting['schema']['type'] ) {
				$value = false;
			}

			/*
			 * Leave out a value its schema rejects, before sanitizing (which could make it pass) or
			 * after (which could make it fail), instead of failing output validation for every setting.
			 */
			if ( is_wp_error( rest_validate_value_from_schema( $value, $setting['schema'] ) ) ) {
				continue;
			}

			$value = rest_sanitize_value_from_schema( $value, $setting['schema'] );
			if ( is_wp_error( rest_validate_value_from_schema( $value, $setting['schema'] ) ) ) {
				continue;
			}

			// Object (not array()) so an empty object value is serialized as {}, consistent with type:object.
			$result[ $exposed_name ] = 'object' === $setting['schema']['type'] ? (object) $value : $value;
		}

		return $result;
	}

	/**
	 * Permission callback for the settings abilities.
	 *
	 * The settings abilities read site options, so they are gated on the `manage_options`
	 * capability regardless of the input.
	 *
	 * @since 7.2.0
	 *
	 * @return bool True if the current user can manage options.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Builds the input schema for the `core/settings-get` ability: optional filters by group and/or name.
	 *
	 * Both `group` and `fields` are optional; supplying both narrows the response to their
	 * intersection, and supplying neither returns every exposed setting.
	 *
	 * @since 7.2.0
	 *
	 * @param list<string> $groups      Available settings groups.
	 * @param list<string> $field_names Available exposed setting names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_settings_get_input_schema( array $groups, array $field_names ): array {
		return array(
			'type'                 => 'object',
			'default'              => array(),
			'properties'           => array(
				'group'  => array(
					'type'        => 'string',
					'enum'        => $groups,
					'description' => __( 'Return only settings that belong to this settings group.' ),
				),
				'fields' => array(
					'type'        => 'array',
					'uniqueItems' => true,
					'items'       => array(
						'type' => 'string',
						'enum' => $field_names,
					),
					'description' => __( 'Return only the settings with these names.' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Builds the output schema for the `core/settings-get` ability.
	 *
	 * No setting is marked required because the `group` and `fields` inputs let the caller
	 * request any subset, and a setting whose value does not match its schema is left out.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_settings_get_output_schema(): array {
		return array(
			'type'                 => 'object',
			'description'          => __( 'A map of setting name to its current value.' ),
			'properties'           => wp_list_pluck( $this->exposed_settings, 'schema' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the settings exposed through the Abilities API.
	 *
	 * Reads {@see get_registered_settings()} and keeps only settings flagged with a truthy
	 * `show_in_abilities` argument, of a type the settings endpoint supports. Each entry is
	 * keyed by its exposed name and carries the underlying option name, the settings group,
	 * and a JSON Schema describing the value.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, array{option: string, group: string, schema: array<string, mixed>}> Settings keyed by exposed name.
	 */
	private function get_exposed_settings(): array {
		$settings = array();

		foreach ( get_registered_settings() as $option_name => $args ) {
			if ( empty( $args['show_in_abilities'] ) ) {
				continue;
			}

			$show = $this->get_exposure_args( $args );

			$schema = $this->value_schema( $args, $show );
			if ( ! in_array( $schema['type'], array( 'number', 'integer', 'string', 'boolean', 'array', 'object' ), true ) ) {
				continue;
			}

			$option_name = (string) $option_name;

			$settings[ empty( $show['name'] ) ? $option_name : $show['name'] ] = array(
				'option' => $option_name,
				'group'  => $args['group'] ?? '',
				'schema' => $schema,
			);
		}

		return $settings;
	}

	/**
	 * Returns the name and schema overrides used to expose a setting to abilities.
	 *
	 * When `show_in_abilities` is `true`, the setting is exposed the same way as in the
	 * REST API: it uses the `name` and `schema` from `show_in_rest`. An array is used
	 * as is.
	 *
	 * @since 7.2.0
	 *
	 * @param array<string, mixed> $args The setting registration arguments.
	 * @return array<string, mixed> The exposure arguments, with optional `name` and `schema` keys.
	 */
	private function get_exposure_args( array $args ): array {
		if ( is_array( $args['show_in_abilities'] ) ) {
			return $args['show_in_abilities'];
		}

		return is_array( $args['show_in_rest'] ) ? $args['show_in_rest'] : array();
	}

	/**
	 * Builds the JSON Schema describing a single setting's value.
	 *
	 * As in the settings endpoint, objects in the schema reject properties they do not declare,
	 * unless the schema allows them.
	 *
	 * @since 7.2.0
	 *
	 * @param array<string, mixed> $args The setting registration arguments.
	 * @param array<string, mixed> $show The exposure arguments, see get_exposure_args().
	 * @return array<string, mixed> The value JSON Schema.
	 */
	private function value_schema( array $args, $show ): array {
		$schema = array(
			'type' => $args['type'],
		);
		if ( ! empty( $args['label'] ) ) {
			$schema['title'] = $args['label'];
		}
		if ( ! empty( $args['description'] ) ) {
			$schema['description'] = $args['description'];
		}
		if ( isset( $show['schema'] ) && is_array( $show['schema'] ) ) {
			/** @var array<string, mixed> $show_schema */
			$show_schema = $show['schema'];
			$schema      = array_merge( $schema, $show_schema );
		}

		return rest_default_additional_properties_to_false( $schema );
	}
}
