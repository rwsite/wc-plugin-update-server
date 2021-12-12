<?php
/**
 * Settings.
 *
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

final class Lic_Settings
{
    public static $section_id = 'products';

    public static $licensing_enabled    = '_wc_slm_licensing_enabled';
    public static $sites_allowed        = '_wc_slm_sites_allowed';
    public static $renewal_period       = '_wc_slm_licensing_renewal_period';
    public static $type                 = '_wc_slm_type';
    public static $slug                 = '_wc_slm_slug';

    public function __construct(){
        // Admin product settings
        if(is_admin()) {
            add_action('woocommerce_product_options_general_product_data', [$this, 'show_options']);
            add_action('woocommerce_process_product_meta', [$this, 'save_options']);
        }
    }

    /**
     * Show options values
     */
    public function show_options() {
        global $woocommerce, $post;

        $post_id = $post->ID;
        $licensing_enabled = get_post_meta($post_id, self::$licensing_enabled, true) ? true : false;
        $sites_allowed = esc_attr(get_post_meta($post_id, self::$sites_allowed, true));
        $licensing_renewal_period = esc_attr(get_post_meta($post_id, self::$renewal_period, true));
        $product_type = esc_attr(get_post_meta($post_id, self::$type, true));
        $product_slug = esc_attr(get_post_meta($post_id, self::$slug, true));

        $display = $licensing_enabled ? '' : ' style="display:none;"';
        if (trim($licensing_renewal_period) == '') {
            $licensing_renewal_period = 0;
        }
        ?>
        <script type="text/javascript">
            jQuery( document ).ready( function($) {
                $( "#licensing_enabled" ).on( "click",function() {
                    $( ".variable-toggled-hide" ).toggle();
                    $( ".toggled-hide" ).toggle();
                });

                if( $('#licensing_enabled').is(':checked')){
                    // TODO: require: type and slug of product
                }
            });
        </script>

        <p class="form-field">
            <input type="checkbox" name="licensing_enabled" id="licensing_enabled" value="1" <?php echo checked(true, $licensing_enabled, false); ?> />
            <label for="licensing_enabled"><?php _e('Enable licensing for this download.', '');?></label>
        </p>

        <div <?php echo $display; ?> class="toggled-hide">
            <p class="form-field">
                <label for="licensing_renewal_period"><?php _e('license renewal period(yearly) , enter 0 for lifetime.', '');?></label>
                <input type="number" name="licensing_renewal_period" id="licensing_renewal_period" value="<?php echo $licensing_renewal_period; ?>"  />
            </p>
            <p class="form-field">
                <label for="sites_allowed"><?php _e('How many sites can be activated trough a single license key?', '');?></label>
                <input type="number" name="sites_allowed" class="small-text" value="<?php echo $sites_allowed; ?>" />
            </p>
            <p class="form-field">
                <label for="type"><?php _e('Product type (plugin or theme).', '');?></label>
                <!--<select size="3" name="type[]">
                    <option>plugin</option>
                    <option>theme</option>
                </select>-->
                <input type="text" name="type" class="small-text" value="<?php echo $product_type; ?>" />
            </p>
            <p class="form-field">
                <label for="slug"><?php _e('Product slug.', '');?></label>
                <input type="text" name="slug" class="small-text" value="<?php echo $product_slug; ?>" />
            </p>
        </div>
        <?php
    }

    /**
     * Save options value
     *
     * @param int $post_id
     */
    public function save_options( int $post_id) {
        if (!empty($_POST['licensing_enabled'])) {
            update_post_meta($post_id, self::$licensing_enabled, esc_html($_POST['licensing_enabled']));
        }
        if (!empty($_POST['sites_allowed'])) {
            update_post_meta($post_id, self::$sites_allowed, esc_html($_POST['sites_allowed']));
        }
        if (!empty($_POST['licensing_renewal_period'])) {
            update_post_meta($post_id, self::$renewal_period, esc_html($_POST['licensing_renewal_period']));
        }
        if (!empty($_POST['type'])) {
            update_post_meta($post_id, self::$type, esc_html($_POST['type']));
        }
        if (!empty($_POST['slug'])) {
            update_post_meta($post_id, self::$slug, esc_html($_POST['slug']));
        }
    }

    /**
     * @param $product_id
     * @return bool
     */
    public static function get_licensing_enabled($product_id){
         return boolval(get_post_meta($product_id, self::$licensing_enabled, true));
    }

    /**
     * @param $product_id
     * @return int
     */
    public static function get_sites_allowed($product_id){
        return intval(get_post_meta($product_id, self::$sites_allowed, true));
    }

    /**
     * @param $product_id
     * @return int
     */
    public static function get_renewal_period($product_id){
        return intval(get_post_meta($product_id, self::$renewal_period, true));
    }

    /**
     * @param $product_id
     * @return string
     */
    public static function get_type($product_id){
        return (string)get_post_meta($product_id, self::$type, true);
    }

    /**
     * @param $product_id
     * @return string
     */
    public static function get_slug($product_id){
        return (string)get_post_meta($product_id, self::$slug, true);
    }
}