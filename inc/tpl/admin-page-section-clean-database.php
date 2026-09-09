<?php
defined('ABSPATH') || die;

/* "Clean database" section. Runs in WPURedirectionExtended::page_content__main() scope ($this available). */

echo '<h2>' . esc_html__('Clean database', 'wpu_redirection_extended') . '</h2>';
echo '<p>' . esc_html__('Delete 404 logs where redirections exist or are not useful.', 'wpu_redirection_extended') . '</p>';
submit_button(__('Clean', 'wpu_redirection_extended'), 'primary', 'submit_clean_database');
