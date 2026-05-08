<?php

declare(strict_types=1);

namespace Gallop;

use Gallop\Admin\Menu;
use Gallop\Admin\PostTypesPage;
use Gallop\Admin\Settings;
use Gallop\Frontend\Redirect;
use Gallop\PostTypes\Registry as PostTypesRegistry;
use Gallop\PostTypes\Storage as PostTypesStorage;
use Gallop\Rest\PostEndpoint;
use Gallop\Rest\CategoryEndpoint;

final class Plugin
{
    public function boot(): void
    {
        $postTypesStorage = new PostTypesStorage();
        $postTypesRegistry = new PostTypesRegistry($postTypesStorage);

        add_action('init', [$postTypesRegistry, 'registerAll']);

        add_action('rest_api_init', [new PostEndpoint(), 'register']);
        add_action('rest_api_init', [new CategoryEndpoint(), 'register']);

        (new Redirect())->register();

        if (is_admin()) {
            $settings = new Settings();
            add_action('admin_init', [$settings, 'register']);

            $postTypesPage = new PostTypesPage($postTypesStorage, $settings);
            $postTypesPage->registerHandlers();

            add_action('admin_menu', [new Menu($postTypesPage), 'register']);
        }
    }
}
