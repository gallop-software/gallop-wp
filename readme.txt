=== Gallop ===
Contributors: gallopsoftware
Tags: headless, rest-api, nextjs, decoupled, authentication
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A REST API for headless Next.js sites: a page's post, SEO, and site data in one request, plus comments and member accounts that stay in WordPress.

== Description ==

Gallop is the headless WordPress REST API for Next.js that's FAST and SIMPLE. Keep the WordPress you write in, lose the theme that slows you down — and ship your whole page from one request.

> The WordPress editing experience your team already knows, with the speed of a fully decoupled Next.js front end. One endpoint, one response, done.

[Visit the Gallop homepage &amp; documentation →](https://gallop.software/headless-wordpress)

Most headless setups make you stitch together a waterfall of core WordPress REST calls per page — `/wp/v2/posts`, `/wp/v2/media`, meta, and taxonomy — then bolt on a JWT layer and an auth service just to log a user in. Gallop replaces all of that.

**One request. The whole page.** Hand Gallop a URI and it returns the post body, an SEO block, and your global site data — already joined, already resolved, ready to render. **The API is the point:** a dedicated, Next.js-shaped REST namespace (`/wp-json/gallop/v1`) so your front-end code stays simple — one fetch, one response, ready to render.

#### Why choose Gallop?

* **One round trip instead of five.** Everything a page needs — `post`, `seo`, and `site` — in a single response.
* **No JWT, no separate auth service.** Members log in, sign up, and reset passwords against WordPress's own user table, through your front end's server, and reading content needs no key at all.
* **Comments that stay in WordPress.** Visitors comment on your front end; WordPress stores, moderates, and notifies exactly as it does for its own comment form.
* **SEO done for you.** With Yoast active, the `seo` block ships search-ready out of the box.
* **No-code custom post types.** Register REST-enabled CPTs from the admin — no `register_post_type()` boilerplate.
* **Instant publishing.** Publish in WordPress and Gallop revalidates the affected Next.js routes automatically — no full redeploy.
* **Framework-agnostic JSON.** Next.js is the reference target, but any HTTP client can consume the API.
* **Keep your workflow.** Your editors keep the exact WordPress publishing experience they already rely on.

= Everything a page needs, in one request =

Hand Gallop a URI and it returns the whole page in a single response: the full `post`, its `seo` metadata, and your global `site` data, already joined, already resolved, ready to render. `GET|POST /gallop/v1/post` handles posts and pages, and `POST /gallop/v1/category` does the same for taxonomy archives. No waterfall of `/wp/v2/posts`, `/wp/v2/media`, meta, and taxonomy calls per page — one round trip instead of five, with no JWT, API keys, or complicated authentication to set up. Your front end stays simple, and your pages load fast.

The SEO is done for you. With Yoast active, the `seo` block is populated straight from Yoast's indexables (canonical, meta description, OpenGraph, robots flags, reading time) so every page ships search-ready out of the box. Without Yoast, `seo` comes back as an empty object instead of disappearing, so your front end can check it and fall back to its own defaults.

= Login, members, and comments, already wired up =

https://vimeo.com/1200535505

Moving off a WordPress template usually means rebuilding everything it gave you for free. Gallop ships with it already done. Readers log in, sign up, reset a password, and edit their profile on your Next.js site, and every one of them is an ordinary WordPress user. Editors sign in with their normal WordPress credentials. There is no JWT layer and no separate auth service to stand up: your front end's server talks to WordPress with one key, and keeps its own session for each member.

Comments stay in WordPress too. Visitors and members comment on your front end; WordPress stores, moderates, and notifies exactly as it does for its own comment form.

= Settings and custom post types, configured from WordPress =

https://vimeo.com/1196416170

Point Gallop at your Next.js production URL and it 301-redirects public WordPress front-end requests to the matching path on your headless host. Admin, REST API, and previews are left untouched.

Register REST-enabled custom post types from the Post Types tab and they're immediately available through the Gallop namespace — no `register_post_type()` boilerplate, no developer round trip. Core post types are left alone, and content you create survives a deactivate/uninstall.

= Trusted on real production sites =

Gallop isn't a proof of concept — it powers live production sites today:

* **douglasnewby.com**
* **cmwelectric.com**
* **winx.gallop.software**

Every one edits in WordPress and ships a fast Next.js front end — headless content, real Google rankings and structured data, no theme holding it back, and the same WordPress publishing experience your team already knows.

[See how Gallop powers headless WordPress →](https://gallop.software/headless-wordpress)

= REST endpoints =

All endpoints live under the `gallop/v1` namespace.

* `GET|POST /gallop/v1/post` — Resolve a post and return `post`, `seo`, and `site` payloads. Accepts `uri`, `id`, or `slug` with `type`.
* `GET|POST /gallop/v1/posts` — A filtered, paged collection of posts of one `type`. Accepts `category`, `include`, `meta_key` / `meta_value` / `meta_compare`, `orderby`, `order`, `size`, `offset`, and `fields`.
* `GET|POST /gallop/v1/posts/list` — Slug, uri, and modified date for every published post of a `type`, for sitemaps and static builds. Optional `parent`.
* `POST /gallop/v1/category` — Resolve a category URI to a term and return `category`, `seo`, and `site` payloads.
* `GET  /gallop/v1/comments` — The approved comments on a `post`, oldest first, as a flat list with `parent` ids. Public.
* `POST /gallop/v1/comments` — Submit a visitor's or a member's comment. Requires the API key.
* `POST /gallop/v1/members/login`, `POST /gallop/v1/members`, `POST /gallop/v1/members/confirm`, `POST /gallop/v1/members/reset-request`, `POST /gallop/v1/members/reset`, `GET|PATCH /gallop/v1/members/{id}` — Members: log in, sign up, confirm an address, reset a password, read and edit a profile. All require the API key.
* `POST /gallop/v1/auth/login`, `POST /gallop/v1/auth/logout`, `GET /gallop/v1/auth/session` — The older cookie-based login, kept for front ends on the same registered domain that use it.

= Members =

Readers get accounts, and WordPress is the only place they live. Every route takes the API key with the **Manage members** permission and is called by your front end's **server**, which keeps its own session for the member; WordPress is asked only when someone logs in or changes something.

* **Log in** with an email address or username and a password, checked by `wp_authenticate()` as the visitor, so login-protection plugins see the visitor's address. No WordPress cookies are set.
* **Sign up**: nothing is created until the reader opens the confirmation email; then they become a Subscriber, verified, and logged in. An address that already has an account is only marked subscribed. An email is never a way past a password.
* **Reset a password** with WordPress's own reset keys, emailed as a link to your front end.
* **Profiles**: name, email, password, and two email choices. Changing the email or password needs the current one and ends every session the front end holds, through a `sessionVersion` handed back with each request.
* **Comments as themselves**, recorded as theirs, and accepted where only registered users may comment.

Five wrong passwords for one login name from one address within fifteen minutes return HTTP 429; each address is sent at most one email every ten minutes and five a day; login, sign-up and reset answer the same whether or not the address is known.

= Comments =

Show WordPress comments on a headless front end, and let visitors leave them, without giving up anything WordPress does with a comment.

* `GET /gallop/v1/comments?post=<id>` returns the post's approved `comments`, oldest first, each with `id`, `parent`, `authorName`, `authorUrl`, `isPostAuthor`, `avatar`, `dateGmt`, and rendered `content`. Build the thread from `parent`. It also returns `open`, `count`, `requireNameEmail`, `threadDepth`, and `truncated`. A commenter's email address, IP address, and browser are never returned.
* `POST /gallop/v1/comments` takes `post`, `parent`, `authorName`, `authorEmail`, `authorUrl`, `content`, and the visitor's `ip`, `userAgent`, and `referer`. It answers `201` with the `comment`, its `status` (`approved`, `hold`, `spam`, or `trash`), and the post's new `count`.

A submitted comment is handed to `wp_handle_comment_submission()`, the function WordPress's own comment form uses. Your Discussion settings, moderation, the duplicate and flood checks, anti-spam plugins such as Akismet, and notification emails all behave as they do for a comment left on a WordPress site. Core's own REST route for comments sends no notification emails.

Send the request from your front end's **server**, never from the browser: it carries the API key.

= API key =

Reading from Gallop needs no key. Writing does: a request that changes something in WordPress on a visitor's behalf has to prove it comes from your front end's own server.

* **Generate it** under Gallop → Settings → Front-end connection. It is shown once. Gallop stores only a hash of it.
* **Send it** in the `X-Gallop-WP-Key` header, over HTTPS.
* **Keep it on your server**, in a secret environment variable such as `GALLOP_WP_API_KEY`. Never give it a `NEXT_PUBLIC_` prefix, and never put it in code.
* **Or set it in `wp-config.php`** with `define( 'GALLOP_WP_API_KEY', 'gallopwp_…' );`. A key set there takes priority over a generated one.
* **Permissions are off by default.** A key can do only what you tick: "Submit comments" and "Manage members". A permission added by a later version is never switched on for you.
* **Regenerating** replaces the key at once.

Gallop removes the key from the request as soon as it has been checked, so other plugins that read or forward request headers never see it.

= SEO integration =

When the [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) plugin is active, the `seo` block in the post and category responses is populated from Yoast's indexable data (canonical, meta description, OpenGraph fields, robots flags, reading time, etc.). Without Yoast, `seo` is returned as an empty object so clients can branch safely.

= Action hooks =

* `gallop_auth_login_success` — fires after a successful REST login. Args: `WP_User $user`, `WP_REST_Request $request`.
* `gallop_auth_login_failed` — fires after a failed REST login. Args: `string $username`, `WP_REST_Request $request`.
* `gallop_auth_logout` — fires after a REST logout. Args: `WP_User $user`, `WP_REST_Request $request`.
* `gallop_comment_submitted` — fires after a submitted comment has been saved, whatever its status. Args: `WP_Comment $comment`, `WP_REST_Request $request`.
* `gallop_comment_rejected` — fires when WordPress refused a submitted comment. Args: `WP_Error $error`, `WP_REST_Request $request`.
* `gallop_api_key_generated` — fires after a key has been generated. The key is not passed. Args: `string $keyId`.
* `gallop_api_key_verified` — fires when a request presented a valid key for a permission it holds. Args: `string $keyId`, `string $capability`, `WP_REST_Request $request`.
* `gallop_api_key_failed` — fires when a request presented a key that is not this site's. Args: `WP_REST_Request $request`.

= Filter hooks =

* `gallop_trust_forwarded_ip` — filter the boolean controlling whether reverse-proxy IP headers (`CF-Connecting-IP`, `X-Forwarded-For`) are trusted when rate-limiting REST auth. Defaults to the "Trust proxy IP headers" setting. Only enable behind a trusted proxy that overwrites these headers, otherwise the per-IP rate limit can be bypassed by spoofing them.
* `gallop_comment_data` — filter one comment as it is returned. `gallop_comments_data` — filter the whole response to a request for a post's comments.
* `gallop_comments_max` — the most comments one request returns. Default 500.
* `gallop_comment_avatar_sizes` — the avatar sizes returned with each comment, in pixels. Default 48 and 96.
* `gallop_comments_cache_control` — the `Cache-Control` header sent with a post's comments.
* `gallop_api_key_capabilities` — the permissions a key can be granted. Adding one makes it available to tick; it grants it to nothing.
* `gallop_api_key_max_failed_attempts` — how many wrong keys one address may present in fifteen minutes. Default 10.
* `gallop_api_key_transport_secure` — whether a request arrived over a connection safe to carry the key.

= Data stored =

* `gallop_post_types` (option) — your custom post type definitions.
* `gallop_nextjs_production_url` (option) — the redirect target, if configured.
* `gallop_trust_forwarded_ip` (option) — whether to trust reverse-proxy IP headers when rate-limiting auth (default off).
* `gallop_api_key_hash` (option) — a hash of your API key, with the date it was generated and its last four characters. Never the key itself.
* `gallop_api_key_permissions` (option) — what the key is allowed to do.
* `gallop_verified`, `gallop_subscribed`, `gallop_reply_emails`, `gallop_session_version` (user meta) — a member's confirmed address, email choices, and session version. `_gallop_reply_notified` (comment meta) — a reply already emailed about.
* `gallop_auth_*` (transients) — short-lived login rate-limit counters.
* `gallop_pending_*` (transients) — sign-ups awaiting confirmation, up to 48 hours: address, names, and a hash of the link's key.
* `gallop_confirm_sent_*` (transients) — when each address was last emailed, for a day.
* `gallop_key_fail_*` (transients) — short-lived counters of wrong API keys, by address.
* `gallop_key_reveal_*` (transient) — a newly generated key, held for up to two minutes so it can be shown once after the page reloads, then deleted.

Comments submitted through Gallop are ordinary WordPress comments, and members are ordinary WordPress users, stored where WordPress stores them.

== Installation ==

1. Upload the `gallop` folder to `/wp-content/plugins/`, or install the ZIP from the Plugins screen.
2. Activate **Gallop** from the Plugins screen.
3. Point your Next.js front end at `https://your-wp-site.example/wp-json/gallop/v1` and start fetching the `post` and `category` endpoints.
4. (Optional) Open **Gallop** in the admin menu to register custom post types and set your Next.js production URL.
5. (Optional) To accept comments or members from your front end, generate an API key under **Front-end connection**, tick **Submit comments** and **Manage members**, and give the key to your front end's server. Member emails link to the Next.js production URL, so set it first.

Requires PHP 8.1 or higher. The plugin will refuse to boot and show an admin notice on older PHP versions.

== Screenshots ==

1. The Gallop REST API in action — a request to the `gallop/v1` namespace returning post, SEO, and site data.
2. Login UI: a reader signing in on the front end with their WordPress account.
3. Settings tab: point Gallop at your Next.js production URL, configure proxy IP trust for auth rate limiting, and generate the API key your front end writes with.
4. Post Types tab: register REST-enabled custom post types (no code) and view their slugs and REST endpoints.

== Frequently Asked Questions ==

= Do I have to use Next.js? =

No. Gallop's REST endpoints are framework-agnostic JSON. Next.js is the reference target and the redirect feature is named for it, but any HTTP client can consume the API.

= Does the redirect break the WordPress admin or previews? =

No. The redirect runs on `template_redirect` only, skips any request with a `preview=true` or `_wp*` query parameter, and never touches `/wp-admin` or `/wp-json`. Leave the Next.js URL setting blank to disable redirection entirely.

= How do members log in on the front end? =

The front end's server sends the login name, password, and the visitor's address to `/gallop/v1/members/login` with the API key. Gallop checks the password with `wp_authenticate()`, the function behind wp-login.php, and answers with the member's details; the front end then keeps a session of its own for them. No WordPress cookies are involved, so the front end can be on any domain. A two-factor plugin on the WordPress login screen does not apply to front-end logins. The older `/gallop/v1/auth/*` routes, which set WordPress's own cookies for a front end on the same registered domain, still work.

= What happens when someone signs up? =

Nothing is written to the users table. Gallop keeps the address and names for up to 48 hours and emails a confirmation link to the front end. When the link is opened the front end calls `/gallop/v1/members/confirm`, and only then is a user created, with the Subscriber role. If the address already had an account, it is marked verified (and subscribed, if that was asked) and the person is told to log in; the link never logs anyone into an existing account. WordPress's "Anyone can register" setting is not consulted: the Manage members permission is the switch.

= What emails does Gallop send? =

Four, all through `wp_mail()` and all to the member: a confirmation link, a note that an address already has an account, a password reset link, and, if the member asked for it, a message when someone replies to their comment. Every link points at your Next.js production URL. The `gallop_member_email` filter can change or suppress any of them.

= Is the login endpoint rate-limited? =

Yes. Five failed attempts per login name + client IP within fifteen minutes return HTTP 429 until the window expires. Successful logins clear the counter. Login, sign-up, and reset requests also answer the same whether or not the account exists, and take the same time, so they cannot be used to find out who has an account.

= What is the "Trust proxy IP headers" setting? =

By default Gallop uses `REMOTE_ADDR` for the per-IP portion of the login rate limit. If your site sits behind a trusted reverse proxy (Cloudflare, a load balancer, etc.) that overwrites the client-IP headers, enable **Trust proxy IP headers** on the Gallop settings screen so `CF-Connecting-IP` / `X-Forwarded-For` are used instead. Leave it off on direct-served sites — turning it on without a trusted proxy lets attackers spoof those headers to bypass the rate limit. The setting can also be overridden in code via the `gallop_trust_forwarded_ip` filter.

= Which posts can comments be read from? =

Published posts of a public type that are not password protected. Anything else, a missing post included, answers the same `404 gallop_comments_post_not_found`, so the route cannot be used to find out that a draft exists. The newest 500 comments are returned by default; `truncated` is `true` when there are more, and `gallop_comments_max` raises the ceiling.

= What can submitting a comment be refused with? =

Every refusal has a code, and WordPress's own reason in `data.reason`.

* The key: `gallop_key_missing` (401), `gallop_key_invalid` (401), `gallop_key_forbidden` (403, the key lacks the permission), `gallop_https_required` (403), `gallop_key_rate_limited` (429).
* The site does not accept it: `gallop_comment_closed` (403), `gallop_comment_login_required` (403), `gallop_comments_post_not_found` (404).
* The request: `gallop_comment_name_email_required`, `gallop_comment_invalid_email`, `gallop_comment_empty`, `gallop_comment_too_long`, `gallop_comment_name_too_long`, `gallop_comment_email_too_long`, `gallop_comment_url_too_long`, `gallop_comment_invalid_ip` (all 400).
* The reply: `gallop_comment_invalid_parent`, `gallop_comment_thread_too_deep`, `gallop_comment_threading_disabled` (all 400). A parent must be an approved comment on the same post.
* WordPress's checks: `gallop_comment_duplicate` (409), `gallop_comment_flood` (429).
* Anything else: `gallop_comment_rejected` (400, another plugin refused it), `gallop_comment_save_failed` (500).

= How does WordPress see the visitor rather than my server? =

Your server passes on the visitor's IP address, browser, and the page they commented from. While the comment is being saved, Gallop presents those details to WordPress and to other plugins in place of the server's own, then puts the originals back. That is what lets WordPress record, throttle, and spam-check each comment against the person who wrote it. The details are only accepted from a request whose key has been verified.

= The key is refused with gallop_https_required. Why? =

The key is only accepted over HTTPS, or in a `local` environment. If your site is served over HTTPS and you still see this, it is probably behind a proxy that ends TLS without telling WordPress. Fixing `is_ssl()` in `wp-config.php` is the right answer, since WordPress's own Application Passwords need it too. The `gallop_api_key_transport_secure` filter is there if you cannot.

= What happens after too many wrong keys? =

After ten wrong keys from one address in fifteen minutes, further wrong keys from it are answered `429 gallop_key_rate_limited`. A correct key is never refused for that reason, so nobody can lock your front end out by sending wrong ones. If the key in `wp-config.php` is not valid, every request that needs a key is refused and an admin notice says so.

= Why do comments need an API key when reading does not? =

Anyone may read a published post's comments, as on any WordPress site. Submitting one is different: it arrives from your front end's server on a visitor's behalf, carrying the visitor's IP address for WordPress to record and check. The key is how Gallop knows the request, and that address, really came from your server. Without it, anyone could post straight to WordPress, skip whatever spam checks your front end runs, and claim any address they liked.

= I lost my API key. Can I see it again? =

No. Gallop stores a hash of the key, not the key. Regenerate it under Gallop → Settings → Front-end connection and install the new one. The old key stops working as soon as you do.

= Are comments held for moderation? =

That is decided by your Discussion settings, exactly as for a comment left on a WordPress site. The response to a submitted comment reports its `status`, so your front end can tell a visitor that their comment is waiting for approval.

= Do I need Yoast SEO? =

No. If Yoast is not active the `seo` field in responses is an empty object. With Yoast active, Gallop reads from its indexables to populate canonical, OpenGraph, and robots data.

= Does Gallop modify core post types? =

No. Posts, pages, media, and built-in taxonomies are left alone. Only post types you create through the Gallop admin screen are registered by this plugin.

= What happens if I deactivate or delete the plugin? =

Deactivating stops Gallop from registering its post types and REST routes; content created under those post types stays in the database. Deleting the plugin (via the Plugins screen) additionally removes every `gallop_*` option and transient. Posts authored under your custom post types are intentionally left in place so they survive an uninstall/reinstall. So are comments and member accounts; what Gallop recorded about members (a few user meta rows) is removed.

== Privacy ==

Gallop does not send any data to external services. All data stays on your WordPress site.

The login endpoints authenticate users with WordPress's built-in functions. To mitigate brute-force attacks, Gallop temporarily stores failed-login counters in WordPress transients keyed by a hash of the login name and the visitor's IP address. These counters expire automatically (typically within 15 minutes) and are removed on plugin uninstall.

A sign-up is kept as a transient for up to 48 hours (the address, the names typed, and a hash of the confirmation key), then becomes a WordPress user or expires. Gallop emails members through `wp_mail()` only what they asked for: confirmation and reset links, a note that an address already has an account, and replies to their comments if they turned that on. It records when each address was last emailed, for a day, to limit how often one can be written to.

When a request presents a wrong API key, Gallop counts it in a transient keyed by a hash of the requesting IP address. That counter also expires within 15 minutes and is removed on uninstall.

A comment submitted through `/gallop/v1/comments` is stored by WordPress with the commenter's name, email address, IP address, and browser, as WordPress stores every comment. Those details are supplied by your front end, and the IP address and browser are presented to other active plugins while the comment is saved, as they would be for a comment left on the site itself: an anti-spam plugin may send them to its own service, under its own privacy terms. Comments are returned by `/gallop/v1/comments` without the email address, IP address, or browser. If avatars are switched on, each comment includes an avatar address from your avatar service (Gravatar by default), which contains a hash of the commenter's email address, as WordPress's own themes and REST API expose.

No personal data is shared with third parties. No tracking, analytics, or telemetry is performed.

== Changelog ==

= 1.2.0 =
* Added members: `POST /gallop/v1/members/login`, `POST /gallop/v1/members` (sign up), `/members/confirm`, `/members/reset-request`, `/members/reset`, and `GET|PATCH /members/{id}`. A front end's server can let readers log in with `wp_authenticate()`, sign up with email confirmation, reset a password with WordPress's own keys, and edit a profile, with no WordPress cookies involved. All need the API key with the new "Manage members" permission, which starts off.
* A member logged in on the front end can comment as themselves: `POST /gallop/v1/comments` takes `user` and `sessionVersion`, and `GET` reports `openToMembers`.
* A member can ask to be emailed when someone replies to their comment.
* Accounts with the Subscriber role that have never chosen count as subscribed to new posts by email; a member's own choice always wins.
* New filters: `gallop_member_data`, `gallop_member_email`. New actions: `gallop_member_login`, `gallop_member_login_failed`, `gallop_member_signup_requested`, `gallop_member_verified`, `gallop_member_registered`, `gallop_member_password_reset`, `gallop_member_updated`.
* Existing endpoints and their responses are unchanged, except that `GET /gallop/v1/comments` gains one field.

= 1.1.0 =
* Added `/gallop/v1/comments`. `GET` returns a post's approved comments as a flat list with `parent` ids, ready to thread. `POST` submits a visitor's comment through WordPress's own comment form handling, so Discussion settings, moderation, the duplicate and flood checks, anti-spam plugins, and notification emails all behave as they do for a comment left on a WordPress site.
* Added an API key for front ends that write to WordPress. Generate it under Gallop → Settings → Front-end connection, or define `GALLOP_WP_API_KEY` in `wp-config.php`. Only a hash is stored. It is sent in the `X-Gallop-WP-Key` header, over HTTPS only.
* What a key may do is switched on one permission at a time, and every permission starts off. "Submit comments" is the first.
* New filters: `gallop_comment_data`, `gallop_comments_data`, `gallop_comments_max`, `gallop_comment_avatar_sizes`, `gallop_comments_cache_control`, `gallop_api_key_capabilities`, `gallop_api_key_max_failed_attempts`, `gallop_api_key_transport_secure`. New actions: `gallop_comment_submitted`, `gallop_comment_rejected`, `gallop_api_key_generated`, `gallop_api_key_verified`, `gallop_api_key_failed`.
* Existing endpoints and their responses are unchanged.

= 1.0.0 =
* First stable release. No functional changes from 0.2.0: the REST API and its response shapes are now considered stable.

= 0.2.0 =
* Added `/gallop/v1/posts` for filtered collections: post type, category, id list, meta filtering, ordering, paging, and field selection. Defaults to 10 per page; a site can set a ceiling with `gallop_posts_page_max`. The response reports the `size` and `offset` actually used.
* Added `/gallop/v1/posts/list`, a lightweight index of every published post of a type, for sitemaps and static builds. Capped at 5,000 by default (`gallop_posts_list_max`) and reports `truncated` when the cap is reached.
* `/gallop/v1/post` can now resolve by `id`, or by `slug` together with `type`, as well as by `uri`. `uri` lookups behave exactly as before.
* The `post` payload gains `uri`, `link`, `excerpt`, `featuredImage`, `author`, and `categories`. Existing fields are unchanged.
* `/gallop/v1/auth/login` and `/auth/session` now also return a `wp_rest` nonce, so a browser session can make cookie-authenticated REST requests.
* New filters to extend payloads without forking: `gallop_pre_post_data`, `gallop_post_data`, `gallop_seo_data`, `gallop_site_data`, `gallop_category_data`, `gallop_category_seo_data`, `gallop_category_site_data`, `gallop_resolved_post`.
* Only public post types registered with `show_in_rest` can be read by id, slug, or listing, and only meta keys registered for that type with `show_in_rest` can be filtered on. This matches core REST behaviour. Widen either with `gallop_post_type_queryable` or `gallop_meta_key_queryable`. Accepted meta comparisons can be changed with `gallop_meta_comparisons`.
* Post content now renders with the post set as the global post, so shortcodes and dynamic blocks that read it see the right one. Block content is no longer run through `do_blocks()` twice, which had let `wpautop` add stray `<p>` and `<br>` tags.

= 0.1.1 =
* Documentation: expanded the plugin description.

= 0.1.0 =
* Initial release.
* Admin UI for registering REST-enabled custom post types.
* `/gallop/v1/post` and `/gallop/v1/category` endpoints with optional Yoast SEO payloads.
* Cookie-based REST auth endpoints (`/auth/login`, `/auth/logout`, `/auth/session`) with per-username/IP rate limiting.
* Optional Next.js production URL redirect for public front-end requests.

== Upgrade Notice ==

= 1.2.0 =
Adds member accounts for front ends: login, sign-up with email confirmation, password reset, and profiles. Nothing changes for existing requests, and the new permission starts switched off.

= 1.1.0 =
Adds comment endpoints and an API key for front ends that write to WordPress. Nothing changes for existing requests. No key exists until you generate one, and its permissions start switched off.

= 1.0.0 =
First stable release. No functional changes from 0.2.0.

= 0.2.0 =
Adds collection and index endpoints, id and slug lookups, and richer post payloads. Existing requests and fields are unchanged, but rendered block content no longer carries the stray `<p>` and `<br>` tags earlier versions added. Check any front-end styling that relied on them.

= 0.1.0 =
Initial release.
