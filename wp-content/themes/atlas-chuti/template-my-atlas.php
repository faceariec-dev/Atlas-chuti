<?php
/**
 * Template Name: Můj Atlas
 * Description: Přiřaďte této šabloně stránku se slugem „muj-atlas“.
 *
 * KROK 5: ONE account page, routed by a plain `?sekce=` query string (item 6 —
 * this alone already satisfies "reload funguje", "back/forward funguje", "bez JS
 * je použitelné", since it's a real URL, not client-side routing). Logged-out
 * visitors see login/registration/password-reset forms here; logged-in visitors
 * see the account dashboard. noindex is applied globally to this template in
 * class-seo.php (item 36).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$section      = atlas_chuti_account_section();
$logged_in    = is_user_logged_in();
$account_url  = atlas_chuti_system_url( 'account' );
$error_code   = isset( $_GET['chyba'] ) ? sanitize_key( wp_unslash( $_GET['chyba'] ) ) : '';
$redirect_to  = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
?>

<section class="container" style="padding:var(--space-14) var(--gutter) 0;">
	<span class="kicker" style="color:var(--color-ink);"><?php esc_html_e( 'Můj Atlas', 'atlas-chuti' ); ?></span>
	<?php if ( $logged_in ) : ?>
		<?php $current_user = wp_get_current_user(); ?>
		<h1><?php echo esc_html( sprintf( /* translators: %s: display name */ __( 'Ahoj, %s', 'atlas-chuti' ), $current_user->display_name ) ); ?></h1>
	<?php else : ?>
		<h1><?php esc_html_e( 'Můj Atlas', 'atlas-chuti' ); ?></h1>
		<p class="lede" style="max-width:none;"><?php esc_html_e( 'Ukládejte oblíbené recepty, označujte uvařené, budujte Kulinářský pas, hodnoťte a komentujte.', 'atlas-chuti' ); ?></p>
	<?php endif; ?>
</section>

