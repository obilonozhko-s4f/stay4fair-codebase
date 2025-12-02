<?php
if (!defined('ABSPATH')) exit;

/**
 * Helpers: даты/гости, Room/RoomType discovery,
 * owner_price_per_night (из RoomType), имя/адрес владельца (из RoomType),
 * кеш в мету брони.
 */

/** === BSBT canonical meta keys === */
// RoomType (mphb_room_type):
if (!defined('BSBT_META_OWNER_PRICE_N')) define('BSBT_META_OWNER_PRICE_N', 'owner_price_per_night');
if (!defined('BSBT_META_OWNER_NAME'))    define('BSBT_META_OWNER_NAME',    'owner_name');
if (!defined('BSBT_META_APT_ADDRESS'))   define('BSBT_META_APT_ADDRESS',   'address');

// Booking-level cache (optional, для UI/писем):
if (!defined('BSBT_BMETA_OWNER_PRICE_N')) define('BSBT_BMETA_OWNER_PRICE_N', 'bsbt_owner_price_per_night');
if (!defined('BSBT_BMETA_OWNER_NAME'))    define('BSBT_BMETA_OWNER_NAME',    'bsbt_owner_name');
if (!defined('BSBT_BMETA_APT_ADDRESS'))   define('BSBT_BMETA_APT_ADDRESS',   'bsbt_apartment_address');
if (!defined('BSBT_BMETA_OWNER_PHONE'))   define('BSBT_BMETA_OWNER_PHONE',   'bsbt_owner_phone');
if (!defined('BSBT_BMETA_OWNER_EMAIL'))   define('BSBT_BMETA_OWNER_EMAIL',   'bsbt_owner_email');
if (!defined('BSBT_BMETA_APARTMENT_CODE'))define('BSBT_BMETA_APARTMENT_CODE','bsbt_apartment_code');

/**
 * ===== Basics (dates, nights, guests, contacts) =====
 */
if (!function_exists('bsbt_get_booking_dates')) {
	function bsbt_get_booking_dates($booking_id){
		$in  = get_post_meta($booking_id, 'mphb_check_in_date', true);
		$out = get_post_meta($booking_id, 'mphb_check_out_date', true);
		if (empty($in))  $in  = get_post_meta($booking_id, '_mphb_check_in_date', true);
		if (empty($out)) $out = get_post_meta($booking_id, '_mphb_check_out_date', true);
		return [$in, $out];
	}
}

if (!function_exists('bsbt_nights_between')) {
	function bsbt_nights_between($check_in, $check_out){
		$in  = strtotime($check_in);
		$out = strtotime($check_out);
		if (!$in || !$out) return 0;
		$diff = max(0, $out - $in);
		return (int) round($diff / DAY_IN_SECONDS);
	}
}

if (!function_exists('bsbt_get_guest_total')) {
	function bsbt_get_guest_total($booking_id){
		$override = get_post_meta($booking_id, defined('BSBT_BMETA_OVERRIDE_GUEST_TOTAL') ? BSBT_BMETA_OVERRIDE_GUEST_TOTAL : 'bsbt_override_guest_total', true);
		if ($override !== '' && $override !== null) return (float) $override;

		if (function_exists('mphb_get_booking')) {
			$booking = mphb_get_booking($booking_id);
			if ($booking && method_exists($booking, 'get_total_price')) {
				return (float) $booking->get_total_price();
			}
		}
		foreach ([
			'mphb_booking_total_price','_mphb_booking_total_price',
			'mphb_total_price','_mphb_total_price','mphb_price',
		] as $k){
			$val = get_post_meta($booking_id, $k, true);
			if ($val !== '' && $val !== null) return (float) $val;
		}
		return 0.0;
	}
}

if (!function_exists('bsbt_get_guest_count')) {
	function bsbt_get_guest_count($booking_id){
		$cnt = get_post_meta($booking_id, 'mphb_adults', true);
		if ($cnt === '' || $cnt === null) $cnt = get_post_meta($booking_id, '_mphb_adults', true);
		if (!$cnt) $cnt = 1;
		return (int) $cnt;
	}
}

if (!function_exists('bsbt_get_guest_contact')) {
	function bsbt_get_guest_contact($booking_id){
		$first = get_post_meta($booking_id, 'mphb_first_name', true);
		$last  = get_post_meta($booking_id, 'mphb_last_name', true);
		$phone = get_post_meta($booking_id, 'mphb_phone', true);
		$addr  = get_post_meta($booking_id, 'mphb_address1', true);

		if (empty($first)) $first = get_post_meta($booking_id, '_mphb_first_name', true);
		if (empty($last))  $last  = get_post_meta($booking_id, '_mphb_last_name', true);
		if (empty($phone)) $phone = get_post_meta($booking_id, '_mphb_phone', true);
		if (empty($addr))  $addr  = get_post_meta($booking_id, '_mphb_address1', true);

		return ['first'=>$first, 'last'=>$last, 'phone'=>$phone, 'addr'=>$addr];
	}
}

