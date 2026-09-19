<?php
defined('ABSPATH') || die;
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

wp_clear_scheduled_hook('wpu_redirection_extended_check_404_spike');

/* Direct deletes : instantiating the libraries just to drop two options would be silly */
delete_option('wpu_redirection_extended_options');
delete_option('wpu_redirection_extended_options_wpubasenotify_errors');
delete_option('wpu_redirection_extended_404_spike_last_notified');
