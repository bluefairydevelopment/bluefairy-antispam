=== BlueFairy AntiSpam ===
Contributors: bluefairydevelopment
Tags: spam, anti-spam, honeypot, comments, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

We hate spam. No CAPTCHAs. No third-party puzzles. Just invisible traps that catch bots and let real people through.

== Description ==

We hate spam with a burning passion. We hate it so much we built this plugin for free.

**BlueFairy AntiSpam** stops bot submissions on comments, user registration, WooCommerce reviews, My Account registration, and checkout — without a single CAPTCHA in sight. No puzzles. No friction on the path to purchase. Just invisible protection working silently in the background.

**How it works:**

* **Honeypot** — A hidden field is added to your forms. Real humans never see it or fill it in. Bots do. Blocked.
* **Time trap** — A cryptographically signed timestamp is embedded at page load. Submissions that arrive faster than a human can type are rejected. Bots move fast. Too fast.
* **Stop Forum Spam (optional)** — Cross-reference visitor IPs and emails against the world's largest spam database. Disabled by default. You choose when to turn it on.

No configuration required to get started. Activate and it works. The honeypot and time trap are on by default.

**What it protects:**

* WordPress comments (traditional and REST API)
* WordPress user registration
* WooCommerce product reviews
* WooCommerce My Account registration
* WooCommerce shortcode checkout
* WooCommerce Block checkout

Logged-in users are never challenged — no friction for your actual customers.

== Installation ==

1. Upload the `bluefairy-antispam` folder to `/wp-content/plugins/`
2. Activate the plugin in **Plugins > Installed Plugins**
3. Done. Honeypot and time trap are active immediately.

To configure: **Tools > Anti-Spam**

== Frequently Asked Questions ==

= Will this break my checkout? =

No. Real customers never interact with any trap field. The honeypot is invisible. The time trap gives 24 hours before expiry — far longer than any checkout takes.

= Do I need a Stop Forum Spam account? =

No. SFS is entirely optional and off by default. Honeypot and time trap work without it.

To enable SFS checking (IP and email lookup only), turn it on in **Tools > Anti-Spam** — no account needed.

To also *report* confirmed spammers back to SFS, you need a free API key. Register at https://www.stopforumspam.com/signup and paste your key into the SFS API key field.

= Does it work with the WooCommerce Block checkout? =

Yes. The Block checkout uses the Store API, so POST-based traps don't apply — but the SFS check still runs if enabled.

= What gets stored? =

When a submission is blocked: the time, which trap fired, which form, the IP address, and a hashed (HMAC-SHA256) version of the email address. The raw email address is never stored.

= How long are logs kept? =

90 days by default. Configurable under **Tools > Anti-Spam**.

= When I mark a comment as spam in the admin, does the plugin report it? =

Yes — if Stop Forum Spam is enabled and an API key is configured, marking a comment as spam from the Comments list or post edit screen will automatically report the commenter's IP and email to SFS.

== Third Party Services ==

This plugin can optionally connect to the Stop Forum Spam service (https://www.stopforumspam.com/).

**This feature is disabled by default.** It must be explicitly enabled under Tools > Anti-Spam.

When enabled:
* Visitor IP addresses and email addresses are sent to `https://api.stopforumspam.org/api` for spam lookup.
* If you provide an API key, blocked visitors' IP and email may be reported to `https://www.stopforumspam.com/add.php`.

Stop Forum Spam privacy policy: https://www.stopforumspam.com/legal
Stop Forum Spam terms of service: https://www.stopforumspam.com/legal

No data is ever sent without your explicit opt-in.

== Screenshots ==

1. Dashboard widget showing total spammers blocked.
2. Settings page under Tools > Anti-Spam.
3. Anti-Spam log — searchable, filterable, with Reported to SFS view tab.

== Changelog ==

= 1.1.2 =
* Fixed: SFS enabled, confidence threshold, and API key fields were silently not saving. WordPress calls add_option() instead of issuing a MySQL UPDATE when the stored value equals the default registered via register_setting(), and add_option() is a no-op when the row already exists. Removed the registered default so WordPress always takes the UPDATE path.

= 1.1.1 =
* Fixed: Log filter (trap type, search) now persists after bulk-delete actions (was lost because filters were read from $_GET, which is empty after POST redirect).
* Fixed: Bulk-delete on the Anti-Spam Log now shows a success notice with the count of entries deleted.

= 1.1.0 =
* Added: when an admin marks a comment as spam from the Comments list or post edit screen, the commenter's IP and email are automatically reported to Stop Forum Spam (requires SFS enabled + API key configured).
* Added: "Reported to SFS" view tab on the Anti-Spam Log page so you can filter to see only submissions forwarded to Stop Forum Spam.
* Added: PHPUnit unit test suite (23 tests covering Honeypot and TimeTrap classes).

= 1.0.1 =
* Fixed: register WP 6.9 abilities on correct action hooks to eliminate deprecation notices.

= 1.0.0 =
* Initial release. Honeypot, time trap, optional Stop Forum Spam integration, dashboard widget, admin log.

== Upgrade Notice ==

= 1.1.2 =
Bug fix: settings page fields (SFS enabled, confidence threshold, API key) were silently not saving. Upgrade immediately if you rely on the Stop Forum Spam integration.

= 1.1.0 =
New: marking a comment as spam in admin now auto-reports to Stop Forum Spam (if enabled). New "Reported to SFS" tab on the log page.

= 1.0.0 =
Initial release.
