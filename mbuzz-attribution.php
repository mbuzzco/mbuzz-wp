<?php
/**
 * Plugin Name:       Mbuzz Attribution
 * Plugin URI:        https://mbuzz.co/wordpress
 * Description:       Server-side multi-touch attribution for WordPress and WooCommerce.
 * Version:           0.6.0-alpha
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Mbuzz
 * Author URI:        https://mbuzz.co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mbuzz-attribution
 *
 * @package Mbuzz\WP
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('MBUZZ_ATTRIBUTION_VERSION', '0.6.0-alpha');
define('MBUZZ_ATTRIBUTION_FILE', __FILE__);
define('MBUZZ_ATTRIBUTION_DIR', plugin_dir_path(__FILE__));
define('MBUZZ_ATTRIBUTION_URL', plugin_dir_url(__FILE__));

// PHP-floor enforcement. The plugin header's "Requires PHP: 8.1" already
// prevents activation on WP >= 5.1. Belt-and-braces for everyone else:
// abort early with a friendly notice instead of fataling on a typed property.
if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Mbuzz Attribution requires PHP 8.1 or higher. The plugin has been deactivated.', 'mbuzz-attribution');
        echo '</p></div>';
    });
    add_action('admin_init', function () {
        deactivate_plugins(plugin_basename(__FILE__));
    });
    return;
}

// Composer autoload — bundled at build time via the WP.org release script.
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    // Dev install (no `composer install` yet). Surface the error loudly so
    // it's obvious during local setup; in shipped releases vendor/ is always
    // present.
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Mbuzz Attribution: vendor/ directory missing. Run `composer install` in the plugin folder.', 'mbuzz-attribution');
        echo '</p></div>';
    });
    return;
}

// Theme/snippet escape hatch — see spec §2. After php-scoper, `\Mbuzz\Mbuzz`
// won't exist (it'll be `Mbuzz\Vendor\Mbuzz\Mbuzz`). These helpers are the
// documented, scope-stable surface for theme code copy-pasted from SDK docs.
require_once __DIR__ . '/src/helpers.php';

\Mbuzz\WP\Plugin::instance()->register();
