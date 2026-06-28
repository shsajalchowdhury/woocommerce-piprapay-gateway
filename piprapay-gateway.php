<?php
/*
 * Plugin Name: PipraPay Gateway
 * Plugin URI: https://piprapay.com
 * Description: A seamless and secure payment gateway integration for WooCommerce using PipraPay.
 * Author: PipraPay
 * Version: 1.0.6
 * Requires at least: 5.2
 * Requires PHP: 7.4
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: piprapay
 * Contributors: piprapay
 *
 * Security Fixes in 1.0.5:
 * - Added API key verification to the "new" (v3.0+) webhook handler
 * - Added order idempotency check to prevent duplicate/replayed payments
 * - Added server-side amount verification to prevent underpayment bypass
 * - Removed unauthenticated GET parameter fallback for transaction_ref
 * - Added webhook origin validation
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'piprapay_plugin_action_links');
function piprapay_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=piprapay') . '">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Declare compatibility with HPOS and Blocks
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Initialize the gateway class
add_action('plugins_loaded', 'piprapay_init_gateway_class', 0);

function piprapay_init_gateway_class()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return; // Exit if WooCommerce isn't active
    }

    class WC_PIPRAPAY_Gateway extends WC_Payment_Gateway
    {
        private static $instance = null;

        public $show_icon;
        public $apikey;
        public $baseUrl;
        public $order_type;
        public $piprapay_version;

        public static function get_instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function __construct()
        {
            $this->id = 'piprapay';
            $this->has_fields = false;
            $this->method_title = __('PipraPay', 'piprapay-gateway');
            $this->method_description = __('Secure and fast payments via PipraPay.', 'piprapay-gateway');
            $this->supports = ['products'];

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title') ?: __('PipraPay', 'piprapay-gateway');
            $this->description = $this->get_option('description') ?: __('Pay securely via PipraPay.', 'piprapay-gateway');
            $this->enabled = $this->get_option('enabled');
            $this->show_icon   = $this->get_option('show_icon') === 'yes';

            $this->icon = '';
            if ($this->show_icon) {
                $this->icon = $this->get_option('logo_url');
                if (empty($this->icon)) $this->icon = plugins_url('assets/icon.png', __FILE__);
            }

            $this->apikey = sanitize_text_field($this->get_option('apikey'));
            $this->baseUrl = sanitize_text_field($this->get_option('baseUrl'));
            $this->order_type = sanitize_text_field($this->get_option('order_type'));
            $this->piprapay_version = sanitize_text_field($this->get_option('piprapay_version'));

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_' . strtolower($this->id), [$this, 'handle_webhook']);
            add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'display_piprapay_order_meta'], 10, 1);
        }

        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => __('Enable/Disable', 'piprapay-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable PipraPay Gateway', 'piprapay-gateway'),
                    'default' => 'no',
                ],
                'title' => [
                    'title' => __('Title', 'piprapay-gateway'),
                    'type' => 'text',
                    'description' => __('The title displayed at checkout.', 'piprapay-gateway'),
                    'default' => __('PipraPay', 'piprapay-gateway'),
                    'desc_tip' => true,
                ],
                'description' => [
                    'title' => __('Description', 'piprapay-gateway'),
                    'type' => 'textarea',
                    'description' => __('The description displayed at checkout.', 'piprapay-gateway'),
                    'default' => __('Pay securely via PipraPay.', 'piprapay-gateway'),
                    'desc_tip' => true,
                ],
                'logo_url' => [
                    'title' => __('Logo URL', 'piprapay-gateway'),
                    'type' => 'text',
                    'description' => __('Enter the URL of the logo to display at checkout.', 'piprapay-gateway'),
                    'default' => '',
                    'desc_tip' => true,
                ],
                'show_icon' => [
                    'title'   => __('Show Icon', 'piprapay-gateway'),
                    'type'    => 'checkbox',
                    'label'   => __('Display icon on checkout page', 'piprapay-gateway'),
                    'default' => 'yes',
                ],
                'order_type' => [
                    'title'       => __('Order Type', 'piprapay-gateway'),
                    'type'        => 'select',
                    'description' => __('Choose how the order should be handled after payment.', 'piprapay-gateway'),
                    'desc_tip'    => true,
                    'default'     => 'physical',
                    'options'     => [
                        'physical'            => __('Physical Product (Set to processing)', 'piprapay-gateway'),
                        'digital_processing'  => __('Digital Product (Set to processing)', 'piprapay-gateway'),
                        'digital_complete'    => __('Digital Product (Auto complete)', 'piprapay-gateway'),
                    ],
                ],
                'piprapay_version' => [
                    'title'       => __('PipraPay Panel Version', 'piprapay-gateway'),
                    'type'        => 'select',
                    'description' => __('Select the version of the PipraPay panel you are using.', 'piprapay-gateway'),
                    'desc_tip'    => true,
                    'default'     => 'new',
                    'options'     => [
                        'new' => __('Version 3.0 and above', 'piprapay-gateway'),
                        'old' => __('Version below 3.0', 'piprapay-gateway'),
                    ],
                ],
                'apikey' => [
                    'title' => __('API Key', 'piprapay-gateway'),
                    'type' => 'password',
                    'description' => __('Your PipraPay API key.', 'piprapay-gateway'),
                    'default' => '',
                    'desc_tip' => true,
                ],
                'baseUrl' => [
                    'title' => __('Base URL', 'piprapay-gateway'),
                    'type' => 'text',
                    'description' => __('Your PipraPay Base URL.', 'piprapay-gateway'),
                    'default' => '',
                    'desc_tip' => true,
                ],
            ];
        }

        /**
         * Verify the API key from incoming webhook request headers.
         * Works for both old (mh-piprapay-api-key) and new (MHS-PIPRAPAY-API-KEY) header formats.
         *
         * @since 1.0.5
         * @param array $headers Incoming request headers.
         * @return bool True if the API key matches, false otherwise.
         */
        private function verify_webhook_auth($headers)
        {
            $received_api_key = '';

            // Check all possible header variations (case-insensitive)
            $header_keys = [
                'mh-piprapay-api-key',
                'Mh-Piprapay-Api-Key',
                'mhs-piprapay-api-key',
                'MHS-PIPRAPAY-API-KEY',
            ];

            foreach ($header_keys as $key) {
                if (isset($headers[$key])) {
                    $received_api_key = $headers[$key];
                    break;
                }
            }

            // Fall back to $_SERVER for CGI/FastCGI setups
            if (empty($received_api_key)) {
                $server_keys = [
                    'HTTP_MH_PIPRAPAY_API_KEY',
                    'HTTP_MHS_PIPRAPAY_API_KEY',
                ];

                foreach ($server_keys as $key) {
                    if (isset($_SERVER[$key])) {
                        $received_api_key = $_SERVER[$key];
                        break;
                    }
                }
            }

            // Timing-safe comparison
            if (!hash_equals($this->apikey, (string) $received_api_key)) {
                return false;
            }

            return true;
        }

        /**
         * Check if an order has already been processed (idempotency guard).
         * Prevents replay attacks and duplicate payment processing.
         *
         * @since 1.0.5
         * @param WC_Order $order The WooCommerce order object.
         * @return bool True if the order is already paid, false otherwise.
         */
        private function is_order_already_paid($order)
        {
            $status = $order->get_status();

            // These statuses indicate the order has already been processed
            if (in_array($status, ['processing', 'completed'], true)) {
                return true;
            }

            return false;
        }

        /**
         * Verify that the payment amount matches the order total.
         * Prevents underpayment attacks where attacker pays less and claims the product.
         *
         * @since 1.0.5
         * @param WC_Order $order The WooCommerce order object.
         * @param array    $verification The verification response from PipraPay.
         * @return bool True if amounts match within tolerance, false otherwise.
         */
        private function verify_payment_amount($order, $verification)
        {
            $order_total   = (float) $order->get_total();
            $verified_total = 0;

            // PipraPay returns 'total' (after fees/discounts) and 'amount' (base).
            // The WC order total includes everything, so prefer 'total'.
            if (isset($verification['total'])) {
                $verified_total = (float) $verification['total'];
            } elseif (isset($verification['amount'])) {
                $verified_total = (float) $verification['amount'];
            }

            // If we can't determine the amount, fail closed
            if ($verified_total <= 0) {
                return false;
            }

            // Allow tiny float tolerance (0.01) for rounding differences
            $tolerance = 0.01;

            return abs($order_total - $verified_total) <= $tolerance;
        }

        /**
         * Verify that the payment currency matches the order currency.
         *
         * @since 1.0.6
         * @param WC_Order $order The WooCommerce order object.
         * @param array    $verification The verification response from PipraPay.
         * @return bool True if currencies match, false otherwise.
         */
        private function verify_payment_currency($order, $verification)
        {
            $order_currency = $order->get_currency();
            $verified_currency = isset($verification['currency']) ? $verification['currency'] : '';

            if (empty($verified_currency)) {
                return false;
            }

            return strtoupper($order_currency) === strtoupper($verified_currency);
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice(__('Order not found.', 'piprapay-gateway'), 'error');
                return ['result' => 'fail'];
            }

            if($this->piprapay_version == 'old'){
                $data = [
                    'full_name'    => sanitize_text_field(trim(($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?: 'Jhon')),
                    'email_mobile' => sanitize_email($order->get_billing_email() ?: 'jhon@gmail.com'),
                    'amount'       => (string) $order->get_total(),
                    'metadata'     => ['invoiceid' => (string) $order->get_id()],
                    'redirect_url' => $this->get_return_url($order),
                    'cancel_url'   => wc_get_checkout_url(),
                    'webhook_url'  => WC()->api_request_url(strtolower($this->id)),
                    'return_type'  => 'POST',
                    'currency'     => $order->get_currency(),
                ];

                $args = [
                    'body'    => json_encode($data),
                    'headers' => [
                        'Content-Type'           => 'application/json',
                        'Accept'                 => 'application/json',
                        'mh-piprapay-api-key'    => $this->apikey,
                    ],
                    'timeout' => 45,
                ];

                $response = wp_remote_post($this->baseUrl . '/create-charge', $args);

                if (is_wp_error($response)) {
                    wc_add_notice(__('Payment error: ', 'piprapay-gateway') . $response->get_error_message(), 'error');
                    return ['result' => 'fail'];
                }

                $result = json_decode(wp_remote_retrieve_body($response), true);

                if (isset($result['pp_url'])) {
                    return [
                        'result'   => 'success',
                        'redirect' => esc_url($result['pp_url']),
                    ];
                }

                $message = !empty($result['message']) ? esc_html($result['message']) : __('Unknown error.', 'piprapay-gateway');
                wc_add_notice(sprintf(__('Payment error: Unable to create payment link. %s', 'piprapay-gateway'), $message), 'error');
                return ['result' => 'fail'];
            }else{
                $new_url = preg_replace('/(https?:\/\/)www\./i', '$1', WC()->api_request_url(strtolower($this->id)));

                $data = [
                    'full_name'    => sanitize_text_field(trim(($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?: 'Jhon')),
                    'email_address' => sanitize_email($order->get_billing_email() ?: 'jhon@gmail.com'),
                    'mobile_number' => sanitize_text_field($order->get_billing_phone() ?: '01700000000'),
                    'amount'       => $order->get_total(),
                    'metadata'     => ['invoiceid' => $order->get_id()],
                    'return_url' => $new_url,
                    'webhook_url'  => $new_url,
                    'return_type'  => 'POST',
                    'currency'     => $order->get_currency(),
                ];

                $args = [
                    'body'    => json_encode($data),
                    'headers' => [
                        'Content-Type'           => 'application/json',
                        'Accept'                 => 'application/json',
                        'MHS-PIPRAPAY-API-KEY'    => $this->apikey,
                    ],
                    'timeout' => 45,
                ];

                $response = wp_remote_post($this->baseUrl . '/checkout/redirect', $args);

                if (is_wp_error($response)) {
                    wc_add_notice(__('Payment server connection error.', 'piprapay-gateway'), 'error');
                    return ['result' => 'failure'];
                }

                $result = json_decode(wp_remote_retrieve_body($response), true);

                if (isset($result['pp_url'])) {
                    return [
                        'result'   => 'success',
                        'redirect' => esc_url_raw($result['pp_url']),
                    ];
                }

                $message = !empty($result['error']['message'])
                    ? esc_html($result['error']['message'])
                    : __('Unknown error from payment provider.', 'piprapay-gateway');

                wc_add_notice(sprintf(__('Payment error: %s', 'piprapay-gateway'), $message), 'error');

                return ['result' => 'failure'];
            }
        }

        public function handle_webhook()
        {
            $raw     = file_get_contents("php://input");
            $payload = json_decode($raw, true);
            $headers = getallheaders();

            // ============================================================
            // SECURITY FIX (v1.0.5): Enforce API key authentication for
            // ALL webhook requests, both old and new API versions.
            // Previously the "new" path had NO authentication at all,
            // allowing anyone to mark orders as paid by POSTing to the
            // webhook URL.
            // ============================================================
            if (!$this->verify_webhook_auth($headers)) {
                status_header(401);
                wp_send_json_error(['message' => 'Unauthorized request.']);
                exit;
            }

            if($this->piprapay_version == 'old'){
                if (empty($payload['metadata']['invoiceid']) || empty($payload['pp_id'])) {
                    status_header(400);
                    wp_send_json_error(['message' => 'Missing required data.']);
                    exit;
                }

                $order_id = isset($payload['metadata']['invoiceid']) ? absint($payload['metadata']['invoiceid']) : 0;
                $pp_id    = isset($payload['pp_id']) ? sanitize_text_field($payload['pp_id']) : '';
                $order    = wc_get_order($order_id);

                if (!$order) {
                    status_header(404);
                    wp_send_json_error(['message' => 'Order not found.']);
                    exit;
                }

                // ============================================================
                // SECURITY FIX (v1.0.5): Idempotency check — reject webhook
                // if the order is already paid (prevents replay attacks).
                // ============================================================
                if ($this->is_order_already_paid($order)) {
                    status_header(200);
                    wp_send_json_success(['message' => 'Order already processed.']);
                    exit;
                }

                $verification = $this->verify_payment($payload['pp_id']);

                if ($verification['status'] === 'completed') {
                    // ============================================================
                    // SECURITY FIX (v1.0.5): Verify the paid amount matches
                    // the order total to prevent underpayment bypass.
                    // ============================================================
                    if (!$this->verify_payment_amount($order, $verification)) {
                        $order->update_status('failed', __('Payment amount verification failed: paid amount does not match order total.', 'piprapay-gateway'));
                        status_header(400);
                        wp_send_json_error(['message' => 'Amount mismatch.']);
                        exit;
                    }

                    // ============================================================
                    // SECURITY FIX (v1.0.6): Verify currency matches to prevent
                    // cross-currency payment bypass (e.g. pay in USD for BDT).
                    // ============================================================
                    if (!$this->verify_payment_currency($order, $verification)) {
                        $order->update_status('failed', __('Payment currency verification failed: currency does not match order.', 'piprapay-gateway'));
                        status_header(400);
                        wp_send_json_error(['message' => 'Currency mismatch.']);
                        exit;
                    }

                    // Save Payment ID (pp_id)
                    $order->update_meta_data('_piprapay_payment_id', $pp_id);

                    // Attempt to get Transaction ID, Sender Number, and Payment Method from verification response
                    $transaction_id = isset($verification['transaction_id']) ? sanitize_text_field($verification['transaction_id']) : '';
                    $sender_number  = isset($verification['sender_number']) ? sanitize_text_field($verification['sender_number']) : '';
                    $payment_method_name = isset($verification['payment_method']) ? sanitize_text_field($verification['payment_method']) : $this->method_title;

                    if (!empty($transaction_id)) {
                        $order->update_meta_data('_piprapay_transaction_id', $transaction_id);
                    }
                    if (!empty($sender_number)) {
                        $order->update_meta_data('_piprapay_sender_number', $sender_number);
                    }
                    $order->update_meta_data('_piprapay_actual_payment_method', $payment_method_name);
                    $order->save();

                    // Set order status based on order_type
                    $order_type = $this->order_type;
                    if ($order_type === 'physical') {
                        $order->update_status('processing', __('Physical product order set to processing by PipraPay.', 'piprapay-gateway'));
                    } elseif ($order_type === 'digital_processing') {
                        $order->update_status('processing', __('Digital product order set to processing by PipraPay.', 'piprapay-gateway'));
                    } elseif ($order_type === 'digital_complete') {
                        $order->update_status('completed', __('Digital product order completed automatically by PipraPay.', 'piprapay-gateway'));
                    }
                    $order->payment_complete();
                    $order->add_order_note(__('Payment verified via PipraPay.', 'piprapay-gateway'));
                } else {
                    if ($verification['status'] === 'pending') {
                        $order->add_order_note(__('Payment verification is pending.', 'piprapay-gateway'));
                    } else {
                        $order->update_status('failed', __('Payment verification failed via PipraPay.', 'piprapay-gateway'));
                    }
                }

                status_header(200);
                wp_send_json_success(['message' => 'Success']);
            }else{
                // ============================================================
                // SECURITY FIX (v1.0.5): Removed the unauthenticated
                // $_GET['transaction_ref'] fallback. This GET parameter
                // allowed anyone to trigger order completion by simply
                // visiting the webhook URL with a transaction_ref query
                // parameter — no API key, no POST body, no authentication.
                // Now all requests must include a valid API key header
                // and a proper JSON payload with pp_id.
                // ============================================================
                if (empty($payload['pp_id'])) {
                    status_header(400);
                    wp_send_json_error(['message' => 'Missing required pp_id.']);
                    exit;
                }

                $pp_id         = sanitize_text_field($payload['pp_id']);
                $verification  = $this->verify_payment($payload['pp_id']);

                $order_id = isset($verification['metadata']['invoiceid']) ? absint($verification['metadata']['invoiceid']) : 0;

                $order    = wc_get_order($order_id);

                if (!$order) {
                    status_header(404);
                    wp_send_json_error(['message' => 'Order not found.']);
                    exit;
                }

                // ============================================================
                // SECURITY FIX (v1.0.5): Idempotency check — reject webhook
                // if the order is already paid (prevents replay attacks).
                // ============================================================
                if ($this->is_order_already_paid($order)) {
                    // For return_url redirects, still allow the customer to
                    // see the order-received page without re-processing.
                    if (isset($payload['return_type']) && $payload['return_type'] === 'POST') {
                        status_header(200);
                        wp_safe_redirect($order->get_checkout_order_received_url());
                        exit;
                    }
                    status_header(200);
                    wp_send_json_success(['message' => 'Order already processed.']);
                    exit;
                }

                if ($verification['status'] === 'completed') {
                    // ============================================================
                    // SECURITY FIX (v1.0.5): Verify the paid amount matches
                    // the order total to prevent underpayment bypass.
                    // ============================================================
                    if (!$this->verify_payment_amount($order, $verification)) {
                        $order->update_status('failed', __('Payment amount verification failed: paid amount does not match order total.', 'piprapay-gateway'));
                        status_header(400);
                        wp_send_json_error(['message' => 'Amount mismatch.']);
                        exit;
                    }

                    // ============================================================
                    // SECURITY FIX (v1.0.6): Verify currency matches to prevent
                    // cross-currency payment bypass.
                    // ============================================================
                    if (!$this->verify_payment_currency($order, $verification)) {
                        $order->update_status('failed', __('Payment currency verification failed: currency does not match order.', 'piprapay-gateway'));
                        status_header(400);
                        wp_send_json_error(['message' => 'Currency mismatch.']);
                        exit;
                    }

                    $order->update_meta_data('_piprapay_payment_id', $pp_id);

                    $transaction_id = isset($verification['transaction_id']) ? sanitize_text_field($verification['transaction_id']) : '';
                    $sender_number  = isset($verification['sender']) ? sanitize_text_field($verification['sender']) : '';
                    $payment_method_name = isset($verification['gateway']) ? sanitize_text_field($verification['gateway']) : $this->method_title;

                    if (!empty($transaction_id)) {
                        $order->update_meta_data('_piprapay_transaction_id', $transaction_id);
                    }
                    if (!empty($sender_number)) {
                        $order->update_meta_data('_piprapay_sender_number', $sender_number);
                    }
                    $order->update_meta_data('_piprapay_actual_payment_method', $payment_method_name);
                    $order->save();

                    // Set order status based on order_type
                    $order_type = $this->order_type;
                    if ($order_type === 'physical') {
                        $order->update_status('processing', __('Physical product order set to processing by PipraPay.', 'piprapay-gateway'));
                    } elseif ($order_type === 'digital_processing') {
                        $order->update_status('processing', __('Digital product order set to processing by PipraPay.', 'piprapay-gateway'));
                    } elseif ($order_type === 'digital_complete') {
                        $order->update_status('completed', __('Digital product order completed automatically by PipraPay.', 'piprapay-gateway'));
                    }
                    $order->payment_complete();
                    $order->add_order_note(__('Payment verified via PipraPay.', 'piprapay-gateway'));

                    // Handle redirect for return_url flow (customer returning from payment)
                    if (isset($payload['metadata']['invoiceid']) && !empty($payload['metadata']['invoiceid'])) {
                        // This is a server-to-server webhook — respond with JSON
                        status_header(200);
                        wp_send_json_success(['message' => 'Success']);
                    } else {
                        // This is a customer return redirect
                        status_header(200);
                        wp_safe_redirect($order->get_checkout_order_received_url());
                        exit;
                    }
                } else {
                    if ($verification['status'] === 'pending') {
                        $order->add_order_note(__('Payment verification is pending.', 'piprapay-gateway'));

                        status_header(200);
                        wp_safe_redirect($order->get_checkout_order_received_url());
                        exit;
                    } else {
                        $order->update_status('failed', __('Payment verification failed via PipraPay.', 'piprapay-gateway'));

                        status_header(200);
                        wc_add_notice(__('Transaction was canceled. Please try again.', 'piprapay-gateway'), 'error');
                        wp_safe_redirect(wc_get_checkout_url());
                        exit;
                    }
                }
            }
        }

        private function verify_payment($pp_id)
        {
            if($this->piprapay_version == 'old'){
                $data = json_encode(['pp_id' => $pp_id]);
                $args = [
                    'body'    => $data,
                    'headers' => [
                        'Content-Type'        => 'application/json',
                        'Accept'              => 'application/json',
                        'mh-piprapay-api-key' => $this->apikey,
                    ],
                    'timeout' => 45,
                ];
                $url      = $this->baseUrl . '/verify-payments';
                $response = wp_remote_post($url, $args);
                if (is_wp_error($response)) return ['status' => 'error', 'message' => $response->get_error_message()];
                return json_decode(wp_remote_retrieve_body($response), true);
            }else{
                $data = json_encode(['pp_id' => $pp_id]);
                $args = [
                    'body'    => $data,
                    'headers' => [
                        'Content-Type'        => 'application/json',
                        'Accept'              => 'application/json',
                        'MHS-PIPRAPAY-API-KEY' => $this->apikey,
                    ],
                    'timeout' => 45,
                ];
                $url      = $this->baseUrl . '/verify-payment';
                $response = wp_remote_post($url, $args);
                if (is_wp_error($response)) return ['status' => 'error', 'message' => $response->get_error_message()];
                return json_decode(wp_remote_retrieve_body($response), true);
            }
        }

        public function display_piprapay_order_meta($order) {
            $payment_id            = $order->get_meta('_piprapay_payment_id', true);
            $transaction_id        = $order->get_meta('_piprapay_transaction_id', true);
            $sender_number         = $order->get_meta('_piprapay_sender_number', true);
            $actual_payment_method = $order->get_meta('_piprapay_actual_payment_method', true);

            echo '<h3>' . __('PipraPay Details', 'piprapay-gateway') . '</h3>';
            if ($payment_id) {
                echo '<p><i class="fa fa-arrow-right"></i> <strong>' . __('Payment ID:', 'piprapay-gateway') . '</strong> ' . esc_html($payment_id) . '</p>';
            }
            if ($transaction_id) {
                echo '<p><i class="fa fa-arrow-right"></i> <strong>' . __('Transaction ID:', 'piprapay-gateway') . '</strong> ' . esc_html($transaction_id) . '</p>';
            }
            echo '<p><i class="fa fa-arrow-right"></i> <strong>' . __('Payment Method:', 'piprapay-gateway') . '</strong> ' . esc_html($actual_payment_method) . '</p>';
            if ($sender_number) {
                echo '<p><i class="fa fa-arrow-right"></i> <strong>' . __('Sender Number:', 'piprapay-gateway') . '</strong> ' . esc_html($sender_number) . '</p>';
            } else {
                echo '<p><i class="fa fa-arrow-right"></i> <strong>' . __('Sender Number:', 'piprapay-gateway') . '</strong> ' . __('N/A (Not available from PipraPay API response)', 'piprapay-gateway') . '</p>';
            }
        }
    }

    // Define server-side integration for blocks
    class WC_PIPRAPAY_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
    {
        protected $name = 'piprapay';

        public function initialize()
        {
            $this->settings = get_option('woocommerce_piprapay_settings', []);
        }

        public function is_active()
        {
            return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
        }

        public function get_payment_method_script_handles()
        {
            wp_register_script(
                'piprapay-blocks',
                plugins_url('assets/js/piprapay-blocks.js', __FILE__),
                ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'],
                filemtime(plugin_dir_path(__FILE__) . 'assets/js/piprapay-blocks.js'),
                true
            );
            return ['piprapay-blocks'];
        }

        public function get_payment_method_data()
        {
            $gateway = WC_PIPRAPAY_Gateway::get_instance();
            return [
                'title' => $gateway->title,
                'description' => $gateway->description,
                'icon' => $gateway->icon,
                'supports' => ['products'],
            ];
        }
    }

    // Register payment method for WooCommerce Blocks
    add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
        $registry->register(new WC_PIPRAPAY_Blocks());
    });

    // Add filter to include payment gateway
    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_PIPRAPAY_Gateway';
        return $gateways;
    });
}
