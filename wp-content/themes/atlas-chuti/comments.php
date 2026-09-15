<?php
/**
 * KROK 5, items 20/28/29: native WP comments, restricted to logged-in visitors on
 * `atlas_recipe` (see class-comments.php — comments_open() already returns false
 * for a guest there, this template just decides what to SHOW instead of the form
 * in that case: the existing thread plus a clear login prompt, never a raw
 * "Comments are closed" default). Each locale's post has its own, entirely
 * separate comment stream — this file never crosses that boundary, it only ever
 * lists comments for THIS post (item 30 of the KROK 5 brief / item 20 of Krok 4).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( post_password_required() ) {
	return;
}
?>

<?php if ( have_comments() ) : ?>
	<ol class="comment-list">
		<?php wp_list_comments( array( 'style' => 'ol', 'avatar_size' => 40 ) ); ?>
	</ol>
	<?php the_comments_navigation(); ?>
<?php else : ?>
	<p class="community-empty"><?php esc_html_e( 'Zatím žádné komentáře. Buďte první!', 'atlas-chuti' ); ?></p>
<?php endif; ?>

<?php if ( is_user_logged_in() ) : ?>
	<?php
	comment_form(
		array(
			'title_reply'        => __( 'Přidat komentář', 'atlas-chuti' ),
			'label_submit'       => __( 'Odeslat komentář', 'atlas-chuti' ),
			'comment_field'      => '<p class="comment-form-comment"><label for="comment" class="screen-reader-text">' . esc_html__( 'Komentář', 'atlas-chuti' ) . '</label><textarea id="comment" name="comment" rows="5" required></textarea></p>',
		)
	);
	?>
<?php else : ?>
	<p class="community-login-prompt">
		<?php
		printf(
			/* translators: %s: login URL */
			wp_kses( __( 'Pro přidání komentáře se prosím <a href="%s">přihlaste</a>.', 'atlas-chuti' ), array( 'a' => array( 'href' => array() ) ) ),
			esc_url( add_query_arg( array( 'sekce' => 'prihlaseni', 'redirect_to' => get_permalink() ), atlas_chuti_system_url( 'account' ) ) )
		);
		?>
	</p>
<?php endif; ?>
