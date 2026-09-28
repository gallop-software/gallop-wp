<?php

declare(strict_types=1);

namespace Gallop\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Lists of posts: a filtered collection, and a lightweight index of a whole post type.
 *
 * Two routes rather than one because they answer different questions. `/posts/` pages
 * through full posts and is what a listing screen renders; `/posts/list` returns only
 * what a sitemap or a static-params build needs, for every published post of a type at
 * once. Splitting them keeps the cheap query cheap: a front end enumerating 4,000 URLs
 * should not pay to render 4,000 post bodies.
 */
final class PostsEndpoint
{
    public function __construct(private readonly PostEndpoint $posts)
    {
    }

    public function register(): void
    {
        register_rest_route('gallop/v1', '/posts/list', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'handleList'],
            'permission_callback' => '__return_true',
            'args' => [
                'type' => [
                    'required' => true,
                    'validate_callback' => function ($param): bool {
                        return is_string($param) && PostEndpoint::postTypeQueryable($param);
                    },
                ],
                'parent' => [
                    'required' => false,
                    'validate_callback' => function ($param): bool {
                        return is_numeric($param);
                    },
                ],
            ],
        ]);

        register_rest_route('gallop/v1', '/posts/', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true',
            'args' => [
                'type' => [
                    'required' => true,
                    'validate_callback' => function ($param): bool {
                        return is_string($param) && PostEndpoint::postTypeQueryable($param);
                    },
                ],
                'category' => ['required' => false],
                'include' => ['required' => false],
                'meta_key' => ['required' => false], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- REST parameter name, not a query arg.
                'meta_value' => ['required' => false], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- REST parameter name, not a query arg.
                'meta_compare' => [
                    'required' => false,
                    'validate_callback' => function ($param): bool {
                        return is_string($param) && in_array(strtoupper($param), self::comparisons(), true);
                    },
                ],
                'orderby' => ['required' => false, 'default' => 'date'],
                'order' => ['required' => false, 'default' => 'DESC'],
                'size' => ['required' => false, 'default' => self::PAGE_DEFAULT],
                'offset' => ['required' => false, 'default' => 0],
                'fields' => ['required' => false],
            ],
        ]);
    }

    /**
     * Ceiling on an unpaginated list.
     *
     * The endpoint answers "every published post of this type", which is what a sitemap
     * wants, but on a large site that is an unbounded query reachable by anyone. Sites
     * that genuinely need more raise it through `gallop_posts_list_max`.
     */
    private const LIST_MAX = 5000;

    /** Page size used when a request does not ask for one. */
    private const PAGE_DEFAULT = 10;

    /**
     * Meta comparisons a caller may ask for.
     *
     * LIKE and NOT LIKE are deliberately absent. wp_postmeta indexes meta_key but not
     * meta_value, which is longtext, so a LIKE filter scans every row for the key on a
     * route that is public and uncached. The rest resolve through the meta_key index.
     * A site that needs pattern matching adds it through `gallop_meta_comparisons`.
     */
    private const COMPARISONS = ['=', '!=', '>', '>=', '<', '<=', 'IN', 'NOT IN', 'EXISTS', 'NOT EXISTS'];

    /**
     * @return array<int, string>
     */
    private static function comparisons(): array
    {
        /** Filter the meta comparison operators this endpoint accepts. */
        return (array) apply_filters('gallop_meta_comparisons', self::COMPARISONS);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $size = max(1, (int) ($request->get_param('size') ?: self::PAGE_DEFAULT));

        /**
         * Filter a ceiling on the page size, or 0 for none.
         *
         * Unset by default: a caller gets the size it asked for. Worth setting on a
         * site where this route is public and the content is large, since every row
         * is built in full -- content rendered, blocks parsed -- before `fields`
         * discards the parts that were not requested.
         */
        $pageMax = (int) apply_filters('gallop_posts_page_max', 0);
        if ($pageMax > 0) {
            $size = min($pageMax, $size);
        }
        $offset = max(0, (int) $request->get_param('offset'));
        $order = strtoupper((string) $request->get_param('order')) === 'ASC' ? 'ASC' : 'DESC';
        $orderby = $request->get_param('orderby') === 'menu_order' ? 'menu_order' : 'date';

        $args = [
            'post_type' => (string) $request->get_param('type'),
            'post_status' => 'publish',
            'posts_per_page' => $size,
            'offset' => $offset,
            // Ties broken by date DESC: menu_order on its own leaves posts sharing an
            // order in an unstable sequence, so two identical requests can disagree.
            'orderby' => $orderby === 'menu_order' ? ['menu_order' => $order, 'date' => 'DESC'] : ['date' => $order],
            'ignore_sticky_posts' => true,
            'suppress_filters' => false,
        ];

        $category = (string) ($request->get_param('category') ?? '');
        if ($category !== '') {
            $args['category_name'] = $category;
        }

        $include = (string) ($request->get_param('include') ?? '');
        if ($include !== '') {
            $ids = array_values(array_filter(array_map('intval', explode(',', $include))));
            if ($ids === []) {
                return new WP_REST_Response($this->payload([], 0, $size, $offset), 200);
            }
            $args['post__in'] = $ids;

            // Answer in the order the ids were given. A caller passing an explicit id
            // list usually means that order -- the first entry is the one being rendered
            // around -- and re-sorting it by date silently changes which one that is.
            // Skipped when the caller asked for a specific order instead.
            if (in_array($request->get_param('orderby'), [null, '', 'date'], true)) {
                $args['orderby'] = 'post__in';
                unset($args['order']);
            }
        }

        $metaQuery = $this->metaQuery($request);
        if ($metaQuery === false) {
            // Refused rather than ignored: silently dropping the filter would answer a
            // narrow question with every post of the type, which is not what was asked.
            return new WP_REST_Response(['error' => 'meta_key is not queryable'], 400);
        }
        if ($metaQuery !== null) {
            // Limited to registered show_in_rest keys and to comparisons that use the
            // meta_key index; see COMPARISONS and metaKeyQueryable().
            $args['meta_query'] = [$metaQuery]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        }

        $query = new WP_Query($args);

        $fields = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($request->get_param('fields') ?? ''))
        )));

        $items = [];
        foreach ($query->posts as $post) {
            if (!$post instanceof WP_Post) {
                continue;
            }
            $item = $this->posts->serialize($post);
            if ($fields !== []) {
                $item = array_intersect_key($item, array_flip($fields));
            }
            $items[] = $item;
        }

        return new WP_REST_Response(
            $this->payload($items, (int) $query->found_posts, $size, $offset),
            200
        );
    }

    /**
     * Build the meta filter, if one was asked for.
     *
     * `meta_value` is split on commas for IN and NOT IN, since a list is the whole
     * point of those, and left alone otherwise so a value containing a comma still
     * matches. EXISTS and NOT EXISTS take no value at all.
     *
     * Returns null when no filter was asked for, and false when one was asked for
     * against a key this site does not expose.
     *
     * @return array<string, mixed>|null|false
     */
    private function metaQuery(WP_REST_Request $request): array|null|false
    {
        $key = (string) ($request->get_param('meta_key') ?? '');
        if ($key === '') {
            return null;
        }

        if (!$this->metaKeyQueryable($key, (string) $request->get_param('type'))) {
            return false;
        }

        $compare = strtoupper((string) ($request->get_param('meta_compare') ?? '=')) ?: '=';
        if ($compare === 'EXISTS' || $compare === 'NOT EXISTS') {
            return ['key' => $key, 'compare' => $compare];
        }

        $value = (string) ($request->get_param('meta_value') ?? '');
        if ($compare === 'IN' || $compare === 'NOT IN') {
            $value = array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
        }

        return ['key' => $key, 'value' => $value, 'compare' => $compare];
    }

    /**
     * Whether a meta key may be queried from a public request.
     *
     * Protected keys -- the underscore-prefixed ones WordPress hides from the editor --
     * are refused by default, matching core's REST behaviour, which exposes only meta
     * registered with `show_in_rest`. Without this an anonymous caller could probe for
     * any key on the site by asking whether it EXISTS.
     *
     * A site that wants one of its own protected keys queried opts it in here.
     */
    private function metaKeyQueryable(string $key, string $type): bool
    {
        // Registered for this post type and flagged for REST is the site saying, in
        // WordPress's own words, that the field is part of its API, and it is the
        // only thing accepted here. Filtering by a key is a stronger power than
        // reading one -- it lets a caller search the whole site by value -- and core
        // exposes no meta filtering at all, so this starts closed. Widening a default
        // later is harmless; narrowing one is not.
        //
        // Scoped to the subtype on purpose: register_post_meta() records a key
        // against one post type, so a key registered for one type is not queryable
        // against another. show_in_rest may be an array (a schema) rather than true.
        $registered = get_registered_meta_keys('post', $type);
        $queryable = !empty($registered[$key]['show_in_rest']);

        return (bool) apply_filters('gallop_meta_key_queryable', $queryable, $key, $type);
    }

    public function handleList(WP_REST_Request $request): WP_REST_Response
    {
        $type = (string) $request->get_param('type');

        /** Filter the maximum number of posts a single list request may return. */
        $max = max(1, (int) apply_filters('gallop_posts_list_max', self::LIST_MAX, $type));

        $args = [
            'post_type' => $type,
            'post_status' => 'publish',
            'numberposts' => $max,
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => false,
        ];

        $parent = $request->get_param('parent');
        if ($parent !== null && $parent !== '') {
            $args['post_parent'] = (int) $parent;
        }

        $items = [];
        foreach (get_posts($args) as $post) {
            $items[] = [
                // WordPress stores post_name percent-encoded for non-ASCII slugs, and a
                // front end building URLs from these needs the decoded form.
                'slug' => urldecode($post->post_name),
                'status' => $post->post_status,
                'modified' => str_replace(' ', 'T', $post->post_modified),
                'uri' => $this->posts->uriFor($post),
            ];
        }

        // Surfaced rather than left implicit: a truncated list silently produces a
        // truncated sitemap, which is the kind of thing nobody notices for months.
        return new WP_REST_Response([
            'items' => $items,
            'truncated' => count($items) >= $max,
        ], 200);
    }

    /**
     * `size` is the page size actually used. It is what the caller asked for unless
     * a site set a ceiling through `gallop_posts_page_max`, in which case it is the
     * capped value -- a caller paging by its own requested size would otherwise step
     * past rows it never received. `offset` is reported for the same reason.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function payload(array $items, int $total, int $size, int $offset): array
    {
        return [
            'items' => $items,
            'total' => $total,
            'size' => $size,
            'offset' => $offset,
            'pageInfo' => [
                'hasNextPage' => ($offset + $size) < $total,
                'hasPreviousPage' => $offset > 0,
            ],
        ];
    }
}
