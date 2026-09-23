<?php
/*
Plugin Name: WPU Redirection Extended
Plugin URI: https://github.com/WordPressUtilities/wpu_redirection_extended
Update URI: https://github.com/WordPressUtilities/wpu_redirection_extended
Description: Enhance the Redirection plugin with additional features.
Version: 0.23.2
Author: darklg
Author URI: https://darklg.me/
Text Domain: wpu_redirection_extended
Domain Path: /lang
Requires at least: 6.2
Requires PHP: 8.0
Requires Plugins: redirection
Network: Optional
License: MIT License
License URI: https://opensource.org/licenses/MIT
*/

if (!defined('ABSPATH')) {
    exit();
}

class WPURedirectionExtended {
    private $plugin_version = '0.23.2';
    private $plugin_settings = array(
        'id' => 'wpu_redirection_extended',
        'name' => 'WPU Redirection Extended'
    );
    private $user_level = 'wpu_redirection_extended_access';
    private $basetoolbox;
    private $messages;
    private $adminpages;
    private $settings;
    private $settings_details;
    private $basenotify;
    private $widget_types = array();
    private $redirection_issues = array();

    public function __construct() {
        add_action('init', array(&$this, 'load_translation'));
        add_action('init', array(&$this, 'load_toolbox'));
        add_action('init', array(&$this, 'load_admin_page'));
        add_action('init', array(&$this, 'load_messages'));
        add_action('init', array(&$this, 'load_settings'));
        add_action('init', array(&$this, 'check_dependencies'));
        add_action('init', array(&$this, 'set_custom_roles'), 11);
        add_action('init', array(&$this, 'load_widget_types'));
        add_action('wp_dashboard_setup', array(&$this, 'add_dashboard_widgets'));
        add_action('admin_notices', array(&$this, 'notice_404_spike_retention'));
        add_action('admin_menu', array(&$this, 'set_admin_menus'), 10);
        add_action('edit_form_after_title', array(&$this, 'notice_slug_match_redirection'));
        add_action('admin_init', array(&$this, 'notice_slug_match_redirection__all_terms'));
        add_action('add_meta_boxes', array(&$this, 'add_metabox_incoming_redirections'));
        add_action('admin_init', array(&$this, 'handle_widget_csv_download'));
        add_action('admin_enqueue_scripts', array(&$this, 'enqueue_admin_scripts'));

        /* 404 spike alert */
        add_action('init', array(&$this, 'schedule_404_spike_check'));
        add_action('wpu_redirection_extended_check_404_spike', array(&$this, 'check_404_spike'));
        register_deactivation_hook(__FILE__, array(&$this, 'unschedule_404_spike_check'));

        /* Hooks for WP-CLI */
        add_action('wpu_redirection_extended_clean_database', array(&$this,
            'page_action__main__submit_clean_database'
        ));

        /* Redirection settings */
        add_filter('redirection_role', function ($role) {
            return $this->user_level;
        });
        add_filter('rest_request_after_callbacks', array(&$this, 'extend_redirect_autocomplete'), 10, 3);

        /* Front 404 quick-redirect form */
        add_action('wp_footer', array(&$this, 'display_404_redirect_form'));
        add_action('admin_post_wpu_redir_ext_create_404', array(&$this, 'handle_404_redirect_form'));
    }

