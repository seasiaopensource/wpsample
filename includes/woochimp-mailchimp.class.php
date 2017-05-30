<?php

/**
 * WooChimp MailChimp API Wrapper Class
 * Partly based on official MailChimp API Wrapper for PHP
 *
 * @class WooChimp_Mailchimp
 * @package WooChimp
 * @author RightPress
 */

if (!class_exists('WooChimp_Mailchimp')) {

    class WooChimp_Mailchimp
    {
        /**
         * API Key
         */
        public $apikey;
        public $ch;
        public $root = 'https://api.mailchimp.com/3.0';

        /**
         * Constructor class
         *
         * @access public
         * @param string $apikey
         * @return void
         */
        public function __construct($apikey, $log = false) {

            // Set up API Key
            if (!$apikey) {
                throw new Exception('You must provide a MailChimp API key');
            }

            $this->apikey = $apikey;

            // Set up host to connect to
            $dc = 'us1';

            if (strstr($this->apikey, '-')){
                list($key, $dc) = explode('-', $this->apikey, 2);

                if (!$dc) {
                    $dc = 'us1';
                }
            }

            $this->root = str_replace('https://api', 'https://' . $dc . '.api', $this->root);
            $this->root = rtrim($this->root, '/') . '/';

            // Maybe activate log
            if ($log !== false) {

                // Set logger object
                $this->logger = new WC_Logger();

                // Set type
                $this->log_type = $log;

                // Maybe migrate old log
                $this->log_migrate();
            }

            // Initialize Curl
            $this->ch = curl_init();

            curl_setopt($this->ch, CURLOPT_HTTPHEADER, array(
                'Accept: application/vnd.api+json',
                'Content-Type: application/vnd.api+json',
                'Authorization: apikey ' . $this->apikey
            ));
            curl_setopt($this->ch, CURLOPT_HEADER, false);
            curl_setopt($this->ch, CURLOPT_USERAGENT, 'MailChimp-API/3.0');

            curl_setopt($this->ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
            curl_setopt($this->ch, CURLOPT_ENCODING, '');
            curl_setopt($this->ch, CURLINFO_HEADER_OUT, true);

            curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($this->ch, CURLOPT_TIMEOUT, 600);
        }

        /**
         * Destructor class
         *
         * @access public
         * @return void
         */
        public function __destruct() {
            curl_close($this->ch);
        }

        /**
         * Make call to MailChimp
         *
         * @param type $http_verb
         * @param type $url
         * @param type $params
         */
        public function call($http_verb, $url, $params = array())
        {
            $request_url = $this->root . $url;
            $params_query = !empty($params) ? '?' . http_build_query($params) : '';
            $params_encoded = json_encode($params);

            $ch = $this->ch;
            curl_setopt($ch, CURLOPT_URL, $request_url);

            switch ($http_verb) {
                case 'post':
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $params_encoded);
                    break;

                case 'get':
                    curl_setopt($ch, CURLOPT_URL, $request_url . $params_query);
                    break;

                case 'delete':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                    break;

                case 'patch':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $params_encoded);
                    break;

                case 'put':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $params_encoded);
                    break;
            }

            $start = microtime(true);

            $response_body = curl_exec($ch);
            $info = curl_getinfo($ch);
            $time = microtime(true) - $start;

            // Check for curl error
            $curl_error = curl_error($ch) ? ('API call to ' . $url . ' failed: ' . curl_error($ch)) : false;

            // Decode result
            $result = json_decode($response_body, true);

            // Remove unneeded '_links' arrays from response
            $result = $this->remove_links_in_response($result);

            // Check for errors
            $response_error = (floor($info['http_code'] / 100) >= 4) ? true : false;

            // Write to log
            if (isset($this->logger)) {

                $error = ($response_error || $curl_error) ? true : false;

                if ($this->log_type == 'all' || ($error && $this->log_type == 'errors')) {

                    $this->log_add(__('REQUEST: ', 'woochimp') . $http_verb . ' ' . $url);

                    if (!empty($params)) {
                        $this->log_add($params);
                    }

                    $this->log_add(__('RESPONSE', 'woochimp') . ($error ? (' - ' . __('ERROR RECEIVED', 'woochimp')) : '') . ':');

                    if ($curl_error !== false) {
                        $this->log_add($curl_error);
                    }
                    else {
                        $this->log_add($result);
                    }
                }
            }

            // Process the errors
            if ($curl_error !== false) {
                throw new Exception($curl_error);
            }
            else if ($response_error === true) {

                if (!isset($result['status'])) {
                    throw new Exception('We received an unexpected error: ' . json_encode($result));
                }

                throw new Exception($result['title'] . '(' . $result['status'] . '): ' . $result['detail'] . (isset($result['errors']) ? ' ' . maybe_serialize($result['errors']) : ''));
            }

            return $result;
        }

        /**
         * Get account details (calling root)
         *
         * @access public
         * @param array $params
         * @return mixed
         */
        public function get_account_details($params = array())
        {
            return $this->call('get', '', $params);
        }

        /**
         * Get lists
         *
         * @access public
         * @param array $params
         * @return mixed
         */
        public function get_lists($params = array('count'  => 100))
        {
            return $this->call('get', 'lists', $params);
        }

        /**
         * Get list
         *
         * @access public
         * @param string $list_id
         * @param array $params
         * @return mixed
         */
        public function get_list($list_id, $params = array())
        {
            return $this->call('get', 'lists/' . $list_id, $params);
        }

        /**
         * Get merge fields
         *
         * @access public
         * @param string $list_id
         * @param array $params
         * @return mixed
         */
        public function get_merge_fields($list_id, $params = array('count'  => 100))
        {
            return $this->call('get', 'lists/' . $list_id . '/merge-fields', $params);
        }

        /**
         * Get interest categories
         *
         * @access public
         * @param string $list_id
         * @param array $params
         * @return mixed
         */
        public function get_interest_categories($list_id, $params = array('count'  => 100))
        {
            return $this->call('get', 'lists/' . $list_id . '/interest-categories', $params);
        }

        /**
         * Get interests
         *
         * @access public
         * @param string $list_id
         * @param array $params
         * @return mixed
         */
        public function get_interests($list_id, $category_id, $params = array('count'  => 100))
        {
            return $this->call('get', 'lists/' . $list_id . '/interest-categories/' . $category_id . '/interests', $params);
        }

        /**
         * Get member
         *
         * @access public
         * @param string $list_id
         * @param string $email
         * @param string $params
         * @return mixed
         */
        public function get_member($list_id, $email, $params = array())
        {
            $hash = self::member_hash($email);
            return $this->call('get', 'lists/' . $list_id . '/members/' . $hash, $params);
        }

        /**
         * Subscribe member
         *
         * @access public
         * @param string $list_id
         * @param array $params
         * @return mixed
         */
        public function post_member($list_id, $params)
        {
            return $this->call('post', 'lists/' . $list_id . '/members', $params);
        }

        /**
         * Subscribe or update member
         *
         * @access public
         * @param string $list_id
         * @param array $params
         * @return mixed
         */
        public function put_member($list_id, $params)
        {
            $hash = self::member_hash($params['email_address']);
            return $this->call('put', 'lists/' . $list_id . '/members/' . $hash, $params);
        }

        /**
         * Delete member
         *
         * @access public
         * @param string $list_id
         * @param string $email
         * @return mixed
         */
        public function delete_member($list_id, $email)
        {
            $hash = self::member_hash($email);
            return $this->call('delete', 'lists/' . $list_id . '/members/' . $hash);
        }

        /**
         * Get stores
         *
         * @access public
         * @return mixed
         */
        public function get_stores($params = array('count'  => 100))
        {
            return $this->call('get', 'ecommerce/stores/', $params);
        }

        /**
         * Create store
         *
         * @access public
         * @param array $params
         * @return mixed
         */
        public function create_store($params)
        {
            return $this->call('post', 'ecommerce/stores/', $params);
        }

        /**
         * Get customers (not used now)
         *
         * @access public
         * @param string $store_id
         * @param array $params
         * @return mixed
         */
        public function get_customers($store_id, $params = array('count'  => 1000))
        {
            return $this->call('get', 'ecommerce/stores/' . $store_id . '/customers', $params);
        }

        /**
         * Get products (not used now)
         *
         * @access public
         * @param string $store_id
         * @param array $params
         * @return mixed
         */
        public function get_products($store_id, $params = array('count'  => 1000))
        {
            return $this->call('get', 'ecommerce/stores/' . $store_id . '/products', $params);
        }

        /**
         * Get product
         *
         * @access public
         * @param string $store_id
         * @param string $product_id
         * @param array $params
         * @return mixed
         */
        public function get_product($store_id, $product_id, $params = array())
        {
            return $this->call('get', 'ecommerce/stores/' . $store_id . '/products/' . $product_id, $params);
        }

        /**
         * Create product
         *
         * @access public
         * @param string $store_id
         * @param array $params
         * @return mixed
         */
        public function create_product($store_id, $params)
        {
            return $this->call('post', 'ecommerce/stores/' . $store_id . '/products/', $params);
        }

        /**
         * Create product variant
         *
         * @access public
         * @param string $store_id
         * @param string $product_id
         * @param array $params
         * @return mixed
         */
        public function create_variant($store_id, $product_id, $params)
        {
            return $this->call('post', 'ecommerce/stores/' . $store_id . '/products/' . $product_id . '/variants/', $params);
        }

        /**
         * Get order
         *
         * @access public
         * @param string $store_id
         * @param string $order_id
         * @param array $params
         * @return mixed
         */
        public function get_order($store_id, $order_id, $params = array())
        {
            return $this->call('get', 'ecommerce/stores/' . $store_id . '/orders/' . $order_id, $params);
        }

        /**
         * Create order
         *
         * @access public
         * @param string $store_id
         * @param array $params
         * @return mixed
         */
        public function create_order($store_id, $params)
        {
            return $this->call('post', 'ecommerce/stores/' . $store_id . '/orders/', $params);
        }

        /**
         * Update order
         *
         * @access public
         * @param string $store_id
         * @param string $order_id
         * @param array $params
         * @return mixed
         */
        public function update_order($store_id, $order_id, $params)
        {
            return $this->call('patch', 'ecommerce/stores/' . $store_id . '/orders/' . $order_id, $params);
        }

        /**
         * Delete order
         *
         * @access public
         * @param string $store_id
         * @param string $order_id
         * @return mixed
         */
        public function delete_order($store_id, $order_id)
        {
            return $this->call('delete', 'ecommerce/stores/' . $store_id . '/orders/' . $order_id);
        }

        /**
         * Get member_hash
         *
         * @access public
         * @param string $email
         * @return mixed
         */
        public static function member_hash($email = '') {
            return md5(strtolower($email));
        }

        /**
         * Remove all huge '_links' arrays from response
         *
         * @access public
         * @return void
         */
        private function remove_links_in_response($result)
        {
            $result_changed = $result;

            // Sometimes links are in root
            if (isset($result_changed['_links'])) {
                unset($result_changed['_links']);
            }

            // main_key is 'lists', 'merge_fields' or 'total_items'
            foreach ($result as $main_key => $main_array) {

                // Check is it's array of items, not plain field (like 'total_items')
                if (is_array($main_array)) {

                    // Could be here
                    if (isset($main_array['_links'])) {
                        unset($result_changed[$main_key]['_links']);
                    }

                    // [0] => array of lists/interests/etc
                    foreach ($main_array as $key => $item_array) {

                        // Each item can have links array
                        if (isset($item_array['_links'])) {
                            unset($result_changed[$main_key][$key]['_links']);
                        }
                    }
                }
            }

            return $result_changed;
        }

        /**
         * Add log entry
         *
         * @access public
         * @return void
         */
        public function log_add($entry)
        {
            if (isset($this->logger)) {

                // Save string
                if (!is_array($entry)) {
                    $this->logger->add('woochimp_log', $entry);
                }

                // Save array
                else {
                    $this->logger->add('woochimp_log', print_r($entry, true));
                }
            }
        }

        /**
         * Migrate and unset old log
         *
         * @access public
         * @return void
         */
        public function log_migrate()
        {
            // Get existing value
            $woochimp_log = get_option('woochimp_log');

            // Already migrated
            if ($woochimp_log === false) {
                return;
            }

            // Migrate
            $this->logger->add('woochimp_log', __('OLD LOG START', 'woochimp'));
            $this->logger->add('woochimp_log', print_r($woochimp_log, true));
            $this->logger->add('woochimp_log', __('OLD LOG END', 'woochimp'));

            // Delete option
            delete_option('woochimp_log');
        }

        /**
         * Erase log
         *
         * @access public
         * @return void
         */
        private function log_erase()
        {
            if (isset($this->logger)) {
                $this->logger->clear('woochimp_log');
            }
        }

    }
}
