<?php
// Test fixture only: never ship this plugin in a distribution.
define('DISABLE_WP_CRON', true);
add_filter('pre_wp_mail', '__return_true');
add_filter('pre_http_request', static fn () => new WP_Error('offline_test', 'Network disabled by the integration test.'), 10, 3);
