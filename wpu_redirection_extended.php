<?php
/*
Plugin Name: WPU Redirection Extended
Plugin URI: https://github.com/WordPressUtilities/wpu_redirection_extended
Update URI: https://github.com/WordPressUtilities/wpu_redirection_extended
Description: Enhance the Redirection plugin with additional features.
Version: 0.3.0
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
    private $plugin_version = '0.3.0';
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
        echo '<tr>';
        echo '<th scope="row"><label for="upload_file">' . __('CSV File', 'wpu_redirection_extended') . '</label></th>';
        echo '<td><input type="file" accept="text/csv" name="upload_file"  id="upload_file" /></td>';
        echo '</tr>';
        echo '</table>';
        submit_button(__('Upload', 'wpu_redirection_extended'), 'primary', 'submit_upload_csv');

        echo '<hr />';
        echo '<h2>' . __('Clean database', 'wpu_redirection_extended') . '</h2>';
        echo '<p>' . __('Delete 404 logs where redirections exist or are not useful.', 'wpu_redirection_extended') . '</p>';
        submit_button(__('Clean', 'wpu_redirection_extended'), 'primary', 'submit_clean_database');

    }

    public function page_action__main() {

        if (isset($_POST['submit_upload_csv'])) {
            $this->page_action__main__submit_csv();
        }

        if (isset($_POST['submit_clean_database'])) {
            global $wpdb;
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

    public function page_action__main__submit_csv() {

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
            $this->set_message('csv_upload_error', __('The uploaded file is not a valid CSV.', 'wpu_redirection_extended'));
        }

        $csv_values = array();
        $line_number = 0;
        $handle = fopen($_FILES['upload_file']['tmp_name'], 'r');

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
                if ($first_val == 'before' || $first_val == 'from' || $first_val == 'source' || $first_val == 'url') {
                    continue;
                }
            }

            $before = mb_convert_encoding($row[0], 'UTF-8', 'UTF-8, ISO-8859-1, ISO-8859-15, Windows-1252');
            $after = mb_convert_encoding($row[1], 'UTF-8', 'UTF-8, ISO-8859-1, ISO-8859-15, Windows-1252');

            /* Ignore lines with spaces */
            if (preg_match('/\s/', $before . $after)) {
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
                continue;
            }

            /* Ignore line where before is / */
            if ($before === '/') {
                continue;
            }

            $csv_values[] = array(
                'before' => $before,
                'after' => $after
            );
        }

        fclose($handle);
        $this->basetoolbox->export_array_to_csv($csv_values, 'validated_redirections.csv', array(
            'add_keys' => false
        ));
    }

    /* ----------------------------------------------------------
      Widgets
    ---------------------------------------------------------- */

    public function add_dashboard_widgets() {
        if (!current_user_can($this->user_level)) {
            return;
        }
        wp_add_dashboard_widget(
            'wpu_redirection_extended_top_404_bots',
            __('Top 404 Errors from Bots', 'wpu_redirection_extended'),
            array(&$this, 'wpu_redirection_extended_top_404_bots_dashboard_widget__content')
        );
    }

    public function wpu_redirection_extended_top_404_bots_dashboard_widget__content() {
        global $wpdb;
        $lines = $wpdb->get_results("
            SELECT COUNT(*) AS result_count, url
            FROM {$wpdb->prefix}redirection_404
            WHERE
                agent LIKE '%bot%'
                OR ip LIKE '66.249%'
            GROUP BY url
            ORDER BY result_count DESC
            LIMIT 10;
        ");

        echo $this->basetoolbox->admin_widget_build_table($lines, array(
            'columns' => array(
                __('Hits', 'wpu_redirection_extended'),
                __('URL', 'wpu_redirection_extended')
            )
        ));

        echo '<p><a class="button" href="' . admin_url('tools.php?page=redirection.php&sub=404s&filterby%5Bagent%5D=bot&groupby=url') . '">';
        echo __('See all errors', 'wpu_redirection_extended');
        echo '</a></p>';

    }

}

$WPURedirectionExtended = new WPURedirectionExtended();
