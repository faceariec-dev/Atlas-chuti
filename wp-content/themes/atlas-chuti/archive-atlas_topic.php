<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * KROK 6, item 13-17: Diskuze archive (/diskuze/ CZ, /en/discussions/ EN once
 * Polylang gives atlas_topic its own per-language archive slug — see
 * atlas_chuti_discussion_url()) — topic list + category filter + the "start a
 * new topic" form (login-gated, item 17: anonymous visitors get a login/register
 * CTA instead, never a working form).
 */
get_header();

$discussion = class_exists( 'Atlas_Chuti_Discussion' ) ? Atlas_Chuti_Discussion::instance() : null;

$category_keys = Atlas_Chuti_Taxonomy_Labels::keys( 'atlas_topic_category' );
$active_cat     = isset( $_GET['kategorie'] ) ? sanitize_key( wp_unslash( $_GET['kategorie'] ) ) : '';
if ( ! in_array( $active_cat, $category_keys, true ) ) {
	$active_cat = '';
}

$pinned = get_posts(
	array(
		'post_type'      => 'atlas_topic',
		'post_status'    => 'publish',
		'posts_per_page' => 5,
		'meta_key'       => 'atlas_topic_pinned',
		'meta_value'     => 1,
		'orderby'        => 'date',
		'order'          => 'DESC',
	)
);

$error = isset( $_GET['chyba'] ) ? sanitize_key( wp_unslash( $_GET['chyba'] ) ) : '';
$error_messages = array(
	'title_required'   => __( 'Zadejte prosím název tématu.', 'atlas-chuti' ),
	'content_required' => __( 'Napište prosím text tématu.', 'atlas-chuti' ),
	'invalid_category' => __( 'Zvolte prosím platnou kategorii.', 'atlas-chuti' ),
	'rate_limited'      => __( 'Příliš mnoho témat najednou, zkuste to prosím za chvíli.', 'atlas-chuti' ),
	'server_error'      => __( 'Téma se nepodařilo uložit. Zkuste to prosím znovu.', 'atlas-chuti' ),
);
?>

<section class="container-narrow" style="padding:var(--space-14) var(--gutter) var(--space-5);">
	<span class="kicker is-blue"><?php esc_html_e( 'Diskuze', 'atlas-chuti' ); ?></span>
	<h1><?php esc_html_e( 'Diskuze Atlasu chutí', 'atlas-chuti' ); ?></h1>
	<p class="lede" style="max-width:none;"><?php esc_html_e( 'Ptejte se, radte a sdílejte zkušenosti s vařením s ostatními.', 'atlas-chuti' ); ?></p>
</section>

<section class="container" style="padding:0 var(--gutter) var(--space-5);">
	<div class="flex-wrap-gap">
		<a class="chip <?php echo ! $active_cat ? 'chip-static' : ''; ?>" href="<?php echo esc_url( remove_query_arg( 'kategorie' ) ); ?>"><?php esc_html_e( 'Vše', 'atlas-chuti' ); ?></a>
		<?php foreach ( $category_keys as $key ) : ?>
			<a class="chip <?php echo $active_cat === $key ? 'chip-static' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'kategorie', $key, remove_query_arg( 'paged' ) ) ); ?>"><?php echo esc_html( Atlas_Chuti_Taxonomy_Labels::label( 'atlas_topic_category', $key ) ); ?></a>
		<?php endforeach; ?>
	</div>
</section>

