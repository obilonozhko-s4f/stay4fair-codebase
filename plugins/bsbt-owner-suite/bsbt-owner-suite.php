<?php
/**
 * Plugin Name: BSBT Owner Suite (PDF + WhatsApp text)
 * Description: Admin totals, Owner PDF (open/download/email), WhatsApp = text only.
 * Version: 1.1.1
 * Author: BS Business Travelling
 * Text Domain: bsbt
 */

if (!defined('ABSPATH')) exit;

// Флаг от двойной загрузки
if (defined('BSBT_OWNER_SUITE_LOADED')) return;
define('BSBT_OWNER_SUITE_LOADED', true);

define('BSBT_OS_DIR', plugin_dir_path(__FILE__));
define('BSBT_OS_URL', plugin_dir_url(__FILE__));

// Единые ключи мета
define('BSBT_META_OWNER_PRICE_N',       'owner_price_per_night');
define('BSBT_META_OWNER_NAME',          'owner_name');
define('BSBT_META_OWNER_PHONE',         'owner_phone');
define('BSBT_META_OWNER_EMAIL',         'owner_email');
define('BSBT_META_APT_ADDRESS',         'address');
define('BSBT_META_DOORBELL',            'doorbell_name');
define('BSBT_META_APARTMENT_CODE',      'apartment_code');

define('BSBT_BMETA_OWNER_PRICE_N',      'bsbt_owner_price_per_night');
define('BSBT_BMETA_OWNER_NAME',         'bsbt_owner_name');
define('BSBT_BMETA_OWNER_PHONE',        'bsbt_owner_phone');
define('BSBT_BMETA_OWNER_EMAIL',        'bsbt_owner_email');
define('BSBT_BMETA_APT_ADDRESS',        'bsbt_apartment_address');
define('BSBT_BMETA_APARTMENT_CODE',     'bsbt_apartment_code');
define('BSBT_BMETA_OVERRIDE_GUEST_TOTAL','bsbt_guest_total_override');

// Сбор ошибок require, чтобы не падать на активации
$GLOBALS['bsbt_owner_suite_missing'] = [];

function bsbt_os_safe_require($rel_path){
	$full = BSBT_OS_DIR . ltrim($rel_path, '/');
	if ( file_exists($full) ) {
		require_once $full;
		return true;
	} else {
		$GLOBALS['bsbt_owner_suite_missing'][] = $rel_path;
		return false;
	}
}

// Грузим модули после загрузки плагинов
add_action('plugins_loaded', function(){
	bsbt_os_safe_require('includes/helpers.php');
	bsbt_os_safe_require('includes/copy-meta.php');
	bsbt_os_safe_require('includes/admin-columns.php');
	bsbt_os_safe_require('includes/metabox-owner-pdf.php');
	bsbt_os_safe_require('includes/pdf-template.php');
	bsbt_os_safe_require('includes/pdf-render.php');
	bsbt_os_safe_require('includes/apartment-code.php');
});

// Показываем заметку, если чего-то не хватило
add_action('admin_notices', function(){
	if ( empty($GLOBALS['bsbt_owner_suite_missing']) ) return;
	echo '<div class="notice notice-error"><p><strong>BSBT Owner Suite:</strong> Не удалось загрузить файлы:</p><ul>';
	foreach ($GLOBALS['bsbt_owner_suite_missing'] as $rel){
		echo '<li><code>'.esc_html($rel).'</code></li>';
	}
	echo '</ul><p>Проверьте названия и расположение файлов в <code>wp-content/plugins/bsbt-owner-suite/</code>.</p></div>';
});
