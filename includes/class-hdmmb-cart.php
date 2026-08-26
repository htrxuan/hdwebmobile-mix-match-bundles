<?php

namespace htrxuan\hdmmb;

if (!defined('ABSPATH')) {
    exit;
}

class HDMMB_Cart
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
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_selection'), 10, 3);
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 2);
        add_filter('woocommerce_get_cart_item_from_session', array($this, 'get_cart_item_from_session'), 10, 2);
        add_filter('woocommerce_get_item_data', array($this, 'display_item_data'), 10, 2);
        add_action('woocommerce_before_calculate_totals', array($this, 'set_cart_item_price'), 20);
    }

    /**
     * Read and sanitize the posted selection into [product_id => qty], dropping zero/invalid entries.
     */
    private function get_posted_selection()
    {
        // Add-to-cart forms are not nonce-protected in WooCommerce core either (see
        // WC_Form_Handler::add_to_cart_handler_simple()); every value read here is
        // re-validated server-side in validate_selection() before anything is trusted.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (empty($_POST['hdmmb_selection']) || !is_array($_POST['hdmmb_selection'])) {
            return array();
        }

        $selection = array();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        foreach (wp_unslash($_POST['hdmmb_selection']) as $product_id => $qty) {
            $product_id = absint($product_id);
            $qty        = absint($qty);
            if ($product_id && $qty > 0) {
                $selection[$product_id] = $qty;
            }
        }

        return $selection;
    }

    /**
     * Re-derive the allowed component product IDs for a bundle from its own config,
     * never trusting posted product IDs blindly.
     */
    public static function get_eligible_component_ids($bundle_id)
    {
        $source_type = get_post_meta($bundle_id, '_hdmmb_source_type', true) ?: 'category';

        if ('manual' === $source_type) {
            $source_products = get_post_meta($bundle_id, '_hdmmb_source_products', true);
            return is_array($source_products) ? array_map('absint', $source_products) : array();
        }

        $category_id = absint(get_post_meta($bundle_id, '_hdmmb_source_category', true));
        if (!$category_id) {
            return array();
        }

        $products = wc_get_products(array(
            'product_category_id' => array($category_id),
            'status'              => 'publish',
            'limit'               => -1,
            'return'              => 'ids',
        ));

        return array_map('absint', $products);
    }

    public function validate_selection($passed, $product_id, $quantity)
    {
        if ('yes' !== get_post_meta($product_id, '_hdmmb_enabled', true)) {
            return $passed;
        }

        $selection = $this->get_posted_selection();
        $pick_qty  = absint(get_post_meta($product_id, '_hdmmb_pick_qty', true));
        $eligible  = self::get_eligible_component_ids($product_id);

        $total_qty = array_sum($selection);

        if ($total_qty !== $pick_qty) {
            wc_add_notice(
                sprintf(
                    /* translators: %d: required quantity */
                    __('Please select exactly %d items for this bundle.', 'hdwebmobile-mix-match-bundles'),
                    $pick_qty
                ),
                'error'
            );
            return false;
        }

        foreach ($selection as $component_id => $qty) {
            if (!in_array($component_id, $eligible, true)) {
                wc_add_notice(__('One of the selected items is not available for this bundle.', 'hdwebmobile-mix-match-bundles'), 'error');
                return false;
            }

            $component = wc_get_product($component_id);
            if (!$component || !$component->is_purchasable()) {
                wc_add_notice(__('One of the selected items is no longer available.', 'hdwebmobile-mix-match-bundles'), 'error');
                return false;
            }

            if ($component->managing_stock() && !$component->has_enough_stock($qty)) {
                wc_add_notice(
                    sprintf(
                        /* translators: %s: product name */
                        __('Not enough stock available for "%s".', 'hdwebmobile-mix-match-bundles'),
                        $component->get_name()
                    ),
                    'error'
                );
                return false;
            }
        }

        return $passed;
    }

    public function add_cart_item_data($cart_item_data, $product_id)
    {
        if ('yes' !== get_post_meta($product_id, '_hdmmb_enabled', true)) {
            return $cart_item_data;
        }

        $selection = $this->get_posted_selection();
        if (!empty($selection)) {
            $cart_item_data['hdmmb_selection'] = $selection;
        }

        return $cart_item_data;
    }

    public function get_cart_item_from_session($cart_item, $values)
    {
        if (isset($values['hdmmb_selection'])) {
            $cart_item['hdmmb_selection'] = $values['hdmmb_selection'];
        }
        return $cart_item;
    }

    public function display_item_data($item_data, $cart_item)
    {
        if (empty($cart_item['hdmmb_selection'])) {
            return $item_data;
        }

        $item_data[] = array(
            'key'   => __('Bundle Contents', 'hdwebmobile-mix-match-bundles'),
            'value' => wc_clean(self::format_selection($cart_item['hdmmb_selection'])),
        );

        return $item_data;
    }

    public function set_cart_item_price($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (did_action('woocommerce_before_calculate_totals') >= 2) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['hdmmb_selection'])) {
                continue;
            }

            $price = self::calculate_bundle_price($cart_item['data'], $cart_item['hdmmb_selection']);
            $cart_item['data']->set_price($price);
        }
    }

    /**
     * Single source of truth for bundle pricing, shared between the cart total
     * calculation and the AJAX live price preview.
     */
    public static function calculate_bundle_price($bundle_product, array $selection)
    {
        $bundle_id = $bundle_product->get_id();
        $mode      = get_post_meta($bundle_id, '_hdmmb_pricing_mode', true);

        if ('discount_percent' === $mode) {
            $sum = 0;
            foreach ($selection as $component_id => $qty) {
                $component = wc_get_product($component_id);
                if ($component) {
                    $sum += (float) $component->get_price() * (int) $qty;
                }
            }

            $percent = (float) get_post_meta($bundle_id, '_hdmmb_discount_percent', true);
            return round($sum * (1 - ($percent / 100)), wc_get_price_decimals());
        }

        if ('fixed' === $mode) {
            return (float) get_post_meta($bundle_id, '_hdmmb_bundle_price', true);
        }

        return (float) $bundle_product->get_price();
    }

    /**
     * Human-readable "2 x Widget A, 1 x Widget B" string, shared between cart display and order item meta.
     */
    public static function format_selection(array $selection)
    {
        $parts = array();
        foreach ($selection as $component_id => $qty) {
            $component = wc_get_product($component_id);
            if ($component) {
                $parts[] = $qty . ' x ' . $component->get_name();
            }
        }

        return implode(', ', $parts);
    }
}
