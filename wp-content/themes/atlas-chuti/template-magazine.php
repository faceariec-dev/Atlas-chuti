<?php
/**
 * Template Name: Magazín
 * Description: Přiřazeno automaticky stránce se slugem „magazin“ (Atlas chutí →
 * Nastavení stránek, viz class-page-setup.php). KROK 6, item 9: NE jednotná
 * mřížka — lead story, sekundární články, kategorijní pruhy, Tipy a triky
 * highlight, nejnovější články — vše postavené jen na skutečných publikovaných
 * `post` (item: "NEGENERUJ ani NEPLŇ desítky článků" — žádná sekce se
 * nezobrazí, dokud pro ni neexistuje reálný obsah).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$latest = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 13 ) );
$lead      = $latest ? $latest[0] : null;
$secondary = $latest ? array_slice( $latest, 1, 4 ) : array();
$more      = $latest ? array_slice( $latest, 5 ) : array();

$tips_slug  = class_exists( 'Atlas_Chuti_Magazine' ) ? Atlas_Chuti_Magazine::tips_tricks_category_slug() : '';
$tips_term  = $tips_slug ? get_term_by( 'slug', $tips_slug, 'category' ) : null;
$tips_posts = $tips_term && ! is_wp_error( $tips_term )
	? get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 3, 'cat' => $tips_term->term_id ) )
	: array();

// Up to 2 category strips, excluding Tipy a triky (its own highlight below) — only
// categories that actually have published articles right now.
$category_strips = array();
if ( class_exists( 'Atlas_Chuti_Magazine' ) ) {
	foreach ( Atlas_Chuti_Magazine::CATEGORIES as $entry ) {
		if ( 'tips_tricks' === $entry['key'] || count( $category_strips ) >= 2 ) {
			continue;
		}
		$slug = Atlas_Chuti_Magazine::category_slug_for_key( $entry['key'] );
		$term = $slug ? get_term_by( 'slug', $slug, 'category' ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			continue;
		}
		$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 3, 'cat' => $term->term_id ) );
		if ( $posts ) {
			$category_strips[] = array( 'term' => $term, 'posts' => $posts );
		}
	}
}

$has_any_content = $lead || $tips_posts || $category_strips || $more;
?>

<section class="container-narrow" style="padding:var(--space-14) var(--gutter) var(--space-5);">
	<span class="kicker is-blue"><?php esc_html_e( 'Magazín', 'atlas-chuti' ); ?></span>
	<h1><?php esc_html_e( 'Čtení k tématu', 'atlas-chuti' ); ?></h1>
	<p class="lede" style="max-width:none;"><?php esc_html_e( 'Techniky, suroviny, kuchyně světa a příběhy jídel — vše, co vaření dělá zajímavějším.', 'atlas-chuti' ); ?></p>
</section>

<?php if ( ! $has_any_content ) : ?>
	<section class="container-narrow section">
		<p class="empty-state"><?php esc_html_e( 'Magazín zatím nemá žádné publikované články. Vraťte se brzy.', 'atlas-chuti' ); ?></p>
	</section>
<?php endif; ?>

<?php if ( $lead ) : ?>
	<section class="container section" style="padding-top:var(--space-4);">
		<a class="magazine-item" href="<?php echo esc_url( get_permalink( $lead ) ); ?>" style="display:grid;grid-template-columns:1.2fr 1fr;gap:var(--space-8);align-items:center;">
			<div class="magazine-item-media" style="aspect-ratio:16/9;margin-bottom:0;"><?php echo atlas_chuti_media( $lead->ID, 'atlas-hero', '', 'magazine' ); ?></div>
			<div>
				<h2 style="margin-bottom:var(--space-2);"><?php echo esc_html( get_the_title( $lead ) ); ?></h2>
				<p style="color:var(--color-muted);"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt( $lead ) ), 30 ) ); ?></p>
			</div>
		</a>
	</section>
<?php endif; ?>

<?php if ( $secondary ) : ?>
	<section class="container section" style="padding-top:0;">
		<div class="magazine-strip">
			<?php foreach ( $secondary as $post_item ) : ?>
				<a class="magazine-item" href="<?php echo esc_url( get_permalink( $post_item ) ); ?>">
					<div class="magazine-item-media"><?php echo atlas_chuti_media( $post_item->ID, 'atlas-card', '', 'magazine' ); ?></div>
					<h3><?php echo esc_html( get_the_title( $post_item ) ); ?></h3>
					<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post_item ) ), 18 ) ); ?></p>
				</a>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( $tips_posts ) : ?>
	<section class="section bg-blue-tint">
		<div class="container">
			<div class="section-head">
				<div>
					<span class="kicker is-saffron"><?php esc_html_e( 'Highlight', 'atlas-chuti' ); ?></span>
					<h2><?php echo esc_html( $tips_term->name ); ?></h2>
				</div>
				<a class="more-link" href="<?php echo esc_url( get_term_link( $tips_term ) ); ?>"><?php esc_html_e( 'Zobrazit vše →', 'atlas-chuti' ); ?></a>
			</div>
			<div class="magazine-strip">
				<?php foreach ( $tips_posts as $post_item ) : ?>
					<a class="magazine-item" href="<?php echo esc_url( get_permalink( $post_item ) ); ?>">
						<div class="magazine-item-media"><?php echo atlas_chuti_media( $post_item->ID, 'atlas-card', '', 'magazine' ); ?></div>
						<h3><?php echo esc_html( get_the_title( $post_item ) ); ?></h3>
						<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post_item ) ), 18 ) ); ?></p>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
<?php endif; ?>

<?php foreach ( $category_strips as $strip ) : ?>
	<section class="section">
		<div class="container">
			<div class="section-head">
				<div>
					<span class="kicker is-blue"><?php esc_html_e( 'Magazín', 'atlas-chuti' ); ?></span>
					<h2><?php echo esc_html( $strip['term']->name ); ?></h2>
				</div>
				<a class="more-link" href="<?php echo esc_url( get_term_link( $strip['term'] ) ); ?>"><?php esc_html_e( 'Zobrazit vše →', 'atlas-chuti' ); ?></a>
			</div>
			<div class="magazine-strip">
				<?php foreach ( $strip['posts'] as $post_item ) : ?>
					<a class="magazine-item" href="<?php echo esc_url( get_permalink( $post_item ) ); ?>">
						<div class="magazine-item-media"><?php echo atlas_chuti_media( $post_item->ID, 'atlas-card', '', 'magazine' ); ?></div>
						<h3><?php echo esc_html( get_the_title( $post_item ) ); ?></h3>
						<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post_item ) ), 18 ) ); ?></p>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
<?php endforeach; ?>

<?php if ( $more ) : ?>
	<section class="section">
		<div class="container">
			<div class="section-head">
				<div><h2><?php esc_html_e( 'Nejnovější články', 'atlas-chuti' ); ?></h2></div>
			</div>
			<div class="card-grid card-grid-3">
				<?php foreach ( $more as $post_item ) : ?>
					<a class="magazine-item" href="<?php echo esc_url( get_permalink( $post_item ) ); ?>">
						<div class="magazine-item-media"><?php echo atlas_chuti_media( $post_item->ID, 'atlas-card', '', 'magazine' ); ?></div>
						<h3><?php echo esc_html( get_the_title( $post_item ) ); ?></h3>
						<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post_item ) ), 18 ) ); ?></p>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
<?php endif; ?>

<?php get_footer(); ?>
