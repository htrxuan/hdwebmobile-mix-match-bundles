<?php

namespace htrxuan\hdmmb;

if (!defined('ABSPATH')) {
    exit;
}

class HDMMB_Ajax
{

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('wp_ajax_hdmmb_get_bundle_price', array($this, 'get_bundle_price'));
        add_action('wp_ajax_nopriv_hdmmb_get_bundle_price', array($this, 'get_bundle_price'));
    }

    public function get_bundle_price()
    {
        check_ajax_referer('hdmmb_frontend_nonce', 'nonce');

        $bundle_id = isset($_POST['bundle_id']) ? absint($_POST['bundle_id']) : 0;
        $bundle    = $bundle_id ? wc_get_product($bundle_id) : false;

        if (!$bundle || 'yes' !== get_post_meta($bundle_id, '_hdmmb_enabled', true)) {
            wp_send_json_error(array('message' => __('Invalid bundle product.', 'hdwebmobile-mix-match-bundles')));
        }

        $eligible  = HDMMB_Cart::get_eligible_component_ids($bundle_id);
        $selection = array();

        if (!empty($_POST['selection']) && is_array($_POST['selection'])) {
            // Nonce already verified above via check_ajax_referer(); each value is
            // sanitized with absint() and cross-checked against the eligible list below.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            foreach (wp_unslash($_POST['selection']) as $product_id => $qty) {
                $product_id = absint($product_id);
                $qty        = absint($qty);
                if ($product_id && $qty > 0 && in_array($product_id, $eligible, true)) {
                    $selection[$product_id] = $qty;
                }
            }
        }

        $price = HDMMB_Cart::calculate_bundle_price($bundle, $selection);

        wp_send_json_success(array(
            'price_html' => wc_price($price),
        ));
    }
}
