<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 7, item 17: the "direct campaign" data model — a lightweight, hidden CPT
 * (`atlas_ad_campaign`), the same `public=>false, show_ui=>true` pattern already
 * used for `atlas_ingredient` (class-post-types.php) — auditable in wp-admin like
 * any other post, no separate ad-server/auction/frequency-capping/billing system
 * (explicitly out of scope per the brief). The featured image IS the creative
 * (no separate media-upload meta field needed — WordPress already has one).
 *
 * A campaign is "active" only when ALL of: post_status is 'publish', today
 * (site timezone) falls within [start, end] (either bound optional), the
 * requested slot is in its slot list, and the requested locale matches its
 * locale targeting ('all' or an exact match). Atlas_Chuti_Advertising is the
 * only caller of is_active_for() — this class never renders HTML itself,
 * matching the plugin/theme "data model vs presentation" split used everywhere
 * else in this project.
 */
class Atlas_Chuti_Ad_Campaign {

	const POST_TYPE = 'atlas_ad_campaign';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Reklamní kampaně', 'atlas-chuti' ),
					'singular_name' => __( 'Kampaň', 'atlas-chuti' ),
					'add_new_item'  => __( 'Přidat kampaň', 'atlas-chuti' ),
					'edit_item'     => __( 'Upravit kampaň', 'atlas-chuti' ),
					'search_items'  => __( 'Hledat kampaně', 'atlas-chuti' ),
					'not_found'     => __( 'Žádné kampaně nenalezeny', 'atlas-chuti' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'atlas-chuti-import',
				'show_in_rest'    => true,
				'menu_icon'       => 'dashicons-megaphone',
				'supports'        => array( 'title', 'thumbnail', 'revisions' ),
				'has_archive'     => false,
				'capability_type' => 'post',
			)
		);
	}

	/**
	 * Every stable slot key this campaign is allowed to render into — a closed
	 * list validated against Atlas_Chuti_Ad_Slots::exists(), never a free-text
	 * field (item 4: unknown keys are always rejected).
	 */
	public function get_slots( $campaign_id ) {
		$slots = get_post_meta( $campaign_id, 'atlas_ad_slots', true );
		$slots = is_array( $slots ) ? $slots : array();
		return array_values( array_filter( $slots, array( 'Atlas_Chuti_Ad_Slots', 'exists' ) ) );
	}

	public function get_locale( $campaign_id ) {
		$locale = get_post_meta( $campaign_id, 'atlas_ad_locale', true );
		return in_array( $locale, array( 'all', 'cs', 'en' ), true ) ? $locale : 'all';
	}

	public function get_click_url( $campaign_id ) {
		return (string) get_post_meta( $campaign_id, 'atlas_ad_click_url', true );
	}

	public function get_alt_text( $campaign_id ) {
		return (string) get_post_meta( $campaign_id, 'atlas_ad_alt_text', true );
	}

	private function in_date_range( $campaign_id ) {
		$start = get_post_meta( $campaign_id, 'atlas_ad_start', true );
		$end   = get_post_meta( $campaign_id, 'atlas_ad_end', true );
		$now   = current_time( 'timestamp' ); // phpcs:ignore -- WordPress site timezone, matches item 36's own instruction.

		if ( $start && strtotime( $start ) > $now ) {
			return false;
		}
		if ( $end && strtotime( $end ) < $now ) {
			return false;
		}
		return true;
	}

	/**
	 * The one real decision point: is THIS campaign eligible to render into
	 * $slot_key, for a visitor in $locale, right now? Publish status + date
	 * range + slot membership + locale targeting, nothing more — no auction, no
	 * frequency capping (item 17's own explicit "NEIMPLEMENTUJ" list).
	 */
	public function is_active_for( $campaign_id, $slot_key, $locale ) {
		if ( 'publish' !== get_post_status( $campaign_id ) ) {
			return false;
		}
		if ( ! in_array( $slot_key, $this->get_slots( $campaign_id ), true ) ) {
			return false;
		}
		$campaign_locale = $this->get_locale( $campaign_id );
		if ( 'all' !== $campaign_locale && $campaign_locale !== $locale ) {
			return false;
		}
		return $this->in_date_range( $campaign_id );
	}

	/**
	 * The first published, in-range, correctly-targeted campaign for this slot —
	 * "first" by publish date, oldest first, so results are stable/reproducible
	 * rather than depending on insertion order. Deliberately no auction/rotation
	 * logic (item 17).
	 */
	public function find_for_slot( $slot_key, $locale ) {
		$candidates = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);
		foreach ( $candidates as $campaign ) {
			if ( $this->is_active_for( $campaign->ID, $slot_key, $locale ) ) {
				return $campaign;
			}
		}
		return null;
	}
}
