<?php

declare(strict_types=1);

namespace Gallop\Admin;

final class Menu
{
    public function __construct(private readonly PostTypesPage $postTypesPage)
    {
    }

    public function register(): void
    {
        $callback = [$this->postTypesPage, 'render'];

        add_menu_page(
            page_title: 'Gallop',
            menu_title: 'Gallop',
            capability: 'manage_options',
            menu_slug: 'gallop',
            callback: $callback,
            icon_url: 'dashicons-rest-api',
            position: 58,
        );

        add_submenu_page(
            parent_slug: 'gallop',
            page_title: 'Settings',
            menu_title: 'Settings',
            capability: 'manage_options',
            menu_slug: 'gallop',
            callback: $callback,
        );

        add_submenu_page(
            parent_slug: 'gallop',
            page_title: 'Post Types',
            menu_title: 'Post Types',
            capability: 'manage_options',
            menu_slug: 'gallop&tab=post-types',
            callback: '__return_null',
        );
    }
}
