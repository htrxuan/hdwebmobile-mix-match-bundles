<?php

namespace htrxuan\hdmmb;

if (!defined('ABSPATH')) {
    exit;
}

class HDMMB_Admin
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
        add_filter('woocommerce_product_data_tabs', array($this, 'add_product_data_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'render_product_data_panel'));
        add_action('woocommerce_process_product_meta_simple', array($this, 'save_product_meta'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        require_once HDMMB_PLUGIN_DIR . 'includes/class-hdmmb-hub.php';
        add_filter('hdwebmobile_hub_tabs', array($this, 'register_hub_tabs'));
    }

    /**
     * This plugin has no per-product-independent settings of its own (everything lives on
     * the product-data tab), so this hub tab exists solely to host the "more plugins by
     * this author" panel -- kept minimal rather than skipped, so every hdwebmobile plugin
     * offers the same discovery path.
     */
    public function register_hub_tabs($tabs)
    {
        $tabs['mix-match-bundles'] = array(
            'label'  => __('Mix & Match Bundles', 'hdwebmobile-mix-match-bundles'),
            'order'  => 90,
            'render' => array($this, 'render_plugins_page'),
        );
        return $tabs;
    }

    public function render_plugins_page()
    {
        ?>
        <p><?php esc_html_e('Let customers build their own box: pick a fixed number of items from a category or curated list on a single WooCommerce product. There\'s nothing to configure here -- go to any simple product\'s own "Mix & Match" tab under Product Data to enable it and choose the eligible items.', 'hdwebmobile-mix-match-bundles'); ?></p>
        <?php
    }

    public function add_product_data_tab($tabs)
    {
        $tabs['hdmmb'] = array(
            'label'    => __('Mix & Match', 'hdwebmobile-mix-match-bundles'),
            'target'   => 'hdmmb_product_data',
            'class'    => array('show_if_simple'),
            'priority' => 21,
        );
        return $tabs;
    }

    public function enqueue_admin_assets($hook)
    {
        if ('post.php' !== $hook && 'post-new.php' !== $hook) {
            return;
        }

        global $post_type;
        if ('product' !== $post_type) {
            return;
        }

        wp_enqueue_script(
            'hdmmb-admin-js',
            HDMMB_PLUGIN_URL . 'assets/js/hdmmb-admin.js',
            array('jquery'),
            HDMMB_VERSION,
            true
        );

        wp_enqueue_style(
            'hdmmb-admin-css',
            HDMMB_PLUGIN_URL . 'assets/css/hdmmb-admin.css',
            array(),
            HDMMB_VERSION
        );
    }

    public function render_product_data_panel()
    {
        global $post;

        $product_id       = $post->ID;
        $enabled          = get_post_meta($product_id, '_hdmmb_enabled', true);
        $pick_qty         = get_post_meta($product_id, '_hdmmb_pick_qty', true);
        $pricing_mode     = get_post_meta($product_id, '_hdmmb_pricing_mode', true) ?: 'fixed';
        $bundle_price     = get_post_meta($product_id, '_hdmmb_bundle_price', true);
        $discount_percent = get_post_meta($product_id, '_hdmmb_discount_percent', true);
        $source_type      = get_post_meta($product_id, '_hdmmb_source_type', true) ?: 'category';
        $source_category  = get_post_meta($product_id, '_hdmmb_source_category', true);
        $source_products  = get_post_meta($product_id, '_hdmmb_source_products', true);
        $source_products  = is_array($source_products) ? $source_products : array();

        wp_nonce_field('hdmmb_save_meta', 'hdmmb_meta_nonce');
        ?>
        <div id="hdmmb_product_data" class="panel woocommerce_options_panel hidden">
            <div class="options_group">
                <?php
                woocommerce_wp_checkbox(array(
                    'id'          => '_hdmmb_enabled',
                    'label'       => __('Enable Mix & Match', 'hdwebmobile-mix-match-bundles'),
                    'description' => __('Let customers build a bundle by picking components on this product\'s page.', 'hdwebmobile-mix-match-bundles'),
                    'value'       => $enabled ?: 'no',
                ));
                ?>
            </div>

            <div class="options_group show_if_hdmmb_enabled">
                <?php
                woocommerce_wp_text_input(array(
                    'id'                => '_hdmmb_pick_qty',
                    'label'             => __('Pick quantity', 'hdwebmobile-mix-match-bundles'),
                    'description'       => __('Customers must select exactly this many units before they can add the bundle to cart.', 'hdwebmobile-mix-match-bundles'),
                    'desc_tip'          => true,
                    'type'              => 'number',
                    'custom_attributes' => array('step' => '1', 'min' => '1'),
                    'value'             => $pick_qty ?: '',
                ));

                woocommerce_wp_radio(array(
                    'id'      => '_hdmmb_pricing_mode',
                    'label'   => __('Pricing mode', 'hdwebmobile-mix-match-bundles'),
                    'options' => array(
                        'fixed'            => __('Fixed bundle price', 'hdwebmobile-mix-match-bundles'),
                        'discount_percent' => __('Percentage off component total', 'hdwebmobile-mix-match-bundles'),
                    ),
                    'value'   => $pricing_mode,
                ));

                woocommerce_wp_text_input(array(
                    'id'            => '_hdmmb_bundle_price',
                    /* translators: %s: store currency symbol */
                    'label'         => sprintf(__('Bundle price (%s)', 'hdwebmobile-mix-match-bundles'), get_woocommerce_currency_symbol()),
                    'data_type'     => 'price',
                    'wrapper_class' => 'show_if_pricing_fixed',
                    'value'         => $bundle_price,
                ));

                woocommerce_wp_text_input(array(
                    'id'                => '_hdmmb_discount_percent',
                    'label'             => __('Discount percent', 'hdwebmobile-mix-match-bundles'),
                    'type'              => 'number',
                    'custom_attributes' => array('step' => '0.01', 'min' => '0', 'max' => '100'),
                    'wrapper_class'     => 'show_if_pricing_discount',
                    'value'             => $discount_percent !== '' ? $discount_percent : '',
                ));

                woocommerce_wp_radio(array(
                    'id'      => '_hdmmb_source_type',
                    'label'   => __('Component source', 'hdwebmobile-mix-match-bundles'),
                    'options' => array(
                        'category' => __('Product category', 'hdwebmobile-mix-match-bundles'),
                        'manual'   => __('Manual product list', 'hdwebmobile-mix-match-bundles'),
                    ),
                    'value'   => $source_type,
                ));
                ?>

                <p class="form-field show_if_source_category">
                    <label for="_hdmmb_source_category"><?php esc_html_e('Category', 'hdwebmobile-mix-match-bundles'); ?></label>
                    <?php
                    wp_dropdown_categories(array(
                        'taxonomy'         => 'product_cat',
                        'name'             => '_hdmmb_source_category',
                        'id'               => '_hdmmb_source_category',
                        'class'            => 'wc-enhanced-select',
                        'show_option_none' => __('Select a category&hellip;', 'hdwebmobile-mix-match-bundles'),
                        'selected'         => $source_category,
                        'hide_empty'       => false,
                    ));
                    ?>
                </p>

                <p class="form-field show_if_source_manual">
                    <label for="_hdmmb_source_products"><?php esc_html_e('Eligible products', 'hdwebmobile-mix-match-bundles'); ?></label>
                    <select
                        id="_hdmmb_source_products"
                        name="_hdmmb_source_products[]"
                        class="wc-product-search"
                        multiple="multiple"
                        style="width:50%;"
                        data-placeholder="<?php esc_attr_e('Search for products&hellip;', 'hdwebmobile-mix-match-bundles'); ?>"
                        data-action="woocommerce_json_search_products_and_variations"
                        data-exclude="<?php echo intval($product_id); ?>"
                    >
                        <?php
                        foreach ($source_products as $product_id_option) {
                            $option_product = wc_get_product($product_id_option);
                            if ($option_product) {
                                echo '<option value="' . esc_attr($product_id_option) . '" selected="selected">' . esc_html($option_product->get_formatted_name()) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </p>
            </div>
        </div>
        <?php
    }

    public function save_product_meta($post_id)
    {
        if (!isset($_POST['hdmmb_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hdmmb_meta_nonce'])), 'hdmmb_save_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $enabled = isset($_POST['_hdmmb_enabled']) ? 'yes' : 'no';
        update_post_meta($post_id, '_hdmmb_enabled', $enabled);

        if (isset($_POST['_hdmmb_pick_qty'])) {
            update_post_meta($post_id, '_hdmmb_pick_qty', absint($_POST['_hdmmb_pick_qty']));
        }

        if (isset($_POST['_hdmmb_pricing_mode'])) {
            $pricing_mode = sanitize_text_field(wp_unslash($_POST['_hdmmb_pricing_mode']));
            $pricing_mode = in_array($pricing_mode, array('fixed', 'discount_percent'), true) ? $pricing_mode : 'fixed';
            update_post_meta($post_id, '_hdmmb_pricing_mode', $pricing_mode);
        }

        if (isset($_POST['_hdmmb_bundle_price'])) {
            update_post_meta($post_id, '_hdmmb_bundle_price', wc_format_decimal(sanitize_text_field(wp_unslash($_POST['_hdmmb_bundle_price']))));
        }

        if (isset($_POST['_hdmmb_discount_percent'])) {
            update_post_meta($post_id, '_hdmmb_discount_percent', wc_format_decimal(sanitize_text_field(wp_unslash($_POST['_hdmmb_discount_percent']))));
        }

        if (isset($_POST['_hdmmb_source_type'])) {
            $source_type = sanitize_text_field(wp_unslash($_POST['_hdmmb_source_type']));
            $source_type = in_array($source_type, array('category', 'manual'), true) ? $source_type : 'category';
            update_post_meta($post_id, '_hdmmb_source_type', $source_type);
        }

        if (isset($_POST['_hdmmb_source_category'])) {
            update_post_meta($post_id, '_hdmmb_source_category', absint($_POST['_hdmmb_source_category']));
        }

        $source_products = isset($_POST['_hdmmb_source_products']) ? array_map('absint', (array) $_POST['_hdmmb_source_products']) : array();
        update_post_meta($post_id, '_hdmmb_source_products', $source_products);
    }
}
