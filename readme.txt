=== RH SMTP ===
Contributors: robinherbeck
Tags: smtp, mail, email, deliverability
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress mail through a real SMTP server instead of PHP mail(), so password resets and notifications actually arrive.

== Description ==

RH SMTP routes wp_mail through an SMTP server (e.g. a Mailcow instance). Active only once a host is set. The password can be set more securely as a constant RH_SMTP_PASS in wp-config.php (takes precedence over the field). The sender address also sets the wp_mail_from filters so the From header is reliable.

Part of the rh-blueprint collection. Settings live under RH Blueprint > SMTP.

== Changelog ==

= 0.3.0 =
* Security: SMTP password is now stored encrypted at rest (libsodium, key from wp-config salts), entered via a write-only masked field. The RH_SMTP_PASS constant still takes precedence.
* Added: short timeouts (no long hangs on wrong password/host), test connection, test mail, and mail redirection for staging.

= 0.1.0 =
* Initial release: SMTP transport via phpmailer_init, constant-based password option, reliable From address.
