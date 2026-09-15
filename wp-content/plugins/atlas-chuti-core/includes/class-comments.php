<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 5, items 20-21: native WordPress comments (audit found no reason to build a
 * parallel system — comments belong to one specific locale's post, which is exactly
 * WP's own `comment_post_ID` model, and native comments already come with
 * moderation/spam handling this plugin must not duplicate). Two changes only:
 *   1. `atlas_recipe` gains `comments` support (it never had it — see the Step 5
 *      report's audit section).
 *   2. Only logged-in users may actually POST a comment — enforced server-side in
 *      TWO places (comments_open() AND preprocess_comment, belt-and-suspenders
 *      against anything that bypasses the theme's own comment_form() call), never
 *      only hidden in the UI.
 * This restriction is scoped to `atlas_recipe` only (item 20: "nevyžaduj kvůli tomu
 * globálně přihlášení pro komentáře u všech budoucích typů obsahu") — a future
 * Magazín `post` comment thread is untouched by this class.
 */
class Atlas_Chuti_Comments {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Priority 20: after class-post-types.php's register_post_type() call
		// (priority 10) has actually created 'atlas_recipe', so add_post_type_support()
		// has something to attach to.
		add_action( 'init', array( $this, 'add_comment_support' ), 20 );
		add_filter( 'comments_open', array( $this, 'require_login_for_recipe_comments' ), 10, 2 );
		add_filter( 'preprocess_comment', array( $this, 'block_anonymous_recipe_comment' ) );
	}

	public function add_comment_support() {
		add_post_type_support( 'atlas_recipe', 'comments' );
	}

	public function require_login_for_recipe_comments( $open, $post_id ) {
		if ( 'atlas_recipe' === get_post_type( $post_id ) ) {
			return is_user_logged_in();
		}
		return $open;
	}

	/**
	 * Defense in depth: even if some future code re-opens comments_open() for
	 * recipes, a direct POST to wp-comments-post.php still can't create an
	 * anonymous comment on an atlas_recipe post.
	 */
	public function block_anonymous_recipe_comment( $commentdata ) {
		$post_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
		if ( $post_id && 'atlas_recipe' === get_post_type( $post_id ) && ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Pro přidání komentáře k receptu se prosím přihlaste.', 'atlas-chuti' ), '', array( 'response' => 403 ) );
		}
		return $commentdata;
	}

	/**
	 * Můj Atlas → Moje komentáře (item 21): every comment this user left on a
	 * recipe, most recent first, across BOTH locales (native wp_comments already
	 * ties each row to one specific post — the CZ/EN separation the brief requires
	 * falls out automatically since a CZ comment's comment_post_ID is the CZ post).
	 */
	public function get_user_recipe_comments( $user_id ) {
		if ( ! $user_id ) {
			return array();
		}
		return get_comments(
			array(
				'user_id'    => $user_id,
				'post_type'  => 'atlas_recipe',
				'status'     => 'all',
				'orderby'    => 'comment_date_gmt',
				'order'      => 'DESC',
				'number'     => 200,
			)
		);
	}
}
