<?php
/*
Plugin Name: WPU Redirection Extended
Plugin URI: https://github.com/WordPressUtilities/wpu_redirection_extended
Update URI: https://github.com/WordPressUtilities/wpu_redirection_extended
Description: Enhance the Redirection plugin with additional features.
Version: 0.7.0
Author: darklg
Author URI: https://darklg.me/
Text Domain: wpu_redirection_extended
Domain Path: /lang
Requires at least: 6.2
Requires PHP: 8.0
Network: Optional
License: MIT License
License URI: https://opensource.org/licenses/MIT
*/

if (!defined('ABSPATH')) {
    exit();
}

class WPURedirectionExtended {
    private $plugin_version = '0.7.0';
    private $plugin_settings = array(
        'id' => 'wpu_redirection_extended',
        'name' => 'WPU Redirection Extended'
    );
    private $user_level = 'wpu_redirection_extended_access';
    private $basetoolbox;
    private $messages;
    private $adminpages;

    public function __construct() {
        add_action('init', array(&$this, 'load_translation'));
        add_action('init', array(&$this, 'load_toolbox'));
        add_action('init', array(&$this, 'load_admin_page'));
        add_action('init', array(&$this, 'load_messages'));
        add_action('init', array(&$this, 'check_dependencies'));
        add_action('init', array(&$this, 'set_custom_roles'), 11);
        add_action('wp_dashboard_setup', array(&$this, 'add_dashboard_widgets'));
        add_action('admin_menu', array(&$this, 'set_admin_menus'), 10);
        add_action('edit_form_after_title', array(&$this, 'notice_slug_match_redirection'));

        /* Redirection settings */
        add_filter('redirection_role', function ($role) {
            return $this->user_level;
        });
    }

    # TRANSLATION
    public function load_translation() {
        $lang_dir = dirname(plugin_basename(__FILE__)) . '/lang/';
        if (strpos(__DIR__, 'mu-plugins') !== false) {
            load_muplugin_textdomain('wpu_redirection_extended', $lang_dir);
        } else {
            load_plugin_textdomain('wpu_redirection_extended', false, $lang_dir);
        }
        /* Load desc string */
        __('Enhance the Redirection plugin with additional features.', 'wpu_redirection_extended');
    }

    # TOOLBOX
    public function load_toolbox() {
        require_once __DIR__ . '/inc/WPUBaseToolbox/WPUBaseToolbox.php';
        $this->basetoolbox = new \wpu_redirection_extended\WPUBaseToolbox(array(
            'need_form_js' => false,
            'plugin_name' => $this->plugin_settings['name']
        ));
    }

    # CUSTOM PAGE
    public function load_admin_page() {
        $admin_pages = array(
            'main' => array(
                'icon_url' => 'dashicons-admin-generic',
                'menu_name' => $this->plugin_settings['name'],
                'name' => '' . $this->plugin_settings['name'],
                'settings_link' => true,
                'section' => 'tools.php',
                'has_file' => true,
                'settings_name' => __('Settings', 'wpu_redirection_extended'),
                'function_content' => array(&$this,
                    'page_content__main'
                ),
                'function_action' => array(&$this,
                    'page_action__main'
                )
            )
        );
        $pages_options = array(
            'id' => $this->plugin_settings['id'],
            'level' => $this->user_level,
            'basename' => plugin_basename(__FILE__)
        );
        // Init admin page
        require_once __DIR__ . '/inc/WPUBaseAdminPage/WPUBaseAdminPage.php';
        $this->adminpages = new \wpu_redirection_extended\WPUBaseAdminPage();
        $this->adminpages->init($pages_options, $admin_pages);
    }

    # MESSAGES
    public function load_messages() {
        if (!is_admin()) {
            return;
        }

        require_once __DIR__ . '/inc/WPUBaseMessages/WPUBaseMessages.php';
        $this->messages = new \wpu_redirection_extended\WPUBaseMessages($this->plugin_settings['id']);

    }
    /* Add a message */
    public function set_message($id, $message, $group = '') {
        if (!$this->messages) {
            error_log($id . ' - ' . $message);
            return;
        }
        $this->messages->set_message($id, $message, $group);
    }

    # DEPENDENCIES
    public function check_dependencies() {
        $this->basetoolbox->check_plugins_dependencies(array(
            'wpuoptions' => array(
                'path' => 'redirection/redirection.php',
                'url' => 'https://wordpress.org/plugins/redirection/',
                'name' => 'Redirection'
            )
        ));
    }

