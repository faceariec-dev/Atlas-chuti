<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 6, item 7-8: the Magazín's cross-linking meta box — comma-separated stable
 * keys (recipe_key / ISO / glossary translation_group), never post IDs, so a
 * relation survives even if a post gets re-imported or re-translated. Deliberately
 * a standalone meta box (not Atlas_Chuti_Meta_Box_Base) because that base class's
 * field catalog (Atlas_Chuti_Meta_Fields) is the importer's own shared contract for
 * atlas_recipe/atlas_country/atlas_glossary/atlas_ingredient — extending it to cover
 * `post` risks touching that contract for post types this box has nothing to do
 * with. The 3 meta keys themselves are already registered in
 * class-register-meta.php (register_magazine_relation_fields()).
 */
class Atlas_Chuti_Magazine_Meta_Box {

	const FIELDS = array(
		'atlas_related_recipe_keys'   => array(
			'label'       => 'Související recepty (recipe_key, oddělené čárkou)',
			'placeholder' => 'napr. spaghetti_carbonara, gulas',
		),
		'atlas_related_country_iso'   => array(
			'label'       => 'Související země (ISO kód, oddělené čárkou)',
			'placeholder' => 'napr. IT, CZ',
		),
		'atlas_related_glossary_keys' => array(
			'label'       => 'Související pojmy ve slovníčku (klíč, oddělené čárkou)',
			'placeholder' => 'napr. sous-vide',
		),
	);

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function hooks() {
		add_action( 'add_meta_boxes_post', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_post', array( $this, 'save' ), 10, 2 );
	}

	public function add_meta_box() {
		add_meta_box(
			'atlas_magazine_relations',
			__( 'Atlas chutí – související obsah', 'atlas-chuti' ),
			array( $this, 'render' ),
			'post',
			'normal',
			'high'
		);
	}

	public function render( $post ) {
		wp_nonce_field( 'atlas_save_magazine_relations', 'atlas_magazine_relations_nonce' );
		echo '<div class="atlas-meta-box">';
		foreach ( self::FIELDS as $meta_key => $field ) {
			$value = get_post_meta( $post->ID, $meta_key, true );
			$value = is_array( $value ) ? implode( ', ', $value ) : '';
			$id    = 'atlas_field_' . $meta_key;
			echo '<div class="atlas-field">';
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label>';
			printf(
				'<input type="text" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s" style="width:100%%;">',
				esc_attr( $id ),
				esc_attr( 'atlas_magazine_meta[' . $meta_key . ']' ),
				esc_attr( $value ),
				esc_attr( $field['placeholder'] )
			);
			echo '</div>';
		}
		echo '<p class="description">' . esc_html__( 'Vyplňte pouze existující stabilní klíče. Neplatné/neexistující klíče se na detailu článku prostě nezobrazí – nikdy nevytvoří rozbitý odkaz.', 'atlas-chuti' ) . '</p>';
		echo '</div>';
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['atlas_magazine_relations_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_magazine_relations_nonce'], 'atlas_save_magazine_relations' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$posted = isset( $_POST['atlas_magazine_meta'] ) ? wp_unslash( $_POST['atlas_magazine_meta'] ) : array();

		foreach ( self::FIELDS as $meta_key => $field ) {
			$raw   = isset( $posted[ $meta_key ] ) ? (string) $posted[ $meta_key ] : '';
			$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
			if ( 'atlas_related_country_iso' === $meta_key ) {
				$parts = array_map( 'strtoupper', $parts );
			}
			$parts = array_values( array_unique( array_map( 'sanitize_text_field', $parts ) ) );
			update_post_meta( $post_id, $meta_key, $parts );
		}
	}
}
