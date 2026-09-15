<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 8, item 29: video editor meta box — structured fields only, shared
 * between `atlas_recipe` and `post` (Magazín) since both use the exact same
 * `atlas_video_*` meta contract (class-register-meta.php). Standalone class
 * (not Atlas_Chuti_Meta_Box_Base — same reasoning as class-magazine-meta-box.php
 * and class-ad-campaign-meta-box.php: this isn't part of the importer's
 * shared Meta_Fields catalog).
 */
class Atlas_Chuti_Video_Meta_Box {

	const POST_TYPES = array( 'atlas_recipe', 'post' );

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		foreach ( self::POST_TYPES as $post_type ) {
			add_action( 'save_post_' . $post_type, array( $this, 'save' ), 10, 2 );
		}
	}

	public function add_meta_box() {
		foreach ( self::POST_TYPES as $post_type ) {
			add_meta_box( 'atlas_video_details', __( 'Atlas chutí – video', 'atlas-chuti' ), array( $this, 'render' ), $post_type, 'normal', 'default' );
		}
	}

	public function render( $post ) {
		wp_nonce_field( 'atlas_save_video', 'atlas_video_nonce' );
		$type    = get_post_meta( $post->ID, 'atlas_video_type', true ) ?: Atlas_Chuti_Video::TYPE_NONE;
		$url     = get_post_meta( $post->ID, 'atlas_video_url', true );
		$title   = get_post_meta( $post->ID, 'atlas_video_title', true );
		$channel = get_post_meta( $post->ID, 'atlas_video_channel', true );
		$lang    = get_post_meta( $post->ID, 'atlas_video_language', true );

		echo '<div class="atlas-meta-box">';
		echo '<div class="atlas-field"><label for="atlas_video_type">' . esc_html__( 'Typ videa', 'atlas-chuti' ) . '</label>';
		echo '<select id="atlas_video_type" name="atlas_video_meta[type]">';
		foreach (
			array(
				Atlas_Chuti_Video::TYPE_NONE    => __( 'Žádné', 'atlas-chuti' ),
				Atlas_Chuti_Video::TYPE_YOUTUBE => __( 'YouTube (ručně vložená URL)', 'atlas-chuti' ),
				Atlas_Chuti_Video::TYPE_OWN     => __( 'Vlastní video (přímá URL k souboru)', 'atlas-chuti' ),
			) as $val => $lbl
		) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $val ), selected( $type, $val, false ), esc_html( $lbl ) );
		}
		echo '</select></div>';

		printf(
			'<div class="atlas-field"><label for="atlas_video_url">%1$s</label><input type="url" id="atlas_video_url" name="atlas_video_meta[url]" value="%2$s" style="width:100%%;" placeholder="https://"><p class="description">%3$s</p></div>',
			esc_html__( 'URL videa', 'atlas-chuti' ),
			esc_attr( $url ),
			esc_html__( 'YouTube: plná URL (youtube.com/watch?v=... nebo youtu.be/...). Vlastní: přímá URL k video souboru.', 'atlas-chuti' )
		);
		printf(
			'<div class="atlas-field"><label for="atlas_video_title">%1$s</label><input type="text" id="atlas_video_title" name="atlas_video_meta[title]" value="%2$s" style="width:100%%;"></div>',
			esc_html__( 'Název videa', 'atlas-chuti' ),
			esc_attr( $title )
		);
		printf(
			'<div class="atlas-field"><label for="atlas_video_channel">%1$s</label><input type="text" id="atlas_video_channel" name="atlas_video_meta[channel]" value="%2$s"></div>',
			esc_html__( 'Kanál / autor (nepovinné)', 'atlas-chuti' ),
			esc_attr( $channel )
		);
		printf(
			'<div class="atlas-field"><label for="atlas_video_language">%1$s</label><input type="text" id="atlas_video_language" name="atlas_video_meta[language]" value="%2$s" placeholder="cs, en..."></div>',
			esc_html__( 'Jazyk videa (nepovinné)', 'atlas-chuti' ),
			esc_attr( $lang )
		);
		echo '<p class="description">' . esc_html__( 'Žádné automatické vyhledávání ani stahování videí — vkládejte pouze ručně ověřené URL.', 'atlas-chuti' ) . '</p>';
		echo '</div>';
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['atlas_video_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_video_nonce'], 'atlas_save_video' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$posted = isset( $_POST['atlas_video_meta'] ) ? wp_unslash( $_POST['atlas_video_meta'] ) : array();

		$type = isset( $posted['type'] ) ? sanitize_key( $posted['type'] ) : Atlas_Chuti_Video::TYPE_NONE;
		if ( ! in_array( $type, array( Atlas_Chuti_Video::TYPE_NONE, Atlas_Chuti_Video::TYPE_YOUTUBE, Atlas_Chuti_Video::TYPE_OWN ), true ) ) {
			$type = Atlas_Chuti_Video::TYPE_NONE;
		}
		update_post_meta( $post_id, 'atlas_video_type', $type );
		update_post_meta( $post_id, 'atlas_video_url', isset( $posted['url'] ) ? esc_url_raw( $posted['url'] ) : '' );
		update_post_meta( $post_id, 'atlas_video_title', isset( $posted['title'] ) ? sanitize_text_field( $posted['title'] ) : '' );
		update_post_meta( $post_id, 'atlas_video_channel', isset( $posted['channel'] ) ? sanitize_text_field( $posted['channel'] ) : '' );
		update_post_meta( $post_id, 'atlas_video_language', isset( $posted['language'] ) ? sanitize_text_field( $posted['language'] ) : '' );
	}
}
