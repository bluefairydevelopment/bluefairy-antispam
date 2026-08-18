<?php
declare(strict_types=1);

/**
 * Plugin Name:       BlueFairy AntiSpam
 * Plugin URI:        https://wordpress.bluefairydevelopment.com/anti-spam/
 * Description:       Invisible anti-spam protection for WordPress and WooCommerce. No CAPTCHAs. No friction. Just spam-free forms.
 * Version:           1.1.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Blue Fairy Development
 * Author URI:        https://wordpress.bluefairydevelopment.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bluefairy-antispam
 * Domain Path:       /languages
 */

namespace BlueFairy\AntiSpam;

if (! defined('ABSPATH')) {
    exit;
}

define('BFAS_VERSION', '1.1.1');
define('BFAS_FILE', __FILE__);
define('BFAS_DIR', plugin_dir_path(__FILE__));
define('BFAS_URL', plugin_dir_url(__FILE__));

// Autoloader.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'BlueFairy\\AntiSpam\\')) {
        $relative = str_replace('BlueFairy\\AntiSpam\\', '', $class);
        $path = BFAS_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
});

// Activation / deactivation hooks.
register_activation_hook(__FILE__, static function (): void {
    ( new Installer() )->activate();
});

register_deactivation_hook(__FILE__, static function (): void {
    ( new Installer() )->deactivate();
});

// Boot the plugin after plugins are loaded so WooCommerce detection works.
add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});
