<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 5: fills the `atlas_chuti_recipe_community` hook slot single-atlas_recipe.php
 * already had prepared (Step 2 scope) — ratings + reader photos + comments, one
 * cohesive "community" section. Every button here is a REAL <form>/<button> that
 * works without JS (item 27/29); assets/js/my-atlas.js progressively enhances the
 * same markup into async REST calls.
 */
function atlas_chuti_render_recipe_community( $post_id ) {
	$recipe_key = get_post_meta( $post_id, 'atlas_recipe_key', true ) ?: get_post_field( 'post_name', $post_id );
	$canonical  = get_permalink( $post_id );

	$aggregate = class_exists( 'Atlas_Chuti_Ratings' )
		? Atlas_Chuti_Ratings::instance()->get_aggregate( $recipe_key )
		: array( 'average' => 0.0, 'count' => 0 );
	$photos = class_exists( 'Atlas_Chuti_Photos' )
		? Atlas_Chuti_Photos::instance()->get_approved_for_recipe_key( $recipe_key )
		: array();

	$my_rating = null;
	if ( is_user_logged_in() && class_exists( 'Atlas_Chuti_Ratings' ) ) {
		$my_rating = Atlas_Chuti_Ratings::instance()->get_user_rating( $recipe_key, get_current_user_id() );
	} elseif ( class_exists( 'Atlas_Chuti_Ratings' ) && ! empty( $_COOKIE[ Atlas_Chuti_Ratings::COOKIE_NAME ] ) ) {
		$my_rating = Atlas_Chuti_Ratings::instance()->get_anonymous_rating( $recipe_key, Atlas_Chuti_Ratings::instance()->hash_token( $_COOKIE[ Atlas_Chuti_Ratings::COOKIE_NAME ] ) );
	}
	?>
	<div class="recipe-community">

		<section class="recipe-community-block" id="hodnoceni" data-rating-widget data-recipe-key="<?php echo esc_attr( $recipe_key ); ?>">
			<h2><?php esc_html_e( 'Hodnocení', 'atlas-chuti' ); ?></h2>

			<div class="rating-summary" data-rating-summary>
				<?php if ( $aggregate['count'] > 0 ) : ?>
					<span class="rating-stars-display" aria-hidden="true"><?php echo esc_html( str_repeat( '★', (int) round( $aggregate['average'] ) ) . str_repeat( '☆', 5 - (int) round( $aggregate['average'] ) ) ); ?></span>
					<span class="rating-value" data-rating-value><?php echo esc_html( number_format_i18n( $aggregate['average'], 1 ) ); ?> / 5</span>
					<span class="rating-count" data-rating-count>(<?php echo esc_html( $aggregate['count'] ); ?>)</span>
				<?php else : ?>
					<span class="rating-empty" data-rating-empty><?php esc_html_e( 'Zatím bez hodnocení', 'atlas-chuti' ); ?></span>
				<?php endif; ?>
			</div>

			<form class="rating-input-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'atlas_rating_submit', 'atlas_rating_nonce' ); ?>
				<input type="hidden" name="action" value="atlas_rating_submit">
				<input type="hidden" name="recipe_key" value="<?php echo esc_attr( $recipe_key ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $canonical ); ?>">
				<fieldset class="rating-input" data-rating-fieldset>
					<legend><?php esc_html_e( 'Vaše hodnocení', 'atlas-chuti' ); ?></legend>
					<div class="rating-stars-input" role="radiogroup" aria-label="<?php esc_attr_e( 'Hodnocení 1 až 5 hvězdiček', 'atlas-chuti' ); ?>">
						<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
							<?php
							// A REAL <input type="radio"> drives this, not a custom
							// role="radio" span (item 29): native radiogroup keyboard/focus
							// behavior (Tab, arrow keys, Space) comes for free and correctly,
							// with no risk of double-announcing to a screen reader.
							$star_input_id = 'rating-star-' . $post_id . '-' . $i;
							?>
							<label class="rating-star-label" for="<?php echo esc_attr( $star_input_id ); ?>">
								<input type="radio" id="<?php echo esc_attr( $star_input_id ); ?>" name="rating" value="<?php echo esc_attr( $i ); ?>" class="visually-hidden" data-rating-radio<?php checked( $my_rating, $i ); ?>>
								<span class="rating-star-btn" aria-hidden="true">★</span>
								<span class="visually-hidden"><?php echo esc_html( sprintf( /* translators: %d: number of stars */ _n( '%d hvězdička', '%d hvězdiček', $i, 'atlas-chuti' ), $i ) ); ?></span>
							</label>
						<?php endfor; ?>
					</div>
					<noscript><button type="submit" class="btn btn-outline" style="margin-top:var(--space-3);"><?php esc_html_e( 'Odeslat hodnocení', 'atlas-chuti' ); ?></button></noscript>
				</fieldset>
				<p class="rating-status no-print" data-rating-status aria-live="polite"></p>
			</form>
		</section>

		<section class="recipe-community-block" id="fotografie">
			<h2><?php esc_html_e( 'Fotky čtenářů', 'atlas-chuti' ); ?></h2>

			<?php if ( $photos ) : ?>
				<div class="ugc-photo-grid" data-ugc-photo-grid>
					<?php foreach ( $photos as $photo ) : ?>
						<figure class="ugc-photo">
							<?php if ( $photo['image_url'] ) : ?>
								<img src="<?php echo esc_url( $photo['image_url'] ); ?>" alt="" loading="lazy">
							<?php endif; ?>
							<figcaption><?php echo esc_html( $photo['display_name'] ?: __( 'Čtenář/ka Atlasu chutí', 'atlas-chuti' ) ); ?></figcaption>
						</figure>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="community-empty"><?php esc_html_e( 'Zatím žádné fotografie čtenářů. Buďte první!', 'atlas-chuti' ); ?></p>
			<?php endif; ?>

			<?php if ( is_user_logged_in() ) : ?>
				<form class="ugc-photo-upload" data-photo-upload data-recipe-key="<?php echo esc_attr( $recipe_key ); ?>" enctype="multipart/form-data" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'atlas_photo_upload', 'atlas_photo_nonce' ); ?>
					<input type="hidden" name="action" value="atlas_photo_upload">
					<input type="hidden" name="recipe_key" value="<?php echo esc_attr( $recipe_key ); ?>">
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( $canonical ); ?>">
					<label for="ugc-photo-input" class="ugc-upload-label"><?php esc_html_e( 'Přidat vlastní fotografii receptu', 'atlas-chuti' ); ?></label>
					<div class="ugc-upload-row">
						<input type="file" id="ugc-photo-input" name="photo" accept="image/jpeg,image/png,image/webp" required>
						<button type="submit" class="btn btn-outline"><?php esc_html_e( 'Nahrát', 'atlas-chuti' ); ?></button>
					</div>
					<p class="upload-status no-print" data-upload-status aria-live="polite"></p>
				</form>
			<?php else : ?>
				<p class="community-login-prompt">
					<a href="<?php echo esc_url( add_query_arg( array( 'sekce' => 'prihlaseni', 'redirect_to' => $canonical ), atlas_chuti_system_url( 'account' ) ) ); ?>"><?php esc_html_e( 'Přihlaste se a přidejte vlastní fotografii.', 'atlas-chuti' ); ?></a>
				</p>
			<?php endif; ?>
		</section>

		<section class="recipe-community-block" id="komentare">
			<h2><?php esc_html_e( 'Komentáře', 'atlas-chuti' ); ?></h2>
			<?php
			// comments.php itself handles the logged-out login-prompt vs. logged-in
			// comment_form() branching (item 20/28/29) — one call, not duplicated here.
			comments_template( '', true );
			?>
		</section>

	</div>
	<?php
}
add_action( 'atlas_chuti_recipe_community', 'atlas_chuti_render_recipe_community' );
