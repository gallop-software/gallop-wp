<?php
/**
 * Plugin Name: Gallop
 * Description: Headless WordPress plugin that registers custom post types and exposes them via the WP REST API for consumption by a Next.js front end.
 * Version:     0.1.0
 * Author:      Webplant Media
 * License:     GPL-2.0-or-later
 * Requires PHP: 8.1
 * Requires at least: 6.4
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>Gallop</strong> requires PHP 8.1 or higher. Current version: ' . esc_html(PHP_VERSION) . '</p></div>';
    });
    return;
}

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
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

(new Gallop\Plugin())->boot();
