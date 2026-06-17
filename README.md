# RH SMTP

WordPress-Mail über einen echten SMTP-Server statt PHP `mail()`. Teil der rh-blueprint Kollektion.

Damit Passwort-Reset- und Admin-Mails ankommen (z.B. im Coolify-Container ohne MTA), mit kurzen Timeouts, Test-Werkzeugen und verschlüsselt gespeichertem Passwort.

## Was es macht

- **SMTP-Versand** über `phpmailer_init`, Absender zuverlässig über die `wp_mail_from`-Filter.
- **Kurze Timeouts**: PHPMailer-Default sind 300s, das führt bei falschem Passwort oder nicht erreichbarem Server zu minutenlangen Hängern. Standard hier: 10s (Connect und pro Befehl).
- **Verbindung testen**: schneller Vorabcheck plus SMTP-Handshake und Login, ohne Mailversand. Failt sofort statt zu hängen.
- **Testmail senden** an eine wählbare Adresse.
- **Mail-Umleitung** (Staging-Schutz): leitet jede ausgehende Mail an eine feste Adresse um, damit Test-Sites keine echten Kunden anmailen. Der ursprüngliche Empfänger steht im Betreff.

## Passwort sicher speichern

- **Am sichersten**: in der `wp-config.php` als Konstante, dann steht es gar nicht in der Datenbank:
  ```php
  define( 'RH_SMTP_PASS', 'dein-passwort' );
  ```
  Die Konstante hat Vorrang vor dem Feld.
- **Sonst**: über das maskierte, write-only Feld im SMTP-Tab. Es wird **verschlüsselt** in der Datenbank gespeichert (libsodium, Schlüssel aus den wp-config-Salts, die nicht in der DB liegen). Ein reiner DB-Leak gibt das Passwort nicht preis.

## Einstellungen

Im Backend unter **RH Blueprint → SMTP**: Host, Port, Verschlüsselung (STARTTLS/SSL/keine), Benutzername, Absender-Adresse und -Name, Timeout, Mail-Umleitung. Im Test-Werkzeuge-Bereich: Passwort setzen, Verbindung testen, Testmail senden. Erst speichern, dann testen.

## Installation

ZIP hochladen, aktivieren, Zugangsdaten eintragen, Verbindung testen. Der geteilte Core ist gebündelt.

## Voraussetzungen

WordPress 6.5+, PHP 8.1+ (libsodium ist eingebaut).