/**
 * ===== Room / RoomType discovery =====
 */

/** room_id из брони (если есть). */
if (!function_exists('bsbt_get_room_id_from_booking')) {
	function bsbt_get_room_id_from_booking($booking_id){
		foreach (['mphb_room_id','_mphb_room_id','mphb_room','_mphb_room'] as $k){
			$rid = (int) get_post_meta($booking_id, $k, true);
			if ($rid > 0) return $rid;
		}
		// reserved_rooms как массив ID reserved_room постов
		$reserved = get_post_meta($booking_id, 'mphb_reserved_rooms', true);
		if (is_array($reserved) && !empty($reserved)){
			$rr_id = (int) reset($reserved);
			if ($rr_id > 0){
				$rid = (int) get_post_meta($rr_id, 'mphb_room_id', true);
				if ($rid > 0) return $rid;
			}
		}
		// через API HB
		if (function_exists('mphb_get_booking')) {
			$booking = mphb_get_booking($booking_id);
			if ($booking) {
				if (method_exists($booking, 'get_room_id')) {
					$rid = (int) $booking->get_room_id();
					if ($rid > 0) return $rid;
				}
				if (method_exists($booking, 'getReservedRoomsIds')) {
					$ids = (array) $booking->getReservedRoomsIds();
					if (!empty($ids)) {
						$rr_id = (int) reset($ids);
						if ($rr_id > 0) {
							$rid = (int) get_post_meta($rr_id, 'mphb_room_id', true);
							if ($rid > 0) return $rid;
						}
					}
				}
			}
		}
		return 0;
	}
}

/** room_type_id по room_id. */
if (!function_exists('bsbt_get_room_type_id_from_room')) {
	function bsbt_get_room_type_id_from_room($room_id){
		$typeid = (int) get_post_meta($room_id, 'mphb_room_type_id', true);
		if ($typeid > 0) return $typeid;

		if (function_exists('mphb_get_room') && $room_id > 0) {
			$room = mphb_get_room($room_id);
			if ($room && method_exists($room, 'getRoomTypeId')) {
				$typeid = (int) $room->getRoomTypeId();
				if ($typeid > 0) return $typeid;
			}
		}
		return 0;
	}
}

/** room_type_id напрямую из брони: охватывает все варианты хранения. */
if (!function_exists('bsbt_get_room_type_id_from_booking')) {
	function bsbt_get_room_type_id_from_booking($booking_id){
		// 0) прямые мета
		foreach (['mphb_room_type_id','_mphb_room_type_id'] as $k){
			$typeid = (int) get_post_meta($booking_id, $k, true);
			if ($typeid > 0) return $typeid;
		}

		// 1) reserved_rooms может быть:
		//    - массив ID reserved_room постов
		//    - массив структур с ключом room_type_id
		$reserved = get_post_meta($booking_id, 'mphb_reserved_rooms', true);
		if (!empty($reserved)) {
			// массив структур
			if (is_array($reserved) && isset($reserved[0]) && is_array($reserved[0]) && isset($reserved[0]['room_type_id'])) {
				$typeid = (int) $reserved[0]['room_type_id'];
				if ($typeid > 0) return $typeid;
			}
			// массив ID reserved_room
			if (is_array($reserved)) {
				$rr_id = (int) reset($reserved);
				if ($rr_id > 0) {
					$typeid = (int) get_post_meta($rr_id, 'mphb_room_type_id', true);
					if ($typeid > 0) return $typeid;
				}
			}
		}

		// 2) через API MotoPress
		if (function_exists('mphb_get_booking')) {
			$booking = mphb_get_booking($booking_id);
			if ($booking) {
				// некоторые версии: getRoomTypeId()
				foreach (['getRoomTypeId','get_room_type_id'] as $m){
					if (method_exists($booking, $m)) {
						$typeid = (int) $booking->$m();
						if ($typeid > 0) return $typeid;
					}
				}
				// getReservedRoomsIds() -> reserved_room -> meta room_type_id
				if (method_exists($booking, 'getReservedRoomsIds')) {
					$ids = (array) $booking->getReservedRoomsIds();
					if (!empty($ids)) {
						$rr_id = (int) reset($ids);
						if ($rr_id > 0) {
							$typeid = (int) get_post_meta($rr_id, 'mphb_room_type_id', true);
							if ($typeid > 0) return $typeid;
						}
					}
				}
			}
		}

		// 3) WP_Query по mphb_reserved_room, связанной с этой бронью
		$q = new WP_Query([
			'post_type'      => 'mphb_reserved_room',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => 'mphb_booking_id',
					'value' => $booking_id,
				]
			]
		]);
		if ($q->have_posts()){
			$rr_id = (int) $q->posts[0];
			$typeid = (int) get_post_meta($rr_id, 'mphb_room_type_id', true);
			if ($typeid > 0) return $typeid;
		}

		// 4) как крайняя мера: через room_id → room_type_id
		$room_id = bsbt_get_room_id_from_booking($booking_id);
		if ($room_id > 0) {
			$typeid = bsbt_get_room_type_id_from_room($room_id);
			if ($typeid > 0) return $typeid;
		}

		return 0;
	}
}