    /* ----------------------------------------------------------
      Menus
    ---------------------------------------------------------- */

    public function set_admin_menus() {
        if (!defined('REDIRECTION_DB_VERSION')) {
            return;
        }
        /* Quick menu to Redirection */
        add_menu_page(
            __('Redirection', 'wpu_redirection_extended'),
            __('Redirection', 'wpu_redirection_extended'),
            $this->user_level,
            'tools.php?page=redirection.php'
        );
        /*  Additionnal submenu for settings */
        add_submenu_page(
            'tools.php?page=redirection.php',
            __('Extended settings', 'wpu_redirection_extended'),
            __('Extended settings', 'wpu_redirection_extended'),
            $this->user_level,
            'tools.php?page=wpu_redirection_extended-main'
        );
    }

    /* ----------------------------------------------------------
      Roles
    ---------------------------------------------------------- */

    public function set_custom_roles() {
        /* Set access to existing roles */
        $this->update_role('super_editor');
        $this->update_role('administrator');
        $this->create_custom_role();
    }

    public function create_custom_role() {
        $capabilities = array();
        $capabilities[$this->user_level] = true;
        $this->basetoolbox->create_custom_user_role($capabilities, array(
            'role_opt' => 'wpu_redirection_extended_manager',
            'role_id' => 'redirection_manager',
            'role_name' => __('Redirection Manager', 'wpu_redirection_extended')
        ));
    }

    public function update_role($user_role) {
        $role = get_role($user_role);
        if (!$role) {
            return;
        }
        $role->add_cap($this->user_level, true);
    }

    /* ----------------------------------------------------------
      Admin Page: Main
    ---------------------------------------------------------- */

    public function page_content__main() {

        echo '<h2>' . __('Validate your CSV file', 'wpu_redirection_extended') . '</h2>';
        echo '<table class="form-table">';
        echo $this->get_admin_field_html('upload_file', array(
            'label' => __('CSV File', 'wpu_redirection_extended'),
            'type' => 'upload'
        ));
        echo $this->get_admin_field_html('filter_existing_slugs', array(
            'label' => __('Filter existing slugs', 'wpu_redirection_extended'),
            'label_checkbox' => __('Existing slugs will be removed', 'wpu_redirection_extended'),
            'type' => 'checkbox'
        ));
        echo $this->get_admin_field_html('filter_existing_redirections', array(
            'label' => __('Filter existing redirections', 'wpu_redirection_extended'),
            'label_checkbox' => __('Existing redirections will be removed', 'wpu_redirection_extended'),
            'type' => 'checkbox'
        ));
        echo '</table>';
        echo '<p>';
        submit_button(__('Get a list of errors', 'wpu_redirection_extended'), 'secondary', 'submit_get_errors', false);
        echo ' ';
        submit_button(__('Get a cleaned CSV', 'wpu_redirection_extended'), 'primary', 'submit_upload_csv', false);
        echo '</p>';

        if (!$this->is_redirection_configured()) {
            return;
        }
        echo '<hr />';
        echo '<h2>' . __('Clean database', 'wpu_redirection_extended') . '</h2>';
        echo '<p>' . __('Delete 404 logs where redirections exist or are not useful.', 'wpu_redirection_extended') . '</p>';
        submit_button(__('Clean', 'wpu_redirection_extended'), 'primary', 'submit_clean_database');
    }

