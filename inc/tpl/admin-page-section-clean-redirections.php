<?php
defined('ABSPATH') || die;

/* "Clean redirections" section. Runs in WPURedirectionExtended::page_content__main() scope ($this available). */

echo '<hr />';
echo '<h2>' . esc_html__('Clean redirections', 'wpu_redirection_extended') . '</h2>';
echo '<p>' . esc_html__('Detect common redirection issues and clean them.', 'wpu_redirection_extended') . '</p>';
echo '<p>';
submit_button(__('Get a list of issues', 'wpu_redirection_extended'), 'secondary', 'submit_get_redirection_issues', false);
echo ' ';
submit_button(__('Fix issues', 'wpu_redirection_extended'), 'primary', 'submit_fix_redirection_issues', false);
echo '</p>';
