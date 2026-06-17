<?php

declare(strict_types=1);

namespace RhSmtp;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP as PhpSmtp;
use RhSmtp\Admin\SmtpGroup;

/**
 * Konfiguriert PHPMailer auf SMTP, mit kurzen Timeouts gegen Hänger, optionaler
 * Mail-Umleitung (Staging) und Test-Werkzeugen (Verbindung + Testmail).
 *
 * Passwort bevorzugt aus Konstante RH_SMTP_PASS (wp-config.php), sonst Settings.
 */
final class Smtp
{
    /** Eigene Option für das verschlüsselte Passwort (nicht im Settings-Group-Klartext). */
    public const PASS_OPTION = 'rhsmtp_pass';

    public function boot(): void
    {
        if (! $this->enabled()) {
            return;
        }

        add_action('phpmailer_init', [$this, 'configure']);

        $fromEmail = $this->setting(SmtpGroup::FIELD_FROM_EMAIL);
        if ($fromEmail !== '' && is_email($fromEmail)) {
            add_filter('wp_mail_from', static fn (): string => $fromEmail, 99);

            $fromName = $this->setting(SmtpGroup::FIELD_FROM_NAME);
            if ($fromName !== '') {
                add_filter('wp_mail_from_name', static fn (): string => $fromName, 99);
            }
        }

        // Staging-Schutz: alle Mails auf eine Adresse umleiten.
        $redirectTo = $this->setting(SmtpGroup::FIELD_REDIRECT_TO);
        if ((bool) rhbp_setting(SmtpGroup::GROUP_ID, SmtpGroup::FIELD_REDIRECT_ENABLED, false)
            && $redirectTo !== '' && is_email($redirectTo)
        ) {
            add_filter('wp_mail', [$this, 'redirectMail'], 99);
        }
    }

