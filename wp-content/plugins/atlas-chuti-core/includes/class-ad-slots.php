<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 7, item 3-4: the Ad Slot Registry — a closed, stable catalog of every
 * place this site is willing to show advertising, each with its own technical
 * key, placement/context, device policy, reserved-dimension strategy, lazy
 * policy and consent requirement. Nothing outside this file may invent a new
 * slot key — Atlas_Chuti_Advertising::render_slot() (class-advertising.php)
 * refuses anything not registered here (item 4: "neznámý slot key → bezpečně
 * rejected/empty").
 *
 * This is pure, static, admin-independent DATA — per-slot admin overrides
 * (enabled/source/campaign/locale/dates) live in the `atlas_chuti_ads_settings`
 * option, resolved by Atlas_Chuti_Advertising, never here. Keeping the two
 * separate is what makes "unknown slot key" a permanent, code-level guarantee
 * rather than something a bad admin save could ever widen.
 */
class Atlas_Chuti_Ad_Slots {

	const CONSENT_NONE      = 'none';
	const CONSENT_MARKETING = 'marketing';

	const DEVICE_ALL     = 'all';
	const DEVICE_DESKTOP = 'desktop';

	/**
	 * key => [
	 *   'label'          => admin-facing name,
	 *   'context'        => which template area this belongs to (for the admin UI/report only),
	 *   'device'         => DEVICE_ALL | DEVICE_DESKTOP,
	 *   'reserved'       => CSS aspect-ratio/min-height reservation class (see main.css ".ad-slot--*"),
	 *   'lazy'           => true (IntersectionObserver-loaded) | false (eager — only ever the GATE and header slot, both already above the fold by design),
	 *   'consent'        => CONSENT_NONE | CONSENT_MARKETING (CONSENT_MARKETING is REQUIRED — not just default — whenever the resolved source is external_network, regardless of this value; this value only matters for a `direct` campaign, see Atlas_Chuti_Advertising::slot_requires_consent()),
	 * ]
	 */
	const SLOTS = array(
		'gate_desktop'          => array(
			'label'    => 'GATE — desktop wallpaper',
			'context'  => 'sitewide',
			'device'   => self::DEVICE_DESKTOP,
			'reserved' => 'gate',
			'lazy'     => false,
			'consent'  => self::CONSENT_NONE,
		),
		'header_leaderboard'    => array(
			'label'    => 'Header leaderboard',
			'context'  => 'sitewide',
			'device'   => self::DEVICE_ALL,
			'reserved' => 'leaderboard',
			'lazy'     => false,
			'consent'  => self::CONSENT_NONE,
		),
		'homepage_after_lead'   => array(
			'label'    => 'Homepage — po hlavním editorial bloku',
			'context'  => 'homepage',
			'device'   => self::DEVICE_ALL,
			'reserved' => 'leaderboard',
			'lazy'     => true,
			'consent'  => self::CONSENT_NONE,
		),
		'homepage_mid_content'  => array(
			'label'    => 'Homepage — feed sidebar',
			'context'  => 'homepage',
			'device'   => self::DEVICE_ALL,
			'reserved' => 'rectangle',
			'lazy'     => true,
			'consent'  => self::CONSENT_NONE,
		),
		'homepage_before_footer' => array(
			'label'    => 'Homepage — před footerem',
			'context'  => 'homepage',
			'device'   => self::DEVICE_ALL,
			'reserved' => 'leaderboard',
			'lazy'     => true,
			'consent'  => self::CONSENT_NONE,
		),
		'recipe_sidebar_top'    => array(
			'label'    => 'Recipe — sidebar top',
			'context'  => 'recipe_detail',
			'device'   => self::DEVICE_ALL,
			'reserved' => 'rectangle',
			'lazy'     => true,
			'consent'  => self::CONSENT_NONE,
		),
		'recipe_in_content'     => array(
			'label'    => 'Recipe — in-content (po ingrediencích/postupu)',
			'context'  => 'recipe_detail',
			'device'   => self::DEVICE_ALL,
			'reserved' => 'leaderboard',
			'lazy'     => true,
			'consent'  => self::CONSENT_NONE,
		),
		'recipe_after_content'  => array(
			'label'    => 'Recipe — after content (volitelné)',
			'context'  => 'recipe_detail',
			'device'   => self::DEVICE_ALL,
			'reserved' => 'leaderboard',
			'lazy'     => true,
			'consent'  => self::CONSENT_NONE,
		),
		'archive_in_feed'       => array(
			'label'    => 'Recipe archive — in-feed (po N kartách)',
			'context'  => 'recipe_archive',
			'device'   => self::DEVICE_ALL,
			'reserved' => 'card',
			'lazy'     => true,
			'consent'  => self::CONSENT_NONE,
		),
		'magazine_sidebar'      => array(
			'label'    => 'Magazín — sidebar (zatím bez cílového místa, viz report sekce N)',
			'context'  => 'magazine',
			'device'   => self::DEVICE_ALL,
			'reserved' => 'rectangle',
			'lazy'     => true,
			'consent'  => self::CONSENT_NONE,
		),
		'magazine_in_content'   => array(
			'label'    => 'Magazín — in-content (článek)',
			'context'  => 'magazine',
			'device'   => self::DEVICE_ALL,
			'reserved' => 'leaderboard',
			'lazy'     => true,
			'consent'  => self::CONSENT_NONE,
		),
		'magazine_archive_in_feed' => array(
			'label'    => 'Magazín — archive in-feed',
			'context'  => 'magazine',
			'device'   => self::DEVICE_ALL,
			'reserved' => 'card',
			'lazy'     => true,
			'consent'  => self::CONSENT_NONE,
		),
		'discussion_in_feed'    => array(
			'label'    => 'Diskuze — archive in-feed (po N tématech)',
			'context'  => 'discussion',
			'device'   => self::DEVICE_ALL,
			'reserved' => 'leaderboard', // a horizontal-banner shape, not 'card' — the topic list is a row list (.atlas-topic-list), not a card grid, so a tall card-ratio box would look broken here.
			'lazy'     => true,
			'consent'  => self::CONSENT_NONE,
		),
		'discussion_topic_after_content' => array(
			'label'    => 'Diskuze — po hlavním obsahu tématu',
			'context'  => 'discussion',
			'device'   => self::DEVICE_ALL,
			'reserved' => 'leaderboard',
			'lazy'     => true,
			'consent'  => self::CONSENT_NONE,
		),
		'footer_leaderboard'    => array(
			'label'    => 'Footer leaderboard',
			'context'  => 'sitewide',
			'device'   => self::DEVICE_ALL,
			'reserved' => 'leaderboard',
			'lazy'     => true,
			'consent'  => self::CONSENT_NONE,
		),
	);

	public static function keys() {
		return array_keys( self::SLOTS );
	}

	public static function exists( $key ) {
		return isset( self::SLOTS[ $key ] );
	}

	public static function get( $key ) {
		return self::SLOTS[ $key ] ?? null;
	}

	public static function label( $key ) {
		return self::SLOTS[ $key ]['label'] ?? $key;
	}
}
