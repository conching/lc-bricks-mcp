<?php
/**
 * Bricks element schema generator service.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SchemaGenerator class.
 *
 * Converts Bricks element registry controls into JSON Schema format.
 * Caches results via WordPress transients with Bricks version in cache key.
 */
class SchemaGenerator {

	/**
	 * Cache option name prefix for schema storage (non-autoloaded wp_options).
	 * Survives full object cache flushes by WP Rocket and similar plugins.
	 * @var string
	 */
	private const CACHE_OPTION_PREFIX = 'bricks_mcp_schema_cache';

	/**
	 * Cache expiry option name.
	 * @var string
	 */
	private const CACHE_EXPIRY_OPTION = 'bricks_mcp_schema_cache_expires';

	/**
	 * Cache duration in seconds (24 hours).
	 * @var int
	 */
	private const CACHE_DURATION = DAY_IN_SECONDS;

	/**
	 * Get all element schemas from Bricks registry.
	 *
	 * Returns the full catalog of all registered element types with their
	 * JSON Schema definitions and minimal working examples.
	 * Results are cached via transients using the Bricks version as cache key.
	 *
	 * @return array<string, array<string, mixed>> Map of element name => schema data.
	 */
	public function get_all_schemas(): array {
		if ( ! class_exists( '\Bricks\Elements' ) ) {
			return [];
		}

		$cache_key = self::CACHE_OPTION_PREFIX . '_' . str_replace( '.', '_', $this->get_bricks_version() );
		$cached    = $this->read_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		$schemas = [];

		// Bricks\Elements::$elements is the registered element registry.
		if ( ! isset( \Bricks\Elements::$elements ) || ! is_array( \Bricks\Elements::$elements ) ) {
			return [];
		}

		foreach ( \Bricks\Elements::$elements as $element_name => $element_entry ) {
			// Bricks stores elements as arrays with 'class' key or as class strings/objects.
			$element_class = is_array( $element_entry ) ? ( $element_entry['class'] ?? null ) : $element_entry;

			if ( null === $element_class ) {
				continue;
			}

			$element_obj = $this->get_element_object( $element_class );

			if ( null === $element_obj ) {
				continue;
			}

			$schemas[ $element_name ] = [
				'name'            => $element_name,
				'label'           => $this->get_element_label( $element_obj ),
				'category'        => $this->get_element_category( $element_obj ),
				'settings_schema' => $this->convert_to_json_schema( $element_obj ),
				'working_example' => $this->generate_working_example( $element_name, $element_obj ),
			];
		}

		$this->write_cache( $cache_key, $schemas );

		return $schemas;
	}

	/**
	 * Get schema for a single element type.
	 *
	 * @param string $element_name The element type name (e.g., 'heading', 'section').
	 * @return array<string, mixed>|\WP_Error Schema data or WP_Error if not found.
	 */
	public function get_element_schema( string $element_name ): array|\WP_Error {
		$all_schemas = $this->get_all_schemas();

		if ( empty( $all_schemas ) && ! class_exists( '\Bricks\Elements' ) ) {
			return new \WP_Error(
				'bricks_not_active',
				__( 'Bricks Builder must be installed and active to retrieve element schemas.', 'bricks-mcp' )
			);
		}

		if ( ! isset( $all_schemas[ $element_name ] ) ) {
			// Suggest similar element names.
			$similar = $this->find_similar_element_names( $element_name, array_keys( $all_schemas ) );

			return new \WP_Error(
				'element_not_found',
				sprintf(
					/* translators: %s: Element type name */
					__( 'Element type "%s" not found in Bricks element registry.', 'bricks-mcp' ),
					$element_name
				),
				[
					'element_type' => $element_name,
					'suggestions'  => $similar,
				]
			);
		}

		return $all_schemas[ $element_name ];
	}

