<?php
/**
 * Name:    WooCommerce make order on the fly
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LicProlong
{

    private static $instance;

    /** @var false|int */
    public $base_product;

    /** @var string */
    public $renewal_period;

    /** @var bool */
    public $bs4;


    /**
     * @return LicProlong
     */
    public static function I() {
        if ( static::$instance === null ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    private function __construct(){
        $this->base_product = !empty(get_option('wc_pus_product')) ? absint(get_option('wc_pus_product')) : null;
        $this->renewal_period = get_option('wc_pus_renewal_period', null);
        $this->bs4 = (bool) get_option('wc_pus_bs4', false);
    }

    /**
     * Add actions
     *
     * @return void
     * @throws Exception
     */
    public function add_actions(){

        add_action( 'admin_menu', function (){
            $function    = array( $this, 'plugin_help_page' );
            $page_title  = __( 'WP Plugin Update Server - WcooCommerce', 'wppus' );
            $menu_title  = __( 'WooCommerce', 'wppus' );
            $menu_slug   = 'wppus-page-wc';
            $capability  = 'manage_options';
            $parent_slug = 'wppus-page';
            add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function );
        }, 199, 0 );

        /* 1. Add lic key to product. Make order with product. */
        add_action('template_redirect', [$this, 'checkout_render']);

        /* 2. Display information as Meta on Cart page. */
        add_filter('woocommerce_get_item_data', [$this, 'woocommerce_get_item_data'], 99, 2);

        /* 3. Save to order data. Showing on order received page. */
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'woocommerce_checkout_create_order_line_item'],9,4);
    }

    public function checkout_render(){
        if(!is_checkout() || empty($this->base_product) || !isset($_GET['wcpus_lic'])){
            return;
        }
        $lic = $_GET['wcpus_lic'];
        $lic_data = $this->get_old_lic_data($lic);
        WC()->cart->empty_cart(true);
        WC()->cart->add_to_cart($this->base_product, 1, 0, [], [
            'wc_pus_license'     => $lic,
            'wc_pus_label'       => LicHelper::get_types($lic_data->package_type) .' '. $lic_data->package_slug,
            'wc_pus_public_info' => substr($lic, 0, 5) .
                implode('', array_fill(1, mb_strlen($lic, 'UTF-8') - 8, 'x')) .
                substr($lic, -3, 3),
            'wc_pus_license_data' => $lic_data
        ]);
    }

    public function woocommerce_get_item_data(array $item_data, array $cart_item){
        if(isset($cart_item['wc_pus_license'])){
            $item_data[] = [
                'key'   => $cart_item['wc_pus_label'], // showing
                'value' => $cart_item['wc_pus_public_info'], // showing
                'wc_pus_license' => $cart_item['wc_pus_license']
            ];
        }
        return $item_data;
    }

    public function woocommerce_checkout_create_order_line_item(WC_Order_Item_Product $item, $cart_item_key, $cart_items, $order){
        if(isset($cart_items['wc_pus_license'])){
            $item->add_meta_data($cart_items['wc_pus_label'], $cart_items['wc_pus_public_info']);
            $item->add_meta_data('_wc_pus_license', $cart_items['wc_pus_license']);
        }
        return $item;
    }

    /**
     * Render settings page. ADM
     *
     * @return void
     */
    public function plugin_help_page() {

        $chosen = $this->base_product;
        $products = ['' => ''] + get_posts(['post_type' => 'product']);

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Sorry, you are not allowed to access this page.' ) ); // @codingStandardsIgnoreLine
        }

        if(isset($_POST['wc_pus_product'])){
            $chosen = is_numeric($_POST['wc_pus_product']) ? absint($_POST['wc_pus_product']) : '';
            update_option('wc_pus_product', $chosen);
        }

        if(isset($_POST['wc_pus_renewal_period'])){
            $this->renewal_period = absint($_POST['wc_pus_renewal_period']);
            update_option('wc_pus_renewal_period', $this->renewal_period);
        }

        if(isset($_POST['wc_pus_bs4'])){
            $this->bs4 = 'on' === $_POST['wc_pus_bs4'] ? 1 : 0;
            update_option('wc_pus_bs4', $this->bs4);
        }

        ob_start();
        ?>
        <div class="wrap wppus-wrap">
            <h1><?php esc_html_e('WP Plugin Update Server', 'wppus'); ?></h1>
            <h2 class="nav-tab-wrapper">
                <a href="admin.php?page=wppus-page" class="nav-tab">
                    <span class='dashicons dashicons-welcome-view-site'></span> <?php esc_html_e('Overview', 'wppus'); ?>
                </a>
                <a href="admin.php?page=wppus-page-remote-sources" class="nav-tab">
                    <span class='dashicons dashicons-networking'></span> <?php esc_html_e('Remote Sources', 'wppus'); ?>
                </a>
                <a href="admin.php?page=wppus-page-licenses" class="nav-tab">
                    <span class='dashicons dashicons-admin-network'></span> <?php esc_html_e('Licenses', 'wppus'); ?>
                </a>
                <a href="admin.php?page=wppus-page-help" class="nav-tab nav-tab">
                    <span class='dashicons dashicons-editor-help'></span> <?php esc_html_e('Help', 'wppus'); ?>
                </a>
                <a href="admin.php?page=wppus-page-wc" class="nav-tab nav-tab-active">
                    <span class='dashicons dashicons-cart'></span> <?php esc_html_e('WooCommerce', 'wc-pus'); ?>
                </a>
            </h2>
            <h2><?php esc_html_e('WooCommerce integration settings', 'wc-pus'); ?></h2>
            <form action="" method="post">
                <p>
                    <label for="wc_pus_bs4"><?php esc_html_e('Enable bootstrap styles capability?', 'wc-pus') ?></label>
                    <input type="hidden" name="wc_pus_bs4" value="0" />
                    <input id="wc_pus_bs4" name="wc_pus_bs4" type="checkbox" <?= $this->bs4 ? 'checked' : ''; ?> >
                </p>
                <hr>
                <h3><?php esc_html_e('Renewal settings', 'wc-pus'); ?></h3>
                <p><label><?php esc_html_e('Renewal period in days', 'wc-pus'); ?>: <input name="wc_pus_renewal_period" type="number" min="0" max="99999" value="<?=$this->renewal_period?>"></label></p>
                <p><label for="wc_pus_product"><?php _e('Base product as prolongation', 'wc-pus') ?>:</label><br/>
                    <select id="wc_pus_product" name="wc_pus_product" style="width:99%; max-width:25em;">
                        <?php
                        foreach ($products as $product) {
                            $selected = ( isset($product->ID) && $product->ID === $chosen) ? ' selected="selected"' : '';
                            if(empty($product)) {
                                echo '<option value="' . $product . '"' . $selected . '>' .  $product . '</option>';
                                continue;
                            }
                            $wc_product = wc_get_product($product);
                            echo '<option value="' . $product->ID . '"' . $selected . '>' .  $wc_product->get_name() .' - '. $wc_product->get_price() . '</option>';
                        } ?>
                    <select>
                    <button type="submit" class="close-panel button button-primary"><?php esc_html_e('Save', 'wc-pus'); ?></button>
                </p>
            </form>
        </div>
        <?php
        echo ob_get_clean(); // @codingStandardsIgnoreLine
    }

    /**
     * Render renewal link
     *
     * @param array $lic
     * @return void
     */
    public function render_renewal_checkout_link($lic)
    {
        $product = wc_get_product($this->base_product);

        $bs4['class'] = LicProlong::I()->bs4 ? 'btn btn-outline-primary' : 'button';
        $bs4['icon'] = LicProlong::I()->bs4 ? '<i class="las la-cart-arrow-down"></i>' : '';

        $html = '<small>' . sprintf(esc_html__('Your can renew this license key until: %s', 'wc-pus'),
                '<strong>' . $this->get_renewal_period((object)$lic) . '</strong>') . '</small>';
        $html .= '</td><td colspan="2" style="text-align: right">
        <a href="' . wc_get_checkout_url() . '?wcpus_lic=' . $lic['license_key'] . '"  
        class="' . $bs4['class'] . ' renew">' . $bs4['icon'] .
            sprintf(esc_html__('Renew license for %s', 'wc-pus'),
                '<span style="margin-left: 5px">' . $product->get_price_html() . '</span>') . '</a>';

        return apply_filters('wc_pus_renew_link', $html);
    }


    /**
     * Set new renewal period
     *
     * @param stdClass $old_lic
     * @return string num days
     */
    public function get_renewal_period($old_lic){
        $days = LicProlong::I()->renewal_period;
        if(strtotime('now') > strtotime($old_lic->date_expiry) ){ // license has already expired
            $period = date('Y-m-d', strtotime('+'.$days.' days'));
        } else {
            $period = date('Y-m-d', strtotime('+'.$days.' days', strtotime($old_lic->date_expiry)));
        }
        return $period;
    }


    /**
     * Return today day
     * @return false|int|string
     */
    private function get_date_renewed(){
        return mysql2date('Y-m-d', current_time('mysql'), false);
    }


    /**
     * Get old licence data from DB
     *
     * @param string $lic_key
     * @return stdClass|null
     */
    public function get_old_lic_data($lic_key){
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wppus_licenses WHERE `license_key` = '{$lic_key}'")[0] ?? null;
    }

    /**
     * Update old lic data
     *
     * @param stdClass $old_lic_data
     * @return bool|int|mysqli_result|resource|null
     */
    public function update_old_lic(stdClass $old_lic_data){
        global $wpdb;
        $renewal_period = $this->get_renewal_period($old_lic_data);
        return $wpdb->update("{$wpdb->prefix}wppus_licenses",
            ['date_expiry' => $renewal_period, 'date_renewed' => $this->get_date_renewed()],['id' => $old_lic_data->id] );
    }

}