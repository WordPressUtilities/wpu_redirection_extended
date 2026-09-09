<?php
defined('ABSPATH') || die;

/* "Recommended settings" section. Runs in WPURedirectionExtended::page_content__main() scope ($this available). */

echo '<h2>' . esc_html__('Recommended settings', 'wpu_redirection_extended') . '</h2>';
echo '<p>' . esc_html__('Apply the recommended Redirection settings:', 'wpu_redirection_extended');
echo ' <a href="' . esc_url(admin_url('tools.php?page=redirection.php&sub=options')) . '">' . esc_html__('(see current settings)', 'wpu_redirection_extended') . '</a>';
echo '</p>';
echo '<ul style="list-style:disc;padding-left:2em;">';
foreach ($this->get_recommended_settings() as $wre_setting) {
    if (empty($wre_setting['label'])) {
        continue;
    }
    $wre_line = $wre_setting['label'];
    if (!empty($wre_setting['value_label'])) {
        /* translators: 1: setting name, 2: recommended value */
        $wre_line = sprintf(__('%1$s: %2$s', 'wpu_redirection_extended'), $wre_setting['label'], $wre_setting['value_label']);
    }
    echo '<li>' . esc_html($wre_line) . '</li>';
}
echo '</ul>';
echo '<p>' . esc_html__('Existing values will be overwritten.', 'wpu_redirection_extended') . '</p>';
submit_button(__('Apply recommended settings', 'wpu_redirection_extended'), 'primary', 'submit_recommended_settings');
