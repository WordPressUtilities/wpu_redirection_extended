<?php
defined('ABSPATH') || die;

/* "Generate CSV from sitemap" section. Runs in WPURedirectionExtended::page_content__main() scope ($this available). */

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
