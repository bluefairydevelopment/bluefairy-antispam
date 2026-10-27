# BlueFairy Anti-Spam

Invisible spam protection for WordPress and WooCommerce. Honeypot, time trap, and optional Stop Forum Spam integration. No CAPTCHAs. No friction. Zero tolerance for spam.

**Always free.** Because we hate spam that much.

---

## Requirements

- PHP 8.1+
- WordPress 6.5+
- WooCommerce 7.0+ (optional — WC integration loads only if WooCommerce is active)

---

## Installation

1. Upload the `bluefairy-antispam/` directory to `wp-content/plugins/`
2. Activate via **Plugins > Installed Plugins**
3. That's it. Honeypot and time trap are active immediately.

Optional configuration: **Tools > Anti-Spam**

---

## Features

| Feature | Default | Notes |
|---------|---------|-------|
| Honeypot | On | CSS-hidden field; bots fill it in |
| Time trap | On | HMAC-signed token; blocks submissions under 3 seconds |
| Stop Forum Spam check | Off | Opt-in — sends IP/email to external API |
| SFS reporting | Off | Requires SFS API key |
| Dashboard widget | Always | Block stats in wp-admin |
| Log page | Always | Tools > Anti-Spam Log |

---

## Forms Protected

- WordPress comments (traditional POST + REST API)
- WordPress user registration
- WooCommerce product reviews
- WooCommerce My Account registration
- WooCommerce shortcode checkout
- WooCommerce Block checkout (Store API)

Logged-in users bypass all traps — no friction for real customers.

---

## Settings Reference

All settings under **Tools > Anti-Spam**.

| Setting | Default | Description |
|---------|---------|-------------|
| `honeypot_enabled` | `true` | Toggle honeypot trap |
| `timetrap_enabled` | `true` | Toggle time trap |
| `min_time_seconds` | `3` | Minimum seconds before submission accepted |
| `sfs_enabled` | `false` | Enable Stop Forum Spam lookup (opt-in) |
| `sfs_check_threshold` | `1` | SFS confidence score threshold (0-100) |
| `log_retention_days` | `90` | Days to keep log entries |

SFS API key stored separately in `bfas_sfs_api_key` (non-autoloaded option).

---

## BlueFairy Forms Integration (coming soon)

The plugin exposes a `bfas_check_submission` filter for integration with BlueFairy Forms:

```php
$result = apply_filters( 'bfas_check_submission', null, [
    'form_type' => 'my_custom_form',
    'ip'        => $ip,
    'email'     => $email,
    'post_data' => $_POST,
] );
// $result = null (clean) or trap name string (spam)
```

---

## Development

```bash
composer install
vendor/bin/phpstan analyse   # PHPStan level 8
vendor/bin/phpunit           # Unit tests
```

---

## License

GPL-2.0-or-later

## Support the free plugin

This plugin is free and always will be. If it saves you time, you can [buy us a coffee on Ko-fi](https://ko-fi.com/bluefairydevelopment) ☕ — it helps keep the free plugins tested and maintained.

Found a bug or have an idea? [Open an issue](../../issues/new/choose). Security problems: see [SECURITY.md](SECURITY.md).
