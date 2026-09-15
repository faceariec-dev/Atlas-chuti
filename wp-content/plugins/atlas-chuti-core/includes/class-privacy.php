<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 5, item 37: this step adds account-linked custom-table data
 * (favorite/cooked/tasted, registered ratings, photo metadata) that WordPress's
 * own privacy tools (Nástroje → Export osobních údajů / Vymazat osobní údaje)
 * cannot see on their own — core only knows about its own tables. Comments are
 * deliberately NOT duplicated here (item 37: "Komentáře WordPress už umí vlastní
 * privacy flow — neduplikuj ho") — WP core already registers a comments
 * exporter/eraser for every comment matching the requested email.
 */
class Atlas_Chuti_Privacy {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	public function register_exporter( $exporters ) {
		$exporters['atlas-chuti-account-data'] = array(
			'exporter_friendly_name' => __( 'Atlas chutí — Můj Atlas', 'atlas-chuti' ),
			'callback'                => array( $this, 'export_data' ),
		);
		return $exporters;
	}

	public function register_eraser( $erasers ) {
		$erasers['atlas-chuti-account-data'] = array(
			'eraser_friendly_name' => __( 'Atlas chutí — Můj Atlas', 'atlas-chuti' ),
			'callback'              => array( $this, 'erase_data' ),
		);
		return $erasers;
	}

	private function user_for( $email ) {
		return get_user_by( 'email', $email );
	}

	public function export_data( $email, $page = 1 ) {
		$user = $this->user_for( $email );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		$user_id = $user->ID;
		$data    = array();

		foreach ( array( Atlas_Chuti_User_State::STATE_FAVORITE, Atlas_Chuti_User_State::STATE_COOKED ) as $state ) {
			foreach ( Atlas_Chuti_User_State::instance()->get_for_user( $user_id, Atlas_Chuti_User_State::TYPE_RECIPE, $state ) as $recipe_key ) {
				$data[] = array(
					'group_id'    => 'atlas-chuti-' . $state,
					'group_label' => 'favorite' === $state ? __( 'Oblíbené recepty', 'atlas-chuti' ) : __( 'Uvařené recepty', 'atlas-chuti' ),
					'item_id'     => 'atlas-chuti-' . $state . '-' . $recipe_key,
					'data'        => array( array( 'name' => __( 'recipe_key', 'atlas-chuti' ), 'value' => $recipe_key ) ),
				);
			}
		}

		foreach ( Atlas_Chuti_User_State::instance()->get_for_user( $user_id, Atlas_Chuti_User_State::TYPE_COUNTRY, Atlas_Chuti_User_State::STATE_TASTED ) as $iso ) {
			$data[] = array(
				'group_id'    => 'atlas-chuti-tasted',
				'group_label' => __( 'Ochutnané země', 'atlas-chuti' ),
				'item_id'     => 'atlas-chuti-tasted-' . $iso,
				'data'        => array( array( 'name' => __( 'ISO kód', 'atlas-chuti' ), 'value' => $iso ) ),
			);
		}

		foreach ( Atlas_Chuti_Ratings::instance()->get_user_ratings( $user_id ) as $row ) {
			$data[] = array(
				'group_id'    => 'atlas-chuti-ratings',
				'group_label' => __( 'Moje hodnocení', 'atlas-chuti' ),
				'item_id'     => 'atlas-chuti-rating-' . $row['recipe_key'],
				'data'        => array(
					array( 'name' => __( 'recipe_key', 'atlas-chuti' ), 'value' => $row['recipe_key'] ),
					array( 'name' => __( 'hodnocení', 'atlas-chuti' ), 'value' => $row['rating'] ),
					array( 'name' => __( 'aktualizováno', 'atlas-chuti' ), 'value' => $row['updated_at'] ),
				),
			);
		}

		foreach ( Atlas_Chuti_Photos::instance()->get_user_photos( $user_id ) as $row ) {
			$data[] = array(
				'group_id'    => 'atlas-chuti-photos',
				'group_label' => __( 'Moje fotografie', 'atlas-chuti' ),
				'item_id'     => 'atlas-chuti-photo-' . $row['id'],
				'data'        => array(
					array( 'name' => __( 'recipe_key', 'atlas-chuti' ), 'value' => $row['recipe_key'] ),
					array( 'name' => __( 'stav', 'atlas-chuti' ), 'value' => $row['status'] ),
					array( 'name' => __( 'nahráno', 'atlas-chuti' ), 'value' => $row['created_at'] ),
				),
			);
		}

		// KROK 8, item 39/49: collections/shopping list/meal plan join the same
		// exporter — each a foreach over its own service's get_for_user(), same
		// shape as every group above.
		foreach ( Atlas_Chuti_Collections::instance()->get_for_user( $user_id ) as $collection ) {
			$data[] = array(
				'group_id'    => 'atlas-chuti-collections',
				'group_label' => __( 'Moje kolekce', 'atlas-chuti' ),
				'item_id'     => 'atlas-chuti-collection-' . $collection->id,
				'data'        => array(
					array( 'name' => __( 'název', 'atlas-chuti' ), 'value' => $collection->title ),
					array( 'name' => __( 'počet receptů', 'atlas-chuti' ), 'value' => $collection->item_count ),
					array( 'name' => __( 'aktualizováno', 'atlas-chuti' ), 'value' => $collection->updated_at ),
				),
			);
		}

		foreach ( Atlas_Chuti_Shopping_List::instance()->get_for_user( $user_id ) as $item ) {
			$data[] = array(
				'group_id'    => 'atlas-chuti-shopping-list',
				'group_label' => __( 'Nákupní seznam', 'atlas-chuti' ),
				'item_id'     => 'atlas-chuti-shopping-' . $item->id,
				'data'        => array(
					array( 'name' => __( 'položka', 'atlas-chuti' ), 'value' => $item->display_name ),
					array( 'name' => __( 'množství', 'atlas-chuti' ), 'value' => trim( $item->quantity_text . ' ' . $item->unit_key ) ),
				),
			);
		}

		foreach ( Atlas_Chuti_Meal_Plan::instance()->get_for_range( $user_id, '1970-01-01', '2999-12-31' ) as $item ) {
			$data[] = array(
				'group_id'    => 'atlas-chuti-meal-plan',
				'group_label' => __( 'Plán jídel', 'atlas-chuti' ),
				'item_id'     => 'atlas-chuti-meal-plan-' . $item->id,
				'data'        => array(
					array( 'name' => __( 'datum', 'atlas-chuti' ), 'value' => $item->plan_date ),
					array( 'name' => __( 'jídlo', 'atlas-chuti' ), 'value' => $item->meal_slot ),
					array( 'name' => __( 'recipe_key', 'atlas-chuti' ), 'value' => $item->recipe_key ),
				),
			);
		}

		return array( 'data' => $data, 'done' => true );
	}

	public function erase_data( $email, $page = 1 ) {
		$user = $this->user_for( $email );
		if ( ! $user ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		$user_id = $user->ID;

		Atlas_Chuti_User_State::instance()->delete_all_for_user( $user_id );
		// Registered ratings: a GDPR erase request removes them outright (stronger
		// than the softer WP-user-deletion path, which anonymizes by the same
		// mechanism — see class-ratings.php's own docblock for why a "half-anonymous"
		// row is never left behind).
		Atlas_Chuti_Ratings::instance()->anonymize_for_deleted_user( $user_id );
		Atlas_Chuti_Photos::instance()->delete_all_for_user( $user_id );
		Atlas_Chuti_Collections::instance()->delete_all_for_user( $user_id );
		Atlas_Chuti_Shopping_List::instance()->delete_all_for_user( $user_id );
		Atlas_Chuti_Meal_Plan::instance()->delete_all_for_user( $user_id );

		return array(
			'items_removed'  => true,
			'items_retained' => false,
			'messages'        => array(),
			'done'            => true,
		);
	}
}
