# BlueFairy Anti-Spam — Feature Guide

---

## Honeypot Trap

A hidden field is added to every protected form. Real humans never see it — it's positioned 9,999 pixels off-screen and marked `aria-hidden`. Only bots that blindly fill in all form fields will trigger it.

The field name varies per form type (chosen from a pool of eight realistic-sounding names) to prevent bots from blacklisting a single known name.

**Forms covered:** WordPress comments, WordPress registration, WooCommerce reviews, WooCommerce My Account registration, WooCommerce shortcode checkout.

---

## Time Trap

At page load, a cryptographically signed token is embedded in a hidden field. The token contains a nonce, a timestamp, and the form type — all signed with a secret key only your site knows.

When the form is submitted, the plugin verifies the signature and checks elapsed time. If the submission arrives in under 3 seconds (default, configurable), it's rejected. Tokens expire after 24 hours.

---

## Stop Forum Spam (optional)

Stop Forum Spam maintains a database of known spammer IPs and emails from across the internet.

**Off by default.** Enabling sends visitor IP and email to their API. Only enable if your privacy policy covers this.

When enabled, you can also report blocked visitors back to Stop Forum Spam (requires a free SFS API key).

---

## What Gets Logged

| Field | Value |
|-------|-------|
| Date | UTC timestamp of the block |
| Trap | `honeypot`, `timetrap`, or `sfs` |
| Form | `wp_comment`, `wp_register`, `wc_review`, `wc_register`, `wc_checkout`, `wc_checkout_block` |
| IP | Visitor's IP address |
| Email hash | HMAC-SHA256 of the email — raw email never stored |
| Reported to SFS | Whether this block was reported |

Logs are kept for 90 days (configurable), then auto-deleted in batches.

---

## Dashboard Widget

The **Anti-Spam Shield** widget shows:

- Total spammers blocked (all time)
- Blocked this week / this month
- Breakdown by trap type

---

## Admin Log Page (Tools > Anti-Spam Log)

Searchable, filterable table of blocked submissions. Search by IP, filter by trap type, sort by date, bulk delete.

---

## Settings (Tools > Anti-Spam)

- **Spam Traps:** enable/disable honeypot, time trap, minimum submit time
- **Stop Forum Spam:** opt-in toggle, API key, confidence threshold
- **Log:** retention period in days

---

## Logged-In Users

Logged-in users bypass all traps — no friction for real customers.

---

## WooCommerce Block Checkout

Block checkout uses the Store API; POST fields don't apply. Only the SFS check runs (if enabled). Blocking done via `RouteException`.

---

## BlueFairy Forms (coming soon)

Integrates automatically via the `bfas_check_submission` filter when BlueFairy Forms launches.
