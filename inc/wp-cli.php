<?php
defined('ABSPATH') || die;

if(!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('wpu-redirection-extended-clean-database', function () {
    do_action('wpu_redirection_extended_clean_database');
}, array(
    'shortdesc' => 'Clean the database by removing old redirections and logs.',
    'synopsis' => array()
));

