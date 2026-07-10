<?php
/*
Plugin Name: WooCommerce MoneyEU Payments (S2S / HPP)
Description: Integrates WooCommerce with the MoneyEU unified processPayment API, supporting both the Server-to-Server (inline card form) and Hosted Payment Page (redirect) flows through the same core endpoint.
Version: 1.0.1
Author: MoneyEU
Requires PHP: 7.4
Requires at least: 5.8
*/

if (! defined('ABSPATH')) {
    exit;
}

define('MONEYEU2_OPTION_KEY', 'woocommerce_moneyeu_payments_settings');

/**
 * Auto-updater. Points the bundled plugin-update-checker library at a GitHub
 * repo (set via the gateway's "GitHub Repo URL" setting) instead of a custom
 * backend endpoint — the library reads releases/tags directly from GitHub, so
 * no server-side update-info API is needed. Disabled until that setting is
 * filled in, so this is inert out of the box.
 */
if (file_exists(__DIR__ . '/plugin-update-checker/plugin-update-checker.php')) {
    require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
    moneyeu2_init_update_checker();
}
function moneyeu2_init_update_checker() {
    $settings = get_option(MONEYEU2_OPTION_KEY, array());
    $repo_url = isset($settings['github_repo_url']) ? trim((string) $settings['github_repo_url']) : '';
    if ($repo_url === '') return;

    $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        $repo_url,
        __FILE__,
        'woocommerce-moneyeu-payments'
    );

    // Prefer an uploaded release .zip over GitHub's auto-generated source
    // archive — the auto archive's top-level folder name won't match the
    // plugin slug, which breaks WordPress's updater. Guarded with
    // method_exists() throughout: if the configured URL isn't actually a
    // GitHub URL, buildUpdateChecker() returns a plain (non-VCS) checker that
    // has no getVcsApi() method at all, and calling it directly would fatal.
    if (method_exists($update_checker, 'getVcsApi')) {
        $vcs_api = $update_checker->getVcsApi();
        if ($vcs_api && method_exists($vcs_api, 'enableReleaseAssets')) {
            $vcs_api->enableReleaseAssets();
        }
    }

    $token = isset($settings['github_access_token']) ? trim((string) $settings['github_access_token']) : '';
    if ($token !== '' && method_exists($update_checker, 'setAuthentication')) {
        $update_checker->setAuthentication($token);
    }
}

function moneyeu2_settings() {
    return get_option(MONEYEU2_OPTION_KEY, array());
}

function moneyeu2_setting($key, $default = '') {
    $settings = moneyeu2_settings();
    return isset($settings[$key]) && $settings[$key] !== '' ? $settings[$key] : $default;
}

function moneyeu2_api_base_url() {
    return rtrim((string) moneyeu2_setting('api_base_url'), '/');
}

/**
 * Maps this API's webhook/status status strings (Transaction Successful /
 * Transaction Declined / Transaction Pending, plus raw acquirer response
 * codes like "0: Approved") into a fixed set: success / failed / pending / unknown.
 */
function moneyeu2_normalize_status($raw) {
    $s = strtolower((string) $raw);
    if ($s === '') return 'unknown';
    if (strpos($s, 'refund') !== false || strpos($s, 'chargeback') !== false) return 'failed';
    if (strpos($s, 'success') !== false || strpos($s, 'complete') !== false || strpos($s, 'approved') !== false) return 'success';
    if (strpos($s, 'decline') !== false || strpos($s, 'denied') !== false
        || strpos($s, 'fail') !== false || strpos($s, 'error') !== false
        || strpos($s, 'reject') !== false || strpos($s, 'timeout') !== false
        || strpos($s, 'expired') !== false) return 'failed';
    if (strpos($s, 'pend') !== false || strpos($s, 'process') !== false) return 'pending';
    return 'unknown';
}

/**
 * The CRM resolves country by full English name (falling back to currency),
 * not ISO code, so the plugin sends WooCommerce's country name rather than
 * a dial code or alpha-2 code.
 */
function moneyeu2_country_name($iso_code) {
    if (!function_exists('WC') || !WC()->countries) return (string) $iso_code;
    $countries = WC()->countries->countries;
    $name = isset($countries[$iso_code]) ? (string) $countries[$iso_code] : (string) $iso_code;
    return trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $name));
}

/**
 * message = serviceName + salt + apiKey + timestamp + rawRequestBody
 * signature = base64( HMAC-SHA256(merchantSecret, message) )
 * $raw_body must be the exact string sent as the POST body — signing a
 * re-encoded copy would produce a signature the backend can't verify.
 */
