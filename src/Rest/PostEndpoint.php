<?php

declare(strict_types=1);

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
        $uri = sanitize_text_field($request->get_param('uri') ?? '');
        $postId = url_to_postid($uri);

        if (empty($postId)) {
            return new WP_REST_Response([
                'post' => null,
                'seo' => null,
                'site' => null,
            ], 200);
        }

        $post = get_post($postId);

        if (empty($post)) {
            return new WP_REST_Response([
                'post' => null,
                'seo' => null,
                'site' => null,
            ], 200);
        }

        return new WP_REST_Response([
            'post' => $this->buildPostData($post),
            'seo' => $this->buildSeoData($post),
            'site' => $this->buildSiteData($post),
        ], 200);
    }

    private function buildPostData(\WP_Post $post): array
    {
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
            'postPassword' => $post->post_password,
            'postName' => $post->post_name,
            'toPing' => $post->to_ping,
            'pinged' => $post->pinged,
            'postModified' => $post->post_modified,
            'postModifiedGmt' => $post->post_modified_gmt,
            'postParent' => $post->post_parent,
            'guid' => $post->guid,
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
        $images = array_reverse($seo->open_graph_images);
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
                'userEmail' => get_the_author_meta('user_email', $post->post_author),
                'userUrl' => get_the_author_meta('user_url', $post->post_author),
                'description' => get_the_author_meta('description', $post->post_author),
            ],
            'permalink' => get_permalink($post->ID),
            'siteAuthor' => get_bloginfo('admin_email'),
            'siteTitle' => get_bloginfo('name'),
            'siteDescription' => get_bloginfo('description'),
        ];
    }
}
