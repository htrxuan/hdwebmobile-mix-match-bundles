<?php

namespace htrxuan\hdmmb;

if (!defined('ABSPATH')) {
    exit;
}

class HDMMB_Frontend
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
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_assets'));
        // Priority 5, before the default renderer at priority 30 (see woocommerce_simple_add_to_cart
        // in wc-template-hooks.php). This hook is the common path for both the classic single-product
        // template and WooCommerce's blockified "Add to Cart Form" block, which internally triggers
        // the same action rather than woocommerce_single_product_summary.
        add_action('woocommerce_simple_add_to_cart', array($this, 'maybe_replace_add_to_cart'), 5);
    }

    private function get_current_bundle_product()
    {
        if (!is_product()) {
            return null;
        }

        $product = wc_get_product(get_queried_object_id());
        if (!$product || 'yes' !== get_post_meta($product->get_id(), '_hdmmb_enabled', true)) {
            return null;
        }

        return $product;
    }

    public function maybe_enqueue_assets()
    {
        $product = $this->get_current_bundle_product();
        if (!$product) {
            return;
        }

        wp_enqueue_style(
            'hdmmb-frontend-css',
            HDMMB_PLUGIN_URL . 'assets/css/hdmmb-frontend.css',
            array(),
            HDMMB_VERSION
        );

        wp_enqueue_script(
            'hdmmb-frontend-js',
            HDMMB_PLUGIN_URL . 'assets/js/hdmmb-frontend.js',
            array(),
            HDMMB_VERSION,
            true
        );

        wp_localize_script('hdmmb-frontend-js', 'hdmmbParams', array(
            'ajax_url'   => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('hdmmb_frontend_nonce'),
            'product_id' => $product->get_id(),
            'pick_qty'   => absint(get_post_meta($product->get_id(), '_hdmmb_pick_qty', true)),
            'i18n'       => array(
                /* translators: %1$d: selected count, %2$d: required count */
                'progress' => __('Selected: %1$d / %2$d', 'hdwebmobile-mix-match-bundles'),
            ),
        ));
    }

    public function maybe_replace_add_to_cart()
    {
        global $product;

        if (!$product instanceof \WC_Product || 'yes' !== get_post_meta($product->get_id(), '_hdmmb_enabled', true)) {
            return;
        }

        remove_action('woocommerce_simple_add_to_cart', 'woocommerce_simple_add_to_cart', 30);
        $this->render_bundle_picker();
    }

    private function get_components($product)
    {
        $source_type = get_post_meta($product->get_id(), '_hdmmb_source_type', true) ?: 'category';

        if ('manual' === $source_type) {
            $ids = get_post_meta($product->get_id(), '_hdmmb_source_products', true);
            $ids = is_array($ids) ? $ids : array();
        } else {
            $category_id = absint(get_post_meta($product->get_id(), '_hdmmb_source_category', true));
            $ids         = $category_id ? wc_get_products(array(
                'product_category_id' => array($category_id),
                'status'              => 'publish',
                'limit'               => -1,
                'return'              => 'ids',
            )) : array();
        }

        $components = array();
        foreach ($ids as $id) {
            $component = wc_get_product($id);
            if ($component && $component->is_purchasable()) {
                $components[] = $component;
            }
        }

        return $components;
    }

    public function render_bundle_picker()
    {
        global $product;

        $components = $this->get_components($product);
        $pick_qty   = absint(get_post_meta($product->get_id(), '_hdmmb_pick_qty', true));

        if (empty($components) || !$pick_qty) {
            // Fall back to the normal simple-product add-to-cart template (already removed
            // from this action by maybe_replace_add_to_cart(), so call it directly here).
            woocommerce_simple_add_to_cart();
            return;
        }
        ?>
        <form class="cart hdmmb-picker" action="<?php echo esc_url(apply_filters('woocommerce_add_to_cart_form_action', get_permalink($product->get_id()))); /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- this is WooCommerce core's own filter, not one we define */ ?>" method="post" enctype="multipart/form-data">
            <p class="hdmmb-picker__instructions">
                <?php
                printf(
                    /* translators: %d: required quantity */
                    esc_html__('Choose exactly %d items to build your bundle:', 'hdwebmobile-mix-match-bundles'),
                    absint($pick_qty)
                );
                ?>
            </p>

            <div class="hdmmb-picker__grid">
                <?php foreach ($components as $component) : ?>
                    <div class="hdmmb-picker__item" data-product-id="<?php echo esc_attr($component->get_id()); ?>" data-price="<?php echo esc_attr($component->get_price()); ?>" data-max-stock="<?php echo esc_attr($component->managing_stock() ? $component->get_stock_quantity() : ''); ?>">
                        <?php echo wp_kses_post($component->get_image('thumbnail')); ?>
                        <div class="hdmmb-picker__item-name"><?php echo esc_html($component->get_name()); ?></div>
                        <div class="hdmmb-picker__item-price"><?php echo wp_kses_post($component->get_price_html()); ?></div>
                        <div class="hdmmb-picker__stepper">
                            <button type="button" class="hdmmb-picker__stepper-minus">&minus;</button>
                            <input type="number" class="hdmmb-picker__stepper-input" name="hdmmb_selection[<?php echo esc_attr($component->get_id()); ?>]" value="0" min="0" readonly="readonly" />
                            <button type="button" class="hdmmb-picker__stepper-plus">+</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="hdmmb-picker__progress" data-pick-qty="<?php echo esc_attr($pick_qty); ?>">
                <?php
                /* translators: %1$d: number of items selected so far, %2$d: number required */
                echo esc_html(sprintf(__('Selected: %1$d / %2$d', 'hdwebmobile-mix-match-bundles'), 0, $pick_qty));
                ?>
            </p>

            <p class="hdmmb-picker__price-preview"></p>

            <input type="hidden" name="add-to-cart" value="<?php echo esc_attr($product->get_id()); ?>" />
            <input type="hidden" name="quantity" value="1" />

            <button type="submit" class="single_add_to_cart_button button alt hdmmb-picker__submit" disabled="disabled">
                <?php echo esc_html($product->single_add_to_cart_text()); ?>
            </button>
        </form>
        <?php
    }
}