function moneyeu2_sign_request($service_name, $api_key, $secret, $raw_body) {
    $salt      = substr(bin2hex(random_bytes(16)), 0, 32);
    $timestamp = (string) time();
    $message   = $service_name . $salt . $api_key . $timestamp . $raw_body;
    $signature = base64_encode(hash_hmac('sha256', $message, $secret, true));
    return array('salt' => $salt, 'timestamp' => $timestamp, 'signature' => $signature);
}

/**
 * Lookup order by the stored MoneyEU transaction ID. This API has no
 * merchant order-reference field, so transaction_id is the only correlator
 * available to both the webhook and the return redirect.
 */
function moneyeu2_get_order_id_by_transaction_id($transaction_id) {
    global $wpdb;
    if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
        && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_moneyeu2_transaction_id' AND meta_value = %s",
            $transaction_id
        ));
    } else {
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_moneyeu2_transaction_id' AND meta_value = %s",
            $transaction_id
        ));
    }
    return $order_id ? intval($order_id) : null;
}

/**
 * Idempotent status transition. Shared by the webhook handler, the return
 * redirect, and the poller so none of them can double-process an order.
 */
function moneyeu2_apply_status($order, $raw_status, $message, $reference) {
    $norm    = moneyeu2_normalize_status($raw_status);
    $current = $order->get_status();

    if ($norm === 'success') {
        if (in_array($current, array('processing', 'completed'), true)) return;
        $order->payment_complete($reference !== '' ? $reference : (string) $order->get_meta('_moneyeu2_transaction_id'));
        $note = __('MoneyEU: payment confirmed.', 'woocommerce');
        if ($message !== '') $note .= ' ' . $message;
        $order->add_order_note($note);
        return;
    }

    if ($norm === 'failed') {
        if ($current === 'failed') return;
        $reason = $message !== '' ? $message : ($raw_status !== '' ? $raw_status : 'Payment failed');
        $order->update_status('failed', __('MoneyEU: ', 'woocommerce') . $reason);
        wc_increase_stock_levels($order->get_id());
        return;
    }

    $note = __('MoneyEU status update: ', 'woocommerce') . $raw_status;
    if ($message !== '') $note .= ' — ' . $message;
    $order->add_order_note($note);
}

/**
 * GET /v1/payments/redirect/status/{transactionId} — public, no auth headers
 * required (the transaction id itself is the opaque bearer of access).
 */