    public function configure(PHPMailer $phpmailer): void
    {
        $host = $this->setting(SmtpGroup::FIELD_HOST);
        if ($host === '') {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = $host;
        $phpmailer->Port = (int) ($this->setting(SmtpGroup::FIELD_PORT) ?: '587');

        $encryption = $this->setting(SmtpGroup::FIELD_ENCRYPTION, 'tls');
        if ($encryption === 'tls' || $encryption === 'ssl') {
            $phpmailer->SMTPSecure = $encryption;
        } else {
            $phpmailer->SMTPSecure = '';
            $phpmailer->SMTPAutoTLS = false;
        }

        $username = $this->setting(SmtpGroup::FIELD_USERNAME);
        if ($username !== '') {
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = $username;
            $phpmailer->Password = $this->password();
        } else {
            $phpmailer->SMTPAuth = false;
        }

        // Kurze Timeouts: Timeout = TCP-Connect, Timelimit = pro SMTP-Befehl.
        // Beide statt der 300s-Defaults, sonst hängt ein falsches Passwort/Host minutenlang.
        $timeout = $this->timeout();
        $phpmailer->Timeout = $timeout;
        $smtp = $phpmailer->getSMTPInstance();
        $smtp->Timelimit = $timeout;
    }

    /**
     * @param array<string, mixed> $atts
     * @return array<string, mixed>
     */
    public function redirectMail(array $atts): array
    {
        $redirectTo = $this->setting(SmtpGroup::FIELD_REDIRECT_TO);
        $original = $atts['to'] ?? '';
        $originalLabel = is_array($original) ? implode(', ', $original) : (string) $original;

        $atts['to'] = $redirectTo;
        $subject = is_string($atts['subject'] ?? null) ? $atts['subject'] : '';
        $atts['subject'] = '[Umgeleitet von ' . $originalLabel . '] ' . $subject;

        return $atts;
    }

    /**
     * Verbindung testen ohne Mailversand. Schneller fsockopen-Vorabcheck (failt sofort
     * bei nicht erreichbarem Host), dann SMTP-Handshake + Auth-Verify mit kurzem Timeout.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        $host = $this->setting(SmtpGroup::FIELD_HOST);
        if ($host === '') {
            return ['ok' => false, 'message' => __('Kein Host konfiguriert.', 'rh-smtp')];
        }

        // WP lädt die PHPMailer-Klassen nur bei Bedarf (erster wp_mail-Aufruf).
        // Für den eigenständigen Verbindungstest hier explizit nachladen.
        if (! class_exists(PhpSmtp::class)) {
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        }

        $port = (int) ($this->setting(SmtpGroup::FIELD_PORT) ?: '587');
        $encryption = $this->setting(SmtpGroup::FIELD_ENCRYPTION, 'tls');
        $timeout = $this->timeout();
        $connectHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;

        // Vorabcheck: ist der Host/Port überhaupt erreichbar? Failt schnell.
        $fp = @fsockopen($connectHost, $port, $errno, $errstr, $timeout);
        if (! $fp) {
            return ['ok' => false, 'message' => sprintf(__('Host nicht erreichbar: %s', 'rh-smtp'), $errstr !== '' ? $errstr : 'Timeout')];
        }
        fclose($fp);

        $smtp = new PhpSmtp();
        $smtp->Timeout = $timeout;
        $smtp->Timelimit = $timeout;
        $helo = (string) (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'localhost');

        if (! $smtp->connect($connectHost, $port, $timeout)) {
            return ['ok' => false, 'message' => $this->smtpError($smtp, __('Verbindung fehlgeschlagen', 'rh-smtp'))];
        }
        if (! $smtp->hello($helo)) {
            $smtp->quit();
            return ['ok' => false, 'message' => $this->smtpError($smtp, 'EHLO fehlgeschlagen')];
        }
        if ($encryption === 'tls') {
            if (! $smtp->startTLS()) {
                $smtp->quit();
                return ['ok' => false, 'message' => $this->smtpError($smtp, __('STARTTLS fehlgeschlagen', 'rh-smtp'))];
            }
            $smtp->hello($helo);
        }

        $username = $this->setting(SmtpGroup::FIELD_USERNAME);
        if ($username !== '') {
            if (! $smtp->authenticate($username, $this->password())) {
                $msg = $this->smtpError($smtp, __('Authentifizierung fehlgeschlagen (Benutzer/Passwort?)', 'rh-smtp'));
                $smtp->quit();
                return ['ok' => false, 'message' => $msg];
            }
        }

        $smtp->quit();

        return ['ok' => true, 'message' => __('Verbindung erfolgreich: Host erreichbar, Handshake und Login ok.', 'rh-smtp')];
    }

    /**
     * Testmail über den konfigurierten Versand senden.
     *
     * @return array{ok: bool, message: string}
     */
    public function sendTestMail(string $to): array
    {
        if (! is_email($to)) {
            return ['ok' => false, 'message' => __('Ungültige Empfängeradresse.', 'rh-smtp')];
        }

        $subject = sprintf(__('RH SMTP Testmail von %s', 'rh-smtp'), (string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $body = __('Diese Testmail wurde über RH SMTP versendet. Wenn sie ankommt, ist der SMTP-Versand korrekt konfiguriert.', 'rh-smtp');

        $sent = wp_mail($to, $subject, $body);

        return $sent
            ? ['ok' => true, 'message' => sprintf(__('Testmail an %s ausgelöst. Bitte Postfach prüfen.', 'rh-smtp'), $to)]
            : ['ok' => false, 'message' => __('Versand fehlgeschlagen. Einstellungen prüfen und Verbindung testen.', 'rh-smtp')];
    }

    private function smtpError(PhpSmtp $smtp, string $prefix): string
    {
        $error = $smtp->getError();
        $detail = '';
        if (is_array($error)) {
            $detail = trim((string) ($error['error'] ?? '') . ' ' . (string) ($error['detail'] ?? ''));
        }

        return $detail !== '' ? $prefix . ': ' . $detail : $prefix . '.';
    }

    private function timeout(): int
    {
        $value = (int) rhbp_setting(SmtpGroup::GROUP_ID, SmtpGroup::FIELD_TIMEOUT, '10');

        return $value > 0 ? $value : 10;
    }

    private function password(): string
    {
        // Sicherste Variante zuerst: Konstante in der wp-config.php (nicht in der DB).
        if (defined('RH_SMTP_PASS') && is_string(\RH_SMTP_PASS) && \RH_SMTP_PASS !== '') {
            return \RH_SMTP_PASS;
        }

        // Sonst die verschlüsselt gespeicherte Option entschlüsseln.
        return Secret::decrypt((string) get_option(self::PASS_OPTION, ''));
    }

    private function enabled(): bool
    {
        return (bool) rhbp_setting(SmtpGroup::GROUP_ID, SmtpGroup::FIELD_ENABLED, true);
    }

    private function setting(string $field, string $default = ''): string
    {
        return trim((string) rhbp_setting(SmtpGroup::GROUP_ID, $field, $default));
    }
}