	/**
	 * Get simplified element catalog without full schemas.
	 *
	 * Returns only element name, label, and category for quick reference.
	 *
	 * @return array<int, array<string, string>> List of elements with name, label, category.
	 */
	public function get_element_catalog(): array {
		$all_schemas = $this->get_all_schemas();
		$catalog     = [];

		foreach ( $all_schemas as $element_name => $schema ) {
			$catalog[] = [
				'name'     => $element_name,
				'label'    => $schema['label'] ?? $element_name,
				'category' => $schema['category'] ?? 'general',
			];
		}

		// Sort by category then name.
		usort(
			$catalog,
			static function ( array $a, array $b ): int {
				$cat_cmp = strcmp( $a['category'], $b['category'] );
				if ( 0 !== $cat_cmp ) {
					return $cat_cmp;
				}
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return $catalog;
	}

	/**
	 * Convert a Bricks element's controls to JSON Schema Draft 2020-12.
	 *
	 * Maps Bricks control types to JSON Schema types.
	 * Controls with responsive variants note the key format {key}:{breakpoint}:{pseudo}.
	 *
	 * @param object $element The Bricks element object.
	 * @return array<string, mixed> JSON Schema object for element settings.
	 */
	public function convert_to_json_schema( object $element ): array {
		$controls = [];

		if ( method_exists( $element, 'get_controls' ) ) {
			$controls = $element->get_controls();
		} elseif ( isset( $element->controls ) && is_array( $element->controls ) ) {
			$controls = $element->controls;
		}

		if ( empty( $controls ) || ! is_array( $controls ) ) {
			return [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => true,
			];
		}

		$properties    = [];
		$required      = [];
		$control_count = 0;

		foreach ( $controls as $control_key => $control ) {
			if ( ! is_array( $control ) || ! isset( $control['type'] ) ) {
				continue;
			}

			// Skip group/section/tab separators.
			if ( in_array( $control['type'], [ 'group', 'section', 'tab', 'separator', 'data' ], true ) ) {
				continue;
			}

			++$control_count;
			if ( $control_count > 200 ) {
				// Prevent excessive iteration on complex elements.
				break;
			}

			$control_schema = $this->map_control_type_to_schema( $control );

			// Add description from control definition.
			if ( ! empty( $control['label'] ) ) {
				$control_schema['description'] = $control['label'];
			} elseif ( ! empty( $control['placeholder'] ) ) {
				$control_schema['description'] = $control['placeholder'];
			}

			// Note responsive/state support.
			if ( ! empty( $control['css'] ) ) {
				$existing_desc                 = $control_schema['description'] ?? '';
				$control_schema['description'] = trim( $existing_desc . ' Supports responsive variants: {key}:{breakpoint}:{pseudo} (e.g., ' . $control_key . ':tablet_portrait, ' . $control_key . ':mobile:hover). Use get_breakpoints tool for valid breakpoint names.' );
			}

			$properties[ $control_key ] = $control_schema;

			// Mark as required if explicitly set.
			if ( ! empty( $control['required'] ) && true === $control['required'] ) {
				$required[] = $control_key;
			}
		}

		$schema = [
			'type'                 => 'object',
			'properties'           => empty( $properties ) ? new \stdClass() : $properties,
			'additionalProperties' => true,
		];

		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Generate a minimal working example for an element.
	 *
	 * Returns an example element array with minimal valid settings.
	 *
	 * @param string $element_name The element type name.
	 * @param object $element      The Bricks element object.
	 * @return array<string, mixed> Minimal working example element.
	 */
	public function generate_working_example( string $element_name, object $element ): array {
		// Known element examples with sensible defaults.
		$known_examples = [
			'section'    => [ '_padding' => '40px 0' ],
			'container'  => [],
			'block'      => [],
			'div'        => [],
			'heading'    => [
				'text' => 'Heading Text',
				'tag'  => 'h2',
			],
			'text-basic' => [ 'text' => '<p>Paragraph text</p>' ],
			'text'       => [ 'text' => '<p>Paragraph text</p>' ],
			'button'     => [
				'text' => 'Click Me',
				'link' => [ 'url' => '#' ],
			],
			'image'      => [
				'image' => [
					'id'  => 0,
					'url' => 'https://placeholder.example/800x400',
				],
			],
			'video'      => [ 'videoUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ],
			'divider'    => [],
			'icon'       => [],
			'icon-box'   => [
				'heading' => 'Icon Box Heading',
				'text'    => 'Icon box description.',
			],
			'list'       => [],
			'code'       => [ 'code' => '// Your code here' ],
			'map'        => [ 'address' => 'New York, NY, USA' ],
			'form'       => [
				'fields'           => [
					[
						'id'          => 'abc123',
						'type'        => 'text',
						'label'       => 'Name',
						'placeholder' => 'Your Name',
						'required'    => false,
						'width'       => 100,
					],
					[
						'id'          => 'def456',
						'type'        => 'email',
						'label'       => 'Email',
						'placeholder' => 'Your Email',
						'required'    => true,
						'width'       => 100,
					],
					[
						'id'          => 'ghi789',
						'type'        => 'textarea',
						'label'       => 'Message',
						'placeholder' => 'Your Message',
						'required'    => true,
						'width'       => 100,
					],
				],
				'actions'          => [ 'email' ],
				'successMessage'   => 'Message successfully sent. We will get back to you as soon as possible.',
				'emailSubject'     => 'Contact form request',
				'emailTo'          => 'admin_email',
				'htmlEmail'        => true,
				'submitButtonText' => 'Send',
			],
			'nav-menu'    => [],
			'posts'       => [],
			'pagination'  => [],
			'logo'        => [],
			'search'      => [],
			'toggle-mode' => [
				'ariaLabel' => 'Toggle mode',
			],
		];

		if ( isset( $known_examples[ $element_name ] ) ) {
			return $known_examples[ $element_name ];
		}

		// For unknown elements: use defaults from controls where available.
		$settings = [];

		$controls = [];
		if ( method_exists( $element, 'get_controls' ) ) {
			$controls = $element->get_controls();
		} elseif ( isset( $element->controls ) && is_array( $element->controls ) ) {
			$controls = $element->controls;
		}

		if ( ! is_array( $controls ) ) {
			return $settings;
		}

		$added = 0;
		foreach ( $controls as $control_key => $control ) {
			if ( ! is_array( $control ) || ! isset( $control['type'] ) ) {
				continue;
			}

			// Skip non-setting controls.
			if ( in_array( $control['type'], [ 'group', 'section', 'tab', 'separator', 'data' ], true ) ) {
				continue;
			}

			// Only include controls with explicit defaults or required fields.
			if ( isset( $control['default'] ) ) {
				$settings[ $control_key ] = $control['default'];
				++$added;
			} elseif ( ! empty( $control['required'] ) ) {
				// Use type-appropriate empty value for required fields.
				$settings[ $control_key ] = $this->get_empty_value_for_type( $control['type'] );
				++$added;
			}

			if ( $added >= 5 ) {
				// Keep examples minimal.
				break;
			}
		}

		return $settings;
	}

	/**
	 * Get the Bricks version string for cache key generation.
	 *
	 * @return string Bricks version or 'unknown' if not available.
	 */
	public function get_bricks_version(): string {
		if ( defined( 'BRICKS_VERSION' ) ) {
			return (string) BRICKS_VERSION;
		}
		return 'unknown';
	}

	/**
	 * Flush all schema transient caches.
	 *
	 * Called when Bricks version changes or plugins are updated.
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		global $wpdb;

		// Delete all transients matching our prefix pattern.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::CACHE_OPTION_PREFIX ) . '%'
			)
		);
		delete_option( self::CACHE_EXPIRY_OPTION );
	}

	/**
	 * Read cached schema data from wp_options.
	 *
	 * Returns null if the cache is expired or missing.
	 *
	 * @param string $key Option name for the cached data.
	 * @return array<string, mixed>|null Cached schemas or null if expired/missing.
	 */
	private function read_cache( string $key ): ?array {
		$expires = get_option( self::CACHE_EXPIRY_OPTION, 0 );
		if ( time() > (int) $expires ) {
			return null;
		}
		$data = get_option( $key, null );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Write schema data to wp_options cache (non-autoloaded).
	 *
	 * @param string              $key  Option name for the cached data.
	 * @param array<string, mixed> $data Schema data to cache.
	 * @return void
	 */
	private function write_cache( string $key, array $data ): void {
		update_option( $key, $data, false );
		update_option( self::CACHE_EXPIRY_OPTION, time() + self::CACHE_DURATION, false );
	}

	/**
	 * Map a Bricks control definition to a JSON Schema type.
	 *
	 * @param array<string, mixed> $control Bricks control definition.
	 * @return array<string, mixed> JSON Schema type definition.
	 */
	private function map_control_type_to_schema( array $control ): array {
		$type = $control['type'] ?? 'text';

		switch ( $type ) {
			case 'text':
			case 'textarea':
			case 'code':
			case 'editor':
			case 'richtextEditor':
				return [ 'type' => 'string' ];

			case 'number':
				$schema = [ 'type' => 'number' ];
				if ( isset( $control['min'] ) ) {
					$schema['minimum'] = $control['min'];
				}
				if ( isset( $control['max'] ) ) {
					$schema['maximum'] = $control['max'];
				}
				return $schema;

			case 'checkbox':
			case 'toggle':
				return [ 'type' => 'boolean' ];

			case 'select':
				$schema = [ 'type' => 'string' ];
				if ( ! empty( $control['options'] ) && is_array( $control['options'] ) ) {
					$schema['enum'] = array_values( array_keys( $control['options'] ) );
				}
				return $schema;

			case 'color':
				// Bricks color values are ALWAYS objects, never plain strings.
				// Valid formats: {"hex":"#1E293B"}, {"raw":"var(--primary)"}, {"rgb":"rgba(30,41,59,0.8)"}
				return [
					'type'        => 'object',
					'description' => 'Bricks color object. Use ONE key: {"hex":"#value"} for hex colors, {"raw":"var(--var)"} for CSS vars/keywords, or {"rgb":"rgba(...)"} for rgba.',
					'properties'  => [
						'hex' => [ 'type' => 'string', 'pattern' => '^#[0-9a-fA-F]{3,8}$' ],
						'raw' => [ 'type' => 'string', 'description' => 'CSS variable or raw CSS color (e.g. "var(--primary)", "transparent")' ],
						'rgb' => [ 'type' => 'string', 'description' => 'RGBA string (e.g. "rgba(30, 41, 59, 0.8)")' ],
					],
				];

			case 'dimensions':
				// All directional values are CSS unit strings (e.g. "20px", "var(--spacing)", "50%").
				// NOT integers. Bricks does NOT auto-append "px" when values are set programmatically.
				return [
					'type'        => 'object',
					'description' => 'All values are CSS unit strings (e.g. "20px", "var(--s)", "50%"). NOT integers.',
					'properties' => [
						'top'    => [ 'type' => 'string' ],
						'right'  => [ 'type' => 'string' ],
						'bottom' => [ 'type' => 'string' ],
						'left'   => [ 'type' => 'string' ],
					],
				];

			case 'typography':
				return [
					'type'        => 'object',
					'properties'  => [
						'font-family'     => [ 'type' => 'string' ],
						'font-size'       => [ 'type' => 'string', 'description' => 'CSS unit (e.g. 16px, 1.5rem)' ],
						'font-weight'     => [ 'type' => [ 'string', 'number' ], 'description' => '700 or bold' ],
						'line-height'     => [ 'type' => 'string' ],
						'letter-spacing'  => [ 'type' => 'string' ],
						'text-transform'  => [ 'type' => 'string', 'enum' => [ 'none', 'uppercase', 'lowercase', 'capitalize' ] ],
						'text-decoration' => [ 'type' => 'string' ],
						'text-align'      => [ 'type' => 'string', 'enum' => [ 'left', 'center', 'right', 'justify' ] ],
						'font-style'      => [ 'type' => 'string', 'enum' => [ 'normal', 'italic', 'oblique' ] ],
						'color'           => [
							'type'        => 'object',
							'properties'  => [ 'hex' => ['type'=>'string'], 'raw' => ['type'=>'string'], 'rgb' => ['type'=>'string'] ],
							'description' => 'Text color object. Use {"hex":"#value"} or {"raw":"var(--color)"}',
						],
					],
					'description' => 'Typography. color must be color object, not plain string.',
				];

			case 'image':
				return [
					'type'       => 'object',
					'properties' => [
						'id'  => [ 'type' => 'integer' ],
						'url' => [ 'type' => 'string' ],
					],
				];

			case 'gallery':
				return [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'  => [ 'type' => 'integer' ],
							'url' => [ 'type' => 'string' ],
						],
					],
				];

			case 'link':
				return [
					'type'       => 'object',
					'properties' => [
						'url'      => [ 'type' => 'string' ],
						'type'     => [ 'type' => 'string' ],
						'newTab'   => [ 'type' => 'boolean' ],
						'nofollow' => [ 'type' => 'boolean' ],
					],
				];

			case 'icon':
				return [
					'type'       => 'object',
					'properties' => [
						'library' => [ 'type' => 'string' ],
						'icon'    => [ 'type' => 'string' ],
						'svg'     => [
							'type'       => 'object',
							'properties' => [
								'id'  => [ 'type' => 'integer' ],
								'url' => [ 'type' => 'string' ],
							],
						],
					],
				];

			case 'repeater':
				// Recurse into repeater fields.
				$items_props = [];
				if ( ! empty( $control['fields'] ) && is_array( $control['fields'] ) ) {
					foreach ( $control['fields'] as $field_key => $field ) {
						if ( is_array( $field ) ) {
							$items_props[ $field_key ] = $this->map_control_type_to_schema( $field );
						}
					}
				}

				return [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'properties'           => empty( $items_props ) ? new \stdClass() : $items_props,
						'additionalProperties' => true,
					],
				];

			case 'background':
				// color MUST be a color object: {"hex":"#value"} or {"raw":"var(--var)"} — never a plain string.
				return [
					'type'        => 'object',
					'description' => 'Background settings. color must be color object {hex/raw/rgb}, not a plain string.',
					'properties' => [
						'color'      => [
							'type'        => 'object',
							'description' => 'Color object. Use {"hex":"#value"} or {"raw":"var(--var)"}. Never a plain string.',
							'properties'  => [
								'hex' => [ 'type' => 'string' ],
								'raw' => [ 'type' => 'string' ],
								'rgb' => [ 'type' => 'string' ],
							],
						],
						'image'      => [
							'type'       => 'object',
							'properties' => [
								'id'   => [ 'type' => 'integer' ],
								'url'  => [ 'type' => 'string' ],
								'size' => [ 'type' => 'string', 'description' => 'Image size slug (e.g. "full", "large")' ],
							],
						],
						'size'       => [ 'type' => 'string', 'description' => 'CSS background-size (e.g. "cover", "contain")' ],
						'position'   => [ 'type' => 'string', 'description' => 'CSS background-position (e.g. "center center")' ],
						'repeat'     => [ 'type' => 'string', 'description' => 'CSS background-repeat (e.g. "no-repeat")' ],
						'attachment' => [ 'type' => 'string', 'description' => '"scroll" or "fixed"' ],
					],
				];

			case 'border':
				// width/radius: CSS unit STRINGS ("4px", "50%", "var(--r)") or per-side objects.
				// color: color OBJECT {hex/raw/rgb}, never a plain string.
				return [
					'type'        => 'object',
					'description' => 'Border settings. width/radius are CSS strings or per-side objects. color is a color object.',
					'properties' => [
						'width'  => [
							'description' => 'CSS string ("1px", "var(--border)") or per-side {top,right,bottom,left} object. NOT an integer.',
							'oneOf' => [
								[ 'type' => 'string' ],
								[ 'type' => 'object', 'properties' => [ 'top' => ['type'=>'string'], 'right' => ['type'=>'string'], 'bottom' => ['type'=>'string'], 'left' => ['type'=>'string'] ] ],
							],
						],
						'style'  => [ 'type' => 'string', 'enum' => ['none','solid','dashed','dotted','double','groove','ridge','inset','outset'] ],
						'color'  => [
							'type'        => 'object',
							'description' => 'Color object. {"hex":"#value"} or {"raw":"var(--var)"}. Never a plain string.',
							'properties'  => [ 'hex' => ['type'=>'string'], 'raw' => ['type'=>'string'], 'rgb' => ['type'=>'string'] ],
						],
						'radius' => [
							'description' => 'CSS string ("12px", "50%", "var(--r)") or per-corner {top,right,bottom,left} object. NOT an integer.',
							'oneOf' => [
								[ 'type' => 'string' ],
								[ 'type' => 'object', 'properties' => [ 'top' => ['type'=>'string'], 'right' => ['type'=>'string'], 'bottom' => ['type'=>'string'], 'left' => ['type'=>'string'] ] ],
							],
						],
					],
				];

			case 'box-shadow':
				return [
					'type'       => 'object',
					'properties' => [
						'offsetX' => [ 'type' => 'string' ],
						'offsetY' => [ 'type' => 'string' ],
						'blur'    => [ 'type' => 'string' ],
						'spread'  => [ 'type' => 'string' ],
						'color'   => [
							'type'        => 'object',
							'description' => 'Color object {hex/raw/rgb}. Never a plain string.',
							'properties'  => [ 'hex' => ['type'=>'string'], 'raw' => ['type'=>'string'], 'rgb' => ['type'=>'string'] ],
						],
					],
				];

			default:
				// Fallback: accept any string or object.
				return [
					'oneOf' => [
						[ 'type' => 'string' ],
						[ 'type' => 'number' ],
						[ 'type' => 'boolean' ],
						[ 'type' => 'object' ],
						[ 'type' => 'array' ],
					],
				];
		}
	}

	/**
	 * Get an element label from the element object.
	 *
	 * @param object $element The Bricks element object.
	 * @return string Element label.
	 */
	private function get_element_label( object $element ): string {
		if ( isset( $element->label ) && is_string( $element->label ) ) {
			return $element->label;
		}
		if ( method_exists( $element, 'get_label' ) ) {
			return (string) $element->get_label();
		}
		return '';
	}

	/**
	 * Get an element category from the element object.
	 *
	 * @param object $element The Bricks element object.
	 * @return string Element category slug.
	 */
	private function get_element_category( object $element ): string {
		if ( isset( $element->category ) && is_string( $element->category ) ) {
			return $element->category;
		}
		return 'general';
	}

	/**
	 * Instantiate a Bricks element object from a class name or object.
	 *
	 * @param string|object $element_class Class name or existing object.
	 * @return object|null Instantiated element or null on failure.
	 */
	private function get_element_object( string|object $element_class ): ?object {
		if ( is_object( $element_class ) ) {
			return $element_class;
		}

		if ( ! is_string( $element_class ) || ! class_exists( $element_class ) ) {
			return null;
		}

		try {
			return new $element_class( [] );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Find element names similar to the requested name.
	 *
	 * Uses similar_text() to find closest matches.
	 *
	 * @param string        $requested     The requested element name.
	 * @param array<string> $element_names All available element names.
	 * @return array<string> List of similar element names (max 5).
	 */
	private function find_similar_element_names( string $requested, array $element_names ): array {
		$similar = [];

		foreach ( $element_names as $name ) {
			similar_text( $requested, $name, $percent );
			if ( $percent > 40 ) {
				$similar[ $name ] = $percent;
			}
		}

		// Sort by similarity descending.
		arsort( $similar );

		return array_slice( array_keys( $similar ), 0, 5 );
	}

	/**
	 * Get a type-appropriate empty value for a control type.
	 *
	 * Used when generating minimal examples for required fields.
	 *
	 * @param string $type The control type.
	 * @return mixed Type-appropriate empty value.
	 */
	private function get_empty_value_for_type( string $type ): mixed {
		return match ( $type ) {
			'number'                     => 0,
			'checkbox', 'toggle'         => false,
			'color'                      => [ 'hex' => '#000000' ],
			'repeater', 'gallery'        => [],
			'image', 'link', 'icon',
			'dimensions', 'typography',
			'background', 'border',
			'box-shadow'                 => [],
			default                      => '',
		};
	}
}
