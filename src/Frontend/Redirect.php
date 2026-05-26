<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

namespace Gallop\Frontend;

use Gallop\Admin\Settings;

final class Redirect
{
    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeRedirect']);
        add_filter('allowed_redirect_hosts', [$this, 'allowTargetHost']);
    }

    /**
     * @param array<int, string> $hosts
     * @return array<int, string>
     */
    public function allowTargetHost(array $hosts): array
    {
        $target = (string) get_option(Settings::OPTION_NEXTJS_URL, '');
        if ($target === '') {
            return $hosts;
        }
        $host = wp_parse_url($target, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $hosts[] = $host;
        }
        return $hosts;
    }

    public function maybeRedirect(): void
    {
        $target = (string) get_option(Settings::OPTION_NEXTJS_URL, '');
        if ($target === '') {
            return;
        }

        $targetHost = wp_parse_url($target, PHP_URL_HOST);
        if (!is_string($targetHost) || $targetHost === '') {
            return;
        }

        $get = wp_unslash($_GET); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public GET redirect, no state change.
        if (!is_array($get)) {
            $get = [];
        }

        if (isset($get['preview']) && $get['preview'] === 'true') {
            return;
        }
        foreach (array_keys($get) as $key) {
            if (is_string($key) && str_starts_with($key, '_wp')) {
                return;
            }
        }

        $currentHost = isset($_SERVER['HTTP_HOST'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']))
            : '';
        if (strcasecmp($targetHost, $currentHost) === 0) {
            return;
        }

        $requestUri = isset($_SERVER['REQUEST_URI'])
            ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']))
            : '/';
        $path = ($requestUri === '' || $requestUri === '/') ? '' : $requestUri;

        $destination = esc_url_raw(untrailingslashit($target) . $path);
        if ($destination === '') {
            return;
        }

        wp_safe_redirect($destination, 301);
        exit;
    }
}