<?php if ( ! $logged_in ) : ?>

	<section class="container section" style="max-width:440px;">
		<nav class="my-atlas-auth-tabs" aria-label="<?php esc_attr_e( 'Přihlášení nebo registrace', 'atlas-chuti' ); ?>">
			<a href="<?php echo esc_url( add_query_arg( array( 'sekce' => 'prihlaseni', 'redirect_to' => $redirect_to ?: null ), $account_url ) ); ?>" class="pill<?php echo 'prihlaseni' === $section ? ' is-active' : ''; ?>"><?php esc_html_e( 'Přihlásit se', 'atlas-chuti' ); ?></a>
			<a href="<?php echo esc_url( add_query_arg( array( 'sekce' => 'registrace', 'redirect_to' => $redirect_to ?: null ), $account_url ) ); ?>" class="pill<?php echo 'registrace' === $section ? ' is-active' : ''; ?>"><?php esc_html_e( 'Registrovat se', 'atlas-chuti' ); ?></a>
		</nav>

		<?php if ( $error_code ) : ?>
			<p class="form-notice form-notice-error" role="alert"><?php echo esc_html( atlas_chuti_account_error_message( $error_code ) ); ?></p>
		<?php endif; ?>

		<?php if ( 'prihlaseni' === $section ) : ?>

			<?php if ( isset( $_GET['heslo_obnoveno'] ) ) : ?>
				<p class="form-notice form-notice-success"><?php esc_html_e( 'Heslo bylo úspěšně změněno, nyní se můžete přihlásit.', 'atlas-chuti' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="account-form">
				<?php wp_nonce_field( 'atlas_login', 'atlas_account_nonce' ); ?>
				<input type="hidden" name="action" value="atlas_login">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
				<p><label for="login"><?php esc_html_e( 'E-mail nebo přihlašovací jméno', 'atlas-chuti' ); ?></label><input type="text" id="login" name="login" required autocomplete="username"></p>
				<p><label for="password"><?php esc_html_e( 'Heslo', 'atlas-chuti' ); ?></label><input type="password" id="password" name="password" required autocomplete="current-password"></p>
				<p class="account-form-remember"><label><input type="checkbox" name="remember" value="1"> <?php esc_html_e( 'Zapamatovat si mě', 'atlas-chuti' ); ?></label></p>
				<button type="submit" class="btn btn-accent" style="width:100%;"><?php esc_html_e( 'Přihlásit se', 'atlas-chuti' ); ?></button>
			</form>
			<p style="margin-top:var(--space-4);"><a href="<?php echo esc_url( add_query_arg( 'sekce', 'zapomenute-heslo', $account_url ) ); ?>"><?php esc_html_e( 'Zapomenuté heslo?', 'atlas-chuti' ); ?></a></p>

		<?php elseif ( 'registrace' === $section ) : ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="account-form">
				<?php wp_nonce_field( 'atlas_register', 'atlas_account_nonce' ); ?>
				<input type="hidden" name="action" value="atlas_register">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
				<p><label for="reg-email"><?php esc_html_e( 'E-mail', 'atlas-chuti' ); ?></label><input type="email" id="reg-email" name="email" required autocomplete="email"></p>
				<p><label for="reg-password"><?php esc_html_e( 'Heslo (min. 8 znaků)', 'atlas-chuti' ); ?></label><input type="password" id="reg-password" name="password" required minlength="8" autocomplete="new-password"></p>
				<p><label for="reg-password-confirm"><?php esc_html_e( 'Heslo znovu', 'atlas-chuti' ); ?></label><input type="password" id="reg-password-confirm" name="password_confirm" required minlength="8" autocomplete="new-password"></p>
				<p class="account-form-consent">
					<label>
						<input type="checkbox" name="consent" value="1" required>
						<?php
						printf(
							/* translators: 1: privacy policy URL, 2: terms URL */
							wp_kses( __( 'Souhlasím se <a href="%1$s">zpracováním osobních údajů</a> a <a href="%2$s">podmínkami používání</a>.', 'atlas-chuti' ), array( 'a' => array( 'href' => array() ) ) ),
							esc_url( atlas_chuti_system_url( 'privacy' ) ),
							esc_url( atlas_chuti_system_url( 'terms' ) )
						);
						?>
					</label>
				</p>
				<button type="submit" class="btn btn-accent" style="width:100%;"><?php esc_html_e( 'Vytvořit účet', 'atlas-chuti' ); ?></button>
			</form>

		<?php elseif ( 'zapomenute-heslo' === $section ) : ?>

			<?php if ( isset( $_GET['odeslano'] ) ) : ?>
				<p class="form-notice form-notice-success"><?php esc_html_e( 'Pokud tento e-mail existuje, poslali jsme na něj odkaz pro obnovení hesla.', 'atlas-chuti' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="account-form">
					<?php wp_nonce_field( 'atlas_lost_password', 'atlas_account_nonce' ); ?>
					<input type="hidden" name="action" value="atlas_lost_password">
					<p><label for="lost-email"><?php esc_html_e( 'E-mail nebo přihlašovací jméno', 'atlas-chuti' ); ?></label><input type="text" id="lost-email" name="user_login" required></p>
					<button type="submit" class="btn btn-accent" style="width:100%;"><?php esc_html_e( 'Odeslat odkaz pro obnovení', 'atlas-chuti' ); ?></button>
				</form>
			<?php endif; ?>

		<?php elseif ( 'nove-heslo' === $section ) : ?>

			<?php
			$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
			$login = isset( $_GET['login'] ) ? sanitize_user( wp_unslash( $_GET['login'] ) ) : '';
			?>
			<?php if ( ! $key || ! $login ) : ?>
				<p class="form-notice form-notice-error"><?php esc_html_e( 'Odkaz pro obnovení hesla je neplatný.', 'atlas-chuti' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="account-form">
					<?php wp_nonce_field( 'atlas_reset_password', 'atlas_account_nonce' ); ?>
					<input type="hidden" name="action" value="atlas_reset_password">
					<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
					<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>">
					<p><label for="new-password"><?php esc_html_e( 'Nové heslo (min. 8 znaků)', 'atlas-chuti' ); ?></label><input type="password" id="new-password" name="password" required minlength="8" autocomplete="new-password"></p>
					<p><label for="new-password-confirm"><?php esc_html_e( 'Nové heslo znovu', 'atlas-chuti' ); ?></label><input type="password" id="new-password-confirm" name="password_confirm" required minlength="8" autocomplete="new-password"></p>
					<button type="submit" class="btn btn-accent" style="width:100%;"><?php esc_html_e( 'Nastavit nové heslo', 'atlas-chuti' ); ?></button>
				</form>
			<?php endif; ?>

		<?php endif; ?>
	</section>

<?php else : ?>

	<section class="container section my-atlas-layout">
		<nav class="my-atlas-nav" aria-label="<?php esc_attr_e( 'Sekce Mého Atlasu', 'atlas-chuti' ); ?>">
			<?php
			$nav_items = array(
				'prehled'    => __( 'Přehled', 'atlas-chuti' ),
				'oblibene'   => __( 'Oblíbené', 'atlas-chuti' ),
				'uvarene'    => __( 'Uvařené', 'atlas-chuti' ),
				'pas'        => __( 'Kulinářský pas', 'atlas-chuti' ),
				'hodnoceni'  => __( 'Moje hodnocení', 'atlas-chuti' ),
				'komentare'  => __( 'Moje komentáře', 'atlas-chuti' ),
				'fotografie' => __( 'Moje fotografie', 'atlas-chuti' ),
				'nastaveni'  => __( 'Nastavení účtu', 'atlas-chuti' ),
			);
			foreach ( $nav_items as $key => $label ) :
				?>
				<a href="<?php echo esc_url( add_query_arg( 'sekce', $key, $account_url ) ); ?>" class="my-atlas-nav-item<?php echo $key === $section ? ' is-active' : ''; ?>"<?php echo $key === $section ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="my-atlas-nav-item my-atlas-logout"><?php esc_html_e( 'Odhlásit se', 'atlas-chuti' ); ?></a>
		</nav>

		<div class="my-atlas-content">

			<div class="passport-merge-banner no-print" data-passport-merge-banner hidden>
				<p><strong data-merge-title></strong> <span data-merge-body></span></p>
				<div class="passport-merge-actions">
					<button type="button" class="btn btn-accent" data-merge-accept></button>
					<button type="button" class="btn btn-outline" data-merge-decline></button>
				</div>
			</div>

			<?php if ( 'prehled' === $section ) : ?>

				<?php
				$fav_count    = Atlas_Chuti_User_State::instance()->count_for_user( get_current_user_id(), Atlas_Chuti_User_State::TYPE_RECIPE, Atlas_Chuti_User_State::STATE_FAVORITE );
				$cooked_count = Atlas_Chuti_User_State::instance()->count_for_user( get_current_user_id(), Atlas_Chuti_User_State::TYPE_RECIPE, Atlas_Chuti_User_State::STATE_COOKED );
				$tasted_count = Atlas_Chuti_User_State::instance()->count_for_user( get_current_user_id(), Atlas_Chuti_User_State::TYPE_COUNTRY, Atlas_Chuti_User_State::STATE_TASTED );
				?>
				<div class="my-atlas-stats-grid">
					<a class="my-atlas-stat-card" href="<?php echo esc_url( add_query_arg( 'sekce', 'oblibene', $account_url ) ); ?>">
						<span class="my-atlas-stat-value"><?php echo esc_html( $fav_count ); ?></span>
						<span class="my-atlas-stat-label"><?php esc_html_e( 'Oblíbené recepty', 'atlas-chuti' ); ?></span>
					</a>
					<a class="my-atlas-stat-card" href="<?php echo esc_url( add_query_arg( 'sekce', 'uvarene', $account_url ) ); ?>">
						<span class="my-atlas-stat-value"><?php echo esc_html( $cooked_count ); ?></span>
						<span class="my-atlas-stat-label"><?php esc_html_e( 'Uvařené recepty', 'atlas-chuti' ); ?></span>
					</a>
					<a class="my-atlas-stat-card" href="<?php echo esc_url( add_query_arg( 'sekce', 'pas', $account_url ) ); ?>">
						<span class="my-atlas-stat-value"><?php echo esc_html( $tasted_count ); ?></span>
						<span class="my-atlas-stat-label"><?php esc_html_e( 'Ochutnané země', 'atlas-chuti' ); ?></span>
					</a>
				</div>

			<?php elseif ( 'oblibene' === $section ) : ?>

				<h2><?php esc_html_e( 'Oblíbené recepty', 'atlas-chuti' ); ?></h2>
				<?php $items = atlas_chuti_account_favorite_recipes( get_current_user_id() ); ?>
				<?php if ( $items ) : ?>
					<div class="card-grid card-grid-3">
						<?php foreach ( $items as $entry ) : ?>
							<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => $entry['post']->ID ) ); ?>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="community-empty"><?php esc_html_e( 'Zatím nemáte žádné oblíbené recepty. Otevřete recept a klikněte na „Oblíbené“.', 'atlas-chuti' ); ?></p>
				<?php endif; ?>

			<?php elseif ( 'uvarene' === $section ) : ?>

				<h2><?php esc_html_e( 'Uvařené recepty', 'atlas-chuti' ); ?></h2>
				<?php $items = atlas_chuti_account_cooked_recipes( get_current_user_id() ); ?>
				<?php if ( $items ) : ?>
					<div class="card-grid card-grid-3">
						<?php foreach ( $items as $entry ) : ?>
							<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => $entry['post']->ID ) ); ?>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="community-empty"><?php esc_html_e( 'Zatím jste žádný recept neoznačili jako uvařený.', 'atlas-chuti' ); ?></p>
				<?php endif; ?>

			<?php elseif ( 'pas' === $section ) : ?>

				<?php
				$continents      = atlas_chuti_continent_totals();
				$total_countries = array_sum( wp_list_pluck( $continents, 'total' ) );
				$tasted          = atlas_chuti_account_tasted_countries( get_current_user_id() );
				$tasted_isos     = array_keys( $tasted );
				$cooked_items    = atlas_chuti_account_cooked_recipes( get_current_user_id() );
				?>
				<h2><?php esc_html_e( 'Kulinářský pas', 'atlas-chuti' ); ?></h2>
				<div class="passport-hero" style="margin-top:var(--space-4);">
					<div style="flex-shrink:0;">
						<div class="passport-count"><?php echo esc_html( count( $tasted_isos ) ); ?><span> / <?php echo esc_html( $total_countries ); ?></span></div>
						<div style="font-size:14px;color:var(--dark-text-soft);margin-top:4px;"><?php esc_html_e( 'zemí ochutnáno', 'atlas-chuti' ); ?></div>
					</div>
					<div class="passport-progress-track">
						<div class="passport-progress-fill" style="width:<?php echo esc_attr( $total_countries ? round( ( count( $tasted_isos ) / $total_countries ) * 100 ) : 0 ); ?>%;"></div>
					</div>
				</div>

				<h3 style="margin-top:var(--space-8);"><?php esc_html_e( 'Podle světadílů', 'atlas-chuti' ); ?></h3>
				<div style="display:flex;flex-direction:column;gap:var(--space-4);margin-top:var(--space-4);">
					<?php foreach ( $continents as $continent ) : ?>
						<?php $local_tasted = count( array_intersect( wp_list_pluck( $continent['countries'], 'iso' ), $tasted_isos ) ); ?>
						<div class="continent-progress-card">
							<div class="continent-progress-head"><h3><?php echo esc_html( $continent['name'] ); ?></h3><span><?php echo esc_html( $local_tasted . ' / ' . $continent['total'] ); ?></span></div>
							<div class="passport-flags">
								<?php foreach ( $continent['countries'] as $country ) : ?>
									<span style="opacity:<?php echo in_array( $country['iso'], $tasted_isos, true ) ? '1' : '0.3'; ?>;filter:<?php echo in_array( $country['iso'], $tasted_isos, true ) ? 'none' : 'grayscale(1)'; ?>;"><?php echo esc_html( $country['flag'] ); ?></span>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<h3 style="margin-top:var(--space-8);"><?php esc_html_e( 'Uvařené recepty', 'atlas-chuti' ); ?></h3>
				<?php if ( $cooked_items ) : ?>
					<div class="card-grid card-grid-4" style="margin-top:var(--space-4);">
						<?php foreach ( $cooked_items as $entry ) : ?>
							<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => $entry['post']->ID ) ); ?>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="passport-empty"><?php esc_html_e( 'Zatím jste žádný recept neoznačili jako uvařený. Otevřete recept a klikněte na „Uvařil/a jsem“.', 'atlas-chuti' ); ?></p>
				<?php endif; ?>

			<?php elseif ( 'hodnoceni' === $section ) : ?>

				<h2><?php esc_html_e( 'Moje hodnocení', 'atlas-chuti' ); ?></h2>
				<?php $ratings = atlas_chuti_account_ratings( get_current_user_id() ); ?>
				<?php if ( $ratings ) : ?>
					<table class="widefat striped my-atlas-table">
						<thead><tr><th><?php esc_html_e( 'Recept', 'atlas-chuti' ); ?></th><th><?php esc_html_e( 'Moje hodnocení', 'atlas-chuti' ); ?></th><th><?php esc_html_e( 'Průměr', 'atlas-chuti' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $ratings as $row ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( get_permalink( $row['post'] ) ); ?>"><?php echo esc_html( get_the_title( $row['post'] ) ); ?></a></td>
								<td><?php echo esc_html( str_repeat( '★', $row['rating'] ) ); ?></td>
								<td><?php echo $row['aggregate']['count'] ? esc_html( number_format_i18n( $row['aggregate']['average'], 1 ) . ' (' . $row['aggregate']['count'] . ')' ) : esc_html__( 'Zatím bez hodnocení', 'atlas-chuti' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="community-empty"><?php esc_html_e( 'Zatím jste žádný recept neohodnotili.', 'atlas-chuti' ); ?></p>
				<?php endif; ?>

			<?php elseif ( 'komentare' === $section ) : ?>

				<h2><?php esc_html_e( 'Moje komentáře', 'atlas-chuti' ); ?></h2>
				<?php $comments = atlas_chuti_account_comments( get_current_user_id() ); ?>
				<?php if ( $comments ) : ?>
					<ul class="my-atlas-comment-list">
						<?php foreach ( $comments as $comment ) : ?>
							<li>
								<a href="<?php echo esc_url( get_comment_link( $comment ) ); ?>"><?php echo esc_html( get_the_title( $comment->comment_post_ID ) ); ?></a>
								<span class="my-atlas-comment-status"><?php echo '1' === $comment->comment_approved ? esc_html__( 'zveřejněno', 'atlas-chuti' ) : esc_html__( 'čeká na schválení', 'atlas-chuti' ); ?></span>
								<p><?php echo esc_html( wp_trim_words( $comment->comment_content, 30 ) ); ?></p>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="community-empty"><?php esc_html_e( 'Zatím jste nenapsali žádný komentář.', 'atlas-chuti' ); ?></p>
				<?php endif; ?>

			<?php elseif ( 'fotografie' === $section ) : ?>

				<h2><?php esc_html_e( 'Moje fotografie', 'atlas-chuti' ); ?></h2>
				<?php $photos = atlas_chuti_account_photos( get_current_user_id() ); ?>
				<?php if ( $photos ) : ?>
					<div class="ugc-photo-grid">
						<?php foreach ( $photos as $photo ) : ?>
							<figure class="ugc-photo">
								<?php if ( $photo['image_url'] ) : ?><img src="<?php echo esc_url( $photo['image_url'] ); ?>" alt="" loading="lazy"><?php endif; ?>
								<figcaption>
									<?php echo $photo['post'] ? esc_html( get_the_title( $photo['post'] ) ) : esc_html( $photo['recipe_key'] ); ?>
									<span class="my-atlas-photo-status my-atlas-photo-status-<?php echo esc_attr( $photo['status'] ); ?>">
										<?php
										echo esc_html(
											array(
												'pending'  => __( 'čeká na schválení', 'atlas-chuti' ),
												'approved' => __( 'schváleno', 'atlas-chuti' ),
												'rejected' => __( 'zamítnuto', 'atlas-chuti' ),
											)[ $photo['status'] ] ?? ''
										);
										?>
									</span>
								</figcaption>
							</figure>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="community-empty"><?php esc_html_e( 'Zatím jste nenahráli žádnou fotografii.', 'atlas-chuti' ); ?></p>
				<?php endif; ?>

			<?php elseif ( 'nastaveni' === $section ) : ?>

				<h2><?php esc_html_e( 'Nastavení účtu', 'atlas-chuti' ); ?></h2>

				<?php if ( $error_code ) : ?>
					<p class="form-notice form-notice-error" role="alert"><?php echo esc_html( atlas_chuti_account_error_message( $error_code ) ); ?></p>
				<?php endif; ?>
				<?php if ( isset( $_GET['ulozeno'] ) ) : ?>
					<p class="form-notice form-notice-success"><?php esc_html_e( 'Uloženo.', 'atlas-chuti' ); ?></p>
				<?php endif; ?>

				<?php $current_user = wp_get_current_user(); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="account-form" style="max-width:440px;">
					<?php wp_nonce_field( 'atlas_update_profile', 'atlas_account_nonce' ); ?>
					<input type="hidden" name="action" value="atlas_update_profile">
					<p><label for="display-name"><?php esc_html_e( 'Zobrazované jméno', 'atlas-chuti' ); ?></label><input type="text" id="display-name" name="display_name" value="<?php echo esc_attr( $current_user->display_name ); ?>"></p>
					<p><em><?php echo esc_html( $current_user->user_email ); ?></em></p>
					<h3 style="margin-top:var(--space-6);"><?php esc_html_e( 'Změna hesla', 'atlas-chuti' ); ?></h3>
					<p><label for="current-password"><?php esc_html_e( 'Současné heslo', 'atlas-chuti' ); ?></label><input type="password" id="current-password" name="current_password" autocomplete="current-password"></p>
					<p><label for="new-password-settings"><?php esc_html_e( 'Nové heslo', 'atlas-chuti' ); ?></label><input type="password" id="new-password-settings" name="new_password" autocomplete="new-password"></p>
					<p><label for="new-password-settings-confirm"><?php esc_html_e( 'Nové heslo znovu', 'atlas-chuti' ); ?></label><input type="password" id="new-password-settings-confirm" name="new_password_confirm" autocomplete="new-password"></p>
					<button type="submit" class="btn btn-accent"><?php esc_html_e( 'Uložit změny', 'atlas-chuti' ); ?></button>
				</form>

				<h3 style="margin-top:var(--space-10);color:var(--color-danger,#b3261e);"><?php esc_html_e( 'Smazat účet', 'atlas-chuti' ); ?></h3>
				<p><?php esc_html_e( 'Nevratně smaže váš účet a všechna oblíbená/uvařená označení, hodnocení a fotografie.', 'atlas-chuti' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="account-form" style="max-width:440px;" onsubmit="return window.confirm('<?php echo esc_js( __( 'Opravdu chcete nevratně smazat svůj účet?', 'atlas-chuti' ) ); ?>');">
					<?php wp_nonce_field( 'atlas_delete_account', 'atlas_account_nonce' ); ?>
					<input type="hidden" name="action" value="atlas_delete_account">
					<p><label for="delete-password"><?php esc_html_e( 'Heslo pro potvrzení', 'atlas-chuti' ); ?></label><input type="password" id="delete-password" name="current_password" required autocomplete="current-password"></p>
					<button type="submit" class="btn btn-outline"><?php esc_html_e( 'Smazat účet', 'atlas-chuti' ); ?></button>
				</form>

			<?php endif; ?>
		</div>
	</section>

<?php endif; ?>

<?php get_footer(); ?>