function moneyeu2_fetch_status($transaction_id) {
    $base_url = moneyeu2_api_base_url();
    if ($base_url === '' || $transaction_id === '') return null;

    $endpoint = $base_url . '/v1/payments/redirect/status/' . rawurlencode($transaction_id);
    $response = wp_remote_get($endpoint, array('timeout' => 20));

    if (is_wp_error($response)) {
        error_log('MoneyEU2 status fetch error: ' . $response->get_error_message());
        return null;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body      = json_decode(wp_remote_retrieve_body($response), true);
    if ($http_code !== 200 || !is_array($body)) return null;
    return $body;
}

/**
 * Resolve an on-hold order's real status by polling the status endpoint.
 * Returns 'processing' / 'failed' / null (still pending or unresolvable).
 */
function moneyeu2_poll_status($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return null;
    if (!$order->has_status('on-hold')) return $order->get_status();

    $transaction_id = (string) $order->get_meta('_moneyeu2_transaction_id');
    if ($transaction_id === '') {
        error_log('MoneyEU2 poll: no _moneyeu2_transaction_id for order ' . $order_id);
        return null;
    }

    $body = moneyeu2_fetch_status($transaction_id);
    if ($body === null) return null;

    $raw_status = (string) ($body['status'] ?? '');
    $message    = (string) ($body['responseMessage'] ?? $body['message'] ?? '');
    if ($raw_status === '') return null;

    moneyeu2_apply_status($order, $raw_status, $message, $transaction_id);

    $final = $order->get_status();
    if (in_array($final, array('processing', 'completed'), true)) return 'processing';
    if ($final === 'failed') return 'failed';
    return null;
}

// ---- Webhook endpoint ----

add_action('rest_api_init', 'register_moneyeu2_routes');
function register_moneyeu2_routes() {
    register_rest_route('moneyeu2/v1', '/callback', array(
        'methods'  => 'POST',
        'callback' => 'handle_moneyeu2_callback',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('moneyeu2/v1', '/return', array(
        'methods'  => array('GET', 'POST'),
        'callback' => 'handle_moneyeu2_return',
        'permission_callback' => '__return_true',
    ));
}

/**
 * Payload: { transaction_id, status, response_message, paid_amount, currency }.
 * No signature and no merchant order-reference on this webhook — defense in
 * depth comes only from moneyeu2_apply_status() being idempotent.
 */
function handle_moneyeu2_callback($request) {
    $raw = $request->get_body();
    error_log('MoneyEU2 webhook payload: ' . $raw);

    $input = $request->get_json_params();
    if (empty($input)) $input = $_POST;

    $transaction_id = sanitize_text_field((string) ($input['transaction_id'] ?? ''));
    $raw_status     = sanitize_text_field((string) ($input['status'] ?? ''));
    $message        = sanitize_text_field((string) ($input['response_message'] ?? ''));

    if ($transaction_id === '') {
        error_log('MoneyEU2 webhook: missing transaction_id');
        return new WP_REST_Response(array('ok' => true), 200);
    }

    $order_id = moneyeu2_get_order_id_by_transaction_id($transaction_id);
    $order    = $order_id ? wc_get_order($order_id) : null;
    if (!$order) {
        error_log('MoneyEU2 webhook: no order found for transaction_id=' . $transaction_id);
        return new WP_REST_Response(array('ok' => true), 200);
    }

    moneyeu2_apply_status($order, $raw_status, $message, $transaction_id);
    return new WP_REST_Response(array('ok' => true), 200);
}

/**
 * Customer's browser lands here after the HPP hosted page or an S2S 3DS
 * challenge completes — this is the redirectUrl sent in the processPayment
 * request body, so it applies to both flows.
 */
function handle_moneyeu2_return($request) {
    $order_id  = absint($request->get_param('order_id'));
    $order_key = sanitize_text_field((string) $request->get_param('order_key'));

    if (!$order_id || !$order_key) {
        wp_redirect(wc_get_checkout_url());
        exit;
    }

    clean_post_cache($order_id);
    $order = wc_get_order($order_id);
    if (!$order || !$order->key_is_valid($order_key)) {
        wp_redirect(wc_get_checkout_url());
        exit;
    }

    if ($order->has_status('on-hold')) {
        moneyeu2_poll_status($order_id);
        $order = wc_get_order($order_id);
    }

    $status = $order->get_status();
    if (in_array($status, array('processing', 'completed'), true)) {
        wp_redirect($order->get_checkout_order_received_url());
    } elseif ($status === 'failed') {
        wp_redirect(add_query_arg(array('moneyeu2_status' => 'failed'), wc_get_checkout_url()));
    } else {
        wp_redirect(add_query_arg(
            array('moneyeu2_status' => 'pending', 'key' => $order->get_order_key()),
            $order->get_view_order_url()
        ));
    }
    exit;
}

// ---- WP-Cron tapered polling ----

add_filter('cron_schedules', 'moneyeu2_add_cron_schedules');
function moneyeu2_add_cron_schedules($schedules) {
    $schedules['moneyeu2_30s'] = array('interval' => 30, 'display' => __('Every 30 Seconds (MoneyEU2)', 'woocommerce'));
    return $schedules;
}

add_action('woocommerce_order_status_on-hold', 'moneyeu2_schedule_status_checks', 10, 1);
function moneyeu2_schedule_status_checks($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || $order->get_payment_method() !== 'moneyeu_payments') return;

    $delays = array(30, 120, 300, 900, 1800);
    $now    = time();
    foreach ($delays as $delay) {
        wp_schedule_single_event($now + $delay, 'moneyeu2_cron_check_order_status', array($order_id));
    }
}

add_action('moneyeu2_cron_check_order_status', 'moneyeu2_poll_status', 10, 1);

// ---- AJAX browser polling ----

add_action('wp_ajax_moneyeu2_check_order_status',        'moneyeu2_ajax_check_order_status');
add_action('wp_ajax_nopriv_moneyeu2_check_order_status', 'moneyeu2_ajax_check_order_status');
function moneyeu2_ajax_check_order_status() {
    $order_id  = absint($_GET['order_id'] ?? 0);
    $order_key = sanitize_text_field($_GET['order_key'] ?? '');

    if (!$order_id || !$order_key) {
        wp_send_json_error(array('message' => 'Invalid request'), 400);
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order || !$order->key_is_valid($order_key)) {
        wp_send_json_error(array('message' => 'Order not found'), 404);
        return;
    }

    if (!$order->has_status('on-hold')) {
        wp_send_json_success(array('wc_status' => $order->get_status(), 'resolved' => true));
        return;
    }

    $result = moneyeu2_poll_status($order_id);
    $order  = wc_get_order($order_id);
    wp_send_json_success(array('wc_status' => $order->get_status(), 'resolved' => $result !== null));
}

// ---- Query-param notice rendering ----

add_action('woocommerce_before_checkout_form', 'moneyeu2_render_checkout_query_notice', 5);
function moneyeu2_render_checkout_query_notice() {
    if (sanitize_text_field($_GET['moneyeu2_status'] ?? '') === 'failed') {
        wc_print_notice(__('Payment was declined. Please try again.', 'woocommerce'), 'error');
    }
}

add_action('woocommerce_view_order', 'moneyeu2_render_view_order_query_notice', 1);
function moneyeu2_render_view_order_query_notice($order_id) {
    if (sanitize_text_field($_GET['moneyeu2_status'] ?? '') !== 'pending') return;
    $order = wc_get_order($order_id);
    if (!$order) return;

    if ($order->has_status('failed')) {
        wc_print_notice(__('Payment was declined. Please try again or contact support.', 'woocommerce'), 'error');
    } elseif (!$order->has_status(array('processing', 'completed'))) {
        wc_print_notice(__('Your payment is still being verified. Please wait for confirmation before considering this order paid.', 'woocommerce'), 'notice');
    }
}

add_action('wp_enqueue_scripts', 'moneyeu2_enqueue_checkout_assets');
function moneyeu2_enqueue_checkout_assets() {
    if ((!function_exists('is_checkout') || !is_checkout()) && !is_wc_endpoint_url('order-pay')) return;
    wp_enqueue_style('moneyeu2-checkout', plugin_dir_url(__FILE__) . 'assets/css/moneyeu-checkout.css', array(), '1.0.0');
}

add_action('wp_enqueue_scripts', 'moneyeu2_enqueue_polling_script');
function moneyeu2_enqueue_polling_script() {
    if (!is_wc_endpoint_url('view-order')) return;
    if (empty($_GET['moneyeu2_status']) || $_GET['moneyeu2_status'] !== 'pending') return;

    global $wp;
    $order_id = absint($wp->query_vars['view-order'] ?? 0);
    if (!$order_id) return;

    $order = wc_get_order($order_id);
    if (!$order || $order->has_status(array('processing', 'completed', 'failed'))) return;

    $order_key = sanitize_text_field($_GET['key'] ?? '') ?: $order->get_order_key();

    wp_enqueue_script(
        'moneyeu2-polling',
        plugin_dir_url(__FILE__) . 'assets/js/moneyeu-polling.js',
        array('jquery'),
        '1.0.0',
        true
    );
    wp_localize_script('moneyeu2-polling', 'moneyeu2Polling', array(
        'ajaxUrl'          => admin_url('admin-ajax.php'),
        'orderId'          => $order_id,
        'orderKey'         => $order_key,
        'orderReceivedUrl' => $order->get_checkout_order_received_url(),
        'checkoutUrl'      => wc_get_checkout_url(),
        'i18n'             => array(
            'checking' => __('Checking payment status…', 'woocommerce'),
            'success'  => __('Payment confirmed! Redirecting…', 'woocommerce'),
            'failed'   => __('Payment was declined. Please try again.', 'woocommerce'),
            'timeout'  => __('Could not confirm payment status. Please check your orders or contact support.', 'woocommerce'),
        ),
    ));
}

// ---- Admin order screen ----

add_action('woocommerce_admin_order_data_after_billing_address', 'moneyeu2_display_admin_meta', 10, 1);
function moneyeu2_display_admin_meta($order) {
    $transaction_id = $order->get_meta('_moneyeu2_transaction_id');
    $flow_type      = $order->get_meta('_moneyeu2_flow_type');
    if ($transaction_id) {
        echo '<p><strong>' . esc_html__('MoneyEU Transaction ID', 'woocommerce') . ':</strong> ' . esc_html($transaction_id) . '</p>';
    }
    if ($flow_type) {
        echo '<p><strong>' . esc_html__('MoneyEU Flow', 'woocommerce') . ':</strong> ' . esc_html(strtoupper($flow_type)) . '</p>';
    }
}

// Capture block-checkout card data into order meta before process_payment runs.
add_action('woocommerce_rest_checkout_process_payment_with_context', 'moneyeu2_capture_blocks_payment_data', 10, 2);
function moneyeu2_capture_blocks_payment_data($context, $result) {
    if ($context->payment_method !== 'moneyeu_payments') return;

    $order = $context->order;
    $pd    = $context->payment_data;

    $order->update_meta_data('_moneyeu2_card_number', sanitize_text_field($pd['moneyeu2_card_number'] ?? ''));
    $order->update_meta_data('_moneyeu2_card_exp_month', sanitize_text_field($pd['moneyeu2_card_exp_month'] ?? ''));
    $order->update_meta_data('_moneyeu2_card_exp_year', sanitize_text_field($pd['moneyeu2_card_exp_year'] ?? ''));
    $order->update_meta_data('_moneyeu2_card_cvv', sanitize_text_field($pd['moneyeu2_card_cvv'] ?? ''));
    $order->save();
}

add_action('plugins_loaded', 'init_moneyeu2_payment_gateway');
function init_moneyeu2_payment_gateway() {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Gateway_MoneyEU_Payments extends WC_Payment_Gateway {
        public $instructions;

        public function __construct() {
            $this->id                 = 'moneyeu_payments';
            $this->has_fields         = true;
            $this->method_title       = 'MoneyEU Payments (S2S / HPP)';
            $this->method_description = 'Accept payments via the MoneyEU processPayment API, in either Server-to-Server (inline card form) or Hosted Payment Page (redirect) mode.';

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title', 'Credit Card (MoneyEU)');
            $this->description = $this->get_option('description');
            $this->instructions = $this->get_option('instructions', $this->description);

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
            add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Enable/Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable MoneyEU Payments',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'Credit Card (MoneyEU)',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Pay securely with your credit card.',
                ),
                'instructions' => array(
                    'title'       => 'Instructions',
                    'type'        => 'textarea',
                    'description' => 'Instructions that will be added to the thank you page and emails.',
                    'default'     => 'Thank you for your payment.',
                    'desc_tip'    => true,
                ),
                'flow_type' => array(
                    'title'       => 'Payment Flow',
                    'type'        => 'select',
                    'description' => 'Server-to-Server shows an inline card form on your site and sends card data with the request. Hosted Payment Page shows no card fields — the customer is redirected to a MoneyEU-hosted page to enter their card.',
                    'default'     => 's2s',
                    'options'     => array(
                        's2s' => 'Server-to-Server (inline card form)',
                        'hpp' => 'Hosted Payment Page (redirect)',
                    ),
                    'desc_tip'    => true,
                ),
                'api_base_url' => array(
                    'title'       => 'API Base URL',
                    'type'        => 'text',
                    'description' => 'e.g. https://api.moneyeu.com/moneyEu/api — no trailing slash, do not include /v1/processPayment.',
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'sandbox' => array(
                    'title'       => 'Sandbox mode',
                    'type'        => 'checkbox',
                    'label'       => 'Send isSandbox: true on every request',
                    'default'     => 'no',
                ),
                'api_key' => array(
                    'title'       => 'API Key',
                    'type'        => 'text',
                    'description' => 'Provided by MoneyEU.',
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'merchant_secret' => array(
                    'title'       => 'Merchant Secret',
                    'type'        => 'password',
                    'description' => 'Used to sign each request (HMAC-SHA256). Provided by MoneyEU.',
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'service_name' => array(
                    'title'       => 'Service Name',
                    'type'        => 'text',
                    'description' => 'Included in the request signature. Leave as default unless MoneyEU instructs otherwise.',
                    'default'     => 'moneyEuPayment',
                    'desc_tip'    => true,
                ),
                'merchant_terminal_id' => array(
                    'title'       => 'Merchant Terminal ID (advanced)',
                    'type'        => 'text',
                    'description' => 'Optional. Leave blank to let MoneyEU auto-resolve your most recent terminal for the selected flow.',
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'update_info' => array(
                    'title'       => 'Auto-updates',
                    'type'        => 'title',
                    'description' => 'Optional. Point this at a GitHub repo to get plugin updates in the normal WordPress Plugins screen, sourced from that repo\'s Releases. Leave the repo URL blank to disable.',
                ),
                'github_repo_url' => array(
                    'title'       => 'GitHub Repo URL',
                    'type'        => 'text',
                    'description' => 'e.g. https://github.com/your-org/woocommerce-moneyeu-payments — leave blank to disable auto-updates.',
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'github_access_token' => array(
                    'title'       => 'GitHub Access Token',
                    'type'        => 'password',
                    'description' => 'Only needed if the repo above is private. A GitHub personal access token with read access to the repo.',
                    'default'     => '',
                    'desc_tip'    => true,
                ),
            );
        }

        private function is_hpp() {
            return $this->get_option('flow_type', 's2s') === 'hpp';
        }

        public function payment_fields() {
            if ($this->is_hpp()) {
                ?>
                <div id="moneyeu2-card-fields" class="moneyeu2-card-fields moneyeu2-card-fields--redirect">
                    <?php if ($this->description): ?>
                        <div class="moneyeu2-card-fields__description"><?php echo wp_kses_post(wpautop($this->description)); ?></div>
                    <?php endif; ?>
                    <p><?php esc_html_e('You\'ll be redirected to a secure MoneyEU page to enter your payment details and complete your purchase.', 'woocommerce'); ?></p>
                </div>
                <?php
                return;
            }
            ?>
            <div id="moneyeu2-card-fields" class="moneyeu2-card-fields">
                <?php if ($this->description): ?>
                    <div class="moneyeu2-card-fields__description"><?php echo wp_kses_post(wpautop($this->description)); ?></div>
                <?php endif; ?>
                <div class="moneyeu2-card-fields__body">
                    <div class="moneyeu2-card-field">
                        <label for="moneyeu2-card-number">
                            <span><?php esc_html_e('Card Number', 'woocommerce'); ?></span>
                            <span class="moneyeu2-card-field__required"><?php esc_html_e('Required', 'woocommerce'); ?></span>
                        </label>
                        <input class="moneyeu2-input" id="moneyeu2-card-number" name="moneyeu2_card_number" type="text" inputmode="numeric" autocomplete="cc-number" placeholder="1234 5678 9012 3456" maxlength="19" />
                    </div>
                    <div class="moneyeu2-card-fields__grid">
                        <div class="moneyeu2-card-field">
                            <label for="moneyeu2-card-expiry-month">
                                <span><?php esc_html_e('Expiry Month', 'woocommerce'); ?></span>
                                <span class="moneyeu2-card-field__required"><?php esc_html_e('Required', 'woocommerce'); ?></span>
                            </label>
                            <select class="moneyeu2-input" id="moneyeu2-card-expiry-month" name="moneyeu2_card_exp_month" autocomplete="cc-exp-month">
                                <option value="">MM</option>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo sprintf('%02d', $m); ?>"><?php echo sprintf('%02d', $m); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="moneyeu2-card-field">
                            <label for="moneyeu2-card-expiry-year">
                                <span><?php esc_html_e('Expiry Year', 'woocommerce'); ?></span>
                                <span class="moneyeu2-card-field__required"><?php esc_html_e('Required', 'woocommerce'); ?></span>
                            </label>
                            <select class="moneyeu2-input" id="moneyeu2-card-expiry-year" name="moneyeu2_card_exp_year" autocomplete="cc-exp-year">
                                <option value="">YYYY</option>
                                <?php
                                $current_year = (int) date('Y');
                                for ($y = $current_year; $y <= $current_year + 15; $y++): ?>
                                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="moneyeu2-card-field moneyeu2-card-field--cvv">
                            <label for="moneyeu2-card-cvv">
                                <span><?php esc_html_e('CVV', 'woocommerce'); ?></span>
                                <span class="moneyeu2-card-field__required"><?php esc_html_e('Required', 'woocommerce'); ?></span>
                            </label>
                            <input class="moneyeu2-input" id="moneyeu2-card-cvv" name="moneyeu2_card_cvv" type="text" inputmode="numeric" autocomplete="cc-csc" placeholder="123" maxlength="4" />
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }

        public function validate_fields() {
            if ($this->is_hpp()) return true;

            $card_number = preg_replace('/\s+/', '', sanitize_text_field($_POST['moneyeu2_card_number'] ?? ''));
            $exp_month   = sanitize_text_field($_POST['moneyeu2_card_exp_month'] ?? '');
            $exp_year    = sanitize_text_field($_POST['moneyeu2_card_exp_year'] ?? '');
            $cvv         = sanitize_text_field($_POST['moneyeu2_card_cvv'] ?? '');

            if (empty($card_number) || !preg_match('/^\d{12,19}$/', $card_number)) {
                wc_add_notice(__('Please enter a valid card number.', 'woocommerce'), 'error');
                return false;
            }
            if (empty($exp_month)) {
                wc_add_notice(__('Please select an expiry month.', 'woocommerce'), 'error');
                return false;
            }
            if (empty($exp_year)) {
                wc_add_notice(__('Please select an expiry year.', 'woocommerce'), 'error');
                return false;
            }
            if (empty($cvv) || !preg_match('/^\d{3,4}$/', $cvv)) {
                wc_add_notice(__('Please enter a valid CVV.', 'woocommerce'), 'error');
                return false;
            }
            return true;
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            $api_key = $this->get_option('api_key');
            $secret  = $this->get_option('merchant_secret');
            $base_url = rtrim((string) $this->get_option('api_base_url'), '/');

            if (empty($api_key) || empty($secret) || empty($base_url)) {
                wc_add_notice(__('Payment configuration error: gateway is not fully configured. Please contact the merchant.', 'woocommerce'), 'error');
                return array('result' => 'fail');
            }

            $is_hpp = $this->is_hpp();

            $card_number = $card_exp_month = $card_exp_year = $card_cvv = '';
            if (!$is_hpp) {
                $meta_card_number = $order->get_meta('_moneyeu2_card_number');
                if (!empty($meta_card_number)) {
                    $card_number    = $meta_card_number;
                    $card_exp_month = $order->get_meta('_moneyeu2_card_exp_month');
                    $card_exp_year  = $order->get_meta('_moneyeu2_card_exp_year');
                    $card_cvv       = $order->get_meta('_moneyeu2_card_cvv');
                    $order->delete_meta_data('_moneyeu2_card_number');
                    $order->delete_meta_data('_moneyeu2_card_exp_month');
                    $order->delete_meta_data('_moneyeu2_card_exp_year');
                    $order->delete_meta_data('_moneyeu2_card_cvv');
                    $order->save();
                } else {
                    $card_number    = preg_replace('/\s+/', '', sanitize_text_field($_POST['moneyeu2_card_number'] ?? ''));
                    $card_exp_month = sanitize_text_field($_POST['moneyeu2_card_exp_month'] ?? '');
                    $card_exp_year  = sanitize_text_field($_POST['moneyeu2_card_exp_year'] ?? '');
                    $card_cvv       = sanitize_text_field($_POST['moneyeu2_card_cvv'] ?? '');
                }
            }

            $return_url = add_query_arg(array(
                'order_id'  => $order_id,
                'order_key' => $order->get_order_key(),
            ), rest_url('moneyeu2/v1/return'));

            $data = array(
                'customerName'  => $order->get_formatted_billing_full_name(),
                'customerEmail' => $order->get_billing_email(),
                'zip'           => $order->get_billing_postcode(),
                'language'      => 'English',
                'service'       => 'Order #' . $order_id,
                'countryName'   => moneyeu2_country_name($order->get_billing_country()),
                'phone'         => $order->get_billing_phone(),
                'amount'        => (float) round($order->get_total(), 2),
                'currency'      => $order->get_currency(),
                'address'       => $order->get_billing_address_1(),
                'city'          => $order->get_billing_city(),
                'state'         => $order->get_billing_state(),
                'callbackUrl'   => rest_url('moneyeu2/v1/callback'),
                'redirectUrl'   => $return_url,
                'merchantName'  => get_bloginfo('name'),
            );

            if (!$is_hpp) {
                $data['cardholderName'] = $order->get_formatted_billing_full_name();
                $data['cardNumber']     = $card_number;
                $data['expiryDate']     = $card_exp_month !== '' && $card_exp_year !== ''
                    ? sprintf('%02d/%02d', (int) $card_exp_month, ((int) $card_exp_year) % 100)
                    : '';
                $data['cvv']            = $card_cvv;
            }

            $terminal_id = trim((string) $this->get_option('merchant_terminal_id'));
            if ($terminal_id !== '' && ctype_digit($terminal_id)) {
                $data['merchantTerminalId'] = (int) $terminal_id;
            }

            $raw_body = wp_json_encode($data);
            $service_name = $this->get_option('service_name', 'moneyEuPayment');
            $auth = moneyeu2_sign_request($service_name, $api_key, $secret, $raw_body);

            $response = wp_remote_post($base_url . '/v1/processPayment', array(
                'method'  => 'POST',
                'headers' => array(
                    'apiKey'       => $api_key,
                    'salt'         => $auth['salt'],
                    'signature'    => $auth['signature'],
                    'timestamp'    => $auth['timestamp'],
                    'X-Flow-Type'  => $is_hpp ? 'HPP' : 'S2S',
                    'isCheckout'   => 'false',
                    'isSandbox'    => $this->get_option('sandbox') === 'yes' ? 'true' : 'false',
                    'Content-Type' => 'application/json',
                ),
                'body'    => $raw_body,
                'timeout' => 60,
            ));

            if (is_wp_error($response)) {
                $err = $response->get_error_message();
                error_log('MoneyEU2 processPayment HTTP error: ' . $err);
                wc_add_notice(__('Payment error: ', 'woocommerce') . $err, 'error');
                return array('result' => 'fail');
            }

            $http_code = wp_remote_retrieve_response_code($response);
            $raw_resp  = wp_remote_retrieve_body($response);
            $body      = json_decode($raw_resp, true);
            error_log('MoneyEU2 processPayment response HTTP ' . $http_code . ': ' . $raw_resp);

            if ($http_code >= 400 || !is_array($body) || empty($body['success'])) {
                $err = is_array($body) ? ($body['message'] ?? 'Payment initiation failed.') : 'Payment initiation failed.';
                $order->update_status('failed', __('MoneyEU API error: ', 'woocommerce') . sanitize_text_field($err));
                wc_add_notice(__('Payment error: ', 'woocommerce') . sanitize_text_field($err), 'error');
                return array('result' => 'fail');
            }

            $payload = is_array($body['data'] ?? null) ? $body['data'] : array();

            $transaction_id = sanitize_text_field((string) ($payload['transactionId'] ?? ''));
            $is_hpp_response = !empty($payload['hpp']);
            $payment_url     = esc_url_raw((string) ($payload['paymentUrl'] ?? ''));
            $redirect_url    = esc_url_raw((string) ($payload['redirectUrl'] ?? ''));

            if ($transaction_id === '') {
                $order->update_status('failed', __('MoneyEU API error: no transaction id returned.', 'woocommerce'));
                wc_add_notice(__('Payment error: unable to initiate payment.', 'woocommerce'), 'error');
                return array('result' => 'fail');
            }

            $order->update_meta_data('_moneyeu2_transaction_id', $transaction_id);
            $order->update_meta_data('_moneyeu2_flow_type', $is_hpp ? 'hpp' : 's2s');
            $order->save();

            $order->update_status('on-hold', __('Awaiting MoneyEU payment confirmation.', 'woocommerce'));
            wc_reduce_stock_levels($order_id);
            WC()->cart->empty_cart();

            if ($is_hpp_response && $payment_url !== '') {
                $order->add_order_note(__('MoneyEU: redirecting to hosted payment page.', 'woocommerce') . ' TXN: ' . esc_html($transaction_id));
                return array('result' => 'success', 'redirect' => $payment_url);
            }

            if ($redirect_url !== '') {
                $order->add_order_note(__('MoneyEU: redirecting for authentication.', 'woocommerce') . ' TXN: ' . esc_html($transaction_id));
                return array('result' => 'success', 'redirect' => $redirect_url);
            }

            // No redirect target — resolve synchronously if possible before falling back to polling.
            $resolved = moneyeu2_poll_status($order_id);
            if ($resolved === 'processing') {
                return array('result' => 'success', 'redirect' => $order->get_checkout_order_received_url());
            }
            if ($resolved === 'failed') {
                wc_add_notice(__('Payment declined.', 'woocommerce'), 'error');
                return array('result' => 'fail');
            }

            $order->add_order_note(__('MoneyEU: payment initiated — awaiting confirmation.', 'woocommerce') . ' TXN: ' . esc_html($transaction_id));
            return array(
                'result'   => 'success',
                'redirect' => add_query_arg(
                    array('moneyeu2_status' => 'pending', 'key' => $order->get_order_key()),
                    $order->get_view_order_url()
                ),
            );
        }

        public function thankyou_page() {
            if ($this->instructions) {
                echo wpautop(wp_kses_post($this->instructions));
            }
        }

        public function email_instructions($order, $sent_to_admin, $plain_text = false) {
            if ($this->instructions && !$sent_to_admin && $this->id === $order->get_payment_method()) {
                echo wpautop(wp_kses_post($this->instructions));
            }
        }
    }

    add_filter('woocommerce_payment_gateways', 'add_moneyeu2_gateway_class');
    function add_moneyeu2_gateway_class($gateways) {
        $gateways[] = 'WC_Gateway_MoneyEU_Payments';
        return $gateways;
    }
}

// ---- WooCommerce Blocks integration ----

add_action('woocommerce_blocks_loaded', 'moneyeu2_register_blocks_integration');
function moneyeu2_register_blocks_integration() {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) return;

    class WC_MoneyEU_Payments_Blocks_Integration extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
        protected $name = 'moneyeu_payments';

        public function initialize() {
            $this->settings = get_option(MONEYEU2_OPTION_KEY, array());
        }

        public function is_active() {
            return !empty($this->settings['enabled']) && $this->settings['enabled'] === 'yes';
        }

        public function get_payment_method_script_handles() {
            wp_register_script(
                'moneyeu2-blocks',
                plugin_dir_url(__FILE__) . 'assets/js/moneyeu-blocks.js',
                array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'),
                '1.0.0',
                true
            );
            return array('moneyeu2-blocks');
        }

        public function get_payment_method_data() {
            return array(
                'title'       => $this->settings['title'] ?? 'Credit Card (MoneyEU)',
                'description' => $this->settings['description'] ?? '',
                'flowType'    => ($this->settings['flow_type'] ?? 's2s') === 'hpp' ? 'hpp' : 's2s',
                'supports'    => array('products'),
            );
        }
    }

    add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
        $registry->register(new WC_MoneyEU_Payments_Blocks_Integration());
    });
}
