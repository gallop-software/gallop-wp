=== Gallop ===
Contributors: webplantmedia
Tags: headless, rest-api, custom-post-types, decoupled
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Headless WordPress for Next.js: register custom post types and expose post, category, and auth data through a dedicated REST namespace.

== Description ==

Gallop is a lightweight headless-WordPress toolkit aimed at sites that render their public front end with Next.js (or any framework that can call a REST API) while continuing to author content in WordPress.

It does three things:

1. **UI-driven custom post types.** Add, edit, and remove public, REST-enabled custom post types from a Gallop admin screen — no code, no `register_post_type()` boilerplate. Definitions are stored as an option and registered on every request.
2. **A purpose-built REST namespace (`/wp-json/gallop/v1`).** Endpoints return the exact shape a Next.js page typically needs (post body, SEO block, site block) in one round trip, avoiding multiple core REST calls per page.
3. **Optional front-end redirect.** Set your Next.js production URL in settings and Gallop will 301-redirect public WordPress front-end requests to the matching path on your headless host, while leaving the admin, REST API, and preview flows untouched.

= REST endpoints =

All endpoints live under the `gallop/v1` namespace.

* `GET|POST /gallop/v1/post` — Resolve a front-end URI to a post and return `post`, `seo`, and `site` payloads. Accepts `uri` as a parameter.
* `POST /gallop/v1/category` — Resolve a category URI to a term and return `category`, `seo`, and `site` payloads.
* `POST /gallop/v1/auth/login` — Cookie-based login for a headless front end. Accepts `username`, `password`, and optional `remember`. Rate-limited per username/IP.
* `POST /gallop/v1/auth/logout` — Log out the current user.
* `GET  /gallop/v1/auth/session` — Return the current user payload, or `{ "user": null }` when not logged in.

= SEO integration =

When the [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) plugin is active, the `seo` block in the post and category responses is populated from Yoast's indexable data (canonical, meta description, OpenGraph fields, robots flags, reading time, etc.). Without Yoast, `seo` is returned as an empty object so clients can branch safely.

= Action hooks =

* `gallop_auth_login_success` — fires after a successful REST login. Args: `WP_User $user`, `WP_REST_Request $request`.
* `gallop_auth_login_failed` — fires after a failed REST login. Args: `string $username`, `WP_REST_Request $request`.
* `gallop_auth_logout` — fires after a REST logout. Args: `WP_User $user`, `WP_REST_Request $request`.

= Data stored =

* `gallop_post_types` (option) — your custom post type definitions.
* `gallop_nextjs_production_url` (option) — the redirect target, if configured.
* `gallop_auth_*` (transients) — short-lived login rate-limit counters.

== Installation ==

1. Upload the `gallop` folder to `/wp-content/plugins/`, or install the ZIP from the Plugins screen.
2. Activate **Gallop** from the Plugins screen.
3. Open **Gallop** in the admin menu to register custom post types and (optionally) set your Next.js production URL.
4. Point your headless front end at `https://your-wp-site.example/wp-json/gallop/v1`.

Requires PHP 8.1 or higher. The plugin will refuse to boot and show an admin notice on older PHP versions.

== Frequently Asked Questions ==

= Do I have to use Next.js? =

No. Gallop's REST endpoints are framework-agnostic JSON. Next.js is the reference target and the redirect feature is named for it, but any HTTP client can consume the API.

= Does the redirect break the WordPress admin or previews? =

No. The redirect runs on `template_redirect` only, skips any request with a `preview=true` or `_wp*` query parameter, and never touches `/wp-admin` or `/wp-json`. Leave the Next.js URL setting blank to disable redirection entirely.

= How does authentication work for the headless client? =

`/gallop/v1/auth/login` calls `wp_signon()` and sets the standard WordPress auth cookies. A Next.js front end on the same registered domain can then call `/gallop/v1/auth/session` (or any other authenticated REST endpoint) with credentials included. There is no JWT layer — cookie auth is intentional.

= Is the login endpoint rate-limited? =

Yes. Five failed attempts per username + client IP within fifteen minutes return HTTP 429 until the window expires. Successful logins clear the counter.

= Do I need Yoast SEO? =

No. If Yoast is not active the `seo` field in responses is an empty object. With Yoast active, Gallop reads from its indexables to populate canonical, OpenGraph, and robots data.

= Does Gallop modify core post types? =

No. Posts, pages, media, and built-in taxonomies are left alone. Only post types you create through the Gallop admin screen are registered by this plugin.

= What happens if I deactivate or delete the plugin? =

Deactivating stops Gallop from registering its post types and REST routes; content created under those post types stays in the database. Deleting the plugin (via the Plugins screen) additionally removes the `gallop_post_types`, `gallop_nextjs_production_url`, and `gallop_trust_forwarded_ip` options plus any leftover login rate-limit transients. Posts authored under your custom post types are intentionally left in place so they survive an uninstall/reinstall.

== Changelog ==

= 0.2.1 =
* Initial public release.
* Admin UI for registering REST-enabled custom post types.
* `/gallop/v1/post` and `/gallop/v1/category` endpoints with optional Yoast SEO payloads.
* Cookie-based REST auth endpoints (`/auth/login`, `/auth/logout`, `/auth/session`) with per-username/IP rate limiting.
* Optional Next.js production URL redirect for public front-end requests.

== Upgrade Notice ==

= 0.2.1 =
Initial public release.
