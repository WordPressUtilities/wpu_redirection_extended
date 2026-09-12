<?php
defined('ABSPATH') || die;

/* 404 errors graph. Runs in WPURedirectionExtended::page_content__main() scope ($this available). */

if (!current_user_can($this->user_level) || !$this->is_redirection_configured()) {
    return;
}

$graph_counts = $this->get_404_daily_counts();

echo '<h2>' . esc_html__('404 errors over the last 30 days', 'wpu_redirection_extended') . '</h2>';

if (!array_sum($graph_counts)) {
    echo '<p>' . esc_html__('No data found.', 'wpu_redirection_extended') . '</p>';
    echo '<hr />';
    return;
}

$graph_labels = array();
foreach (array_keys($graph_counts) as $graph_day) {
    $graph_labels[] = date_i18n('j M', strtotime($graph_day));
}

echo '<div style="max-width:100%"><canvas id="wre-404-graph" height="200"></canvas></div>';
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
