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
        add_menu_page(
            page_title: 'Gallop',
            menu_title: 'Gallop',
            capability: 'manage_options',
            menu_slug: 'gallop',
            callback: [$this->postTypesPage, 'render'],
            icon_url: 'dashicons-rest-api',
            position: 58,
        );
    }
}
