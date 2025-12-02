<?php
/**
 * Plugin Name: BS Business Travelling – Voucher PDF + Auto-Mail
 * Description: Ваучер MPHB: PDF (Open/Download), отправка письма (ручная/авто), лог отправок. Тексты/верстка + данные как в рабочем варианте; фиксированная автологика.
 * Author: BS Business Travelling / Stay4Fair.com
 * Version: 1.3
 */

if (!defined('ABSPATH')) exit;

/* ============================================================
 * 0) PDF ENGINE LOADER (mPDF -> DOMPDF)
 * ============================================================ */
function bs_bt_try_load_pdf_engine() {
	if (class_exists('\Mpdf\Mpdf'))     return 'mpdf';
	if (class_exists('\Dompdf\Dompdf')) return 'dompdf';

	$mpdf_candidates = array(
		WP_PLUGIN_DIR . '/motopress-hotel-booking-pdf-invoices/vendor/autoload.php',
		WP_PLUGIN_DIR . '/hotel-booking-pdf-invoices/vendor/autoload.php',
	);
	foreach ($mpdf_candidates as $autoload) {
		if (is_file($autoload)) {
			require_once $autoload;
			if (class_exists('\Mpdf\Mpdf')) return 'mpdf';
		}
	}

	$dompdf_autoload = WP_PLUGIN_DIR . '/mphb-invoices/vendors/dompdf/autoload.inc.php';
	if (is_file($dompdf_autoload)) {
		require_once $dompdf_autoload;
		if (class_exists('\Dompdf\Dompdf')) return 'dompdf';
	}
	return '';
}

/* ============================================================
 * 0a) НОМЕР ВАУЧЕРА (внешний приоритет, согласован с проектом)
 * ============================================================ */
if ( ! defined('BS_EXT_REF_META') ) {
	// единый ключ по проекту
	define('BS_EXT_REF_META', '_bs_external_reservation_ref');
}

function bs_bt_get_voucher_number($booking_id) {
	$booking_id = (int) $booking_id;

	// 0) если уже есть общий хелпер — используем его (из других плагинов)
	if (function_exists('bsbt_get_display_booking_ref')) {
		return (string) bsbt_get_display_booking_ref($booking_id);
	}

	// 1) наш стандартный ключ
	$ext = trim((string) get_post_meta($booking_id, BS_EXT_REF_META, true));
	if ($ext !== '') return $ext;

	// 2) запасные старые ключи (обратная совместимость)
	$candidate_keys = array(
		'bs_external_reservation','external_reservation_number','external_booking_number',
		'booking_external_id','external_res_no','external_reservation_no',
		'bs_booking_number','reservation_number','custom_reservation_number'
	);
	foreach ($candidate_keys as $key) {
		$val = trim((string) get_post_meta($booking_id, $key, true));
		if ($val !== '') return $val;
	}

	// 3) внутренний номер (если ничего не найдено)
	$internal = trim((string) get_post_meta($booking_id, 'bs_internal_booking_number', true));
	if ($internal !== '') return $internal;

	return (string) $booking_id;
}

/* ============================================================
 * 0b) ОБЩИЙ ЛОГ ОТПРАВОК
 * ============================================================ */
function bs_bt_log_voucher_send($booking_id, $entry) {
	$log = get_post_meta($booking_id, '_bsbt_voucher_log', true);
	if (!is_array($log)) $log = array();
	$entry = wp_parse_args($entry, array(
		'time' => current_time('mysql'),
		'to' => '', 'subject' => '', 'source' => '',
		'status' => 'ok', 'error' => ''
	));
	$log[] = $entry;
	update_post_meta($booking_id, '_bsbt_voucher_log', $log);
	return $entry;
}

/* ============================================================
 * 1) РЕНДЕР HTML ВАУЧЕРА
 * ============================================================ */
