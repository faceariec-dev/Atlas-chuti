<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link" href="#main"><?php esc_html_e( 'Přejít na obsah', 'atlas-chuti' ); ?></a>

<header class="site-header">
	<div class="site-header-inner">
		<a class="site-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<span class="dot"></span>
			<span class="name"><?php bloginfo( 'name' ); ?></span>
		</a>

		<nav class="main-nav" aria-label="<?php esc_attr_e( 'Hlavní menu', 'atlas-chuti' ); ?>">
			<div class="mobile-nav-extra">
				<form class="search-box" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" role="search">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.5" y2="16.5"></line></svg>
					<input type="search" name="s" placeholder="<?php esc_attr_e( 'Hledat…', 'atlas-chuti' ); ?>" value="<?php echo esc_attr( get_search_query() ); ?>">
				</form>
				<div class="mobile-nav-account">
					<?php atlas_chuti_language_switcher(); ?>
					<?php if ( is_user_logged_in() ) : ?>
						<a class="header-account" href="<?php echo esc_url( atlas_chuti_system_url( 'account' ) ); ?>"><?php esc_html_e( 'Můj Atlas', 'atlas-chuti' ); ?></a>
					<?php else : ?>
						<a class="header-login" href="<?php echo esc_url( add_query_arg( 'sekce', 'prihlaseni', atlas_chuti_system_url( 'account' ) ) ); ?>"><?php esc_html_e( 'Přihlásit se', 'atlas-chuti' ); ?></a>
						<a class="header-account" href="<?php echo esc_url( add_query_arg( 'sekce', 'registrace', atlas_chuti_system_url( 'account' ) ) ); ?>"><?php esc_html_e( 'Registrace', 'atlas-chuti' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<?php atlas_chuti_primary_nav(); ?>
		</nav>

		<div class="header-tools">
			<?php atlas_chuti_language_switcher(); ?>
			<form class="search-box header-search" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" role="search">
				<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.5" y2="16.5"></line></svg>
				<input type="search" name="s" placeholder="<?php esc_attr_e( 'Hledat…', 'atlas-chuti' ); ?>" value="<?php echo esc_attr( get_search_query() ); ?>">
			</form>

			<?php if ( is_user_logged_in() ) : ?>
				<a class="header-account" href="<?php echo esc_url( atlas_chuti_system_url( 'account' ) ); ?>">
					<?php esc_html_e( 'Můj Atlas', 'atlas-chuti' ); ?>
				</a>
			<?php else : ?>
				<a class="header-login" href="<?php echo esc_url( add_query_arg( 'sekce', 'prihlaseni', atlas_chuti_system_url( 'account' ) ) ); ?>">
					<?php esc_html_e( 'Přihlásit se', 'atlas-chuti' ); ?>
				</a>
				<a class="header-account" href="<?php echo esc_url( add_query_arg( 'sekce', 'registrace', atlas_chuti_system_url( 'account' ) ) ); ?>">
					<?php esc_html_e( 'Registrace', 'atlas-chuti' ); ?>
				</a>
			<?php endif; ?>

			<?php
			// KROK 5, item 14: a logged-in visitor's Passport journey lands in the
			// account-integrated view (Můj Atlas → Kulinářský pas), not the standalone
			// anonymous/localStorage-only page — never two systems that diverge. The
			// standalone page (template-passport.php) itself is untouched and still
			// serves anonymous visitors exactly as before (backward compatibility).
			$passport_href = is_user_logged_in()
				? add_query_arg( 'sekce', 'pas', atlas_chuti_system_url( 'account' ) )
				: atlas_chuti_system_url( 'passport' );
			?>
			<a class="passport-icon" href="<?php echo esc_url( $passport_href ); ?>" title="<?php esc_attr_e( 'Kulinářský pas', 'atlas-chuti' ); ?>">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#C85D42" stroke-width="1.8"><circle cx="12" cy="12" r="9"></circle><polygon points="15,9 13,13 9,15 11,11" fill="#C85D42" stroke="none"></polygon></svg>
			</a>
			<button class="nav-toggle" aria-expanded="false" aria-label="<?php esc_attr_e( 'Otevřít menu', 'atlas-chuti' ); ?>"><span></span></button>
		</div>
	</div>
</header>

<?php atlas_chuti_breadcrumbs(); ?>

<main id="main">
