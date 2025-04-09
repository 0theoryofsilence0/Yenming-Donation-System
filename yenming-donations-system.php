<?php
/*
Plugin Name: Yen Ming Temple Donation System
Description: Handles donation tracking and processing for Yen Ming Temple
Version: 1.0
Author: Paperdino Dev (Julius Enriquez)
Author URI: https://paperdino.com.au/
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class YenMingDonationSystem {
    public function __construct() {
        // Register activation hook to create the database table
        register_activation_hook(__FILE__, array($this, 'create_donations_item_table'));

        // Initialize all hooks
        $this->init_hooks();
    }

    private function init_hooks() {
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // Formidable hooks
        add_action('frm_payment_status_complete', array($this, 'frm_log_payment_complete'), 20);
        add_action('frm_after_create_entry', array($this, 'handle_donation_entry_creation'), 10, 2);
        add_filter('frm_redirect_url', array($this, 'modify_donation_redirect_url'), 10, 3);

        // Validation
        add_action('init', array($this, 'validate_donation_params'));

        // REST API
        add_action('rest_api_init', array($this, 'register_donation_api_routes'));

        // Shortcode
        add_shortcode('display_donations_checkout', array($this, 'display_donations_checkout_shortcode'));

        // AJAX handlers
        add_action('wp_ajax_update_donation_amount', array($this, 'handle_update_donation_amount'));
        add_action('wp_ajax_nopriv_update_donation_amount', array($this, 'handle_update_donation_amount'));
        add_action('wp_ajax_delete_donation', array($this, 'handle_delete_donation'));
        add_action('wp_ajax_nopriv_delete_donation', array($this, 'handle_delete_donation'));
    }

    public function create_donations_item_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'donations_item';
        $charset_collate = $wpdb->get_charset_collate();

        // Check if the table already exists to avoid recreation.
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $sql = "CREATE TABLE $table_name (
                id BIGINT(20) NOT NULL AUTO_INCREMENT,
                user_id BIGINT(20) DEFAULT NULL, 
                session_id VARCHAR(255) NOT NULL,
                form_id BIGINT(20) NOT NULL,
                entry_id BIGINT(20) NOT NULL,
                donation_amount DECIMAL(10, 2) NOT NULL,
                form_name VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY (id)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }

    public function enqueue_assets() {
        // Enqueue JavaScript
        wp_enqueue_script(
            'donation-actions',
            plugins_url('donation-actions.js', __FILE__),
            array('jquery'), 
            null, 
            true
        );
        
        // Enqueue CSS
        wp_enqueue_style(
            'donation-styles',
            plugins_url('donation-styles.css', __FILE__),
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'donation-styles.css') // Use file modification time as version
        );

        // Localize script for AJAX
        wp_localize_script('donation-actions', 'donation_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('donation_actions_nonce')
        ]);
    }

    public function frm_log_payment_complete($atts) {
        $target_action_id = 43398; // Replace with your actual Stripe payment action ID

        if (
            $atts['payment']->status === 'complete' &&
            (int) $atts['payment']->action_id === $target_action_id
        ) {
            error_log('payment complete!');
            error_log('Amount: $' . $atts['payment']->amount);
            error_log('Payment ID: ' . $atts['payment']->id);

            $entry_id = isset($atts['payment']->entry_id) ? $atts['payment']->entry_id : 0;
            error_log('Entry ID: ' . $entry_id);

            // Get identification
            $user_id = get_current_user_id();
            $session_id = isset($_COOKIE['donation_session_id']) ? sanitize_text_field($_COOKIE['donation_session_id']) : '';
        
            if (empty($user_id) && empty($session_id)) {
                error_log("ERROR: No user or session identifier found");
                return;
            }

            error_log("User ID: " . $user_id);
            error_log("Session ID: " . $session_id);
            
            // Immediate cleanup (no longer need to schedule it)
            global $wpdb;
            $table_name = $wpdb->prefix . 'donations_item';
            
            $conditions = [];
            $params = [];
        
            if ($user_id) {
                $conditions[] = 'user_id = %d';
                $params[] = $user_id;
            }
        
            if ($session_id) {
                $conditions[] = 'session_id = %s';
                $params[] = $session_id;
            }
        
            if (!empty($conditions)) {
                $where_clause = implode(' OR ', $conditions);
                $query = "DELETE FROM {$table_name} WHERE {$where_clause}";
        
                try {
                    $prepared_query = $wpdb->prepare($query, ...$params);
                    $deleted = $wpdb->query($prepared_query);
        
                    if ($deleted !== false) {
                        error_log("SUCCESS: Deleted {$deleted} donation record(s).");
        
                        // Clear session cookie if used
                        if ($session_id) {
                            setcookie(
                                'donation_session_id',
                                '',
                                time() - 3600,
                                defined('COOKIEPATH') ? COOKIEPATH : '/',
                                defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : $_SERVER['HTTP_HOST']
                            );
                            error_log("Session cookie cleared.");
                        }
                    }
                } catch (Exception $e) {
                    error_log("DB ERROR: " . $e->getMessage());
                }
            }
        }
    }

    public function validate_donation_params() {
        // Check for 'total_amount' parameter in the URL query
        if (isset($_GET['total_amount'])) {
            // Get the total_amount from the query parameter
            $total_amount = floatval($_GET['total_amount']);
            
            // Ensure total_amount is a valid number and greater than zero
            if ($total_amount <= 0) {
                // Redirect to a custom error page or show an error message
                wp_die('Invalid total amount parameter.', 'Invalid Request', array('response' => 400));
            }
            
            // Check the sum of donations (from the database) and ensure it matches the expected total
            $validation_result = $this->is_valid_donation_total($total_amount);
            
            if ($validation_result === 'no_donations') {
                wp_die('No donations found. Please add donations to proceed.', 'Invalid Request', array('response' => 400));
            } elseif ($validation_result === false) {
                wp_die('Total amount mismatch. Please ensure the donation amount is valid.', 'Invalid Request', array('response' => 400));
            }
        } 
    }
    
    public function is_valid_donation_total($expected_total) {
        global $wpdb;
        $user_id = get_current_user_id();
        $session_id = isset($_COOKIE['donation_session_id']) ? sanitize_text_field($_COOKIE['donation_session_id']) : '';
    
        // Query donations based on user or session ID
        $table_name = $wpdb->prefix . 'donations_item';
    
        // Prepare the query to sum donation amounts for the current user or session
        if ($user_id) {
            // If the user is logged in, we filter by user_id
            $query = $wpdb->prepare(
                "SELECT SUM(donation_amount) FROM $table_name WHERE user_id = %d",
                $user_id
            );
        } else {
            // If the user is not logged in, we filter by session_id
            $query = $wpdb->prepare(
                "SELECT SUM(donation_amount) FROM $table_name WHERE session_id = %s",
                $session_id
            );
        }
    
        $actual_total = $wpdb->get_var($query);
    
        // Check if any donations were found
        if ($actual_total === null) {
            // No donations found
            return 'no_donations';
        }
    
        // Compare the sum with the expected total amount from the query
        return abs($actual_total - $expected_total) < 0.01;  // Allow for floating point precision
    }

    public function handle_donation_entry_creation($entry_id, $form_id) {
        // Retrieve entry data
        $entry = FrmEntry::getOne($entry_id, true);

        // Log the entire entry data before processing
        error_log('Submitted Entry Data: ' . print_r($entry, true));

        $donation_form_ids = [13, 6, 11, 8, 2, 18, 10, 14, 5, 21, 24, 29, 31, 32, 34, 35];

        // Check if the form is a donation form.
        if (in_array($form_id, $donation_form_ids)) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'donations_item';

            // Determine user identification: user_id or session_id.
            $user_id = get_current_user_id();
            $session_id = null;

            if (!$user_id) {
                // Generate or retrieve a unique session identifier for anonymous users
                if (!isset($_COOKIE['donation_session_id'])) {
                    $session_id = wp_generate_uuid4(); // Generate a new UUID
                    setcookie('donation_session_id', $session_id, time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
                } else {
                    $session_id = sanitize_text_field($_COOKIE['donation_session_id']);
                }
                $user_id = null; // Reset user_id for clarity
            } else {
                $session_id = null; // Logged-in users don't need a session ID
            }

            // Initialize donation amount.
            $amount = 0;

            // Map form IDs to their respective donation amount fields.
            $form_field_map = [
                13 => 261,
                6 => 81,
                11 => 243,
                8 => 144,
                2 => 46,
                18 => 408,
                10 => 213,
                14 => 298,
                5 => 59,
                21 => 475,
                24 => 514,
                29 => 626,
                31 => 702,
                32 => 736,
                34 => 777,
                35 => 811,
            ];

            if (isset($form_field_map[$form_id]) && isset($entry->metas[$form_field_map[$form_id]])) {
                $amount = floatval($entry->metas[$form_field_map[$form_id]]);
                error_log("Donation amount for form $form_id: $amount");
            } else {
                error_log("No donation amount found for form $form_id.");
                return; // Skip processing if no amount is found.
            }

            // Get the form name.
            $form = FrmForm::getOne($form_id);
            $form_name = $form->name;

            // Insert the donation data into the custom table.
            $inserted = $wpdb->insert($table_name, [
                'user_id'         => $user_id,
                'session_id'      => $session_id,
                'form_id'         => $form_id,
                'entry_id'        => $entry_id,
                'donation_amount' => $amount,
                'form_name'       => $form_name,
                'created_at'      => current_time('mysql'),
            ]);

            if ($inserted === false) {
                wp_cache_flush();  // Clear cache after insertion if necessary
                error_log('Failed to insert donation data into the table.');
            } else {
                error_log('Donation data inserted successfully.');
            }
        }
    }

    public function register_donation_api_routes() {
        register_rest_route('custom/v1', '/donations/', array(
            'methods'  => 'GET',
            'callback' => array($this, 'get_user_donations'),
            'permission_callback' => function () { return true; }
        ));
    }

    public function get_user_donations(WP_REST_Request $request) {
        global $wpdb;

        // Get the user_id and session_id from the request parameters
        $user_id = $request->get_param('user_id');
        $session_id = $request->get_param('sid') ? sanitize_text_field($request->get_param('sid')) : '';

        // Get the current user ID (if not passed in the request)
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        error_log('param - $user_id: ' . $user_id);
        error_log('param - $session_id: ' . $session_id);

        // Define the custom table name where donations are stored
        $table_name = $wpdb->prefix . 'donations_item';
        
        error_log('Checking session_id: ' . (empty($session_id) ? 'EMPTY' : $session_id));

        if ($user_id > 0) {
            $query = $wpdb->prepare("SELECT * FROM $table_name WHERE user_id = %d", $user_id);
            error_log('$query: ' . $query);
        } elseif (isset($session_id) && strlen($session_id) > 0) {
            error_log('Session detected, attempting query...');
            $query = $wpdb->prepare("SELECT * FROM $table_name WHERE session_id = %s", $session_id);
            error_log('$query: ' . $query);
        } else {
            error_log('No valid user_id or session_id found.');
            return new WP_REST_Response(['message' => 'No donations found'], 404);
        }

        // Get the donations from the custom table
        $donations = $wpdb->get_results($query);

        // Check if donations were found
        if (empty($donations)) {
            return new WP_REST_Response(['message' => 'No donations found'], 404);
        }

        // Prepare the donation data for the response
        $donation_data = array();
        foreach ($donations as $donation) {
            $donation_item = array(
                'form_name'       => $donation->form_name,
                'donation_amount' => $donation->donation_amount,
                'entry_id' => $donation->entry_id,
            );
            
            // Include session_id for guest users or user_id for logged-in users
            if ($user_id > 0) {
                $donation_item['user_id'] = $user_id;
            } else {
                $donation_item['session_id'] = $session_id;
            }
            
            $donation_data[] = $donation_item;
        }

        return new WP_REST_Response($donation_data, 200);
    }

    public function display_donations_checkout_shortcode() {
        // Get the user_id and session_id
        $user_id = get_current_user_id();
        $session_id = isset($_COOKIE['donation_session_id']) ? sanitize_text_field($_COOKIE['donation_session_id']) : '';

        error_log('api call - user_id: '.$user_id);
        error_log('api call - session_id: '.$session_id);

        // If no user_id and no session_id, stop execution and return empty.
        if (!$user_id && empty($session_id)) {
            return '<p style="padding: 20px; text-align:center">您没有待处理的捐款./You have no pending donations.</p>';
        }

        // API endpoint for donations data
        $api_url = site_url('/wp-json/custom/v1/donations/');

        // Add parameters to the API request URL based on user status
        if ($user_id > 0) {
            $api_url = add_query_arg('user_id', $user_id, $api_url);
        } elseif (!empty($session_id)) {
            $api_url = add_query_arg('sid', $session_id, $api_url);
        }

        // Log the API URL for debugging
        error_log('$api_url: ' . $api_url);

        // Fetch donations data from the REST API
        $response = wp_remote_get($api_url);

        if (is_wp_error($response)) {
            return '<p style="padding: 20px; text-align:center">您没有待处理的捐款./You have no pending donations.</p>';
        }
        
        $donations = json_decode(wp_remote_retrieve_body($response), true);
        
        // Check if donations data is available
        if (empty($donations) || isset($donations['message'])) {
            return '<p style="padding: 20px; text-align:center">没有找到捐款数据/No donations found.</p>';
        }
        

        // Generate the checkout table.
        $total = 0;
        $output = '<div id="checkout-form" class="checkout-table">';
        $output .= '<h2>您的捐款/Your Donations</h2>';
        $output .= '<table><tr><th><strong>描述/Description</strong></th><th><strong>量/Amount</strong></th><th><strong>操作/Actions</strong></th></tr>';
        
        foreach ($donations as $donation) {
            $output .= '<tr>';
            $output .= '<td>' . esc_html($donation['form_name']) . '</td>';
            $output .= '<td>
                <span class="donation-amount" data-id="' . $donation['entry_id'] . '">' . esc_html($donation['donation_amount']) . '</span>
                <input type="number" class="edit-amount" data-id="' . $donation['entry_id'] . '" value="' . esc_html($donation['donation_amount']) . '" style="display: none; width: 100px;">
            </td>';
            $output .= '<td class="checkout-actions">
                <button class="edit-donation" data-id="' . $donation['entry_id'] . '">编辑<br>Edit</button>
                <button class="save-donation" data-id="' . $donation['entry_id'] . '" style="display: none;">保存<br>Save</button>
                <button class="cancel-donation" data-id="' . $donation['entry_id'] . '" style="display: none;">取消<br>Cancel</button>
                <button class="delete-donation" data-id="' . $donation['entry_id'] . '">删除<br>Delete</button>
            </td>';
            $output .= '</tr>';
            $total += floatval($donation['donation_amount']); 
        }

        $formatted_total = number_format($total, 2);
        $output .= "<tr><td><strong>总/Total</strong></td><td>{$formatted_total}</td><td></td></tr>";
        $output .= '</table>';

        // Determine the checkout URL based on the current language
        $current_language = pll_current_language();
        if ($current_language === 'en') {
            $checkout_url = add_query_arg('total_amount', $total, 'https://yenmingtemple.org.au/en/donation-items');
        } else {
            $checkout_url = add_query_arg('total_amount', $total, 'https://yenmingtemple.org.au/checkout/');
        }
        
        $output .= '<a href="' . esc_url($checkout_url) . '" class="button checkout-btn">继续付款/Proceed to Payment</a>';

        // Include checkout form
        if (class_exists('FrmFormsController')) {
            $output .= FrmFormsController::get_form_shortcode(['id' => 19]);
        }

        $output .= '</div>';

        return $output;
    }

    public function modify_donation_redirect_url($url, $form, $args) {
        $donation_form_ids = [13, 6, 11, 8, 2, 18, 10, 14, 5, 21, 24, 29, 31, 32, 34, 35];

        if (in_array($form->id, $donation_form_ids)) {
            if (function_exists('pll_current_language')) {
                $current_lang = pll_current_language();
                if ($current_lang === 'en') {
                    return 'https://yenmingtemple.org.au/en/donation-items/';
                }
            }
        }
        return $url;
    }

    public function handle_update_donation_amount() {
        global $wpdb;

        check_ajax_referer('donation_actions_nonce', 'security');

        $entry_id = intval($_POST['entry_id']);
        $amount = floatval($_POST['amount']);

        // Verify session for non-logged-in users
        if (!is_user_logged_in()) {
            $session_id = isset($_COOKIE['donation_session_id']) ? sanitize_text_field($_COOKIE['donation_session_id']) : '';
            if (empty($session_id)) {
                wp_send_json_error(['message' => 'Invalid session'], 403);
            }
            
            // Verify the donation belongs to this session
            $table_name = $wpdb->prefix . 'donations_item';
            $donation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE entry_id = %d AND session_id = %s",
                $entry_id,
                $session_id
            ));
            
            if (!$donation) {
                wp_send_json_error(['message' => 'Donation not found for this session'], 403);
            }
        }

        // Rest of your update code...
        $table_name = $wpdb->prefix . 'donations_item';
        $updated = $wpdb->update(
            $table_name,
            ['donation_amount' => $amount],
            ['entry_id' => $entry_id]
        );

        // Update Formidable entry if exists
        if (class_exists('FrmEntry')) {
            $form_field_map = [
                13 => 261,
                6 => 81,
                11 => 243,
                8 => 144,
                2 => 46,
                18 => 408,
                10 => 213,
                14 => 298,
                5 => 59,
                21 => 475,
                24 => 514,
                29 => 626,
                31 => 702,
                32 => 736,
                34 => 777,
                35 => 811,
            ];

            $entry = FrmEntry::getOne($entry_id);
            if ($entry && isset($form_field_map[$entry->form_id])) {
                $field_id = $form_field_map[$entry->form_id];
                FrmEntryMeta::update_entry_meta($entry_id, $field_id, $amount);
            }
        }

        wp_send_json_success([
            'redirect' => $_SERVER['HTTP_REFERER'] ?: home_url()
        ]);
    }

    public function handle_delete_donation() {
        global $wpdb;

        check_ajax_referer('donation_actions_nonce', 'security');

        $entry_id = intval($_POST['entry_id']);

        // Verify session for non-logged-in users
        if (!is_user_logged_in()) {
            $session_id = isset($_COOKIE['donation_session_id']) ? sanitize_text_field($_COOKIE['donation_session_id']) : '';
            if (empty($session_id)) {
                wp_send_json_error(['message' => 'Invalid session'], 403);
            }
            
            // Verify the donation belongs to this session
            $table_name = $wpdb->prefix . 'donations_item';
            $donation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE entry_id = %d AND session_id = %s",
                $entry_id,
                $session_id
            ));
            
            if (!$donation) {
                wp_send_json_error(['message' => 'Donation not found for this session'], 403);
            }
        }

        // Rest of your delete code...
        $table_name = $wpdb->prefix . 'donations_item';
        $wpdb->delete($table_name, ['entry_id' => $entry_id]);

        if (class_exists('FrmEntry')) {
            $entry = FrmEntry::getOne($entry_id);
            if ($entry) {
                FrmEntry::destroy($entry_id);
            }
        }

        wp_send_json_success([
            'redirect' => $_SERVER['HTTP_REFERER'] ?: home_url()
        ]);
    }
}

// Initialize the plugin
new YenMingDonationSystem();