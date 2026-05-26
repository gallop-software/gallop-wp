<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

namespace Gallop\Rest;

use WP_REST_Request;
use WP_REST_Response;

final class PostEndpoint
{
    public function register(): void
    {
        register_rest_route('gallop/v1', '/post/', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $emptyPayload = [
            'post' => null,
            'seo'  => null,
            'site' => null,
        ];

        $uri = sanitize_text_field((string) ($request->get_param('uri') ?? ''));
        if ($uri === '') {
            return new WP_REST_Response($emptyPayload, 200);
        }

        $postId = url_to_postid($uri);
        if (empty($postId)) {
            return new WP_REST_Response($emptyPayload, 200);
        }

        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return new WP_REST_Response($emptyPayload, 200);
        }

        // Only expose published, non-password-protected content to the headless front end.
        if ($post->post_status !== 'publish' || post_password_required($post)) {
            return new WP_REST_Response($emptyPayload, 200);
        }

        return new WP_REST_Response([
            'post' => $this->buildPostData($post),
            'seo'  => $this->buildSeoData($post),
            'site' => $this->buildSiteData($post),
        ], 200);
    }

    private function buildPostData(\WP_Post $post): array
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying WordPress core's `the_content` filter to render post content, not defining a new hook.
        $rendered = apply_filters('the_content', do_blocks($post->post_content));

        return [
            'ID' => $post->ID,
            'postAuthor' => $post->post_author,
            'postDate' => $post->post_date,
            'postDateGmt' => $post->post_date_gmt,
            'postContent' => $rendered,
            'postTitle' => $post->post_title,
            'postExcerpt' => $post->post_excerpt,
            'postStatus' => $post->post_status,
            'commentStatus' => $post->comment_status,
            'pingStatus' => $post->ping_status,
            'postName' => $post->post_name,
            'toPing' => $post->to_ping,
            'pinged' => $post->pinged,
            'postModified' => $post->post_modified,
            'postModifiedGmt' => $post->post_modified_gmt,
            'postParent' => $post->post_parent,
            'menuOrder' => $post->menu_order,
            'postType' => $post->post_type,
            'postMimeType' => $post->post_mime_type,
            'commentCount' => $post->comment_count,
        ];
    }

    private function buildSeoData(\WP_Post $post): array|\stdClass
    {
        if (!function_exists('YoastSEO')) {
            return new \stdClass();
        }

        $seo = YoastSEO()->meta->for_post($post->ID);
        if (!$seo) {
            return new \stdClass();
        }
        $ogImages = is_array($seo->open_graph_images ?? null) ? $seo->open_graph_images : [];
        $images = array_reverse($ogImages);
        $image = array_pop($images);

        return [
            'canonical' => $seo->canonical,
            'metaDesc' => $seo->description,
            'opengraphAuthor' => $seo->open_graph_author,
            'opengraphDescription' => $seo->open_graph_description,
            'metaRobotsNoFollow' => $seo->robots_no_follow,
            'metaRobotsNoindex' => $seo->robots_no_index,
            'metaKeywords' => $seo->meta_keywords,
            'opengraphImage' => [
                'mediaItemUrl' => $image['url'] ?? null,
                'mediaDetails' => [
                    'height' => $image['height'] ?? null,
                    'width' => $image['width'] ?? null,
                ],
                'mediaType' => $image['type'] ?? null,
            ],
            'opengraphModifiedTime' => $seo->open_graph_modified_time,
            'opengraphPublishedTime' => $seo->open_graph_published_time,
            'title' => $seo->title,
            'opengraphTitle' => $seo->open_graph_title,
            'opengraphSiteName' => $seo->open_graph_site_name,
            'opengraphUrl' => $seo->open_graph_url,
            'readingTime' => $seo->reading_time,
            'opengraphType' => $seo->open_graph_type,
            'opengraphPublisher' => $seo->open_graph_publisher,
        ];
    }

    private function buildSiteData(\WP_Post $post): array
    {
        return [
            'author' => [
                'ID' => $post->post_author,
                'displayName' => get_the_author_meta('display_name', $post->post_author),
                'userUrl' => get_the_author_meta('user_url', $post->post_author),
                'description' => wp_kses_post((string) get_the_author_meta('description', $post->post_author)),
            ],
            'permalink' => get_permalink($post->ID),
            'siteTitle' => get_bloginfo('name'),
            'siteDescription' => get_bloginfo('description'),
        ];
    }
}