/**
 * ===== Закупочная цена/ночь (эффективная) =====
 * 1) из меты брони (bsbt_owner_price_per_night)
 * 2) из Unterkunftsart (mphb_room_type) → owner_price_per_night
 * 3) из конкретного room (фолбэк)
 * + при первом удачном чтении — сохраняем в мету брони (миграция/кеш).
 */
if (!function_exists('bsbt_get_owner_price_night_effective')) {
	function bsbt_get_owner_price_night_effective($booking_id){
		// 1) бронь
		$val = get_post_meta($booking_id, BSBT_BMETA_OWNER_PRICE_N, true);
		if ($val !== '' && $val !== null) return (float) $val;

		// 2) room_type
		$type_id = bsbt_get_room_type_id_from_booking($booking_id);
		if ($type_id > 0) {
			$from_type = get_post_meta($type_id, BSBT_META_OWNER_PRICE_N, true); // 'owner_price_per_night'
			if ($from_type !== '' && $from_type !== null){
				$from_type = (float) $from_type;
				update_post_meta($booking_id, BSBT_BMETA_OWNER_PRICE_N, $from_type);
				return (float) $from_type;
			}
		}

		// 3) room (fallback)
		$room_id = bsbt_get_room_id_from_booking($booking_id);
		if ($room_id > 0) {
			$from_room = get_post_meta($room_id, BSBT_META_OWNER_PRICE_N, true);
			if ($from_room !== '' && $from_room !== null){
				$from_room = (float) $from_room;
				update_post_meta($booking_id, BSBT_BMETA_OWNER_PRICE_N, $from_room);
				return (float) $from_room;
			}
		}

		return 0.0;
	}
}

/** ===== Имя/Адрес владельца из RoomType (с кешем в брони) ===== */
if (!function_exists('bsbt_get_owner_name_from_booking')) {
	function bsbt_get_owner_name_from_booking($booking_id){
		$type_id = bsbt_get_room_type_id_from_booking($booking_id);
		if ($type_id > 0) {
			$val = get_post_meta($type_id, BSBT_META_OWNER_NAME, true); // 'owner_name'
			if ($val !== '' && $val !== null) {
				update_post_meta($booking_id, BSBT_BMETA_OWNER_NAME, $val);
				return (string)$val;
			}
		}
		$cached = get_post_meta($booking_id, BSBT_BMETA_OWNER_NAME, true);
		return (string)($cached ?: '');
	}
}

if (!function_exists('bsbt_get_owner_address_from_booking')) {
	function bsbt_get_owner_address_from_booking($booking_id){
		$type_id = bsbt_get_room_type_id_from_booking($booking_id);
		if ($type_id > 0) {
			$val = get_post_meta($type_id, BSBT_META_APT_ADDRESS, true); // 'address'
			if ($val !== '' && $val !== null) {
				update_post_meta($booking_id, BSBT_BMETA_APT_ADDRESS, $val);
				return (string)$val;
			}
		}
		$cached = get_post_meta($booking_id, BSBT_BMETA_APT_ADDRESS, true);
		return (string)($cached ?: '');
	}
}

/** Опционально: прогрев кеша при сохранении брони (ускоряет UI/письма) */
add_action('save_post_mphb_booking', function($post_id, $post = null){
	if (get_post_type($post_id) !== 'mphb_booking') return;
	bsbt_get_owner_price_night_effective($post_id);
	bsbt_get_owner_name_from_booking($post_id);
	bsbt_get_owner_address_from_booking($post_id);
}, 20, 2);
