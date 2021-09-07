<?php


final class Lic_Manager
{
    public const key = '_wc_slm_payment_licenses';

    public function __construct(){
        $this->add_actions();
    }

    public function add_actions(){
        // 1 - call
        add_action('woocommerce_order_status_completed',                [$this, 'create_license_keys'], 20, 1);
        // 2 - call
        add_action('woocommerce_email_before_order_table',              [$this, 'email_content'], 10, 2);
        // 3 - call
        add_action('woocommerce_order_details_before_order_table',      [$this, 'print_order_meta'], 10, 1);
    }

    /**
     * Generate licences and save to order data
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public function create_license_keys( int $order_id)
    {
        $payment_meta = $licenses = [];
        $order = wc_get_order($order_id);
        if ( ! $order ) { return; }

        $user_id = $order->get_user_id();
        $get_user_meta = get_user_meta($user_id);

        if(!is_array($get_user_meta)){
            error_log('get_user_meta() failed!'); return;
        }

        $payment_meta['user_info']['first_name']    = $get_user_meta['billing_first_name'][0];
        $payment_meta['user_info']['last_name']     = $get_user_meta['billing_last_name'][0] ?? '';
        $payment_meta['user_info']['email']         = $get_user_meta['billing_email'][0];
        $payment_meta['user_info']['company']       = $get_user_meta['billing_company'][0] ?? '';

        // Generate license keys for all products in order
        $items = $order->get_items();
        foreach ($items as $item => $values) {

            $product_id = $values['product_id'];
            $product = new WC_Product($product_id);

            if ( !Lic_Settings::get_licensing_enabled($product_id) || !$product->is_downloadable() ) {
                continue; // Лицензии выключены или товар не имеет разрешения на загрузку
            }

            $download_quantity = absint($values['qty']);
            for ($i = 1; $i <= $download_quantity; $i++) {

                $renewal_period = Lic_Settings::get_renewal_period($product_id);
                if ($renewal_period == 0) {
                    $renewal_period = '0000-00-00';
                } else {
                    $renewal_period = date('Y-m-d', strtotime('+' . $renewal_period . ' years'));
                }

                // Sites allowed
                $sites_allowed = Lic_Settings::get_sites_allowed($product_id);
                if (!$sites_allowed) {
                    $sites_allowed_error = __('License could not be created: Invalid sites allowed number.', 'wc-pus');
                    $this->add_order_note($order_id, $sites_allowed_error);
                    break;
                }

                $product_slug = Lic_Settings::get_slug($product_id);
                if (!$product_slug) {
                    $this->add_order_note($order_id, __('License could not be created: Invalid product slug.', 'wc-pus'));
                    break;
                }

                $product_type = Lic_Settings::get_type($product_id);
                if (!$product_type) {
                    $this->add_order_note($order_id, __('License could not be created: Invalid product type.', 'wc-pus'));
                    break;
                }

                // Build item name
                $item_name = $product->get_title();
                $owner_name = (isset($payment_meta['user_info']['first_name'])) ? $payment_meta['user_info']['first_name'] : '';
                $owner_name .= " " . (isset($payment_meta['user_info']['last_name'])) ? $payment_meta['user_info']['last_name'] : '';

                // Build parameters
                $api_params = [];
                $api_params['linknonce'] = wp_create_nonce('linknonce');
                $api_params['wppus_license_action'] = 'create';
                $api_params['page'] = 'wppus-page-licenses';
                $api_params['wppus_license_action'] = 'create';

                $payload = [
                    // default data
                    'license_key'   => bin2hex(openssl_random_pseudo_bytes(16)),
                    'date_created'  => mysql2date('Y-m-d', current_time('mysql'), false),
                    'status'        => 'pending',
                    // setup
                    'max_allowed_domains' => $sites_allowed,
                    'email'         => (isset($payment_meta['user_info']['email'])) ? $payment_meta['user_info']['email'] : '',
                    'date_renewed'  => $renewal_period,
                    'date_expiry'   => $renewal_period,
                    'package_slug'  => $product_slug,
                    'package_type'  => $product_type,
                    'owner_name'    => $owner_name,
                    'company_name'  => $payment_meta['user_info']['company'],
                    'txn_id' => (string)$order_id,
                    // custom
                    'first_name' => (isset($payment_meta['user_info']['first_name'])) ? $payment_meta['user_info']['first_name'] : '',
                    'last_name' => (isset($payment_meta['user_info']['last_name'])) ? $payment_meta['user_info']['last_name'] : '',
                ];

                $lic_manager = new WPPUS_License_Server();
                $result = $lic_manager->add_license($payload);

                // Collect license keys
                if (isset($result->license_key)) {
                    $licenses[] = [
                        'item'      => $item_name,
                        'key'       => $result->license_key,
                        'expires'   => $renewal_period,
                        'lic_id'    => $result->id
                    ];
                }
            }
        }


        if (count($licenses) !== 0) {
            update_post_meta($order_id, Lic_Manager::key, $licenses);
        }

        if (count($licenses) !== 0) {
            $message = __('License Key(s) generated', 'wc-slm');
            foreach ($licenses as $license) {
                $message .= '<br />' . $license['item'] . ': ' . $license['key'];
            }
        } else {
            $message = __('License Key(s) could not be created.', 'wc-slm');
        }

        self::add_order_note($order_id, $message);
    }

    /** Add note to order */
    public static function add_order_note( int $order_id, string $note){
        $order = wc_get_order($order_id);
        $order->add_order_note($note);
    }

