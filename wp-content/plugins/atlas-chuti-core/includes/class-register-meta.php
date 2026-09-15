<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central register_post_meta() for every field in Atlas_Chuti_Meta_Fields, plus the
 * language-readiness fields (item 3 of this phase's brief). One place, one contract:
 * the JSON importer, the admin meta boxes and a future OpenAI module all read/write
 * the exact same registered meta keys — nothing here is importer-specific.
 *
 * show_in_rest is enabled everywhere so the REST API (used by wp-admin's own screens,
 * and later by any AI integration) sees the real data shape instead of opaque values.
 * The data itself is not otherwise made public through this — capability checks for
 * editing are unchanged (auth_callback still requires edit_posts).
 */
class Atlas_Chuti_Register_Meta {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register' ), 20 );
	}

	public function register() {
		$this->register_fields_for( 'atlas_recipe', Atlas_Chuti_Meta_Fields::recipe_fields() );
		$this->register_fields_for( 'atlas_country', Atlas_Chuti_Meta_Fields::country_fields() );
		$this->register_fields_for( 'atlas_glossary', Atlas_Chuti_Meta_Fields::glossary_fields() );
		$this->register_fields_for( 'atlas_ingredient', Atlas_Chuti_Meta_Fields::ingredient_fields() );

		// KROK 6: 'post' (Magazín) joins the same locale/translation contract 'post'
		// already gets automatically from Atlas_Chuti_I18N::LOCALIZED_POST_TYPES
		// (query scoping + atlas_locale/translation_group backfill, since Krok 4) —
		// this was the one place that backfill wasn't matched by a formal
		// register_post_meta() call, so REST/Gutenberg never saw the fields. 'atlas_topic'
		// (Diskuze) gets the same contract for the same reason: a CZ and an EN topic
		// are just two independent posts, never "the same topic" — but the plain
		// locale tag + query scoping this shared registration provides is exactly
		// what item 18 ("Topic má locale") needs.
		foreach ( array( 'atlas_recipe', 'atlas_country', 'atlas_glossary', 'post', 'atlas_topic' ) as $post_type ) {
			$this->register_i18n_fields( $post_type );
		}

		$this->register_magazine_relation_fields();
		$this->register_video_fields();

		// KROK 4: recipe_key is atlas_recipe's OWN stable dish-concept identity —
		// distinct from atlas_translation_group (registered above via
		// register_i18n_fields(), now a separate, optional field — see
		// class-json-importer.php's import_recipe()). Recipe-only, unlike the fields
		// register_i18n_fields() shares across atlas_recipe/atlas_country/atlas_glossary.
		register_post_meta(
			'atlas_recipe',
			'atlas_recipe_key',
			array(
				'type'              => 'string',
				'description'       => 'Stable dish-concept identity shared by every locale of this recipe (e.g. "spaghetti_carbonara").',
				'single'            => true,
				'sanitize_callback' => 'sanitize_title',
				'auth_callback'     => array( $this, 'auth_edit_posts' ),
				'show_in_rest'      => true,
			)
		);

		// Internal linkage fields the importer/meta boxes write directly (not part of
		// the field registry because they're relationships, not authored content).
		register_post_meta(
			'atlas_recipe',
			'_atlas_recipe_primary_country_term_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( $this, 'auth_edit_posts' ),
				'show_in_rest'      => false,
			)
		);
		register_post_meta(
			'atlas_country',
			'_atlas_country_term_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( $this, 'auth_edit_posts' ),
				'show_in_rest'      => false,
			)
		);
	}

	public function auth_edit_posts() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * KROK 6, item 7: minimal custom meta for the Magazín — three stable-key
	 * relation lists, never post IDs (item 8's own instruction: recipe/country/
	 * glossary crosslinks resolve through Atlas_Chuti_I18N::find_by_recipe_key()/
	 * find_country_by_iso()/find_by_translation_group(), exactly like every other
	 * cross-entity relation in this codebase since Krok 3/4).
	 */
	private function register_magazine_relation_fields() {
		foreach (
			array(
				'atlas_related_recipe_keys'   => 'Related recipe_key values (stable dish-concept identity), not post IDs.',
				'atlas_related_country_iso'   => 'Related ISO 3166-1 country codes, not post IDs.',
				'atlas_related_glossary_keys' => 'Related glossary translation_group keys, not post IDs.',
			) as $meta_key => $description
		) {
			register_post_meta(
				'post',
				$meta_key,
				array(
					'type'         => 'array',
					'description'  => $description,
					'single'       => true,
					'auth_callback' => array( $this, 'auth_edit_posts' ),
					'show_in_rest' => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				)
			);
		}
	}

	/**
	 * KROK 8, item 29-31: recipe/article video model — `atlas_video_type` is a
	 * closed vocabulary (none|youtube|own, enforced in Atlas_Chuti_Video's own
	 * sanitize path, not here, since register_post_meta()'s sanitize_callback
	 * has no clean way to reject-vs-coerce an invalid enum value without
	 * silently corrupting it). No video exists until an editor sets one of
	 * these — never fabricated (item: "Video zobraz pouze pokud skutečně
	 * existuje").
	 */
	private function register_video_fields() {
		$fields = array(
			'atlas_video_type'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key', 'description' => 'none|youtube|own' ),
			'atlas_video_url'      => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
			'atlas_video_title'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'atlas_video_channel'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'atlas_video_language' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
		);
		foreach ( array( 'atlas_recipe', 'post' ) as $post_type ) {
			foreach ( $fields as $meta_key => $args ) {
				register_post_meta(
					$post_type,
					$meta_key,
					array(
						'type'              => $args['type'],
						'description'       => $args['description'] ?? '',
						'single'            => true,
						'sanitize_callback' => $args['sanitize_callback'],
						'auth_callback'     => array( $this, 'auth_edit_posts' ),
						'show_in_rest'      => true,
					)
				);
			}
		}
	}

	private function register_i18n_fields( $post_type ) {
		register_post_meta(
			$post_type,
			'atlas_locale',
			array(
				'type'              => 'string',
				'description'       => 'BCP 47 locale of this content, e.g. cs-CZ.',
				'single'            => true,
				'default'           => Atlas_Chuti_I18N::DEFAULT_LOCALE,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( $this, 'auth_edit_posts' ),
				'show_in_rest'      => true,
			)
		);
		register_post_meta(
			$post_type,
			'atlas_translation_group',
			array(
				'type'              => 'string',
				'description'       => 'Stable, language-independent identity shared by every locale of this content.',
				'single'            => true,
				'sanitize_callback' => 'sanitize_title',
				'auth_callback'     => array( $this, 'auth_edit_posts' ),
				'show_in_rest'      => true,
			)
		);
		register_post_meta(
			$post_type,
			'atlas_translation_status',
			array(
				'type'              => 'string',
				'description'       => 'none|draft|reviewed|published',
				'single'            => true,
				'default'           => 'published',
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => array( $this, 'auth_edit_posts' ),
				'show_in_rest'      => true,
			)
		);
	}

	private function register_fields_for( $post_type, $fields ) {
		foreach ( $fields as $key => $field ) {
			$meta_key = Atlas_Chuti_Meta_Fields::meta_key( $key );
			$args     = $this->args_for_field( $field );
			register_post_meta( $post_type, $meta_key, $args );
		}
	}

	/**
	 * Maps one Atlas_Chuti_Meta_Fields field definition to register_post_meta() args.
	 */
	private function args_for_field( $field ) {
		$common = array(
			'single'            => true,
			'auth_callback'     => array( $this, 'auth_edit_posts' ),
			'show_in_rest'      => true,
		);

		switch ( $field['type'] ) {
			case 'int':
				return $common + array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				);
			case 'url':
				return $common + array(
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				);
			case 'date':
				return $common + array(
					'type'              => 'string',
					'sanitize_callback' => function ( $value ) {
						return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ? $value : '';
					},
				);
			case 'richtext':
				return $common + array(
					'type'              => 'string',
					'sanitize_callback' => 'wp_kses_post',
				);
			case 'text':
			case 'textarea':
				return $common + array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				);
			case 'post_ref':
				return $common + array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				);
			case 'string_list':
				return $common + array(
					'type'         => 'array',
					'show_in_rest' => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				);
			case 'post_ref_list':
				return $common + array(
					'type'         => 'array',
					'show_in_rest' => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
				);
			case 'repeater':
				$shape      = $field['shape'] ?? array();
				$types      = $field['types'] ?? array();
				$properties = array();
				foreach ( $shape as $shape_key ) {
					$sub_type                 = 'bool' === ( $types[ $shape_key ] ?? '' ) ? 'boolean' : 'string';
					$properties[ $shape_key ] = array( 'type' => $sub_type );
				}
				return $common + array(
					'type'         => 'array',
					'show_in_rest' => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => $properties,
							),
						),
					),
				);
			default:
				return $common + array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' );
		}
	}
}
