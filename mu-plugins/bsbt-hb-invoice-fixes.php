<?php
/**
 * Plugin Name: BSBT – Invoice overrides (ext. ref + EN + VAT row DOM)
 * Description: MotoPress HB PDF Invoices: force EN, show external booking ref, insert VAT(7%) row strictly ABOVE TOTAL using DOM.
 * Author: BS Business Travelling
 */
if (!defined('ABSPATH')) exit;

if (!defined('BS_EXT_REF_META')) {
	define('BS_EXT_REF_META', '_bs_external_reservation_ref');
}

/* 1) Force English while rendering PDF */
add_action('mphb_invoices_print_pdf_before', function($booking_id){
	if (function_exists('switch_to_locale')) switch_to_locale('en_US');
}, 1);
add_action('mphb_invoices_print_pdf_after', function($booking_id){
	if (function_exists('restore_previous_locale')) restore_previous_locale();
}, 99);

/** Helper: insert VAT row before TOTAL in the BOOKING_DETAILS HTML using DOM */
function bsbt_insert_vat_before_total_dom(string $html, string $vatHtml): string {
	if ($html === '' || $vatHtml === '') return $html;

	libxml_use_internal_errors(true);
	$dom = new DOMDocument('1.0', 'UTF-8');
	$dom->loadHTML(
		'<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>',
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);
	$xpath = new DOMXPath($dom);

	// Targets: TOTAL / GESAMT (case-insensitive)
	$targets = $xpath->query("//tr[th and (translate(normalize-space(th[1]), 'abcdefghijklmnopqrstuvwxyzäöüß', 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜSS')='TOTAL' or translate(normalize-space(th[1]), 'abcdefghijklmnopqrstuvwxyzäöüß', 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜSS')='GESAMT')]");

	// Build <tr>
	$tr = $dom->createElement('tr');
	$tr->setAttribute('class', 'bsbt-vat-row');

	$th = $dom->createElement('th', 'VAT (7%) included');

	$td = $dom->createElement('td');
	// <<< Главное изменение: вставляем текст, а не XML-фрагмент >>>
	$plain = html_entity_decode( wp_strip_all_tags($vatHtml), ENT_QUOTES, 'UTF-8' );
	$td->appendChild( $dom->createTextNode( $plain ) );

	$tr->appendChild($th);
	$tr->appendChild($td);

	if ($targets && $targets->length > 0) {
		$targets->item(0)->parentNode->insertBefore($tr, $targets->item(0));
	} else {
		// Fallback: после SUBTOTAL / ZWISCHENSUMME
		$subs = $xpath->query("//tr[th and (translate(normalize-space(th[1]), 'abcdefghijklmnopqrstuvwxyzäöüß', 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜSS')='SUBTOTAL' or translate(normalize-space(th[1]), 'abcdefghijklmnopqrstuvwxyzäöüß', 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜSS')='ZWISCHENSUMME')]");
		if ($subs && $subs->length > 0) {
			$sub = $subs->item(0);
			if ($sub->nextSibling) {
				$sub->parentNode->insertBefore($tr, $sub->nextSibling);
			} else {
				$sub->parentNode->appendChild($tr);
			}
		} else {
			$tbodys = $xpath->query("//table[contains(@class,'mphb-price-breakdown')]//tbody");
			if ($tbodys && $tbodys->length > 0) {
				$tbodys->item($tbodys->length - 1)->appendChild($tr);
			}
		}
	}

	$out = $dom->saveHTML();
	$out = preg_replace('~^.*?<body>(.*)</body>.*$~is', '$1', $out);
	libxml_clear_errors();
	return $out ?: $html;
}

/* 2) Variables override + VAT insertion */
add_filter('mphb_invoices_print_pdf_variables', function(array $vars, $booking_id){

	$booking_id = (int)$booking_id;

	// Booking Ref вместо внутреннего ID
	$ext = trim((string)get_post_meta($booking_id, BS_EXT_REF_META, true));
	if ($ext !== '') {
		$vars['BOOKING_ID']  = $ext;
		$vars['CPT_BOOKING'] = 'Booking Ref';
	}

	// Заголовок по умолчанию на EN
	if (empty($vars['OPTIONS_INVOICE_TITLE'])) {
		$vars['OPTIONS_INVOICE_TITLE'] = 'BOOKING INVOICE – STAY4FAIR.COM';
	}

	// Вставляем VAT (7%) строго перед TOTAL
	if (function_exists('MPHB') && !empty($vars['BOOKING_DETAILS'])) {
		try {
			$booking = MPHB()->getBookingRepository()->findById($booking_id);
			if ($booking) {
				$gross = (float)$booking->getTotalPrice(); // gross includes VAT
				if ($gross > 0 && function_exists('mphb_format_price')) {
					$vat = round($gross - ($gross / 1.07), 2);
					if ($vat > 0) {
						$vatHtml = mphb_format_price($vat); // HTML со знаком €
						$vars['BOOKING_DETAILS'] = bsbt_insert_vat_before_total_dom((string)$vars['BOOKING_DETAILS'], (string)$vatHtml);
					}
				}
			}
		} catch (\Throwable $e) { /* ignore */ }
	}

	return $vars;
}, 10, 2);
