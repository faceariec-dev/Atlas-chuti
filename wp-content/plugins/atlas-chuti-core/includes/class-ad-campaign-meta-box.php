<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 7, item 17: campaign editor meta box — structured fields only (slot
 * checkboxes against the closed Atlas_Chuti_Ad_Slots registry, a locale select,
 * start/end datetimes, click URL, accessible alt text), never a raw HTML/JS
 * snippet field (item 4: "žádný arbitrary PHP/eval/raw executable code").
 * Standalone class (not Atlas_Chuti_Meta_Box_Base — that base is the importer's
 * own shared Meta_Fields contract for atlas_recipe/country/glossary/ingredient,
 * unrelated to this CPT), same pattern as class-magazine-meta-box.php.
 */
class Atlas_Chuti_Ad_Campaign_Meta_Box {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function hooks() {
		add_action( 'add_meta_boxes_' . Atlas_Chuti_Ad_Campaign::POST_TYPE, array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . Atlas_Chuti_Ad_Campaign::POST_TYPE, array( $this, 'save' ), 10, 2 );
	}

	public function add_meta_box() {
		add_meta_box(
			'atlas_ad_campaign_details',
			__( 'Atlas chutí – detaily kampaně', 'atlas-chuti' ),
			array( $this, 'render' ),
			Atlas_Chuti_Ad_Campaign::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function render( $post ) {
		wp_nonce_field( 'atlas_save_ad_campaign', 'atlas_ad_campaign_nonce' );
		$slots        = (array) get_post_meta( $post->ID, 'atlas_ad_slots', true );
		$locale       = get_post_meta( $post->ID, 'atlas_ad_locale', true ) ?: 'all';
		$start        = get_post_meta( $post->ID, 'atlas_ad_start', true );
		$end          = get_post_meta( $post->ID, 'atlas_ad_end', true );
		$click_url    = get_post_meta( $post->ID, 'atlas_ad_click_url', true );
		$alt_text     = get_post_meta( $post->ID, 'atlas_ad_alt_text', true );

		echo '<div class="atlas-meta-box">';

		echo '<div class="atlas-field"><label>' . esc_html__( 'Cílové sloty', 'atlas-chuti' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Kampaň se zobrazí jen ve slotech, které jsou i v administraci Reklamy zapnuté a nastavené na zdroj "Přímá kampaň".', 'atlas-chuti' ) . '</p>';
		foreach ( Atlas_Chuti_Ad_Slots::keys() as $key ) {
			printf(
				'<label style="display:block;margin:4px 0;"><input type="checkbox" name="atlas_ad_meta[slots][]" value="%1$s" %2$s> %3$s <code>%1$s</code></label>',
				esc_attr( $key ),
				checked( in_array( $key, $slots, true ), true, false ),
				esc_html( Atlas_Chuti_Ad_Slots::label( $key ) )
			);
		}
		echo '</div>';

		echo '<div class="atlas-field"><label for="atlas_ad_locale">' . esc_html__( 'Jazykové cílení', 'atlas-chuti' ) . '</label>';
		echo '<select id="atlas_ad_locale" name="atlas_ad_meta[locale]">';
		foreach ( array( 'all' => __( 'Vše (CZ + EN)', 'atlas-chuti' ), 'cs' => 'CZ', 'en' => 'EN' ) as $val => $lbl ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $val ), selected( $locale, $val, false ), esc_html( $lbl ) );
		}
		echo '</select></div>';

		printf(
			'<div class="atlas-field"><label for="atlas_ad_start">%1$s</label><input type="datetime-local" id="atlas_ad_start" name="atlas_ad_meta[start]" value="%2$s"></div>',
			esc_html__( 'Začátek (nepovinné)', 'atlas-chuti' ),
			esc_attr( $start )
		);
		printf(
			'<div class="atlas-field"><label for="atlas_ad_end">%1$s</label><input type="datetime-local" id="atlas_ad_end" name="atlas_ad_meta[end]" value="%2$s"></div>',
			esc_html__( 'Konec (nepovinné)', 'atlas-chuti' ),
			esc_attr( $end )
		);
		printf(
			'<div class="atlas-field"><label for="atlas_ad_click_url">%1$s</label><input type="url" id="atlas_ad_click_url" name="atlas_ad_meta[click_url]" value="%2$s" style="width:100%%;" placeholder="https://"></div>',
			esc_html__( 'Cílová URL (click-through)', 'atlas-chuti' ),
			esc_attr( $click_url )
		);
		printf(
			'<div class="atlas-field"><label for="atlas_ad_alt_text">%1$s</label><input type="text" id="atlas_ad_alt_text" name="atlas_ad_meta[alt_text]" value="%2$s" style="width:100%%;"><p class="description">%3$s</p></div>',
			esc_html__( 'Accessible popisek (alt text)', 'atlas-chuti' ),
			esc_attr( $alt_text ),
			esc_html__( 'Povinné, pokud kreativa (obrázek) nese vlastní význam, ne jen dekorativní pozadí.', 'atlas-chuti' )
		);

		echo '<p class="description">' . esc_html__( 'Kreativa = vystavený obrázek příspěvku (featured image).', 'atlas-chuti' ) . '</p>';

		echo '</div>';
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['atlas_ad_campaign_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_ad_campaign_nonce'], 'atlas_save_ad_campaign' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$posted = isset( $_POST['atlas_ad_meta'] ) ? wp_unslash( $_POST['atlas_ad_meta'] ) : array();

		$slots = isset( $posted['slots'] ) ? array_map( 'sanitize_key', (array) $posted['slots'] ) : array();
		$slots = array_values( array_filter( $slots, array( 'Atlas_Chuti_Ad_Slots', 'exists' ) ) );
		update_post_meta( $post_id, 'atlas_ad_slots', $slots );

		$locale = isset( $posted['locale'] ) ? sanitize_key( $posted['locale'] ) : 'all';
		update_post_meta( $post_id, 'atlas_ad_locale', in_array( $locale, array( 'all', 'cs', 'en' ), true ) ? $locale : 'all' );

		foreach ( array( 'start', 'end' ) as $field ) {
			$value = isset( $posted[ $field ] ) ? sanitize_text_field( $posted[ $field ] ) : '';
			// datetime-local posts "YYYY-MM-DDTHH:MM" — normalize to a real, safely
			// parseable datetime string, or store nothing if the input was empty/junk.
			update_post_meta( $post_id, 'atlas_ad_' . $field, $value && strtotime( $value ) ? $value : '' );
		}

		$click_url = isset( $posted['click_url'] ) ? esc_url_raw( $posted['click_url'] ) : '';
		update_post_meta( $post_id, 'atlas_ad_click_url', $click_url );

		$alt_text = isset( $posted['alt_text'] ) ? sanitize_text_field( $posted['alt_text'] ) : '';
		update_post_meta( $post_id, 'atlas_ad_alt_text', $alt_text );
	}
}
