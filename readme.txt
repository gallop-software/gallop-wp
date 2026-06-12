=== Gallop ===
Contributors: gallopsoftware
Tags: headless, rest-api, nextjs, decoupled, authentication
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A REST API for headless Next.js sites: a page's post, SEO, and site data in one request, plus cookie login for authenticated front ends.

== Description ==

Keep the WordPress you write in. Lose the theme that slows you down. Gallop pipes every page's content, SEO, and settings straight into your Next.js front end in a single request, so you keep the WordPress publishing workflow your team relies on and still ship a fast, decoupled front end.

Instead of stitching together multiple core WordPress REST calls per page, your Next.js front end hits a single Gallop endpoint and gets back exactly what the page needs — the post body, an SEO block, and a site block — already joined, already resolved, ready to render in one round trip.

**The API is the point.** Gallop exposes a dedicated, Next.js-shaped REST namespace (`/wp-json/gallop/v1`) so your front-end code stays simple: one fetch, one response, ready to render.

= Everything a page needs, in one request =

Hand Gallop a URI and it returns the whole page in a single response: the full `post`, its `seo` metadata, and your global `site` data, already joined, already resolved, ready to render. `GET|POST /gallop/v1/post` handles posts and pages, and `POST /gallop/v1/category` does the same for taxonomy archives. No waterfall of `/wp/v2/posts`, `/wp/v2/media`, meta, and taxonomy calls per page — one round trip instead of five, with no JWT, API keys, or complicated authentication to set up. Your front end stays simple, and your pages load fast.

The SEO is done for you. With Yoast active, the `seo` block is populated straight from Yoast's indexables (canonical, meta description, OpenGraph, robots flags, reading time) so every page ships search-ready out of the box. Without Yoast, `seo` comes back as an empty object instead of disappearing, so your front end can check it and fall back to its own defaults.

= Login, editing, and cache revalidation, already wired up =

Moving off a WordPress template usually means rebuilding everything it gave you for free. Gallop ships with it already done. Cookie-based login is wired into the front end through the Gallop plugin, so editors sign in on your Next.js site with their normal WordPress credentials — no JWT layer, no separate auth service to stand up, and no API keys to manage.

Editing works the way your team already knows. Publish or update a post or page in WordPress and Gallop tells your Next.js site to revalidate the affected routes and clear their cache, so changes and new posts go live instantly with no full redeploy. Login, editing, and cache invalidation are all baked in, so you keep the WordPress workflow your team relies on and still ship a fast Next.js front end.

= Settings and custom post types, configured from WordPress =

Point Gallop at your Next.js production URL and it 301-redirects public WordPress front-end requests to the matching path on your headless host. Admin, REST API, and previews are left untouched.

Register REST-enabled custom post types from the Post Types tab and they're immediately available through the Gallop namespace — no `register_post_type()` boilerplate, no developer round trip. Core post types are left alone, and content you create survives a deactivate/uninstall.

= Real sites running Gallop =

Gallop powers production sites today — among them dougnewby.com (30k+ images, 5k+ pages, 3k+ blog posts, fetched from WordPress), douglasnewby.com, dallasmodernhomesforsale.com (multiple domains reading from one WordPress install), tpaynelaw.com, and cmwelectric.com. Every one edits in WordPress and ships a fast Next.js front end — headless content, real Google rankings and structured data, no theme holding it back, and the same WordPress publishing experience.

= REST endpoints =

All endpoints live under the `gallop/v1` namespace.

* `GET|POST /gallop/v1/post` — Resolve a front-end URI to a post and return `post`, `seo`, and `site` payloads. Accepts `uri` as a parameter.
* `POST /gallop/v1/category` — Resolve a category URI to a term and return `category`, `seo`, and `site` payloads.
* `POST /gallop/v1/auth/login` — Cookie-based login for a headless front end. Accepts `username`, `password`, and optional `remember`. Rate-limited per username/IP.
* `POST /gallop/v1/auth/logout` — Log out the current user.
* `GET  /gallop/v1/auth/session` — Return the current user payload, or `{ "user": null }` when not logged in.

= Login support =

Gallop ships with everything a Next.js site needs to authenticate users against WordPress — no extra plugin, no JWT layer to wire up:

* **Cookie-based login** via `POST /gallop/v1/auth/login`, which calls WordPress's built-in `wp_signon()` and sets the standard auth cookies. A Next.js front end on the same registered domain can then make authenticated requests with credentials included.
* **Session checks** via `GET /gallop/v1/auth/session`, so your front end can tell whether a visitor is logged in and render accordingly.
* **Logout** via `POST /gallop/v1/auth/logout`.
* **Brute-force protection** out of the box: five failed attempts per username + client IP within fifteen minutes return HTTP 429 until the window expires, with optional reverse-proxy IP awareness for sites behind Cloudflare or a load balancer.

= SEO integration =

When the [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) plugin is active, the `seo` block in the post and category responses is populated from Yoast's indexable data (canonical, meta description, OpenGraph fields, robots flags, reading time, etc.). Without Yoast, `seo` is returned as an empty object so clients can branch safely.

= Action hooks =

* `gallop_auth_login_success` — fires after a successful REST login. Args: `WP_User $user`, `WP_REST_Request $request`.
* `gallop_auth_login_failed` — fires after a failed REST login. Args: `string $username`, `WP_REST_Request $request`.
* `gallop_auth_logout` — fires after a REST logout. Args: `WP_User $user`, `WP_REST_Request $request`.

