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
        add_action('woocommerce_check_cart_items', array($this, 'check_component_stock'));
    }

    private function has_valid_nonce()
    {
        return isset($_POST['hdmmb_add_to_cart_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hdmmb_add_to_cart_nonce'])), 'hdmmb_add_to_cart');
    }

    /**
     * Read and sanitize the posted selection into [product_id => qty], dropping zero/invalid entries.
     */
    private function get_posted_selection()
    {
        // Verified inline (not via has_valid_nonce()) so it visibly precedes every read of the
        // form data. Without a valid nonce from our own render_bundle_picker() form, treat it as
        // if nothing was selected; validate_selection() reports that case with its own message.
        if (
            !isset($_POST['hdmmb_add_to_cart_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hdmmb_add_to_cart_nonce'])), 'hdmmb_add_to_cart')
        ) {
            return array();
        }

        if (empty($_POST['hdmmb_selection']) || !is_array($_POST['hdmmb_selection'])) {
            return array();
        }

        $selection = array();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce-verified above; every product_id/qty pair is cast with absint() immediately below and the product_id is re-checked against get_eligible_component_ids() by callers before anything is trusted.
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
            $ids             = is_array($source_products) ? array_map('absint', $source_products) : array();
        } else {
            $category_id = absint(get_post_meta($bundle_id, '_hdmmb_source_category', true));
            if (!$category_id) {
                return array();
            }

            $ids = array_map('absint', wc_get_products(array(
                'product_category_id' => array($category_id),
                'status'              => 'publish',
                'limit'               => -1,
                'return'              => 'ids',
            )));
        }

        return array_values(array_filter($ids, function ($id) use ($bundle_id) {
            return self::is_allowed_component($id, $bundle_id);
        }));
    }

    /**
     * A bundle can't contain itself or another bundle, and grouped/external products have
     * nothing to put in a box. With category sourcing the bundle product is often in that
     * same category, which used to list the bundle as one of its own components.
     */
    private static function is_allowed_component($component_id, $bundle_id)
    {
        if (!$component_id || (int) $component_id === (int) $bundle_id) {
            return false;
        }

        $component = wc_get_product($component_id);
        if (!$component || $component->is_type(array('grouped', 'external'))) {
            return false;
        }

        return 'yes' !== get_post_meta($component->get_id(), '_hdmmb_enabled', true);
    }

    /**
     * Whether a component can be picked right now. is_purchasable() alone lets through
     * products marked "Out of stock" that don't use stock quantities.
     */
    public static function is_available_component($component)
    {
        return $component && $component->is_purchasable() && $component->is_in_stock();
    }

    public function validate_selection($passed, $product_id, $quantity)
    {
        if ('yes' !== get_post_meta($product_id, '_hdmmb_enabled', true)) {
            return $passed;
        }

        if (!$this->has_valid_nonce()) {
            // Typically a product page served from a page cache long enough for its nonce to expire.
            wc_add_notice(__('Your session has expired. Please reload the page and choose your items again.', 'hdwebmobile-mix-match-bundles'), 'error');
            return false;
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
            if (!self::is_available_component($component)) {
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

        // No once-per-request guard: setting the price is idempotent, and the Cart/Checkout
        // blocks recalculate totals several times in one Store API request.
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

        $bundle_price = get_post_meta($bundle_id, '_hdmmb_bundle_price', true);
        if ('fixed' === $mode && '' !== $bundle_price) {
            return (float) $bundle_price;
        }

        // Fixed mode with an empty "Bundle price" used to sell the bundle for 0 -- fall back
        // to the product's own price instead.
        return (float) $bundle_product->get_price();
    }

    /**
     * Add-to-cart only checks one bundle's worth of stock. Changing the bundle quantity in the
     * cart, adding several bundles, or buying a component on its own as well can need more
     * than is in stock, so check the combined demand per component before checkout.
     */
    public function check_component_stock()
    {
        $cart = WC()->cart;
        if (!$cart) {
            return;
        }

        $needed = array();
        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['hdmmb_selection'])) {
                foreach ($cart_item['hdmmb_selection'] as $component_id => $qty) {
                    $needed[$component_id] = ($needed[$component_id] ?? 0) + $qty * $cart_item['quantity'];
                }
            }
        }

        if (!$needed) {
            return;
        }

        // Standalone cart lines for the same products draw on the same stock.
        foreach ($cart->get_cart() as $cart_item) {
            $id = $cart_item['variation_id'] ?: $cart_item['product_id'];
            if (isset($needed[$id])) {
                $needed[$id] += $cart_item['quantity'];
            }
        }

        foreach ($needed as $component_id => $qty) {
            $component = wc_get_product($component_id);
            if ($component && $component->managing_stock() && !$component->has_enough_stock($qty)) {
                wc_add_notice(
                    sprintf(
                        /* translators: 1: product name, 2: quantity in stock */
                        __('Not enough "%1$s" in stock for the bundles in your cart (%2$s available). Please reduce the bundle quantity.', 'hdwebmobile-mix-match-bundles'),
                        $component->get_name(),
                        wc_format_stock_quantity_for_display($component->get_stock_quantity(), $component)
                    ),
                    'error'
                );
            }
        }
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
