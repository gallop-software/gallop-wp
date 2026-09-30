<?php

declare(strict_types=1);

namespace Gallop;

if (!defined('ABSPATH')) {
    exit;
}

use Gallop\Admin\ConnectionSection;
use Gallop\Admin\MembersSection;
use Gallop\Admin\Menu;
use Gallop\Admin\PostTypesPage;
use Gallop\Admin\Settings;
use Gallop\Frontend\Redirect;
use Gallop\Members\ReplyNotifier;
use Gallop\PostTypes\Registry as PostTypesRegistry;
use Gallop\PostTypes\Storage as PostTypesStorage;
use Gallop\Rest\AuthEndpoint;
use Gallop\Rest\PostEndpoint;
use Gallop\Rest\CategoryEndpoint;
use Gallop\Rest\CommentsEndpoint;
use Gallop\Rest\MembersEndpoint;
use Gallop\Rest\PostsEndpoint;

final class Plugin
{
    public function boot(): void
    {
        $postTypesStorage = new PostTypesStorage();
        $postTypesRegistry = new PostTypesRegistry($postTypesStorage);

        add_action('init', [$postTypesRegistry, 'registerAll']);

        $postEndpoint = new PostEndpoint();
        add_action('rest_api_init', [$postEndpoint, 'register']);
        add_action('rest_api_init', [new PostsEndpoint($postEndpoint), 'register']);
        add_action('rest_api_init', [new CategoryEndpoint(), 'register']);
        add_action('rest_api_init', [new AuthEndpoint(), 'register']);
        add_action('rest_api_init', [new CommentsEndpoint(), 'register']);
        add_action('rest_api_init', [new MembersEndpoint(), 'register']);

        (new Redirect())->register();
        (new ReplyNotifier())->register();

        if (is_admin()) {
            $connection = new ConnectionSection();
            $connection->registerHandlers();

            $settings = new Settings($connection, new MembersSection());
            add_action('admin_init', [$settings, 'register']);

            $postTypesPage = new PostTypesPage($postTypesStorage, $settings);
            $postTypesPage->registerHandlers();

            add_action('admin_menu', [new Menu($postTypesPage), 'register']);
        }
    }
}
