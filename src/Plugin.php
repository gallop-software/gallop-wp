<?php

declare(strict_types=1);

namespace Gallop;

use Gallop\Admin\Menu;
use Gallop\Admin\PostTypesPage;
use Gallop\PostTypes\Registry as PostTypesRegistry;
use Gallop\PostTypes\Storage as PostTypesStorage;

final class Plugin
{
    public function boot(): void
    {
        $postTypesStorage = new PostTypesStorage();
        $postTypesRegistry = new PostTypesRegistry($postTypesStorage);

        add_action('init', [$postTypesRegistry, 'registerAll']);

        if (is_admin()) {
            $postTypesPage = new PostTypesPage($postTypesStorage);
            $postTypesPage->registerHandlers();

            add_action('admin_menu', [new Menu($postTypesPage), 'register']);
        }
    }
}
