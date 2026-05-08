<?php

declare(strict_types=1);

namespace Gallop\Frontend;

use Gallop\Admin\Settings;

final class Redirect
{
    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeRedirect']);
    }

    public function maybeRedirect(): void
    {
        $target = (string)get_option(Settings::OPTION_NEXTJS_URL, '');
        if ($target === '') {
            return;
        }

        if (isset($_GET['preview']) && $_GET['preview'] === 'true') {
            return;
        }
        foreach (array_keys($_GET) as $key) {
            if (is_string($key) && str_starts_with($key, '_wp')) {
                return;
            }
        }

        $targetHost = parse_url($target, PHP_URL_HOST);
        $currentHost = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($targetHost === null || strcasecmp($targetHost, $currentHost) === 0) {
            return;
        }

        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = $uri === '/' ? '' : $uri;

        wp_redirect(untrailingslashit($target) . $path, 301);
        exit;
    }
}
