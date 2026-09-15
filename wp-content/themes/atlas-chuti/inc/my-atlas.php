<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 5: theme-facing helpers for "Můj Atlas" — thin wrappers that call the
 * plugin's own service classes (Atlas_Chuti_User_State/Ratings/Comments/Photos)
 * and resolve their stable recipe_key/ISO identities into an actual, current-locale
 * post to link to. This is where the "theme = presentation, plugin = data model"
 * split (maintained since Step 1) puts the resolve-a-stable-key-into-a-real-post
 * step — the template itself only loops over already-resolved WP_Post objects.
 */

/**
 * Which section of the one-page "Můj Atlas" account area to render — a plain GET
 * query var, not a rewrite endpoint (item 6: reload/back/forward must all just
 * work, and they do for free with a real query string; no JS routing needed for
 * the base experience to work).
 */
function atlas_chuti_account_section() {
	$requested = isset( $_GET['sekce'] ) ? sanitize_key( wp_unslash( $_GET['sekce'] ) ) : '';
	// KROK 6, item 21: "Moje témata" added directly (not deferred) — the resolver
	// infrastructure this needed (Atlas_Chuti_Discussion::get_user_topics(), same
	// shape as Krok 5's own get_user_recipe_comments()) already existed, so the
	// marginal scope here was small enough not to warrant deferring it.
	$logged_in_sections  = array( 'prehled', 'oblibene', 'uvarene', 'pas', 'hodnoceni', 'komentare', 'fotografie', 'temata', 'nastaveni' );
	$logged_out_sections = array( 'prihlaseni', 'registrace', 'zapomenute-heslo', 'nove-heslo' );

	if ( is_user_logged_in() ) {
		return in_array( $requested, $logged_in_sections, true ) ? $requested : 'prehled';
	}
	return in_array( $requested, $logged_out_sections, true ) ? $requested : 'prihlaseni';
}

/**
 * Item 31: resolves a stable recipe_key to a REAL post — current locale first,
 * falling back to the default locale, then to any supported locale that still
 * has it, so an already-favorited recipe that hasn't (yet) got an EN translation
 * still shows up (linking to its CZ version) instead of silently vanishing from
 * the list or producing a broken URL. Returns null only if no locale has it at
 * all (e.g. the recipe was later deleted) — callers must skip that item.
 */
function atlas_chuti_resolve_recipe_key( $recipe_key ) {
	$locale = Atlas_Chuti_I18N::current_locale();
	$post   = Atlas_Chuti_I18N::find_by_recipe_key( $recipe_key, $locale );
	if ( $post ) {
		return array( 'post' => $post, 'exact' => true );
	}
	foreach ( Atlas_Chuti_I18N::SUPPORTED_LOCALES as $fallback_locale ) {
		if ( $fallback_locale === $locale ) {
			continue;
		}
		$post = Atlas_Chuti_I18N::find_by_recipe_key( $recipe_key, $fallback_locale );
		if ( $post ) {
			return array( 'post' => $post, 'exact' => false );
		}
	}
	return null;
}

function atlas_chuti_resolve_country_iso( $iso ) {
	$locale = Atlas_Chuti_I18N::current_locale();
	$post   = Atlas_Chuti_I18N::find_country_by_iso( $iso, $locale );
	if ( $post ) {
		return array( 'post' => $post, 'exact' => true );
	}
	foreach ( Atlas_Chuti_I18N::SUPPORTED_LOCALES as $fallback_locale ) {
		if ( $fallback_locale === $locale ) {
			continue;
		}
		$post = Atlas_Chuti_I18N::find_country_by_iso( $iso, $fallback_locale );
		if ( $post ) {
			return array( 'post' => $post, 'exact' => false );
		}
	}
	return null;
}

/**
 * Resolves a whole list of recipe_keys at once, skipping any that no longer
 * resolve — never a broken card in the grid.
 */
function atlas_chuti_resolve_recipe_keys( array $recipe_keys ) {
	$out = array();
	foreach ( $recipe_keys as $key ) {
		$resolved = atlas_chuti_resolve_recipe_key( $key );
		if ( $resolved ) {
			$out[ $key ] = $resolved;
		}
	}
	return $out;
}

