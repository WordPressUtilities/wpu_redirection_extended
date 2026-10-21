<?php
defined('ABSPATH') || die;

/* 404 errors graph. Runs in WPURedirectionExtended::page_content__main() scope ($this available). */

if (!current_user_can($this->user_level) || !$this->is_redirection_configured()) {
    return;
}

$graph_settings = $this->get_404_range();

/* Range selector applies to the whole tab (graph + widgets) */
$graph_links = array();
foreach ($this->get_404_ranges() as $range_id => $range_infos) {
    if ($range_id === $graph_settings['id']) {
        $graph_links[] = '<strong>' . esc_html($range_infos['label']) . '</strong>';
        continue;
    }
    $graph_links[] = '<a href="' . esc_url(add_query_arg('wre_graph_range', $range_id)) . '">' . esc_html($range_infos['label']) . '</a>';
}
echo '<p>' . implode(' | ', $graph_links) . '</p>';

echo '<h2>' . esc_html($graph_settings['title']) . '</h2>';

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