    # REDIRECT AUTOCOMPLETE
    public function extend_redirect_autocomplete($response, $handler, $request) {
        if (!is_a($request, 'WP_REST_Request') || $request->get_route() !== '/redirection/v1/redirect/post') {
            return $response;
        }
        if (is_wp_error($response)) {
            return $response;
        }

        $search = sanitize_text_field((string) $request->get_param('text'));
        if ($search === '') {
            return $response;
        }

        $response = rest_ensure_response($response);
        $data = (array) $response->get_data();

        $post_types = get_post_types(array('public' => true), 'names');
        unset($post_types['post'], $post_types['page'], $post_types['attachment']);
        if (!empty($post_types)) {
            $query = new WP_Query(array(
                'post_type' => array_values($post_types),
                'post_status' => 'publish',
                's' => $search,
                'posts_per_page' => 10,
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'suppress_filters' => false
            ));
            foreach ($query->posts as $post) {
                $permalink = get_permalink($post);
                if (!$permalink) {
                    continue;
                }
                $data[] = array(
                    'title' => html_entity_decode(get_the_title($post) . ' (' . $post->post_type . ')', ENT_QUOTES, 'UTF-8'),
                    'value' => wp_make_link_relative($permalink)
                );
            }
        }

        $taxonomies = get_taxonomies(array('public' => true), 'names');
        if (!empty($taxonomies)) {
            $terms = get_terms(array(
                'taxonomy' => array_values($taxonomies),
                'search' => $search,
                'hide_empty' => false,
                'number' => 10
            ));
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $link = get_term_link($term);
                    if (is_wp_error($link)) {
                        continue;
                    }
                    $data[] = array(
                        'title' => html_entity_decode($term->name . ' (' . $term->taxonomy . ')', ENT_QUOTES, 'UTF-8'),
                        'value' => wp_make_link_relative($link)
                    );
                }
            }
        }

        $response->set_data($data);
        return $response;
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
        $default_string = ($group ? $group . ' - ' : '') . $id . ' - ' . $message;
        if (php_sapi_name() === 'cli') {
            echo wp_strip_all_tags($default_string) . PHP_EOL;
            return;
        }
        if (!$this->messages) {
            error_log($default_string);
            return;
        }
        $this->messages->set_message($id, $message, $group);
    }

    # SETTINGS
    public function load_settings() {
        $this->settings_details = array(
            /* Rendered inside the "settings" tab of the main admin page, not in a page of its own */
            'create_page' => false,
            /* Must match the admin page slug so is_admin_page detection works */
            'plugin_id' => $this->plugin_settings['id'] . '-main',
            'option_id' => $this->plugin_settings['id'] . '_options',
            'plugin_name' => $this->plugin_settings['name'],
            'user_cap' => $this->user_level,
            'sections' => array()
        );

        require_once __DIR__ . '/inc/WPUBaseNotify/WPUBaseNotify.php';
        $this->basenotify = new \wpu_redirection_extended\WPUBaseNotify(array(
            'option_id' => $this->settings_details['option_id'],
            'plugin_name' => $this->plugin_settings['name'],
            'user_cap' => $this->user_level,
            'notifications' => array(
                '404_spike' => array(
                    'label' => __('404 spike', 'wpu_redirection_extended'),
                    'help' => __('Sent when the number of 404 errors of the previous day exceeds twice the average of the days before.', 'wpu_redirection_extended')
                )
            )
        ));

        $this->settings_details['sections'] += $this->basenotify->get_settings_section();
        $settings = $this->basenotify->get_settings_fields();

        /* Settings screen is the only consumer of WPUBaseSettings : notifications are sent front-side */
        if (!is_admin()) {
            return;
        }

        require_once __DIR__ . '/inc/WPUBaseSettings/WPUBaseSettings.php';
        $this->settings = new \wpu_redirection_extended\WPUBaseSettings($this->settings_details, $settings);
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
      Widget Types
    ---------------------------------------------------------- */

    public function load_widget_types() {
        global $wpdb;
        $this->widget_types = apply_filters('wpu_redirection_extended_widget_types', array(
            'bots' => array(
                'label' => __('Top 404 Errors from Bots', 'wpu_redirection_extended'),
                'search_param' => '&filterby%5Bagent%5D=bot&groupby=url',
                'query' => "SELECT COUNT(*) AS result_count, url
                    FROM {$wpdb->prefix}redirection_404
                    WHERE {$this->get_bots_sql_predicate()}
                    GROUP BY url"
            ),
            'files' => array(
                'label' => __('Top 404 Errors on Files', 'wpu_redirection_extended'),
                'query' => "SELECT COUNT(*) AS result_count, url
                    FROM {$wpdb->prefix}redirection_404
                    WHERE url LIKE '%.pdf%'
                        OR url LIKE '%.jpg%'
                        OR url LIKE '%.png%'
                        OR url LIKE '%.jpeg%'
                        OR url LIKE '%.gif%'
                        OR url LIKE '%.mp4%'
                    GROUP BY url"
            ),
            'utm' => array(
                'label' => __('Top 404 Errors with UTM Source', 'wpu_redirection_extended'),
                'search_param' => '&filterby%5Burl%5D=utm_',
                'query' => "SELECT COUNT(*) AS result_count, SUBSTRING_INDEX(url, '?', 1) AS url
                    FROM {$wpdb->prefix}redirection_404
                    WHERE url LIKE '%\?utm_%'
                    GROUP BY SUBSTRING_INDEX(url, '?', 1)"
            )
        ));
    }

    /* ----------------------------------------------------------
      404 spike alert
    ---------------------------------------------------------- */

    # 404 SPIKE

    /* Shared by the "bots" dashboard widget and the spike breakdown : keep a single definition */
    public function get_bots_sql_predicate() {
        return "agent LIKE '%bot%' OR ip LIKE '66.249%'";
    }

    public function schedule_404_spike_check() {
        if (wp_next_scheduled('wpu_redirection_extended_check_404_spike')) {
            return;
        }
        /* Tomorrow 8am, site time : the alert talks about "yesterday", so the hour has to be stable */
        $local_start = strtotime('tomorrow 08:00', current_time('timestamp'));
        $utc_start = $local_start - intval(get_option('gmt_offset') * HOUR_IN_SECONDS);
        wp_schedule_event($utc_start, 'daily', 'wpu_redirection_extended_check_404_spike');
    }

    public function unschedule_404_spike_check() {
        wp_clear_scheduled_hook('wpu_redirection_extended_check_404_spike');
    }

    /* How many baseline days the Redirection log retention actually allows.
       0 means the alert cannot run. */
    public function get_404_spike_baseline_days() {
        $options = get_option('redirection_options');
        $expire = isset($options['expire_404']) ? intval($options['expire_404']) : 7;
        /* -1 : 404 logging is disabled */
        if ($expire < 0) {
            return 0;
        }
        /* 0 : logs are kept forever */
        $days = $expire === 0 ? 8 : min(8, $expire - 2);
        return $days >= 3 ? $days : 0;
    }

    /* The retention conflict is a pure function of the Redirection option : recompute it
       instead of storing a flag the cron would have to keep in sync. */
    public function notice_404_spike_retention() {
        if (!current_user_can($this->user_level)) {
            return;
        }
        if (!$this->basenotify || !$this->basenotify->is_enabled('404_spike')) {
            return;
        }
        if ($this->get_404_spike_baseline_days()) {
            return;
        }
        echo '<div class="notice notice-warning"><p>';
        echo esc_html(__('WPU Redirection Extended : the 404 spike alert is disabled because the Redirection 404 logs are not kept long enough. Set the 404 log retention to at least 5 days in the Redirection options.', 'wpu_redirection_extended'));
        echo '</p></div>';
    }

    public function check_404_spike() {
        global $wpdb;

        /* Cheap guard before any query */
        if (!$this->basenotify || !$this->basenotify->is_enabled('404_spike')) {
            return;
        }

        $now = current_time('timestamp');
        $today = date('Y-m-d', $now);
        $option_last = $this->plugin_settings['id'] . '_404_spike_last_notified';
        /* WP-Cron can fire twice the same morning : one alert per day, whatever happens */
        if (get_option($option_last) === $today) {
            return;
        }

        $baseline_days = $this->get_404_spike_baseline_days();
        if (!$baseline_days) {
            return;
        }

        $yesterday = date('Y-m-d', strtotime('-1 day', $now));
        $baseline_start = date('Y-m-d', strtotime('-' . ($baseline_days + 1) . ' days', $now));
        $baseline_end = date('Y-m-d', strtotime('-2 days', $now));

        /* Literal % of the bot predicate have to be doubled to survive wpdb::prepare() */
        $bots_predicate = str_replace('%', '%%', $this->get_bots_sql_predicate());
        $stats = $wpdb->get_row($wpdb->prepare("SELECT
                SUM(CASE WHEN DATE(created) = %s THEN 1 ELSE 0 END) AS day_count,
                SUM(CASE WHEN DATE(created) = %s AND ({$bots_predicate}) THEN 1 ELSE 0 END) AS day_bots,
                SUM(CASE WHEN DATE(created) < %s THEN 1 ELSE 0 END) AS baseline_count
            FROM {$wpdb->prefix}redirection_404
            WHERE created >= %s AND created < %s",
            $yesterday,
            $yesterday,
            $yesterday,
            $baseline_start . ' 00:00:00',
            $today . ' 00:00:00'
        ));

        if (!$stats) {
            return;
        }

        $day_count = intval($stats->day_count);
        $day_bots = intval($stats->day_bots);
        /* Divided by the full number of days : days without a single 404 have no row but still count */
        $average = intval($stats->baseline_count) / $baseline_days;

        $thresholds = apply_filters('wpu_redirection_extended_404_spike_thresholds', array(
            'ratio' => 2,
            'min_count' => 20
        ));
        $ratio = isset($thresholds['ratio']) ? floatval($thresholds['ratio']) : 2;
        $min_count = isset($thresholds['min_count']) ? intval($thresholds['min_count']) : 20;

        if ($day_count < $min_count) {
            return;
        }
        if ($day_count <= $average * $ratio) {
            return;
        }

        $this->basenotify->notify('404_spike',
            sprintf(__('[%s] 404 spike : %s yesterday vs %s on average (%s days)', 'wpu_redirection_extended'),
                wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                number_format_i18n($day_count),
                number_format_i18n($average, 1),
                $baseline_days
            ),
            $this->get_404_spike_message($yesterday, $day_count, $day_bots, $average, $ratio, $baseline_days, $baseline_start, $baseline_end)
        );

        update_option($option_last, $today, false);
    }

    /* Only built once the alert is triggered : the extra query costs nothing on a normal day */
    private function get_404_spike_message($yesterday, $day_count, $day_bots, $average, $ratio, $baseline_days, $baseline_start, $baseline_end) {
        global $wpdb;

        $message = sprintf(__('Yesterday (%s) : %s 404 errors, including %s from bots.', 'wpu_redirection_extended'),
            $yesterday,
            number_format_i18n($day_count),
            number_format_i18n($day_bots)
        ) . "\n";
        $message .= sprintf(__('Average of the %s previous days (%s to %s) : %s.', 'wpu_redirection_extended'),
            $baseline_days,
            $baseline_start,
            $baseline_end,
            number_format_i18n($average, 1)
        ) . "\n";
        $message .= sprintf(__('Threshold : average x %s = %s.', 'wpu_redirection_extended'),
            number_format_i18n($ratio, 1),
            number_format_i18n($average * $ratio, 1)
        ) . "\n";

        $top_urls = $wpdb->get_results($wpdb->prepare("SELECT COUNT(*) AS result_count, url
            FROM {$wpdb->prefix}redirection_404
            WHERE DATE(created) = %s
            GROUP BY url
            ORDER BY result_count DESC
            LIMIT 5", $yesterday));

        if ($top_urls) {
            $message .= "\n" . __('Top URLs :', 'wpu_redirection_extended') . "\n";
            foreach ($top_urls as $top_url) {
                $message .= '  ' . $top_url->result_count . '  ' . $top_url->url . "\n";
            }
        }

        $message .= "\n" . admin_url('tools.php?page=redirection.php&sub=404s');

        return $message;
    }

    /* ----------------------------------------------------------
      Roles
    ---------------------------------------------------------- */

    public function set_custom_roles() {
        $roles_to_update = apply_filters('wpu_redirection_extended__roles_to_update', array('super_editor', 'administrator'));
        foreach ($roles_to_update as $role) {
            $this->update_role($role);
        }
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
        /* Avoid a DB write on every load: only add the cap if missing */
        if ($role->has_cap($this->user_level)) {
            return;
        }
        $role->add_cap($this->user_level, true);
    }

    /* ----------------------------------------------------------
      Admin Page: Main
    ---------------------------------------------------------- */

    public function page_content__main() {
        $tabs = array(
            'csv' => array(
                'label' => __('CSV', 'wpu_redirection_extended'),
                'templates' => array('admin-page-section-csv.php')
            ),
            'sitemap' => array(
                'label' => __('Sitemap', 'wpu_redirection_extended'),
                'templates' => array('admin-page-section-sitemap.php')
            )
        );

        if ($this->is_redirection_configured()) {
            $tabs['cleanup'] = array(
                'label' => __('Cleanup', 'wpu_redirection_extended'),
                'templates' => array('admin-page-section-clean-database.php', 'admin-page-section-clean-redirections.php')
            );
            $tabs['404'] = array(
                'label' => __('404 errors', 'wpu_redirection_extended'),
                'templates' => array('admin-page-section-404-graph.php', 'admin-page-section-widgets.php')
            );
            $tabs['misc'] = array(
                'label' => __('Misc', 'wpu_redirection_extended'),
                'templates' => array('admin-page-section-recommended-settings.php')
            );
        }

        $tabs['settings'] = array(
            'label' => __('Settings', 'wpu_redirection_extended'),
            'templates' => array('admin-page-section-settings.php')
        );

        $first = true;
        echo '<h2 class="nav-tab-wrapper" id="wre-tabs-nav">';
        foreach ($tabs as $tab_id => $tab) {
            echo '<a class="nav-tab' . ($first ? ' nav-tab-active' : '') . '" href="#wre-tab-' . esc_attr($tab_id) . '"' . ($first ? ' aria-current="true"' : '') . '>' . esc_html($tab['label']) . '</a>';
            $first = false;
        }
        echo '</h2>';

        $first = true;
        foreach ($tabs as $tab_id => $tab) {
            echo '<div class="wre-tab" id="wre-tab-' . esc_attr($tab_id) . '"' . ($first ? '' : ' hidden') . '>';
            foreach ($tab['templates'] as $template) {
                include __DIR__ . '/inc/tpl/' . $template;
            }
            echo '</div>';
            $first = false;
        }

        include __DIR__ . '/inc/tpl/admin-page-tabs-script.php';
    }

    public function page_action__main() {

        if (isset($_POST['submit_generate_csv_from_sitemap'])) {
            $this->page_action__main__submit_generate_csv_from_sitemap();
        }

        if (isset($_POST['submit_upload_csv']) || isset($_POST['submit_get_errors'])) {
            $this->page_action__main__submit_csv(isset($_POST['submit_get_errors']));
        }

        if (isset($_POST['submit_clean_database'])) {
            $this->page_action__main__submit_clean_database();
        }

        if (isset($_POST['submit_settings']) && $this->settings) {
            $option_id = $this->settings_details['option_id'];
            $values = isset($_POST[$option_id]) && is_array($_POST[$option_id]) ? wp_unslash($_POST[$option_id]) : array();
            $this->settings->update_opt($this->settings->options_validate($values));
            $this->set_message('settings_saved', __('Settings have been saved.', 'wpu_redirection_extended'), 'updated');
        }

        if (isset($_POST['submit_recommended_settings'])) {
            $this->page_action__main__submit_recommended_settings();
        }

        if (isset($_POST['submit_fix_redirection_issues']) || isset($_POST['submit_get_redirection_issues'])) {
            $this->page_action__main__clean_redirections(isset($_POST['submit_get_redirection_issues']));
        }
    }

    public function page_action__main__submit_generate_csv_from_sitemap() {

        $url = isset($_POST['sitemap_url']) ? trim((string) wp_unslash($_POST['sitemap_url'])) : '';
        if (!$url) {
            $this->set_message('sitemap_csv_error', __('Please provide a sitemap URL.', 'wpu_redirection_extended'), 'error');
            return;
        }

        /* Auto-prefix scheme if missing */
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $url = esc_url_raw($url);

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, array('http', 'https'), true)) {
            $this->set_message('sitemap_csv_error', __('The sitemap URL must use http or https.', 'wpu_redirection_extended'), 'error');
            return;
        }

        $max_urls = (int) apply_filters('wpu_redirection_extended__sitemap_max_urls', 50000);
        $max_children = (int) apply_filters('wpu_redirection_extended__sitemap_max_children', 20);
        $http_timeout = (int) apply_filters('wpu_redirection_extended__sitemap_http_timeout', 15);

        @set_time_limit(120);

        /* Discovery mode: path is empty or root-only */
        $url_path = parse_url($url, PHP_URL_PATH);
        $is_root = ($url_path === null || $url_path === '' || $url_path === '/');

        $raw_urls = array();
        $limit_reached = false;

        if ($is_root) {
            $raw_urls = $this->discover_sitemap_urls($url, $max_urls, $max_children, $http_timeout, $limit_reached);
            if (empty($raw_urls)) {
                $this->set_message('sitemap_csv_error', __('No sitemap could be auto-detected for this domain. Please provide the full sitemap URL.', 'wpu_redirection_extended'), 'error');
                return;
            }
        } else {
            $fetch_error = $this->fetch_sitemap_urls($url, $raw_urls, $max_urls, $max_children, $http_timeout, $limit_reached);
            if ($fetch_error) {
                $this->set_message('sitemap_csv_error', $fetch_error, 'error');
                return;
            }
        }

        if (empty($raw_urls)) {
            $this->set_message('sitemap_csv_error', __('No URLs found in the sitemap.', 'wpu_redirection_extended'), 'error');
            return;
        }

        /* Convert absolute URLs to relative paths and deduplicate */
        $sources = array();
        foreach ($raw_urls as $u) {
            $parts = parse_url($u);
            if (!$parts) {
                continue;
            }
            $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
            if (!empty($parts['query'])) {
                $path .= '?' . $parts['query'];
            }
            if (!empty($parts['fragment'])) {
                $path .= '#' . $parts['fragment'];
            }
            $sources[$path] = true;
        }
        $sources = array_keys($sources);

        $exclude_home = isset($_POST['sitemap_exclude_home']) && $_POST['sitemap_exclude_home'] == '1';
        $exclude_slugs = isset($_POST['sitemap_exclude_existing_slugs']) && $_POST['sitemap_exclude_existing_slugs'] == '1';
        $exclude_redirs = isset($_POST['sitemap_exclude_existing_redirections']) && $_POST['sitemap_exclude_existing_redirections'] == '1';

        $existing_slugs = $exclude_slugs ? $this->get_existing_slugs() : array();
        $existing_redirs = $exclude_redirs ? $this->get_existing_redirections() : array();

        $csv_values = array();
        foreach ($sources as $src) {
            if ($exclude_home && ($src === '/' || $src === '')) {
                continue;
            }
            if ($exclude_slugs || $exclude_redirs) {
                $alt = $this->get_alternative_url($src);
                if ($exclude_slugs && (in_array($src, $existing_slugs) || in_array($alt, $existing_slugs))) {
                    continue;
                }
                if ($exclude_redirs && (in_array($src, $existing_redirs) || in_array($alt, $existing_redirs))) {
                    continue;
                }
            }
            $csv_values[] = array('source' => $src, 'target' => '');
        }

        if (empty($csv_values)) {
            $this->set_message('sitemap_csv_error', __('No URLs left after filtering.', 'wpu_redirection_extended'), 'error');
            return;
        }

        if ($limit_reached) {
            $this->set_message('sitemap_csv_warning', sprintf(__('Sitemap URL limit reached (%s). The CSV contains a partial result.', 'wpu_redirection_extended'), '<strong>' . $max_urls . '</strong>'), 'warning');
        }

        $host = parse_url($url, PHP_URL_HOST);
        $host = $host ? preg_replace('/[^a-z0-9.-]/i', '', $host) : 'sitemap';
        $filename = 'redirections-from-sitemap-' . $host . '-' . date('Ymd');

        $this->basetoolbox->export_array_to_csv($csv_values, $filename, array(
            'add_keys' => true,
            'separator' => ';'
        ));
    }

    public function fetch_sitemap_urls($url, &$urls, $max_urls, $max_children, $timeout, &$limit_reached, $depth = 0, $timeout_override = null) {

        if ($timeout_override !== null) {
            $timeout = (int) $timeout_override;
        }

        if (count($urls) >= $max_urls) {
            $limit_reached = true;
            return '';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, array('http', 'https'), true)) {
            return sprintf(__('Skipped sitemap with unsupported scheme (%s).', 'wpu_redirection_extended'), esc_html($url));
        }

        $max_response_size = (int) apply_filters('wpu_redirection_extended__sitemap_max_response_size', 50 * 1024 * 1024);

        $response = wp_remote_get($url, array(
            'timeout' => $timeout,
            'redirection' => 5,
            'limit_response_size' => $max_response_size,
            'user-agent' => 'WPU Redirection Extended Sitemap Fetcher'
        ));

        if (is_wp_error($response)) {
            return sprintf(__('Failed to fetch sitemap (%s): %s', 'wpu_redirection_extended'), esc_html($url), esc_html($response->get_error_message()));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return sprintf(__('Failed to fetch sitemap (%s): HTTP %s', 'wpu_redirection_extended'), esc_html($url), $code);
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            return sprintf(__('Empty response for sitemap (%s).', 'wpu_redirection_extended'), esc_html($url));
        }

        /* Gzip detection */
        $is_gzip = false;
        if (substr($url, -3) === '.gz') {
            $is_gzip = true;
        } elseif (strtolower((string) wp_remote_retrieve_header($response, 'content-encoding')) === 'gzip' && substr($body, 0, 1) !== '<') {
            $is_gzip = true;
        } elseif (strlen($body) >= 2 && substr($body, 0, 2) === "\x1f\x8b") {
            $is_gzip = true;
        }

        if ($is_gzip) {
            if (!function_exists('gzdecode')) {
                return __('Gzipped sitemap detected but PHP zlib extension is not available.', 'wpu_redirection_extended');
            }
            $decoded = @gzdecode($body);
            if ($decoded === false) {
                return sprintf(__('Failed to decode gzipped sitemap (%s).', 'wpu_redirection_extended'), esc_html($url));
            }
            $body = $decoded;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return sprintf(__('Invalid XML in sitemap (%s).', 'wpu_redirection_extended'), esc_html($url));
        }

        $name = strtolower($xml->getName());

        if ($name === 'sitemapindex') {
            if ($depth > 0) {
                /* Don't follow nested sitemap indexes */
                return '';
            }
            $count = 0;
            foreach ($xml->sitemap as $entry) {
                if ($count >= $max_children) {
                    break;
                }
                $loc = trim((string) $entry->loc);
                if ($loc === '') {
                    continue;
                }
                $count++;
                $this->fetch_sitemap_urls($loc, $urls, $max_urls, $max_children, $timeout, $limit_reached, $depth + 1);
                if ($limit_reached) {
                    break;
                }
            }
            return '';
        }

        if ($name === 'urlset') {
            foreach ($xml->url as $entry) {
                if (count($urls) >= $max_urls) {
                    $limit_reached = true;
                    break;
                }
                $loc = trim((string) $entry->loc);
                if ($loc !== '') {
                    $urls[] = $loc;
                }
            }
            return '';
        }

        return sprintf(__('Unrecognized sitemap format (%s).', 'wpu_redirection_extended'), esc_html($url));
    }

    /**
     * Parse a robots.txt body and return all declared Sitemap: URLs.
     * Relative URLs are resolved against $base_url (scheme + host).
     *
     * @param string $body     Raw robots.txt content.
     * @param string $base_url Scheme + host (e.g. https://example.com).
     * @return string[]
     */
    private function parse_robots_txt_sitemaps(string $body, string $base_url): array {
        $sitemaps = array();
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            /* Strip inline comments */
            $hash_pos = strpos($line, '#');
            if ($hash_pos !== false) {
                $line = rtrim(substr($line, 0, $hash_pos));
            }
            if (!preg_match('/^sitemap\s*:\s*(.+)$/i', $line, $matches)) {
                continue;
            }
            $candidate = trim($matches[1]);
            if ($candidate === '') {
                continue;
            }
            /* Resolve relative URLs */
            if (!preg_match('#^https?://#i', $candidate)) {
                $candidate = rtrim($base_url, '/') . '/' . ltrim($candidate, '/');
            }
            $sitemaps[] = $candidate;
        }
        return $sitemaps;
    }

    /**
     * Auto-discover sitemap URLs for a domain root.
     *
     * @param string $domain_url   Full URL of the domain root (e.g. https://example.com).
     * @param int    $max_urls     Maximum total URLs to collect.
     * @param int    $max_children Maximum child sitemaps to follow.
     * @param int    $http_timeout Normal parsing timeout (seconds).
     * @param bool   $limit_reached Passed by reference; set to true if max_urls was hit.
     * @return string[] Collected page URLs (empty on total failure).
     */
    private function discover_sitemap_urls(string $domain_url, int $max_urls, int $max_children, int $http_timeout, bool &$limit_reached): array {
        $discovery_timeout = (int) apply_filters('wpu_redirection_extended__sitemap_discovery_timeout', 5);
        $fallback_paths = (array) apply_filters('wpu_redirection_extended__sitemap_discovery_paths', array(
            '/wp-sitemap.xml',
            '/sitemap_index.xml',
            '/sitemap.xml',
            '/sitemap.xml.gz'
        ));

        $parsed = parse_url($domain_url);
        $base_url = $parsed['scheme'] . '://' . $parsed['host'];
        if (!empty($parsed['port'])) {
            $base_url .= ':' . $parsed['port'];
        }

        $all_urls = array();

        /* Step 1: try robots.txt */
        $robots_url = rtrim($base_url, '/') . '/robots.txt';
        $robots_response = wp_remote_get($robots_url, array(
            'timeout' => $discovery_timeout,
            'redirection' => 3,
            'user-agent' => 'WPU Redirection Extended Sitemap Fetcher'
        ));

        $robots_sitemaps = array();
        if (!is_wp_error($robots_response) && (int) wp_remote_retrieve_response_code($robots_response) === 200) {
            $robots_body = wp_remote_retrieve_body($robots_response);
            if ($robots_body !== '') {
                $robots_sitemaps = $this->parse_robots_txt_sitemaps($robots_body, $base_url);
            }
        }

        /* Step 2: fetch all sitemaps declared in robots.txt and merge */
        foreach ($robots_sitemaps as $sitemap_url) {
            $probe_urls = array();
            $probe_reached = false;
            $this->fetch_sitemap_urls($sitemap_url, $probe_urls, $max_urls - count($all_urls), $max_children, $http_timeout, $probe_reached);
            if (!empty($probe_urls)) {
                foreach ($probe_urls as $u) {
                    if (!in_array($u, $all_urls, true)) {
                        $all_urls[] = $u;
                    }
                    if (count($all_urls) >= $max_urls) {
                        $limit_reached = true;
                        break 2;
                    }
                }
            }
        }

        if (!empty($all_urls)) {
            return $all_urls;
        }

        /* Step 3: fallback paths — first one with ≥1 URL wins */
        foreach ($fallback_paths as $path) {
            $candidate_url = rtrim($base_url, '/') . '/' . ltrim((string) $path, '/');
            $probe_urls = array();
            $this->fetch_sitemap_urls($candidate_url, $probe_urls, $max_urls, $max_children, $discovery_timeout, $limit_reached);
            if (!empty($probe_urls)) {
                return $probe_urls;
            }
        }

        return array();
    }

    public function page_action__main__submit_clean_database() {

        global $wpdb;

        if (!$this->is_redirection_configured()) {
            $this->set_message('database_cleaned', __('Redirection plugin is not configured.', 'wpu_redirection_extended'), 'error');
            return;
        }

        $urls_like = apply_filters('wpu_redirection_extended__submit_clean_database__url_like_conditions', array(
            "url LIKE '%.php%'",
            "url LIKE '%.key%'",
            "url LIKE '%.ini%'",
            "url LIKE '%.js.map%'",
            "url LIKE '/@vite%'",
            "url LIKE '/.%'",
            "url LIKE '%admin%'",
            "url LIKE '/.well-known/%'"
        ));

        $urls_like_str = '';
        if (!empty($urls_like)) {
            $urls_like_str = ' OR ' . implode(' OR ', $urls_like);
        }

        $deleted = $wpdb->query("
            DELETE FROM {$wpdb->prefix}redirection_404
            WHERE url IN(
                SELECT url FROM {$wpdb->prefix}redirection_items WHERE match_url != 'regex'
            )
            OR SUBSTRING_INDEX(url, '?', 1) IN(
                SELECT url FROM {$wpdb->prefix}redirection_items WHERE match_url != 'regex' AND (
                    match_data LIKE \"%" . addslashes('"flag_query":"pass"') . "%\"
                    OR match_data LIKE \"%" . addslashes('"flag_query":"ignore"') . "%\"
                )
            )
            " . $urls_like_str);

        if (!$deleted) {
            $this->set_message('database_cleaned', __('No invalid 404 log entries found.', 'wpu_redirection_extended'), 'updated');
            return;
        }
        $this->set_message('database_cleaned', sprintf(__('Deleted %s log entries.', 'wpu_redirection_extended'), '<strong>' . $deleted . '</strong>'), 'updated');
    }

    public function get_recommended_settings() {
        return apply_filters('wpu_redirection_extended_recommended_settings', array(
            'expire_redirect' => array(
                'value' => 7,
                'label' => __('Redirect logs expiry', 'wpu_redirection_extended'),
                'value_label' => __('7 days', 'wpu_redirection_extended')
            ),
            'expire_404' => array(
                'value' => 30,
                'label' => __('404 logs expiry', 'wpu_redirection_extended'),
                'value_label' => __('30 days', 'wpu_redirection_extended')
            ),
            'rest_api' => array(
                'value' => 1,
                'label' => __('REST API', 'wpu_redirection_extended'),
                'value_label' => __('Raw', 'wpu_redirection_extended')
            ),
            'flag_query' => array(
                'value' => 'pass',
                'label' => __('Query parameter matching', 'wpu_redirection_extended'),
                'value_label' => __('ignore and pass all parameters', 'wpu_redirection_extended')
            )
        ));
    }

    public function page_action__main__submit_recommended_settings() {
        if (!class_exists('Red_Options')) {
            $this->set_message('recommended_settings_error', __('The Redirection plugin is not available.', 'wpu_redirection_extended'), 'error');
            return;
        }

        $recommended = $this->get_recommended_settings();

        $settings = array();
        foreach ($recommended as $key => $setting) {
            if (!is_array($setting) || !array_key_exists('value', $setting)) {
                continue;
            }
            $settings[$key] = $setting['value'];
        }

        if (!$settings) {
            $this->set_message('recommended_settings_empty', __('No recommended setting to apply.', 'wpu_redirection_extended'), 'error');
            return;
        }

        $before = Red_Options::get();
        $after = Red_Options::save($settings);

        /* Compare against the saved result: Red_Options clamps and validates values */
        $changed = array();
        foreach ($settings as $key => $value) {
            if (!array_key_exists($key, $before) || !array_key_exists($key, $after)) {
                continue;
            }
            if ($before[$key] === $after[$key]) {
                continue;
            }
            $changed[] = isset($recommended[$key]['label']) ? $recommended[$key]['label'] : $key;
        }

        if (!$changed) {
            $this->set_message('recommended_settings_ok', __('Redirection settings are already up to date.', 'wpu_redirection_extended'), 'updated');
            return;
        }

        $this->set_message('recommended_settings_done', sprintf(__('Updated settings: %s.', 'wpu_redirection_extended'), implode(', ', $changed)), 'updated');
    }

    public function page_action__main__submit_csv($get_errors = false) {

        if (!isset($_FILES['upload_file']) || $_FILES['upload_file']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['upload_file']['tmp_name'])) {
            $this->set_message('csv_upload_error', __('No file uploaded or upload error.', 'wpu_redirection_extended'), 'error');
            return false;
        }

        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($file_info, $_FILES['upload_file']['tmp_name']);
        finfo_close($file_info);

        $allowed_mime_types = array(
            'text/csv',
            'text/plain',
            'application/vnd.ms-excel',
            'application/csv',
            'text/comma-separated-values',
            'application/octet-stream'
        );

        $file_ext = strtolower(pathinfo($_FILES['upload_file']['name'], PATHINFO_EXTENSION));
        if ($file_ext != 'csv' || !in_array($mime_type, $allowed_mime_types)) {
            $this->set_message('csv_upload_error', __('The uploaded file is not a valid CSV.', 'wpu_redirection_extended'), 'error');
            return false;
        }

        $csv_values = array();
        $line_number = 0;
        $handle = fopen($_FILES['upload_file']['tmp_name'], 'r');
        if (!$handle) {
            $this->set_message('csv_upload_error', __('Failed to open the uploaded file.', 'wpu_redirection_extended'), 'error');
            return false;
        }

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

        $excluded_urls = apply_filters('wpu_redirection_extended__submit_csv_excluded_urls', array(
            '/*',
            '/wp-content/plugins/*',
            '/wp-content/themes/*',
            '/wp-content/uploads/*'
        ));
        if (!is_array($excluded_urls)) {
            $excluded_urls = array();
        }

        $errors_list = array();
        $seen_before = array();

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
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

            $before = trim($before);
            $after = trim($after);

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

            /* Avoid after value to end with two / */
            if (substr($after, -2) === '//') {
                $after = rtrim($after, '/');
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

            $alternative_before = $this->get_alternative_url($before);

            /* Filter duplicates within CSV */
            if (in_array($before, $seen_before) || in_array($alternative_before, $seen_before)) {
                $errors_list[] = sprintf(__('Line %s: before value is a duplicate within the CSV.', 'wpu_redirection_extended'), $line_number);
                continue;
            }

            $seen_before[] = $before;
            if ($alternative_before !== $before) {
                $seen_before[] = $alternative_before;
            }

            /* Filter existing slugs */
            if ($filter_existing_slugs && (in_array($before, $existing_slugs) || in_array($alternative_before, $existing_slugs))) {
                $errors_list[] = sprintf(__('Line %s: before value already exists as a slug.', 'wpu_redirection_extended'), $line_number);
                continue;
            }

            /* Filter existing redirections */
            if ($filter_existing_redirections && (in_array($before, $existing_redirections) || in_array($alternative_before, $existing_redirections))) {
                $errors_list[] = sprintf(__('Line %s: before value already exists as a redirection.', 'wpu_redirection_extended'), $line_number);
                continue;
            }

            /* Filter excluded URLs */
            if (in_array($before, $excluded_urls)) {
                $errors_list[] = sprintf(__('Line %s: before value is in the excluded URLs list.', 'wpu_redirection_extended'), $line_number);
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
                $this->set_message('csv_upload_no_errors', __('No errors found in the uploaded file.', 'wpu_redirection_extended'), 'updated');
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

    public function page_action__main__clean_redirections($diagnostic_only = false) {
        global $wpdb;

        if (!$this->is_redirection_configured()) {
            $this->set_message('redirections_cleaned', __('Redirection plugin is not configured.', 'wpu_redirection_extended'), 'error');
            return;
        }

        $redirections = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}redirection_items WHERE status = 'enabled'");

        $issues_found = 0;
        $duplicates_found = 0;
        $unresolvable_found = 0;
        $chained_found = 0;
        $duplicate_ids = $this->page_action__main__clean_redirections__duplicates($redirections, $diagnostic_only, $duplicates_found);
        $slugs_lookup = array_flip($this->get_existing_slugs());
        $redirs_lookup = $diagnostic_only ? array_flip($this->get_existing_redirections()) : array();
        foreach ($redirections as $redirection) {

            /* Skip redirections already flagged as duplicates */
            if (isset($duplicate_ids[$redirection->id])) {
                continue;
            }

            if (strpos($redirection->url, '?') === false) {
                if (!$redirection->match_data) {
                    $redirection->match_data = '{}';
                }
                $match_data = json_decode($redirection->match_data, true);

                /* Invalid flag query */
                if ($this->page_action__main__clean_redirections__invalid_flag_query($redirection, $match_data, $diagnostic_only)) {
                    $issues_found++;
                }
            }

            /* Match an existing slug */
            if ($this->page_action__main__clean_redirections__match_existing($redirection, $slugs_lookup, $diagnostic_only)) {
                $issues_found++;
            }

            /* Target cannot be resolved : warning only, no automatic fix is possible */
            if ($diagnostic_only) {
                $this->page_action__main__clean_redirections__unresolvable_target($redirection, $slugs_lookup, $redirs_lookup, $unresolvable_found, $chained_found);
            }

        }

        if ($duplicates_found > 0) {
            if ($diagnostic_only) {
                $this->set_message('redirection_duplicates', sprintf(__('%s duplicate redirections detected (same source).', 'wpu_redirection_extended'), '<strong>' . $duplicates_found . '</strong>'), 'error');
            } else {
                $this->set_message('redirection_duplicates', sprintf(__('Disabled %s duplicate redirections (same source).', 'wpu_redirection_extended'), '<strong>' . $duplicates_found . '</strong>'), 'updated');
            }
        }

        if ($unresolvable_found > 0) {
            $this->set_message('redirection_unresolvable', sprintf(__('%s redirections target an internal URL that does not match any existing content.', 'wpu_redirection_extended'), '<strong>' . $unresolvable_found . '</strong>'), 'error');
        }

        if ($chained_found > 0) {
            $this->set_message('redirection_chained', sprintf(__('%s redirections target an URL that is itself a redirection.', 'wpu_redirection_extended'), '<strong>' . $chained_found . '</strong>'), 'error');
        }

        if ($diagnostic_only) {
            if ($issues_found == 0) {
                $this->set_message('redirection_issues', __('No redirection issue found.', 'wpu_redirection_extended'), 'updated');
            } else {
                $this->set_message('redirection_issues', sprintf(__('%s redirections have issues that may cause conflicts or unexpected behavior.', 'wpu_redirection_extended'), '<strong>' . $issues_found . '</strong>'), 'error');
            }
            if (!empty($this->redirection_issues)) {
                $issues_html = '';
                foreach ($this->redirection_issues as $redirection_id => $redirection_issue) {
                    $edit_link = admin_url('tools.php?page=redirection.php') . '&' . urlencode('filterby[url]') . '=' . urlencode($redirection_issue['url']);
                    $url_link = '<a href="' . esc_url($edit_link) . '">' . esc_html($redirection_issue['url']) . '</a>';
                    $issues_html .= '<strong>' . sprintf(__('Redirection #%1$s (%2$s)', 'wpu_redirection_extended'), $redirection_id, $url_link) . '</strong>';
                    $issues_html .= '<ul class="ul-disc"><li>' . implode('</li><li>', $redirection_issue['issues']) . '</li></ul>';
                }
                $this->set_message('redirection_issues_list', $issues_html, 'error');
            }
        } else {
            $this->set_message('redirections_cleaned', sprintf(__('Cleaned %s redirections with potential issues.', 'wpu_redirection_extended'), '<strong>' . $issues_found . '</strong>'), 'updated');
        }
    }

    /* Store an issue, grouped by redirection */
    public function add_redirection_issue($redirection, $message) {
        if (!isset($this->redirection_issues[$redirection->id])) {
            $this->redirection_issues[$redirection->id] = array(
                'url' => $redirection->url,
                'issues' => array()
            );
        }
        $this->redirection_issues[$redirection->id]['issues'][] = $message;
    }

    /* Detect duplicate redirections (same url + match_url) and disable extras */
    public function page_action__main__clean_redirections__duplicates($redirections, $diagnostic_only, &$duplicates_found) {
        global $wpdb;

        $groups = array();
        foreach ($redirections as $redirection) {
            $key = $redirection->match_url . '|' . $redirection->url;
            if (!isset($groups[$key])) {
                $groups[$key] = array();
            }
            $groups[$key][] = $redirection;
        }

        $duplicate_ids = array();
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            $keeper = null;
            foreach ($group as $item) {
                if ($item->last_count > 0 && ($keeper === null || $item->id < $keeper->id)) {
                    $keeper = $item;
                }
            }
            if ($keeper === null) {
                foreach ($group as $item) {
                    if ($keeper === null || $item->id < $keeper->id) {
                        $keeper = $item;
                    }
                }
            }

            foreach ($group as $item) {
                if ($item->id == $keeper->id) {
                    continue;
                }
                $duplicate_ids[$item->id] = true;
                $duplicates_found++;
                if ($diagnostic_only) {
                    $this->add_redirection_issue($item, sprintf(__('Is a duplicate of ID %s.', 'wpu_redirection_extended'), $keeper->id));
                } else {
                    $wpdb->update(
                        $wpdb->prefix . 'redirection_items',
                        array('status' => 'disabled'),
                        array('id' => $item->id),
                        array('%s'),
                        array('%d')
                    );
                }
            }
        }

        return $duplicate_ids;
    }

    /* Check if a redirection has an invalid flag query and disable it if not in diagnostic mode */
    public function page_action__main__clean_redirections__invalid_flag_query($redirection, $match_data, $diagnostic_only = false) {
        global $wpdb;

        $has_invalid_flag_query = false;
        if (!isset($match_data['source']) || !is_array($match_data['source']) || !isset($match_data['source']['flag_query']) || $match_data['source']['flag_query'] != 'pass') {
            $has_invalid_flag_query = true;
        }

        if (!$has_invalid_flag_query) {
            return false;
        }

        if ($diagnostic_only) {
            $this->add_redirection_issue($redirection, __('Query parameters are not allowed, which may cause issues.', 'wpu_redirection_extended'));
        } else {
            if (!isset($match_data['source']) || !is_array($match_data['source'])) {
                $match_data['source'] = array();
            }
            $match_data['source']['flag_query'] = 'pass';
            $wpdb->update(
                $wpdb->prefix . 'redirection_items',
                array('match_data' => json_encode($match_data)),
                array('id' => $redirection->id),
                array('%s'),
                array('%d')
            );
        }
        return true;
    }

    /* Check if a redirection matches an existing slug and disable it if not in diagnostic mode */
    public function page_action__main__clean_redirections__match_existing($redirection, $slugs_lookup, $diagnostic_only = false) {
        global $wpdb;
        $redirection_match_existing = false;
        if ($redirection->match_url == 'regex') {
            foreach ($slugs_lookup as $slug => $slug_index) {
                if (@preg_match('#' . $redirection->url . '#', $slug)) {
                    $redirection_match_existing = true;
                    break;
                }
            }
        } else {
            if (isset($slugs_lookup[$redirection->url]) || isset($slugs_lookup[$this->get_alternative_url($redirection->url)])) {
                $redirection_match_existing = true;
            }
        }
        if (!$redirection_match_existing) {
            return false;
        }

        if ($diagnostic_only) {
            $this->add_redirection_issue($redirection, __('Matches an existing slug and may cause conflicts.', 'wpu_redirection_extended'));
        } else {
            $wpdb->update(
                $wpdb->prefix . 'redirection_items',
                array('status' => 'disabled'),
                array('id' => $redirection->id),
                array('%s'),
                array('%d')
            );
        }
        return true;
    }

    /* Check if a redirection targets an internal URL matching no existing content */
    public function page_action__main__clean_redirections__unresolvable_target($redirection, $slugs_lookup, $redirs_lookup, &$unresolvable_found, &$chained_found) {

        if ($redirection->action_type != 'url') {
            return;
        }

        $target = is_string($redirection->action_data) ? trim($redirection->action_data) : '';
        if (!$target) {
            return;
        }

        /* Dynamic target using a regex backreference : cannot be checked */
        if (preg_match('/\$[0-9]/', $target)) {
            return;
        }

        $target = $this->normalize_internal_target($target);
        if ($target === false) {
            return;
        }

        $alt_target = $this->get_alternative_url($target);

        /* Target matches an existing content */
        if (isset($slugs_lookup[$target]) || isset($slugs_lookup[$alt_target])) {
            return;
        }

        /* Target is itself a redirection source */
        if (isset($redirs_lookup[$target]) || isset($redirs_lookup[$alt_target]) || $this->slug_match_regex_redirection($target)) {
            $chained_found++;
            $this->add_redirection_issue($redirection, sprintf(__('Targets %s which is itself a redirection.', 'wpu_redirection_extended'), esc_html($target)));
            return;
        }

        $unresolvable_found++;
        $this->add_redirection_issue($redirection, sprintf(__('Targets %s which does not match any existing content.', 'wpu_redirection_extended'), esc_html($target)));
    }

    /* Convert a redirection target to a comparable relative path, or false if it should be ignored */
    public function normalize_internal_target($target) {

        /* Absolute or protocol relative URL : keep only targets on this site */
        if (preg_match('#^(https?:)?//#i', $target)) {
            $target_host = parse_url($target, PHP_URL_HOST);
            $home_host = parse_url(home_url('/'), PHP_URL_HOST);
            if (!$target_host || !$home_host || strtolower($target_host) !== strtolower($home_host)) {
                return false;
            }
            $target = wp_make_link_relative($target);
        }

        /* Anything else (mailto, anchor, relative path, …) is out of scope */
        if (substr($target, 0, 1) !== '/') {
            return false;
        }

        /* Drop query string & fragment */
        $target = preg_replace('/[?#].*$/', '', $target);
        if ($target === '') {
            $target = '/';
        }

        /* Home page is never part of the existing slugs */
        if ($target === '/') {
            return false;
        }

        $ignored_prefixes = apply_filters('wpu_redirection_extended__internal_target_ignored_prefixes', array(
            'wp-admin',
            'wp-login.php',
            'wp-json',
            'wp-content',
            'feed'
        ));
        $target_start = ltrim($target, '/');
        foreach ($ignored_prefixes as $prefix) {
            if (strpos($target_start, $prefix) === 0) {
                return false;
            }
        }

        return $target;
    }

    public function get_existing_redirection_regex() {
        $cache_id = 'wpu_redirection_extended_existing_redirection_regex';

        $existing_redirection_regex = wp_cache_get($cache_id);
        if ($existing_redirection_regex === false) {
            global $wpdb;
            $existing_redirection_regex = $wpdb->get_col("SELECT url FROM {$wpdb->prefix}redirection_items WHERE match_url = 'regex' and status = 'enabled'");
            wp_cache_set($cache_id, $existing_redirection_regex, '', 60);
        }
        return $existing_redirection_regex;
    }

    public function get_existing_redirections() {
        $cache_id = 'wpu_redirection_extended_existing_redirections';

        $existing_redirections = wp_cache_get($cache_id);
        if ($existing_redirections === false) {
            global $wpdb;
            $existing_redirections = $wpdb->get_col("SELECT match_url FROM {$wpdb->prefix}redirection_items WHERE match_url != 'regex' and status = 'enabled'");
            wp_cache_set($cache_id, $existing_redirections, '', 60);
        }
        return $existing_redirections;
    }

    /* Return a relative URL in every language : language prefix, plus the
       translated base slug when Polylang Pro translates slugs.
       $lang_prefixes is keyed by language slug. */
    public function get_lang_variants($relative_url, $lang_prefixes, $slug_type = '') {
        if (count($lang_prefixes) < 2) {
            return array($relative_url);
        }

        /* Drop an already present language prefix before re-adding each one */
        foreach ($lang_prefixes as $prefix) {
            if ($prefix !== '' && strpos($relative_url, $prefix . '/') === 0) {
                $relative_url = substr($relative_url, strlen($prefix));
                break;
            }
        }

        $urls = array();
        foreach ($lang_prefixes as $lang => $prefix) {
            $urls[] = $prefix . $this->translate_base_slug($relative_url, $lang, $slug_type);
        }
        return $urls;
    }

    /* Rewrite the base slug of a relative URL into $lang, when Polylang Pro
       translates slugs. Idempotent : accepts an already translated URL.
       $slug_type is a PLL_Translate_Slugs_Model type : post type name,
       taxonomy name, or 'archive_<post_type>'. */
    public function translate_base_slug($relative_url, $lang, $slug_type) {
        if (!$slug_type || !$lang || !function_exists('PLL') || !isset(PLL()->translate_slugs->slugs_model)) {
            return $relative_url;
        }

        $lang_obj = PLL()->model->get_language($lang);
        if (!$lang_obj) {
            return $relative_url;
        }

        return PLL()->translate_slugs->slugs_model->switch_translated_slug($relative_url, $lang_obj, $slug_type);
    }

    public function get_existing_slugs() {
        $cache_id = 'wpu_redirection_extended_existing_slugs';
        $existing_slugs = wp_cache_get($cache_id);
        if ($existing_slugs !== false) {
            return $existing_slugs;
        }

        $existing_slugs = array();

        /* Polylang : relative home prefix per language slug, [''] when inactive */
        $lang_prefixes = array('');
        if (function_exists('pll_languages_list') && function_exists('pll_home_url')) {
            $pll_prefixes = array();
            foreach (pll_languages_list() as $lang) {
                $pll_prefixes[$lang] = untrailingslashit(wp_make_link_relative(pll_home_url($lang)));
            }
            if (!empty($pll_prefixes)) {
                $lang_prefixes = $pll_prefixes;
            }
        }

        $public_post_types = get_post_types(array(
            'public' => true
        ), 'names');
        $excluded_post_types = apply_filters('wpu_redirection_extended__excluded_post_types', array(
            'attachment'
        ));
        $post_types_without_archive = apply_filters('wpu_redirection_extended__post_types_without_archive', array(
            'post',
            'page'
        ));
        foreach ($public_post_types as $post_type) {
            if (in_array($post_type, $excluded_post_types)) {
                continue;
            }

            /* Archive */
            $archive_link = get_post_type_archive_link($post_type);
            if ($archive_link && !in_array($post_type, $post_types_without_archive)) {
                /* Archive links are not translated outside of the front-end : build one URL per language */
                $existing_slugs = array_merge($existing_slugs, $this->get_lang_variants(wp_make_link_relative($archive_link), $lang_prefixes, 'archive_' . $post_type));
            }

            /* Posts */
            $posts = get_posts(apply_filters('wpu_redirection_extended__get_existing_slugs_query', array(
                'post_type' => $post_type,
                'post_status' => array('publish', 'private', 'future', 'draft', 'pending'),
                'numberposts' => -1,
                'fields' => 'ids',
                'lang' => ''
            ), $post_type));
            foreach ($posts as $post_id) {
                $post_name = get_post_field('post_name', $post_id);
                if (!$post_name) {
                    continue;
                }
                $post_url = wp_make_link_relative(get_permalink($post_id));
                if (function_exists('pll_get_post_language')) {
                    $post_url = $this->translate_base_slug($post_url, pll_get_post_language($post_id), $post_type);
                }
                $existing_slugs[] = $post_url;
            }
        }

        $taxonomies = get_taxonomies(array(
            'public' => true
        ), 'names');

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(array(
                'taxonomy' => $taxonomy,
                'hide_empty' => true,
                'fields' => 'ids',
                'lang' => ''
            ));
            foreach ($terms as $term_id) {
                $term_link = get_term_link($term_id);
                if (is_wp_error($term_link)) {
                    continue;
                }
                $term_url = wp_make_link_relative($term_link);
                if (function_exists('pll_get_term_language')) {
                    $term_url = $this->translate_base_slug($term_url, pll_get_term_language($term_id), $taxonomy);
                }
                $existing_slugs[] = $term_url;
            }
        }

        /*  Add slugs with and without trailing slash */
        $existing_slugs_copy = $existing_slugs;
        foreach ($existing_slugs_copy as $slug) {
            $existing_slugs[] = $this->get_alternative_url($slug);
        }

        /* Add all PDF files */
        $files = get_posts(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'application/pdf',
            'post_status' => 'inherit',
            'numberposts' => 150,
            'fields' => 'ids'
        ));
        foreach ($files as $file_id) {
            $is_wpucf_att = get_post_meta($file_id, '_wpucontactforms_att', true);
            if ($is_wpucf_att) {
                continue;
            }
            $file_url = wp_get_attachment_url($file_id);
            if ($file_url) {
                $existing_slugs[] = wp_make_link_relative($file_url);
            }
        }

        $existing_slugs = array_unique($existing_slugs);

        wp_cache_set($cache_id, $existing_slugs, '', 60);

        return $existing_slugs;
    }

    /* ----------------------------------------------------------
      Suggest existing slugs that resemble a (404) URI
    ---------------------------------------------------------- */

    /* Return existing slugs ranked by word-similarity to a given URI.
       Bag-of-words matching with fuzzy per-token comparison, scored
       with a symmetric Dice/F1 coverage. Returns [['url','score'], ...]. */
    public function suggest_redirections_for_uri($uri, $limit = 5, $min_score = null) {
        if ($min_score === null) {
            $min_score = (float) apply_filters('wpu_redirection_extended__suggest_min_score', 0.3);
        }

        $uri_path = wp_parse_url($uri, PHP_URL_PATH);
        if (!is_string($uri_path) || $uri_path === '') {
            return array();
        }
        $uri_tokens = $this->tokenize_url_string($uri_path);
        if (empty($uri_tokens)) {
            return array();
        }

        $seen = array();
        $results = array();
        foreach ($this->get_existing_slugs() as $slug) {
            $tokens = $this->tokenize_url_string($slug);
            if (empty($tokens)) {
                continue;
            }

            /* Dedupe candidates sharing the same canonical token set
               (e.g. trailing-slash variants from get_existing_slugs) */
            sort($tokens);
            $key = implode(' ', $tokens);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $score = $this->score_token_sets($uri_tokens, $tokens);
            if ($score < $min_score) {
                continue;
            }
            $results[] = array('url' => $slug, 'score' => round($score, 4));
        }

        usort($results, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($results, 0, max(0, (int) $limit));
    }

    /* Split a URL/path into a deduped set of normalized word tokens */
    public function tokenize_url_string($str) {
        $str = strtolower(remove_accents(urldecode((string) $str)));
        /* Split letter/digit boundaries so "ref123" -> "ref" "123" */
        $str = preg_replace('/([a-z])([0-9])/', '$1 $2', $str);
        $str = preg_replace('/([0-9])([a-z])/', '$1 $2', $str);
        $tokens = preg_split('/[^a-z0-9]+/', $str, -1, PREG_SPLIT_NO_EMPTY);

        $drop = array('html', 'htm', 'php', 'asp', 'aspx', 'jsp', 'shtml', 'index');
        $out = array();
        foreach ($tokens as $token) {
            if (strlen($token) <= 1 || in_array($token, $drop, true)) {
                continue;
            }
            $out[] = $token;
        }
        return array_values(array_unique($out));
    }

    /* F-beta similarity between the 404 token set ($a) and a candidate ($b).
       beta < 1 favours precision (candidate identity present in the 404)
       over recall, so directory noise in the 404 path doesn't sink the score. */
    public function score_token_sets($a, $b) {
        $recall = $this->token_set_coverage($a, $b);
        $precision = $this->token_set_coverage($b, $a);
        if ($precision + $recall <= 0) {
            return 0.0;
        }
        $beta = (float) apply_filters('wpu_redirection_extended__suggest_beta', 0.5);
        $beta2 = $beta * $beta;
        return ((1 + $beta2) * $precision * $recall) / ($beta2 * $precision + $recall);
    }

    /* Length-weighted best-match coverage of $from against the $to set, so
       short navigation tokens (de, ml, cms) weigh less than substantial words */
    private function token_set_coverage($from, $to) {
        if (empty($from)) {
            return 0.0;
        }
        $sum = 0.0;
        $total = 0;
        foreach ($from as $token) {
            $len = strlen($token);
            $sum += $this->best_token_match($token, $to) * $len;
            $total += $len;
        }
        return $total > 0 ? $sum / $total : 0.0;
    }

    /* Best fuzzy match of a token against a set: 1.0 if equal, else the
       similar_text ratio when it clears the threshold, otherwise 0 */
    private function best_token_match($token, $set) {
        $best = 0.0;
        foreach ($set as $other) {
            if ($token === $other) {
                return 1.0;
            }
            $pct = 0.0;
            similar_text($token, $other, $pct);
            $ratio = $pct / 100;
            if ($ratio > $best) {
                $best = $ratio;
            }
        }
        return $best >= 0.7 ? $best : 0.0;
    }

    /* ----------------------------------------------------------
      Admin notice if a redirection exists for the current URL slug
    ---------------------------------------------------------- */

    public function get_current_slug() {
        $slug = false;
        global $pagenow;
        if ($pagenow == 'post.php' && isset($_GET['post'], $_GET['action']) && $_GET['action'] == 'edit') {
            $post_id = intval($_GET['post']);
            $slug = wp_make_link_relative(get_permalink($post_id));
        }
        if ($pagenow == 'term.php' && isset($_GET['tag_ID'], $_GET['taxonomy'])) {
            $term_id = intval($_GET['tag_ID']);
            $taxonomy = sanitize_text_field($_GET['taxonomy']);
            $term_link = get_term_link($term_id, $taxonomy);
            if (!is_wp_error($term_link)) {
                $slug = wp_make_link_relative($term_link);
            }
        }

        return $slug;
    }

    public function notice_slug_match_redirection__all_terms() {
        $taxonomies = get_taxonomies(array(
            'public' => true
        ), 'names');
        foreach ($taxonomies as $taxonomy) {
            add_action($taxonomy . '_term_edit_form_top', array(&$this, 'notice_slug_match_redirection'));
        }
    }

    public function notice_slug_match_redirection() {
        if (!$this->is_redirection_configured()) {
            return;
        }

        $slug = $this->get_current_slug();
        if (!$slug) {
            return;
        }

        $existing_redirections = $this->get_existing_redirections();

        /* Set two versions for current slug */
        $slugs_to_check = array(
            $slug,
            $this->get_alternative_url($slug)
        );
        $error_message = __('<a href="%s">A redirection</a> is configured for the current URL : %s.', 'wpu_redirection_extended');
        $error_message_regex = __('<a href="%s">A regex redirection</a> matches the current URL : %s.', 'wpu_redirection_extended');
        $error_message_end = __('You won’t be able to access the content.', 'wpu_redirection_extended');
        foreach ($slugs_to_check as $slug_to_check) {

            /* A redirection exists */
            if (in_array($slug_to_check, $existing_redirections)) {
                /* Optimise slug for search */
                $slug_search = $slug_to_check;
                if (substr($slug_search, -1) === '/') {
                    $slug_search = rtrim($slug_search, '/');
                }
                /* Display message and stop */
                $url = admin_url('tools.php?page=redirection.php&filterby[url]=' . urlencode($slug_search));
                echo '<div class="notice notice-error">';
                echo wpautop(sprintf($error_message . '<br />' . $error_message_end, esc_url($url), '<strong>' . wp_strip_all_tags($slug) . '</strong>'));
                echo '</div>';
                return;
            }

            /* Check regex redirections */
            if ($this->slug_match_regex_redirection($slug_to_check)) {
                /* Display message and stop */
                $url = admin_url('tools.php?page=redirection.php&filterby[url-match]=regular');
                echo '<div class="notice notice-error">';
                echo wpautop(sprintf($error_message_regex . '<br />' . $error_message_end, esc_url($url), '<strong>' . wp_strip_all_tags($slug) . '</strong>'));
                echo '</div>';
                return;

            }

        }

    }

    /* ----------------------------------------------------------
      Metabox listing redirections pointing to the current URL
    ---------------------------------------------------------- */

    public function add_metabox_incoming_redirections() {
        if (!current_user_can($this->user_level) || !$this->is_redirection_configured()) {
            return;
        }

        $url = $this->get_current_slug();
        if (!$url) {
            return;
        }

        $redirections = $this->get_incoming_redirections($url);
        if (!$redirections) {
            return;
        }

        add_meta_box(
            'wpu_redirection_extended_incoming',
            __('Incoming redirections', 'wpu_redirection_extended'),
            function () use ($redirections) {
                $this->metabox_incoming_redirections_content($redirections);
            }
        );
    }

    public function metabox_incoming_redirections_content($redirections) {
        echo '<ul>';
        foreach ($redirections as $source) {
            $link = admin_url('tools.php?page=redirection.php&filterby[url]=' . urlencode(rtrim($source, '/')));
            echo '<li><a href="' . esc_url($link) . '">' . esc_html($source) . '</a></li>';
        }
        echo '</ul>';
    }

    /* Sources of the enabled redirections targeting this URL */
    public function get_incoming_redirections($url) {
        global $wpdb;

        /* Site root, without the subdirectory part already carried by $url */
        $host = preg_replace('#^(https?://[^/]+).*$#', '$1', home_url('/'));

        /* Relative & absolute URL, with and without a trailing slash */
        $targets = array(
            $url,
            $this->get_alternative_url($url),
            $host . $url,
            $host . $this->get_alternative_url($url)
        );
        $targets = array_unique($targets);

        $placeholders = implode(',', array_fill(0, count($targets), '%s'));

        return $wpdb->get_col($wpdb->prepare("SELECT url
            FROM {$wpdb->prefix}redirection_items
            WHERE status = 'enabled'
              AND action_type = 'url'
              AND action_data IN ($placeholders)
            ORDER BY url ASC", $targets));
    }

    public function slug_match_regex_redirection($slug) {
        $regexes = $this->get_existing_redirection_regex();
        foreach ($regexes as $regex) {
            if (@preg_match('#' . str_replace('#', '\#', $regex) . '#', $slug)) {
                return true;
            }
        }
        return false;
    }

    /* ----------------------------------------------------------
      Graph
    ---------------------------------------------------------- */

    /* Load Chart.js on the plugin admin page only */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'tools_page_' . $this->plugin_settings['id'] . '-main') {
            return;
        }
        wp_enqueue_script('wpu-redirection-extended-chartjs', plugins_url('assets/chart.umd.min.js', __FILE__), array(), '4.5.1', true);
    }

    /* Number of 404 errors for each of the last N days, missing days filled with 0 */
    public function get_404_daily_counts($nb_days = 30) {
        global $wpdb;

        $counts = array();
        for ($i = $nb_days - 1; $i >= 0; $i--) {
            $counts[date('Y-m-d', strtotime('-' . $i . ' days', current_time('timestamp')))] = 0;
        }

        $results = $wpdb->get_results($wpdb->prepare("SELECT DATE(created) AS day, COUNT(*) AS result_count
            FROM {$wpdb->prefix}redirection_404
            WHERE created >= %s
            GROUP BY DATE(created)", array_key_first($counts) . ' 00:00:00'), ARRAY_A);

        foreach ($results as $result) {
            if (isset($counts[$result['day']])) {
                $counts[$result['day']] = (int) $result['result_count'];
            }
        }

        return $counts;
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

        foreach ($this->widget_types as $widget_type => $widget_infos) {
            wp_add_dashboard_widget(
                'wpu_redirection_extended_top_404_' . $widget_type,
                $widget_infos['label'],
                function () use ($widget_type) {
                    echo $this->wpu_redirection_get_widget_content($widget_type);
                }
            );
        }
    }

    public function get_widget_query_results($widget_type, $limit = 0, $output = OBJECT) {
        global $wpdb;
        $limit_sql = $limit > 0 ? ' LIMIT ' . intval($limit) : '';
        $order_sql = ' ORDER BY result_count DESC ';

        $widget_infos = isset($this->widget_types[$widget_type]) ? $this->widget_types[$widget_type] : false;
        if (!$widget_infos || !isset($widget_infos['query'])) {
            return array();
        }
        return $wpdb->get_results($widget_infos['query'] . $order_sql . $limit_sql, $output);
    }

    public function wpu_redirection_get_widget_content($widget_type = '') {
        $lines = $this->get_widget_query_results($widget_type, 10, ARRAY_A);
        if (empty($lines)) {
            return '<p>' . esc_html__('No data found.', 'wpu_redirection_extended') . '</p>';
        }
        $html = '';
        $html .= $this->basetoolbox->admin_widget_build_table($lines, array(
            'columns' => array(
                __('Hits', 'wpu_redirection_extended'),
                __('URL', 'wpu_redirection_extended')
            )
        ));

        if ($widget_type) {
            $widget_infos = isset($this->widget_types[$widget_type]) ? $this->widget_types[$widget_type] : false;
            $html .= '<p>';
            if ($widget_infos && isset($widget_infos['search_param'])) {
                $html .= '<a class="button button-small" href="' . esc_url(admin_url('tools.php?page=redirection.php&sub=404s' . $widget_infos['search_param'])) . '">';
                $html .= esc_html__('See all errors', 'wpu_redirection_extended');
                $html .= '</a>';
            }
            $html .= ' ' . $this->get_widget_download_button($widget_type);
            $html .= '</p>';
        }
        return $html;
    }

    public function get_widget_download_button($widget_type) {
        $download_url = wp_nonce_url(admin_url('index.php?wpu_redir_ext_download_widget=' . $widget_type), 'wpu_redir_ext_download_' . $widget_type);
        return '<a class="button button-small" href="' . esc_url($download_url) . '">' . esc_html__('Export CSV', 'wpu_redirection_extended') . '</a>';
    }

    /* ----------------------------------------------------------
      Helpers
    ---------------------------------------------------------- */

    /* Handle widget CSV download */
    public function handle_widget_csv_download() {
        if (!isset($_GET['wpu_redir_ext_download_widget'])) {
            return;
        }
        $widget_type = sanitize_text_field($_GET['wpu_redir_ext_download_widget']);
        if (!isset($this->widget_types[$widget_type])) {
            return;
        }
        if (!wp_verify_nonce($_GET['_wpnonce'], 'wpu_redir_ext_download_' . $widget_type)) {
            return;
        }
        if (!current_user_can($this->user_level)) {
            return;
        }
        if (!$this->is_redirection_configured()) {
            return;
        }
        $results = $this->get_widget_query_results($widget_type, 0, ARRAY_A);
        if (!$results) {
            return;
        }
        $this->basetoolbox->export_array_to_csv($results, 'top-404-' . $widget_type);
    }

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
        case 'url':
            $field_html .= '<input type="' . esc_attr($field['type']) . '" name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '" class="regular-text" />';
            break;
        default:
            $field_html .= '<input type="text" name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '" class="regular-text" />';
        }

        if (isset($field['description'])) {
            $field_html .= '<p class="description"><small>' . esc_html($field['description']) . '</small></p>';
        }

        $html .= '<tr>';
        $html .= '<th scope="row"><label for="' . esc_attr($field_id) . '">' . esc_html($field['label']) . '</label></th>';
        $html .= '<td>' . $field_html . '</td>';
        $html .= '</tr>';

        return $html;
    }

    /* ----------------------------------------------------------
      Front 404 quick-redirect form
    ---------------------------------------------------------- */

    /* Display a minimal redirect-creation form in the footer of 404 pages */
    public function display_404_redirect_form() {
        if (!is_404() || !is_user_logged_in() || !current_user_can($this->user_level)) {
            return;
        }
        if (!$this->is_redirection_configured()) {
            return;
        }

        // Source = current path without query string
        $source = wp_parse_url(esc_url_raw($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        if (!is_string($source) || $source === '') {
            return;
        }

        $suggestions = $this->suggest_redirections_for_uri($source);

        include __DIR__ . '/inc/tpl/front-404-redirect-form.php';
    }

    /* Handle the 404 quick-redirect form submission */
    public function handle_404_redirect_form() {
        if (!current_user_can($this->user_level)) {
            wp_die(esc_html__('You are not allowed to do this.', 'wpu_redirection_extended'));
        }
        check_admin_referer('wpu_redir_ext_create_404', '_wpnonce');

        $redirect_back = admin_url('tools.php?page=redirection.php');

        $source = isset($_POST['source']) ? wp_parse_url(esc_url_raw(wp_unslash($_POST['source'])), PHP_URL_PATH) : '';
        $target = isset($_POST['target']) ? esc_url_raw(wp_unslash($_POST['target'])) : '';

        if (!is_string($source) || $source === '' || $target === '') {
            $this->set_message('404_redirect_error', __('Source or target URL is missing.', 'wpu_redirection_extended'), 'error');
            wp_safe_redirect($redirect_back);
            exit;
        }

        if ($source === $target || $source === wp_parse_url($target, PHP_URL_PATH)) {
            $this->set_message('404_redirect_error', __('The target cannot be the same as the source.', 'wpu_redirection_extended'), 'error');
            wp_safe_redirect($redirect_back);
            exit;
        }

        $existing = $this->get_existing_redirections();
        if (in_array($source, $existing) || in_array($this->get_alternative_url($source), $existing)) {
            $this->set_message('404_redirect_error', sprintf(__('A redirection already exists for %s.', 'wpu_redirection_extended'), esc_html($source)), 'error');
            wp_safe_redirect($redirect_back);
            exit;
        }

        if (!class_exists('Red_Item')) {
            $this->set_message('404_redirect_error', __('The Redirection plugin is not available.', 'wpu_redirection_extended'), 'error');
            wp_safe_redirect($redirect_back);
            exit;
        }

        $result = \Red_Item::create(array(
            'url' => $source,
            'action_data' => array('url' => $target),
            'match_type' => 'url',
            'action_type' => 'url',
            'action_code' => 301,
            'group_id' => $this->get_default_redirection_group_id(),
            'match_data' => array('source' => array('flag_query' => 'pass'))
        ));

        if (is_wp_error($result)) {
            $this->set_message('404_redirect_error', $result->get_error_message(), 'error');
        } else {
            $this->set_message('404_redirect_success', sprintf(__('Redirection created: %1$s &rarr; %2$s', 'wpu_redirection_extended'), esc_html($source), esc_html($target)), 'updated');
        }

        wp_safe_redirect($redirect_back);
        exit;
    }

    /* Get the group id used for new redirections (Redirection monitor group, fallback first group) */
    public function get_default_redirection_group_id() {
        if (function_exists('red_get_options')) {
            $options = red_get_options();
            if (!empty($options['monitor_post'])) {
                return intval($options['monitor_post']);
            }
        }
        global $wpdb;
        $group_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}redirection_groups ORDER BY id ASC LIMIT 1");
        return $group_id ? intval($group_id) : 1;
    }

}

$WPURedirectionExtended = new WPURedirectionExtended();

include_once __DIR__ . '/inc/wp-cli.php';
