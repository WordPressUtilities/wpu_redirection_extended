<?php
defined('ABSPATH') || die;

/* "Settings" section. Runs in WPURedirectionExtended::page_content__main() scope ($this available). */
/* No <form> nor settings_fields() here: WPUBaseAdminPage already wraps the page in its own admin-post.php form. */

do_settings_sections($this->settings_details['plugin_id']);
submit_button(__('Save settings', 'wpu_redirection_extended'), 'primary', 'submit_settings');
