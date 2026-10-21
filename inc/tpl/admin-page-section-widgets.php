<?php
defined('ABSPATH') || die;

/* 404 widget previews section. Runs in WPURedirectionExtended::page_content__main() scope ($this available). */

$widget_range = $this->get_404_range();
foreach ($this->widget_types as $widget_type => $widget_infos) {
    echo '<h2>' . esc_html($widget_infos['label']) . '</h2>';
    echo '<details class="wre-widget-details" data-widget="' . esc_attr($widget_type) . '">';
    echo $this->wpu_redirection_get_widget_content($widget_type, $widget_range);
    echo '</details>';
    echo '<hr />';
}
?>
<script>
/* Keep opened widgets open when switching range */
(function() {
    Array.prototype.forEach.call(document.querySelectorAll('.wre-widget-details'), function(details) {
        var key = 'wre_widget_open_' + details.getAttribute('data-widget');
        try {
            details.open = sessionStorage.getItem(key) === '1';
        } catch (e) {}
        details.addEventListener('toggle', function() {
            try {
                sessionStorage.setItem(key, details.open ? '1' : '0');
            } catch (e) {}
        });
    });
})();
</script>
