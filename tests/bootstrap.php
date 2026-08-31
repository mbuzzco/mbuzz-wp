<?php
/**
 * PHPUnit bootstrap. Loads composer autoload + Brain Monkey for WP function
 * mocking. Brain Monkey's setUp/tearDown should be called from each TestCase
 * (see tests/Unit/PluginActivationTest.php for the pattern).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Constants the plugin would otherwise pull from WordPress.
defined('ABSPATH') || define('ABSPATH', dirname(__DIR__) . '/');
defined('MBUZZ_ATTRIBUTION_FILE') || define('MBUZZ_ATTRIBUTION_FILE', dirname(__DIR__) . '/mbuzz-attribution.php');
defined('MBUZZ_ATTRIBUTION_DIR') || define('MBUZZ_ATTRIBUTION_DIR', dirname(__DIR__) . '/');
defined('MBUZZ_ATTRIBUTION_VERSION') || define('MBUZZ_ATTRIBUTION_VERSION', '0.1.0-alpha-test');
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 86400);
