<?php
if (!defined('ABSPATH')) {
    exit;
}

// Define Constants
define('PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PLUGIN_URL', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)));
define('VERSION', '2.2.1');
define('SUPPORT_PHP', '5.3');
define('SUPPORT_WP', '3.5');
define('SUPPORT_WC', '2.1');

if (!class_exists('custom')) {

    /**
     * Main plugin class
     *
     * @package custom
     * @author RightPress
     */
    class custom
    {

        /**
         * Class constructor
         *
         * @access public
         * @return void
         */
        public function __construct()
        {
            $this->mailchimp = null;

            // Load translation
            load_textdomain('custom', WP_LANG_DIR . '/custom/custom-' . apply_filters('plugin_locale', get_locale(), 'custom') . '.mo');
            load_plugin_textdomain('custom', false, dirname(plugin_basename(__FILE__)) . '/languages/');

            // Execute other code when all plugins are loaded
            add_action('plugins_loaded', array($this, 'on_plugins_loaded'), 1);
        }

        /**
         * Code executed when all plugins are loaded
         *
         * @access public
         * @return void
         */
        public function on_plugins_loaded()
        {
            // Check environment
            if (!self::check_environment()) {
                return;
            }

            // Load classes/includes
            require PLUGIN_PATH . 'includes/classes/custom-mailchimp-subscription.class.php';
            require PLUGIN_PATH . 'includes/custom-plugin-structure.inc.php';
            require PLUGIN_PATH . 'includes/custom-form.inc.php';

            // Initialize automatic updates
            require_once(plugin_dir_path(__FILE__) . 'includes/classes/libraries/rightpress-updates.class.php');
            RightPress_Updates_6044286::init(__FILE__, VERSION);

            // Load configuration and current settings
            $this->get_config();
            $this->opt = $this->get_options();

            // Maybe migrate some options
            $this->migrate_options();

            // API3 options migration
            if (!isset($this->opt['api_version']) || $this->opt['api_version'] < 3) {
                $this->api3_migrate_groups();
            }

            // Hook into WordPress
            if (is_admin()) {
                add_action('admin_menu', array($this, 'add_admin_menu'));
                add_action('admin_init', array($this, 'admin_construct'));
                add_filter('plugin_action_links_'.plugin_basename(__FILE__), array($this, 'plugin_settings_link'));

                if (preg_match('/page=custom/i', $_SERVER['QUERY_STRING'])) {
                    add_action('init', array($this, 'enqueue_select2'), 1);
                    add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
                }
            }
            else {
                add_action('load_frontend_assets', array($this, 'load_frontend_assets'));
            }

            // Widgets
            add_action('widgets_init', create_function('', 'return register_widget("MailChimp_Signup");'));

            // Shortcodes
            add_shortcode('form', array($this, 'subscription_shortcode'));

            // Hook into WooCommerce
            add_action('woocommerce_order_status_completed', array($this, 'on_completed'));
            add_action('woocommerce_order_status_processing', array($this, 'on_completed'));
            add_action('woocommerce_payment_complete', array($this, 'on_completed'));
            add_action('woocommerce_order_status_cancelled', array($this, 'on_cancel'));
            add_filter('woocommerce_order_status_refunded', array($this, 'on_cancel'));
            add_filter('woocommerce_order_status_changed', array($this, 'on_status_update'));

            // Checkout
            add_action('woocommerce_checkout_update_order_meta', array($this, 'on_checkout'));

            // New order
            add_action('woocommerce_new_order', array($this, 'new_order'));

            // New order added by admin
            add_filter('woocommerce_process_shop_order_meta', array($this, 'new_order'));

            $checkbox_position = (isset($this->opt['checkbox_position']) && !empty($this->opt['checkbox_position'])) ? $this->opt['checkbox_position'] : 'woocommerce_checkout_after_customer_details';
            add_action($checkbox_position, array($this, 'add_permission_question'));

            // Add hidden fields on checkout to store campaign ids
            add_action('woocommerce_checkout_after_customer_details', array($this, 'backup_campaign_cookies'));

            // Delete settings on plugin removal
            register_uninstall_hook(__FILE__, array('custom', 'uninstall'));

            // Define Ajax handlers
            add_action('wp_ajax_mailchimp_status', array($this, 'ajax_mailchimp_status'));
            add_action('wp_ajax_get_lists_with_multiple_groups_and_fields', array($this, 'ajax_lists_for_checkout'));
            add_action('wp_ajax_get_lists', array($this, 'ajax_lists_in_array'));
            add_action('wp_ajax_update_groups_and_tags', array($this, 'ajax_groups_and_tags_in_array'));
            add_action('wp_ajax_update_checkout_groups_and_tags', array($this, 'ajax_groups_and_tags_in_array_for_checkout'));
            add_action('wp_ajax_subscribe_shortcode', array($this, 'ajax_subscribe_shortcode'));
            add_action('wp_ajax_subscribe_widget', array($this, 'ajax_subscribe_widget'));
            add_action('wp_ajax_nopriv_subscribe_shortcode', array($this, 'ajax_subscribe_shortcode'));
            add_action('wp_ajax_nopriv_subscribe_widget', array($this, 'ajax_subscribe_widget'));
            add_action('wp_ajax_product_search', array($this, 'ajax_product_search'));
            add_action('wp_ajax_product_variations_search', array($this, 'ajax_product_variations_search'));

            // Catch mc_cid & mc_eid (MailChimp Campaign ID and MailChimp Email ID)
            add_action('init', array($this, 'track_campaign'));

            // Check updates of user lists and groups
            add_action('wp', array($this, 'user_lists_data_update'));

            // Intercept Webhook call
            if (isset($_GET['custom-webhook-call'])) {
                add_action('init', array($this, 'process_webhook'));
            }

            if (isset($_GET['custom-get-user-groups'])) {
                add_action('init', array($this, 'get_user_groups_handler'));
            }

            // Define form styles
            $this->form_styles = array(
                '2' => 'skin_general',
            );

            // Define all properties available on checkout
            $this->checkout_properties = array(
                'order_billing_first_name' => __('Billing First Name', 'custom'),
                'order_billing_last_name' => __('Billing Last Name', 'custom'),
                'order_billing_company' => __('Billing Company', 'custom'),
                'order_billing_address_1' => __('Billing Address 1', 'custom'),
                'order_billing_address_2' => __('Billing Address 2', 'custom'),
                'order_billing_city' => __('Billing City', 'custom'),
                'order_billing_state' => __('Billing State', 'custom'),
                'order_billing_postcode' => __('Billing Postcode', 'custom'),
                'order_billing_country' => __('Billing Country', 'custom'),
                'order_billing_phone' => __('Billing Phone', 'custom'),
                'order_shipping_first_name' => __('Shipping First Name', 'custom'),
                'order_shipping_last_name' => __('Shipping Last Name', 'custom'),
                'order_shipping_address_1' => __('Shipping Address 1', 'custom'),
                'order_shipping_address_2' => __('Shipping Address 2', 'custom'),
                'order_shipping_city' => __('Shipping City', 'custom'),
                'order_shipping_state' => __('Shipping State', 'custom'),
                'order_shipping_postcode' => __('Shipping Postcode', 'custom'),
                'order_shipping_country' => __('Shipping Country', 'custom'),
                'order_shipping_method_title' => __('Shipping Method Title', 'custom'),
                'order_payment_method_title' => __('Payment Method Title ', 'custom'),
                'order_user_id' => __('User ID', 'custom'),
                'user_first_name' => __('User First Name', 'custom'),
                'user_last_name' => __('User Last Name', 'custom'),
                'user_nickname' => __('User Nickname', 'custom'),
                'user_paying_customer' => __('User Is Paying Customer', 'custom'),
                'user__order_count' => __('User Completed Order Count', 'custom'),
            );
        }

        /**
         * Loads/sets configuration values from structure file and database
         *
         * @access public
         * @return void
         */
        public function get_config()
        {
            // Settings tree
            $this->settings = plugin_settings();

            // Load some data from config
            $this->hints = $this->options('hint');
            $this->validation = $this->options('validation', true);
            $this->titles = $this->options('title');
            $this->options = $this->options('values');
            $this->section_info = $this->get_section_info();
            $this->default_tabs = $this->get_default_tabs();
        }

        /**
         * Get settings options: default, hint, validation, values
         *
         * @access public
         * @param string $name
         * @param bool $split_by_subpage
         * @return array
         */
        public function options($name, $split_by_subpage = false)
        {
            $results = array();

            // Iterate over settings array and extract values
            foreach ($this->settings as $page => $page_value) {
                $page_options = array();

                foreach ($page_value['children'] as $subpage => $subpage_value) {
                    foreach ($subpage_value['children'] as $section => $section_value) {
                        foreach ($section_value['children'] as $field => $field_value) {
                            if (isset($field_value[$name])) {
                                $page_options['' . $field] = $field_value[$name];
                            }
                        }
                    }

                    $results[preg_replace('/_/', '-', $subpage)] = $page_options;
                    $page_options = array();
                }
            }

            $final_results = array();

            // Do we need to split results per page?
            if (!$split_by_subpage) {
                foreach ($results as $value) {
                    $final_results = array_merge($final_results, $value);
                }
            }
            else {
                $final_results = $results;
            }

            return $final_results;
        }

        /**
         * Get default tab for each page
         *
         * @access public
         * @return array
         */
        public function get_default_tabs()
        {
            $tabs = array();

            // Iterate over settings array and extract values
            foreach ($this->settings as $page => $page_value) {
                reset($page_value['children']);
                $tabs[$page] = key($page_value['children']);
            }

            return $tabs;
        }

        /**
         * Get array of section info strings
         *
         * @access public
         * @return array
         */
        public function get_section_info()
        {
            $results = array();

            // Iterate over settings array and extract values
            foreach ($this->settings as $page_value) {
                foreach ($page_value['children'] as $subpage => $subpage_value) {
                    foreach ($subpage_value['children'] as $section => $section_value) {
                        if (isset($section_value['info'])) {
                            $results[$section] = $section_value['info'];
                        }
                    }
                }
            }

            return $results;
        }

        /*
         * Get plugin options set by user
         *
         * @access public
         * @return array
         */
        public function get_options()
        {
            $default_options = array_merge(
                $this->options('default'),
                array(
                    'checkout_fields' => array(),
                    'widget_fields' => array(),
                    'shortcode_fields' => array(),
                )
            );

            $overrides = array(
                'webhook_url' => site_url('/?custom-webhook-call'),
            );

            return array_merge(
                       $default_options,
                       get_option('options', $this->options('default')),
                       $overrides
                   );
        }

        /*
         * Update options
         *
         * @access public
         * @param array $args
         * @return bool
         */
        public function update_options($args = array())
        {
            return update_option('options', array_merge($this->get_options(), $args));
        }

        /*
         * Maybe unset old options
         *
         * @access public
         * @param array $args
         * @return bool
         */
        public function maybe_unset_old_options($args = array())
        {
            $options = $this->get_options();

            foreach ($args as $option) {
                if (isset($options[$option])) {
                    unset($options[$option]);
                }
            }

            return update_option('options', $options);
        }

        /*
         * Migrate some options from older plugin versions
         *
         * @access public
         * @return void
         */
        public function migrate_options()
        {
            // If checkout option disabled or unset
            if (!isset($this->opt['enabled_checkout']) || $this->opt['enabled_checkout'] == 1) {
                return;
            }

            // Check and pass saved sets
            if (isset($this->opt['sets']) && is_array($this->opt['sets']) && !empty($this->opt['sets'])) {
                $sets = $this->opt['sets'];
            }
            else {
                $sets = array();
            }

            $options = array();

            // Automatic was selected
            if ($this->opt['enabled_checkout'] == 2) {

                $options = array(
                    'checkout_checkbox_subscribe_on'   => 4, // disable
                    'checkout_auto_subscribe_on'       => $this->opt['checkout_subscribe_on'], // move
                    'sets_checkbox'                             => array(),
                    'sets_auto'                                 => $sets,
                    'do_not_resubscribe_auto'          => $this->opt['do_not_resubscribe'],
                    'replace_groups_checkout_checkbox' => '1',
                    'replace_groups_checkout_auto'     => $this->opt['replace_groups_checkout'],
                    'double_checkout_checkbox'         => 0,
                    'double_checkout_auto'             => $this->opt['double_checkout'],
                    'welcome_checkout_checkbox'        => 0,
                    'welcome_checkout_auto'            => $this->opt['welcome_checkout'],
                );
            }

            // Ask for permission was selected
            else if ($this->opt['enabled_checkout'] == 3) {

                $options = array(
                    'checkout_checkbox_subscribe_on'   => $this->opt['checkout_subscribe_on'], // move
                    'checkout_auto_subscribe_on'       => 4, // disable
                    'sets_checkbox'                             => $sets,
                    'sets_auto'                                 => array(),
                    'do_not_resubscribe_auto'          => $this->opt['do_not_resubscribe'],
                    'replace_groups_checkout_checkbox' => $this->opt['replace_groups_checkout'],
                    'replace_groups_checkout_auto'     => '1',
                    'double_checkout_checkbox'         => $this->opt['double_checkout'],
                    'double_checkout_auto'             => 0,
                    'welcome_checkout_checkbox'        => $this->opt['welcome_checkout'],
                    'welcome_checkout_auto'            => 0,
                );
            }

            // Actually make the changes
            $this->update_options($options);

            // Unset old options
            $unset_old_options = array(
                'enabled_checkout',
                'checkout_subscribe_on',
                'sets',
                'do_not_resubscribe',
                'replace_groups_checkout',
                'double_checkout',
                'welcome_checkout',
                'subscription_checkout_list_groups',
            );

            $this->maybe_unset_old_options($unset_old_options);
        }


        /*
         * Migrate groups options from API 2.0 plugin versions
         *
         * @access public
         * @return void
         */
        public function api3_migrate_groups()
        {
            $options = array();

            foreach (array('checkbox', 'auto') as $sets_type) {

                if (!empty($this->opt['sets_' . $sets_type]) && is_array($this->opt['sets_' . $sets_type])) {

                    foreach ($this->opt['sets_' . $sets_type] as $set_id => $set) {

                        $options['sets_' . $sets_type][$set_id] = $set;

                        if (!empty($set['groups']) && is_array($set['groups'])) {

                            $groups_changed = array();

                            foreach ($set['groups'] as $group) {

                                $parts = preg_split('/:/', htmlspecialchars_decode($group), 2);
                                $group_id = trim($parts[0]);
                                $group_name = trim($parts[1]);

                                $groups_new = $this->get_groups($set['list']);
                                unset($groups_new['']);

                                foreach (array_keys($groups_new) as $group_new) {

                                    $parts = preg_split('/:/', htmlspecialchars_decode($group_new), 2);
                                    $group_new_id = trim($parts[0]);
                                    $group_new_name = trim($parts[1]);

                                    if ($group_name == $group_new_name && intval($group_id) > 0) {
                                        $groups_changed[] = $group_new;
                                    }
                                }
                            }

                            $options['sets_' . $sets_type][$set_id]['groups'] = $groups_changed;
                        }
                    }
                }
            }

            $options['api_version'] = 3;
            $this->update_options($options);
        }

        /**
         * Add link to admin page
         *
         * @access public
         * @return void
         */
        public function add_admin_menu()
        {
            if (!current_user_can('manage_options')) {
                return;
            }

            global $submenu;

            if (isset($submenu['woocommerce'])) {
                add_submenu_page(
                    'woocommerce',
                     $this->settings['custom']['page_title'],
                     $this->settings['custom']['title'],
                     $this->settings['custom']['capability'],
                     $this->settings['custom']['slug'],
                     array($this, 'set_up_admin_page')
                );
            }
        }

        /*
         * Set up admin page
         *
         * @access public
         * @return void
         */
        public function set_up_admin_page()
        {
            // Open form container
            echo '<div class="wrap woocommerce custom"><form method="post" action="options.php" enctype="multipart/form-data">';

            // Print notices
            settings_errors();

            // Print page tabs
            $this->render_tabs();

            // Check for general warnings
            if (!$this->curl_enabled()) {
                add_settings_error(
                    'error_type',
                    'general',
                    sprintf(__('Warning: PHP cURL extension is not enabled on this server. cURL is required for this plugin to function correctly. You can read more about cURL <a href="%s">here</a>.', 'custom'), 'http://url.rightpress.net/php-curl')
                );
            }

            // Print page content
            $this->render_page();

            // Close form container
            echo '</form></div>';
        }

        /**
         * Admin interface constructor
         *
         * @access public
         * @return void
         */
        public function admin_construct()
        {
            // Iterate subpages
            foreach ($this->settings['custom']['children'] as $subpage => $subpage_value) {

                register_setting(
                    'opt_group_' . $subpage,            // Option group
                    'options',                          // Option name
                    array($this, 'options_validate')             // Sanitize
                );

                // Iterate sections
                foreach ($subpage_value['children'] as $section => $section_value) {

                    add_settings_section(
                        $section,
                        $section_value['title'],
                        array($this, 'render_section_info'),
                        'custom-admin-' . str_replace('_', '-', $subpage)
                    );

                    // Iterate fields
                    foreach ($section_value['children'] as $field => $field_value) {

                        add_settings_field(
                            '' . $field,                                     // ID
                            $field_value['title'],                                      // Title
                            array($this, 'render_options_' . $field_value['type']),     // Callback
                            'custom-admin-' . str_replace('_', '-', $subpage), // Page
                            $section,                                                   // Section
                            array(                                                      // Arguments
                                'name' => '' . $field,
                                'options' => $this->opt,
                            )
                        );

                    }
                }
            }
        }

        /**
         * Render admin page navigation tabs
         *
         * @access public
         * @param string $current_tab
         * @return void
         */
        public function render_tabs()
        {
            // Get current page and current tab
            $current_page = $this->get_current_page_slug();
            $current_tab = $this->get_current_tab();

            // Output admin page tab navigation
            echo '<h2 class="custom-tabs-container nav-tab-wrapper">';
            echo '<div id="icon-custom" class="icon32 icon32-custom"><br></div>';
            foreach ($this->settings as $page => $page_value) {
                if ($page != $current_page) {
                    continue;
                }

                foreach ($page_value['children'] as $subpage => $subpage_value) {
                    $class = ($subpage == $current_tab) ? ' nav-tab-active' : '';
                    echo '<a class="nav-tab'.$class.'" href="?page='.preg_replace('/_/', '-', $page).'&tab='.$subpage.'">'.((isset($subpage_value['icon']) && !empty($subpage_value['icon'])) ? $subpage_value['icon'] . '&nbsp;' : '').$subpage_value['title'].'</a>';
                }
            }
            echo '</h2>';
        }

        /**
         * Get current tab (fallback to default)
         *
         * @access public
         * @param bool $is_dash
         * @return string
         */
        public function get_current_tab($is_dash = false)
        {
            $tab = (isset($_GET['tab']) && $this->page_has_tab($_GET['tab'])) ? preg_replace('/-/', '_', $_GET['tab']) : $this->get_default_tab();

            return (!$is_dash) ? $tab : preg_replace('/_/', '-', $tab);
        }

        /**
         * Get default tab
         *
         * @access public
         * @return string
         */
        public function get_default_tab()
        {
            // Get page slug
            $current_page_slug = $this->get_current_page_slug();

            // Check if slug is set in default tabs and return the first one if not
            return isset($this->default_tabs[$current_page_slug]) ? $this->default_tabs[$current_page_slug] : array_shift(array_slice($this->default_tabs, 0, 1));
        }

        /**
         * Get current page slug
         *
         * @access public
         * @return string
         */
        public function get_current_page_slug()
        {
            $current_screen = get_current_screen();
            $current_page = $current_screen->base;

            // Make sure the 'parent_base' is woocommerce, because 'base' could have changed name
            if ($current_screen->parent_base == 'woocommerce') {
                $current_page_slug = preg_replace('/.+_page_/', '', $current_page);
                $current_page_slug = preg_replace('/-/', '_', $current_page_slug);
            }

            // Otherwise return some other page slug
            else {
                $current_page_slug = isset($_GET['page']) ? $_GET['page'] : '';
            }

            return $current_page_slug;
        }

        /**
         * Check if current page has requested tab
         *
         * @access public
         * @param string $tab
         * @return bool
         */
        public function page_has_tab($tab)
        {
            $current_page_slug = $this->get_current_page_slug();

            if (isset($this->settings[$current_page_slug]['children'][$tab])) {
                return true;
            }

            return false;
        }

        /**
         * Render settings page
         *
         * @access public
         * @param string $page
         * @return void
         */
        public function render_page(){

            $current_tab = $this->get_current_tab(true);

            ?>
                <div class="custom-container">
                    <div class="custom-left">
                        <input type="hidden" name="current_tab" value="<?php echo $current_tab; ?>" />

                        <?php
                            settings_fields('opt_group_'.preg_replace('/-/', '_', $current_tab));
                            do_settings_sections('custom-admin-' . $current_tab);
                        ?>

                        <?php
                            if ($current_tab == 'integration') {
                                echo '<div class="custom-status" id="custom-status"><p class="loading loading_status"><span class="loading_icon"></span>'.__('Connecting to MailChimp...', 'custom').'</p></div>';
                            }
                            else if ($current_tab == 'widget') {
                                ?>
                                <div class="custom-usage" id="custom-usage">
                                    <p><?php _e('To activate a signup widget:', 'custom'); ?>
                                        <ul style="">
                                            <li><?php printf(__('go to <a href="%s">Widgets</a> page', 'custom'), site_url('/wp-admin/widgets.php')); ?></li>
                                            <li><?php _e('locate a widget named MailChimp Signup', 'custom'); ?></li>
                                            <li><?php _e('drag and drop it to the sidebar of your choise', 'custom'); ?></li>
                                        </ul>
                                    </p>
                                    <p>
                                        <?php _e('Widget will not be displayed to customers if it is not enabled here or if the are issues with configuration.', 'custom'); ?>
                                    </p>
                                    <p>
                                        <?php _e('To avoid potential conflicts, we recommend to use at most one MailChimp Signup widget per page.', 'custom'); ?>
                                    </p>
                                </div>
                                <?php
                            }
                            else if ($current_tab == 'shortcode') {
                                ?>
                                <div class="custom-usage" id="custom-usage">
                                    <p><?php _e('You can display a signup form anywhere in your pages, posts and WooCommerce product descriptions.', 'custom'); ?></p>
                                    <p><?php _e('To do this, simply insert the following shortcode to the desired location:', 'custom'); ?></p>
                                    <div class="custom-code">[form]</div>
                                    <p>
                                        <?php _e('Shorcode will not be displayed to customers if it is not enabled here or if there are issues with configuration.', 'custom'); ?>
                                    </p>
                                    <p>
                                        <?php _e('To avoid potential conflicts, we recommend to place at most one shortcode per page.', 'custom'); ?>
                                    </p>
                                </div>
                                <?php
                            }
                        ?>

                        <?php
                            submit_button();
                        ?>
                    </div>
                    <div style="clear: both;"></div>
                </div>
            <?php

            /**
             * Pass data on selected lists, groups and merge tags
             */

            if ($current_tab == 'checkout-auto') {
                $sets = isset($this->opt['sets_auto']) ? $this->opt['sets_auto'] : '';
                $sets_type = 'sets_auto';
            }
            else if ($current_tab == 'checkout-checkbox') {
                $sets = isset($this->opt['sets_checkbox']) ? $this->opt['sets_checkbox'] : '';
                $sets_type = 'sets_checkbox';
            }

            if (isset($sets) && is_array($sets) && !empty($sets)) {

                $checkout_sets = array();
                $checkout_sets['sets_type'] = $sets_type;

                foreach ($sets as $set_key => $set) {
                    $checkout_sets[$set_key] = array(
                        'list'      => $set['list'],
                        'groups'    => $set['groups'],
                        'merge'     => $set['fields'],
                        'condition' => $set['condition']
                    );
                }
            }
            else {
                $checkout_sets = array();
            }

            // Add labels to optgroups
            $checkout_optgroup_labels = array(
                __('Billing Fields', 'custom'),
                __('Shipping Fields', 'custom'),
                __('Order Properties', 'custom'),
                __('User Properties', 'custom'),
                __('Advanced', 'custom'),
            );

            // Add labels to custom fields
            $checkout_custom_fields_labels = array(
                __('Enter Order Field Key', 'custom'),
                __('Enter User Meta Key', 'custom'),
                __('Enter Static Value', 'custom'),
            );

            // Pass variables to JavaScript
            ?>
                <script>
                    var hints = <?php echo json_encode($this->hints); ?>;
                    var home_url = '<?php echo site_url(); ?>';
                    var enabled = '<?php echo $this->opt['enabled']; ?>';
                    var checkout_checkbox_subscribe_on = '<?php echo $this->opt['checkout_checkbox_subscribe_on']; ?>';
                    var checkout_auto_subscribe_on = '<?php echo $this->opt['checkout_auto_subscribe_on']; ?>';
                    var enabled_widget = '<?php echo $this->opt['enabled_widget']; ?>';
                    var enabled_shortcode = '<?php echo $this->opt['enabled_shortcode']; ?>';
                    var selected_list = {
                        'widget': '<?php echo $this->opt['list_widget']; ?>',
                        'store': '<?php echo $this->opt['list_store']; ?>',
                        'shortcode': '<?php echo $this->opt['list_shortcode']; ?>'
                    };
                    var selected_groups = {
                        'widget': <?php echo json_encode($this->opt['groups_widget']); ?>,
                        'shortcode': <?php echo json_encode($this->opt['groups_shortcode']); ?>
                    };
                    var label_no_results_match = '<?php _e('No results match', 'custom'); ?>';
                    var label_select_mailing_list = '<?php _e('Select a mailing list', 'custom'); ?>';
                    var label_select_tag = '<?php _e('Select a tag', 'custom'); ?>';
                    var label_select_checkout_field = '<?php _e('Select a checkout field', 'custom'); ?>';
                    var label_select_some_groups = '<?php _e('Select some groups (optional)', 'custom'); ?>';
                    var label_select_some_products = '<?php _e('Select some products', 'custom'); ?>';
                    var label_select_some_roles = '<?php _e('Select some roles', 'custom'); ?>';
                    var label_select_some_categories = '<?php _e('Select some categories', 'custom'); ?>';
                    var label_connecting_to_mailchimp = '<?php _e('Connecting to MailChimp...', 'custom'); ?>';
                    var label_still_connecting_to_mailchimp = '<?php _e('Still connecting to MailChimp...', 'custom'); ?>';
                    var label_fields_field = '<?php _e('Field Name', 'custom'); ?>';
                    var label_fields_tag = '<?php _e('MailChimp Tag', 'custom'); ?>';
                    var label_add_new = '<?php _e('Add Field', 'custom'); ?>';
                    var label_add_new_set = '<?php _e('Add Set', 'custom'); ?>';
                    var label_mailing_list = '<?php _e('Mailing list', 'custom'); ?>';
                    var label_groups = '<?php _e('Groups', 'custom'); ?>';
                    var label_set_no = '<?php _e('Set #', 'custom'); ?>';
                    var label_custom_order_field = '<?php _e('Custom Order Field', 'custom'); ?>';
                    var label_custom_user_field = '<?php _e('Custom User Field', 'custom'); ?>';
                    var label_static_value = '<?php _e('Static Value', 'custom'); ?>';
                    var webhook_enabled = '<?php echo $this->opt['enable_webhooks']; ?>';
                    var label_bad_ajax_response = '<?php printf(__('%s Response received from your server is <a href="%s" target="_blank">malformed</a>.', 'custom'), '<i class="fa fa-times" style="font-size: 1.5em; color: red;"></i>&nbsp;&nbsp;&nbsp;', 'http://url.rightpress.net/custom-response-malformed'); ?>';
                    var log_link = '<?php echo '<a id="log_link" href="admin.php?page=wc-status&tab=logs">' . __('View Log', 'custom') . '</a>'; ?>';
                    <?php if (in_array($current_tab, array('checkout-checkbox', 'checkout-auto'))): ?>
                    var checkout_sets = <?php echo json_encode($checkout_sets); ?>;
                    var checkout_optgroup_labels = <?php echo json_encode($checkout_optgroup_labels); ?>;
                    var checkout_custom_fields_labels = <?php echo json_encode($checkout_custom_fields_labels); ?>;
                    <?php endif; ?>

                </script>
            <?php
        }

        /**
         * Render section info
         *
         * @access public
         * @param array $section
         * @return void
         */
        public function render_section_info($section)
        {
            if (isset($this->section_info[$section['id']])) {
                echo $this->section_info[$section['id']];
            }

            // Subscription widget fields
            if ($section['id'] == 'subscription_widget_fields') {

                // Get current fields
                $current_fields = $this->opt['widget_fields'];

                ?>
                <div class="custom-fields">
                    <p><?php printf(__('Email address field is always displayed. You may wish to set up additional fields and associate them with MailChimp <a href="%s">merge tags</a>.', 'custom'), 'http://url.rightpress.net/mailchimp-merge-tags'); ?></p>
                    <div class="custom-status" id="widget_fields"><p class="loading"><span class="loading_icon"></span><?php _e('Connecting to MailChimp...', 'custom'); ?></p></div>
                </div>
                <?php
            }

            // Subscription shortcode fields
            else if ($section['id'] == 'subscription_shortcode_fields') {

                // Get current fields
                $current_fields = $this->opt['shortcode_fields'];

                ?>
                <div class="custom-fields">
                    <p><?php printf(__('Email address field is always displayed. You may wish to set up additional fields and associate them with MailChimp <a href="%s">merge tags</a>.', 'custom'), 'http://url.rightpress.net/mailchimp-merge-tags'); ?></p>
                    <div class="custom-status" id="shortcode_fields"><p class="loading"><span class="loading_icon"></span><?php _e('Connecting to MailChimp...', 'custom'); ?></p></div>
                </div>
                <?php
            }

            // Checkbox subscription checkout checkbox
            else if ($section['id'] == 'subscription_checkout_checkbox') {
                ?>
                <div class="custom-fields">
                    <p><?php _e('Use this if you wish to add a checkbox to your Checkout page so users can opt-in to receive your newsletters.', 'custom'); ?></p>
                </div>
                <?php
            }

            // Auto subscription checkout auto
            else if ($section['id'] == 'subscription_checkout_auto') {
                ?>
                <div class="custom-fields">
                    <p><?php _e('Use this if you wish to subscribe all customers to one of your lists without asking for their consent.', 'custom'); ?></p>
                </div>
                <?php
            }

            // E-Commerce
            else if ($section['id'] == 'ecomm_description') {
                ?>
                <div class="custom-fields">
                    <p><?php printf(__('<a href="%s">MailChimp E-Commerce</a> syncs order data with MailChimp and associates it with subscribers and campaigns. E-Commerce must be enabled in both custom and MailChimp settings. Data is sent when payment is received or order is marked completed.', 'custom'), 'http://url.rightpress.net/mailchimp-ecommerce'); ?></p>
                </div>
                <?php
            }
            else if ($section['id'] == 'ecomm_store') {
                ?>
                <div class="custom-fields">
                    <p><?php printf(__('MailChimp E-Commerce functionality requires a Store to be configured. Store must have a unique ID and must be tied to a specific MailChimp list. All customers, orders and products are tied to a single Store and changing these values later will make new e-commerce data to appear under a different store in MailChimp. You can read more about this <a href="http://url.rightpress.net/mailchimp-ecommerce-api">here</a>.', 'custom'), 'http://url.rightpress.net/mailchimp-ecommerce-api'); ?></p>
                </div>
                <?php
            }

            // Subscription on checkout list, groups and fields
            else if (in_array($section['id'], array('subscription_checkout_list_groups_auto', 'subscription_checkout_list_groups_checkbox'))) {

                /**
                 * Load list of all product categories
                 */
                $post_categories = array();

                $post_categories_raw = get_terms(array('product_cat'), array('hide_empty' => 0));
                $post_categories_raw_count = count($post_categories_raw);

                foreach ($post_categories_raw as $post_cat_key => $post_cat) {
                    $category_name = $post_cat->name;

                    if ($post_cat->parent) {
                        $parent_id = $post_cat->parent;
                        $has_parent = true;

                        // Make sure we don't have an infinite loop here (happens with some kind of "ghost" categories)
                        $found = false;
                        $i = 0;

                        while ($has_parent && ($i < $post_categories_raw_count || $found)) {

                            // Reset each time
                            $found = false;
                            $i = 0;

                            foreach ($post_categories_raw as $parent_post_cat_key => $parent_post_cat) {

                                $i++;

                                if ($parent_post_cat->term_id == $parent_id) {
                                    $category_name = $parent_post_cat->name . ' &rarr; ' . $category_name;
                                    $found = true;

                                    if ($parent_post_cat->parent) {
                                        $parent_id = $parent_post_cat->parent;
                                    }
                                    else {
                                        $has_parent = false;
                                    }

                                    break;
                                }
                            }
                        }
                    }

                    $post_categories[$post_cat->term_id] = $category_name;
                }

                /**
                 * Load list of all roles
                 */

                global $wp_roles;

                if (!isset($wp_roles)) {
                    $wp_roles = new WP_Roles();
                }

                $role_names = $wp_roles->get_names();

                /**
                 * Load list of all countries
                 */

                /**
                 * Available conditions
                 */
                $condition_options = array(
                    'always'          => __('No condition', 'custom'),
                    'products'        => __('Products in cart', 'custom'),
                    'variations'      => __('Product variations in cart', 'custom'),
                    'categories'      => __('Product categories in cart', 'custom'),
                    'amount'          => __('Order total', 'custom'),
                    'custom'          => __('Custom field value', 'custom'),
                    'roles'            => __('Customer roles', 'custom'),
                );

                /**
                 * Load saved forms
                 */
                if ($section['id'] == 'subscription_checkout_list_groups_auto') {
                    $saved_sets = isset($this->opt['sets_auto']) ? $this->opt['sets_auto'] : '';
                }
                else if ($section['id'] == 'subscription_checkout_list_groups_checkbox') {
                    $saved_sets = isset($this->opt['sets_checkbox']) ? $this->opt['sets_checkbox'] : '';
                }

                if (is_array($saved_sets) && !empty($saved_sets)) {

                    // Pass selected properties to Javascript
                    $selected_lists = array();

                    foreach ($saved_sets as $set_key => $set) {
                        $selected_lists[$set_key] = array(
                            'list'      => $set['list'],
                            'groups'    => $set['groups'],
                            'merge'     => $set['fields']
                        );
                    }
                }
                else {

                    // Mockup
                    $saved_sets[1] = array(
                        'list'      => '',
                        'groups'    => array(),
                        'fields'    => array(),
                        'condition' => array(
                            'key'       => '',
                            'operator'  => '',
                            'value'     => '',
                        ),
                    );

                    // Pass selected properties to Javascript
                    $selected_lists = array();
                }

                ?>
                <div class="custom-list-groups">
                    <p><?php _e('Select mailing list and groups that customers will be added to. Multiple sets of list and groups with conditional selection are supported. If criteria of more than one set is matched, user will be subscribed multiple times to multiple lists.', 'custom'); ?></p>
                    <div id="list_groups_list">

                        <?php foreach ($saved_sets as $set_key => $set): ?>

                        <div id="list_groups_list_<?php echo $set_key; ?>">
                            <h4 class="list_groups_handle"><span class="list_groups_title" id="list_groups_title_<?php echo $set_key; ?>"><?php _e('Set #', 'custom'); ?><?php echo $set_key; ?></span><span class="list_groups_remove" id="list_groups_remove_<?php echo $set_key; ?>" title="<?php _e('Remove', 'custom'); ?>"><i class="fa fa-times"></i></span></h4>
                            <div style="clear:both;" class="list_groups_content">

                                <div class="list_groups_section">List & Groups</div>
                                <p id="list_checkout_<?php echo $set_key; ?>" class="loading_checkout list_checkout">
                                    <span class="loading_icon"></span>
                                    <?php _e('Connecting to MailChimp...', 'custom'); ?>
                                </p>

                                <div class="list_groups_section">Fields</div>
                                <p id="fields_table_<?php echo $set_key; ?>" class="loading_checkout fields_checkout">
                                    <span class="loading_icon"></span>
                                    <?php _e('Connecting to MailChimp...', 'custom'); ?>
                                </p>

                                <div class="list_groups_section">Conditions</div>
                                <table class="form-table"><tbody>
                                    <tr valign="top">
                                        <th scope="row"><?php _e('Condition', 'custom'); ?></th>
                                        <td><select id="sets_condition_<?php echo $set_key; ?>" name="options[sets][<?php echo $set_key; ?>][condition]" class="custom-field set_condition_key">

                                            <?php
                                                foreach ($condition_options as $cond_value => $cond_title) {
                                                    $is_selected = (is_array($set['condition']) && isset($set['condition']['key']) && $set['condition']['key'] == $cond_value) ? 'selected="selected"' : '';
                                                    echo '<option value="' . $cond_value . '" ' . $is_selected . '>' . $cond_title . '</option>';
                                                }
                                            ?>

                                        </select></td>
                                    </tr>
                                    <tr valign="top">
                                        <th scope="row"><?php _e('Operator', 'custom'); ?></th>
                                        <td><select id="sets_condition_operator_products_<?php echo $set_key; ?>" name="options[sets][<?php echo $set_key; ?>][operator_products]" class="custom-field set_condition_operator set_condition_operator_products">
                                            <?php $is_selected = (is_array($set['condition']) && isset($set['condition']['key']) && $set['condition']['key'] == 'products') ? true : false; ?>
                                            <option value="contains" <?php echo (($is_selected && isset($set['condition']['value']) && $set['condition']['value']['operator'] == 'contains') ? 'selected="selected"' : ''); ?>><?php _e('Contains', 'custom'); ?></option>
                                            <option value="does_not_contain" <?php echo (($is_selected && isset($set['condition']['value']) && $set['condition']['value']['operator'] == 'does_not_contain') ? 'selected="selected"' : ''); ?>><?php _e('Does not contain', 'custom'); ?></option>
                                        </select></td>
                                    </tr>
                                    <tr valign="top">
                                        <th scope="row"><?php _e('Operator', 'custom'); ?></th>
                                        <td><select id="sets_condition_operator_variations_<?php echo $set_key; ?>" name="options[sets][<?php echo $set_key; ?>][operator_variations]" class="custom-field set_condition_operator set_condition_operator_variations">
                                            <?php $is_selected = (is_array($set['condition']) && isset($set['condition']['key']) && $set['condition']['key'] == 'variations') ? true : false; ?>
                                            <option value="contains" <?php echo (($is_selected && isset($set['condition']['value']) && $set['condition']['value']['operator'] == 'contains') ? 'selected="selected"' : ''); ?>><?php _e('Contains', 'custom'); ?></option>
                                            <option value="does_not_contain" <?php echo (($is_selected && isset($set['condition']['value']) && $set['condition']['value']['operator'] == 'does_not_contain') ? 'selected="selected"' : ''); ?>><?php _e('Does not contain', 'custom'); ?></option>
                                        </select></td>
                                    </tr>
                                    <tr valign="top">
                                        <th scope="row"><?php _e('Operator', 'custom'); ?></th>
                                        <td><select id="sets_condition_operator_categories_<?php echo $set_key; ?>" name="options[sets][<?php echo $set_key; ?>][operator_categories]" class="custom-field set_condition_operator set_condition_operator_categories">
                                            <?php $is_selected = (is_array($set['condition']) && isset($set['condition']['key']) && $set['condition']['key'] == 'categories') ? true : false; ?>
                                            <option value="contains" <?php echo (($is_selected && isset($set['condition']['value']) && $set['condition']['value']['operator'] == 'contains') ? 'selected="selected"' : ''); ?>><?php _e('Contains', 'custom'); ?></option>
                                            <option value="does_not_contain" <?php echo (($is_selected && isset($set['condition']['value']) && $set['condition']['value']['operator'] == 'does_not_contain') ? 'selected="selected"' : ''); ?>><?php _e('Does not contain', 'custom'); ?></option>
                                        </select></td>
                                    </tr>
                                    <tr valign="top">
                                        <th scope="row"><?php _e('Operator', 'custom'); ?></th>
                                        <td><select id="sets_condition_operator_amount_<?php echo $set_key; ?>" name="options[sets][<?php echo $set_key; ?>][operator_amount]" class="custom-field set_condition_operator set_condition_operator_amount">
                                            <?php $is_selected = (is_array($set['condition']) && isset($set['condition']['key']) && $set['condition']['key'] == 'amount') ? true : false; ?>
                                            <option value="lt" <?php echo (($is_selected && isset($set['condition']['value']) && $set['condition']['value']['operator'] == 'lt') ? 'selected="selected"' : ''); ?>><?php _e('Less than', 'custom'); ?></option>
                                            <option value="le" <?php echo (($is_selected && isset($set['condition']['value']) && $set['condition']['value']['operator'] == 'le') ? 'selected="selected"' : ''); ?>><?php _e('Less than or equal to', 'custom'); ?></option>
                                            <option value="eq" <?php echo (($is_selected && isset($set['condition']['value']) && $set['condition']['value']['operator'] == 'eq') ? 'selected="selected"' : ''); ?>><?php _e('Equal to', 'custom'); ?></option>
                                            <option value="ge" <?php echo (($is_selected && isset($set['condition']['value']) && $set['condition']['value']['operator'] == 'ge') ? 'selected="selected"' : ''); ?>><?php _e('Greater than or equal to', 'custom'); ?></option>
                                            <option value="gt" <?php echo (($is_selected && isset($set['condition']['value']) && $set['condition']['value']['operator'] == 'gt') ? 'selected="selected"' : ''); ?>><?php _e('Greater than', 'custom'); ?></option>
                                        </select></td>
                                    </tr>
                                    <tr valign="top">
                                        <th scope="row"><?php _e('Operator', 'custom'); ?></th>
                                        <td><select id="sets_condition_operator_roles_<?php echo $set_key; ?>" name="options[sets][<?php echo $set_key; ?>][operator_roles]" class="custom-field set_condition_operator set_condition_operator_roles">
                                            <?php $is_selected = (is_array($set['condition']) && isset($set['condition']['key']) && $set['condition']['key'] == 'roles') ? true : false; ?>
                                            <option value="is" <?php echo (($is_selected && isset($set['condition']['value']) && $set['condition']['value']['operator'] == 'is') ? 'selected="selected"' : ''); ?>><?php _e('Is', 'custom'); ?></option>
                                            <option value="is_not" <?php echo (($is_selected && isset($set['condition']['value']) && $set['condition']['value']['operator'] == 'is_not') ? 'selected="selected"' : ''); ?>><?php _e('Is not', 'custom'); ?></option>
                                        </select></td>
                                    </tr>
                                    <tr valign="top">
                                        <th scope="row"><?php _e('Products', 'custom'); ?></th>
                                        <td><select multiple id="sets_condition_products_<?php echo $set_key; ?>" name="options[sets][<?php echo $set_key; ?>][condition_products][]" class="custom-field set_condition_value set_condition_value_products">
                                            <?php
                                                // Load list of selected products
                                                if (is_array($set['condition']) && isset($set['condition']['key']) && $set['condition']['key'] == 'products' && isset($set['condition']['value']) && isset($set['condition']['value']['value']) && is_array($set['condition']['value']['value'])) {
                                                    foreach ($set['condition']['value']['value'] as $key => $id) {
                                                        $name = get_the_title($id);
                                                        echo '<option value="' . $id . '" selected="selected">' . $name . '</option>';
                                                    }
                                                }
                                            ?>
                                        </select></td>
                                    </tr>
                                    <tr valign="top">
                                        <th scope="row"><?php _e('Variations', 'custom'); ?></th>
                                        <td><select multiple id="sets_condition_variations_<?php echo $set_key; ?>" name="options[sets][<?php echo $set_key; ?>][condition_variations][]" class="custom-field set_condition_value set_condition_value_variations">
                                            <?php
                                                // Load list of selected products
                                                if (is_array($set['condition']) && isset($set['condition']['key']) && $set['condition']['key'] == 'variations' && isset($set['condition']['value']) && isset($set['condition']['value']['value']) && is_array($set['condition']['value']['value'])) {
                                                    foreach ($set['condition']['value']['value'] as $key => $id) {
                                                        $name = get_the_title($id);
                                                        echo '<option value="' . $id . '" selected="selected">' . $name . '</option>';
                                                    }
                                                }
                                            ?>
                                        </select></td>
                                    </tr>
                                    <tr valign="top">
                                        <th scope="row"><?php _e('Product categories', 'custom'); ?></th>
                                        <td><select multiple id="sets_condition_categories_<?php echo $set_key; ?>" name="options[sets][<?php echo $set_key; ?>][condition_categories][]" class="custom-field set_condition_value set_condition_value_categories">

                                            <?php
                                                foreach ($post_categories as $key => $name) {
                                                    $is_selected = (is_array($set['condition']) && isset($set['condition']['key']) && $set['condition']['key'] == 'categories' && isset($set['condition']['value']) && isset($set['condition']['value']['value']) && in_array($key, $set['condition']['value']['value'])) ? 'selected="selected"' : '';
                                                    echo '<option value="' . $key . '" ' . $is_selected . '>' . $name . '</option>';
                                                }
                                            ?>

                                        </select></td>
                                    </tr>
                                    <tr valign="top">
                                        <th scope="row"><?php _e('Order total', 'custom'); ?></th>
                                        <td><input type="text" id="sets_condition_amount_<?php echo $set_key; ?>" name="options[sets][<?php echo $set_key; ?>][condition_amount]" value="<?php echo ((is_array($set['condition']) && $set['condition']['key'] == 'amount' && isset($set['condition']['value']) && isset($set['condition']['value']['value'])) ? $set['condition']['value']['value'] : ''); ?>" class="custom-field set_condition_value set_condition_value_amount"></td>
                                    </tr>
                                    <tr valign="top">
                                        <th scope="row"><?php _e('Custom field key', 'custom'); ?></th>
                                        <td><input type="text" id="sets_condition_key_custom_<?php echo $set_key; ?>" name="options[sets][<?php echo $set_key; ?>][condition_key_custom]" value="<?php echo ((is_array($set['condition']) && $set['condition']['key'] == 'custom' && isset($set['condition']['value']) && isset($set['condition']['value']['key'])) ? $set['condition']['value']['key'] : ''); ?>" class="custom-field set_condition_custom_key set_condition_custom_key_custom"></td>
                                    </tr>
                                    <tr valign="top">
                                        <th scope="row"><?php _e('Operator', 'custom'); ?></th>
                                        <td><select id="sets_condition_operator_custom_<?php echo $set_key; ?>" name="options[sets][<?php echo $set_key; ?>][operator_custom]" class="custom-field set_condition_operator set_condition_operator_custom">
                                            <?php $is_selected = (is_array($set['condition']) && isset($set['condition']['key']) && $set['condition']['key'] == 'custom') ? true : false; ?>
                                            <optgroup label="String">
                                                <option value="is" <?php echo (($is_selected && isset($set['condition']['value']) && isset($set['condition']['value']['operator']) && $set['condition']['value']['operator'] == 'is') ? 'selected="selected"' : ''); ?>><?php _e('Is', 'custom'); ?></option>
                                                <option value="is_not" <?php echo (($is_selected && isset($set['condition']['value']) && isset($set['condition']['value']['operator']) && $set['condition']['value']['operator'] == 'is_not') ? 'selected="selected"' : ''); ?>><?php _e('Is not', 'custom'); ?></option>
                                                <option value="contains" <?php echo (($is_selected && isset($set['condition']['value']) && isset($set['condition']['value']['operator']) && $set['condition']['value']['operator'] == 'contains') ? 'selected="selected"' : ''); ?>><?php _e('Contains', 'custom'); ?></option>
                                                <option value="does_not_contain" <?php echo (($is_selected && isset($set['condition']['value']) && isset($set['condition']['value']['operator']) && $set['condition']['value']['operator'] == 'does_not_contain') ? 'selected="selected"' : ''); ?>><?php _e('Does not contain', 'custom'); ?></option>
                                            </optgroup>
                                            <optgroup label="Number">
                                                <option value="lt" <?php echo (($is_selected && isset($set['condition']['value']) && isset($set['condition']['value']['operator']) && $set['condition']['value']['operator'] == 'lt') ? 'selected="selected"' : ''); ?>><?php _e('Less than', 'custom'); ?></option>
                                                <option value="le" <?php echo (($is_selected && isset($set['condition']['value']) && isset($set['condition']['value']['operator']) && $set['condition']['value']['operator'] == 'le') ? 'selected="selected"' : ''); ?>><?php _e('Less than or equal to', 'custom'); ?></option>
                                                <option value="eq" <?php echo (($is_selected && isset($set['condition']['value']) && isset($set['condition']['value']['operator']) && $set['condition']['value']['operator'] == 'eq') ? 'selected="selected"' : ''); ?>><?php _e('Equal to', 'custom'); ?></option>
                                                <option value="ge" <?php echo (($is_selected && isset($set['condition']['value']) && isset($set['condition']['value']['operator']) && $set['condition']['value']['operator'] == 'ge') ? 'selected="selected"' : ''); ?>><?php _e('Greater than or equal to', 'custom'); ?></option>
                                                <option value="gt" <?php echo (($is_selected && isset($set['condition']['value']) && isset($set['condition']['value']['operator']) && $set['condition']['value']['operator'] == 'gt') ? 'selected="selected"' : ''); ?>><?php _e('Greater than', 'custom'); ?></option>
                                            </optgroup>
                                        </select></td>
                                    </tr>
                                    <tr valign="top">
                                        <th scope="row"><?php _e('Custom field value', 'custom'); ?></th>
                                        <td><input type="text" id="sets_condition_custom_value_<?php echo $set_key; ?>" name="options[sets][<?php echo $set_key; ?>][condition_custom_value]" value="<?php echo ((is_array($set['condition']) && $set['condition']['key'] == 'custom' && isset($set['condition']['value']) && isset($set['condition']['value']['value'])) ? $set['condition']['value']['value'] : ''); ?>" class="custom-field set_condition_value set_condition_value_custom"></td>
                                    </tr>
                                    <tr valign="top">
                                        <th scope="row"><?php _e('Customer roles', 'custom'); ?></th>
                                        <td><select multiple id="sets_condition_roles_<?php echo $set_key; ?>" name="options[sets][<?php echo $set_key; ?>][condition_roles][]" class="custom-field set_condition_value set_condition_value_roles">

                                            <?php
                                                foreach ($role_names as $key => $name) {
                                                    $is_selected = (is_array($set['condition']) && isset($set['condition']['key']) && $set['condition']['key'] == 'roles' && isset($set['condition']['value']) && isset($set['condition']['value']['value']) && in_array($key, $set['condition']['value']['value'])) ? 'selected="selected"' : '';
                                                    echo '<option value="' . $key . '" ' . $is_selected . '>' . $name . '</option>';
                                                }
                                            ?>

                                        </select></td>
                                    </tr>
                                </tbody></table>

                            </div>
                            <div style="clear: both;"></div>
                        </div>

                        <?php endforeach; ?>

                    </div>
                    <div>
                        <button type="button" name="add_set" id="add_set" disabled="disabled" class="button" value="<?php _e('Add Set', 'custom'); ?>" title="<?php _e('Still connecting to MailChimp...', 'custom'); ?>"><i class="fa fa-plus">&nbsp;&nbsp;<?php _e('Add Set', 'custom'); ?></i></button>
                        <div style="clear: both;"></div>
                    </div>
                </div>
                <?php
            }
        }

        /*
         * Render a text field
         *
         * @access public
         * @param array $args
         * @return void
         */
        public function render_options_text($args = array())
        {
            printf(
                '<input type="text" id="%s" name="options[%s]" value="%s" class="custom-field" />',
                $args['name'],
                $args['name'],
                $args['options'][$args['name']]
            );
        }

        /*
         * Render a text area
         *
         * @access public
         * @param array $args
         * @return void
         */
        public function render_options_textarea($args = array())
        {
            printf(
                '<textarea id="%s" name="options[%s]" class="custom-textarea">%s</textarea>',
                $args['name'],
                $args['name'],
                $args['options'][$args['name']]
            );
        }

        /*
         * Render a checkbox
         *
         * @access public
         * @param array $args
         * @return void
         */
        public function render_options_checkbox($args = array())
        {
            printf(
                '<input type="checkbox" id="%s" name="%soptions[%s]" value="1" %s />',
                $args['name'],
                '',
                $args['name'],
                checked($args['options'][$args['name']], true, false)
            );
        }

        /*
         * Render a dropdown
         *
         * @access public
         * @param array $args
         * @return void
         */
        public function render_options_dropdown($args = array())
        {
            // Handle MailChimp lists dropdown differently
            if (in_array($args['name'], array('list_checkout', 'list_widget', 'list_shortcode', 'list_store'))) {
                echo '<p id="' . $args['name'] . '" class="loading"><span class="loading_icon"></span>' . __('Connecting to MailChimp...', 'custom') . '</p>';
            }
            // Handle MailChimp groups multiselect differently
            else if (in_array($args['name'], array('groups_checkout', 'groups_widget', 'groups_shortcode'))) {
                echo '<p id="' . $args['name'] . '" class="loading"><span class="loading_icon"></span>' . __('Connecting to MailChimp...', 'custom') . '</p>';
            }
            else {

                printf(
                    '<select id="%s" name="options[%s]" class="custom-field">',
                    $args['name'],
                    $args['name']
                );

                foreach ($this->options[$args['name']] as $key => $name) {
                    printf(
                        '<option value="%s" %s %s>%s</option>',
                        $key,
                        selected($key, $args['options'][$args['name']], false),
                        ($key === 0 ? 'disabled="disabled"' : ''),
                        $name
                    );
                }
                echo '</select>';
            }
        }

        /*
         * Render a dropdown with optgroups
         *
         * @access public
         * @param array $args
         * @return void
         */
        public function render_options_dropdown_optgroup($args = array())
        {
            printf(
                '<select id="%s" name="options[%s]" class="custom-field">',
                $args['name'],
                $args['name']
            );

            foreach ($this->options[$args['name']] as $optgroup) {

                printf(
                    '<optgroup label="%s">',
                    $optgroup['title']
                );

                foreach ($optgroup['children'] as $value => $title) {

                    printf(
                        '<option value="%s" %s %s>%s</option>',
                        $value,
                        selected($value, $args['options'][$args['name']], false),
                        ($value === 0 ? 'disabled="disabled"' : ''),
                        $title
                    );
                }

                echo '</optgroup>';
            }

            echo '</select>';
        }

        /*
         * Render a password field
         *
         * @access public
         * @param array $args
         * @return void
         */
        public function render_options_password($args = array())
        {
            printf(
                '<input type="password" id="%s" name="options[%s]" value="%s" class="custom-field" />',
                $args['name'],
                $args['name'],
                $args['options'][$args['name']]
            );
        }

        /**
         * Validate admin form input
         *
         * @access public
         * @param array $input
         * @return array
         */
        public function options_validate($input)
        {
            $current_tab = isset($_POST['current_tab']) ? $_POST['current_tab'] : 'general-settings';
            $output = $original = $this->get_options();

            $revert = array();
            $errors = array();

            // Handle checkout tabs differently
            if (in_array($current_tab, array('checkout-auto', 'checkout-checkbox'))) {

                if ($current_tab == 'checkout-checkbox') {

                    // Subscribe on
                    $output['checkout_checkbox_subscribe_on'] = (isset($input['checkout_checkbox_subscribe_on']) && in_array($input['checkout_checkbox_subscribe_on'], array('1', '2', '3', '4'))) ? $input['checkout_checkbox_subscribe_on'] : '1';

                    // Label
                    $output['text_checkout'] = (isset($input['text_checkout']) && !empty($input['text_checkout'])) ? $input['text_checkout'] : '';

                    // Checkbox position
                    $output['checkbox_position'] = (in_array($input['checkbox_position'], array('woocommerce_checkout_before_customer_details', 'woocommerce_checkout_after_customer_details', 'woocommerce_checkout_billing', 'woocommerce_checkout_shipping', 'woocommerce_checkout_order_review', 'woocommerce_review_order_after_submit', 'woocommerce_review_order_before_submit', 'woocommerce_review_order_before_order_total', 'woocommerce_after_checkout_billing_form'))) ? $input['checkbox_position'] : 'woocommerce_checkout_after_customer_details';

                    // Default state
                    $output['default_state'] = (isset($input['default_state']) && $input['default_state'] == '1') ? '1' : '2';

                    // Method how to add to groups
                    $output['checkout_groups_method'] = (in_array($input['checkout_groups_method'], array('auto','multi','single','select','single_req','select_req'))) ? $input['checkout_groups_method'] : 'auto';

                    // Hide checkbox for subscribed
                    $output['hide_checkbox'] = (in_array($input['hide_checkbox'], array('1','2','3'))) ? $input['hide_checkbox'] : '1';

                    // Replace groups on MailChimp
                    $output['replace_groups_checkout_checkbox'] = (isset($input['replace_groups_checkout_checkbox']) && $input['replace_groups_checkout_checkbox'] == '1') ? '1' : '0';

                    // Double opt-in
                    $output['double_checkout_checkbox'] = (isset($input['double_checkout_checkbox']) && $input['double_checkout_checkbox'] == '1') ? '1' : '0';

                    // Send welcome email
                    $output['welcome_checkout_checkbox'] = (isset($input['welcome_checkout_checkbox']) && $input['welcome_checkout_checkbox'] == '1') ? '1' : '0';

                    // Sets
                    $sets_key = 'sets_checkbox';
                    $input_sets = isset($input[$sets_key]) ? $input[$sets_key] : $input['sets'];
                }

                else if ($current_tab == 'checkout-auto') {

                    // Subscribe on
                    $output['checkout_auto_subscribe_on'] = (isset($input['checkout_auto_subscribe_on']) && in_array($input['checkout_auto_subscribe_on'], array('1', '2', '3', '4'))) ? $input['checkout_auto_subscribe_on'] : '1';

                    // Do not resubscribe unsubscribed
                    $output['do_not_resubscribe_auto'] = (isset($input['do_not_resubscribe_auto']) && $input['do_not_resubscribe_auto'] == '1') ? '1' : '0';

                    // Replace groups on MailChimp
                    $output['replace_groups_checkout_auto'] = (isset($input['replace_groups_checkout_auto']) && $input['replace_groups_checkout_auto'] == '1') ? '1' : '0';

                    // Double opt-in
                    $output['double_checkout_auto'] = (isset($input['double_checkout_auto']) && $input['double_checkout_auto'] == '1') ? '1' : '0';

                    // Send welcome email
                    $output['welcome_checkout_auto'] = (isset($input['welcome_checkout_auto']) && $input['welcome_checkout_auto'] == '1') ? '1' : '0';

                    // Sets
                    $sets_key = 'sets_auto';
                    $input_sets = isset($input[$sets_key]) ? $input[$sets_key] : $input['sets'];
                }

                $new_sets = array();

                if (isset($input_sets) && !empty($input_sets)) {

                    $set_number = 0;

                    foreach ($input_sets as $set) {

                        $set_number++;

                        $new_sets[$set_number] = array();

                        // List
                        $new_sets[$set_number]['list'] = (isset($set['list']) && !empty($set['list'])) ? $set['list']: '';

                        // Groups
                        $new_sets[$set_number]['groups'] = array();

                        if (isset($set['groups']) && is_array($set['groups'])) {
                            foreach ($set['groups'] as $group) {
                                $new_sets[$set_number]['groups'][] = $group;
                            }
                        }

                        // Fields
                        $new_sets[$set_number]['fields'] = array();

                        if (isset($set['field_names']) && is_array($set['field_names'])) {

                            $field_number = 0;

                            foreach ($set['field_names'] as $field) {

                                if (!is_array($field) || !isset($field['name']) || !isset($field['tag']) || empty($field['name']) || empty($field['tag'])) {
                                    continue;
                                }

                                $field_number++;

                                $new_sets[$set_number]['fields'][$field_number] = array(
                                    'name'  => $field['name'],
                                    'tag'   => $field['tag']
                                );

                                // Add value for custom fields
                                if (!empty($field['value'])) {
                                    $new_sets[$set_number]['fields'][$field_number]['value'] = $field['value'];
                                }
                            }
                        }

                        // Condition
                        $new_sets[$set_number]['condition'] = array();
                        $new_sets[$set_number]['condition']['key'] = (isset($set['condition']) && !empty($set['condition'])) ? $set['condition']: 'always';

                        // Condition value
                        if ($new_sets[$set_number]['condition']['key'] == 'products') {
                            if (isset($set['operator_products']) && !empty($set['operator_products']) && isset($set['condition_products']) && is_array($set['condition_products']) && !empty($set['condition_products'])) {

                                // Operator
                                $new_sets[$set_number]['condition']['value']['operator'] = $set['operator_products'];

                                // Value
                                foreach ($set['condition_products'] as $condition_item) {
                                    if (empty($condition_item)) {
                                        continue;
                                    }

                                    $new_sets[$set_number]['condition']['value']['value'][] = $condition_item;
                                }
                            }
                            else {
                                $new_sets[$set_number]['condition']['key'] = 'always';
                                $new_sets[$set_number]['condition']['value'] = array();
                            }
                        }
                        else if ($new_sets[$set_number]['condition']['key'] == 'variations') {
                            if (isset($set['operator_variations']) && !empty($set['operator_variations']) && isset($set['condition_variations']) && is_array($set['condition_variations']) && !empty($set['condition_variations'])) {

                                // Operator
                                $new_sets[$set_number]['condition']['value']['operator'] = $set['operator_variations'];

                                // Value
                                foreach ($set['condition_variations'] as $condition_item) {
                                    if (empty($condition_item)) {
                                        continue;
                                    }

                                    $new_sets[$set_number]['condition']['value']['value'][] = $condition_item;
                                }
                            }
                            else {
                                $new_sets[$set_number]['condition']['key'] = 'always';
                                $new_sets[$set_number]['condition']['value'] = array();
                            }
                        }
                        else if ($new_sets[$set_number]['condition']['key'] == 'categories') {
                            if (isset($set['operator_categories']) && !empty($set['operator_categories']) && isset($set['condition_categories']) && is_array($set['condition_categories']) && !empty($set['condition_categories'])) {

                                // Operator
                                $new_sets[$set_number]['condition']['value']['operator'] = $set['operator_categories'];

                                // Value
                                foreach ($set['condition_categories'] as $condition_item) {
                                    if (empty($condition_item)) {
                                        continue;
                                    }

                                    $new_sets[$set_number]['condition']['value']['value'][] = $condition_item;
                                }
                            }
                            else {
                                $new_sets[$set_number]['condition']['key'] = 'always';
                                $new_sets[$set_number]['condition']['value'] = array();
                            }
                        }
                        else if ($new_sets[$set_number]['condition']['key'] == 'amount') {
                            if (isset($set['operator_amount']) && !empty($set['operator_amount']) && isset($set['condition_amount']) && !empty($set['condition_amount'])) {

                                // Operator
                                $new_sets[$set_number]['condition']['value']['operator'] = $set['operator_amount'];

                                // Value
                                $new_sets[$set_number]['condition']['value']['value'] = $set['condition_amount'];
                            }
                            else {
                                $new_sets[$set_number]['condition']['key'] = 'always';
                                $new_sets[$set_number]['condition']['value'] = array();
                            }
                        }
                        else if ($new_sets[$set_number]['condition']['key'] == 'custom') {
                            if (isset($set['condition_key_custom']) && !empty($set['condition_key_custom']) && isset($set['operator_custom']) && !empty($set['operator_custom']) && isset($set['condition_custom_value']) && !empty($set['condition_custom_value'])) {

                                // Field key
                                $new_sets[$set_number]['condition']['value']['key'] = $set['condition_key_custom'];

                                // Operator
                                $new_sets[$set_number]['condition']['value']['operator'] = $set['operator_custom'];

                                // Value
                                $new_sets[$set_number]['condition']['value']['value'] = $set['condition_custom_value'];
                            }
                            else {
                                $new_sets[$set_number]['condition']['key'] = 'always';
                                $new_sets[$set_number]['condition']['value'] = array();
                            }
                        }
                        else if ($new_sets[$set_number]['condition']['key'] == 'roles') {
                            if (isset($set['operator_roles']) && !empty($set['operator_roles']) && isset($set['condition_roles']) && is_array($set['condition_roles']) && !empty($set['condition_roles'])) {

                                // Operator
                                $new_sets[$set_number]['condition']['value']['operator'] = $set['operator_roles'];

                                // Value
                                foreach ($set['condition_roles'] as $condition_item) {
                                    if (empty($condition_item)) {
                                        continue;
                                    }

                                    $new_sets[$set_number]['condition']['value']['value'][] = $condition_item;
                                }
                            }
                            else {
                                $new_sets[$set_number]['condition']['key'] = 'always';
                                $new_sets[$set_number]['condition']['value'] = array();
                            }
                        }
                        else {
                            $new_sets[$set_number]['condition']['value'] = array();
                        }

                    }

                }

                $output[$sets_key] = $new_sets;
            }

            // Handle all other settings as usual
            else {

                // Handle field names (if any)
                if (isset($input['field_names'])) {

                    $new_field_names = array();
                    $fields_page = null;

                    if (is_array($input['field_names']) && !empty($input['field_names'])) {
                        foreach ($input['field_names'] as $key => $page) {

                            $fields_page = $key;

                            if (is_array($page) && !empty($page)) {

                                $merge_field_key = 1;

                                foreach ($page as $merge_field) {
                                    if (isset($merge_field['name']) && !empty($merge_field['name']) && isset($merge_field['tag']) && !empty($merge_field['tag'])) {

                                        $new_field_names[$merge_field_key] = array(
                                            'name' => $merge_field['name'],
                                            'tag' => $merge_field['tag'],
                                        );

                                        $merge_field_key++;
                                    }
                                }
                            }

                        }
                    }

                    if (!empty($page)) {
                        $output[''.$fields_page.'_fields'] = $new_field_names;
                    }
                }

                // Iterate over fields and validate/sanitize input
                foreach ($this->validation[$current_tab] as $field => $rule) {

                    $allow_empty = true;

                    // Conditional validation
                    if (is_array($rule['empty']) && !empty($rule['empty'])) {
                        if (isset($input['' . $rule['empty'][0]]) && ($input['' . $rule['empty'][0]] != '0')) {
                            $allow_empty = false;
                        }
                    }
                    else if ($rule['empty'] == false) {
                        $allow_empty = false;
                    }

                    // Different routines for different field types
                    switch($rule['rule']) {

                        // Validate numbers
                        case 'number':
                            if (is_numeric($input[$field]) || ($input[$field] == '' && $allow_empty)) {
                                $output[$field] = $input[$field];
                            }
                            else {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'number'));
                            }
                            break;

                        // Validate boolean values (actually 1 and 0)
                        case 'bool':
                            $input[$field] = (isset($input[$field]) && $input[$field] != '') ? $input[$field] : '0';
                            if (in_array($input[$field], array('0', '1')) || ($input[$field] == '' && $allow_empty)) {
                                $output[$field] = $input[$field];
                            }
                            else {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'bool'));
                            }
                            break;

                        // Validate predefined options
                        case 'option':

                            // Check if this call is for mailing lists
                            if ($field == 'list_checkout') {
                                //$this->options[$field] = $this->get_lists();
                                if (is_array($rule['empty']) && !empty($rule['empty']) && $input[''.$rule['empty'][0]] != '1' && (empty($input[$field]) || $input[$field] == '0')) {
                                    if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                        $revert[$rule['empty'][0]] = '1';
                                    }
                                    array_push($errors, array('setting' => $field, 'code' => 'option'));
                                }
                                else {
                                    $output[$field] = ($input[$field] == null ? '0' : $input[$field]);
                                }

                                break;
                            }
                            else if (in_array($field, array('list_widget', 'list_shortcode', 'list_store'))) {
                                //$this->options[$field] = $this->get_lists();
                                if (is_array($rule['empty']) && !empty($rule['empty']) && $input[''.$rule['empty'][0]] != '0' && (empty($input[$field]) || $input[$field] == '0')) {
                                    if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                        $revert[$rule['empty'][0]] = '0';
                                    }
                                    array_push($errors, array('setting' => $field, 'code' => 'option'));
                                }
                                else {
                                    $output[$field] = ($input[$field] == null ? '0' : $input[$field]);
                                }

                                break;
                            }

                            if (isset($this->options[$field][$input[$field]]) || ($input[$field] == '' && $allow_empty)) {
                                $output[$field] = ($input[$field] == null ? '0' : $input[$field]);
                            }
                            else {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'option'));
                            }
                            break;

                        // Multiple selections
                        case 'multiple_any':
                            if (empty($input[$field]) && !$allow_empty) {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'multiple_any'));
                            }
                            else {
                                if (!empty($input[$field]) && is_array($input[$field])) {
                                    $temporary_output = array();

                                    foreach ($input[$field] as $field_val) {
                                        $temporary_output[] = htmlspecialchars($field_val);
                                    }

                                    $output[$field] = $temporary_output;
                                }
                                else {
                                    $output[$field] = array();
                                }
                            }
                            break;

                        // Validate emails
                        case 'email':
                            if (filter_var(trim($input[$field]), FILTER_VALIDATE_EMAIL) || ($input[$field] == '' && $allow_empty)) {
                                $output[$field] = esc_attr(trim($input[$field]));
                            }
                            else {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'email'));
                            }
                            break;

                        // Validate URLs
                        case 'url':
                            // FILTER_VALIDATE_URL for filter_var() does not work as expected
                            if (($input[$field] == '' && !$allow_empty)) {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'url'));
                            }
                            else {
                                $output[$field] = esc_attr(trim($input[$field]));
                            }
                            break;

                        // Custom validation function
                        case 'function':
                            $function_name = 'validate_' . $field;
                            $validation_results = $this->$function_name($input[$field]);

                            // Check if parent is disabled - do not validate then and reset to ''
                            if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                if (empty($input[''.$rule['empty'][0]])) {
                                    $output[$field] = '';
                                    break;
                                }
                            }

                            if (($input[$field] == '' && $allow_empty) || $validation_results === true) {
                                $output[$field] = $input[$field];
                            }
                            else {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'option', 'custom' => $validation_results));
                            }
                            break;

                        // Default validation rule (text fields etc)
                        default:
                            if (((!isset($input[$field]) || $input[$field] == '') && !$allow_empty)) {
                                if (is_array($rule['empty']) && !empty($rule['empty'])) {
                                    $revert[$rule['empty'][0]] = '0';
                                }
                                array_push($errors, array('setting' => $field, 'code' => 'string'));
                            }
                            else {
                                $output[$field] = isset($input[$field]) ? esc_attr(trim($input[$field])) : '';
                            }
                            break;
                    }
                }

                // Revert parent fields if needed
                if (!empty($revert)) {
                    foreach ($revert as $key => $value) {
                        $output[''.$key] = $value;
                    }
                }

            }

            // Display settings updated message
            add_settings_error(
                'settings_updated',
                'settings_updated',
                __('Your settings have been saved.', 'custom'),
                'updated'
            );

            // Define error messages
            $messages = array(
                'number' => __('must be numeric', 'custom'),
                'bool' => __('must be either 0 or 1', 'custom'),
                'option' => __('is not allowed', 'custom'),
                'email' => __('is not a valid email address', 'custom'),
                'url' => __('is not a valid URL', 'custom'),
                'string' => __('is not a valid text string', 'custom'),
            );

            // Display errors
            foreach ($errors as $error) {

                $message = (!isset($error['custom']) ? $messages[$error['code']] : $error['custom']) . '. ' . __('Reverted to a previous state.', 'custom');

                add_settings_error(
                    $error['setting'],
                    $error['code'],
                    __('Value of', 'custom') . ' "' . $this->titles[$error['setting']] . '" ' . $message
                );
            }

            return $output;
        }

        /**
         * Custom validation for service provider API key
         *
         * @access public
         * @param string $key
         * @return mixed
         */
        public function validate_api_key($key)
        {
            if (empty($key)) {
                return 'is empty';
            }

            $test_results = $this->test_mailchimp($key);

            if ($test_results === true) {
                return true;
            }
            else {
                return ' is not valid or something went wrong. More details: ' . $test_results;
            }
        }

        /**
         * Load scripts required for admin
         *
         * @access public
         * @return void
         */
        public function enqueue_scripts()
        {
            // Font awesome (icons)
            wp_register_style('custom-font-awesome', PLUGIN_URL . '/assets/css/font-awesome/css/font-awesome.min.css', array(), '4.5.0');

            // Our own scripts and styles
            wp_register_script('custom', PLUGIN_URL . '/assets/js/custom-admin.js', array('jquery'), VERSION);
            wp_register_style('custom', PLUGIN_URL . '/assets/css/style.css', array(), VERSION);

            // Scripts
            wp_enqueue_script('media-upload');
            wp_enqueue_script('thickbox');
            wp_enqueue_script('jquery');
            wp_enqueue_script('jquery-ui');
            wp_enqueue_script('jquery-ui-accordion');
            wp_enqueue_script('jquery-ui-tooltip');
            wp_enqueue_script('custom');

            // Styles
            wp_enqueue_style('thickbox');
            wp_register_style('jquery-ui', PLUGIN_URL . '/assets/jquery-ui/jquery-ui.min.css', array(), VERSION);
            wp_enqueue_style('jquery-ui');
            wp_enqueue_style('custom-font-awesome');
            wp_enqueue_style('custom');
        }

        /**
         * Load Select2 scripts and styles
         *
         * @access public
         * @return void
         */
        public function enqueue_select2()
        {
            // Select2
            wp_register_script('jquery-custom-select2', PLUGIN_URL . '/assets/js/select2v4.0.0.js', array('jquery'), '4.0.0');
            wp_enqueue_script('jquery-custom-select2');

            // Isolated script
            wp_register_script('jquery-custom-select2-rp', PLUGIN_URL . '/assets/js/select2_rp.js', array('jquery'), VERSION);
            wp_enqueue_script('jquery-custom-select2-rp');

            // Styles
            wp_register_style('jquery-custom-select2-css', PLUGIN_URL . '/assets/css/select2v4.0.0.css', array(), '4.0.0');
            wp_enqueue_style('jquery-custom-select2-css');

            // Print scripts before WordPress takes care of it automatically (helps load our version of Select2 before any other plugin does it)
            add_action('wp_print_scripts', array($this, 'print_select2'));
        }

        /**
         * Print Select2 scripts
         *
         * @access public
         * @return void
         */
        public function print_select2()
        {
            remove_action('wp_print_scripts', array($this, 'print_select2'));
            wp_print_scripts('jquery-custom-select2');
            wp_print_scripts('jquery-custom-select2-rp');
        }

        /**
         * Load frontend scripts and styles, depending on context
         *
         * @access public
         * @param string $context
         * @return void
         */
        public function load_frontend_assets($context = '')
        {
            // Load general assets
            $this->enqueue_frontend_scripts();

            // Skins are needed only for form, not for checkout checkbox
            if ($context != 'checkbox') {
                $this->enqueue_form_skins();
            }
        }

        /**
         * Load scripts required for frontend
         *
         * @access public
         * @return void
         */
        public function enqueue_frontend_scripts()
        {
            wp_register_script('custom-frontend', PLUGIN_URL . '/assets/js/custom-frontend.js', array('jquery'), VERSION);
            wp_register_style('custom', PLUGIN_URL . '/assets/css/style.css', array(), VERSION);
            wp_enqueue_script('custom-frontend');
            wp_enqueue_style('custom');
        }

        /**
         * Load CSS for selected skins
         */
        public function enqueue_form_skins()
        {
            foreach ($this->form_styles as $key => $class) {
                if (in_array(strval($key), array($this->opt['widget_skin'], $this->opt['shortcode_skin']))) {
                    wp_register_style('skin_' . $key, PLUGIN_URL . '/assets/css/skins/skin_' . $key . '.css');
                    wp_enqueue_style('skin_' . $key);
                }
            }
        }

        /**
         * Add settings link on plugins page
         *
         * @access public
         * @return void
         */
        public function plugin_settings_link($links)
        {
            $settings_link = '<a href="http://url.rightpress.net/support-site" target="_blank">'.__('Support', 'custom').'</a>';
            array_unshift($links, $settings_link);
            $settings_link = '<a href="admin.php?page=custom">'.__('Settings', 'custom').'</a>';
            array_unshift($links, $settings_link);
            return $links;
        }

        /**
         * Check if WooCommerce is enabled
		 *
		 * @access public
		 * @return void
         */
        public function woocommerce_is_enabled()
        {
            if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
                return true;
            }

            return false;
        }

        /**
         * Handle plugin uninstall
         *
         * @access public
         * @return void
         */
        public function uninstall()
        {
            if (defined('WP_UNINSTALL_PLUGIN')) {
                delete_option('options');
            }
        }

        /**
         * Return all lists from MailChimp to be used in select fields
         *
         * @access public
         * @return array
         */
        public function get_lists()
        {
            $this->load_mailchimp();

            try {
                if (!$this->mailchimp) {
                    throw new Exception(__('Unable to load lists', 'custom'));
                }

                $lists = $this->mailchimp->get_lists();

                if ($lists['total_items'] < 1) {
                    throw new Exception(__('No lists found', 'custom'));
                }

                $results = array('' => '');

                foreach ($lists['lists'] as $list) {
                    $results[$list['id']] = $list['name'];
                }

                return $results;
            }
            catch (Exception $e) {
                return array('' => '');
            }
        }


        /**
         * Return all groupings/groups from MailChimp to be used in select fields
         *
         * @access public
         * @param mixed $list_id
         * @param bool $for_menu
         * @return array
         */
        public function get_groups($list_id, $for_menu = true)
        {
            $this->load_mailchimp();

            try {

                if (!$this->mailchimp) {
                    throw new Exception(__('Unable to load groups', 'custom'));
                }

                $results = array();

                // Single list?
                if (in_array(gettype($list_id), array('integer', 'string'))) {
                    $results = $this->get_list_groups($list_id, $for_menu);
                }

                // Multiple lists...
                else {
                    foreach ($list_id as $list_id_key => $list_id_value) {
                        $results[$list_id_value['list']] = $this->get_list_groups($list_id_value['list'], $for_menu);
                    }
                }

                return $results;
            }
            catch (Exception $e) {
                return array();
            }
        }


        /**
         * Get individual list's interest (group) categories and interests (groups)
         *
         * @access public
         * @param mixed $list_id
         * @param bool $for_menu
         * @return array
         */
        public function get_list_groups($list_id, $for_menu = true)
        {
            $this->load_mailchimp();

            try {

                if (!$this->mailchimp) {
                    throw new Exception(__('Unable to load groups', 'custom'));
                }

                $categories = array();

                // Change results format
                $results = $for_menu ? array('' => '') : array();

                // Check transient
                $transient_name = '' . $list_id . '_interest_categories';
                $categories_raw = get_transient($transient_name);

                // Make a call to MailChimp - get and save interest categories
                if ($categories_raw === false) {
                    $categories_raw = $this->mailchimp->get_interest_categories($list_id);
                    set_transient($transient_name, $categories_raw, 180);
                }

                if (!$categories_raw || empty($categories_raw)) {
                    throw new Exception(__('No groups found', 'custom'));
                }

                // Save categories
                foreach ($categories_raw['categories'] as $category) {
                    $categories[$category['id']] = $category['title'];
                }

                // Iterate categories and find the interests (groups)
                foreach ($categories as $category_id => $category_title) {

                    // Save title for non-menu output
                    if (!$for_menu) {
                        $results[$category_id]['title'] = $category_title;
                    }

                    // Get interests for current category
                    try {

                        // Check transient
                        $transient_name = '' . $list_id . '_' . $category_id . '_interests';
                        $interests = get_transient($transient_name);

                        // Make a call to MailChimp - get and save interests
                        if ($interests === false) {
                            $interests = $this->mailchimp->get_interests($list_id, $category_id);
                            set_transient($transient_name, $interests, 180);
                        }
                    }
                    catch (Exception $e) {
                        continue;
                    }

                    if (!$interests || empty($interests)) {
                        continue;
                    }

                    // Save the output
                    foreach ($interests['interests'] as $interest) {

                        // For non-menu
                        if (!$for_menu) {
                            $results[$category_id]['groups'][$interest['id']] =  $interest['name'];
                        }

                        // For menu
                        else {
                            // name is not needed in key, only id is used
                            $results[$interest['id'] . ':' . htmlspecialchars($interest['name'])] = htmlspecialchars($category_title) . ': ' . htmlspecialchars($interest['name']);
                        }
                    }
                }

                return $results;
            }

            catch (Exception $e) {
                return array();
            }
        }


        /**
         * Return all merge vars for all available lists
         *
         * @access public
         * @param array $lists
         * @return array
         */
        public function get_merge_vars($lists)
        {
            $this->load_mailchimp();

            // Unset blank list
            unset($lists['']);

            $results = array();

            try {

                if (!$this->mailchimp) {
                    throw new Exception(__('Unable to load merge fields', 'custom'));
                }

                // Iterate all lists
                foreach (array_keys($lists) as $list_id) {

                    // Get merge fields of current list
                    $merge_fields = $this->mailchimp->get_merge_fields($list_id);

                    if (!$merge_fields || empty($merge_fields) || !isset($merge_fields['merge_fields'])) {
                        throw new Exception(__('No merge fields found', 'custom'));
                    }

                    foreach ($merge_fields['merge_fields'] as $merge_field) {
                        $results[$merge_field['list_id']][$merge_field['tag']] = $merge_field['name'];
                    }
                }

                return $results;
            }
            catch (Exception $e) {
                return $results;
            }
        }

        /**
         * Test MailChimp key and connection
         *
         * @access public
         * @return bool
         */
        public function test_mailchimp($key = null)
        {
            // Try to get key from options if not set
            if ($key == null) {
                $key = $this->opt['api_key'];
            }

            // Check if api key is set now
            if (empty($key)) {
                return __('No API key provided', 'custom');
            }

            // Check if curl extension is loaded
            if (!function_exists('curl_exec')) {
                return __('PHP Curl extension not loaded on your server', 'custom');
            }

            // Load MailChimp class if not yet loaded
            if (!class_exists('Mailchimp')) {
                require_once PLUGIN_PATH . 'includes/custom-mailchimp.class.php';
            }

            // Check if log is enabled
            $log = $this->opt['enable_log'] == 1 ? $this->opt['log_events'] : false;

            // Try to initialize MailChimp
            $this->mailchimp = new Mailchimp($key, $log);

            if (!$this->mailchimp) {
                return __('Unable to initialize MailChimp class', 'custom');
            }

            try {
                $results = $this->mailchimp->get_account_details();

                if (!empty($results['account_id'])) {
                    return true;
                }

            }
            catch (Exception $e) {
                return $e->getMessage();
            }

            return __('Something went wrong...', 'custom');
        }

        /**
         * Get MailChimp account details
         *
         * @access public
         * @return mixed
         */
        public function get_mailchimp_account_info()
        {

            if ($this->load_mailchimp()) {
                try {
                    $results = $this->mailchimp->get_account_details();
                    return $results;
                }
                catch (Exception $e) {
                    return false;
                }
            }

            return false;
        }


        /**
         * Load MailChimp object
         *
         * @access public
         * @return mixed
         */
        public function load_mailchimp()
        {
            if ($this->mailchimp) {
                return true;
            }

            // Load MailChimp class if not yet loaded
            if (!class_exists('Mailchimp')) {
                require_once PLUGIN_PATH . 'includes/custom-mailchimp.class.php';
            }

            // Check if log is enabled
            $log = $this->opt['enable_log'] == 1 ? $this->opt['log_events'] : false;

            try {
                $this->mailchimp = new Mailchimp($this->opt['api_key'], $log);
                return true;
            }
            catch (Exception $e) {
                return false;
            }
        }

        /**
         * Ajax - Render MailChimp status
         *
         * @access public
         * @return void
         */
        public function ajax_mailchimp_status()
        {
            if (!$this->opt['enabled'] || empty($this->opt['api_key'])) {
                $message = '<h4><i class="fa fa-times" style="font-size: 1.5em; color: red;"></i>&nbsp;&nbsp;&nbsp;' . __('Integration not enabled or API key not set', 'custom') . '</h4>';
            }
            else if ($account_info = $this->get_mailchimp_account_info()) {

                $message =  '<p><i class="fa fa-check" style="font-size: 1.5em; color: green;"></i>&nbsp;&nbsp;&nbsp;' .
                            __('Successfully connected to MailChimp account', 'custom') . ' <strong>' . $account_info['account_name'] . '</strong>.</p>';
            }
            else {
                $message = '<h4><i class="fa fa-times" style="font-size: 1.5em; color: red;"></i>&nbsp;&nbsp;&nbsp;' . __('Connection to MailChimp failed.', 'custom') . '</h4>';
                $mailchimp_error = $this->test_mailchimp();

                if ($mailchimp_error !== true) {
                    $message .= '<p><strong>' . __('Reason', 'custom') . ':</strong> '. $mailchimp_error .'</p>';
                }
            }

            echo json_encode(array('message' => $message));
            die();
        }

        /**
         * Ajax - Return MailChimp lists as array for select field
         *
         * @access public
         * @return void
         */
        public function ajax_lists_in_array()
        {
            $lists = $this->get_lists();

            // Get merge vars
            $merge = $this->get_merge_vars($lists);

            // Get selected merge vars
            if (isset($_POST['data']) && isset($_POST['data']['page']) && in_array($_POST['data']['page'], array('checkout', 'widget', 'shortcode'))) {
                if (isset($this->opt[''.$_POST['data']['page'].'_fields']) && !empty($this->opt[''.$_POST['data']['page'].'_fields'])) {
                    $selected_merge = $this->opt[''.$_POST['data']['page'].'_fields'];
                }
            }

            $selected_merge = isset($selected_merge) ? $selected_merge : array();

            // Do we know which list is selected?
            if (isset($_POST['data']) && isset($_POST['data']['page']) && in_array($_POST['data']['page'], array('checkout', 'widget', 'shortcode')) && $this->opt['list_'.$_POST['data']['page']]) {
                $groups = $this->get_groups($this->opt['list_'.$_POST['data']['page']]);

                $selected_groups = array();

                if (is_array($this->opt['groups_'.$_POST['data']['page']])) {
                    foreach ($this->opt['groups_'.$_POST['data']['page']] as $group_val) {
                        $selected_groups[] = htmlspecialchars($group_val);
                    }
                }
            }
            else {
                $groups = array('' => '');
                $selected_groups = array('' => '');
            }

            // Add all checkout properties
            $checkout_properties = array();

            if (isset($_POST['data']) && isset($_POST['data']['page']) && $_POST['data']['page'] == 'checkout') {
                $checkout_properties = $this->checkout_properties;
            }

            echo json_encode(array('message' => array('lists' => $lists, 'groups' => $groups, 'selected_groups' => $selected_groups, 'merge' => $merge, 'selected_merge' => $selected_merge, 'checkout_properties' => $checkout_properties)));
            die();
        }

        /**
         * Ajax - Return MailChimp groups and tags as array for multiselect field
         */
        public function ajax_groups_and_tags_in_array()
        {
            // Check if we have received required data
            if (isset($_POST['data']) && isset($_POST['data']['list'])) {
                $groups = $this->get_groups($_POST['data']['list']);

                $selected_groups = array();

                if (is_array($this->opt['groups_'.$_POST['data']['page']])) {
                    foreach ($this->opt['groups_'.$_POST['data']['page']] as $group_val) {
                        $selected_groups[] = htmlspecialchars($group_val);
                    }
                }

                $merge_vars = $this->get_merge_vars(array($_POST['data']['list'] => ''));
            }
            else {
                $groups = array('' => '');
                $selected_groups = array('' => '');
                $merge_vars = array('' => '');
            }

            // Add all checkout properties
            $checkout_properties = array();

            if (isset($_POST['data']) && isset($_POST['data']['page']) && $_POST['data']['page'] == 'checkout') {
                $checkout_properties = $this->checkout_properties;
            }

            echo json_encode(array('message' => array('groups' => $groups, 'selected_groups' => $selected_groups, 'merge' => $merge_vars, 'selected_merge' => array(), 'checkout_properties' => $checkout_properties)));
            die();
        }

        /**
         * Ajax - Return MailChimp groups and tags as array for multiselect field for checkout page
         */
        public function ajax_groups_and_tags_in_array_for_checkout()
        {
            // Check if we have received required data
            if (isset($_POST['data']) && isset($_POST['data']['list'])) {
                $groups = $this->get_groups($_POST['data']['list']);
                $merge_vars = $this->get_merge_vars(array($_POST['data']['list'] => ''));
            }
            else {
                $groups = array('' => '');
                $merge_vars = array('' => '');
            }

            $checkout_properties = $this->checkout_properties;

            echo json_encode(array('message' => array('groups' => $groups, 'merge' => $merge_vars, 'checkout_properties' => $checkout_properties)));
            die();
        }

        /**
         * Prepare order data for E-Commerce
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        public function prepare_order_data($order_id)
        {
            // Initialize order object
            $order = self::wc_get_order($order_id);

            if (!$order) {
                return;
            }

            // Get store id (or create new)
            $store_id = $this->ecomm_get_store();

            if ($store_id === false) {
                return false;
            }

            // Get customer details
            $customer_details = array(
                'id'            => self::ecomm_get_id('user', $order->user_id),
                'email_address' => $order->billing_email,
                'first_name'    => $order->billing_first_name,
                'last_name'     => $order->billing_last_name,
                'opt_in_status' => $this->opt['opt_in_all'] == '1' ? true : false,
            );


            // Get order details
            $order_details = array(
                'id'               => self::ecomm_get_id('order', $order_id),
                'customer'         => $customer_details,
                'financial_status' => $order->get_status(),
                'currency_code'    => $order->get_order_currency(),
                'order_total'      => floatval($order->order_total),
                'lines'            => array(),
            );

            // Check if we have campaign ID and email ID for this user/order
            $mc_cid = self::get_mc_id('mc_cid', $order->id);
            $mc_eid = self::get_mc_id('mc_eid', $order->id);

            // Pass campaign tracking properties to argument list
            if (!empty($mc_cid)) {
                $order_details['campaign_id'] = $mc_cid;
            }

            // Get order items
            $items = $order->get_items();

            // Populate items
            foreach ($items as $item_key => $item) {

                // Load actual product
                $product = $order->get_product_from_item($item);
                $variation_id = isset($product->variation_id) ? $product->variation_id : $product->id;

                $mc_product_id = self::ecomm_get_id('product', $product->id);
                $mc_variation_id = self::ecomm_get_id('product', $variation_id);

                // Need to create product, if not exists
                if ($this->product_exists($store_id, $mc_product_id) === false) {

                    $product_details = array(
                        'id'       => $mc_product_id,
                        'title'    => $item['name'],
                        'variants' => array(
                                array(
                                    'id'    => $mc_variation_id,
                                    'title' => $item['name'],
                                ),
                            )
                    );

                    $this->mailchimp->create_product($store_id, $product_details);
                }

                // If product exists, but the variation is not
                else if ($mc_product_id != $mc_variation_id) {

                    // Add variation if not exists
                    if ($this->product_exists($store_id, $mc_product_id, $mc_variation_id) === false) {

                        $variant_details = array(
                            'id'    => $mc_variation_id,
                            'title' => $item['name'],
                        );

                        $this->mailchimp->create_variant($store_id, $mc_product_id, $variant_details);
                    }
                }

                $order_details['lines'][] = array(
                    'id'                 => self::ecomm_get_id('item', $item_key),
                    'product_id'         => $mc_product_id,
                    'product_variant_id' => $mc_variation_id,
                    'quantity'           => intval($item['qty']),
                    'price'              => $item['line_total'], // $product->get_price() doesn't fit here because of possible discounts/addons

                );
            }

            $order_details['store_id'] = $store_id;

            return $order_details;
        }

        /**
         * E-Commerce - get id for Mailchimp
         *
         * @access public
         * @param string $type
         * @param int $id
         * @return void
         */
        public static function ecomm_get_id($type, $id)
        {
            // Define prefixes
            $prefixes = apply_filters('ecommerce_id_prefixes', array(
                'user'    => 'user_',
                'order'   => 'order_',
                'product' => 'product_',
                'item'    => 'item_',
            ));

            // Combine and make sure it's a string
            if (isset($prefixes[$type])) {
                return (string) $prefixes[$type] . $id;
            }

            return (string) $id;
        }

        /**
         * E-Commerce - get default store id
         *
         * @access public
         * @return void
         */
        public static function get_default_store_id()
        {
            $parsed_url = parse_url(site_url());
            $default_id = substr(preg_replace('/[^a-zA-Z0-9]+/', '', $parsed_url['host']), 0, 32);
            return $default_id;
        }

        /**
         * E-Commerce - get/create store in Mailchimp
         *
         * @access public
         * @return void
         */
        public function ecomm_get_store()
        {
            // Load MailChimp
            if (!$this->load_mailchimp()) {
                return false;
            }

            // Get selected list for Store
            $list_id = $this->opt['list_store'];

            // Get defined name
            $store_id_set = $this->opt['store_id'];

            if (empty($list_id)) {
                $this->mailchimp->log_add(__('No list selected for Store.', 'custom'));
                return false;
            }

            // Try to find store associated with list
            $stores = $this->mailchimp->get_stores();
            $store_id = null;

            if (!empty($stores['stores'])) {

                foreach ($stores['stores'] as $store) {

                    if ($store['list_id'] == $list_id && $store['id'] == $store_id_set) {
                        return $store['id'];
                    }
                }
            }

            // If not found, create new
            if (is_null($store_id)) {

                // Get domain name from site url
                $parse = parse_url(site_url());

                // Define arguments
                $args = array(
                    'id'      => !empty($this->opt['store_id']) ? $this->opt['store_id'] : custom::get_default_store_id(),
                    'list_id' => $list_id,
                    'name'    => $parse['host'],
                    'currency_code' => get_woocommerce_currency(),
                );

                try {
                    $store = $this->mailchimp->create_store($args);
                    return $store['id'];
                }

                catch (Exception $e) {
                    return false;
                }
            }
        }

        /**
         * E-Commerce - check if product exists in Mailchimp
         *
         * @access public
         * @param int $store_id
         * @param string $mc_product_id
         * @return void
         */
        public function product_exists($store_id, $mc_product_id, $mc_variation_id = '')
        {
            try {
                $product = $this->mailchimp->get_product($store_id, $mc_product_id);

                // Check variation if present
                if (!empty($mc_variation_id) && $mc_product_id != $mc_variation_id) {

                    foreach ($product['variants'] as $variant) {
                        if ($variant['id'] == $mc_variation_id) {
                            return true;
                        }
                    }

                    // No variation found
                    return false;
                }

                return true;
            }
            catch (Exception $e) {
                return false;
            }
        }

        /**
         * E-Commerce - check if order exists in Mailchimp
         *
         * @access public
         * @param int $store_id
         * @param string $mc_order_id
         * @return void
         */
        public function order_exists($store_id, $mc_order_id)
        {
            try {
                $this->mailchimp->get_order($store_id, $mc_order_id);
                return true;
            }
            catch (Exception $e) {
                return false;
            }
        }


        /**
         * Get correct MC ID field data
         *
         * @access public
         * @param string $meta_field
         * @param int $order_id
         * @return void
         */
        public static function get_mc_id($meta_field, $order_id)
        {
            if (in_array($meta_field, array('mc_cid', 'mc_eid'))) {

                $old_mc_id = get_post_meta($order_id, $meta_field, true);
                $new_mc_id = get_post_meta($order_id, '_' . $meta_field, true);

                if (!empty($old_mc_id)) {
                    return $old_mc_id;
                }
                else {
                    return $new_mc_id;
                }
            }
        }

        /**
         * Subscribe on order completed status and send E-Commerce data
         *
         * @access public
         * @param int $order_id
         * @return void
         */
        public function on_completed($order_id)
        {
            // Check if functionality is enabled
            if (!$this->opt['enabled']) {
                return;
            }

            // Check if WC order class is available and MailChimp is loaded
            if (class_exists('WC_Order') && $this->load_mailchimp()) {

                // Do we need to subscribe user on completed order or payment?
                $subscribe_on_completed = get_post_meta($order_id, 'subscribe_on_completed', true);
                $subscribe_on_payment = get_post_meta($order_id, 'subscribe_on_payment', true);

                // Make sure "on payment" option works in any case
                if (!empty($subscribe_on_payment) && self::order_is_paid($order_id) === false) {
                    return;
                }

                foreach (array('auto', 'checkbox') as $sets_type) {
                    if ($subscribe_on_completed == $sets_type || $subscribe_on_payment == $sets_type) {
                        $this->subscribe_checkout($order_id, $sets_type);
                    }
                }

                // Check if we need to send order data or was it already sent
                if (!$this->opt['send_order_data'] || self::order_data_sent($order_id)) {
                    return;
                }

                try {
                    // Get order args
                    $args = $this->prepare_order_data($order_id);

                    // Send order data
                    $this->mailchimp->create_order($args['store_id'], $args);
                    update_post_meta($order_id, '_ecomm_sent', 1);
                }
                catch (Exception $e) {
                    return;
                }
            }
        }

        /**
         * E-Commerce - maybe update status of order in Mailchimp
         *
         * @access public
         * @param int $order_id
         * @return void
         */
        public function on_status_update($order_id, $old_status = '', $new_status = '')
        {
            // Check if it's enabled
            if (!$this->opt['update_order_status'] || empty($order_id) || empty($new_status)) {
                return;
            }

            // Get store id
            try {
                $store_id = $this->ecomm_get_store();

                if ($store_id === false) {
                    return;
                }
            }
            catch (Exception $e) {
                return;
            }

            $mc_order_id = self::ecomm_get_id('order', $order_id);

            // Check if MailChimp is loaded
            if ($this->load_mailchimp()) {

                // Check if order exists in MailChimp
                if ($this->order_exists($store_id, $mc_order_id) === false) {
                    return;
                }

                // Send request to update order
                try {
                    $this->mailchimp->update_order($store_id, $mc_order_id, array('financial_status' => $new_status));
                }
                catch (Exception $e) {
                    return;
                }
            }
        }

        /**
         * E-Commerce - maybe remove order from Mailchimp
         *
         * @access public
         * @param int $order_id
         * @return void
         */
        public function on_cancel($order_id)
        {
            // Check if it's enabled
            if (!$this->opt['delete_order_data'] || empty($order_id)) {
                return;
            }

            // Get store id
            try {
                $store_id = $this->ecomm_get_store();

                if ($store_id === false) {
                    return;
                }
            }
            catch (Exception $e) {
                return;
            }

            $mc_order_id = self::ecomm_get_id('order', $order_id);

            // Check if MailChimp is loaded
            if ($this->load_mailchimp()) {

                // Check if order exists in MailChimp
                if ($this->order_exists($store_id, $mc_order_id) === false) {
                    return;
                }

                // Send request to delete order
                try {
                    $this->mailchimp->delete_order($store_id, $mc_order_id);
                }
                catch (Exception $e) {
                    return;
                }
            }
        }

        /**
         * Check if user was already subscribed from this order
         *
         * @access public
         * @param int $order_id
         * @param string $sets_type
         * @return bool
         */
        public static function already_subscribed_from_order($order_id, $sets_type)
        {
            $subscribed_auto = get_post_meta($order_id, '_subscribed_auto', true);
            $subscribed_checkbox = get_post_meta($order_id, '_subscribed_checkbox', true);

            if (($sets_type == 'auto' && !empty($subscribed_auto)) || ($sets_type == 'checkbox' && !empty($subscribed_checkbox))) {
                return true;
            }

            return false;
        }

        /**
         * Check if new order was already processed
         *
         * @access public
         * @param int $order_id
         * @return void
         */
        public function new_order_processed($order_id)
        {
            $new_order = get_post_meta($order_id, '_new_order', true);
            return !empty($new_order);
        }

        /**
         * Check if order was already sent to MC
         *
         * @access public
         * @param int $order_id
         * @return bool
         */
        public static function order_data_sent($order_id)
        {
            $ecomm_sent = get_post_meta($order_id, '_ecomm_sent', true);
            return !empty($ecomm_sent);
        }

        /**
         * Check if checkout auto-subscribe option is enabled
         *
         * @access public
         * @return bool
         */
        public function checkout_auto_is_active()
        {
            return ($this->opt['checkout_auto_subscribe_on'] == '4') ? false : true;
        }

        /**
         * Check if checkout checkbox subscribe option is enabled
         *
         * @access public
         * @return bool
         */
        public function checkout_checkbox_is_active()
        {
            return ($this->opt['checkout_checkbox_subscribe_on'] == '4') ? false : true;
        }

        /**
         * Subscribe user on checkout
         *
         * @access public
         * @param int $order_id
         * @return bool
         */
        public function on_checkout($order_id)
        {
            // Check if order was processed before
            if (self::new_order_processed($order_id)) {
                return;
            }

            foreach (array('mc_cid', 'mc_eid') as $mc_id) {

                // Copy from cookie directly
                if (isset($_COOKIE[$mc_id])) {
                    add_post_meta($order_id, '_' . $mc_id, $_COOKIE[$mc_id], true);
                }

                // Or use a backup plan with hidden values
                else if (isset($_POST['data'][$mc_id])) {
                    add_post_meta($order_id, '_' . $mc_id, $_POST['data'][$mc_id], true);
                }
            }

            // Save groups data posted on checkout
            if (isset($_POST['data']['groups'])) {
                add_post_meta($order_id, 'subscribe_groups', $_POST['data']['groups'], true);
            }

            // Return user preference
            return isset($_POST['data']['user_preference']);
        }

        /**
         * New order actions
         *
         * @access public
         * @param int $order_id
         * @param bool $user_preference
         * @param array $user_subscribe_groups
         * @return void
         */
        public function new_order($order_id)
        {
            // Possibly run checkout data process and get user preference
            $user_preference = $this->on_checkout($order_id);

            // Check if at least one checkout option is active
            if (!$this->opt['enabled'] || (!$this->checkout_auto_is_active() && !$this->checkout_checkbox_is_active()) || self::new_order_processed($order_id)) {
                return;
            }

            // Process auto-subscription
            if ($this->checkout_auto_is_active()) {

                // Subscribe on completed order
                if ($this->opt['checkout_auto_subscribe_on'] == '2') {
                    add_post_meta($order_id, 'subscribe_on_completed', 'auto', true);
                }

                // Subscribe on payment received
                else if ($this->opt['checkout_auto_subscribe_on'] == '3') {
                    add_post_meta($order_id, 'subscribe_on_payment', 'auto', true);
                }

                // Subscribe now
                else {
                    $this->subscribe_checkout($order_id, 'auto');
                }
            }

            // Process subscription on checkbox
            if ($this->checkout_checkbox_is_active()) {

                // Check if user was already subscribed
                $already_subscribed = ($this->can_user_subscribe_with_checkbox() === false) ? true : false;

                // Check if user preference was set
                if ($user_preference === false) {

                    // If user was subscribed, need to unsubscribe him
                    if ($already_subscribed) {
                        $this->unsubscribe_checkout($order_id, 'checkbox');
                    }

                    return;
                }

                // Subscribe on completed order
                if ($this->opt['checkout_checkbox_subscribe_on'] == '2') {
                    add_post_meta($order_id, 'subscribe_on_completed', 'checkbox', true);
                }

                // Subscribe on payment received
                else if ($this->opt['checkout_checkbox_subscribe_on'] == '3') {
                    add_post_meta($order_id, 'subscribe_on_payment', 'checkbox', true);
                }

                // Subscribe now
                else {
                    $this->subscribe_checkout($order_id, 'checkbox');
                }
            }

            // Mark this order as processed
            update_post_meta($order_id, '_new_order', 1);
        }

        /**
         * Subscribe user on checkout or order completed
         *
         * @access public
         * @param int $order_id
         * @param string $sets_type
         * @return void
         */
        public function subscribe_checkout($order_id, $sets_type)
        {
            $order = self::wc_get_order($order_id);

            if (!$order) {
                return;
            }

            // Get user id
            $user_id = $order->get_user_id();

            if (!is_admin() && $user_id == 0) {
                $user_id = is_user_logged_in() ? get_current_user_id() : 0;
            }

            // Get user meta
            $user_meta = get_user_meta($user_id);

            // Get user email
            $email = isset($order->billing_email) ? $order->billing_email : '';

            if (empty($email)) {
                return;
            }

            // Check if user was subscribed earlier (using this sets type)
            if (self::already_subscribed_from_order($order_id, $sets_type)) {
                return;
            }

            $sets_field = 'sets_' . $sets_type;

            // Subscribe to lists that match criteria
            if (isset($this->opt[$sets_field]) && is_array($this->opt[$sets_field])) {
                foreach ($this->opt[$sets_field] as $set) {

                    // Check conditions
                    $proceed_subscription = $this->conditions_check($set, $sets_type, $order, $user_meta, $user_id);

                    // So, should we proceed with this set?
                    if ($proceed_subscription) {

                        // Get posted groups (only for checkbox)
                        $posted_groups = get_post_meta($order_id, 'subscribe_groups', true);

                        if (!empty($posted_groups) && $sets_type == 'checkbox') {

                            $posted_groups_list = array();

                            foreach ($posted_groups as $grouping_key => $groups) {
                                if (is_array($groups)) {
                                    foreach ($groups as $group) {
                                        $posted_groups_list[] = $group;
                                    }
                                }
                                else {
                                    $posted_groups_list[] = $groups;
                                }
                            }

                            $subscribe_groups = array_intersect($posted_groups_list, $set['groups']);
                        }
                        else {
                            $subscribe_groups = $set['groups'];
                        }

                        // Get custom fields
                        $custom_fields = array();

                        foreach ($set['fields'] as $custom_field) {
                            if (preg_match('/^order_user_id/', $custom_field['name'])) {
                                $custom_fields[$custom_field['tag']] = $user_id;
                            }
                            else if (preg_match('/^order_/', $custom_field['name'])) {
                                $real_field_key = preg_replace('/^order_/', '', $custom_field['name']);
                                if (isset($order->$real_field_key)) {

                                    // Maybe replace country/state code
                                    if (preg_match('/_state$|_country$/', $real_field_key)) {
                                        $value = $this->maybe_replace_location_code($real_field_key, $order);
                                    }
                                    else {
                                        $value = $order->$real_field_key;
                                    }

                                    $custom_fields[$custom_field['tag']] = $value;
                                }
                                else if ($real_field_key == 'shipping_method_title') {
                                    $custom_fields[$custom_field['tag']] = $order->get_shipping_method();
                                }
                            }
                            else if (preg_match('/^user_/', $custom_field['name'])) {
                                $real_field_key = preg_replace('/^user_/', '', $custom_field['name']);
                                if (isset($user_meta[$real_field_key])) {
                                    $custom_fields[$custom_field['tag']] = $user_meta[$real_field_key][0];
                                }
                            }
                            else if ($custom_field['name'] == 'custom_order_field') {
                                $custom_order_field = get_post_meta($order_id, $custom_field['value'], true);
                                $custom_order_field = !empty($custom_order_field) ? $custom_order_field : '';
                                $custom_fields[$custom_field['tag']] = $custom_order_field;
                            }
                            else if ($custom_field['name'] == 'custom_user_field') {
                                if ($user_id > 0) {
                                    $custom_user_field = get_user_meta($user_id, $custom_field['value'], true);
                                    $custom_user_field = !empty($custom_user_field) ? $custom_user_field : '';
                                    $custom_fields[$custom_field['tag']] = $custom_user_field;
                                }
                            }
                            else if ($custom_field['name'] == 'static_value') {
                                $custom_fields[$custom_field['tag']] = $custom_field['value'];
                            }

                        }

                        if ($this->subscribe($set['list'], $email, $subscribe_groups, $custom_fields, $user_id) !== false) {
                            update_post_meta($order_id, '_subscribed_' . $sets_type, 1);
                        }
                    }

                }
            }
        }

        /**
         * Unsubscribe user on checkout
         *
         * @access public
         * @param int $order_id
         * @param string $sets_type
         * @return void
         */
        public function unsubscribe_checkout($order_id, $sets_type)
        {
            $order = self::wc_get_order($order_id);

            if (!$order) {
                return;
            }

            // Get user id
            $user_id = $order->get_user_id();

            if (!is_admin() && $user_id == 0) {
                $user_id = is_user_logged_in() ? get_current_user_id() : 0;
            }

            // Get user email
            $email = isset($order->billing_email) ? $order->billing_email : '';

            if (empty($email)) {
                return;
            }

            $sets_field = 'sets_' . $sets_type;

            // Unsubscribe lists
            if (isset($this->opt[$sets_field]) && is_array($this->opt[$sets_field])) {

                foreach ($this->opt[$sets_field] as $set) {

                    if ($this->unsubscribe($set['list'], $email) !== false) {
                        self::remove_user_list($set['list'], 'subscribed', $user_id);
                        self::track_user_list($set['list'], 'unsubscribed', $email, array(), $user_id);
                    }
                }
            }
        }

        /**
         * Check conditions of set
         *
         * @access public
         * @param array $set
         * @param string $sets_type
         * @param obj $order
         * @param array $user_meta
         * @param int $user_id
         * @param bool $is_cart
         * @return bool
         */
        public function conditions_check($set, $sets_type, $order, $user_meta, $user_id, $is_cart = false)
        {
            // Check if there's no "do not resubscribe" flag
            $do_not_resubscribe = false;
            if ($sets_type == 'auto' && $this->opt['do_not_resubscribe_auto']) {
                $do_not_resubscribe = true;
            }

            if ($do_not_resubscribe) {

                $unsubscribed_lists = self::read_user_lists('unsubscribed', $user_id);
                $unsubscribed_lists = array_keys($unsubscribed_lists);

                foreach ($unsubscribed_lists as $unsub_list) {
                    if ($unsub_list == $set['list']) {
                        return false;
                    }
                }
            }

            $proceed = false;

            // Maybe get items and totals from cart instead of order
            if ($is_cart) {
                global $woocommerce;
                $items = $woocommerce->cart->cart_contents;
                $total = $woocommerce->cart->total;
            }
            else {
                $items = $order->get_items();
                $total = $order->order_total;
            }

            // Always
            if ($set['condition']['key'] == 'always') {
                $proceed = true;
            }

            // Products
            else if ($set['condition']['key'] == 'products') {
                if ($set['condition']['value']['operator'] == 'contains') {
                    foreach ($items as $item) {
                        if (in_array($item['product_id'], $set['condition']['value']['value'])) {
                            $proceed = true;
                            break;
                        }
                    }
                }
                else if ($set['condition']['value']['operator'] == 'does_not_contain') {
                    $contains_item = false;

                    foreach ($items as $item) {
                        if (in_array($item['product_id'], $set['condition']['value']['value'])) {
                            $contains_item = true;
                            break;
                        }
                    }

                    $proceed = !$contains_item;
                }
            }

            // Variations
            else if ($set['condition']['key'] == 'variations') {
                if ($set['condition']['value']['operator'] == 'contains') {
                    foreach ($items as $item) {
                        if (in_array($item['variation_id'], $set['condition']['value']['value'])) {
                            $proceed = true;
                            break;
                        }
                    }
                }
                else if ($set['condition']['value']['operator'] == 'does_not_contain') {
                    $contains_item = false;

                    foreach ($items as $item) {
                        if (in_array($item['variation_id'], $set['condition']['value']['value'])) {
                            $contains_item = true;
                            break;
                        }
                    }

                    $proceed = !$contains_item;
                }
            }

            // Categories
            else if ($set['condition']['key'] == 'categories') {

                $categories = array();

                foreach ($items as $item) {
                    $item_categories = get_the_terms($item['product_id'], 'product_cat');

                    if (is_array($item_categories)) {
                        foreach ($item_categories as $item_category) {
                            $categories[] = $item_category->term_id;
                        }
                    }
                }

                if ($set['condition']['value']['operator'] == 'contains') {
                    foreach ($categories as $category) {
                        if (in_array($category, $set['condition']['value']['value'])) {
                            $proceed = true;
                            break;
                        }
                    }
                }
                else if ($set['condition']['value']['operator'] == 'does_not_contain') {
                    $contains_item = false;

                    foreach ($categories as $category) {
                        if (in_array($category, $set['condition']['value']['value'])) {
                            $contains_item = true;
                            break;
                        }
                    }

                    $proceed = !$contains_item;
                }
            }

            // Amount
            else if ($set['condition']['key'] == 'amount') {
                if (($set['condition']['value']['operator'] == 'lt' && $total < $set['condition']['value']['value'])
                 || ($set['condition']['value']['operator'] == 'le' && $total <= $set['condition']['value']['value'])
                 || ($set['condition']['value']['operator'] == 'eq' && $total == $set['condition']['value']['value'])
                 || ($set['condition']['value']['operator'] == 'ge' && $total >= $set['condition']['value']['value'])
                 || ($set['condition']['value']['operator'] == 'gt' && $total > $set['condition']['value']['value'])) {
                    $proceed = true;
                }
            }

            // Roles
            else if ($set['condition']['key'] == 'roles') {

                if ($user_id > 0) {

                    // Get user data and roles
                    $user_data = get_userdata($user_id);
                    $user_roles = $user_data->roles;

                    // Compare the arrays
                    $compared_array = array_intersect($user_roles, $set['condition']['value']['value']);
                }
                else {
                    $compared_array = array();
                }

                if (($set['condition']['value']['operator'] == 'is' && !empty($compared_array)) || ($set['condition']['value']['operator'] == 'is_not' && empty($compared_array))) {
                    $proceed = true;
                }
            }

            // Custom field
            else if ($set['condition']['key'] == 'custom') {

                // Can't check custom values in cart
                if ($is_cart) {
                    return true;
                }

                $custom_field_value = null;

                // Get the custom field value
                if (isset($order->order_custom_fields[$set['condition']['value']['key']])) {
                    $custom_field_value = is_array($order->order_custom_fields[$set['condition']['value']['key']]) ? $order->order_custom_fields[$set['condition']['value']['key']][0] : $order->order_custom_fields[$set['condition']['value']['key']];
                }
                else if (isset($order->order_custom_fields['_'.$set['condition']['value']['key']])) {
                    $custom_field_value = is_array($order->order_custom_fields['_'.$set['condition']['value']['key']]) ? $order->order_custom_fields['_'.$set['condition']['value']['key']][0] : $order->order_custom_fields['_'.$set['condition']['value']['key']];
                }

                // Should we check in order post meta?
                if ($custom_field_value == null) {
                    $order_meta = get_post_meta($order->id, $set['condition']['value']['key'], true);

                    if ($order_meta == '') {
                        $order_meta = get_post_meta($order->id, '_'.$set['condition']['value']['key'], true);
                    }

                    if ($order_meta != '') {
                        $custom_field_value = is_array($order_meta) ? $order_meta[0] : $order_meta;
                    }
                }

                // Should we check in $_POST data?
                if ($custom_field_value == null && isset($_POST[$set['condition']['value']['key']])) {
                    $custom_field_value = $_POST[$set['condition']['value']['key']];
                }

                // Proceed?
                if ($custom_field_value != null) {
                    if (($set['condition']['value']['operator'] == 'is' && $set['condition']['value']['value'] == $custom_field_value)
                     || ($set['condition']['value']['operator'] == 'is_not' && $set['condition']['value']['value'] != $custom_field_value)
                     || ($set['condition']['value']['operator'] == 'contains' && preg_match('/' . $set['condition']['value']['value'] . '/', $custom_field_value) === 1)
                     || ($set['condition']['value']['operator'] == 'does_not_contain' && preg_match('/' . $set['condition']['value']['value'] . '/', $custom_field_value) !== 1)
                     || ($set['condition']['value']['operator'] == 'lt' && $set['condition']['value']['value'] < $custom_field_value)
                     || ($set['condition']['value']['operator'] == 'le' && $set['condition']['value']['value'] <= $custom_field_value)
                     || ($set['condition']['value']['operator'] == 'eq' && $set['condition']['value']['value'] == $custom_field_value)
                     || ($set['condition']['value']['operator'] == 'ge' && $set['condition']['value']['value'] >= $custom_field_value)
                     || ($set['condition']['value']['operator'] == 'gt' && $set['condition']['value']['value'] > $custom_field_value)) {
                        $proceed = true;
                    }
                }
            }

            return $proceed;
        }

        /**
         * Subscribe user to mailing list
         *
         * @access public
         * @param string $list_id
         * @param string $email
         * @param array $groups
         * @param array $custom_fields
         * @param int $user_id
         * @return bool
         */
        public function subscribe($list_id, $email, $groups = array(), $custom_fields = array(), $user_id = 0)
        {
            // Load MailChimp
            if (!$this->load_mailchimp()) {
                return false;
            }

            $interests = array();
            $merge_fields = array();

            // Any groups to be set?
            if (!empty($groups)) {

                foreach ($groups as $group) {
                    $parts = preg_split('/:/', htmlspecialchars_decode($group), 2);
                    $interests[$parts[0]] = true;
                }
            }

            foreach ($custom_fields as $key => $value) {
                $merge_fields[$key] = $value;
            }

            $params = array(
                'email_address' => $email,
                'status'        => 'subscribed',
            );

            // Don't include empty non-required params
            if (!empty($interests)) {
                $params['interests'] = $interests;
            }

            if (!empty($merge_fields)) {
                $params['merge_fields'] = $merge_fields;
            }

            // Add only new users or also update old
            $update = $this->opt['already_subscribed_action'] == '2' ? true : false;

            // Subscribe
            try {
                $results = ($update === true) ? $this->mailchimp->put_member($list_id, $params) : $this->mailchimp->post_member($list_id, $params);

                // Record user's subscribed list
                self::track_user_list($list_id, 'subscribed', $email, array_keys($interests), $user_id);
                self::remove_user_list($list_id, 'unsubscribed', $user_id);

                return true;
            }
            catch (Exception $e) {

                if (preg_match('/.+is already a list member+/', $e->getMessage())) {
                    return 'member_exists';
                }

                return false;
            }
        }

        /**
         * Unsubscribe user to mailing list
         *
         * @access public
         * @param string $list_id
         * @param string $email
         * @return bool
         */
        public function unsubscribe($list_id, $email)
        {
            // Load MailChimp
            if (!$this->load_mailchimp()) {
                return false;
            }

            try {
                $this->mailchimp->delete_member($list_id, $email);
                return true;
            }
            catch (Exception $e) {
                return false;
            }
        }

        /**
         * Convert two-letter country/state code to full name
         *
         * @access public
         * @param string $field_key
         * @param obj $order
         * @return void
         */
        public function maybe_replace_location_code($field_key, $order)
        {
            // Get countries object
            $wc_countries = new WC_Countries();
            $mc_countries = self::get_mc_countries_exceptions();

            // Get billing/shipping field type
            $field_type = preg_replace('/_state$|_country$/', '', $field_key);

            // Get country code
            $field_country = $field_type . '_country';
            $country_code = $order->$field_country;

            // Maybe get state code
            if (preg_match('/_state$/', $field_key)) {

                $field_state = $field_type . '_state';
                $state_code = isset($order->$field_state) ? $order->$field_state : false;

                if ($state_code == false) {
                    return;
                }
            }

            if (isset($wc_countries->countries[$country_code])) {

                // Return state name if it's set
                if (isset($state_code)) {
                    if (isset($wc_countries->states[$country_code])) {
                        return $wc_countries->states[$country_code][$state_code];
                    }
                    else {
                        return $state_code;
                    }
                }

                // Maybe return MC's country name
                if (isset($mc_countries[$country_code]) && $wc_countries->countries[$country_code] != $mc_countries[$country_code]) {
                    return $mc_countries[$country_code];
                }

                // Return country name
                return $wc_countries->countries[$country_code];
            }
        }

        /**
         * Track campaign
         *
         * @access public
         * @return void
         */
        public function track_campaign()
        {
            // Check if mc_cid is set
            if (isset($_GET['mc_cid'])) {
                setcookie('mc_cid', $_GET['mc_cid'], time()+7776000, COOKIEPATH, COOKIE_DOMAIN);
            }

            // Check if mc_eid is set
            if (isset($_GET['mc_eid'])) {
                setcookie('mc_eid', $_GET['mc_eid'], time()+7776000, COOKIEPATH, COOKIE_DOMAIN);
            }
        }

        /**
         * Track (un)subscribed list
         *
         * @access public
         * @param string $list_id
         * @param string $list_type
         * @param string $email
         * @param array $groups
         * @param int $user_id
         * @return void
         */
        public static function track_user_list($list_id, $list_type, $email, $groups = array(), $user_id = 0)
        {
            if (empty($list_id) || empty($email)) {
                return false;
            }

            // Set one timestamp for all operations
            $timestamp = time();

            // Check if data needs migration
            self::maybe_migrate_user_lists($list_id, $list_type, $timestamp, $email, $groups, $user_id);

            // Set new value
            $new_meta_value[$list_id] = array(
                'email'     => $email,
                'timestamp' => $timestamp,
                'groups'    => $groups,
            );

            // Maybe add user meta
            if ($user_id > 0) {
                self::update_user_meta($user_id, '' . $list_type . '_lists', $new_meta_value);
            }

            // Set cookie
            self::update_user_list_cookie($list_id, $list_type, $timestamp, $email, $groups);
        }

        /**
         * Migrate user lists
         *
         * @access public
         * @param string $list_id
         * @param string $list_type
         * @param int $timestamp
         * @param string $email
         * @param array $groups
         * @param int $user_id
         * @return void
         */
        public static function maybe_migrate_user_lists($list_id, $list_type, $timestamp = '', $email = '', $groups = array(), $user_id = 0)
        {
            if (empty($list_id) || empty($list_type)) {
                return false;
            }

            if (empty($timestamp)) {
                $timestamp = time();
            }

            // Make sure unsubscribed lists has a priority
            $timestamp = ($list_type == 'subscribed') ? $timestamp - 10 : $timestamp;

            // Migrate logged in user meta - all lists
            if ($user_id > 0) {
                self::migrate_user_lists_meta($list_type, $timestamp, $user_id);
            }

            // Migrate cookies list data, if cookie has old format ('1')
            $cookie_value = isset($_COOKIE['' . $list_type . '_list_' . $list_id]) ? $_COOKIE['' . $list_type . '_list_' . $list_id] : '';

            if ($cookie_value == '1') {
                self::update_user_list_cookie($list_id, $list_type, $timestamp, $email, $groups);
            }
        }

        /**
         * Migrate user lists in meta
         *
         * @access public
         * @param string $list_type
         * @param int $timestamp
         * @param int $user_id
         * @return void
         */
        public static function migrate_user_lists_meta($list_type, $timestamp, $user_id)
        {
            // Maybe migrate old format
            if ($lists = get_user_meta($user_id, '' . $list_type . '_lists', true)) {

                $new_lists = array();

                foreach ($lists as $key => $list) {

                    if (!is_array($list) && is_int($key)) {

                        $new_lists[$list] = array(
                            'timestamp' => $timestamp,
                            'email'     => '',
                            'groups'    => array(),
                        );
                    }
                }

                // Update user meta
                if (!empty($new_lists)) {
                    update_user_meta($user_id, '' . $list_type . '_lists', $new_lists);
                }
            }
        }

        /**
         * Update user list in cookies
         *
         * @access public
         * @param string $list_id
         * @param string $list_type
         * @param int $timestamp
         * @param string $email
         * @param array $groups
         * @return void
         */
        public static function update_user_list_cookie($list_id, $list_type, $timestamp = '', $email = '', $groups = array())
        {
            if (empty($timestamp)) {
                $timestamp = time();
            }

            // Check groups value
            $groups = (is_array($groups) && !empty($groups)) ? join('|', $groups) : '';

            // Set list-specific cookie
            $new_list_cookie_value = $timestamp . '|' . $email . '|' . $groups;
            setcookie('' . $list_type . '_list_' . $list_id, $new_list_cookie_value, time()+31557600, COOKIEPATH, COOKIE_DOMAIN);
        }

        /**
         * Remove user list
         *
         * @access public
         * @param string $list_id
         * @param string $list_type
         * @param int $user_id
         * @return void
         */
        public static function remove_user_list($list_id, $list_type, $user_id = 0)
        {
            // Maybe remove list form meta
            if ($user_id > 0) {
                self::remove_user_list_from_meta($list_id, $list_type, $user_id);
            }

            // Try to remove list from cookies
            self::remove_user_list_from_cookies($list_id, $list_type);
        }

        /**
         * Remove user list from meta
         *
         * @access public
         * @param string $list_id
         * @param string $list_type
         * @param int $user_id
         * @return void
         */
        public static function remove_user_list_from_meta($list_id, $list_type, $user_id)
        {
            if ($lists = maybe_unserialize(get_user_meta($user_id, '' . $list_type . '_lists', true))) {

                $updated = false;

                foreach ($lists as $key => $value) {

                    // Check both formats
                    if ($value == $list_id || $key == $list_id) {
                        unset($lists[$key]);
                        $updated = true;
                    }
                }

                if ($updated) {
                    update_user_meta($user_id, '' . $list_type . '_lists', $lists);
                }
            }
        }

        /**
         * Remove user list from cookies
         *
         * @access public
         * @param string $list_id
         * @param string $list_type
         * @param int $timestamp
         * @return void
         */
        public static function remove_user_list_from_cookies($list_id, $list_type)
        {
            // Check list-specific cookie and expire it
            $list_key = '' . $list_type . '_list_' . $list_id;

            if (isset($_COOKIE[$list_key])) {
                setcookie($list_key, 0, time()-100, COOKIEPATH, COOKIE_DOMAIN);
                unset($_COOKIE[$list_key]);
            }
        }

        /**
         * Read user lists
         *
         * @access public
         * @param string $list_type
         * @param int $user_id
         * @return void
         */
        public static function read_user_lists($list_type, $user_id = 0)
        {
            $lists_output = array();
            $default_array = array('timestamp' => '', 'email' => '', 'groups' => array());

            if ($user_id > 0) {

                if ($lists = get_user_meta($user_id, '' . $list_type . '_lists', true)) {

                    foreach ($lists as $key => $value) {

                        // Old format
                        if (!is_array($value)) {
                            $lists_output[$value] = $default_array;
                        }
                        // New format
                        else {
                            $lists_output[$key] = $value;
                        }
                    }
                }
            }

            else {

                // Set the matching pattern
                $cookie_name_preg_part = '/^' . $list_type . '_list_/';

                // Iterate $_COOKIE array
                foreach ($_COOKIE as $cookie_name => $cookie_value) {

                    if (preg_match($cookie_name_preg_part, $cookie_name)) {

                        // Clean up the list id
                        $list_id = preg_replace($cookie_name_preg_part, '', $cookie_name);

                        // Old format - pass on empty structure
                        if ($cookie_value == '1') {
                            $lists_output[$list_id] = $default_array;
                        }
                        // New format - extract the array
                        else {

                            $list_data = explode('|', $cookie_value);

                            // Save timestamp
                            $lists_output[$list_id]['timestamp'] = $list_data[0];

                            // Save email
                            $lists_output[$list_id]['email'] = $list_data[1];

                            // Remove timestamp and email
                            unset($list_data[0]);
                            unset($list_data[1]);

                            // Save groups
                            $lists_output[$list_id]['groups'] = !empty($list_data[2]) ? $list_data : array();
                        }
                    }
                }
            }

            return $lists_output;
        }

        /**
         * Sync user lists
         *
         * @access public
         * @param int $user_id
         * @return void
         */
        public static function sync_user_lists($user_id = 0)
        {
            // There's no point in sync without user id
            if ($user_id == 0) {
                return false;
            }

            // Get all possible data first
            $lists = array(
                'subscribed' => array(
                    'meta' => self::read_user_lists('subscribed', $user_id),
                    'cookies' => self::read_user_lists('subscribed', 0),
                ),
                'unsubscribed' => array(
                    'meta' => self::read_user_lists('unsubscribed', $user_id),
                    'cookies' => self::read_user_lists('unsubscribed', 0),
                ),
            );

            // Set the opposite lists
            $opposite = array(
                'subscribed'   => 'unsubscribed',
                'unsubscribed' => 'subscribed',
                'meta'         => 'cookies',
                'cookies'      => 'meta',
            );

            // Iterate list types
            foreach (array('subscribed', 'unsubscribed') as $list_type) {

                // Create new array for current list type
                $all_lists = array();

                // Iterate data types
                foreach (array('meta', 'cookies') as $data_type) {

                    // Check if there's any data
                    if (empty($lists[$list_type][$data_type])) {
                        continue;
                    }

                    // Iterate lists to check for updates
                    foreach ($lists[$list_type][$data_type] as $list_id => $list_data) {

                        // List is already set in the same list type
                        if (isset($all_lists[$list_id])) {

                            // Check timestamp
                            if ($list_data['timestamp'] > $all_lists[$list_id]['timestamp']) {

                                // Update only if it's newer
                                $all_lists[$list_id] = $list_data;
                            }
                        }

                        // No such list added yet - add it now
                        else {
                            $all_lists[$list_id] = $list_data;
                        }

                        // Check if list is set in the opposite list type
                        if (isset($lists[$opposite[$list_type]][$data_type][$list_id])) {

                            $opposite_list_data = $lists[$opposite[$list_type]][$data_type][$list_id];

                            // Check timestamp - remove list only if it's newer
                            if ($opposite_list_data['timestamp'] > $all_lists[$list_id]['timestamp']) {
                                unset($all_lists[$list_id]);
                            }
                        }
                        else if (isset($lists[$opposite[$list_type]][$opposite[$data_type]][$list_id])) {

                            $opposite_list_data_alt = $lists[$opposite[$list_type]][$opposite[$data_type]][$list_id];

                            // Check timestamp - remove list only if it's newer
                            if ($opposite_list_data_alt['timestamp'] > $all_lists[$list_id]['timestamp']) {
                                unset($all_lists[$list_id]);
                            }
                        }
                    }
                }

                // Write the updated data in meta
                update_user_meta($user_id, '' . $list_type . '_lists', $all_lists);

                // Remove outdated lists in cookies
                foreach ($lists[$list_type]['cookies'] as $list_id => $list_data) {

                    // Remove if it wasn't selected
                    if (!in_array($list_id, array_keys($all_lists))) {
                        self::remove_user_list_from_cookies($list_id, $list_type);
                    }
                }

                // Write the updated lists in cookies
                foreach ($all_lists as $list_id => $list_data) {
                    self::update_user_list_cookie($list_id, $list_type, $list_data['timestamp'], $list_data['email'], $list_data['groups']);
                }
            }
        }

        /**
         * Send request to get user groups from MailChimp
         *
         * @access public
         * @param int $user_id
         * @return void
         */
        public static function get_user_groups_request($user_id = 0)
        {
            // Get url
            $url = add_query_arg('custom-get-user-groups', $user_id, site_url());

            // Get args
            $args = array(
                'timeout'   => 0.01,
                'blocking'  => false,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
	    );

            // Send local request
	    wp_remote_post($url, $args);
        }

        /**
         * Actually get user groups from MailChimp and update meta/cookies
         *
         * @access public
         * @param string $list_type
         * @param string $data_type
         * @param int $user_id
         * @return void
         */
        public function get_user_groups_handler()
        {
            // Check if id was passed
            if (isset($_GET['custom-get-user-groups'])) {
                $user_id = $_GET['custom-get-user-groups'];
            }
            else {
                return false;
            }

            // Load MailChimp
            if (!$this->load_mailchimp()) {
                return false;
            }

            // Get user lists and email
            $subscribed_lists_full = self::read_user_lists('subscribed', $user_id);
            $subscribed_lists = array_keys($subscribed_lists_full);
            $email = get_user_meta($user_id, 'billing_email', true);

            $new_lists = array();

            foreach ($subscribed_lists as $list_id) {

                try {
                    $user_data = $this->mailchimp->get_member($list_id, $email);

                    $groups = array();

                    foreach ($user_data['interests'] as $interest_id => $subscribed) {

                        if ($subscribed !== false && !empty($subscribed)) {
                            $groups[] = $interest_id;
                        }
                    }

                    $timestamp = time();

                    $new_lists[$list_id] = array(
                        'timestamp' => $timestamp,
                        'email'     => $email,
                        'groups'    => $groups,
                    );

                    // Update user meta
                    if (!empty($new_lists)) {
                        $new_lists = array_merge($subscribed_lists_full, $new_lists);
                        update_user_meta($user_id, 'subscribed_lists', $new_lists);
                    }

                    // Update cookie
                    self::update_user_list_cookie($list_id, 'subscribed', $timestamp, $email, $groups);

                    // Mark user
                    update_user_meta($user_id, 'user_groups_requested', 1);
                }
                catch (Exception $e) {
                    return false;
                }
            }
        }

        /**
         * Launches various methods to update and sync lists/groups user data
         *
         * @access public
         * @return bool
         */
        public function user_lists_data_update()
        {
            // Get user id
            $user_id = get_current_user_id();

            // Check user id
            if ($user_id === 0) {
                return false;
            }

            // Check page
            if (!is_account_page() && !is_cart() && !is_checkout()) {
                return false;
            }

            // Make sure meta is migrated, but still with older timestamps
            self::migrate_user_lists_meta('subscribed', time() - 20, $user_id);
            self::migrate_user_lists_meta('unsubscribed', time() - 10, $user_id);

            // Check meta and maybe send request to MC
            $user_groups_requested = get_user_meta($user_id, 'user_groups_requested', true);

            if (empty($user_groups_requested)) {
                self::get_user_groups_request($user_id);
                return true;
            }

            // Sync local data
            self::sync_user_lists($user_id);
            return true;
        }

        /**
         * Check if user is already subscribed to any of checkbox lists
         *
         * @access public
         * @return void
         */
        public function can_user_subscribe_with_checkbox()
        {
            // Get user meta
            $user_id = get_current_user_id();
            $user_meta = is_user_logged_in() ? get_user_meta($user_id) : array();

            // Iterate the sets and check all lists
            if (isset($this->opt['sets_checkbox']) && is_array($this->opt['sets_checkbox'])) {
                foreach ($this->opt['sets_checkbox'] as $set) {

                    // Check conditions
                    if ($this->conditions_check($set, 'checkbox', null, $user_meta, $user_id, true)) {

                        // Get user lists
                        $subscribed_lists = self::read_user_lists('subscribed', $user_id);
                        $subscribed_lists = array_keys($subscribed_lists);

                        // Check meta and cookies and return true if at least one list is not there
                        if (is_user_logged_in()) {

                            // For users check only meta
                            if ((!empty($subscribed_lists) && ((is_array($subscribed_lists) && !in_array($set['list'], $subscribed_lists)) || (!is_array($subscribed_lists) && $subscribed_lists != $set['list']))) || empty($subscribed_lists)) {
                                return true;
                            }
                        }

                        else {

                            // For guests check cookies
                            if (!isset($_COOKIE['subscribed_list_' . $set['list']])) {
                                return true;
                            }
                        }
                    }
                }
            }

            return false;
        }

        /**
         * Get the list of default country names from MC which don't match WC's defalut names
         *
         * @access public
         * @return array
         */
        public static function get_mc_countries_exceptions()
        {
            return array(
                'AX' => __('Aaland Islands', 'custom'),
                'AG' => __('Antigua And Barbuda', 'custom'),
                'BN' => __('Brunei Darussalam', 'custom'),
                'CG' => __('Congo', 'custom'),
                'CD' => __('Democratic Republic of the Congo', 'custom'),
                'CI' => __('Cote D\'Ivoire', 'custom'),
                'CW' => __('Curacao', 'custom'),
                'HM' => __('Heard and Mc Donald Islands', 'custom'),
                'IE' => __('Ireland', 'custom'),
                'JE' => __('Jersey  (Channel Islands)', 'custom'),
                'LA' => __('Lao People\'s Democratic Republic', 'custom'),
                'MO' => __('Macau', 'custom'),
                'FM' => __('Micronesia, Federated States of', 'custom'),
                'MD' => __('Moldova, Republic of', 'custom'),
                'PW' => __('Palau', 'custom'),
                'PS' => __('Palestine', 'custom'),
                'WS' => __('Samoa (Independent)', 'custom'),
                'ST' => __('Sao Tome and Principe', 'custom'),
                'SX' => __('Sint Maarten', 'custom'),
                'GS' => __('South Georgia and the South Sandwich Islands', 'custom'),
                'SH' => __('St. Helena', 'custom'),
                'PM' => __('St. Pierre and Miquelon', 'custom'),
                'SJ' => __('Svalbard and Jan Mayen Islands', 'custom'),
                'TC' => __('Turks & Caicos Islands', 'custom'),
                'GB' => __('United Kingdom', 'custom'),
                'US' => __('United States of America', 'custom'),
                'VA' => __('Vatican City State (Holy See)', 'custom'),
                'WF' => __('Wallis and Futuna Islands', 'custom'),
                'VG' => __('Virgin Islands (British)', 'custom'),
            );
        }

        /**
         * Update user meta values
         *
         * @access public
         * @param int $user_id
         * @param string $meta_key
         * @param string $new_value
         * @return void
         */
        public static function update_user_meta($user_id, $meta_key, $new_value)
        {
            // Get existing value
            $existing_meta = get_user_meta($user_id, $meta_key, true);

            // Make sure new value is array
            $new_value = is_array($new_value) ? $new_value : array($new_value);

            // If field is not new, convert existing to array as well and merge both values
            if ($existing_meta != '') {
                $existing_meta = is_array($existing_meta) ? $existing_meta : array($existing_meta);
                $new_value = array_merge($existing_meta, $new_value);
            }

            update_user_meta($user_id, $meta_key, $new_value);
        }

        /**
         * Add permission checkbox to checkout page
         *
         * @access public
         * @return void
         */
        public function add_permission_question()
        {
            // Skip some Ajax requests
            if (defined('DOING_AJAX') && DOING_AJAX && $this->opt['checkbox_position'] == 'woocommerce_review_order_before_order_total') {
                return;
            }

            // Check if functionality is enabled
            if (!$this->opt['enabled'] || !$this->checkout_checkbox_is_active()) {
                return;
            }

            // Check if user is already subscribed
            $already_subscribed = ($this->can_user_subscribe_with_checkbox() === false) ? true : false;

            // Maybe hide checkbox for already subscribed user
            if ($already_subscribed && $this->opt['hide_checkbox'] == '1') {
                return;
            }

            // Prepare checkbox block
            $checkbox_block = '<p class="checkout_checkbox" style="padding:15px 0;">';
            $checkbox_state = ($already_subscribed || $this->opt['default_state'] == '1') ? 'checked="checked"' : '';
            $checkbox_block .= '<input id="user_preference" name="data[user_preference]" type="checkbox" ' . $checkbox_state . '> <label for="user_preference">' . $this->prepare_label('text_checkout', false) . '</label>';
            $checkbox_block .= '</p>';

            // Maybe prepare groups
            $groups = $this->add_groups();

            // Display the html
            if ($already_subscribed === false || ($already_subscribed && ($this->opt['hide_checkbox'] == '2' || ($this->opt['hide_checkbox'] == '3' && !empty($groups['data']) && !empty($groups['html']))))) {
                echo $checkbox_block;
                echo $groups['html'];
            }

            // Load assets
            $this->load_frontend_assets('checkbox');
        }

        /**
         * Backup campaign cookies - in case of empty $_COOKIE
         *
         * @access public
         * @return void
         */
        public function backup_campaign_cookies()
        {
            // Insert hidden fields
            echo '<input id="cookie_mc_eid" name="data[mc_eid]" type="hidden">
                  <input id="cookie_mc_cid" name="data[mc_cid]" type="hidden">';

            // Enqueue jQuery cookie (if not yet)
            if (!wp_script_is('jquery-cookie', 'enqueued')) {
                wp_register_script('jquery-cookie', PLUGIN_URL . '/assets/js/jquery.cookie.js', array('jquery'), '1.4.1');
                wp_enqueue_script('jquery-cookie');
            }

            // Launch our JS script, which will store cookie values in hidden fields
            wp_register_script('custom-cookie', PLUGIN_URL . '/assets/js/custom-cookie.js', array('jquery'), VERSION);
            wp_enqueue_script('custom-cookie');
        }

        /**
         * Maybe add groups after subscribe on checkout checkbox
         *
         * @access public
         * @return void
         */
        public function add_groups()
        {
            // Check if it's needed
            $method = $this->opt['checkout_groups_method'];

            if (!$method || $method == 'auto') {
                return;
            }

            // Process groups to array
            if (isset($this->opt['sets_checkbox']) && is_array($this->opt['sets_checkbox'])) {

                $groupings = array();
                $required_groups = array();

                // Prepare all groups for this sets/lists (to create nice titles)
                $all_sets_groups_lists = $this->get_groups($this->opt['sets_checkbox'], false);

                $all_sets_groups = array();
                foreach ($all_sets_groups_lists as $list) {
                    $all_sets_groups = array_merge($all_sets_groups, $list);
                }

                foreach ($this->opt['sets_checkbox'] as $set) {

                    if (isset($set['groups']) && is_array($set['groups']) && !empty($set['groups']) ) {

                        foreach ($set['groups'] as $group) {

                            // Grouping id and group name
                            $group_parts = preg_split('/:/', $group);
                            $group_id = $group_parts[0];
                            $group_name = $group_parts[1];

                            foreach ($all_sets_groups as $grouping_key => $groups) {

                                if (isset($groups['groups'][$group_id])) {

                                    // Add title
                                    if (!isset($groupings[$grouping_key]['title'])) {
                                        $groupings[$grouping_key]['title'] = trim($groups['title']);
                                    }

                                    // Add group
                                    if (!isset($groupings[$grouping_key][$group])) {
                                        $groupings[$grouping_key][$group] = $group_name;
                                    }

                                    // Check if required
                                    if (in_array($method, array('single_req', 'select_req'))) {
                                        $required_groups[] = $grouping_key;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Try to get user and his lists
            $user_id = is_user_logged_in() ? get_current_user_id() : 0;
            $subscribed_lists = self::read_user_lists('subscribed', $user_id);

            $all_user_groups = array();

            foreach ($subscribed_lists as $list_id => $list_data) {
                $all_user_groups = array_merge($all_user_groups, $list_data['groups']);
            }

            // Show groups selection
            $html = '<div id="checkout_groups">';

            foreach ($groupings as $group_key => $group_data) {

                $title = $group_data['title'] ? $group_data['title'] : __('Grouping', 'custom') . ' ' . $group_key;
                $required = (!empty($required_groups) && in_array($group_key, $required_groups)) ? 'required' : '';

                // Select field begin
                if (in_array($method, array('select', 'select_req'))) {
                    $html .= '<section><label class="select">';
                    $html .= '<select class="checkout_field_' . $group_key . '" '
                           . 'name="data[groups][' . $group_key . ']" ' . $required . '>'
                           . '<option value="" disabled selected>' . $title . '</option>';
                }
                else {
                    $html .= '<label class="label">' . $title . '</label>';
                }

                unset($group_data['title']);

                $html .= '<br>';

                foreach ($group_data as $group_value => $group_name) {

                    $group_value_parts = preg_split('/:/', $group_value);
                    $selected = in_array($group_value_parts[0], $all_user_groups) ? true : false;

                    // Display checkbox group
                    if ($method == 'multi') {

                        $html .= '<label class="checkbox">';

                        $html .= '<input type="checkbox" '
                               . 'class="checkout_field_' . $group_key . '" '
                               . 'name="data[groups][' . $group_key . '][]" '
                               . 'value="' . $group_value . '" ' . $required . ($selected ? 'checked' : '') .'>';

                        $html .= ' ' . $group_name . '</label>';
                    }

                    // Display select field options
                    else if (in_array($method, array('select', 'select_req'))) {
                        $html .= '<option value="' . $group_value . '">' . $group_name . '</option>';
                    }

                    // Display radio set
                    else {

                        $html .= '<label class="radio">';

                        $html .= '<input type="radio" '
                               . 'class="checkout_field_' . $group_key . '" '
                               . 'name="data[groups][' . $group_key . ']" '
                               . 'value="' . $group_value . '" ' . $required . ($selected ? 'checked' : '') . '>';

                        $html .= ' ' . $group_name . '</label>';
                    }

                    $html .= '<br>';
                }

                // Select field end
                if (in_array($method, array('select', 'select_req'))) {
                    $html .= '</select></label></section>';
                }
            }

            $html .= '</div>';

            // Adding required groups as variable
            if (!empty($required_groups)) {
                $html .= '<script type="text/javascript">'
                   . 'var checkout_required_groups = '
                   . json_encode($required_groups)
                   . '</script>';
            }

            return array('data' => $groupings,
                         'html' => $html);
        }

        /**
         * Display subscription form in place of shortcode
         *
         * @access public
         * @param mixed $attributes
         * @return string
         */
        public function subscription_shortcode($attributes)
        {
            // Check if functionality is enabled
            if (!$this->opt['enabled'] || !$this->opt['enabled_shortcode']) {
                return '';
            }

            // Prepare form
            $form = prepare_form($this->opt, 'shortcode');

            return $form;
        }

        /**
         * Subscribe user from shortcode form
         *
         * @access public
         * @return void
         */
        public function ajax_subscribe_shortcode()
        {
            // Check if feature is enabled
            if (!$this->opt['enabled'] || !$this->opt['enabled_shortcode']) {
                echo $this->prepare_json_label('label_error', true);
                die();
            }

            // Check if data was received
            if (!isset($_POST['data'])) {
                echo $this->prepare_json_label('label_error', true);
                die();
            }

            $data = array();
            parse_str($_POST['data'], $data);

            // Check if our vars were received
            if (!isset($data['shortcode_subscription']) || empty($data['shortcode_subscription'])) {
                echo $this->prepare_json_label('label_error', true);
                die();
            }

            $data = $data['shortcode_subscription'];

            // Check if email was received
            if (!isset($data['email']) || empty($data['email'])) {
                echo $this->prepare_json_label('label_error', true);
                die();
            }

            $email = $data['email'];

            // Parse custom fields
            $custom_fields = array();

            if (isset($data['custom']) && !empty($data['custom'])) {
                foreach ($data['custom'] as $key => $value) {
                    $field_ok = false;

                    foreach ($this->opt['shortcode_fields'] as $custom_field) {
                        if ($key == $custom_field['tag']) {
                            $field_ok = true;
                            break;
                        }
                    }

                    if ($field_ok) {
                        $custom_fields[$key] = $value;
                    }
                }
            }

            // Subscribe user
            $result = $this->subscribe($this->opt['list_shortcode'], $email, $this->opt['groups_shortcode'], $custom_fields);

            // Subscribe successfully
            if ($result === true) {
                echo $this->prepare_json_label('label_success', false);
                die();
            }

            // Already subscribed
            else if ($result == 'member_exists') {
                echo $this->prepare_json_label('label_already_subscribed', true);
                die();
            }

            // Other errors
            echo $this->prepare_json_label('label_error', true);
            die();
        }

        /**
         * Subscribe user from widget form
         *
         * @access public
         * @return void
         */
        public function ajax_subscribe_widget()
        {
            // Check if feature is enabled
            if (!$this->opt['enabled'] || !$this->opt['enabled_widget']) {
                echo $this->prepare_json_label('label_error', true);
                die();
            }

            // Check if data was received
            if (!isset($_POST['data'])) {
                echo $this->prepare_json_label('label_error', true);
                die();
            }

            $data = array();
            parse_str($_POST['data'], $data);

            // Check if our vars were received
            if (!isset($data['widget_subscription']) || empty($data['widget_subscription'])) {
                echo $this->prepare_json_label('label_error', true);
                die();
            }

            $data = $data['widget_subscription'];

            // Check if email was received
            if (!isset($data['email']) || empty($data['email'])) {
                echo $this->prepare_json_label('label_error', true);
                die();
            }

            $email = $data['email'];

            // Parse custom fields
            $custom_fields = array();

            if (isset($data['custom']) && !empty($data['custom'])) {
                foreach ($data['custom'] as $key => $value) {
                    $field_ok = false;

                    foreach ($this->opt['widget_fields'] as $custom_field) {
                        if ($key == $custom_field['tag']) {
                            $field_ok = true;
                            break;
                        }
                    }

                    if ($field_ok) {
                        $custom_fields[$key] = $value;
                    }
                }
            }

            // Subscribe user
            $result = $this->subscribe($this->opt['list_widget'], $email, $this->opt['groups_widget'], $custom_fields);

            // Subscribe successfully
            if ($result === true) {
                echo $this->prepare_json_label('label_success', false);
                die();
            }

            // Already subscribed
            else if ($result == 'member_exists') {
                echo $this->prepare_json_label('label_already_subscribed', true);
                die();
            }

            // Other errors
            echo $this->prepare_json_label('label_error', true);
            die();
        }

        /**
         * Get label for output
         *
         * @access public
         * @param int $key
         * @param bool $decode
         * @return void
         */
        public function prepare_label($key, $decode = true)
        {
            // Check if set
            if (empty($key) || !isset($this->opt[$key])) {
                return false;
            }

            // Decode HTML
            if ($decode) {
                return htmlspecialchars_decode($this->opt[$key]);
            }

            // Output as saved
            else {
                return $this->opt[$key];
            }

            return false;
        }

        /**
         * Get label for output in JSON-encoded format
         *
         * @access public
         * @param int $key
         * @param bool $error
         * @return void
         */
        public function prepare_json_label($key, $error = false)
        {
            // Check if set
            $label = $this->prepare_label($key);

            if ($label === false) {
                return false;
            }

            return json_encode(array('error' => (($error === true) ? 1 : 0), 'message' => $label), JSON_HEX_TAG);
        }

        /**
         * Check if curl is enabled
         *
         * @access public
         * @return void
         */
        public function curl_enabled()
        {
            if (function_exists('curl_version')) {
                return true;
            }

            return false;
        }

        /**
         * Process MailChimp Webhook call
         *
         * @access public
         * @return void
         */
        public function process_webhook() {

            // Handle unsubsribe event
            if (!empty($_POST) && isset($_POST['type'])) {
                switch($_POST['type']){

                    // Unsubscribe
                    case 'unsubscribe':

                        // Load user
                        if ($user = get_user_by('email', $_POST['data']['email'])) {
                            self::remove_user_list($_POST['data']['list_id'], 'subscribed', $user->ID);
                            self::track_user_list($_POST['data']['list_id'], 'unsubscribed', $_POST['data']['email'], array(), $user->ID);
                        }

                        break;

                    // Other available:
                    // case 'subscribe'
                    // case 'cleaned'
                    // case 'upemail'
                    // case 'profile'
                    // case 'campaign'

                    // Default
                    default:
                        break;
                }
            }

            die();
        }

        /**
         * Get all lists plus groups and fields for selected lists in array
         *
         * @access public
         * @return void
         */
        public function ajax_lists_for_checkout()
        {
            if (isset($_POST['data'])) {
                $data = $_POST['data'];
            }
            else {
                $data = array();
            }

            // Get lists
            $lists = $this->get_lists();

            // Check if we have something pre-selected
            if (!empty($data)) {

                // Get merge vars
                $merge = $this->get_merge_vars($lists);

                // Get sets from correct option
                $sets = (isset($data['sets_type']) && isset($this->opt[$data['sets_type']])) ? $this->opt[$data['sets_type']] : $this->opt['sets'];

                // Get groups
                $groups = $this->get_groups($sets);

            }
            else {

                $merge = array();
                $groups = array();

                foreach ($lists as $list_key => $list_value) {

                    if ($list_key == '') {
                        continue;
                    }

                    // Blank merge vars
                    $merge[$list_key] = array('' => '');

                    // Blank groups
                    $groups[$list_key] = array('' => '');
                }
            }

            // Add all checkout properties
            $checkout_properties = $this->checkout_properties;

            echo json_encode(array('message' => array('lists' => $lists, 'groups' => $groups, 'merge' => $merge, 'checkout_properties' => $checkout_properties)));
            die();
        }

        /**
         * Ajax - Return products list
         */
        public function ajax_product_search($find_variations = false)
        {
            $results = array();

            // Check if query string is set
            if (isset($_POST['q'])) {
                $kw = $_POST['q'];
                $search_query = new WP_Query(array('s' => "$kw", 'post_type' => 'product'));

                if ($search_query->have_posts()) {
                    while ($search_query->have_posts()) {
                        $search_query->the_post();
                        $post_title = get_the_title();
                        $post_id = get_the_ID();

                        // Variation product
                        if ($find_variations) {

                            $product = self::wc_version_gte('2.2') ? wc_get_product($post_id) : get_product($post_id);

                            if ($product->product_type == 'variable') {
                                $variations = $product->get_available_variations();

                                foreach ($variations as $variation) {
                                    $results[] = array('id' => $variation['variation_id'], 'text' => get_the_title($variation['variation_id']));
                                }
                            }
                        }

                        // Regular product
                        else {
                            $results[] = array('id' => $post_id, 'text' => $post_title);
                        }
                    }
                }

                // If no posts found
                else {
                    $results[] = array('id' => 0, 'text' => __('Nothing found.', 'custom'), 'disabled' => 'disabled');
                }
            }

            // If no search query was sent
            else {
                $results[] = array('id' => 0, 'text' => __('No query was sent.', 'custom'), 'disabled' => 'disabled');
            }

            echo json_encode(array('results' => $results));
            die();
        }

        /**
         * Ajax - Return product variations list
         */
        public function ajax_product_variations_search()
        {
            $this->ajax_product_search(true);
        }

        /**
         * Get WooCommerce order
         *
         * @access public
         * @param int $order_id
         * @return object
         */
        public static function wc_get_order($order_id)
        {
            if (self::wc_version_gte('2.2')) {
                return wc_get_order($order_id);
            }
            else {
                return new WC_Order($order_id);
            }
        }

        /**
         * Check if order has been paid
         *
         * @access public
         * @param mixed $order
         * @return bool
         */
        public static function order_is_paid($order)
        {
            // Load order if order id was passed in
            if (!is_object($order)) {
                $order = self::wc_get_order($order);
            }

            // Check if order was loaded
            if (!$order) {
                return false;
            }

            // Check if order is paid
            if (self::wc_version_gte('2.5')) {
                return $order->is_paid();
            }
            else {

                // Get paid statuses
                $paid_statuses = apply_filters('woocommerce_order_is_paid_statuses', array('processing', 'completed'));

                // Check if order has paid status
                if (self::wc_version_gte('2.2')) {
                    $has_paid_status = $order->has_status($paid_statuses);
                }
                else {
                    $order_status = apply_filters('woocommerce_order_get_status', 'wc-' === substr($order->post_status, 0, 3) ? substr($order->post_status, 3) : $order->post_status, $order);
                    $has_paid_status = apply_filters('woocommerce_order_has_status', (is_array($paid_statuses) && in_array($order_status, $paid_statuses) ) || $order_status === $paid_statuses ? true : false, $order, $paid_statuses);
                }

                return apply_filters('woocommerce_order_is_paid', $has_paid_status, $order);
            }
        }

        /**
         * Check WooCommerce version
         *
         * @access public
         * @param string $version
         * @return bool
         */
        public static function wc_version_gte($version)
        {
            if (defined('WC_VERSION') && WC_VERSION) {
                return version_compare(WC_VERSION, $version, '>=');
            }
            else if (defined('WOOCOMMERCE_VERSION') && WOOCOMMERCE_VERSION) {
                return version_compare(WOOCOMMERCE_VERSION, $version, '>=');
            }
            else {
                return false;
            }
        }

        /**
         * Check WordPress version
         *
         * @access public
         * @param string $version
         * @return bool
         */
        public static function wp_version_gte($version)
        {
            $wp_version = get_bloginfo('version');

            if ($wp_version) {
                return version_compare($wp_version, $version, '>=');
            }

            return false;
        }

        /**
         * Check if environment meets requirements
         *
         * @access public
         * @return bool
         */
        public static function check_environment()
        {
            $is_ok = true;

            // Check PHP version (RightPress Helper requires PHP 5.3 for itself)
            if (!version_compare(PHP_VERSION, SUPPORT_PHP, '>=')) {
                add_action('admin_notices', array('custom', 'php_version_notice'));
                $is_ok = false;
            }

            // Check WordPress version
            if (!self::wp_version_gte(SUPPORT_WP)) {
                add_action('admin_notices', array('custom', 'wp_version_notice'));
                $is_ok = false;
            }

            // Check if WooCommerce is enabled
            if (!class_exists('WooCommerce')) {
                add_action('admin_notices', array('custom', 'wc_disabled_notice'));
                $is_ok = false;
            }
            else if (!self::wc_version_gte(SUPPORT_WC)) {
                add_action('admin_notices', array('custom', 'wc_version_notice'));
                $is_ok = false;
            }

            // Get options directly, as the class isn't loaded yet
            $options = get_option('options');

            // Check if E-Commerce is enabled and list for Store is selected
            if ($options['send_order_data'] === '1' && empty($options['list_store'])) {
                add_action('admin_notices', array('custom', 'store_not_configured_notice'));
            }

            return $is_ok;
        }

        /**
         * Display 'Store not configured' notice
         *
         * @access public
         * @return void
         */
        public static function store_not_configured_notice()
        {
            echo '<div class="error"><p>' . sprintf(__('<strong>Warning!</strong> MailChimp E-Commerce functionality requires a Store to be configured. You can do this %s.', 'custom'), '<a href="' . admin_url('admin.php?page=custom&tab=ecomm') . '">' . __('here', 'custom') . '</a>') . '</p></div>';
        }

        /**
        * Display PHP version notice
        *
        * @access public
        * @return void
        */
       public static function php_version_notice()
       {
           echo '<div class="error"><p>' . sprintf(__('<strong>custom</strong> requires PHP %s or later. Please update PHP on your server to use this plugin.', 'custom'), SUPPORT_PHP) . ' ' . sprintf(__('If you have any questions, please contact %s.', 'custom'), '<a href="http://url.rightpress.net/new-support-ticket">' . __('RightPress Support', 'custom') . '</a>') . '</p></div>';
       }

        /**
         * Display WP version notice
         *
         * @access public
         * @return void
         */
        public static function wp_version_notice()
        {
            echo '<div class="error"><p>' . sprintf(__('<strong>custom</strong> requires WordPress version %s or later. Please update WordPress to use this plugin.', 'custom'), SUPPORT_WP) . ' ' . sprintf(__('If you have any questions, please contact %s.', 'custom'), '<a href="http://url.rightpress.net/new-support-ticket">' . __('RightPress Support', 'custom') . '</a>') . '</p></div>';
        }

        /**
         * Display WC disabled notice
         *
         * @access public
         * @return void
         */
        public static function wc_disabled_notice()
        {
            echo '<div class="error"><p>' . sprintf(__('<strong>custom</strong> requires WooCommerce to be activated. You can download WooCommerce %s.', 'custom'), '<a href="http://url.rightpress.net/woocommerce-download-page">' . __('here', 'custom') . '</a>') . ' ' . sprintf(__('If you have any questions, please contact %s.', 'custom'), '<a href="http://url.rightpress.net/new-support-ticket">' . __('RightPress Support', 'custom') . '</a>') . '</p></div>';
        }

        /**
         * Display WC version notice
         *
         * @access public
         * @return void
         */
        public static function wc_version_notice()
        {
            echo '<div class="error"><p>' . sprintf(__('<strong>custom</strong> requires WooCommerce version %s or later. Please update WooCommerce to use this plugin.', 'custom'), SUPPORT_WC) . ' ' . sprintf(__('If you have any questions, please contact %s.', 'custom'), '<a href="http://url.rightpress.net/new-support-ticket">' . __('RightPress Support', 'custom') . '</a>') . '</p></div>';
        }

    }

    $GLOBALS['custom'] = new custom();

}

?>