= Filter hooks =

* `gallop_trust_forwarded_ip` — filter the boolean controlling whether reverse-proxy IP headers (`CF-Connecting-IP`, `X-Forwarded-For`) are trusted when rate-limiting REST auth. Defaults to the "Trust proxy IP headers" setting. Only enable behind a trusted proxy that overwrites these headers, otherwise the per-IP rate limit can be bypassed by spoofing them.

= Data stored =

* `gallop_post_types` (option) — your custom post type definitions.
* `gallop_nextjs_production_url` (option) — the redirect target, if configured.
* `gallop_trust_forwarded_ip` (option) — whether to trust reverse-proxy IP headers when rate-limiting auth (default off).
* `gallop_auth_*` (transients) — short-lived login rate-limit counters.

== Installation ==

1. Upload the `gallop` folder to `/wp-content/plugins/`, or install the ZIP from the Plugins screen.
2. Activate **Gallop** from the Plugins screen.
3. Point your Next.js front end at `https://your-wp-site.example/wp-json/gallop/v1` and start fetching `post`, `category`, and `auth` endpoints.
4. (Optional) Open **Gallop** in the admin menu to register custom post types and set your Next.js production URL.

Requires PHP 8.1 or higher. The plugin will refuse to boot and show an admin notice on older PHP versions.

== Screenshots ==

1. The Gallop REST API in action — a request to the `gallop/v1` namespace returning post, SEO, and site data.
2. Login UI: the headless auth flow signing in against `/gallop/v1/auth/login` with standard WordPress cookies.
3. Settings tab: point Gallop at your Next.js production URL and configure proxy IP trust for auth rate limiting.
4. Post Types tab: register REST-enabled custom post types (no code) and view their slugs and REST endpoints.

== Frequently Asked Questions ==

= Do I have to use Next.js? =

No. Gallop's REST endpoints are framework-agnostic JSON. Next.js is the reference target and the redirect feature is named for it, but any HTTP client can consume the API.

= Does the redirect break the WordPress admin or previews? =

No. The redirect runs on `template_redirect` only, skips any request with a `preview=true` or `_wp*` query parameter, and never touches `/wp-admin` or `/wp-json`. Leave the Next.js URL setting blank to disable redirection entirely.

= How does authentication work for the headless client? =

`/gallop/v1/auth/login` calls `wp_signon()` and sets the standard WordPress auth cookies. A Next.js front end on the same registered domain can then call `/gallop/v1/auth/session` (or any other authenticated REST endpoint) with credentials included. There is no JWT layer — cookie auth is intentional.

= Is the login endpoint rate-limited? =

Yes. Five failed attempts per username + client IP within fifteen minutes return HTTP 429 until the window expires. Successful logins clear the counter.

= What is the "Trust proxy IP headers" setting? =

By default Gallop uses `REMOTE_ADDR` for the per-IP portion of the login rate limit. If your site sits behind a trusted reverse proxy (Cloudflare, a load balancer, etc.) that overwrites the client-IP headers, enable **Trust proxy IP headers** on the Gallop settings screen so `CF-Connecting-IP` / `X-Forwarded-For` are used instead. Leave it off on direct-served sites — turning it on without a trusted proxy lets attackers spoof those headers to bypass the rate limit. The setting can also be overridden in code via the `gallop_trust_forwarded_ip` filter.

= Do I need Yoast SEO? =

No. If Yoast is not active the `seo` field in responses is an empty object. With Yoast active, Gallop reads from its indexables to populate canonical, OpenGraph, and robots data.

= Does Gallop modify core post types? =

No. Posts, pages, media, and built-in taxonomies are left alone. Only post types you create through the Gallop admin screen are registered by this plugin.

= What happens if I deactivate or delete the plugin? =

Deactivating stops Gallop from registering its post types and REST routes; content created under those post types stays in the database. Deleting the plugin (via the Plugins screen) additionally removes the `gallop_post_types`, `gallop_nextjs_production_url`, and `gallop_trust_forwarded_ip` options plus any leftover login rate-limit transients. Posts authored under your custom post types are intentionally left in place so they survive an uninstall/reinstall.

== Privacy ==

Gallop does not send any data to external services. All data stays on your WordPress site.

The `/gallop/v1/auth/login` endpoint authenticates users with WordPress's built-in `wp_signon()` and sets the standard WordPress auth cookies. To mitigate brute-force attacks, Gallop temporarily stores failed-login counters in WordPress transients keyed by username and by the requesting IP address. These counters expire automatically (typically within 15 minutes) and are removed on plugin uninstall.

No personal data is shared with third parties. No tracking, analytics, or telemetry is performed.

== Changelog ==

= 0.1.1 =
* Documentation: expanded the plugin description.

= 0.1.0 =
* Initial release.
* Admin UI for registering REST-enabled custom post types.
* `/gallop/v1/post` and `/gallop/v1/category` endpoints with optional Yoast SEO payloads.
* Cookie-based REST auth endpoints (`/auth/login`, `/auth/logout`, `/auth/session`) with per-username/IP rate limiting.
* Optional Next.js production URL redirect for public front-end requests.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
