<?php

declare(strict_types=1);

namespace RhSmtp\Admin;

use RhBlueprint\Core\Settings\GroupInterface;
use RhBlueprint\Core\Settings\SettingField;

/**
 * Settings-Gruppe für den SMTP-Versand.
 *
 * Aktiv erst mit gesetztem Host. Das Passwort kann sicherer per Konstante
 * RH_SMTP_PASS in der wp-config.php gesetzt werden, die hat Vorrang vor dem Feld.
 */
final class SmtpGroup implements GroupInterface
{
    public const GROUP_ID = 'smtp';

    public const FIELD_ENABLED = 'enabled';
    public const FIELD_HOST = 'host';
    public const FIELD_PORT = 'port';
    public const FIELD_ENCRYPTION = 'encryption';
    public const FIELD_USERNAME = 'username';
    public const FIELD_FROM_EMAIL = 'from_email';
    public const FIELD_FROM_NAME = 'from_name';
    public const FIELD_TIMEOUT = 'timeout';
    public const FIELD_REDIRECT_ENABLED = 'redirect_enabled';
    public const FIELD_REDIRECT_TO = 'redirect_to';

    public function id(): string
    {
        return self::GROUP_ID;
    }

    public function tab(): string
    {
        return 'smtp';
    }

    public function title(): string
    {
        return __('SMTP', 'rh-smtp');
    }

    public function description(): string
    {
        return __('WordPress-Mails (Passwort-Reset, Benachrichtigungen) über einen echten Mailserver versenden, damit sie ankommen.', 'rh-smtp');
    }

    public function fields(): array
    {
        return [
            new SettingField(
                id: self::FIELD_ENABLED,
                type: SettingField::TYPE_BOOLEAN,
                label: __('SMTP-Versand aktivieren', 'rh-smtp'),
                description: __('Leitet wp_mail über den unten konfigurierten Server. Wirkt nur mit gesetztem Host.', 'rh-smtp'),
                default: true,
                keywords: ['smtp', 'mail', 'versand', 'aktivieren'],
            ),
            new SettingField(
                id: self::FIELD_HOST,
                type: SettingField::TYPE_TEXT,
                label: __('Host', 'rh-smtp'),
                description: __('z.B. mail.deine-domain.de', 'rh-smtp'),
                default: '',
                keywords: ['host', 'server', 'mailcow'],
            ),
            new SettingField(
                id: self::FIELD_PORT,
                type: SettingField::TYPE_TEXT,
                label: __('Port', 'rh-smtp'),
                description: __('587 für STARTTLS, 465 für SSL, 25 unverschlüsselt.', 'rh-smtp'),
                default: '587',
                keywords: ['port'],
            ),
            new SettingField(
                id: self::FIELD_ENCRYPTION,
                type: SettingField::TYPE_SELECT,
                label: __('Verschlüsselung', 'rh-smtp'),
                default: 'tls',
                choices: [
                    'tls' => 'STARTTLS (587)',
                    'ssl' => 'SSL/TLS (465)',
                    '' => __('Keine (25)', 'rh-smtp'),
                ],
                keywords: ['tls', 'ssl', 'starttls', 'verschluesselung'],
            ),
            new SettingField(
                id: self::FIELD_USERNAME,
                type: SettingField::TYPE_TEXT,
                label: __('Benutzername', 'rh-smtp'),
                description: __('Meist die volle E-Mail-Adresse des Postfachs. Leer = ohne Authentifizierung.', 'rh-smtp'),
                default: '',
                keywords: ['username', 'benutzer', 'login'],
            ),
            new SettingField(
                id: self::FIELD_FROM_EMAIL,
                type: SettingField::TYPE_EMAIL,
                label: __('Absender-Adresse', 'rh-smtp'),
                description: __('Erscheint als Von-Adresse. Sollte zur Domain des Mailservers passen (SPF/DKIM).', 'rh-smtp'),
                default: '',
                keywords: ['from', 'absender', 'email'],
            ),
            new SettingField(
                id: self::FIELD_FROM_NAME,
                type: SettingField::TYPE_TEXT,
                label: __('Absender-Name', 'rh-smtp'),
                default: '',
                keywords: ['from', 'absender', 'name'],
            ),
            new SettingField(
                id: self::FIELD_TIMEOUT,
                type: SettingField::TYPE_TEXT,
                label: __('Timeout (Sekunden)', 'rh-smtp'),
                description: __('Maximale Wartezeit für Verbindung und jeden SMTP-Befehl. WordPress/PHPMailer-Default sind 300s, das führt bei falschem Passwort oder nicht erreichbarem Server zu minutenlangen Hängern. Standard hier: 10.', 'rh-smtp'),
                default: '10',
                keywords: ['timeout', 'wartezeit', 'hang'],
            ),
            new SettingField(
                id: self::FIELD_REDIRECT_ENABLED,
                type: SettingField::TYPE_BOOLEAN,
                label: __('Alle Mails umleiten (Staging-Schutz)', 'rh-smtp'),
                description: __('Leitet JEDE ausgehende Mail an eine feste Adresse um, statt an die echten Empfänger. Auf Staging/Test aktivieren, damit keine Mail versehentlich an echte Kunden geht.', 'rh-smtp'),
                default: false,
                keywords: ['umleitung', 'redirect', 'staging', 'test'],
            ),
            new SettingField(
                id: self::FIELD_REDIRECT_TO,
                type: SettingField::TYPE_EMAIL,
                label: __('Umleitungs-Adresse', 'rh-smtp'),
                description: __('Zieladresse für die Umleitung. Der ursprüngliche Empfänger wird im Betreff vermerkt.', 'rh-smtp'),
                default: '',
                keywords: ['umleitung', 'redirect', 'adresse'],
            ),
        ];
    }
}
