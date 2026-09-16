<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CHECKPOINT 10E.1: the real, reviewed "World Classics" (EN) / "Světová
 * klasika" (CZ) editorial curation — approved by the user, plugging into
 * the filter `inc/homepage.php`'s atlas_chuti_world_classics_recipe_keys()
 * has exposed (empty by default) since Checkpoint 10B.
 *
 * Every value below is a `recipe_key` exactly as it exists in
 * `production-data/europe-1/` (Checkpoint 10A) — NEVER a title or slug,
 * matching the same language-neutral identity class-json-importer.php
 * resolves `related_recipes` by (see class-i18n.php's find_by_recipe_key()).
 * `atlas_chuti_home_world_classics()` resolves each key to a real,
 * published post in the CURRENT locale and simply skips any key with no
 * post there — this list is CZ+EN concept-level shared on purpose (one
 * curated list, two localized headings: "Světová klasika" on .cz,
 * "World Classics" on .com), never two separate technical lists.
 *
 * Selection principle: dishes genuinely recognized internationally by
 * name (not merely "typical of their country," and never ranked or
 * described as "most popular" — no popularity metric exists or is implied
 * anywhere in this list). Every entry below was verified, at the time this
 * list was authored, to exist in BOTH `production-data/europe-1/` (cs-CZ)
 * and `production-data/europe-1-en/` (en) — see
 * tests/harness-checkpoint-10e1-editorial-curation.php for the automated
 * check that keeps this true. 14 keys across 10 countries — a deliberately
 * curated subset of the 100-recipe batch, not an attempt at one-per-country
 * coverage.
 *
 * Czech-first .cz homepage behavior does NOT depend on this list — it is
 * already served by `atlas_chuti_home_czech_country()` (ISO-code lookup,
 * `country_iso = CZ`), which stays live/data-driven rather than a second,
 * parallel "Czech Classics" curated recipe_key[] (see the Checkpoint 10E.1
 * report, section E, for why a second list was deliberately NOT added).
 */
function atlas_chuti_editorial_world_classics_recipe_keys() {
	return array(
		'svickova',            // CZ — Svíčková na smetaně / Czech Beef Sirloin in Cream Sauce
		'wiener-schnitzel',    // AT — Vídeňský řízek / Wiener Schnitzel
		'gulyas',              // HU — Gulyás / Hungarian Goulash Soup
		'spaghetti-carbonara', // IT — Spaghetti Carbonara
		'pizza-margherita',    // IT — Pizza Margherita
		'boeuf-bourguignon',   // FR — Hovězí po burgundsku / Beef Bourguignon
		'ratatouille',         // FR — Ratatouille
		'paella-valenciana',   // ES — Paella Valenciana
		'gazpacho',            // ES — Gazpacho
		'moussaka',            // GR — Moussaka
		'tzatziki',            // GR — Tzatziki
		'fish-and-chips',      // GB — Fish and chips
		'pierogi-ruskie',      // PL — Pierogi ruskie
		'kottbullar',          // SE — Švédské masové kuličky / Swedish Meatballs (Köttbullar)
	);
}
add_filter( 'atlas_chuti_world_classics_recipe_keys', 'atlas_chuti_editorial_world_classics_recipe_keys' );
