<?php
/**
 * Plugin Name: Custom Redirect Manager
 * Plugin URI: https://profiles.wordpress.org/sachinatzenith/
 * Description: A plugin to manage custom redirects and automatically handle URL changes.
 * Version: 1.0.0
 * Author: Sachin Mishra
 * Author URI: https://www.linkedin.com/in/mishrasachin
 * License: GPL-2.0+
 * Text Domain: custom-redirect-manager
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define constants
define('CRM_PLUGIN_VERSION', '1.0.0');
define('CRM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CRM_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Activation hook to create database table for redirects.
 */
function crm_activate_plugin() {
    global $wpdb;
    $crmtable = $wpdb->prefix . 'crm_redirects';

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $crmtable (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        old_url VARCHAR(255) NOT NULL,
        new_url VARCHAR(255) NOT NULL,
        type ENUM('301', '302') DEFAULT '301',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'crm_activate_plugin');

/**
 * Deactivation hook to clean up.
 */
function crm_deactivate_plugin() {
    // Placeholder for deactivation tasks if needed
}
register_deactivation_hook(__FILE__, 'crm_deactivate_plugin');

/**
 * Add redirect rules based on database entries.
 */
function crm_handle_redirects() {
    if (is_admin()) {
        return;
    }
    global $wp, $wpdb;
    $schema = (isset($_SERVER["HTTPS"]) == "on") ? "https://" : "http://";
    // $current_url = esc_url_raw(home_url($_SERVER['REQUEST_URI'])); // not working on local
    if(isset($_SERVER["SERVER_NAME"]) && isset($_SERVER["REQUEST_URI"])){
        $servername = filter_input(INPUT_SERVER, 'SERVER_NAME', FILTER_SANITIZE_STRING);
        $requesturi = filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_STRING);
        $current_url = $schema.$servername.$requesturi; // added a hack
        
    }
    $crmtable = $wpdb->prefix . 'crm_redirects';
    
    $redirect = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE old_url = %s", $crmtable, $current_url));   // phpcs:ignore WordPress.DB.DirectDatabaseQuery   

    if ($redirect) {
        wp_redirect(esc_url_raw($redirect->new_url), $redirect->type);
        exit;
    }
}
add_action('init', 'crm_handle_redirects');

/**
 * Automatically create redirects when slugs change.
 */
