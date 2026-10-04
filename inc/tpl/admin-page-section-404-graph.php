<?php
defined('ABSPATH') || die;

/* 404 errors graph. Runs in WPURedirectionExtended::page_content__main() scope ($this available). */

if (!current_user_can($this->user_level) || !$this->is_redirection_configured()) {
    return;
}

$nb_days = 30;

$red_options = array();
if (class_exists('Red_Options')) {
    $red_options = Red_Options::get();
}
if (is_array($red_options) && isset($red_options['expire_404']) && is_numeric($red_options['expire_404'])) {
    $nb_days = (int) $red_options['expire_404'];
}

$graph_range_title_day = __('404 errors over the last %d days', 'wpu_redirection_extended');
$graph_range_title_hours = __('404 errors over the last %d hours', 'wpu_redirection_extended');
$graph_ranges = array(
    'days' => array(
        'label' => sprintf(__('Last %d days', 'wpu_redirection_extended'), $nb_days),
        'title' => sprintf(__('404 errors over the last %d days', 'wpu_redirection_extended'), $nb_days),
        'unit' => 'day',
        'periods' => $nb_days,
        'format' => 'j M'
    ),
    '24h' => array(
        'label' => sprintf(__('Last %d hours', 'wpu_redirection_extended'), 24),
        'title' => sprintf($graph_range_title_hours, 24),
        'unit' => 'hour',
        'periods' => 24,
        'format' => 'j M, H\h'
    ),
    '48h' => array(
        'label' => sprintf(__('Last %d hours', 'wpu_redirection_extended'), 48),
        'title' => sprintf($graph_range_title_hours, 48),
        'unit' => 'hour',
        'periods' => 48,
        'format' => 'j M, H\h'
    )
);

$graph_range = isset($_GET['wre_graph_range']) ? sanitize_key($_GET['wre_graph_range']) : 'days';
if (!isset($graph_ranges[$graph_range])) {
    $graph_range = 'days';
}
$graph_settings = $graph_ranges[$graph_range];

echo '<h2>' . esc_html($graph_settings['title']) . '</h2>';

$graph_links = array();
foreach ($graph_ranges as $range_id => $range_infos) {
    if ($range_id === $graph_range) {
        $graph_links[] = '<strong>' . esc_html($range_infos['label']) . '</strong>';
        continue;
    }
    $graph_links[] = '<a href="' . esc_url(add_query_arg('wre_graph_range', $range_id)) . '">' . esc_html($range_infos['label']) . '</a>';
}
echo '<p>' . implode(' | ', $graph_links) . '</p>';

$graph_counts = $this->get_404_counts($graph_settings['periods'], $graph_settings['unit']);

if (!array_sum($graph_counts)) {
    echo '<p>' . esc_html__('No data found.', 'wpu_redirection_extended') . '</p>';
    echo '<hr />';
    return;
}

$graph_labels = array();
foreach (array_keys($graph_counts) as $graph_period) {
    $graph_labels[] = date_i18n($graph_settings['format'], strtotime($graph_period));
}

echo '<div style="height:200px;max-width:100%"><canvas id="wre-404-graph" height="200"></canvas></div>';
?>
<script>
(function() {
    var labels = <?php echo wp_json_encode($graph_labels); ?>;
    var values = <?php echo wp_json_encode(array_values($graph_counts)); ?>;

    function init() {
        if (typeof Chart === 'undefined') {
            return setTimeout(init, 50);
        }
        new Chart(document.getElementById('wre-404-graph'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: <?php echo wp_json_encode(__('404 errors', 'wpu_redirection_extended')); ?>,
                    data: values,
                    backgroundColor: '#2271b1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    }
    init();
})();
</script>
<hr />
