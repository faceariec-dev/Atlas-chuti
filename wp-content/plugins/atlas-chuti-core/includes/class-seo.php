<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Technical SEO baseline: document title, meta description, canonical, robots,
 * OpenGraph, WebSite/Recipe/BreadcrumbList structured data — built only from real
 * structured fields, never invented. Stays out of the way if a full SEO plugin
 * (Yoast, RankMath, SEOPress...) is active, or if `atlas_chuti_disable_builtin_seo`
 * is filtered true (a safe manual off-switch for later, without uninstalling
 * anything). Sitemap filters register independently of that switch — they're just a
 * defensive confirmation of the post type/taxonomy `public` flags already set
 * elsewhere, not something a real SEO plugin would need turned off.
 */
class Atlas_Chuti_SEO {

	/**
	 * Recipe archive filter query vars (see theme's inc/archive-filters.php). Kept
	 * here as plain query var names — not a call into theme code — so this class
	 * stays correct under any theme built on the same data model, per this plugin's
	 * "theme-independent" design.
	 */
	const RECIPE_FILTER_QUERY_VARS = array( 'zeme', 'svetadil', 'typ', 'obtiznost', 'dieta', 'cas' );

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'wp_sitemaps_post_types', array( $this, 'filter_sitemap_post_types' ) );
		add_filter( 'wp_sitemaps_taxonomies', array( $this, 'filter_sitemap_taxonomies' ) );

		if ( $this->seo_plugin_active() ) {
			return;
		}
		add_filter( 'document_title_parts', array( $this, 'filter_document_title_parts' ) );
		add_action( 'wp_head', array( $this, 'output_meta' ), 1 );
		add_action( 'wp_head', array( $this, 'output_schema' ), 5 );
	}

	private function seo_plugin_active() {
		if ( apply_filters( 'atlas_chuti_disable_builtin_seo', false ) ) {
			return true;
		}
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_FILE' ) || class_exists( 'SEOPress' );
	}

	// ---------------------------------------------------------------------
	// Document <title>
	// ---------------------------------------------------------------------

	/**
	 * The correct hook for overriding the real HTML <title> (item 1): `wp_title()` is
	 * legacy and many themes don't even call it (this one uses `add_theme_support(
	 * 'title-tag' )`, which renders via `wp_get_document_title()` → this filter).
	 * Falls through to WordPress's normal title when no custom `atlas_seo_title` is
	 * set, so nothing changes for the vast majority of pages.
	 */
	public function filter_document_title_parts( $parts ) {
		if ( is_singular( array( 'atlas_recipe', 'atlas_country', 'atlas_glossary' ) ) ) {
			$custom = get_post_meta( get_the_ID(), 'atlas_seo_title', true );
			if ( $custom ) {
				$parts['title'] = $custom;
			}
		}
		return $parts;
	}

	// ---------------------------------------------------------------------
	// <meta> description / canonical / robots / OpenGraph
	// ---------------------------------------------------------------------

	/**
	 * Never returns raw HTML/shortcodes — strip_shortcodes() first (so a stray
	 * `[gallery]` never leaks into a meta description), then wp_strip_all_tags(), per
	 * item 10 of this phase's brief.
	 */
	private function clean_text( $text ) {
		return trim( wp_strip_all_tags( strip_shortcodes( (string) $text ) ) );
	}

	private function get_meta_description() {
		if ( is_singular( array( 'atlas_recipe', 'atlas_country', 'atlas_glossary' ) ) ) {
			$post_id = get_the_ID();
			$custom  = get_post_meta( $post_id, 'atlas_meta_description', true );
			if ( $custom ) {
				return $this->clean_text( $custom );
			}
			$excerpt = get_post_meta( $post_id, 'atlas_excerpt', true );
			if ( $excerpt ) {
				return $this->clean_text( $excerpt );
			}
			$intro = get_post_meta( $post_id, 'atlas_intro', true );
			if ( $intro ) {
				return wp_trim_words( $this->clean_text( $intro ), 30 );
			}
			$short = get_post_meta( $post_id, 'atlas_short_definition', true );
			if ( $short ) {
				return $this->clean_text( $short );
			}
		}
		// KROK 6, item 11: Magazín article meta description — the real editorial
		// excerpt (manual excerpt, or WordPress's own auto-generated one), never a
		// generated-from-nothing placeholder.
		if ( is_singular( 'post' ) ) {
			$excerpt = get_the_excerpt( get_the_ID() );
			if ( $excerpt ) {
				return $this->clean_text( $excerpt );
			}
		}
		// KROK 9, item 5: every Diskuze topic was falling through to the generic
		// site description (audit finding — a duplicate-meta-description risk
		// across the whole /diskuze/ corpus). The topic's own real first post
		// (post_content) is real, topic-specific content, never invented.
		if ( is_singular( 'atlas_topic' ) ) {
			$excerpt = get_the_excerpt( get_the_ID() );
			if ( $excerpt ) {
				return $this->clean_text( $excerpt );
			}
			$content = get_post_field( 'post_content', get_the_ID() );
			if ( $content ) {
				return wp_trim_words( $this->clean_text( $content ), 30 );
			}
		}
		return $this->clean_text( get_bloginfo( 'description' ) );
	}

	private function get_seo_title() {
		if ( is_singular( array( 'atlas_recipe', 'atlas_country', 'atlas_glossary' ) ) ) {
			$custom = get_post_meta( get_the_ID(), 'atlas_seo_title', true );
			if ( $custom ) {
				return $custom;
			}
		}
		return wp_get_document_title();
	}

	/**
	 * Whether the CURRENT request is the recipe archive with at least one filter
	 * query var set (item 5 of this phase's brief) — /recepty/?obtiznost=easy etc.
	 * The filtering itself stays fully functional for visitors; this only affects
	 * indexing signals.
	 */
	private function has_active_recipe_filters() {
		foreach ( self::RECIPE_FILTER_QUERY_VARS as $var ) {
			if ( '' !== (string) get_query_var( $var ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Canonical URL for the current request, built only from real public routes
	 * (item 4) — never invented, never pointing at a technical/internal URL. Base
	 * archives self-canonicalize (including their own pagination); a filtered recipe
	 * archive collapses to the unfiltered base archive (item 5), since we don't want
	 * every filter combination treated as its own canonical page yet.
	 */
	private function get_canonical_url() {
		if ( is_singular() ) {
			return get_permalink();
		}

		if ( is_post_type_archive( 'atlas_recipe' ) && $this->has_active_recipe_filters() ) {
			return get_post_type_archive_link( 'atlas_recipe' );
		}

		if ( is_post_type_archive( 'atlas_recipe' ) || is_post_type_archive( 'atlas_glossary' ) || is_post_type_archive( 'atlas_topic' )
			|| is_tax( 'atlas_continent' ) || is_category() || is_search() || is_home() || is_front_page() ) {
			$paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
			if ( $paged > 1 ) {
				// get_pagenum_link() is aware of the current archive/search/home
				// context on its own — it's the same helper paginate_links() uses.
				return get_pagenum_link( $paged, false );
			}
			if ( is_post_type_archive( 'atlas_recipe' ) ) {
				return get_post_type_archive_link( 'atlas_recipe' );
			}
			if ( is_post_type_archive( 'atlas_glossary' ) ) {
				return get_post_type_archive_link( 'atlas_glossary' );
			}
			if ( is_post_type_archive( 'atlas_topic' ) ) {
				// KROK 6, item 32: Diskuze archive self-canonicalizes exactly like the
				// recipe/glossary archives above — atlas_chuti_discussion_url() is the
				// same get_post_type_archive_link() call, kept in one place (functions.php)
				// so template code and this canonical logic never drift apart.
				return atlas_chuti_discussion_url();
			}
			if ( is_tax( 'atlas_continent' ) ) {
				$link = get_term_link( get_queried_object() );
				return is_wp_error( $link ) ? '' : $link;
			}
			if ( is_category() ) {
				// KROK 6, item 11: Magazín category archive (incl. Tipy a triky)
				// self-canonicalizes the same way every other real archive here does.
				$link = get_term_link( get_queried_object() );
				return is_wp_error( $link ) ? '' : $link;
			}
			if ( is_search() ) {
				return get_search_link( get_search_query() );
			}
			return home_url( '/' );
		}

		return '';
	}

	/**
	 * Robots directive for pages we don't want indexed YET but that must stay
	 * perfectly crawlable/functional for visitors and for link equity (item 5/6):
	 * search results (near-duplicate by nature) and a recipe archive with an active
	 * filter (an unbounded combination of query params we're not ready to index).
	 */
	private function get_robots_directive() {
		if ( is_search() ) {
			return 'noindex,follow';
		}
		// KROK 8, item 4/47: Cook Mode is a state of the same recipe URL
		// (?cook=1), never its own indexable duplicate — canonical already
		// self-resolves to the query-string-free permalink (get_canonical_url()
		// below uses get_permalink(), which never includes the query string), this
		// just adds the matching noindex.
		if ( is_singular( 'atlas_recipe' ) && isset( $_GET['cook'] ) && '1' === $_GET['cook'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI-state flag.
			return 'noindex,follow';
		}
		// KROK 5, item 36: login/registration/account/password-reset all render
		// through ONE page template (template-my-atlas.php) — never a browsable
		// landing page, never a public user directory. KROK 8, item 19/47: the two
		// new recommendation/finder utility templates join the same noindex
		// treatment — dynamic, filter-heavy result pages, not real landing content.
		if ( is_page_template( array( 'template-my-atlas.php', 'template-co-dnes-varit.php', 'template-co-mam-doma.php' ) ) ) {
			return 'noindex,follow';
		}
		if ( is_post_type_archive( 'atlas_recipe' ) && $this->has_active_recipe_filters() ) {
			return 'noindex,follow';
		}
		// KROK 6, item 34: an empty Magazín category or an empty Diskuze archive
		// shows a graceful message to visitors (never fake content) but isn't worth
		// indexing while there's genuinely nothing on it — becomes indexable again
		// automatically the moment real content exists, no manual flag to flip.
		if ( ( is_category() || is_post_type_archive( 'atlas_topic' ) ) && isset( $GLOBALS['wp_query'] ) && 0 === (int) $GLOBALS['wp_query']->found_posts ) {
			return 'noindex,follow';
		}
		return '';
	}

	public function output_meta() {
		$description = $this->get_meta_description();
		$title       = $this->get_seo_title();
		$canonical   = $this->get_canonical_url();
		$robots      = $this->get_robots_directive();
		$locale_urls = $this->get_locale_urls();

		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $description ) );
		if ( $robots ) {
			printf( '<meta name="robots" content="%s">' . "\n", esc_attr( $robots ) );
		}
		if ( $canonical ) {
			printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $canonical ) );
		}
		$this->output_hreflang( $locale_urls );

		printf( '<meta property="og:type" content="%s">' . "\n", is_singular( array( 'atlas_recipe', 'post' ) ) ? 'article' : 'website' );
		printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $description ) );
		printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
		if ( $canonical ) {
			printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $canonical ) );
		}
		if ( is_singular() && has_post_thumbnail() ) {
			// og:image only when a real photo exists (item 11) — never an empty/fake
			// image URL. atlas-hero (16:9) matches the aspect ratio social platforms
			// expect, and is guaranteed registered (see atlas_chuti_setup()).
			printf( '<meta property="og:image" content="%s">' . "\n", esc_url( get_the_post_thumbnail_url( get_the_ID(), 'atlas-hero' ) ) );
		}
		printf( '<meta property="og:locale" content="%s">' . "\n", esc_attr( $this->og_locale_tag( Atlas_Chuti_I18N::current_locale() ) ) );
		foreach ( $locale_urls as $locale => $url ) {
			if ( $locale !== Atlas_Chuti_I18N::current_locale() ) {
				printf( '<meta property="og:locale:alternate" content="%s">' . "\n", esc_attr( $this->og_locale_tag( $locale ) ) );
			}
		}
	}

	/**
	 * KROK 4, item 17: real translation URLs for the CURRENT singular post/term,
	 * keyed by our internal locale tag — includes the current locale's own
	 * canonical URL, plus every OTHER supported locale Polylang confirms an
	 * actual, published translation for (mirrors
	 * Atlas_Chuti_Polylang_Bridge::switcher_data()'s "never a fake/guessed
	 * translation" rule — this is what makes it safe to feed straight into both
	 * hreflang and og:locale:alternate). Empty array whenever Polylang isn't
	 * active, the current request isn't a singular post/term, or no real
	 * translation pair exists yet (the normal case for nearly all of this site
	 * today) — hreflang/og:locale:alternate are never emitted from a guess.
	 */
	private function get_locale_urls() {
		if ( ! class_exists( 'Atlas_Chuti_Polylang_Bridge' ) || ! Atlas_Chuti_Polylang_Bridge::is_active() ) {
			return array();
		}
		$is_singular_page = is_singular();
		$is_term_page      = is_tax() || is_category() || is_tag();
		if ( ! $is_singular_page && ! $is_term_page ) {
			return array();
		}

		$current_locale = Atlas_Chuti_I18N::current_locale();
		$urls            = array( $current_locale => $this->get_canonical_url() );

		foreach ( Atlas_Chuti_I18N::SUPPORTED_LOCALES as $locale ) {
			if ( $locale === $current_locale ) {
				continue;
			}
			$url = '';
			if ( $is_singular_page ) {
				$translated_id = Atlas_Chuti_Polylang_Bridge::get_post_translation_id( get_queried_object_id(), $locale );
				if ( $translated_id && 'publish' === get_post_status( $translated_id ) ) {
					$url = get_permalink( $translated_id );
				}
			} else {
				$term = get_queried_object();
				if ( $term instanceof WP_Term ) {
					$translated_id = Atlas_Chuti_Polylang_Bridge::get_term_translation_id( $term->term_id, $locale );
					if ( $translated_id ) {
						$link = get_term_link( $translated_id, $term->taxonomy );
						if ( ! is_wp_error( $link ) ) {
							$url = $link;
						}
					}
				}
			}
			if ( $url ) {
				$urls[ $locale ] = $url;
			}
		}
		// A lone self-entry isn't a real multilingual signal — only return
		// anything once there's at least one confirmed real alternate.
		return count( $urls ) > 1 ? $urls : array();
	}

	/**
	 * hreflang, reciprocal by construction (both directions come from the same
	 * Polylang translation relation — see get_locale_urls()) — cs/en for every
	 * real pair, plus x-default pointing at the default locale's URL (item 17).
	 * Never emitted for a lone page with no real translation (get_locale_urls()
	 * already returns empty in that case).
	 *
	 * CHECKPOINT 10B, cross-domain x-default policy: x-default stays pointed at
	 * Atlas_Chuti_I18N::DEFAULT_LOCALE (cs-CZ), i.e. atlaschuti.cz — no code
	 * change was needed to keep this, since get_locale_urls() already resolves
	 * every locale's URL through the now host-aware get_canonical_url()/
	 * get_permalink() (see class-domain-map.php's `home_url` filter), so this
	 * line already emits the correct https://atlaschuti.cz/... URL. Deliberately
	 * NOT switched to atlaschuti.com: DEFAULT_LOCALE is threaded through this
	 * entire codebase as "the locale with no explicit signal" (scope_query_to_
	 * locale()'s legacy-content OR-fallback, the importer's locale defaults,
	 * every pre-10B test fixture) — changing what x-default points at, without
	 * also redefining DEFAULT_LOCALE itself (a far larger, out-of-scope change
	 * for this checkpoint), would decouple "the SEO default" from "the actual
	 * content default" and risk exactly the inconsistency the brief warns
	 * about ("zdokumentuj a otestuj to konzistentně"). See checkpoint-10b
	 * report section E for the full rationale and test coverage.
	 */
	private function output_hreflang( $locale_urls ) {
		if ( ! $locale_urls ) {
			return;
		}
		foreach ( $locale_urls as $locale => $url ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s">' . "\n",
				esc_attr( Atlas_Chuti_Polylang_Bridge::locale_to_slug( $locale ) ),
				esc_url( $url )
			);
		}
		if ( isset( $locale_urls[ Atlas_Chuti_I18N::DEFAULT_LOCALE ] ) ) {
			printf( '<link rel="alternate" hreflang="x-default" href="%s">' . "\n", esc_url( $locale_urls[ Atlas_Chuti_I18N::DEFAULT_LOCALE ] ) );
		}
	}

	/**
	 * Our internal locale tag (cs-CZ/en) translated to the OpenGraph spec's OWN
	 * convention (cs_CZ/en_US, underscore) — a DIFFERENT convention from ours,
	 * so this is never a plain string replace.
	 */
	private function og_locale_tag( $locale ) {
		$map = array(
			'cs-CZ' => 'cs_CZ',
			'en'    => 'en_US',
		);
		return $map[ $locale ] ?? str_replace( '-', '_', $locale );
	}

	// ---------------------------------------------------------------------
	// Structured data
	// ---------------------------------------------------------------------

	public function output_schema() {
		$graphs = array();

		// CHECKPOINT 10B: the Organization entity is the shared BRAND, one
		// per site regardless of which of the two domains (atlaschuti.cz /
		// atlaschuti.com) is serving the current request — its @id/url must
		// therefore be a FIXED host, never home_url() (which is now
		// host-aware per class-domain-map.php and would otherwise mint a
		// second, different "@id" on the other domain, i.e. two
		// Organizations for one brand). WebSite below stays per-host on
		// purpose — that legitimately IS two different WebSite entities
		// (one per domain), both published by this one Organization.
		$organization = array(
			'@type' => 'Organization',
			'@id'   => Atlas_Chuti_Domain_Map::brand_url() . '#organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => Atlas_Chuti_Domain_Map::brand_url(),
		);
		// KROK 9, item 17: only a REAL configured logo — never a guessed/
		// hardcoded image path. has_custom_logo() is false today (none is set
		// anywhere in this repo, confirmed by audit), so this stays inert until
		// an admin actually sets one in Customizer — automatic then, no code
		// change needed.
		$logo_url = $this->organization_logo_url();
		if ( $logo_url ) {
			$organization['logo'] = $logo_url;
		}
		$graphs[] = $organization;

		$graphs[] = array(
			'@type' => 'WebSite',
			// Per-host on purpose (this domain's own WebSite entity) —
			// home_url() here is correct, unlike the fixed Organization
			// above.
			'@id'   => home_url( '/#website' ),
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
			'publisher' => array( '@id' => Atlas_Chuti_Domain_Map::brand_url() . '#organization' ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => home_url( '/?s={search_term_string}' ),
				'query-input' => 'required name=search_term_string',
			),
		);

		if ( is_singular( 'atlas_recipe' ) ) {
			$graphs[] = $this->recipe_schema( get_the_ID() );
		}

		if ( is_singular( 'post' ) ) {
			$graphs[] = $this->article_schema( get_the_ID() );
		}

		// KROK 9, item 16: Discussion topics are genuinely real UGC Q&A/thread
		// content (real author, real post_content, real WP core comment count) —
		// a minimal, valid DiscussionForumPosting is safely groundable, unlike a
		// half-built schema guessed from thin data.
		if ( is_singular( 'atlas_topic' ) ) {
			$graphs[] = $this->discussion_schema( get_the_ID() );
		}

		$breadcrumb = $this->breadcrumb_schema();
		if ( $breadcrumb ) {
			$graphs[] = $breadcrumb;
		}

		$graphs = array_filter( $graphs );
		if ( empty( $graphs ) ) {
			return;
		}

		echo '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array_values( $graphs ),
			)
		) . '</script>' . "\n";
	}

	/**
	 * Recipe JSON-LD built only from fields we actually store (item 2 of this
	 * phase's brief) — deliberately no ratings/reviews/nutrition/calories, since we
	 * have none of that data and won't fabricate it.
	 */
	private function recipe_schema( $post_id ) {
		$ingredients = get_post_meta( $post_id, 'atlas_ingredients', true );
		$steps       = get_post_meta( $post_id, 'atlas_steps', true );
		$prep        = (int) get_post_meta( $post_id, 'atlas_prep_minutes', true );
		$cook        = (int) get_post_meta( $post_id, 'atlas_cook_minutes', true );
		$total       = (int) get_post_meta( $post_id, 'atlas_total_minutes', true );

		$schema = array(
			'@type'       => 'Recipe',
			'name'        => get_the_title( $post_id ),
			'description' => $this->clean_text( get_post_meta( $post_id, 'atlas_excerpt', true ) ),
			'url'         => get_permalink( $post_id ),
			// KROK 4, item 17: optional inLanguage — this post's OWN locale (not the
			// current request's, so a schema fetched cross-locale is never wrong),
			// as a plain BCP 47 subtag (Atlas_Chuti_Polylang_Bridge::locale_to_slug()
			// is a pure lookup, safe to call whether or not Polylang is active).
			'inLanguage'  => Atlas_Chuti_Polylang_Bridge::locale_to_slug( Atlas_Chuti_I18N::get_locale( $post_id ) ),
		);

		$this->add_recipe_images( $schema, $post_id );

		$author_id = (int) get_post_field( 'post_author', $post_id );
		if ( $author_id ) {
			$author_name = get_the_author_meta( 'display_name', $author_id );
			if ( $author_name ) {
				$schema['author'] = array( '@type' => 'Person', 'name' => $author_name );
			}
		}

		$published = get_the_date( 'c', $post_id );
		if ( $published ) {
			$schema['datePublished'] = $published;
		}
		$modified = get_the_modified_date( 'c', $post_id );
		if ( $modified ) {
			$schema['dateModified'] = $modified;
		}

		if ( $prep ) {
			$schema['prepTime'] = 'PT' . $prep . 'M';
		}
		if ( $cook ) {
			$schema['cookTime'] = 'PT' . $cook . 'M';
		}
		if ( $total ) {
			$schema['totalTime'] = 'PT' . $total . 'M';
		}

		$servings = get_post_meta( $post_id, 'atlas_servings_default', true );
		if ( $servings ) {
			$schema['recipeYield'] = (string) $servings;
		}

		if ( is_array( $ingredients ) && $ingredients ) {
			$schema['recipeIngredient'] = array_values(
				array_filter(
					array_map(
						function ( $i ) {
							return trim( ( isset( $i['quantity'] ) ? $i['quantity'] . ' ' . ( $i['unit'] ?? '' ) . ' ' : '' ) . ( $i['display_name'] ?? '' ) );
						},
						$ingredients
					)
				)
			);
		}

		if ( is_array( $steps ) && $steps ) {
			$schema['recipeInstructions'] = array_map(
				function ( $s ) {
					return array(
						'@type' => 'HowToStep',
						'text'  => isset( $s['text'] ) ? wp_strip_all_tags( $s['text'] ) : '',
					);
				},
				$steps
			);
		}

		$this->add_recipe_category( $schema, $post_id );
		$this->add_recipe_cuisine( $schema, $post_id );
		$this->add_recipe_aggregate_rating( $schema, $post_id );
		$this->add_video( $schema, $post_id );

		return $schema;
	}

	/**
	 * KROK 8, item 33: nested `video` property, built only from real,
	 * validated video data (Atlas_Chuti_Video::schema() itself already
	 * returns null for anything unresolvable/unsupported) — omitted entirely
	 * when there is no real video, never a placeholder VideoObject.
	 */
	private function add_video( &$schema, $post_id ) {
		if ( ! class_exists( 'Atlas_Chuti_Video' ) ) {
			return;
		}
		$video = Atlas_Chuti_Video::schema( $post_id );
		if ( $video ) {
			$schema['video'] = $video;
		}
	}

	/**
	 * KROK 5, items 18/35: aggregateRating from REAL stored votes only (recipe_key,
	 * shared across every locale variant of this recipe — the schema for the CZ
	 * post and the EN post of "the same" recipe report the identical aggregate).
	 * The property is entirely OMITTED when there are zero ratings — never a fake
	 * seed value, never ratingCount: 0 left in as a placeholder.
	 */
	private function add_recipe_aggregate_rating( &$schema, $post_id ) {
		if ( ! class_exists( 'Atlas_Chuti_Ratings' ) ) {
			return;
		}
		$recipe_key = get_post_meta( $post_id, 'atlas_recipe_key', true );
		if ( ! $recipe_key ) {
			return;
		}
		$aggregate = Atlas_Chuti_Ratings::instance()->get_aggregate( $recipe_key );
		if ( $aggregate['count'] < 1 ) {
			return;
		}
		$schema['aggregateRating'] = array(
			'@type'       => 'AggregateRating',
			'ratingValue' => $aggregate['average'],
			'ratingCount' => $aggregate['count'],
		);
	}

	/**
	 * Multiple real, already-existing image variants of the SAME featured photo
	 * (item 3): 16:9 (atlas-hero), 4:3 (atlas-card), 1:1 (atlas-square). Each is only
	 * included if WordPress actually generated that size for this attachment —
	 * wp_get_attachment_image_src() returns false otherwise, so this never emits a
	 * broken/fake URL.
	 */
	private function add_recipe_images( &$schema, $post_id ) {
		if ( ! has_post_thumbnail( $post_id ) ) {
			return;
		}
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		$images       = array();
		foreach ( array( 'atlas-hero', 'atlas-card', 'atlas-square' ) as $size ) {
			$src = wp_get_attachment_image_src( $thumbnail_id, $size );
			if ( $src ) {
				$images[] = $src[0];
			}
		}
		if ( $images ) {
			$schema['image'] = array_values( array_unique( $images ) );
		}
	}

	/**
	 * recipeCategory from the recipe's actual atlas_meal_type term(s) (item 2) — not
	 * invented, and never omitted-but-empty: the key is simply absent when the
	 * recipe has no meal type set.
	 */
	private function add_recipe_category( &$schema, $post_id ) {
		$terms = wp_get_post_terms( $post_id, 'atlas_meal_type' );
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			$schema['recipeCategory'] = implode( ', ', wp_list_pluck( $terms, 'name' ) );
		}
	}

	/**
	 * recipeCuisine using the tagged country's LOCALIZED display name for the
	 * current locale (item 2) — resolved via the country post itself
	 * (Atlas_Chuti_Country_Sync::get_country_post_for_term()), not the shared
	 * atlas_country_tax term's technical slug/name, which only reliably reflects
	 * cs-CZ today and will be shared across locale variants once English exists.
	 */
	private function add_recipe_cuisine( &$schema, $post_id ) {
		$terms = wp_get_post_terms( $post_id, 'atlas_country_tax' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return;
		}
		$locale   = Atlas_Chuti_I18N::current_locale();
		$cuisines = array();
		foreach ( $terms as $term ) {
			$country_post_id = Atlas_Chuti_Country_Sync::get_country_post_for_term( $term->term_id, $locale );
			if ( $country_post_id ) {
				$cuisines[] = get_the_title( $country_post_id );
			}
		}
		$cuisines = array_values( array_unique( array_filter( $cuisines ) ) );
		if ( $cuisines ) {
			$schema['recipeCuisine'] = $cuisines;
		}
	}

	/**
	 * KROK 6, item 11: Magazín Article JSON-LD — headline/description/image/author/
	 * datePublished/dateModified/mainEntityOfPage/inLanguage, built only from real
	 * post data (title, excerpt, featured image, author display name, WP dates) —
	 * no ratings/reviews/fabricated fields, mirroring recipe_schema()'s own
	 * "only what we actually store" rule.
	 */
	private function article_schema( $post_id ) {
		$schema = array(
			'@type'            => 'Article',
			'headline'         => get_the_title( $post_id ),
			'url'              => get_permalink( $post_id ),
			'mainEntityOfPage' => array( '@type' => 'WebPage', '@id' => get_permalink( $post_id ) ),
			'inLanguage'       => Atlas_Chuti_Polylang_Bridge::locale_to_slug( Atlas_Chuti_I18N::get_locale( $post_id ) ),
		);

		$description = $this->clean_text( get_the_excerpt( $post_id ) );
		if ( $description ) {
			$schema['description'] = $description;
		}

		if ( has_post_thumbnail( $post_id ) ) {
			$src = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'atlas-hero' );
			if ( $src ) {
				$schema['image'] = array( $src[0] );
			}
		}

		$author_id = (int) get_post_field( 'post_author', $post_id );
		if ( $author_id ) {
			$author_name = get_the_author_meta( 'display_name', $author_id );
			if ( $author_name ) {
				// Display name only (item 10: never a public author archive URL here —
				// see is_author() handling in inc/template-tags.php).
				$schema['author'] = array( '@type' => 'Person', 'name' => $author_name );
			}
		}

		$published = get_the_date( 'c', $post_id );
		if ( $published ) {
			$schema['datePublished'] = $published;
		}
		$modified = get_the_modified_date( 'c', $post_id );
		if ( $modified ) {
			$schema['dateModified'] = $modified;
		}

		$this->add_video( $schema, $post_id );

		return $schema;
	}

	/**
	 * KROK 9, item 16: minimal, valid DiscussionForumPosting — every field
	 * comes from a real WP core primitive (post_content, post_author,
	 * post_date/modified, get_comments_number()). No thin/half-built schema:
	 * if this project's topic model ever couldn't support these fields for
	 * real, this method simply wouldn't be called (see the item 16 note in
	 * the Step 9 report for why the model DOES support it safely).
	 */
	private function discussion_schema( $post_id ) {
		$schema = array(
			'@type'      => 'DiscussionForumPosting',
			'headline'   => get_the_title( $post_id ),
			'url'        => get_permalink( $post_id ),
			'inLanguage' => Atlas_Chuti_Polylang_Bridge::locale_to_slug( Atlas_Chuti_I18N::get_locale( $post_id ) ),
		);

		$text = $this->clean_text( get_post_field( 'post_content', $post_id ) );
		if ( $text ) {
			$schema['text'] = $text;
		}

		$author_id = (int) get_post_field( 'post_author', $post_id );
		if ( $author_id ) {
			$author_name = get_the_author_meta( 'display_name', $author_id );
			if ( $author_name ) {
				$schema['author'] = array( '@type' => 'Person', 'name' => $author_name );
			}
		}

		$published = get_the_date( 'c', $post_id );
		if ( $published ) {
			$schema['datePublished'] = $published;
		}
		$modified = get_the_modified_date( 'c', $post_id );
		if ( $modified ) {
			$schema['dateModified'] = $modified;
		}

		// A real, always-accurate count (including a true zero) — never a
		// fabricated placeholder, so no "omit at zero" rule applies here the
		// way it does for AggregateRating (item 21).
		$schema['interactionStatistic'] = array(
			'@type'                => 'InteractionCounter',
			'interactionType'      => 'https://schema.org/CommentAction',
			'userInteractionCount' => (int) get_comments_number( $post_id ),
		);

		return $schema;
	}

	/**
	 * Real configured Customizer logo only — never a guessed theme asset path.
	 */
	private function organization_logo_url() {
		if ( ! function_exists( 'has_custom_logo' ) || ! has_custom_logo() ) {
			return '';
		}
		$logo_id = get_theme_mod( 'custom_logo' );
		$src     = $logo_id ? wp_get_attachment_image_src( $logo_id, 'full' ) : false;
		return $src ? $src[0] : '';
	}

	private function breadcrumb_schema() {
		if ( is_front_page() ) {
			return null;
		}
		$items = atlas_chuti_get_breadcrumbs();
		if ( count( $items ) < 2 ) {
			return null;
		}
		$list = array();
		foreach ( $items as $i => $item ) {
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $item['label'],
				'item'     => $item['url'],
			);
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $list,
		);
	}

	// ---------------------------------------------------------------------
	// XML sitemap (WordPress core, wp-sitemap.xml) — item 7
	// ---------------------------------------------------------------------

	/**
	 * atlas_recipe/atlas_country/atlas_glossary are `public => true` and already
	 * included by WordPress core's default sitemap logic; atlas_ingredient
	 * (`public => false`, the internal dictionary) is already excluded by it. This
	 * filter is a defensive, explicit confirmation of that — not a workaround.
	 */
	public function filter_sitemap_post_types( $post_types ) {
		unset( $post_types['atlas_ingredient'] );
		// KROK 9, item 41: atlas_ad_campaign (`public => false`, Step 7) is
		// already excluded by WordPress core sitemap logic on that flag alone —
		// same defensive-confirmation reasoning as atlas_ingredient above, not a
		// workaround for an actual leak.
		unset( $post_types['atlas_ad_campaign'] );
		return $post_types;
	}

	/**
	 * atlas_continent and (KROK 6) `category` (the Magazín's own real, indexable
	 * archive pages — category.php) belong in the sitemap. Every technical taxonomy
	 * (atlas_country_tax, atlas_ingredient_tax, atlas_meal_type, atlas_difficulty,
	 * atlas_diet, atlas_glossary_category, atlas_topic_category) is already
	 * `public => false` and excluded by WordPress core by default — this filter just
	 * makes that explicit rather than relying only on the taxonomy registration args.
	 * atlas_topic_category is deliberately NOT added here even though it's this
	 * project's own taxonomy: it has no dedicated public archive template (Diskuze
	 * topics are filtered by category via a query var on /diskuze/, the same
	 * pattern as the recipe archive's own filters), so there is no indexable URL
	 * for it to point at.
	 */
	public function filter_sitemap_taxonomies( $taxonomies ) {
		return array_intersect_key( $taxonomies, array( 'atlas_continent' => true, 'category' => true ) );
	}
}
