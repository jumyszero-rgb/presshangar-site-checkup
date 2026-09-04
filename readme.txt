=== PressHangar Site Checkup ===
Contributors: presshangar
Tags: health check, site health, performance, plugins, duplicate plugins
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A read-only site checkup that explains findings in plain language: spots overlapping plugins, hints at plugin heaviness, lists unused ones.

== Description ==

PressHangar Site Checkup is a **site doctor**, not a mechanic: it looks, it explains, and it advises — it never touches anything. There is nothing in this plugin that deactivates, deletes, or edits any plugin, theme, post, or option other than PressHangar Site Checkup's own two settings. Every diagnostic runs only when you click a button; nothing runs automatically, and there is no background profiler slowing down your site.

**Click "Run checkup" and PressHangar Site Checkup runs three read-only checks:**

* **Overlapping plugins** — PressHangar Site Checkup's flagship check. Looks at your active plugins and guesses which "job" each one does (SEO, caching, security, backups, forms, image optimization, sitemaps, related posts, link management, RSS import, code snippets). If two or more active plugins are doing the same job, you'll see a plain-language explanation of why that's usually worth avoiding — for example, two SEO plugins can both try to output meta tags and sitemaps, confusing search engines — and a reminder that trimming down is entirely your call to make by hand.

* **Heaviness hints** — Not a precision profiler (PressHangar Site Checkup doesn't run one, on purpose). Instead: how many plugins are active, how much data WordPress loads on *every single page view* via "autoload" options, and roughly how much disk space each active plugin uses. All labelled honestly as approximate hints, not exact timings. A separate, optional "Measure front-page load time" button times a single request to your own front page — it only runs when you click it.

* **Unused plugins & themes** — Lists plugins that are installed but not active, and themes that aren't your current theme (or its parent). Unused items still take up disk space and can quietly become a security weak point if they're never updated. PressHangar Site Checkup only lists them — deleting anything is done by you, from the normal Plugins/Themes screens.

Results are cached after each run, so revisiting the PressHangar Site Checkup screen shows "Diagnosed N minutes ago" instead of re-running the checks — nothing here runs on a schedule or on your site's normal front-end requests.

== Installation ==

1. Upload the `presshangar-site-checkup` folder to `/wp-content/plugins/` and activate it.
2. Go to **Tools > PressHangar Site Checkup** and click "Run checkup".

== Frequently Asked Questions ==

= Does PressHangar Site Checkup ever change my site? =

No. PressHangar Site Checkup is read-only. The only things it ever writes are its own two settings (`phcheckup_settings`, which check to run, and `phcheckup_last_scan`, the cached result of your last checkup). It never calls WordPress's plugin/theme deactivation or deletion functions, never edits a post, and never touches any other plugin's data.

= Does PressHangar Site Checkup slow my site down? =

No. There is no background job, no cron event, and no code that runs on a normal front-end page view. Every check only runs when you click "Run checkup" (or, separately, "Measure front-page load time") on the PressHangar Site Checkup admin screen, and each run is a single, bounded request.

= How does the "overlapping plugins" check work? =

It matches each active plugin's folder name against a small dictionary of well-known plugins (Yoast SEO, Rank Math, WP Super Cache, Wordfence, UpdraftPlus, Contact Form 7, and more) to work out what job it does. For anything not in that dictionary, it falls back to a keyword guess based on the plugin's name and description, clearly noted as a lower-confidence guess. If your active plugins include more than one of Musubiemu's own PressHangar-suite plugins in the same category (for example SEOPilot and CitePilot), they're never flagged against each other — they're built to work together.

= What does "heaviness hints" actually measure? =

Three cheap, safe-to-read signals: your active plugin count, the total size of "autoload" options (data WordPress loads into memory on every page, regardless of whether that page needs it), and the disk space used by each active plugin's folder. None of these are a substitute for a real profiling tool (Query Monitor and similar developer tools measure actual request time); they're meant as an approachable starting point for a non-developer site owner.

= Can I turn off individual checks? =

Yes. Each of the three checks (overlapping plugins, heaviness hints, unused items) has its own on/off toggle on the PressHangar Site Checkup screen. All three are on by default, since none of them change anything about your site.

== External services ==

This plugin does not use, connect to, or send any data to any third-party service. The optional front-page timing check makes a single HTTP request to your own site's home URL (the same site the plugin runs on, via `home_url( '/' )`) to measure how long your front page takes to respond. No data leaves your server and no external or third-party endpoint is ever contacted.

== Changelog ==

= 0.3.1 =
* Review fixes: register the admin page directly under Tools (removed the shared suite-menu helper and its generic function/menu names); bind the autoload row-limit query with $wpdb->prepare(); normalize plugin-directory paths with wp_normalize_path(); use the core Sitemaps API for the sitemap index URL.

= 0.3.0 =
* Add: a state-aware "Getting started" panel that guides first-time use as a live checklist.
* Add: bundled translations for Japanese, French, Spanish, German, Brazilian Portuguese and Italian, so the whole interface is localized out of the box in seven languages.

= 0.2.1 =
* Add: groups under a single "PressHangar" admin menu when two or more PressHangar plugins are active (otherwise keeps its normal location).

= 0.2.0 =
* New: "Output conflicts" check — flags a discouraged-indexing + SEO-plugin clash, two sitemaps being served, and duplicated head tags (canonical/og:title/description) actually found on the front page.
* Improved: overlapping-plugin detection now covers many more categories (breadcrumbs, table of contents, redirects, affiliate links, broken-link checkers, scheduled publishing, indexing, analytics, cookie/consent) and the full PressHangar suite.

= 0.1.1 =
* Add: Japanese translation (bundled) + text-domain loading (Domain Path: /languages).

= 0.1.0 =
* Initial release: overlapping-plugin detection, heaviness hints (plugin count, autoload size, disk footprint, optional front-page timing), and unused plugin/theme inventory.
