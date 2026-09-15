<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 8, item 29-34: recipe/article video — a closed `atlas_video_type`
 * vocabulary (none|youtube|own), manually curated only (item 30: no
 * auto-search, no scraping, no downloading someone else's video to this
 * server). Markup generation lives here (plugin), not the theme, following
 * the same precedent as Atlas_Chuti_QRCode::svg() — a small, self-contained,
 * data-driven embeddable widget, not a design-system component.
 *
 * YouTube embeds are ALWAYS click-to-load (a placeholder + button, never an
 * auto-inserted third-party iframe in the initial HTML) regardless of
 * whatever consent state Step 7's `consent_allows_marketing()` reports — that
 * click IS the "before consent: placeholder, tlačítko Načíst video" behavior
 * item 34 itself describes. When consent is NOT yet granted, the placeholder
 * additionally makes that explicit in its copy, so the UI is honest about
 * why a click is needed rather than presenting it as an ordinary
 * play-button.
 */
class Atlas_Chuti_Video {

	const TYPE_NONE    = 'none';
	const TYPE_YOUTUBE = 'youtube';
	const TYPE_OWN     = 'own';

	/**
	 * Real, validated video data for this post, or null when there is no
	 * video (item: "Video zobraz pouze pokud skutečně existuje") — every
	 * caller (embed renderer, schema builder) shares this ONE validation path
	 * so an invalid/unsupported URL can never reach either.
	 */
	public static function get_data( $post_id ) {
		$type = get_post_meta( $post_id, 'atlas_video_type', true );
		if ( ! in_array( $type, array( self::TYPE_YOUTUBE, self::TYPE_OWN ), true ) ) {
			return null;
		}
		$url = get_post_meta( $post_id, 'atlas_video_url', true );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return null;
		}
		if ( self::TYPE_YOUTUBE === $type && ! self::youtube_video_id( $url ) ) {
			return null; // item 45: an arbitrary/unsupported URL claimed as youtube is rejected, never rendered.
		}
		return array(
			'type'     => $type,
			'url'      => $url,
			'title'    => (string) get_post_meta( $post_id, 'atlas_video_title', true ),
			'channel'  => (string) get_post_meta( $post_id, 'atlas_video_channel', true ),
			'language' => (string) get_post_meta( $post_id, 'atlas_video_language', true ),
		);
	}

	public static function has_video( $post_id ) {
		return null !== self::get_data( $post_id );
	}

	/**
	 * Extracts the 11-char YouTube video ID from any common URL shape
	 * (watch?v=, youtu.be/, embed/, shorts/) — returns '' if the URL isn't a
	 * recognizable YouTube URL at all (used both to build the embed and to
	 * REJECT a non-YouTube URL that was mislabeled as type=youtube).
	 */
	public static function youtube_video_id( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( ! $host || ! preg_match( '/(^|\.)(youtube\.com|youtube-nocookie\.com|youtu\.be)$/i', $host ) ) {
			return '';
		}
		if ( preg_match( '/[?&]v=([A-Za-z0-9_-]{11})/', $url, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#(?:youtu\.be/|/embed/|/shorts/)([A-Za-z0-9_-]{11})#', $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	public static function render_embed( $post_id ) {
		$data = self::get_data( $post_id );
		if ( ! $data ) {
			return '';
		}
		return self::TYPE_YOUTUBE === $data['type'] ? self::render_youtube( $data ) : self::render_own( $post_id, $data );
	}

	private static function render_youtube( $data ) {
		$video_id = self::youtube_video_id( $data['url'] );
		$thumb    = "https://i.ytimg.com/vi/{$video_id}/hqdefault.jpg"; // YouTube's own public thumbnail CDN — no API key, no scraping.
		$consent_ready = class_exists( 'Atlas_Chuti_Advertising' ) && Atlas_Chuti_Advertising::instance()->consent_allows_marketing();
		$label    = $data['title'] ? $data['title'] : __( 'Video', 'atlas-chuti' );

		return sprintf(
			'<div class="atlas-video atlas-video--youtube%6$s" data-video-provider="youtube" data-video-id="%1$s">' .
			'<button type="button" class="atlas-video-load" style="background-image:url(%2$s);" aria-label="%3$s: %4$s">' .
			'<span class="atlas-video-play" aria-hidden="true"></span>' .
			'<span class="atlas-video-load-label">%5$s</span>' .
			'</button></div>',
			esc_attr( $video_id ),
			esc_url( $thumb ),
			esc_attr__( 'Načíst video', 'atlas-chuti' ),
			esc_attr( $label ),
			$consent_ready
				? esc_html__( 'Načíst video', 'atlas-chuti' )
				: esc_html__( 'Toto video pochází od externího poskytovatele (YouTube). Kliknutím jej načtete.', 'atlas-chuti' ),
			$consent_ready ? '' : ' atlas-video--needs-consent'
		);
	}

	private static function render_own( $post_id, $data ) {
		$poster = has_post_thumbnail( $post_id ) ? get_the_post_thumbnail_url( $post_id, 'atlas-hero' ) : '';
		return sprintf(
			'<div class="atlas-video atlas-video--own"><video controls preload="metadata"%1$s><source src="%2$s"></video></div>',
			$poster ? ' poster="' . esc_url( $poster ) . '"' : '',
			esc_url( $data['url'] )
		);
	}

	/**
	 * item 33: VideoObject built only from fields that actually exist —
	 * uploadDate is OMITTED (never invented) since this project stores no
	 * video-upload timestamp; the recipe/article's own datePublished already
	 * covers page-level dates in the surrounding Recipe/Article schema.
	 */
	public static function schema( $post_id ) {
		$data = self::get_data( $post_id );
		if ( ! $data ) {
			return null;
		}
		$schema = array(
			'@type' => 'VideoObject',
			'name'  => $data['title'] ? $data['title'] : get_the_title( $post_id ),
		);
		if ( self::TYPE_YOUTUBE === $data['type'] ) {
			$video_id                = self::youtube_video_id( $data['url'] );
			$schema['thumbnailUrl']  = array( "https://i.ytimg.com/vi/{$video_id}/hqdefault.jpg" );
			$schema['embedUrl']      = 'https://www.youtube-nocookie.com/embed/' . $video_id;
		} else {
			$schema['contentUrl'] = $data['url'];
			if ( has_post_thumbnail( $post_id ) ) {
				$schema['thumbnailUrl'] = array( get_the_post_thumbnail_url( $post_id, 'atlas-hero' ) );
			}
		}
		if ( ! isset( $schema['thumbnailUrl'] ) ) {
			return null; // schema.org requires thumbnailUrl — never emit an incomplete/invalid VideoObject.
		}
		return $schema;
	}
}
