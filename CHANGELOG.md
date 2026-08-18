# Changelog

All notable changes to BlueFairy Anti-Spam are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
Versioning: Major.Minor.Patch (major = architecture shift, minor = new feature, patch = bug/security fix)

---

## [1.1.0] — 2026-08-18

### Added

- **Mark-as-spam → SFS reporting**: when an admin marks a comment as spam from the Comments list or post edit screen, the commenter's IP and email are automatically reported to Stop Forum Spam (requires SFS enabled + API key configured). The block is logged as trap type `manual_spam`.
- **PHPUnit test suite**: 23 unit tests covering `Honeypot` (field name determinism, spam detection, disabled state) and `TimeTrap` (missing/tampered/expired/fast tokens, valid token, disabled state, form-scoping).
- **"Reported to SFS" view tab**: the Anti-Spam Log now has an "All / Reported to SFS" tab so you can filter the log to see only submissions that have been forwarded to Stop Forum Spam.

---

## [1.0.1] — 2026-08-17

### Fixed

- Register WP 6.9 abilities on `wp_abilities_api_categories_init` / `wp_abilities_api_init` instead of `init` to eliminate deprecation notices

---

## [1.0.0] — 2026-08-17

### Added

**Honeypot Trap**
- Hidden field rendered on all supported forms using a CSS class (never inline style)
- Field name chosen deterministically from a pool of 8 realistic-sounding names per form type
- `aria-hidden="true"` and `tabindex="-1"` on input; wrapper div hides it off-screen

**Time Trap**
- HMAC-SHA256 signed token embedded at page render time
- Plugin-owned secret (non-autoloaded option, 32-byte random hex, generated on activation)
- Tokens expire after 24 hours; submissions arriving in under 3 seconds (configurable) are blocked

**Stop Forum Spam Integration**
- Disabled by default — requires explicit admin opt-in (WP.org Guideline 7 compliant)
- Checks visitor IP and email against `api.stopforumspam.org/api`
- 7-day transient cache for detected spammers, 1-hour cache for clean checks
- Circuit breaker: skips SFS calls after 5 consecutive HTTP failures (resets on success)
- Async reporting via WP-Cron with 24-hour dedup per IP+email pair

**WooCommerce Integration**
- Product reviews, My Account registration, shortcode checkout, Block checkout (Store API)
- Block checkout uses `woocommerce_store_api_checkout_order_processed` + `RouteException`

**WordPress Integration**
- Comments (traditional + REST via `rest_pre_insert_comment`)
- User registration via `registration_errors`

**Admin**
- Settings page under Tools > Anti-Spam (Settings API)
- Log page under Tools > Anti-Spam Log (WP_List_Table with search, filter, bulk delete)
- Dashboard widget with total/weekly/monthly block counts and trap breakdown

**WP 6.9+ Abilities**
- Category: `bluefairy-antispam`
- `bluefairy-antispam/get-log-entries`, `get-stats`, `get-settings` (all readonly, manage_options)

**Data**
- Table `{prefix}bfas_log`: id, blocked_at, trap, form, ip, email_hash, reported_sfs
- Email stored as HMAC-SHA256 hash — raw address never persisted
- Daily cron cleanup with LIMIT 1000 batch DELETE
- Full `uninstall.php`: table drop, all options, all transients, cron clearance

**Infrastructure**
- `spl_autoload_register` — no Composer required for production
- PHP 8.1+ and WordPress 6.5+ minimum requirements
- PHPStan level 8 with szepeviktor/phpstan-wordpress stubs
- WP Playground `blueprint.json` with networking disabled and seeded demo data
