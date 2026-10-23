<?php
if ('cli' !== PHP_SAPI || '1' !== getenv('BOKUN_TESTS_ALLOWED')) {
    exit('Use the isolated WordPress test runner.');
}
$test_wordpress = realpath(getenv('BOKUN_TEST_WORDPRESS_DIR') ?: '');
if (!$test_wordpress || !is_file($test_wordpress . '/.bokun-test-runtime')) {
    throw new RuntimeException('An explicitly initialized disposable WordPress runtime is required.');
}
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('DB_PATH', getenv('BOKUN_TEST_DATABASE'));
require $test_wordpress . '/wp-load.php';
if (!in_array(parse_url(get_option('siteurl'), PHP_URL_HOST), array('127.0.0.1', 'localhost'), true) && is_blog_installed()) {
    throw new RuntimeException('Tests must not run against a non-loopback installation.');
}
