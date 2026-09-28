<?php

declare(strict_types=1);

namespace Gallop\Rest;

if (!defined('ABSPATH')) {
    exit;
}

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
            // Only the parameters this version adds are declared. `uri` and `type`
            // were accepted without any args block before, so declaring them now
            // would start rejecting values that used to be coerced -- a number in a
            // JSON body, say -- and turn a working call into a 400.
            'args' => [
                'id' => [
                    'required' => false,
                    'validate_callback' => function ($param): bool {
                        return is_numeric($param);
                    },
                ],
                'slug' => [
                    'required' => false,
                    'validate_callback' => function ($param): bool {
                        return is_string($param);
                    },
                ],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $emptyPayload = [
            'post' => null,
            'seo'  => null,
            'site' => null,
        ];

        $post = $this->resolvePost($request);

        /**
         * Filter the post a request resolved to, before it is serialised.
         *
         * Runs ahead of the published and password checks below, so returning a post
         * here cannot expose one those checks would reject.
         */
        $post = apply_filters('gallop_resolved_post', $post, $request);

        if (!$post instanceof \WP_Post) {
            return new WP_REST_Response($emptyPayload, 200);
        }

        // Only expose published, non-password-protected content to the headless front end.
        if ($post->post_status !== 'publish' || post_password_required($post)) {
            return new WP_REST_Response($emptyPayload, 200);
        }

        // One post context for the whole payload. The `the_content` chain, shortcodes,
        // blocks with render callbacks and the excerpt filters all read the global
        // post, so rendering without it gives whichever post was set up last -- or
        // none at all. Wrapping the three builders together rather than each one
        // separately matters too: tearing the context down between them would leave
        // anything hooked to a later builder running outside it.
        return new WP_REST_Response($this->withPostContext($post, fn (): array => [
            'post' => $this->buildPostData($post),
            'seo'  => $this->buildSeoData($post),
            'site' => $this->buildSiteData($post),
        ]), 200);
    }

    /**
     * Resolve the requested post from an id, a slug within a type, or a front-end uri.
     *
     * A uri is what a headless front end usually has, but the other two matter as well:
     * a slug is only unique within a post type, and an id is what related content
     * (a parent, an author, a referenced entry) is normally stored as.
     *
     * `type` scopes the id and slug lookups, which are the ones that can otherwise
     * reach across post types. It does NOT constrain a uri lookup: a uri already
     * identifies one post, earlier versions resolved it without consulting `type`,
     * and narrowing that now would turn a stray parameter into a silent miss for
     * anything already calling this. A site wanting uri lookups type-checked can do
     * it in `gallop_resolved_post`.
     */
    private function resolvePost(WP_REST_Request $request): ?\WP_Post
    {
        $type = (string) ($request->get_param('type') ?? '');

        $id = (int) ($request->get_param('id') ?? 0);
        if ($id > 0) {
            $post = $this->ofType(get_post($id), $type);

            // An id reaches every post type, including the ones a site keeps out of
            // its API -- form submissions, reusable blocks, menu items -- which a uri
            // never resolves to. Held to the same rule as the list endpoints.
            return ($post && self::postTypeQueryable($post->post_type)) ? $post : null;
        }

        $slug = (string) ($request->get_param('slug') ?? '');
        if ($slug !== '' && $type !== '') {
            if (!self::postTypeQueryable($type)) {
                return null;
            }

            $found = get_posts([
                'name' => sanitize_title($slug),
                'post_type' => $type,
                'post_status' => 'publish',
                'numberposts' => 1,
            ]);

            return $found[0] ?? null;
        }

        $uri = sanitize_text_field((string) ($request->get_param('uri') ?? ''));
        if ($uri === '') {
            return null;
        }

        $postId = url_to_postid($uri);
        if (empty($postId)) {
            return null;
        }

        $post = get_post($postId);

        return $post instanceof \WP_Post ? $post : null;
    }

    /**
     * Whether a post type may be read by id, slug or listing from a public request.
     *
     * Mirrors WordPress core, which exposes a type over REST only when it was registered
     * with `show_in_rest`. Accepting anything post_type_exists() knows about would reach
     * types a site deliberately kept out of its API -- submissions, internal records --
     * which core itself refuses to return.
     *
     * The type must also be publicly viewable. Core flags its own theme internals --
     * templates, template parts, global styles, navigation -- `show_in_rest` too, but
     * only serves them to users who can edit them, so `show_in_rest` alone is not a
     * promise that anonymous visitors may read them.
     *
     * A site that wants one exposed anyway opts it in through `gallop_post_type_queryable`.
     */
    public static function postTypeQueryable(string $type): bool
    {
        if (!post_type_exists($type)) {
            return false;
        }

        $object = get_post_type_object($type);
        $queryable = $object !== null && !empty($object->show_in_rest) && is_post_type_viewable($object);

        return (bool) apply_filters('gallop_post_type_queryable', $queryable, $type);
    }

    private function ofType(?\WP_Post $post, string $type): ?\WP_Post
    {
        if (!$post instanceof \WP_Post) {
            return null;
        }

        return ($type === '' || $post->post_type === $type) ? $post : null;
    }

    /**
     * Run a builder with the post set up as the global one, then restore whatever was
     * there before. Restoring rather than resetting keeps a caller that already had a
     * post in scope -- another endpoint, a template -- working afterwards.
     */
    private function withPostContext(\WP_Post $post, callable $fn): mixed
    {
        $previous = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        setup_postdata($post);

        try {
            return $fn();
        } finally {
            $GLOBALS['post'] = $previous;
            if ($previous instanceof \WP_Post) {
                setup_postdata($previous);
            } else {
                wp_reset_postdata();
            }
        }
    }

    /**
     * Serialise one post exactly as a single-post request would.
     *
     * Public so a list endpoint can build items that are identical to the same post
     * fetched on its own, filters included, rather than growing a second shape that
     * drifts from this one.
     */
    public function serialize(\WP_Post $post): array
    {
        return $this->withPostContext($post, fn (): array => $this->buildPostData($post));
    }

    private function buildPostData(\WP_Post $post): array
    {
        /**
         * Short-circuit the post payload.
         *
         * Returning an array skips building the default one, rendering included. A
         * site that replaces the payload rather than adding to it would otherwise
         * pay for `the_content` twice -- once here and once in its own builder --
         * which doubles the work and shifts the per-request counters some blocks
         * use to number themselves.
         *
         * `gallop_post_data` still runs afterwards, so anything else filtering the
         * payload keeps working.
         */
        $pre = apply_filters('gallop_pre_post_data', null, $post);
        if (is_array($pre)) {
            return apply_filters('gallop_post_data', $pre, $post);
        }

        // do_blocks() is deliberately not called here. Core already hooks it into
        // `the_content` at priority 9, so calling it first parses the blocks twice and
        // hands the rest of the chain -- wpautop, shortcodes, embeds -- input that has
        // already been rendered rather than the raw content core expects.
        //
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying WordPress core's `the_content` filter to render post content, not defining a new hook.
        $rendered = apply_filters('the_content', $post->post_content);

        $data = [
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
            // Derived fields. A headless front end needs these on nearly every page
            // and cannot reconstruct them from the columns above: a permalink depends
            // on the site's rewrite rules, and the rest are separate lookups.
            'uri' => $this->uriFor($post),
            'link' => (string) get_permalink($post),
            'excerpt' => $this->renderExcerpt($post),
            'featuredImage' => $this->featuredImage($post),
            'author' => $this->author($post),
            'categories' => $this->categories($post),
        ];

        /**
         * Filter the post payload before it is returned.
         *
         * Gallop returns the columns every site has. Anything beyond that -- post meta,
         * ACF fields, taxonomy rollups -- belongs to a particular site, so it is added
         * here by that site's own plugin rather than carried in this one.
         */
        return apply_filters('gallop_post_data', $data, $post);
    }

    /**
     * The permalink's path, which is what a front end routes on.
     *
     * Decoded, since WordPress stores non-ASCII slugs percent-encoded. Public so the
     * list endpoint returns the same value for a post as a single-post request does.
     */
    public function uriFor(\WP_Post $post): string
    {
        $permalink = get_permalink($post);
        if (!$permalink) {
            return '';
        }

        $path = wp_parse_url($permalink, PHP_URL_PATH);

        return is_string($path) ? urldecode($path) : '';
    }

    /**
     * Both filters, in this order. `get_the_excerpt` generates one from the content
     * when the field is empty, and `the_excerpt` wraps it in paragraphs; applying only
     * the first returns unwrapped text.
     */
    private function renderExcerpt(\WP_Post $post): string
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filters.
        $excerpt = apply_filters('get_the_excerpt', $post->post_excerpt, $post);

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filters.
        return (string) apply_filters('the_excerpt', $excerpt);
    }

    /**
     * The featured image with every registered size.
     *
     * Sizes come from wp_get_attachment_image_src() rather than a URL built from the
     * upload directory: the stored baseurl keeps whatever scheme was saved, so
     * hand-assembled URLs come back http:// on an https site. This helper applies
     * set_url_scheme() and the usual filters.
     *
     * @return array<string, mixed>|null
     */
    private function featuredImage(\WP_Post $post): ?array
    {
        $id = (int) get_post_thumbnail_id($post);
        if ($id <= 0) {
            return null;
        }

        $url = wp_get_attachment_image_url($id, 'full');
        if (!$url) {
            return null;
        }

        $meta = wp_get_attachment_metadata($id);

        $sizes = [];
        foreach (array_keys($meta['sizes'] ?? []) as $name) {
            $src = wp_get_attachment_image_src($id, $name);
            if (!is_array($src) || empty($src[0])) {
                continue;
            }
            $sizes[] = [
                'name' => $name,
                'sourceUrl' => $src[0],
                'width' => (int) $src[1],
                'height' => (int) $src[2],
            ];
        }

        return [
            'id' => $id,
            'sourceUrl' => $url,
            'title' => get_the_title($id),
            'altText' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'mimeType' => get_post_mime_type($id) ?: null,
            'width' => isset($meta['width']) ? (int) $meta['width'] : null,
            'height' => isset($meta['height']) ? (int) $meta['height'] : null,
            'sizes' => $sizes,
        ];
    }

    /** @return array<string, mixed>|null */
    private function author(\WP_Post $post): ?array
    {
        $id = (int) $post->post_author;
        if ($id <= 0) {
            return null;
        }

        return [
            'id' => $id,
            'name' => (string) get_the_author_meta('display_name', $id),
            'firstName' => (string) get_the_author_meta('first_name', $id),
            'lastName' => (string) get_the_author_meta('last_name', $id),
            'url' => (string) get_the_author_meta('user_url', $id),
            'avatar' => get_avatar_url($id) ?: null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function categories(\WP_Post $post): array
    {
        $terms = get_the_terms($post, 'category');
        if (!is_array($terms)) {
            return [];
        }

        return array_values(array_map(static fn ($t) => [
            'id' => (int) $t->term_id,
            'name' => $t->name,
            'slug' => $t->slug,
        ], $terms));
    }

    private function buildSeoData(\WP_Post $post): array|\stdClass
    {
        if (!function_exists('YoastSEO')) {
            return apply_filters('gallop_seo_data', new \stdClass(), $post);
        }

        $seo = YoastSEO()->meta->for_post($post->ID);
        if (!$seo) {
            return apply_filters('gallop_seo_data', new \stdClass(), $post);
        }
        $ogImages = is_array($seo->open_graph_images ?? null) ? $seo->open_graph_images : [];
        $images = array_reverse($ogImages);
        $image = array_pop($images);

        $data = [
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

        /**
         * Filter the SEO payload before it is returned.
         *
         * Also runs when no SEO plugin is active, where the value is an empty object, so
         * a site that stores its own metadata can populate it here.
         */
        return apply_filters('gallop_seo_data', $data, $post);
    }

    private function buildSiteData(\WP_Post $post): array
    {
        $data = [
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

        /**
         * Filter the site payload before it is returned.
         *
         * For values a front end needs on every page -- menus, global options, theme
         * settings -- which Gallop does not model itself.
         */
        return apply_filters('gallop_site_data', $data, $post);
    }
}
