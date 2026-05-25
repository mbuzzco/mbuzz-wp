<?php
/**
 * Uninstall — drops the settings option row and diagnostic transients.
 * Order meta (_mbuzz_conversion_id) is intentionally left in place: it's
 * audit data tied to fulfilled orders and the user can choose to scrub it
 * via the privacy eraser.
 *
 * @package Mbuzz\WP
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('mbuzz_attribution_settings');
delete_transient('mbuzz_attribution_last_call');
delete_transient('mbuzz_attribution_php_notice');
