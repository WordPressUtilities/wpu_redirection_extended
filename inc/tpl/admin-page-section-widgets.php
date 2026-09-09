<?php
defined('ABSPATH') || die;

/* 404 widget previews section. Runs in WPURedirectionExtended::page_content__main() scope ($this available). */

foreach ($this->widget_types as $widget_type => $widget_infos) {
    echo '<h2>' . esc_html($widget_infos['label']) . '</h2>';
    echo '<details>';
    echo $this->wpu_redirection_get_widget_content($widget_type);
    echo '</details>';
    echo '<hr />';
}