    /**
     * Add license details to user account details
     *
     * @param WC_Order $order
     */
    public function print_order_meta(WC_Order $order){
        $licenses = get_post_meta($order->get_id(), Lic_Manager::key, true);
        if ($licenses && count($licenses) != 0) {
            $output = '<h2>' . __('Your Licenses', 'wc-pus') . ':</h2>';
            $output .= '<table class="shop_table shop_table_responsive"><tr><th class="td">' . __('Item', 'wc-pus') . '</th><th class="td">' . __('License', 'wc-pus') . '</th></tr>';
            foreach ($licenses as $license) {
                $output .= '<tr>';
                if (isset($license['item']) && isset($license['key'])) {
                    $output .= '<td class="td">' . $license['item'] . '</td>';
                    $output .= '<td class="td">' . $license['key'] . '</td>';
                } else {
                    $output .= __('No item and key assigned', 'wc-pus');
                }
                $output .= '</tr>';
            }
            $output .= '</table>';
        }

        if (isset($output)) {
            echo $output;
        }
    }

    /**
     * Email
     * @param WC_Order $order
     * @param bool $is_admin_email
     */
    public function email_content( WC_Order $order, $is_admin_email)
    {
        if ('wc-completed' === $order->get_status() || 'completed' === $order->get_status() ) {
            $output = '';

            // Check if licenses were generated
            $licenses = get_post_meta($order->get_id(), Lic_Manager::key, true);

            if ($licenses && count($licenses) != 0) {
                $output = '<h3>' . __('Your Licenses', 'wc-pus') . ':</h3><table><tr><th class="td">' . __('Item', 'wc-pus') . '</th><th class="td">' . __('License', 'wc-pus') . '</th><th class="td">' . __('Expire Date', 'wc-pus') . '</th></tr>';
                foreach ($licenses as $license) {
                    $output .= '<tr>';
                    if (isset($license['item']) && isset($license['key'])) {
                        if ($output) {
                            $output .= '<br />';
                        }
                        $output .= '<td class="td">' . $license['item'] . '</td>';
                        $output .= '<td class="td">' . $license['key'] . '</td>';
                    } else {
                        // $output .= 'No item and key assigned';
                    }
                    /**
                     * added expire date in table
                     * @since       1.0.7
                     * @author      AvdP (Albert van der Ploeg)
                     */
                    if (isset($license['expires'])) {
                        $output .= '<td class="td">' . $license['expires'] . '</td>';
                    }
                    $output .= '</tr>';
                }
                $output .= '</table>';
            } else {
                // $output .= 'No License Generatred';
            }
            echo $output;
        }
    }

    public static function get_types(){
        return [
            'plugin' => __('Plugin', 'wc-pus'),
            'theme'  => __('Theme', 'wc-pus'),
        ];
    }
}