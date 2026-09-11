<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared rendering/saving logic for the recipe/country/glossary/ingredient meta boxes.
 * Field definitions come from Atlas_Chuti_Meta_Fields so the admin UI, the JSON importer
 * and REST all agree on the same keys and types.
 */
abstract class Atlas_Chuti_Meta_Box_Base {

	abstract public function post_type();
	abstract public function box_id();
	abstract public function box_title();

	public function hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . $this->post_type(), array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( $hook ) {
		global $post_type;
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || $post_type !== $this->post_type() ) {
			return;
		}
		wp_enqueue_script( 'atlas-repeater', ATLAS_CHUTI_URL . 'admin/js/repeater.js', array(), ATLAS_CHUTI_VERSION, true );
		wp_localize_script(
			'atlas-repeater',
			'AtlasRepeaterL10n',
			array( 'remove' => __( '✕ odebrat', 'atlas-chuti' ) )
		);
		wp_enqueue_style( 'atlas-admin', ATLAS_CHUTI_URL . 'admin/css/admin.css', array(), ATLAS_CHUTI_VERSION );
	}

	public function add_meta_box() {
		add_meta_box( $this->box_id(), $this->box_title(), array( $this, 'render' ), $this->post_type(), 'normal', 'high' );
	}

	protected function fields() {
		return Atlas_Chuti_Meta_Fields::fields_for( $this->post_type() );
	}

	public function render( $post ) {
		wp_nonce_field( 'atlas_save_' . $this->post_type(), 'atlas_meta_nonce' );
		echo '<div class="atlas-meta-box">';
		$this->render_extra_top( $post );
		foreach ( $this->fields() as $key => $field ) {
			$this->render_field( $post, $key, $field );
		}
		$this->render_extra_bottom( $post );
		echo '</div>';
	}

	protected function render_extra_top( $post ) {}
	protected function render_extra_bottom( $post ) {}

	protected function render_field( $post, $key, $field ) {
		$meta_key = Atlas_Chuti_Meta_Fields::meta_key( $key );
		$value    = get_post_meta( $post->ID, $meta_key, true );
		$id       = 'atlas_field_' . $key;
		$name     = 'atlas_meta[' . $key . ']';

		echo '<div class="atlas-field">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . ( ! empty( $field['required'] ) ? ' *' : '' ) . '</label>';

		switch ( $field['type'] ) {
			case 'text':
				printf( '<input type="text" id="%1$s" name="%2$s" value="%3$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
				break;
			case 'int':
				printf( '<input type="number" id="%1$s" name="%2$s" value="%3$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
				break;
			case 'date':
				printf( '<input type="date" id="%1$s" name="%2$s" value="%3$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
				break;
			case 'textarea':
				printf( '<textarea id="%1$s" name="%2$s">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ) );
				break;
			case 'richtext':
				printf( '<textarea id="%1$s" name="%2$s" rows="6">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ) );
				echo '<p class="description">' . esc_html__( 'Základní HTML je povoleno (odstavce, tučné písmo, odkazy).', 'atlas-chuti' ) . '</p>';
				break;
			case 'string_list':
				$lines = is_array( $value ) ? implode( "\n", $value ) : '';
				printf( '<textarea id="%1$s" name="%2$s" rows="4">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $lines ) );
				echo '<p class="description">' . esc_html__( 'Jedna položka na řádek.', 'atlas-chuti' ) . '</p>';
				break;
			case 'post_ref':
				$this->render_post_ref_select( $id, $name, $field['ref_type'], (int) $value, false );
				break;
			case 'post_ref_list':
				$this->render_post_ref_select( $id, $name . '[]', $field['ref_type'], (array) $value, true );
				break;
			case 'repeater':
				$this->render_repeater( $id, $name, $field, (array) $value );
				break;
		}
		echo '</div>';
	}

	protected function render_post_ref_select( $id, $name, $ref_type, $selected, $multiple ) {
		$posts = get_posts(
			array(
				'post_type'      => $ref_type,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => array( 'publish', 'draft' ),
			)
		);
		printf(
			'<select id="%1$s" name="%2$s" %3$s style="min-height:38px;">',
			esc_attr( $id ),
			esc_attr( $name ),
			$multiple ? 'multiple size="5"' : ''
		);
		if ( ! $multiple ) {
			echo '<option value="">—</option>';
		}
		$selected_arr = $multiple ? array_map( 'intval', (array) $selected ) : array( (int) $selected );
		foreach ( $posts as $p ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				$p->ID,
				selected( in_array( (int) $p->ID, $selected_arr, true ), true, false ),
				esc_html( $p->post_title )
			);
		}
		echo '</select>';
	}

	protected function render_repeater( $id, $name, $field, $value ) {
		$shape  = $field['shape'];
		$labels = array_map(
			function ( $f ) {
				$map = array(
					'ingredient_key' => __( 'Klíč ingredience (volitelné, např. "tomato")', 'atlas-chuti' ),
					'display_name'   => __( 'Název', 'atlas-chuti' ),
					'quantity'       => __( 'Množství', 'atlas-chuti' ),
					'unit'           => __( 'Jednotka', 'atlas-chuti' ),
					'note'           => __( 'Poznámka', 'atlas-chuti' ),
					'group'          => __( 'Skupina', 'atlas-chuti' ),
					'scalable'       => __( 'Škálovatelné? (true/false, prázdné = automaticky)', 'atlas-chuti' ),
					'order'          => __( 'Pořadí', 'atlas-chuti' ),
					'text'           => __( 'Text', 'atlas-chuti' ),
					'recipe_id'      => __( 'Slug receptu (volitelné)', 'atlas-chuti' ),
				);
				return isset( $map[ $f ] ) ? $map[ $f ] : ucfirst( $f );
			},
			$shape
		);
		printf(
			'<div class="atlas-repeater" data-shape="%1$s" data-labels="%2$s">',
			esc_attr( implode( ',', $shape ) ),
			esc_attr( implode( '|', $labels ) )
		);
		echo '<div class="atlas-repeater-rows"></div>';
		printf(
			'<input type="hidden" class="atlas-repeater-value" name="%1$s" value="%2$s">',
			esc_attr( $name ),
			esc_attr( wp_json_encode( $value ) )
		);
		echo '<button type="button" class="button atlas-repeater-add">' . esc_html__( '+ Přidat řádek', 'atlas-chuti' ) . '</button>';
		echo '</div>';
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['atlas_meta_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_meta_nonce'], 'atlas_save_' . $this->post_type() ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$posted = isset( $_POST['atlas_meta'] ) ? wp_unslash( $_POST['atlas_meta'] ) : array();

		foreach ( $this->fields() as $key => $field ) {
			$meta_key = Atlas_Chuti_Meta_Fields::meta_key( $key );
			$raw      = isset( $posted[ $key ] ) ? $posted[ $key ] : ( 'repeater' === $field['type'] || 'string_list' === $field['type'] || 'post_ref_list' === $field['type'] ? array() : '' );

			if ( 'string_list' === $field['type'] && is_string( $raw ) ) {
				$raw = preg_split( '/\r\n|\r|\n/', $raw );
			}
			if ( 'repeater' === $field['type'] && is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				$raw     = is_array( $decoded ) ? $decoded : array();
			}

			$shape = isset( $field['shape'] ) ? $field['shape'] : array();
			$clean = Atlas_Chuti_Meta_Fields::sanitize( $field['type'], $raw, $shape );
			update_post_meta( $post_id, $meta_key, $clean );
		}

		$this->save_extra( $post_id );
	}

	protected function save_extra( $post_id ) {}
}