<section class="container" style="padding:0 var(--gutter) var(--space-8);">
	<?php if ( is_user_logged_in() ) : ?>
		<details class="atlas-new-topic">
			<summary class="btn btn-accent" style="cursor:pointer;display:inline-flex;"><?php esc_html_e( '+ Nové téma', 'atlas-chuti' ); ?></summary>
			<div style="margin-top:var(--space-4);max-width:640px;">
				<?php if ( $error && isset( $error_messages[ $error ] ) ) : ?>
					<p class="community-empty" role="alert"><?php echo esc_html( $error_messages[ $error ] ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'atlas_topic_create', 'atlas_topic_nonce' ); ?>
					<input type="hidden" name="action" value="atlas_topic_create">
					<p>
						<label for="atlas_topic_title"><?php esc_html_e( 'Název tématu', 'atlas-chuti' ); ?></label><br>
						<input type="text" id="atlas_topic_title" name="title" required style="width:100%;">
					</p>
					<p>
						<label for="atlas_topic_category"><?php esc_html_e( 'Kategorie', 'atlas-chuti' ); ?></label><br>
						<select id="atlas_topic_category" name="category" required>
							<option value=""><?php esc_html_e( '— vyberte —', 'atlas-chuti' ); ?></option>
							<?php foreach ( $category_keys as $key ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( Atlas_Chuti_Taxonomy_Labels::label( 'atlas_topic_category', $key ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="atlas_topic_content"><?php esc_html_e( 'Text', 'atlas-chuti' ); ?></label><br>
						<textarea id="atlas_topic_content" name="content" rows="6" required style="width:100%;"></textarea>
					</p>
					<button type="submit" class="btn btn-accent"><?php esc_html_e( 'Založit téma', 'atlas-chuti' ); ?></button>
				</form>
			</div>
		</details>
	<?php else : ?>
		<p class="community-login-prompt">
			<?php
			printf(
				/* translators: %s: login URL */
				wp_kses( __( 'Pro založení nového tématu se prosím <a href="%s">přihlaste nebo se zaregistrujte</a>.', 'atlas-chuti' ), array( 'a' => array( 'href' => array() ) ) ),
				esc_url( add_query_arg( array( 'sekce' => 'prihlaseni', 'redirect_to' => atlas_chuti_discussion_url() ), atlas_chuti_system_url( 'account' ) ) )
			);
			?>
		</p>
	<?php endif; ?>
</section>

<?php if ( $pinned ) : ?>
	<section class="container" style="padding:0 var(--gutter) var(--space-6);">
		<h2 style="font-size:var(--fs-h3);"><?php esc_html_e( 'Připnutá témata', 'atlas-chuti' ); ?></h2>
		<div class="atlas-topic-list">
			<?php foreach ( $pinned as $topic ) : ?>
				<?php get_template_part( 'template-parts/topic-row', null, array( 'post_id' => $topic->ID ) ); ?>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<section class="container section" style="padding-top:0;">
	<?php
	$paged = max( 1, (int) get_query_var( 'paged' ) );
	$args  = array(
		'post_type'      => 'atlas_topic',
		'post_status'    => 'publish',
		'posts_per_page' => 20,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( $active_cat ) {
		$args['tax_query'] = array( array( 'taxonomy' => 'atlas_topic_category', 'field' => 'slug', 'terms' => $active_cat ) );
	}
	$topic_query = new WP_Query( $args );
	?>
	<?php if ( $topic_query->have_posts() ) : ?>
		<div class="atlas-topic-list">
			<?php
			// KROK 7, item 14: umírněné umístění — jeden slot po N tématech, nikdy
			// mezi každou odpovědí/tématem.
			$atlas_ad_after_topic = 6;
			$atlas_topic_index    = 0;
			while ( $topic_query->have_posts() ) :
				$topic_query->the_post();
				get_template_part( 'template-parts/topic-row', null, array( 'post_id' => get_the_ID() ) );
				++$atlas_topic_index;
				if ( $atlas_ad_after_topic === $atlas_topic_index ) {
					atlas_chuti_render_ad_slot( 'discussion_in_feed' );
				}
			endwhile;
			?>
		</div>
		<div class="pagination">
			<?php
			echo paginate_links(
				array(
					'total'     => $topic_query->max_num_pages,
					'current'   => $paged,
					'prev_text' => '←',
					'next_text' => '→',
				)
			);
			?>
		</div>
	<?php else : ?>
		<p class="empty-state">
			<?php
			echo is_user_logged_in()
				? esc_html__( 'V této kategorii zatím nejsou žádná témata. Buďte první, kdo něco napíše!', 'atlas-chuti' )
				: esc_html__( 'V této kategorii zatím nejsou žádná témata.', 'atlas-chuti' );
			?>
		</p>
	<?php endif; ?>
	<?php wp_reset_postdata(); ?>
</section>

<?php get_footer(); ?>
