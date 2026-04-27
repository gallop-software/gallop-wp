<?php

declare(strict_types=1);

namespace Gallop\Rest;

use WP_REST_Request;
use WP_REST_Response;

final class CategoryEndpoint
{
    public function register(): void
    {
        register_rest_route('gallop/v1', '/category/', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true',
            'args' => [
                'uri' => [
                    'required' => true,
                    'validate_callback' => function ($param): bool {
                        return is_string($param) && !empty($param);
                    },
                ],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $uri = sanitize_text_field($request->get_param('uri') ?? '');
        $parts = array_filter(explode('/', trim($uri, '/')));
        $slug = end($parts);

        if (!$slug) {
            return new WP_REST_Response(['error' => 'Invalid category URI'], 400);
        }

        $category = get_category_by_slug($slug);

        if (!$category) {
            return new WP_REST_Response(['error' => 'Category not found'], 404);
        }

        return new WP_REST_Response([
            'category' => $this->buildCategoryData($category),
            'seo' => $this->buildSeoData($category),
            'site' => $this->buildSiteData($category),
        ], 200);
    }

    private function buildCategoryData(\WP_Term $category): array
    {
        return [
            'termId' => $category->term_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'parent' => $category->parent,
            'count' => $category->count,
        ];
    }

    private function buildSeoData(\WP_Term $category): array|\stdClass
    {
        if (!function_exists('YoastSEO')) {
            return new \stdClass();
        }

        $seo = YoastSEO()->meta->for_term($category->term_id, 'category');

        return [
            'title' => $seo->title ?? $category->name,
            'metaDesc' => $seo->description,
            'canonical' => $seo->canonical ?? '',
            'opengraphTitle' => $seo->open_graph_title ?? '',
            'opengraphDescription' => $seo->open_graph_description ?? '',
            'opengraphImage' => $seo->open_graph_image ?? '',
            'opengraphUrl' => $seo->open_graph_url ?? '',
            'metaRobotsNoIndex' => $seo->robots_no_index ?? false,
            'metaRobotsNoFollow' => $seo->robots_no_follow ?? false,
        ];
    }

    private function buildSiteData(\WP_Term $category): array
    {
        return [
            'permalink' => get_category_link($category->term_id),
            'siteAuthor' => get_bloginfo('admin_email'),
            'siteTitle' => get_bloginfo('name'),
            'siteDescription' => get_bloginfo('description'),
        ];
    }
}