    public function page_action__main() {

        if (isset($_POST['submit_upload_csv']) || isset($_POST['submit_get_errors'])) {
            $this->page_action__main__submit_csv(isset($_POST['submit_get_errors']));
        }

        if (isset($_POST['submit_clean_database'])) {
            global $wpdb;

            if (!$this->is_redirection_configured()) {
                $this->set_message('database_cleaned', __('Redirection plugin is not configured.', 'wpu_redirection_extended'), 'error');
                return;
            }

            $deleted = $wpdb->query("
                DELETE FROM {$wpdb->prefix}redirection_404
                WHERE url IN(SELECT url FROM {$wpdb->prefix}redirection_items WHERE match_url != 'regex')
                OR url LIKE '/.well-known/%'
                OR url LIKE '%.php%'
                OR url LIKE '%.js.map%'
                OR url LIKE '%admin%'
            ");

            if (!$deleted) {
                $this->set_message('database_cleaned', __('No invalid 404 log entries found.', 'wpu_redirection_extended'), 'success');
                return;
            }
            $this->set_message('database_cleaned', sprintf(__('Deleted %s log entries.', 'wpu_redirection_extended'), '<strong>' . $deleted . '</strong>'), 'success');
        }

    }

    public function page_action__main__submit_csv($get_errors = false) {

        if (!isset($_FILES['upload_file']) || $_FILES['upload_file']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['upload_file']['tmp_name'])) {
            $this->set_message('csv_upload_error', __('No file uploaded or upload error.', 'wpu_redirection_extended'), 'error');
            return false;
        }

        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($file_info, $_FILES['upload_file']['tmp_name']);

        $allowed_mime_types = array(
            'text/csv',
            'text/plain',
            'application/vnd.ms-excel',
            'application/csv',
            'text/comma-separated-values',
            'application/octet-stream'
        );

        $file_ext = strtolower(pathinfo($_FILES['upload_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($mime_type, $allowed_mime_types) && $file_ext === 'csv') {
            $this->set_message('csv_upload_error', __('The uploaded file is not a valid CSV.', 'wpu_redirection_extended'), 'error');
        }

        $csv_values = array();
        $line_number = 0;
        $handle = fopen($_FILES['upload_file']['tmp_name'], 'r');

        $filter_existing_slugs = isset($_POST['filter_existing_slugs']) && $_POST['filter_existing_slugs'] == '1';
        $existing_slugs = array();
        if ($filter_existing_slugs) {
            $existing_slugs = $this->get_existing_slugs();
        }

        $filter_existing_redirections = isset($_POST['filter_existing_redirections']) && $_POST['filter_existing_redirections'] == '1';
        $existing_redirections = array();
        if ($filter_existing_redirections) {
            $existing_redirections = $this->get_existing_redirections();
        }

        $errors_list = array();

        while (($row = fgetcsv($handle)) !== false) {
            $line_number++;

            /* Ensure CSV format is consistent */
            if (count($row) == 1) {
                if (strpos($row[0], ';') !== false) {
                    $row = explode(';', $row[0]);
                }
            }
            if (count($row) < 2) {
                continue;
            }

            if ($line_number == 1) {
                $first_val = strtolower(trim($row[0]));
                /* Skip header line */
                if ($first_val == 'before' || $first_val == 'from' || $first_val == 'source' || $first_val == 'url' || strpos($first_val, ' url') !== false) {
                    continue;
                }
            }

            $before = mb_convert_encoding($row[0], 'UTF-8', 'UTF-8, ISO-8859-1, ISO-8859-15, Windows-1252');
            $after = mb_convert_encoding($row[1], 'UTF-8', 'UTF-8, ISO-8859-1, ISO-8859-15, Windows-1252');

            /* Ignore lines with spaces */
            if (preg_match('/\s/', $before . $after)) {
                $errors_list[] = sprintf(__('Line %s: contains spaces.', 'wpu_redirection_extended'), $line_number);
                continue;
            }

            /* Remove invalid chars */
            $before = preg_replace('/[^\x20-\x7E]/', '', $before);
            $after = preg_replace('/[^\x20-\x7E]/', '', $after);

            /* Remove domain part for comparison */
            if (strpos($before, 'http') === 0) {
                $before_parts = parse_url($before);
                unset($before_parts['scheme'], $before_parts['host'], $before_parts['port'], $before_parts['user'], $before_parts['pass']);
                $before = $this->basetoolbox->unparse_url($before_parts);
            }
            if (strpos($after, 'http') === 0) {
                $after_parts = parse_url($after);
                unset($after_parts['scheme'], $after_parts['host'], $after_parts['port'], $after_parts['user'], $after_parts['pass']);
                $after = $this->basetoolbox->unparse_url($after_parts);
            }

            /* Force each value to start with a / */
            if (strpos($before, '/') !== 0) {
                $before = '/' . $before;
            }
            if (strpos($after, '/') !== 0) {
                $after = '/' . $after;
            }

            /* Ignore lines where before is equal to after */
            if ($before === $after) {
                $errors_list[] = sprintf(__('Line %s: before and after values are the same.', 'wpu_redirection_extended'), $line_number);
                continue;
            }

            /* Ignore line where before is / */
            if ($before === '/') {
                $errors_list[] = sprintf(__('Line %s: before value is /.', 'wpu_redirection_extended'), $line_number);
                continue;
            }

            /* Ignore line where before and after only differ by a / */
            if (rtrim($before, '/') === rtrim($after, '/')) {
                $errors_list[] = sprintf(__('Line %s: before and after values only differ by a trailing slash.', 'wpu_redirection_extended'), $line_number);
                continue;
            }

            /* Filter existing slugs */
            if ($filter_existing_slugs && in_array($before, $existing_slugs)) {
                $errors_list[] = sprintf(__('Line %s: before value already exists as a slug.', 'wpu_redirection_extended'), $line_number);
                continue;
            }

            /* Filter existing redirections */
            if ($filter_existing_redirections && in_array($before, $existing_redirections)) {
                $errors_list[] = sprintf(__('Line %s: before value already exists as a redirection.', 'wpu_redirection_extended'), $line_number);
                continue;
            }

            $csv_values[] = array(
                'before' => $before,
                'after' => $after
            );
        }
        fclose($handle);

        if ($get_errors) {
            if (empty($errors_list)) {
                $this->set_message('csv_upload_no_errors', __('No errors found in the uploaded file.', 'wpu_redirection_extended'), 'success');
            } else {
                $sep = '<br />- ';
                $this->set_message('csv_upload_errors', __('Errors found in the uploaded file :', 'wpu_redirection_extended') . $sep . implode($sep, $errors_list), 'error');
            }
            return;
        }

        if (empty($csv_values)) {
            $this->set_message('csv_upload_error', __('No valid redirections found in the uploaded file.', 'wpu_redirection_extended'), 'error');
            return;
        }

        $this->basetoolbox->export_array_to_csv($csv_values, 'validated_redirections.csv', array(
            'add_keys' => false
        ));
    }

    public function get_existing_redirections() {
        global $wpdb;
        $existing_redirections = array();
        $results = $wpdb->get_results("SELECT match_url FROM {$wpdb->prefix}redirection_items WHERE match_url != 'regex' and status = 'enabled'", ARRAY_A);
        foreach ($results as $row) {
            $existing_redirections[] = $row['match_url'];
        }
        return $existing_redirections;
    }

    public function get_existing_slugs() {
        $posts = get_posts(array(
            'post_type' => 'any',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids'
        ));
        foreach ($posts as $post_id) {
            $existing_slugs[] = str_replace(home_url(), '', get_permalink($post_id));
        }

        $taxonomies = get_taxonomies(array(
            'public' => true
        ), 'names');

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(array(
                'taxonomy' => $taxonomy,
                'hide_empty' => true,
                'fields' => 'ids'
            ));
            foreach ($terms as $term_id) {
                $term_link = get_term_link($term_id);
                if (!is_wp_error($term_link)) {
                    $existing_slugs[] = str_replace(home_url(), '', $term_link);
                }
            }
        }

        /*  Add slugs with and without trailing slash */
        $existing_slugs_copy = $existing_slugs;
        foreach ($existing_slugs_copy as $slug) {
            $existing_slugs[] = $this->get_alternative_url($slug);
        }

        $existing_slugs = array_unique($existing_slugs);

        return $existing_slugs;
    }

    /* ----------------------------------------------------------
      Admin notice if a redirection exists for the current URL slug
    ---------------------------------------------------------- */

    public function notice_slug_match_redirection() {
        global $pagenow;
        if ($pagenow != 'post.php' || !isset($_GET['post']) || !isset($_GET['action']) || $_GET['action'] !== 'edit') {
            return;
        }
        $post_id = intval($_GET['post']);
        $slug = str_replace(home_url(), '', get_permalink($post_id));
        if (!$slug) {
            return;
        }
        $slugs_to_check = array(
            $slug,
            $this->get_alternative_url($slug)
        );
        $existing_redirections = $this->get_existing_redirections();
        foreach ($slugs_to_check as $slug_to_check) {
            if (in_array($slug_to_check, $existing_redirections)) {
                echo '<div class="notice notice-error">';
                echo wpautop(sprintf(__('A redirection is configured for the current URL : %s.<br />You won’t be able to access the content.', 'wpu_redirection_extended'), '<strong>' . wp_strip_all_tags($slug) . '</strong>'));
                echo '</div>';
            }
        }

    }

    /* ----------------------------------------------------------
      Widgets
    ---------------------------------------------------------- */

    public function add_dashboard_widgets() {
        if (!current_user_can($this->user_level)) {
            return;
        }
        if (!$this->is_redirection_configured()) {
            return;
        }
        /* Top 404 from bots */
        wp_add_dashboard_widget(
            'wpu_redirection_extended_top_404_bots',
            __('Top 404 Errors from Bots', 'wpu_redirection_extended'),
            array(&$this, 'wpu_redirection_extended_top_404_bots_dashboard_widget__content')
        );
        /* Top 404 on files */
        wp_add_dashboard_widget(
            'wpu_redirection_extended_top_404_files',
            __('Top 404 Errors on Files', 'wpu_redirection_extended'),
            array(&$this, 'wpu_redirection_extended_top_404_files_dashboard_widget__content')
        );

    }

    public function wpu_redirection_extended_top_404_bots_dashboard_widget__content() {
        global $wpdb;
        echo $this->wpu_redirection_get_widget_content($wpdb->get_results("
            SELECT COUNT(*) AS result_count, url
            FROM {$wpdb->prefix}redirection_404
            WHERE
                agent LIKE '%bot%'
                OR ip LIKE '66.249%'
            GROUP BY url
            ORDER BY result_count DESC
            LIMIT 10;
        "), '&filterby%5Bagent%5D=bot&groupby=url');
    }

    public function wpu_redirection_extended_top_404_files_dashboard_widget__content() {
        global $wpdb;
        echo $this->wpu_redirection_get_widget_content($wpdb->get_results("
            SELECT COUNT(*) AS result_count, url
            FROM {$wpdb->prefix}redirection_404
            WHERE
                url LIKE '%.pdf%'
                OR url LIKE '%.jpg%'
                OR url LIKE '%.png%'
                OR url LIKE '%.jpeg%'
                OR url LIKE '%.gif%'
                OR url LIKE '%.mp4%'
            GROUP BY url
            ORDER BY result_count DESC
            LIMIT 10;
        "));
    }

    public function wpu_redirection_get_widget_content($lines, $search_param = '') {
        if (empty($lines)) {
            return '<p>' . __('No data found.', 'wpu_redirection_extended') . '</p>';

        }
        $html = '';
        $html .= $this->basetoolbox->admin_widget_build_table($lines, array(
            'columns' => array(
                __('Hits', 'wpu_redirection_extended'),
                __('URL', 'wpu_redirection_extended')
            )
        ));

        $html .= '<p><a class="button" href="' . admin_url('tools.php?page=redirection.php&sub=404s' . $search_param) . '">';
        $html .= __('See all errors', 'wpu_redirection_extended');
        $html .= '</a></p>';
        return $html;
    }

    /* ----------------------------------------------------------
      Helpers
    ---------------------------------------------------------- */

    /* Get alternative URL ending */
    public function get_alternative_url($url) {
        if (substr($url, -1) === '/') {
            return rtrim($url, '/');
        } else {
            return $url . '/';
        }
    }

    /* Is redirection configured */
    public function is_redirection_configured() {
        if (!defined('REDIRECTION_DB_VERSION')) {
            return false;
        }

        global $wpdb;
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}redirection_items'") === "{$wpdb->prefix}redirection_items";
        if (!$table_exists) {
            return false;
        }
        return true;
    }

    /* Field */
    public function get_admin_field_html($field_id, $field = array()) {
        $html = '';
        if (!is_array($field)) {
            return '';
        }
        $field = array_merge(array(
            'label' => $field_id,
            'type' => 'text'
        ), $field);

        if (!isset($field['label_checkbox'])) {
            $field['label_checkbox'] = $field['label'];
        }

        $field_html = '';
        switch ($field['type']) {
        case 'checkbox':
            $field_html .= '<input type="checkbox" name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '" value="1" />';
            $field_html .= '<label for="' . esc_attr($field_id) . '">' . esc_html($field['label_checkbox']) . '</label>';
            break;
        case 'upload':
            $field_html .= '<input type="file" name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '" />';
            break;
        case 'text':
        default:
            $field_html .= '<input type="text" name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '" class="regular-text" />';
        }

        $html .= '<tr>';
        $html .= '<th scope="row"><label for="' . esc_attr($field_id) . '">' . esc_html($field['label']) . '</label></th>';
        $html .= '<td>' . $field_html . '</td>';
        $html .= '</tr>';

        return $html;
    }

}

$WPURedirectionExtended = new WPURedirectionExtended();
