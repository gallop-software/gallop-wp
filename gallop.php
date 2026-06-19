<?php
/**
 * Plugin Name: Gallop
 * Plugin URI:  https://gallop.software/headless-wordpress
 * Description: A purpose-built REST API for Next.js websites — fetch a page's post, SEO, and site data in one request, with built-in cookie login support for authenticated front ends.
 * Version:     0.1.1
 * Author:      Gallop Software
 * Author URI:  https://gallop.software
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * Text Domain: gallop
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' . sprintf(
            /* translators: 1: Plugin name (wrapped in <strong>), 2: Current PHP version. */
            esc_html__('%1$s requires PHP 8.1 or higher. Current version: %2$s', 'gallop'),
            '<strong>Gallop</strong>',
            esc_html(PHP_VERSION)
        ) . '</p></div>';
    });
    return;
}

$gallop_composer_autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($gallop_composer_autoload)) {
    require $gallop_composer_autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Gallop\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

register_activation_hook(__FILE__, static function (): void {
    (new Gallop\PostTypes\Registry(new Gallop\PostTypes\Storage()))->registerAll();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, static function (): void {
    flush_rewrite_rules();
});

(new Gallop\Plugin())->boot();
