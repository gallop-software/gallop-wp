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
            page_title: __('Gallop', 'gallop'),
            menu_title: __('Gallop', 'gallop'),
            capability: 'manage_options',
            menu_slug: 'gallop',
            callback: $callback,
            icon_url: 'dashicons-rest-api',
            position: 58,
        );

        add_submenu_page(
            parent_slug: 'gallop',
            page_title: __('Settings', 'gallop'),
            menu_title: __('Settings', 'gallop'),
            capability: 'manage_options',
            menu_slug: 'gallop',
            callback: $callback,
        );

        add_submenu_page(
            parent_slug: 'gallop',
            page_title: __('Post Types', 'gallop'),
            menu_title: __('Post Types', 'gallop'),
            capability: 'manage_options',
            menu_slug: 'admin.php?page=gallop&tab=post-types',
        );
    }
}
