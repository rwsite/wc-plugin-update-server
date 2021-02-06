<?php
/**
 * Class Software_Licence_Manager_integration
 */

class Software_Licence_Manager_integration
{
    /** @var string - meta key */
    public static $key = 'slm_migration';

    public function __construct(){
        $this->slm_migration();
    }

    /**
     * Run migration
     */
    public function slm_migration(){

        if ( true != get_option( 'wppus_use_licenses' ) || true == get_option('slm_migration') ) {
            return null;
        }

        global $wpdb;

        $new_table = $wpdb->prefix.'wppus_licenses';

        if(!defined(SLM_TBL_LICENSE_KEYS)) {
            $old_table = $wpdb->prefix . 'lic_key_tbl';
        } else {
            $old_table = SLM_TBL_LICENSE_KEYS;
        }

        if(!defined(SLM_TBL_LIC_DOMAIN)){
            $old_domain_table = $wpdb->prefix.'lic_reg_domain_tbl';
        } else {
            $old_domain_table = SLM_TBL_LIC_DOMAIN;
        }

        $result = $wpdb->get_results("SELECT * FROM `$old_table`");

        foreach ($result as $item){
            $pre_domains = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$old_domain_table` WHERE `lic_key` LIKE '%s'", $item->license_key));
            $item->allowed_domains = [];
            foreach ($pre_domains as $domain){
                $item->allowed_domains[] = $domain->registered_domain;
            }
            /**
             * @var object - <li>
             * @property int id
             * @property string license_key
             * @property array max_allowed_domains
             * @property string lic_status
             * @property string first_name
             * @property string last_name
             * @property string email
             * @property string company_name
             * @property string txn_id
             * @property string manual_reset_count
             * @property string date_created
             * @property string date_renewed
             * @property string date_expiry
             * @property string product_ref
             * @property string subscr_id
             * </li>
             */

            $item->product_ref = 'woo-to-iiko';
            $item->package_type = 'plugin';

            if( isset($item->lic_status) && 'active' == $item->lic_status){
                $item->lic_status = 'activated';
            } elseif ('pending' == $item->lic_status){
                $item->lic_status = 'pending';
            } elseif ( 'expired' == $item->lic_status){
                $item->lic_status = 'expired';
            } elseif ( 'blocked' == $item->lic_status){
                $item->lic_status = 'blocked';
            } else {
                $item->lic_status = '';
            }

            $item = apply_filters('slm_migration_item', $item);

            $wpdb->get_results($wpdb->prepare("INSERT INTO `$new_table` 
(`id`, `license_key`, `max_allowed_domains`, `allowed_domains`, `status`, `owner_name`, `email`, `company_name`, `txn_id`, `date_created`, `date_renewed`, `date_expiry`, `package_slug`, `package_type`) VALUES 
(%s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s);",
            null, $item->license_key, $item->max_allowed_domains,
                serialize($item->allowed_domains),
                $item->lic_status,
                $item->first_name . ' ' . $item->last_name,
                $item->email, $item->company_name, $item->txn_id, $item->date_created,
                $item->date_renewed, $item->date_expiry, $item->product_ref, $item->package_type
            ));
        }

        do_action('slm_migration', $wpdb, $result, $new_table, $old_table, $old_domain_table);

        error_log('SLM Migration complete. All items: ' . count( $result));

        update_option('slm_migration', true);
    }
}