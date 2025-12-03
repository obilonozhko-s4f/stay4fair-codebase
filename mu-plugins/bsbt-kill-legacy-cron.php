<?php
/**
 * Plugin Name: BSBT – Kill legacy cron hook bs_hb_wc_make_order
 * Description: Очищает все запланированные события с хукoм bs_hb_wc_make_order и вешает безопасный заглушечный обработчик.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * На всякий случай: заглушка, чтобы если где-то все еще дергается do_action('bs_hb_wc_make_order', $booking_id),
 * это не ломало сайт и не создавало лишних заказов.
 */
add_action( 'bs_hb_wc_make_order', function( $booking_id = null ) {
    // Мягко логируем и ничего не делаем
    if ( function_exists( 'error_log' ) ) {
        error_log( '[BSBT_CRON_CLEANUP] Dummy handler for bs_hb_wc_make_order called. Booking ID: ' . var_export( $booking_id, true ) );
    }
}, 1, 1 );

/**
 * При инициализации чистим все запланированные события с этим хуком.
 */
add_action( 'init', function() {

    if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_unschedule_event' ) ) {
        return;
    }

    $hook = 'bs_hb_wc_make_order';

    // Удаляем все события данного хука (на случай, если их несколько)
    while ( $timestamp = wp_next_scheduled( $hook ) ) {
        wp_unschedule_event( $timestamp, $hook );
    }

    // Для отладки можно один раз залогировать факт чистки
    if ( function_exists( 'error_log' ) ) {
        error_log( '[BSBT_CRON_CLEANUP] Cleared all scheduled events for hook: ' . $hook );
    }
});
