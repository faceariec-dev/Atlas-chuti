<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 7: the ad-rendering engine — the ONE place that decides what (if
 * anything) appears in a given slot, and the ONE place that echoes ad markup.
 * Every template calls the single helper `atlas_chuti_render_ad_slot( $key )`
 * (functions.php) instead of hand-rolling ad HTML (item 3's own instruction);
 * this class is what that helper delegates to.
 *
 * Resolution order for a slot: unknown key → nothing (item 2) → global
 * advertising switch off → nothing → slot not enabled in admin → nothing →
 * source 'none' → nothing → source 'direct' → the first active
 * Atlas_Chuti_Ad_Campaign for this slot/locale, or nothing if none is active →
 * source 'external_network' → nothing UNLESS a provider is actually configured
 * AND consent allows marketing (item 19: "network ads musí být defaultně
 * bezpečně disabled").
 *
 * A slot that resolves to "nothing" renders ZERO markup — no wrapper div, no
 * placeholder, no reserved space (item 12: "Bez reklamy se slot nevykreslí
 * jako prázdná karta"; item 21: "Pokud slot nemá reklamu, nesmí zůstat
 * obrovská prázdná mezera"). Reserved-space CSS only ever wraps a slot that IS
 * rendering something, so a real (or async-pending, for a future real
 * provider) creative never causes a layout jump.
 */
class Atlas_Chuti_Advertising {

	const OPTION = 'atlas_chuti_ads_settings';

	private static $instance = null;
	private $provider_script_enqueued = false;
	private $active_slots_on_page = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_body_open', array( $this, 'render_gate' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'maybe_enqueue_provider_script' ), 20 );
		$this->bridge_existing_theme_hooks();
	}

	/**
	 * KROK 7, item 3: reuses the theme's OWN pre-existing, already-unused ad
	 * extension points (front-page.php's `atlas_chuti_ad_slot`/
	 * `atlas_chuti_home_after_feed`, single-atlas_recipe.php's
	 * `atlas_chuti_recipe_sidebar_ad`) instead of editing those templates —
	 * confirmed by the Step 7 audit that none of the three had any listener
	 * yet (`atlas_chuti_recipe_community` on the same template IS already used
	 * by Step 5's community UI and is deliberately left untouched here). This
	 * is what lets Step 7 add real ad rendering to the homepage and recipe
	 * sidebar with ZERO template edits for those two positions.
	 */
	private function bridge_existing_theme_hooks() {
		add_action(
			'atlas_chuti_ad_slot',
			function ( $position ) {
				$map = array( 'after_lead' => 'homepage_after_lead', 'feed_sidebar' => 'homepage_mid_content' );
				if ( isset( $map[ $position ] ) ) {
					$this->render_slot( $map[ $position ] );
				}
			}
		);
		add_action( 'atlas_chuti_home_after_feed', function () { $this->render_slot( 'homepage_before_footer' ); } );
		add_action( 'atlas_chuti_recipe_sidebar_ad', function () { $this->render_slot( 'recipe_sidebar_top' ); } );
	}

	// -------------------------------------------------------------------
	// Settings resolution
	// -------------------------------------------------------------------

	public function settings() {
		$defaults = array(
			'enabled'                  => true,
			'gate_enabled'             => false,
			'external_network_enabled' => false,
			'debug_mode'               => false,
			'per_slot'                 => array(),
		);
		$stored = get_option( self::OPTION );
		$stored = is_array( $stored ) ? $stored : array();
		return array_merge( $defaults, $stored );
	}

	public function is_globally_enabled() {
		return ! empty( $this->settings()['enabled'] );
	}

	public function is_gate_globally_enabled() {
		return $this->is_globally_enabled() && ! empty( $this->settings()['gate_enabled'] );
	}

	public function is_external_network_globally_enabled() {
		return $this->is_globally_enabled() && ! empty( $this->settings()['external_network_enabled'] );
	}

	public function is_debug_mode() {
		return ! empty( $this->settings()['debug_mode'] );
	}

	/**
	 * Per-slot admin config, merged onto safe defaults — an UNCONFIGURED slot
	 * defaults to disabled + source 'none', never silently active (item 51's
	 * own "žádné fake campaigns"/conservative-default spirit).
	 */
	public function slot_config( $slot_key ) {
		$defaults = array( 'enabled' => false, 'source' => 'none', 'campaign_id' => 0 );
		$per_slot = $this->settings()['per_slot'];
		return array_merge( $defaults, is_array( $per_slot[ $slot_key ] ?? null ) ? $per_slot[ $slot_key ] : array() );
	}

	/**
	 * KROK 7, item 42: login/register/password-reset/account settings/Můj Atlas
	 * (all one template since Step 5, `template-my-atlas.php`) and the legal/
	 * community-rules Pages (privacy, cookies, cookie settings, terms,
	 * community rules, UGC rules) never carry advertising, by DEFAULT and
	 * unconditionally — checked once here so every render path (generic slots,
	 * GATE, the bridged homepage/recipe-sidebar hooks) automatically respects
	 * it without each call site needing to remember to. "Pokud později chceme
	 * změnit policy, lze to nastavit explicitně" (item 42) — a future change
	 * would extend this one method, not scatter exceptions across templates.
	 */
	public function is_ad_free_context() {
		// item 50: "Recommendation landing: může později mít reklamu, ale v Kroku 8
		// preferuj content utility first" — Co dnes vařit?/Co mám doma? stay ad-free
		// for now, a deliberate, documented, reversible choice (see the report).
		if ( is_page_template( array( 'template-my-atlas.php', 'template-co-dnes-varit.php', 'template-co-mam-doma.php' ) ) ) {
			return true;
		}
		// KROK 8, item 5/50: Cook Mode is a utility mode of the SAME recipe URL
		// (?cook=1, see the Step 8 report section B for why — no separate
		// route/page template exists to add to the check above) — a direct
		// load/bookmark/reload of that URL must never render ads server-side,
		// on top of the JS-side visual coverage the Cook Mode overlay itself
		// provides once activated without a reload.
		if ( isset( $_GET['cook'] ) && '1' === $_GET['cook'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI-state flag, not a state-changing action.
			return true;
		}
		if ( is_page() ) {
			$excluded_slugs = array( 'ochrana-osobnich-udaju', 'cookies', 'nastaveni-cookies', 'podminky-pouzivani', 'pravidla-komunity', 'pravidla-ugc' );
			return in_array( get_post_field( 'post_name', get_the_ID() ), $excluded_slugs, true );
		}
		return false;
	}

	// -------------------------------------------------------------------
	// Consent / provider hooks (item 19)
	// -------------------------------------------------------------------

	/**
	 * No CMP exists yet in this project (confirmed by the Step 7 audit) — so
	 * this defaults to false and is entirely filterable, exactly matching item
	 * 19's own instruction: "Pokud CMP zatím není implementované: network ads
	 * musí být defaultně bezpečně disabled." A future CMP integration flips
	 * this true via `add_filter( 'atlas_chuti_consent_allows_marketing', ... )`
	 * once it has a real, meaningful consent signal to check — this class never
	 * invents legal/compliance logic on its own (item 20's own instruction).
	 */
	public function consent_allows_marketing() {
		return (bool) apply_filters( 'atlas_chuti_consent_allows_marketing', false );
	}

	/**
	 * External ad network config — deliberately never hardcoded (item 18: "žádné
	 * production IDs, žádné network credentials, žádný hardcoded publisher ID").
	 * A real deployment supplies this via the filter (e.g. from a private
	 * mu-plugin or wp-config constant, never committed to this repo) — see the
	 * report's "Manual configuration" section for exactly what keys a future
	 * provider integration needs to supply here.
	 */
	public function provider_config() {
		return (array) apply_filters( 'atlas_chuti_ad_provider_config', array() );
	}

	public function can_load_advertising_provider() {
		if ( ! $this->is_external_network_globally_enabled() ) {
			return false;
		}
		if ( empty( $this->provider_config() ) ) {
			return false;
		}
		return $this->consent_allows_marketing();
	}

	// -------------------------------------------------------------------
	// Locale
	// -------------------------------------------------------------------

	private function short_locale() {
		$locale = class_exists( 'Atlas_Chuti_I18N' ) ? Atlas_Chuti_I18N::current_locale() : 'cs-CZ';
		return 0 === strpos( $locale, 'cs' ) ? 'cs' : 'en';
	}

	/**
	 * Item 30: "Reklama" (CZ) / "Advertisement" (EN) — an explicit locale
	 * branch, NOT __()/esc_html__(), because this project has no compiled .mo
	 * translation file for 'en' anywhere (confirmed by the Step 7 audit), so a
	 * gettext call would silently return the Czech string on the English site
	 * too. Same "explicit locale-keyed lookup, not gettext" pattern this
	 * codebase already uses for taxonomy labels (Atlas_Chuti_Taxonomy_Labels)
	 * — kept private/tiny here since it's only ever this one label, not a
	 * whole catalog.
	 */
	private function ad_label() {
		return 'en' === $this->short_locale() ? 'Advertisement' : 'Reklama';
	}

	// -------------------------------------------------------------------
	// Generic slot rendering
	// -------------------------------------------------------------------

	public function render_slot( $slot_key ) {
		echo $this->build_slot_markup( $slot_key ); // phpcs:ignore -- build_slot_markup() only ever emits markup this class itself constructed from esc_*()'d values.
	}

	/**
	 * Pure builder (no echo) so the test harness — and, later, any REST/preview
	 * use — can assert on the returned markup directly. render_slot() above is
	 * the only thing that actually prints it on a real request.
	 */
	public function build_slot_markup( $slot_key ) {
		if ( ! Atlas_Chuti_Ad_Slots::exists( $slot_key ) ) {
			return '';
		}
		if ( ! $this->is_globally_enabled() ) {
			return '';
		}
		if ( $this->is_ad_free_context() ) {
			return '';
		}
		$slot   = Atlas_Chuti_Ad_Slots::get( $slot_key );
		$config = $this->slot_config( $slot_key );
		if ( empty( $config['enabled'] ) ) {
			return '';
		}

		$resolved = $this->resolve_source( $slot_key, $config, $this->short_locale() );
		if ( ! $resolved ) {
			return $this->is_debug_mode() && current_user_can( 'manage_options' ) ? $this->debug_placeholder( $slot_key, $slot ) : '';
		}

		$this->active_slots_on_page[ $slot_key ] = true;
		return $this->render_wrapper( $slot_key, $slot, $resolved );
	}

	/**
	 * Resolves ONE slot to either null (render nothing) or a small, uniform
	 * description of what to render — the only place source-type branching
	 * happens, so render_wrapper() never needs to know the difference.
	 */
	private function resolve_source( $slot_key, $config, $locale ) {
		if ( 'direct' === $config['source'] ) {
			$campaign_id = (int) $config['campaign_id'];
			$campaign    = $campaign_id
				? ( Atlas_Chuti_Ad_Campaign::instance()->is_active_for( $campaign_id, $slot_key, $locale ) ? get_post( $campaign_id ) : null )
				: Atlas_Chuti_Ad_Campaign::instance()->find_for_slot( $slot_key, $locale );
			if ( ! $campaign ) {
				return null;
			}
			return $this->direct_campaign_payload( $campaign->ID );
		}

		if ( 'external_network' === $config['source'] ) {
			if ( ! $this->can_load_advertising_provider() ) {
				return null;
			}
			return array( 'type' => 'external_network', 'slot_key' => $slot_key );
		}

		return null; // source 'none', or anything unrecognized — never render by accident.
	}

	private function direct_campaign_payload( $campaign_id ) {
		$image = get_the_post_thumbnail_url( $campaign_id, 'large' );
		if ( ! $image ) {
			return null; // a direct campaign with no creative image is never a real, renderable ad.
		}
		$click_url = Atlas_Chuti_Ad_Campaign::instance()->get_click_url( $campaign_id );
		if ( ! $click_url || ! wp_http_validate_url( $click_url ) ) {
			return null; // item 33: an invalid/missing click URL is rejected, never rendered as a dead link.
		}
		$alt = Atlas_Chuti_Ad_Campaign::instance()->get_alt_text( $campaign_id );
		return array(
			'type'      => 'direct',
			'image'     => $image,
			'click_url' => $click_url,
			'alt'       => $alt ? $alt : get_the_title( $campaign_id ),
		);
	}

	/**
	 * The one shared markup shape for every non-GATE slot — a reserved-size
	 * wrapper (CLS, item 21), a localized "Reklama"/"Advertisement" label
	 * (item 29/30 — never omitted, never a misleading editorial-sounding
	 * label), lazy-loading opt-in via a data attribute the vanilla JS observer
	 * (assets/js/ads.js) reads (item 22), and — for a direct campaign — a real
	 * `rel="sponsored noopener"` link (item 26).
	 */
	private function render_wrapper( $slot_key, $slot, $resolved ) {
		$label = esc_html( $this->ad_label() );
		$classes = array(
			'atlas-ad-slot',
			'atlas-ad-slot--' . sanitize_html_class( $slot['reserved'] ),
			Atlas_Chuti_Ad_Slots::DEVICE_DESKTOP === $slot['device'] ? 'atlas-ad-slot--desktop-only' : '',
		);
		$attrs = sprintf(
			'class="%1$s" data-ad-slot="%2$s"%3$s',
			esc_attr( trim( implode( ' ', array_filter( $classes ) ) ) ),
			esc_attr( $slot_key ),
			$slot['lazy'] ? ' data-ad-lazy="1"' : ''
		);

		if ( 'external_network' === $resolved['type'] ) {
			// No real provider is wired up in this step (item 18) — this branch is
			// unreachable in practice until a real provider_config() is supplied,
			// but it's written now so the reserved-space/lazy/consent plumbing is
			// already correct the day a real one is. The provider's own script
			// (maybe_enqueue_provider_script()) is what would fill this container.
			return sprintf(
				'<div %1$s><span class="atlas-ad-label">%2$s</span><div class="atlas-ad-slot-body" data-ad-provider-target="%3$s"></div></div>',
				$attrs,
				$label,
				esc_attr( $slot_key )
			);
		}

		return sprintf(
			'<div %1$s><span class="atlas-ad-label">%2$s</span><a class="atlas-ad-creative" href="%3$s" target="_blank" rel="sponsored noopener"><img src="%4$s" alt="%5$s" loading="%6$s" width="1200" height="630"></a></div>',
			$attrs,
			$label,
			esc_url( $resolved['click_url'] ),
			esc_url( $resolved['image'] ),
			esc_attr( $resolved['alt'] ),
			$slot['lazy'] ? 'lazy' : 'eager'
		);
	}

	/**
	 * Admin/staging-only outline showing the slot's own key (item 34) — never
	 * shown to an ordinary visitor (current_user_can('manage_options') is
	 * checked by the only caller, build_slot_markup(), before this is reached),
	 * default OFF (debug_mode defaults to false in settings()).
	 */
	private function debug_placeholder( $slot_key, $slot ) {
		return sprintf(
			'<div class="atlas-ad-slot atlas-ad-slot--debug atlas-ad-slot--%1$s" data-ad-slot="%2$s"><code>%2$s</code> <span>(%3$s — debug)</span></div>',
			esc_attr( sanitize_html_class( $slot['reserved'] ) ),
			esc_attr( $slot_key ),
			esc_html( $slot['context'] )
		);
	}

	// -------------------------------------------------------------------
	// GATE (desktop wallpaper) — item 5-8
	// -------------------------------------------------------------------

	/**
	 * Hooked on wp_body_open (item: no wrapping div exists around <header>+
	 * <main>+<footer> today — see the Step 7 audit — so a fixed-position
	 * overlay injected right after <body> opens is the least invasive way to
	 * add a wallpaper skin without restructuring header.php/footer.php). Two
	 * `position: fixed` panels, each constrained to the dead space OUTSIDE
	 * `.container`'s max-width — never inside it, so this can never affect
	 * `.container`'s own width/position (item 6: central content wrapper stays
	 * stable) and never needs to be an invisible full-viewport overlay (item 7).
	 */
	public function render_gate() {
		if ( ! Atlas_Chuti_Ad_Slots::exists( 'gate_desktop' ) || ! $this->is_gate_globally_enabled() ) {
			return;
		}
		if ( $this->is_ad_free_context() ) {
			return;
		}
		$config = $this->slot_config( 'gate_desktop' );
		if ( empty( $config['enabled'] ) ) {
			return;
		}
		$resolved = $this->resolve_source( 'gate_desktop', $config, $this->short_locale() );
		if ( ! $resolved || 'direct' !== $resolved['type'] ) {
			return; // GATE only ever supports a direct campaign in this step (item 8: "pokud je source external network, gate může být deaktivovaný").
		}
		$this->active_slots_on_page['gate_desktop'] = true;

		$label = esc_html( $this->ad_label() );
		printf(
			'<div class="atlas-gate" aria-hidden="false">' .
			'<a class="atlas-gate-panel atlas-gate-panel--left" href="%1$s" target="_blank" rel="sponsored noopener" aria-label="%2$s" style="background-image:url(%3$s);"><span class="atlas-ad-label">%4$s</span></a>' .
			'<a class="atlas-gate-panel atlas-gate-panel--right" href="%1$s" target="_blank" rel="sponsored noopener" aria-label="%2$s" style="background-image:url(%3$s);"><span class="atlas-ad-label">%4$s</span></a>' .
			'</div>',
			esc_url( $resolved['click_url'] ),
			esc_attr( $resolved['alt'] ),
			esc_url( $resolved['image'] ),
			$label
		);
	}

	// -------------------------------------------------------------------
	// Asset loading (item 22-23, 39)
	// -------------------------------------------------------------------

	/**
	 * The lazy-load observer script — enqueued unconditionally but tiny
	 * (vanilla JS, no dependency, item 39's own performance budget) since
	 * whether any slot on THIS page actually needs it is only known after the
	 * whole page has rendered; it no-ops instantly if it finds nothing to
	 * observe. CSS for slot/GATE structure is part of the theme's own
	 * main.css (presentation stays in the theme, per this project's
	 * established plugin/theme split), not enqueued from here.
	 */
	public function maybe_enqueue_assets() {
		if ( ! $this->is_globally_enabled() ) {
			return;
		}
		wp_enqueue_script(
			'atlas-chuti-ads',
			ATLAS_CHUTI_URL . 'assets/js/ads.js',
			array(),
			ATLAS_CHUTI_VERSION,
			true
		);
	}

	/**
	 * Item 23: the external provider's own script loads AT MOST ONCE, and only
	 * when at least one external_network slot actually resolved to something
	 * on THIS page — never speculatively, never once per slot.
	 */
	public function maybe_enqueue_provider_script() {
		if ( $this->provider_script_enqueued ) {
			return;
		}
		$has_external_slot = false;
		foreach ( $this->active_slots_on_page as $slot_key => $_ ) {
			$config = $this->slot_config( $slot_key );
			if ( 'external_network' === $config['source'] ) {
				$has_external_slot = true;
				break;
			}
		}
		if ( ! $has_external_slot || ! $this->can_load_advertising_provider() ) {
			return;
		}
		$script_url = apply_filters( 'atlas_chuti_ad_provider_script_url', '' );
		if ( ! $script_url ) {
			return;
		}
		printf( '<script async src="%s"></script>' . "\n", esc_url( $script_url ) );
		$this->provider_script_enqueued = true;
	}
}
