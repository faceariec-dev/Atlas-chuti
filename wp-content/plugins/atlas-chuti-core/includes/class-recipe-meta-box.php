<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recipe meta box. Meal type / difficulty / diet get WordPress's default taxonomy
 * boxes automatically; only the country picker needs custom UI because
 * `atlas_country_tax` is hidden from the default UI (it's synced from the Země CPT,
 * see class-country-sync.php).
 */
class Atlas_Chuti_Recipe_Meta_Box extends Atlas_Chuti_Meta_Box_Base {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	public function post_type() {
		return 'atlas_recipe';
	}
	public function box_id() {
		return 'atlas_recipe_details';
	}
	public function box_title() {
		return __( 'Atlas chutí – detaily receptu', 'atlas-chuti' );
	}

	protected function render_extra_top( $post ) {
		$countries = get_posts(
			array(
				'post_type'      => 'atlas_country',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => array( 'publish', 'draft' ),
			)
		);

		$primary_term_id = (int) get_post_meta( $post->ID, '_atlas_recipe_primary_country_term_id', true );
		$current_terms   = wp_get_post_terms( $post->ID, 'atlas_country_tax', array( 'fields' => 'ids' ) );

		echo '<fieldset class="atlas-fieldset"><legend>' . esc_html__( 'Země / kuchyně', 'atlas-chuti' ) . '</legend>';
		echo '<div class="atlas-field"><label>' . esc_html__( 'Hlavní země', 'atlas-chuti' ) . ' *</label><select name="atlas_country_primary">';
		echo '<option value="">—</option>';
		foreach ( $countries as $c ) {
			$term_id = Atlas_Chuti_Country_Sync::get_term_id_for_country_post( $c->ID );
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				$term_id,
				selected( $term_id, $primary_term_id, false ),
				esc_html( $c->post_title )
			);
		}
		echo '</select></div>';

		echo '<div class="atlas-field"><label>' . esc_html__( 'Další související země', 'atlas-chuti' ) . '</label><div>';
		foreach ( $countries as $c ) {
			$term_id = Atlas_Chuti_Country_Sync::get_term_id_for_country_post( $c->ID );
			if ( $term_id === $primary_term_id ) {
				continue;
			}
			printf(
				'<label style="display:inline-block;margin-right:14px;"><input type="checkbox" name="atlas_country_related[]" value="%1$d" %2$s> %3$s</label>',
				$term_id,
				checked( in_array( $term_id, $current_terms, true ), true, false ),
				esc_html( $c->post_title )
			);
		}
		echo '</div></div>';
		echo '</fieldset>';

		echo '<fieldset class="atlas-fieldset"><legend>' . esc_html__( 'Domovská stránka', 'atlas-chuti' ) . '</legend>';
		printf(
			'<label><input type="checkbox" name="atlas_featured_cook_today" value="1" %1$s> %2$s</label>',
			checked( get_post_meta( $post->ID, 'atlas_featured_cook_today', true ), '1', false ),
			esc_html__( 'Zobrazit v sekci „Co dnes uvařit?“', 'atlas-chuti' )
		);
		echo '<p class="description">' . esc_html__( 'Pokud nic nevyberete, homepage automaticky zvolí recept.', 'atlas-chuti' ) . '</p>';
		echo '</fieldset>';
	}

	protected function save_extra( $post_id ) {
		update_post_meta( $post_id, 'atlas_featured_cook_today', ! empty( $_POST['atlas_featured_cook_today'] ) ? '1' : '' );

		$primary = isset( $_POST['atlas_country_primary'] ) ? absint( $_POST['atlas_country_primary'] ) : 0;
		$related = isset( $_POST['atlas_country_related'] ) ? array_map( 'absint', (array) $_POST['atlas_country_related'] ) : array();

		$all_terms = $primary ? array_unique( array_merge( array( $primary ), $related ) ) : $related;
		wp_set_post_terms( $post_id, $all_terms, 'atlas_country_tax', false );

		if ( $primary ) {
			update_post_meta( $post_id, '_atlas_recipe_primary_country_term_id', $primary );
		} else {
			delete_post_meta( $post_id, '_atlas_recipe_primary_country_term_id' );
		}

		// Auto-fill "celkový čas" when the editor left it blank.
		$total = get_post_meta( $post_id, 'atlas_total_minutes', true );
		if ( '' === $total || 0 === (int) $total ) {
			$prep = (int) get_post_meta( $post_id, 'atlas_prep_minutes', true );
			$cook = (int) get_post_meta( $post_id, 'atlas_cook_minutes', true );
			update_post_meta( $post_id, 'atlas_total_minutes', $prep + $cook );
		}
	}
}
