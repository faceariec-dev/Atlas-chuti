<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the vendored, local QR encoder (includes/lib/class-qrcode-vendor.php
 * — MIT-licensed, no external service, no network call). KROK 2's print layout
 * (item 8 of the brief) is the only current caller: a QR pointing at the
 * recipe's own canonical URL, embedded as real SVG markup so it's already
 * present in the DOM (and therefore in a print preview / printed page)
 * without depending on any script running at print time.
 */
class Atlas_Chuti_QRCode {

	/**
	 * Renders $text (expected: a real, already-canonical URL — never invented)
	 * as an inline <svg> QR code, or '' if the vendored encoder isn't available
	 * or $text is empty. Never a broken/placeholder image — callers must treat
	 * an empty return as "don't show a QR", not render a blank box.
	 */
	public static function svg( $text, $size_px = 132, $css_class = 'qr-code' ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		if ( ! class_exists( 'QRCode' ) ) {
			require __DIR__ . '/lib/class-qrcode-vendor.php';
		}
		if ( ! class_exists( 'QRCode' ) ) {
			return '';
		}

		try {
			$qr = QRCode::getMinimumQRCode( $text, QR_ERROR_CORRECT_LEVEL_M );
		} catch ( Exception $e ) {
			return '';
		}

		$count = $qr->getModuleCount();
		if ( $count < 1 ) {
			return '';
		}

		// A 4-module quiet zone on every side is part of the QR spec itself —
		// scanners rely on it, this isn't decorative padding.
		$quiet     = 4;
		$total     = $count + $quiet * 2;
		$module_px = $size_px / $total;

		$path = '';
		for ( $row = 0; $row < $count; $row++ ) {
			for ( $col = 0; $col < $count; $col++ ) {
				if ( ! $qr->isDark( $row, $col ) ) {
					continue;
				}
				$x = ( $col + $quiet ) * $module_px;
				$y = ( $row + $quiet ) * $module_px;
				$path .= sprintf( 'M%F,%FH%FV%FH%FZ', $x, $y, $x + $module_px, $y + $module_px, $x );
			}
		}

		return sprintf(
			'<svg class="%1$s" viewBox="0 0 %2$d %2$d" width="%2$d" height="%2$d" role="img" aria-label="%3$s"><rect width="%2$d" height="%2$d" fill="#fff"/><path d="%4$s" fill="#000"/></svg>',
			esc_attr( $css_class ),
			(int) round( $size_px ),
			esc_attr__( 'QR kód s odkazem na tento recept', 'atlas-chuti' ),
			$path
		);
	}
}
