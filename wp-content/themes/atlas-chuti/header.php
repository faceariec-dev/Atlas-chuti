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
					<?php if ( is_user_logged_in() ) : ?>
						<a class="header-account" href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><?php esc_html_e( 'Můj Atlas', 'atlas-chuti' ); ?></a>
					<?php else : ?>
						<span class="header-account is-soon" aria-disabled="true"><?php esc_html_e( 'Můj Atlas (brzy)', 'atlas-chuti' ); ?></span>
						<a class="header-login" href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Přihlásit se', 'atlas-chuti' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<?php atlas_chuti_primary_nav(); ?>
		</nav>

		<div class="header-tools">
			<form class="search-box header-search" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" role="search">
				<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.5" y2="16.5"></line></svg>
				<input type="search" name="s" placeholder="<?php esc_attr_e( 'Hledat…', 'atlas-chuti' ); ?>" value="<?php echo esc_attr( get_search_query() ); ?>">
			</form>

			<?php if ( is_user_logged_in() ) : ?>
				<a class="header-account" href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>">
					<?php esc_html_e( 'Můj Atlas', 'atlas-chuti' ); ?>
				</a>
			<?php else : ?>
				<span class="header-account is-soon" aria-disabled="true" title="<?php esc_attr_e( 'Uživatelské účty připravujeme.', 'atlas-chuti' ); ?>">
					<?php esc_html_e( 'Můj Atlas', 'atlas-chuti' ); ?>
				</span>
				<a class="header-login" href="<?php echo esc_url( wp_login_url() ); ?>">
					<?php esc_html_e( 'Přihlásit se', 'atlas-chuti' ); ?>
				</a>
			<?php endif; ?>

			<a class="passport-icon" href="<?php echo esc_url( atlas_chuti_system_url( 'passport' ) ); ?>" title="<?php esc_attr_e( 'Kulinářský pas', 'atlas-chuti' ); ?>">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#C85D42" stroke-width="1.8"><circle cx="12" cy="12" r="9"></circle><polygon points="15,9 13,13 9,15 11,11" fill="#C85D42" stroke="none"></polygon></svg>
			</a>
			<button class="nav-toggle" aria-expanded="false" aria-label="<?php esc_attr_e( 'Otevřít menu', 'atlas-chuti' ); ?>"><span></span></button>
		</div>
	</div>
</header>

<?php atlas_chuti_breadcrumbs(); ?>

<main id="main">
