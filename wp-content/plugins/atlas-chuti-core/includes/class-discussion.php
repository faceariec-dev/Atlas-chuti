<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 6, items 13-19: Diskuze — a SEPARATE community area from recipe comments
 * (Krok 5's class-comments.php stays recipe-only, untouched). Topic = `atlas_topic`
 * CPT (class-post-types.php); reply = native WP comment on that CPT, reusing real
 * WordPress moderation/spam/capability handling instead of a parallel system
 * (item 14's own explicit instruction). Locale separation falls out of the SAME
 * mechanism Krok 4/5 already established for every other locale-bearing post type
 * (Atlas_Chuti_I18N::LOCALIZED_POST_TYPES + scope_query_to_locale()) — a reply
 * "dědí locale topicu" for free, since it's just a WP comment whose
 * comment_post_ID already carries that locale (item 18).
 */
class Atlas_Chuti_Discussion {

	const MODERATE_CAPABILITY = 'moderate_comments'; // same real capability Krok 5's photo moderation already uses.

	const RATE_LIMIT_TOPICS_PER_WINDOW  = 3;
	const RATE_LIMIT_TOPIC_WINDOW       = 10 * MINUTE_IN_SECONDS;
	const RATE_LIMIT_REPLIES_PER_WINDOW = 10;
	const RATE_LIMIT_REPLY_WINDOW       = 10 * MINUTE_IN_SECONDS;

	/**
	 * Deliberately narrower than wp_kses_post() (item 36: "sanitize content") — a
	 * forum reply/topic body doesn't need shortcodes, embeds, images, or heading
	 * levels a subscriber-authored post has no editorial reason to use.
	 */
	const ALLOWED_HTML = array(
		'p'          => array(),
		'br'         => array(),
		'strong'     => array(),
		'em'         => array(),
		'a'          => array( 'href' => array(), 'rel' => array() ),
		'ul'         => array(),
		'ol'         => array(),
		'li'         => array(),
		'blockquote' => array(),
	);

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'add_comment_support' ), 20 );
		add_filter( 'comments_open', array( $this, 'require_login_and_open_for_topic_reply' ), 10, 2 );
		add_filter( 'preprocess_comment', array( $this, 'guard_topic_reply' ) );

		add_action( 'admin_post_atlas_topic_create', array( $this, 'handle_admin_post_create' ) );
		add_action( 'admin_post_atlas_topic_moderate', array( $this, 'handle_admin_post_moderate' ) );
	}

	public function add_comment_support() {
		add_post_type_support( 'atlas_topic', 'comments' );
	}

	// ---------------------------------------------------------------------
	// Reply restrictions (item 17: no anonymous posting; item 19: closed topics
	// accept no new replies)
	// ---------------------------------------------------------------------

	public function require_login_and_open_for_topic_reply( $open, $post_id ) {
		if ( 'atlas_topic' !== get_post_type( $post_id ) ) {
			return $open;
		}
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return ! $this->is_closed( $post_id );
	}

	public function guard_topic_reply( $commentdata ) {
		$post_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
		if ( ! $post_id || 'atlas_topic' !== get_post_type( $post_id ) ) {
			return $commentdata;
		}
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Pro odpověď v diskuzi se prosím přihlaste.', 'atlas-chuti' ), '', array( 'response' => 403 ) );
		}
		if ( $this->is_closed( $post_id ) ) {
			wp_die( esc_html__( 'Toto téma je uzavřené a nepřijímá nové odpovědi.', 'atlas-chuti' ), '', array( 'response' => 403 ) );
		}
		if ( $this->is_rate_limited( 'reply', get_current_user_id(), self::RATE_LIMIT_REPLIES_PER_WINDOW, self::RATE_LIMIT_REPLY_WINDOW ) ) {
			wp_die( esc_html__( 'Příliš mnoho odpovědí najednou, zkuste to prosím za chvíli.', 'atlas-chuti' ), '', array( 'response' => 429 ) );
		}
		return $commentdata;
	}

	// ---------------------------------------------------------------------
	// Topic creation (item 11: "Logged-in user může bezpečně vytvořit topic")
	// ---------------------------------------------------------------------

	private function client_identity_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
		return hash( 'sha256', $ip . wp_salt( 'auth' ) );
	}

	private function is_rate_limited( $bucket, $user_id, $max, $window ) {
		$key   = 'atlas_chuti_rl_' . $bucket . '_' . $user_id . '_' . $this->client_identity_hash();
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $key, $count + 1, $window );
		return false;
	}

	public function is_valid_category( $category_key ) {
		return in_array( $category_key, Atlas_Chuti_Taxonomy_Labels::keys( 'atlas_topic_category' ), true );
	}

	/**
	 * The real create-topic logic, isolated from any HTTP request/redirect glue
	 * (same split as class-account.php's register_user()/authenticate_user() —
	 * see that class's own docblock for why) — returns the new topic's post ID or
	 * a WP_Error, never calls exit(). Never trusts $category_key against anything
	 * but the closed, seeded catalog (item 16/26).
	 */
	public function create_topic( $user_id, $title, $content, $category_key ) {
		if ( ! $user_id ) {
			return new WP_Error( 'login_required' );
		}
		if ( $this->is_rate_limited( 'topic', $user_id, self::RATE_LIMIT_TOPICS_PER_WINDOW, self::RATE_LIMIT_TOPIC_WINDOW ) ) {
			return new WP_Error( 'rate_limited' );
		}
		$title = trim( wp_strip_all_tags( (string) $title ) );
		if ( '' === $title ) {
			return new WP_Error( 'title_required' );
		}
		$content = trim( wp_kses( (string) $content, self::ALLOWED_HTML ) );
		if ( '' === $content ) {
			return new WP_Error( 'content_required' );
		}
		if ( ! $this->is_valid_category( $category_key ) ) {
			return new WP_Error( 'invalid_category' );
		}

		$locale = Atlas_Chuti_I18N::current_locale();
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'atlas_topic',
				'post_title'  => $title,
				'post_content' => $content,
				'post_status' => 'publish',
				'post_author' => $user_id,
				'meta_input'  => array( 'atlas_locale' => $locale ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'server_error' );
		}
		wp_set_post_terms( $post_id, array( $category_key ), 'atlas_topic_category', false );
		Atlas_Chuti_Polylang_Bridge::assign_language( $post_id, $locale );
		return $post_id;
	}

	public function handle_admin_post_create() {
		if ( ! is_user_logged_in() || ! isset( $_POST['atlas_topic_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_topic_nonce'], 'atlas_topic_create' ) ) {
			wp_die( esc_html__( 'Neplatný požadavek.', 'atlas-chuti' ) );
		}
		$title    = isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '';
		$content  = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';
		$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '';

		$result = $this->create_topic( get_current_user_id(), $title, $content, $category );

		if ( is_wp_error( $result ) ) {
			$target = add_query_arg( array( 'sekce' => 'nove-tema', 'chyba' => $result->get_error_code() ), atlas_chuti_system_url( 'discussion' ) );
			wp_safe_redirect( $target );
			exit;
		}
		wp_safe_redirect( get_permalink( $result ) );
		exit;
	}

	// ---------------------------------------------------------------------
	// Moderation (item 19: close/pin; capability-gated, never a subscriber action)
	// ---------------------------------------------------------------------

	public function is_closed( $topic_id ) {
		return (bool) get_post_meta( $topic_id, 'atlas_topic_closed', true );
	}

	public function is_pinned( $topic_id ) {
		return (bool) get_post_meta( $topic_id, 'atlas_topic_pinned', true );
	}

	public function set_closed( $topic_id, $closed ) {
		update_post_meta( $topic_id, 'atlas_topic_closed', $closed ? 1 : 0 );
	}

	public function set_pinned( $topic_id, $pinned ) {
		update_post_meta( $topic_id, 'atlas_topic_pinned', $pinned ? 1 : 0 );
	}

	public function handle_admin_post_moderate() {
		$topic_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( ! current_user_can( self::MODERATE_CAPABILITY ) || ! $topic_id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'atlas_topic_moderate_' . $topic_id ) ) {
			wp_die( esc_html__( 'Neplatný požadavek.', 'atlas-chuti' ) );
		}
		$action = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		switch ( $action ) {
			case 'close':
				$this->set_closed( $topic_id, true );
				break;
			case 'open':
				$this->set_closed( $topic_id, false );
				break;
			case 'pin':
				$this->set_pinned( $topic_id, true );
				break;
			case 'unpin':
				$this->set_pinned( $topic_id, false );
				break;
			case 'trash':
				wp_trash_post( $topic_id );
				break;
		}
		wp_safe_redirect( get_permalink( $topic_id ) ?: atlas_chuti_system_url( 'discussion' ) );
		exit;
	}

	/**
	 * Můj Atlas → Moje témata (item 21 — implemented, not deferred: the resolver
	 * infrastructure item 21 would otherwise need already exists from Krok 5's
	 * "Moje komentáře"/"Moje fotografie", so the marginal cost here is small).
	 */
	public function get_user_topics( $user_id ) {
		if ( ! $user_id ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => 'atlas_topic',
				'author'         => $user_id,
				'post_status'    => array( 'publish', 'pending' ),
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}
}