function atlas_chuti_account_favorite_recipes( $user_id ) {
	return atlas_chuti_resolve_recipe_keys( Atlas_Chuti_User_State::instance()->get_for_user( $user_id, Atlas_Chuti_User_State::TYPE_RECIPE, Atlas_Chuti_User_State::STATE_FAVORITE ) );
}

function atlas_chuti_account_cooked_recipes( $user_id ) {
	return atlas_chuti_resolve_recipe_keys( Atlas_Chuti_User_State::instance()->get_for_user( $user_id, Atlas_Chuti_User_State::TYPE_RECIPE, Atlas_Chuti_User_State::STATE_COOKED ) );
}

function atlas_chuti_account_tasted_countries( $user_id ) {
	$out = array();
	foreach ( Atlas_Chuti_User_State::instance()->get_for_user( $user_id, Atlas_Chuti_User_State::TYPE_COUNTRY, Atlas_Chuti_User_State::STATE_TASTED ) as $iso ) {
		$resolved = atlas_chuti_resolve_country_iso( $iso );
		if ( $resolved ) {
			$out[ $iso ] = $resolved;
		}
	}
	return $out;
}

/**
 * Můj Atlas → Moje hodnocení (item 19): each row paired with its resolved recipe
 * post and the recipe's current real aggregate — never the user's own vote
 * mistaken for the aggregate.
 */
function atlas_chuti_account_ratings( $user_id ) {
	$rows = Atlas_Chuti_Ratings::instance()->get_user_ratings( $user_id );
	$out  = array();
	foreach ( $rows as $row ) {
		$resolved = atlas_chuti_resolve_recipe_key( $row['recipe_key'] );
		if ( ! $resolved ) {
			continue;
		}
		$out[] = array(
			'post'      => $resolved['post'],
			'rating'    => (int) $row['rating'],
			'aggregate' => Atlas_Chuti_Ratings::instance()->get_aggregate( $row['recipe_key'] ),
		);
	}
	return $out;
}

function atlas_chuti_account_comments( $user_id ) {
	return Atlas_Chuti_Comments::instance()->get_user_recipe_comments( $user_id );
}

/**
 * Maps class-account.php's error codes (redirected back as `?chyba=...`) to a
 * user-facing message — never a raw PHP/SQL error (item 42).
 */
function atlas_chuti_account_error_message( $code ) {
	$map = array(
		'invalid_nonce'       => __( 'Vypršela platnost formuláře, zkuste to prosím znovu.', 'atlas-chuti' ),
		'rate_limited'        => __( 'Příliš mnoho pokusů, zkuste to prosím za chvíli.', 'atlas-chuti' ),
		'invalid_email'       => __( 'Zadejte prosím platnou e-mailovou adresu.', 'atlas-chuti' ),
		'weak_password'       => __( 'Heslo musí mít alespoň 8 znaků.', 'atlas-chuti' ),
		'password_mismatch'   => __( 'Hesla se neshodují.', 'atlas-chuti' ),
		'consent_required'    => __( 'Pro registraci je nutné souhlasit s podmínkami.', 'atlas-chuti' ),
		'email_taken'         => __( 'Tento e-mail je již zaregistrován. Zkuste se přihlásit nebo obnovit heslo.', 'atlas-chuti' ),
		'server_error'        => __( 'Registraci se nepodařilo dokončit, zkuste to prosím znovu.', 'atlas-chuti' ),
		'invalid_credentials' => __( 'Neplatné přihlašovací údaje.', 'atlas-chuti' ),
		'invalid_key'         => __( 'Odkaz pro obnovení hesla je neplatný nebo vypršel. Požádejte prosím o nový.', 'atlas-chuti' ),
	);
	return $map[ $code ] ?? __( 'Něco se nepovedlo, zkuste to prosím znovu.', 'atlas-chuti' );
}

function atlas_chuti_account_photos( $user_id ) {
	$rows = Atlas_Chuti_Photos::instance()->get_user_photos( $user_id );
	foreach ( $rows as &$row ) {
		$resolved       = atlas_chuti_resolve_recipe_key( $row['recipe_key'] );
		$row['post']    = $resolved ? $resolved['post'] : null;
	}
	return $rows;
}
