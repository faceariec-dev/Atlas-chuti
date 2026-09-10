<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Country meta box. The continent is assigned via the default `atlas_continent`
 * taxonomy box WordPress renders automatically; everything else is generic fields.
 */
class Atlas_Chuti_Country_Meta_Box extends Atlas_Chuti_Meta_Box_Base {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	public function post_type() {
		return 'atlas_country';
	}
	public function box_id() {
		return 'atlas_country_details';
	}
	public function box_title() {
		return __( 'Atlas chutí – detaily země', 'atlas-chuti' );
	}

	protected function render_extra_bottom( $post ) {
		echo '<fieldset class="atlas-fieldset"><legend>' . esc_html__( 'Domovská stránka', 'atlas-chuti' ) . '</legend>';
		printf(
			'<label><input type="checkbox" name="atlas_featured_today" value="1" %1$s> %2$s</label><br>',
			checked( get_post_meta( $post->ID, 'atlas_featured_today', true ), '1', false ),
			esc_html__( 'Zobrazit v sekci „Dnes ochutnejte“', 'atlas-chuti' )
		);
		printf(
			'<label><input type="checkbox" name="atlas_featured_cuisine" value="1" %1$s> %2$s</label>',
			checked( get_post_meta( $post->ID, 'atlas_featured_cuisine', true ), '1', false ),
			esc_html__( 'Zobrazit v sekci „Oblíbené kuchyně“', 'atlas-chuti' )
		);
		echo '<p class="description">' . esc_html__( 'Pokud nic nevyberete, homepage automaticky zvolí rozumné výchozí položky.', 'atlas-chuti' ) . '</p>';
		echo '</fieldset>';
	}

	protected function save_extra( $post_id ) {
		update_post_meta( $post_id, 'atlas_featured_today', ! empty( $_POST['atlas_featured_today'] ) ? '1' : '' );
		update_post_meta( $post_id, 'atlas_featured_cuisine', ! empty( $_POST['atlas_featured_cuisine'] ) ? '1' : '' );
	}
}
