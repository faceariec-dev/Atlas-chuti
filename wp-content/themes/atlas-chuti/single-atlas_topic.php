<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * KROK 6, item 13-19: Diskuze topic detail — body + native WP comments-as-replies
 * (see comments.php, called below via comments_template()). Moderation links
 * (close/pin/trash) are capability-gated and only ever rendered for a user who
 * can actually use them — never shown, let alone usable, to a plain subscriber.
 */
get_header();

while ( have_posts() ) :
	the_post();
	$post_id    = get_the_ID();
	$terms      = get_the_terms( $post_id, 'atlas_topic_category' );
	$category   = ( $terms && ! is_wp_error( $terms ) ) ? Atlas_Chuti_Taxonomy_Labels::label( 'atlas_topic_category', $terms[0]->slug ) : '';
	$discussion = class_exists( 'Atlas_Chuti_Discussion' ) ? Atlas_Chuti_Discussion::instance() : null;
	$is_pinned  = $discussion && $discussion->is_pinned( $post_id );
	$is_closed  = $discussion && $discussion->is_closed( $post_id );
	$can_moderate = current_user_can( Atlas_Chuti_Discussion::MODERATE_CAPABILITY );
	?>

	<article <?php post_class( 'container-narrow' ); ?> style="padding:var(--space-14) var(--gutter) var(--space-5);">
		<div class="card-eyebrow" style="gap:10px;margin-bottom:var(--space-3);">
			<?php if ( $is_pinned ) : ?><span class="chip-static" style="padding:2px 10px;font-size:12px;"><?php esc_html_e( 'Připnuto', 'atlas-chuti' ); ?></span><?php endif; ?>
			<?php if ( $is_closed ) : ?><span class="chip-static" style="padding:2px 10px;font-size:12px;"><?php esc_html_e( 'Uzavřeno', 'atlas-chuti' ); ?></span><?php endif; ?>
			<?php if ( $category ) : ?><span class="kicker is-blue" style="margin:0;"><?php echo esc_html( $category ); ?></span><?php endif; ?>
		</div>

		<h1><?php the_title(); ?></h1>

		<div class="card-eyebrow" style="gap:14px;margin-top:var(--space-3);color:var(--color-muted);font-size:14px;">
			<span><?php echo esc_html( get_the_author_meta( 'display_name', get_post_field( 'post_author', $post_id ) ) ); ?></span>
			<span><?php echo esc_html( get_the_date() ); ?></span>
		</div>

		<div class="entry-content" style="margin-top:var(--space-6);font-size:16px;line-height:1.7;color:var(--color-text);">
			<?php the_content(); ?>
		</div>

		<?php if ( $can_moderate ) : ?>
			<div class="atlas-moderation-tools" style="margin-top:var(--space-6);display:flex;gap:10px;flex-wrap:wrap;">
				<?php
				foreach (
					array(
						$is_closed ? 'open' : 'close'  => $is_closed ? __( 'Znovu otevřít', 'atlas-chuti' ) : __( 'Uzavřít téma', 'atlas-chuti' ),
						$is_pinned ? 'unpin' : 'pin'   => $is_pinned ? __( 'Odepnout', 'atlas-chuti' ) : __( 'Připnout', 'atlas-chuti' ),
						'trash'                          => __( 'Přesunout do koše', 'atlas-chuti' ),
					) as $do => $label
				) :
					$moderate_url = wp_nonce_url(
						add_query_arg(
							array( 'action' => 'atlas_topic_moderate', 'id' => $post_id, 'do' => $do ),
							admin_url( 'admin-post.php' )
						),
						'atlas_topic_moderate_' . $post_id
					);
					?>
					<a class="chip" href="<?php echo esc_url( $moderate_url ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</article>

	<section class="container-narrow" style="padding:0 var(--gutter) var(--space-14);">
		<h2 style="font-size:var(--fs-h3);"><?php esc_html_e( 'Odpovědi', 'atlas-chuti' ); ?></h2>
		<?php comments_template(); ?>
	</section>

<?php endwhile; ?>

<?php get_footer(); ?>