function bs_bt_render_voucher_html($booking_id) {
	$owner = ['name'=>'','phone'=>'','email'=>'','address'=>'','doorbell'=>''];

	// Owner meta from room type
	if (function_exists('MPHB')) {
		try {
			$booking = MPHB()->getBookingRepository()->findById((int)$booking_id);
			if ($booking) {
				$reserved = $booking->getReservedRooms();
				if (!empty($reserved)) {
					$first = reset($reserved);
					$rtid  = method_exists($first,'getRoomTypeId') ? $first->getRoomTypeId() : 0;
					if ($rtid) {
						$owner['name']     = trim((string)get_post_meta($rtid, 'owner_name', true));
						$owner['phone']    = trim((string)get_post_meta($rtid, 'owner_phone', true));
						$owner['email']    = trim((string)get_post_meta($rtid, 'owner_email', true));
						$owner['address']  = trim((string)get_post_meta($rtid, 'address', true));
						$owner['doorbell'] = trim((string)get_post_meta($rtid, 'doorbell_name', true));
					}
				}
			}
		} catch (\Throwable $e) {}
	}

	// Guest
	$guest_first = trim((string)get_post_meta($booking_id,'mphb_first_name',true));
	$guest_last  = trim((string)get_post_meta($booking_id,'mphb_last_name',true));
	$guest_name  = trim($guest_first . ' ' . $guest_last);

	// Guests count
	$adults   = (int)get_post_meta($booking_id, 'mphb_adults', true);
	$children = (int)get_post_meta($booking_id, 'mphb_children', true);
	$total_guests = $adults + $children;
	if ($total_guests <= 0) {
		$total_guests = (int)get_post_meta($booking_id, 'mphb_total_guests', true);
	}
	if ($total_guests <= 0) $total_guests = 1;

	// Dates
	$check_in  = trim((string)get_post_meta($booking_id,'mphb_check_in_date',true));
	$check_out = trim((string)get_post_meta($booking_id,'mphb_check_out_date',true));

	// Voucher number (внешний приоритет)
	$voucher_no = bs_bt_get_voucher_number($booking_id);

	$contact_line = 'Our service number: WhatsApp +49 176 24615269 · business@stay4fair.com';

	ob_start(); ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Stay4Fair.com — Booking Voucher</title>
<style>
	body{font-family:DejaVu Sans, Arial, sans-serif;font-size:12px;color:#111;}
	.h1{font-size:20px;font-weight:800;margin:0 0 4px;}
	.brand{font-size:12px;color:#555;margin-bottom:10px;}
	.muted{color:#666;}
	.grid{display:table;width:100%;border-collapse:collapse;}
	.col{display:table-cell;vertical-align:top;}
	.box{border:1px solid #ddd;border-radius:6px;padding:10px;}
	.mt{margin-top:10px;} .mb{margin-bottom:10px;} .sep{border-top:1px solid #eee;margin:14px 0;}
	.label{font-weight:700;}
	.small{font-size:11px;line-height:1.45;}
	.kv div{margin:2px 0;}
</style>
</head>
<body>
  <div class="h1">Booking Voucher</div>
  <div class="brand">Stay4Fair.com</div>
  <div class="muted">Voucher No: <?php echo esc_html($voucher_no); ?> · Booking ID: <?php echo (int)$booking_id; ?></div>

  <div class="grid mt">
    <div class="col" style="width:58%;padding-right:10px;">
      <div class="box">
        <div class="label">Guest</div>
        <div class="kv">
          <div><?php echo esc_html($guest_name); ?></div>
          <div>Total guests: <?php echo (int)$total_guests; ?></div>
        </div>

        <div class="sep"></div>

        <div class="label">Stay</div>
        <div class="kv">
          <div>Check-in date: <?php echo esc_html($check_in); ?> (from 15:00 to 23:00)</div>
          <div>Check-out date: <?php echo esc_html($check_out); ?> (until 12:00)</div>
        </div>
      </div>
    </div>

    <div class="col" style="width:42%;">
      <div class="box">
        <div class="label">Owner / Check-in Information</div>
        <?php if($owner['name'])    echo '<div>Owner: '.esc_html($owner['name']).'</div>'; ?>
        <?php if($owner['phone'])   echo '<div>Phone: '.esc_html($owner['phone']).'</div>'; ?>
        <?php if($owner['email'])   echo '<div>Email: '.esc_html($owner['email']).'</div>'; ?>
        <?php if($owner['address']) echo '<div class="mt"><strong>Apartment address:</strong><br>'.nl2br(esc_html($owner['address'])).'</div>'; ?>
        <?php if($owner['doorbell'])echo '<div>Doorbell: '.esc_html($owner['doorbell']).'</div>'; ?>
      </div>
    </div>
  </div>

  <div class="box mt small">
    <div class="label">Check-in / Check-out instructions</div>
    <div>
      The keys will be handed over to you at check-in, directly in the apartment (please inform us about your arrival time).<br>
      Please note: this is a private apartment.<br>
      Light cleaning will be performed every third day. We kindly ask you to keep the apartment in order, too.<br>
      At check-out, you may leave the keys on the table and close the door, or coordinate your check-out time with our manager or the landlord to hand over the keys personally.<br>
      Cancellation policy: <strong>Non-Refundable Reservation</strong>. For details, see our website: stay4fair.com.<br>
      In case of any damage to the landlord’s property, the client must compensate the damage to the company or the landlord.
    </div>
  </div>

  <div class="box mt small">
    <div class="label">Contacts</div>
    <div><?php echo esc_html($contact_line); ?></div>
  </div>
</body>
</html>
<?php
	return ob_get_clean();
}

/* ============================================================
 * 1a) E-MAIL ТЕЛО ДЛЯ ОТПРАВКИ
 * ============================================================ */
function bs_bt_render_email_body($booking_id) {
	return bs_bt_render_voucher_html($booking_id);
}

/* ============================================================
 * 2) ГЕНЕРАЦИЯ PDF (Open / Download)
 * ============================================================ */
add_action('admin_init', function(){
	if (empty($_GET['bs_voucher_pdf']) || empty($_GET['booking_id'])) return;

	$booking_id = (int) $_GET['booking_id'];
	if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bs_voucher_pdf_' . $booking_id)) wp_die('Nonce failed');
	if (!current_user_can('edit_post', $booking_id)) wp_die('No permission');

	$html        = bs_bt_render_voucher_html($booking_id);
	$open_inline = !empty($_GET['inline']);

	$upload_dir = wp_upload_dir();
	$dir  = trailingslashit($upload_dir['basedir']).'bs-vouchers';
	if (!is_dir($dir)) wp_mkdir_p($dir);
	$file = trailingslashit($dir).'Voucher-'.$booking_id.'-'.date('Ymd-His').'.pdf';

	$engine = bs_bt_try_load_pdf_engine();

	if ($engine === 'mpdf') {
		try {
			$mpdf = new \Mpdf\Mpdf(['format'=>'A4','margin_left'=>12,'margin_right'=>12,'margin_top'=>14,'margin_bottom'=>14]);
			$mpdf->WriteHTML($html);
			$mpdf->Output($file, \Mpdf\Output\Destination::FILE);

			header('Content-Type: application/pdf');
			header('Content-Disposition: ' . ($open_inline ? 'inline' : 'attachment') . '; filename="'.basename($file).'"');
			readfile($file); exit;
		} catch (\Throwable $e) {}
	} elseif ($engine === 'dompdf') {
		try {
			$dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);
			$dompdf->loadHtml($html, 'UTF-8');
			$dompdf->setPaper('A4','portrait');
			$dompdf->render();
			file_put_contents($file, $dompdf->output());

			header('Content-Type: application/pdf');
			header('Content-Disposition: ' . ($open_inline ? 'inline' : 'attachment') . '; filename="'.basename($file).'"');
			readfile($file); exit;
		} catch (\Throwable $e) {}
	}

	// Fallback: покажем HTML (можно распечатать в PDF вручную)
	echo $html; exit;
});

/* ============================================================
 * 3) МЕТАБОКС: PDF кнопки + поле e-mail + кнопка Send
 * ============================================================ */
function bs_bt_voucher_metabox_cb($post){
	if (!$post || empty($post->ID) || $post->post_type !== 'mphb_booking') return;

	$base = admin_url('post.php?post='.$post->ID.'&action=edit');

	$view_url = wp_nonce_url(add_query_arg(array(
		'bs_voucher_pdf' => 1,
		'booking_id'     => $post->ID,
		'inline'         => 1,
	), $base), 'bs_voucher_pdf_' . $post->ID);

	$download_url = wp_nonce_url(add_query_arg(array(
		'bs_voucher_pdf' => 1,
		'booking_id'     => $post->ID,
		'download'       => 1,
	), $base), 'bs_voucher_pdf_' . $post->ID);

	$guest_email = trim((string)get_post_meta($post->ID,'mphb_email',true));
	$nonce_send  = wp_create_nonce('bsbt_send_voucher_now');

	echo '<div style="display:flex;flex-direction:column;gap:8px;">';
	echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
	echo '<a class="button" target="_blank" href="'.esc_url($view_url).'">'.esc_html__('Open Voucher (PDF)', 'bs-bt').'</a>';
	echo '<a class="button button-primary" href="'.esc_url($download_url).'">'.esc_html__('Download Voucher (PDF)', 'bs-bt').'</a>';
	echo '</div>';

	echo '<div>';
	echo '<label for="bsbt_voucher_email"><strong>Email:</strong></label><br/>';
	printf('<input type="email" id="bsbt_voucher_email" value="%s" style="width:100%%" placeholder="guest@example.com" />', esc_attr($guest_email));
	echo '</div>';

	echo '<div><button class="button button-secondary" id="bsbt_send_btn">'.esc_html__('Send Voucher', 'bs-bt').'</button></div>';
	echo '</div>';

	?>
	<script>
	(function(){
		const btn = document.getElementById('bsbt_send_btn');
		if(!btn) return;
		btn.addEventListener('click', function(e){
			e.preventDefault();
			const email = (document.getElementById('bsbt_voucher_email')||{}).value || '';
			btn.disabled = true; const old = btn.textContent; btn.textContent = 'Sending...';
			const data = new FormData();
			data.append('action','bsbt_send_voucher_now');
			data.append('booking_id','<?php echo esc_js($post->ID); ?>');
			data.append('nonce','<?php echo esc_js($nonce_send); ?>');
			data.append('email', email.trim());
			fetch(ajaxurl,{method:'POST',body:data}).then(r=>r.json()).then(j=>{
				alert(j && j.message ? j.message : 'Done');
			}).catch(()=>alert('Request failed')).finally(()=>{
				btn.disabled=false; btn.textContent=old;
			});
		});
	})();
	</script>
	<?php
}

add_action('add_meta_boxes', function($post_type){
	static $added = false;
	if ($added) return;
	if ($post_type !== 'mphb_booking') return;

	add_meta_box(
		'bs_voucher_pdf_box',
		__('BS Voucher (PDF)', 'bs-bt'),
		'bs_bt_voucher_metabox_cb',
		'mphb_booking',
		'side',
		'high'
	);
	$added = true;
}, 10, 1);

/* ============================================================
 * 4) РУЧНАЯ ОТПРАВКА (AJAX) — прикладываем PDF
 * ============================================================ */
add_action('wp_ajax_bsbt_send_voucher_now', function(){
	if (!current_user_can('edit_posts')) wp_send_json(array('ok'=>false,'message'=>'No permission'));
	$nonce = sanitize_text_field($_POST['nonce'] ?? '');
	if (!wp_verify_nonce($nonce, 'bsbt_send_voucher_now')) wp_send_json(array('ok'=>false,'message'=>'Nonce error'));

	$booking_id = (int)($_POST['booking_id'] ?? 0);
	$email      = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
	if ($booking_id <= 0) wp_send_json(array('ok'=>false,'message'=>'Bad booking id'));

	$res = bs_bt_send_voucher_email($booking_id, 'manual:button', $email ?: null);
	if (!empty($res['error'])) wp_send_json(array('ok'=>false,'message'=>'Error: '.$res['error']));
	wp_send_json(array('ok'=>true,'message'=>'Voucher sent to ' . ($email ?: 'guest email')));
});

/* ============================================================
 * 5) АВТООТПРАВКА ПРИ СМЕНЕ СТАТУСА
 * ============================================================ */
add_action('mphb_booking_status_changed', function($booking_id, $new_status){
	$new_status = strtolower((string)$new_status);
	$targets = array('confirmed','approved','paid');
	if (!in_array($new_status, $targets, true)) return;

	$already = get_post_meta($booking_id, '_bsbt_voucher_sent_statuses', true);
	if (!is_array($already)) $already = array();
	if (in_array($new_status, $already, true)) return;

	$res = bs_bt_send_voucher_email((int)$booking_id, "auto:$new_status", null);
	if ($res && empty($res['error'])) {
		$already[] = $new_status;
		update_post_meta($booking_id, '_bsbt_voucher_sent_statuses', array_values(array_unique($already)));
	}
}, 10, 2);

/* ============================================================
 * 6) ОТПРАВКА ПИСЬМА (HTML + прикреплённый PDF)
 * ============================================================ */
function bs_bt_send_voucher_email($booking_id, $source='auto', $override_email=null) {
	$booking_id = (int)$booking_id;
	if ($booking_id <= 0) return array('error'=>'Invalid booking_id');

	$guest_email = trim((string)get_post_meta($booking_id,'mphb_email',true));
	if (!empty($override_email)) {
		if (!is_email($override_email)) {
			return bs_bt_log_voucher_send($booking_id, array(
				'to'=>$override_email,'subject'=>'(not sent — invalid override email)',
				'source'=>$source,'status'=>'fail','error'=>'Override email invalid'
			));
		}
		$guest_email = $override_email;
	}
	if (empty($guest_email) || !is_email($guest_email)) {
		return bs_bt_log_voucher_send($booking_id, array(
			'to'=>$guest_email ?: '(empty)','subject'=>'(not sent — no guest email)',
			'source'=>$source,'status'=>'fail','error'=>'Guest email missing or invalid'
		));
	}

	$voucher_no = bs_bt_get_voucher_number($booking_id);
	$subject = sprintf('[Stay4Fair.com] Voucher — Booking %s', $voucher_no);
	$body    = bs_bt_render_email_body($booking_id);

	// PDF файл
	$upload_dir = wp_upload_dir();
	$dir  = trailingslashit($upload_dir['basedir']).'bs-vouchers';
	if (!is_dir($dir)) wp_mkdir_p($dir);
	$file = trailingslashit($dir).'Voucher-'.$booking_id.'-'.date('Ymd-His').'.pdf';

	$engine = bs_bt_try_load_pdf_engine();
	try {
		if ($engine === 'mpdf') {
			$mpdf = new \Mpdf\Mpdf(['format'=>'A4','margin_left'=>12,'margin_right'=>12,'margin_top'=>14,'margin_bottom'=>14]);
			$mpdf->WriteHTML($body);
			$mpdf->Output($file, \Mpdf\Output\Destination::FILE);
		} elseif ($engine === 'dompdf') {
			$dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);
			$dompdf->loadHtml($body, 'UTF-8'); $dompdf->setPaper('A4','portrait'); $dompdf->render();
			file_put_contents($file, $dompdf->output());
		}
	} catch (\Throwable $e) {}

	$attachments = array();
	if (file_exists($file)) $attachments[] = $file;

	add_filter('wp_mail_from_name', function($n){ return 'Stay4Fair Reservations'; });
	add_filter('wp_mail_from', function($e){ return 'business@stay4fair.com'; });
	$headers = array('Content-Type: text/html; charset=UTF-8');

	$sent = wp_mail($guest_email, $subject, $body, $headers, $attachments);

	return bs_bt_log_voucher_send($booking_id, array(
		'to'=>$guest_email,'subject'=>$subject,'source'=>$source,
		'status'=>$sent ? 'ok' : 'fail','error'=>$sent ? '' : 'wp_mail returned false'
	));
}

/* ============================================================
 * 7) МЕТАБОКС: ЛОГ ОТПРАВОК
 * ============================================================ */
add_action('add_meta_boxes', function(){
	add_meta_box(
		'bsbt_voucher_log_box',
		__('Voucher Log', 'bs-bt'),
		function($post){
			$log = get_post_meta($post->ID, '_bsbt_voucher_log', true);
			if (!is_array($log) || empty($log)) { echo '<p>No voucher emails sent yet.</p>'; return; }
			echo '<div style="max-height:260px;overflow:auto;font-family:monospace;font-size:12px;line-height:1.35">';
			foreach (array_reverse($log) as $row) {
				$badge = ($row['status'] === 'ok') ? 'background:#22c55e;color:#fff' : 'background:#ef4444;color:#fff';
				printf(
					'<div style="border-bottom:1px solid #e5e5e5;padding:8px 0;margin:0">
						<div><span style="padding:2px 6px;border-radius:6px;%s">%s</span> <strong>%s</strong></div>
						<div>%s</div>
						<div style="color:#666">%s → %s</div>
						%s
					</div>',
					esc_attr($badge),
					esc_html(strtoupper($row['status'] ?? '')),
					esc_html($row['source'] ?? ''),
					esc_html($row['time'] ?? ''),
					esc_html($row['to'] ?? ''),
					esc_html($row['subject'] ?? ''),
					!empty($row['error']) ? '<div style="color:#ef4444">Error: '.esc_html($row['error']).'</div>' : ''
				);
			}
			echo '</div>';
		},
		'mphb_booking',
		'side',
		'default'
	);
});