function crm_auto_redirect_on_slug_change($post_id, $post_after, $post_before) {
    if ($post_after->post_name !== $post_before->post_name) {
        global $wpdb;
        $crmtable = $wpdb->prefix . 'crm_redirects';

        $old_url = home_url('/' . $post_before->post_name);
        $new_url = home_url('/' . $post_after->post_name);

        $wpdb->insert($crmtable, ['old_url' => $old_url, 'new_url' => $new_url,'type'    => '301',],['%s', '%s', '%s']);  // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }
}
add_action('post_updated', 'crm_auto_redirect_on_slug_change', 10, 3);

/**
 * Admin menu to manage redirects.
 */
function crm_add_admin_menu() {
    add_menu_page(
        __('Custom Redirect Manager', 'custom-redirect-manager'),
        __('Redirects', 'custom-redirect-manager'),
        'manage_options',
        'custom-redirect-manager',
        'crm_redirects_page',
        'dashicons-randomize',
        20
    );
}
add_action('admin_menu', 'crm_add_admin_menu');

/**
 * Redirect management page in admin.
 */

 function crm_redirects_page() {
    global $wpdb;
    $crmtable = $wpdb->prefix . 'crm_redirects';

       // Handle redirect editing
       $edit_redirect = null;
       if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['redirect_id'])) {
           $redirect_id = intval($_GET['redirect_id']);
           $edit_redirect = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id = %d", $crmtable, $redirect_id));  // phpcs:ignore WordPress.DB.DirectDatabaseQuery
       }
       if (isset($_SERVER['REQUEST_METHOD']) &&  $_SERVER['REQUEST_METHOD']=== 'POST' && isset($_POST['crm_add_redirect'])) {
        check_admin_referer('crm_add_redirect_nonce');

        $old_url = (isset($_POST['old_url'])) ? esc_url_raw(wp_unslash($_POST['old_url'])) : ""; 
        $new_url = (isset($_POST['new_url'])) ? esc_url_raw(wp_unslash($_POST['new_url'])) : ""; 
        $type = (isset($_POST['type'])) ? esc_url_raw(wp_unslash($_POST['type'])) : ""; 

        $old_url = esc_url_raw($old_url);
        $new_url = esc_url_raw($new_url);
        $type    = sanitize_text_field($type);

        $wpdb->insert($crmtable, [ 'old_url' => $old_url,'new_url' => $new_url, 'type'    => $type,], ['%s', '%s', '%s']);  // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        echo '<div class="updated"><p>' . esc_html('Redirect added successfully.') . '</p></div>';
    }
 
    // Handle redirect update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crm_update_redirect'])) {
        check_admin_referer('crm_update_redirect_nonce');

        $redirect_id = isset($_POST['redirect_id']) ? intval($_POST['redirect_id']): "";
        $old_url = esc_url_raw(wp_unslash($_POST['old_url']));
        $new_url = esc_url_raw(wp_unslash($_POST['new_url']));
        $type = sanitize_text_field(wp_unslash($_POST['type']));

        $updated = $wpdb->update( $crmtable,['old_url' => $old_url,'new_url' => $new_url, 'type'    => $type,], ['id' => $redirect_id], ['%s', '%s', '%s'], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ($updated !== false) {
            wp_redirect(admin_url('admin.php?page=custom-redirect-manager&message=updated'));
            exit;
        } else {
            echo '<div class="error"><p>' . esc_html('Failed to update redirect.') . '</p></div>';
        }
    }

    // Display success message
    if (isset($_GET['message']) && $_GET['message'] === 'updated') {
        echo '<div class="updated"><p>' . esc_html('Redirect updated successfully.') . '</p></div>';
    }

    // Form for adding or editing redirects
    ?>
    <div class="wrap">
        <h1><?php echo $edit_redirect ? esc_html('Edit Redirect') : esc_html('Add New Redirect'); ?></h1>
        <form method="post">
            <?php wp_nonce_field($edit_redirect ? 'crm_update_redirect_nonce' : 'crm_add_redirect_nonce'); ?>
            <input type="hidden" name="redirect_id" value="<?php echo esc_attr($edit_redirect->id ?? ''); ?>">
            <table class="form-table">
                <tr>
                    <th><?php esc_html('Old URL'); ?></th>
                    <td><input type="text" name="old_url" value="<?php echo esc_attr($edit_redirect->old_url ?? ''); ?>" required></td>
                </tr>
                <tr>
                    <th><?php esc_html('New URL'); ?></th>
                    <td><input type="text" name="new_url" value="<?php echo esc_attr($edit_redirect->new_url ?? ''); ?>" required></td>
                </tr>
                <tr>
                    <th><?php esc_html('Redirect Type'); ?></th>
                    <td>
                        <select name="type">
                            <option value="301" <?php selected($edit_redirect->type ?? '', '301'); ?>>301 (Permanent)</option>
                            <option value="302" <?php selected($edit_redirect->type ?? '', '302'); ?>>302 (Temporary)</option>
                        </select>
                    </td>
                </tr>
            </table>
            <p><input type="submit" name="<?php echo $edit_redirect ? 'crm_update_redirect' : 'crm_add_redirect'; ?>" class="button button-primary" value="<?php echo $edit_redirect ? esc_html('Update Redirect') : esc_html('Add Redirect'); ?>"></p>
        </form>
    </div>
    <?php
    // Display existing redirects
    $redirects = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i", $crmtable));  // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    ?>
    <h2><?php esc_html('Existing Redirects'); ?></h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php echo esc_attr('Old URL'); ?></th>
                <th><?php echo esc_attr('New URL'); ?></th>
                <th><?php echo esc_attr('Type'); ?></th>
                <th><?php echo esc_attr('Actions'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($redirects as $redirect) : ?>
                <tr>
                    <td><?php echo esc_html($redirect->old_url); ?></td>
                    <td><?php echo esc_html($redirect->new_url); ?></td>
                    <td><?php echo esc_html($redirect->type); ?></td>
                    <td>
                        <a href="<?php echo esc_url(
                            add_query_arg(
                                [
                                    'page'        => 'custom-redirect-manager',
                                    'action'      => 'edit',
                                    'redirect_id' => $redirect->id,
                                ],
                                admin_url('admin.php')
                            )
                        ); ?>" class="button button-secondary">
                            <?php echo esc_html('Edit'); ?>
                        </a>
                        <a href="<?php echo esc_url(
                            wp_nonce_url(
                                admin_url('admin.php?page=custom-redirect-manager&action=delete&redirect_id=' . $redirect->id),
                                'crm_delete_redirect'
                            )
                        ); ?>" class="button button-secondary" onclick="return confirm('<?php echo esc_html('Are you sure you want to delete this redirect?'); ?>');">
                            <?php echo esc_html('Delete'); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Handle redirect deletion.
 */
function crm_handle_delete_redirect() {
    if (
        isset($_GET['action']) && $_GET['action'] === 'delete' &&
        isset($_GET['redirect_id']) && isset($_GET['_wpnonce'])
    ) {
        if (!wp_verify_nonce(sanitize_key($_GET['_wpnonce']), 'crm_delete_redirect')) {
            wp_die(message: esc_attr('Security check failed.'));
        }

        global $wpdb;
        $crmtable = $wpdb->prefix . 'crm_redirects';
        $redirect_id = intval($_GET['redirect_id']);

        $deleted = $wpdb->delete($crmtable, ['id' => $redirect_id], ['%d']);   // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if ($deleted) {
            wp_redirect(admin_url('admin.php?page=custom-redirect-manager&message=deleted'));
            exit;
        } else {
            wp_die(esc_attr('Failed to delete redirect.'));
        }
    }
}
add_action('admin_init', 'crm_handle_delete_redirect');