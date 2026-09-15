<?php
/**
 * Expects $args['post_id']. One row in the Diskuze archive list.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id  = $args['post_id'];
$terms    = get_the_terms( $post_id, 'atlas_topic_category' );
$category = ( $terms && ! is_wp_error( $terms ) ) ? Atlas_Chuti_Taxonomy_Labels::label( 'atlas_topic_category', $terms[0]->slug ) : '';
$discussion = class_exists( 'Atlas_Chuti_Discussion' ) ? Atlas_Chuti_Discussion::instance() : null;
$pinned   = $discussion && $discussion->is_pinned( $post_id );
$closed   = $discussion && $discussion->is_closed( $post_id );
?>
<a class="magazine-item" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" style="flex-direction:row;align-items:center;justify-content:space-between;gap:var(--space-4);padding:var(--space-4) 0;border-bottom:1px solid var(--color-border);">
	<div>
		<div class="card-eyebrow" style="gap:8px;margin-bottom:4px;">
			<?php if ( $pinned ) : ?><span class="chip-static" style="padding:2px 10px;font-size:12px;"><?php esc_html_e( 'Připnuto', 'atlas-chuti' ); ?></span><?php endif; ?>
			<?php if ( $closed ) : ?><span class="chip-static" style="padding:2px 10px;font-size:12px;"><?php esc_html_e( 'Uzavřeno', 'atlas-chuti' ); ?></span><?php endif; ?>
			<?php if ( $category ) : ?><span style="font-size:12px;color:var(--color-muted);"><?php echo esc_html( $category ); ?></span><?php endif; ?>
		</div>
		<h3 style="margin:0;font-size:17px;"><?php echo esc_html( get_the_title( $post_id ) ); ?></h3>
		<p style="margin:2px 0 0;font-size:13px;color:var(--color-muted);">
			<?php
			printf(
				/* translators: 1: author display name, 2: relative date */
				esc_html__( '%1$s · %2$s', 'atlas-chuti' ),
				esc_html( get_the_author_meta( 'display_name', get_post_field( 'post_author', $post_id ) ) ),
				esc_html( get_the_date( '', $post_id ) )
			);
			?>
		</p>
	</div>
	<div style="font-size:13px;color:var(--color-muted);white-space:nowrap;">
		<?php
		printf(
			/* translators: %d: number of replies */
			esc_html( _n( '%d odpověď', '%d odpovědí', get_comments_number( $post_id ), 'atlas-chuti' ) ),
			(int) get_comments_number( $post_id )
		);
		?>
	</div>
</a>
