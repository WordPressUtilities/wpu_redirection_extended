<?php
/*
Plugin Name: WPU Redirection Extended
Plugin URI: https://github.com/WordPressUtilities/wpu_redirection_extended
Update URI: https://github.com/WordPressUtilities/wpu_redirection_extended
Description: Enhance the Redirection plugin with additional features.
Version: 0.15.4
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
    private $plugin_version = '0.15.4';
    private $plugin_settings = array(
        'id' => 'wpu_redirection_extended',
        'name' => 'WPU Redirection Extended'
    );
    private $user_level = 'wpu_redirection_extended_access';
    private $basetoolbox;
    private $messages;
    private $adminpages;
    private $widget_types = array();
    private $redirection_issues = array();

    public function __construct() {
        add_action('init', array(&$this, 'load_translation'));
        add_action('init', array(&$this, 'load_toolbox'));
        add_action('init', array(&$this, 'load_admin_page'));
        add_action('init', array(&$this, 'load_messages'));
        add_action('init', array(&$this, 'check_dependencies'));
        add_action('init', array(&$this, 'set_custom_roles'), 11);
        add_action('init', array(&$this, 'load_widget_types'));
        add_action('wp_dashboard_setup', array(&$this, 'add_dashboard_widgets'));
        add_action('admin_menu', array(&$this, 'set_admin_menus'), 10);
        add_action('edit_form_after_title', array(&$this, 'notice_slug_match_redirection'));
        add_action('admin_init', array(&$this, 'notice_slug_match_redirection__all_terms'));
        add_action('admin_init', array(&$this, 'handle_widget_csv_download'));

        /* Hooks for WP-CLI */
        add_action('wpu_redirection_extended_clean_database', array(&$this,
            'page_action__main__submit_clean_database'
        ));

        /* Redirection settings */
        add_filter('redirection_role', function ($role) {
            return $this->user_level;
        });
        add_filter('rest_request_after_callbacks', array(&$this, 'extend_redirect_autocomplete'), 10, 3);
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
                    WHERE agent LIKE '%bot%' OR ip LIKE '66.249%'
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

        echo '<h2>' . esc_html__('Generate CSV from sitemap', 'wpu_redirection_extended') . '</h2>';
        echo '<p>' . esc_html__('Provide the URL of an external sitemap (XML or sitemap index, optionally gzipped). A CSV will be generated with one source URL per line and an empty target column.', 'wpu_redirection_extended') . '</p>';
        echo '<table class="form-table">';
        echo $this->get_admin_field_html('sitemap_url', array(
            'label' => __('Sitemap URL', 'wpu_redirection_extended'),
            'description' => __('Provide the URL of a sitemap (XML or sitemap index, optionally gzipped). You can also enter just a domain — the sitemap will be auto-detected via robots.txt or common paths.', 'wpu_redirection_extended'),
            'type' => 'url'
        ));
        echo $this->get_admin_field_html('sitemap_exclude_home', array(
            'label' => __('Exclude home', 'wpu_redirection_extended'),
            'label_checkbox' => __('Exclude the home URL (/) from the generated CSV', 'wpu_redirection_extended'),
            'type' => 'checkbox'
        ));
        echo $this->get_admin_field_html('sitemap_exclude_existing_slugs', array(
            'label' => __('Exclude existing slugs', 'wpu_redirection_extended'),
            'label_checkbox' => __('Exclude URLs that match an existing slug on this site', 'wpu_redirection_extended'),
            'type' => 'checkbox'
        ));
        echo $this->get_admin_field_html('sitemap_exclude_existing_redirections', array(
            'label' => __('Exclude existing redirections', 'wpu_redirection_extended'),
            'label_checkbox' => __('Exclude URLs already configured as redirection sources', 'wpu_redirection_extended'),
            'type' => 'checkbox'
        ));
        echo '</table>';
        submit_button(__('Generate CSV', 'wpu_redirection_extended'), 'primary', 'submit_generate_csv_from_sitemap');

        echo '<hr />';
        echo '<h2>' . esc_html__('Validate your CSV file', 'wpu_redirection_extended') . '</h2>';
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
        echo '<h2>' . esc_html__('Clean database', 'wpu_redirection_extended') . '</h2>';
        echo '<p>' . esc_html__('Delete 404 logs where redirections exist or are not useful.', 'wpu_redirection_extended') . '</p>';
        submit_button(__('Clean', 'wpu_redirection_extended'), 'primary', 'submit_clean_database');

        echo '<hr />';
        echo '<h2>' . esc_html__('Clean redirections', 'wpu_redirection_extended') . '</h2>';
        echo '<p>' . esc_html__('Detect common redirection issues and clean them.', 'wpu_redirection_extended') . '</p>';
        echo '<p>';
        submit_button(__('Get a list of issues', 'wpu_redirection_extended'), 'secondary', 'submit_get_redirection_issues', false);
        echo ' ';
        submit_button(__('Fix issues', 'wpu_redirection_extended'), 'primary', 'submit_fix_redirection_issues', false);
        echo '</p>';

        foreach ($this->widget_types as $widget_type => $widget_infos) {
            echo '<hr />';
            echo '<h2>' . esc_html($widget_infos['label']) . '</h2>';
            echo '<details>';
            echo $this->wpu_redirection_get_widget_content($widget_type);
            echo '</details>';
        }

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
            $this->set_message('database_cleaned', __('No invalid 404 log entries found.', 'wpu_redirection_extended'), 'success');
            return;
        }
        $this->set_message('database_cleaned', sprintf(__('Deleted %s log entries.', 'wpu_redirection_extended'), '<strong>' . $deleted . '</strong>'), 'success');
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

    public function page_action__main__clean_redirections($diagnostic_only = false) {
        global $wpdb;

        if (!$this->is_redirection_configured()) {
            $this->set_message('redirections_cleaned', __('Redirection plugin is not configured.', 'wpu_redirection_extended'), 'error');
            return;
        }

        $redirections = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}redirection_items WHERE status = 'enabled'");

        $issues_found = 0;
        $duplicates_found = 0;
        $duplicate_ids = $this->page_action__main__clean_redirections__duplicates($redirections, $diagnostic_only, $duplicates_found);
        $existing_slugs = $this->get_existing_slugs();
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
            if ($this->page_action__main__clean_redirections__match_existing($redirection, $existing_slugs, $diagnostic_only)) {
                $issues_found++;
            }

        }

        if ($duplicates_found > 0) {
            if ($diagnostic_only) {
                $this->set_message('redirection_duplicates', sprintf(__('%s duplicate redirections detected (same source).', 'wpu_redirection_extended'), '<strong>' . $duplicates_found . '</strong>'), 'error');
            } else {
                $this->set_message('redirection_duplicates', sprintf(__('Disabled %s duplicate redirections (same source).', 'wpu_redirection_extended'), '<strong>' . $duplicates_found . '</strong>'), 'success');
            }
        }

        if ($diagnostic_only) {
            if (!empty($this->redirection_issues)) {
                $this->set_message('redirection_issues_list', implode('<br />', $this->redirection_issues), 'error');
            }
            if ($issues_found == 0) {
                $this->set_message('redirection_issues', __('No redirection issue found.', 'wpu_redirection_extended'), 'success');
            } else {
                $this->set_message('redirection_issues', sprintf(__('%s redirections have issues that may cause conflicts or unexpected behavior.', 'wpu_redirection_extended'), '<strong>' . $issues_found . '</strong>'), 'error');
            }
        } else {
            $this->set_message('redirections_cleaned', sprintf(__('Cleaned %s redirections with potential issues.', 'wpu_redirection_extended'), '<strong>' . $issues_found . '</strong>'), 'success');
        }
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
                    $this->redirection_issues[] = sprintf(__('Redirection with ID %1$s (%2$s) is a duplicate of ID %3$s.', 'wpu_redirection_extended'), $item->id, esc_html($item->url), $keeper->id);
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
            $this->redirection_issues[] = sprintf(__('Redirection with ID %s (%s) does not allow query parameters and may cause issues.', 'wpu_redirection_extended'), $redirection->id, esc_html($redirection->url));
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
    public function page_action__main__clean_redirections__match_existing($redirection, $existing_slugs, $diagnostic_only = false) {
        global $wpdb;
        $redirection_match_existing = false;
        if ($redirection->match_url == 'regex') {
            foreach ($existing_slugs as $slug) {
                if (@preg_match('#' . $redirection->url . '#', $slug)) {
                    $redirection_match_existing = true;
                    break;
                }
            }
        } else {
            if (in_array($redirection->url, $existing_slugs) || in_array($this->get_alternative_url($redirection->url), $existing_slugs)) {
                $redirection_match_existing = true;
            }
        }
        if (!$redirection_match_existing) {
            return false;
        }

        if ($diagnostic_only) {
            $this->redirection_issues[] = sprintf(__('Redirection with ID %s (%s) matches an existing slug and may cause conflicts.', 'wpu_redirection_extended'), $redirection->id, esc_html($redirection->url));
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

    public function get_existing_slugs() {
        $cache_id = 'wpu_redirection_extended_existing_slugs';
        $existing_slugs = wp_cache_get($cache_id);
        if ($existing_slugs !== false) {
            return $existing_slugs;
        }

        $posts = get_posts(array(
            'post_type' => 'any',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids'
        ));
        $existing_slugs = array();
        foreach ($posts as $post_id) {
            $existing_slugs[] = wp_make_link_relative(get_permalink($post_id));
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
                    $existing_slugs[] = wp_make_link_relative($term_link);
                }
            }
        }

        /*  Add slugs with and without trailing slash */
        $existing_slugs_copy = $existing_slugs;
        foreach ($existing_slugs_copy as $slug) {
            $existing_slugs[] = $this->get_alternative_url($slug);
        }

        $existing_slugs = array_unique($existing_slugs);

        wp_cache_set($cache_id, $existing_slugs, '', 60);

        return $existing_slugs;
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
                $html .= '<a class="button" href="' . esc_url(admin_url('tools.php?page=redirection.php&sub=404s' . $widget_infos['search_param'])) . '">';
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
        return '<a class="button" href="' . esc_url($download_url) . '">' . esc_html__('Export CSV', 'wpu_redirection_extended') . '</a>';
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

}

$WPURedirectionExtended = new WPURedirectionExtended();

include_once __DIR__ . '/inc/wp-cli.php';
