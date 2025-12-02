<?php
/**
 * Plugin Name: BSBT – Cancellation Policy per Apartment
 * Description: Adds a meta box for selecting a cancellation policy per apartment and a shortcode [bsbt_cancellation_box] for Single Accommodation.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =========================================================
 * 1. META BOX FOR mphb_room_type (Accommodation Type)
 * =========================================================
 */
add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'bsbt_cancel_policy',
        'BSBT – Cancellation Policy',
        'bsbt_render_cancel_policy_metabox',
        'mphb_room_type',
        'normal',
        'default'
    );
} );

/**
 * Render meta box
 */
function bsbt_render_cancel_policy_metabox( $post ) {

    // nonce
    wp_nonce_field( 'bsbt_save_cancel_policy', 'bsbt_cancel_policy_nonce' );

    $type = get_post_meta( $post->ID, '_bsbt_cancel_policy_type', true );

    if ( empty( $type ) ) {
        $type = 'nonref'; // default: Non-Refundable
    }
    ?>
    <p><strong>Cancellation Policy for this apartment:</strong></p>

    <p>
        <label>
            <input type="radio" name="bsbt_cancel_policy_type" value="nonref" <?php checked( $type, 'nonref' ); ?>>
            Non-Refundable – 100% charged in case of cancellation, change or no-show.
        </label><br>

        <label>
            <input type="radio" name="bsbt_cancel_policy_type" value="standard" <?php checked( $type, 'standard' ); ?>>
            Standard Flexible – free cancellation up to 30 days before arrival, then 100% charged.
        </label>
    </p>

    <p>
        <small>
            This setting controls the Cancellation Policy box shown on the Single Accommodation page via the shortcode <code>[bsbt_cancellation_box]</code>.
            Text is displayed in English only.
        </small>
    </p>
    <?php
}

/**
 * Save meta
 */
add_action( 'save_post_mphb_room_type', function( $post_id ) {

    // nonce check
    if (
        ! isset( $_POST['bsbt_cancel_policy_nonce'] ) ||
        ! wp_verify_nonce( $_POST['bsbt_cancel_policy_nonce'], 'bsbt_save_cancel_policy' )
    ) {
        return;
    }

    // autosave
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    // capability
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $type = isset( $_POST['bsbt_cancel_policy_type'] ) ? sanitize_text_field( $_POST['bsbt_cancel_policy_type'] ) : 'nonref';

    if ( ! in_array( $type, [ 'nonref', 'standard' ], true ) ) {
        $type = 'nonref';
    }

    update_post_meta( $post_id, '_bsbt_cancel_policy_type', $type );
} );

/**
 * =========================================================
 * 2. POLICY TEXTS (ENGLISH ONLY)
 * =========================================================
 */

function bsbt_get_cancellation_text_en( $type ) {

    switch ( $type ) {

        case 'standard':
            $text  = '<p><strong>Standard Flexible Cancellation Policy</strong></p>';
            $text .= '<ul>';
            $text .= '<li>Free cancellation up to <strong>30 days before arrival</strong>.</li>';
            $text .= '<li>For cancellations made <strong>29 days or less</strong> before arrival, as well as in case of no-show, <strong>100% of the total booking amount</strong> will be charged.</li>';
            $text .= '<li>Date changes are subject to availability and must be confirmed by Stay4Fair / BS Business Travelling.</li>';
            $text .= '</ul>';
            break;

        case 'nonref':
        default:
            $text  = '<p><strong>Non-Refundable Cancellation Policy</strong></p>';
            $text .= '<ul>';
            $text .= '<li>This reservation is <strong>non-refundable</strong>.</li>';
            $text .= '<li>In case of cancellation, modification or no-show, <strong>100% of the total booking amount</strong> will be charged.</li>';
            $text .= '<li>Paid amounts cannot be refunded under any circumstances.</li>';
            $text .= '<li>Date changes are not possible.</li>';
            $text .= '</ul>';
            break;
    }

    return $text;
}

/**
 * =========================================================
 * 3. SHORTCODE [bsbt_cancellation_box]
 * =========================================================
 *
 * Usage: [bsbt_cancellation_box] in Single Accommodation template
 */
add_shortcode( 'bsbt_cancellation_box', function( $atts ) {

    $atts = shortcode_atts(
        [
            'id' => 0,
        ],
        $atts
    );

    $room_id = intval( $atts['id'] );
    if ( ! $room_id ) {
        $room_id = get_the_ID();
    }

    if ( ! $room_id ) {
        return '';
    }

    $type = get_post_meta( $room_id, '_bsbt_cancel_policy_type', true );
    if ( empty( $type ) ) {
        $type = 'nonref';
    }

    $content = bsbt_get_cancellation_text_en( $type );

    $box_class = 'bsbt-cancel-box-' . esc_attr( $type );

    $html  = '<div class="bsbt-cancel-box ' . $box_class . '">';
    $html .= '<h3 class="bsbt-cancel-title">Cancellation Policy</h3>';
    $html .= '<div class="bsbt-cancel-content">' . $content . '</div>';
    $html .= '<p class="bsbt-cancel-link-note">';
    $html .= 'Full details can be found in our <a href="/cancellation-policy" target="_blank">Cancellation Policy</a> ';
    $html .= 'and <a href="/terms-conditions-agb" target="_blank">Terms &amp; Conditions</a>.';
    $html .= '</p>';
    $html .= '</div>';

    return $html;
} );

/**
 * =========================================================
 * 4. BASIC STYLES (Manrope, card, shadow, radius 10px)
 * =========================================================
 */
add_action( 'wp_head', function() {
    ?>
    <style>
        .bsbt-cancel-box {
            border-radius: 10px;
            border: 1px solid rgba(33, 47, 84, 0.10);
            padding: 18px 20px;
            margin: 24px 0;
            background: #ffffff;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            font-family: "Manrope", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .bsbt-cancel-title {
            margin: 0 0 8px;
            font-size: 18px;
            color: #212F54;
            font-weight: 700;
        }
        .bsbt-cancel-content p {
            margin: 0 0 6px;
            font-size: 14px;
            color: #212F54;
        }
        .bsbt-cancel-content ul {
            margin: 0 0 8px 18px;
            padding: 0;
            font-size: 14px;
            color: #212F54;
        }
        .bsbt-cancel-content li {
            margin-bottom: 4px;
        }
        .bsbt-cancel-link-note {
            margin: 8px 0 0;
            font-size: 13px;
            color: #555555;
        }
        .bsbt-cancel-link-note a {
            color: #212F54;
            text-decoration: underline;
        }

        /* Slight visual difference between scenarios (optional) */
        .bsbt-cancel-box-nonref {
            border-color: rgba(224, 184, 73, 0.6);
            background: #fffaf2;
        }
        .bsbt-cancel-box-nonref .bsbt-cancel-title {
            color: #B27F00;
        }
        .bsbt-cancel-box-standard {
            border-color: rgba(33, 47, 84, 0.25);
        }
    </style>
    <?php
} );
