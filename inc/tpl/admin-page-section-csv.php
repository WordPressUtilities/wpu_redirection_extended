<?php
defined('ABSPATH') || die;

/* "Validate your CSV file" section. Runs in WPURedirectionExtended::page_content__main() scope ($this available). */

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
