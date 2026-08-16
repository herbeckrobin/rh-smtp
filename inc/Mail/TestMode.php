<?php

declare(strict_types=1);

namespace RhSmtp\Mail;

use RhSmtp\Admin\SmtpGroup;

/**
 * Testmodus für den Mailversand.
 *
 * Der Unfall, den das verhindert: ein Klon der Kundenseite läuft als Staging,
 * jemand löst dort eine Bestellung oder ein Formular aus, und die Bestätigung
 * geht an einen echten Kunden. Oder schlimmer, ein Import spielt echte Daten
 * ein und die Seite verschickt reihenweise Mails an Adressen, die dort gar
 * nichts verloren haben.
 *
 * Deshalb greift der Modus nicht nur für die Suite, sondern für JEDE Mail der
 * Installation (Kontaktformular, Shop, fremde Plugins). Er hängt dafür am
 * wp_mail-Filter, also an der Transportebene.
 *
 * Die Vorgabe kommt aus der Umgebung, nicht aus einem Schalter, den jemand
 * beim Klonen vergisst: auf local und staging ist er an, auf production aus.
 * Dasselbe Muster benutzt rh-sync für seine Inbound-Rechte.
 *
 * Löst den früheren Staging-Schutz ab (die Felder redirect_enabled und
 * redirect_to). Die Zieladresse ist dieselbe geblieben, ein bereits gesetzter
 * alter Schalter gilt weiterhin als Umleitung, damit bestehende Installationen
 * nicht plötzlich wieder scharf verschicken.
 */
final class TestMode
{
    public const MODE_AUTO = 'auto';
    public const MODE_OFF = 'off';
    public const MODE_REDIRECT = 'redirect';
    public const MODE_BLOCK = 'block';

    public function boot(): void
    {
        // Sehr früh, damit die Umleitung vor allem anderen greift.
        add_filter('wp_mail', [$this, 'apply'], 1);
    }

    /**
     * Greift der Testmodus gerade, und wenn ja wie?
     */
    public static function active(): string
    {
        $mode = (string) rhbp_setting(SmtpGroup::GROUP_ID, SmtpGroup::FIELD_TEST_MODE, self::MODE_AUTO);

        // Wer den alten Staging-Schutz eingeschaltet hatte, bleibt umgeleitet,
        // auch wenn der neue Schalter noch auf seiner Vorgabe steht.
        if ($mode === self::MODE_AUTO
            && (bool) rhbp_setting(SmtpGroup::GROUP_ID, SmtpGroup::FIELD_REDIRECT_ENABLED, false)
        ) {
            return self::MODE_REDIRECT;
        }

        if ($mode === self::MODE_AUTO) {
            return in_array(wp_get_environment_type(), ['local', 'development', 'staging'], true)
                ? self::MODE_REDIRECT
                : self::MODE_OFF;
        }

        return in_array($mode, [self::MODE_OFF, self::MODE_REDIRECT, self::MODE_BLOCK], true)
            ? $mode
            : self::MODE_OFF;
    }

    public static function isActive(): bool
    {
        return self::active() !== self::MODE_OFF;
    }

    /**
     * @param array<string, mixed> $atts
     * @return array<string, mixed>
     */
    public function apply(array $atts): array
    {
        $mode = self::active();

        if ($mode === self::MODE_OFF) {
            return $atts;
        }

        $original = $this->recipients($atts['to'] ?? '');

        if ($mode === self::MODE_BLOCK) {
            // Kein Empfänger heisst: wp_mail bricht ab. Der Versuch steht
            // trotzdem im Protokoll, dafür sorgt das Mail-Log.
            $atts['to'] = [];
            $atts['subject'] = $this->prefix($atts['subject'] ?? '', __('BLOCKIERT', 'rh-smtp'));

            return $atts;
        }

        $target = $this->target();

        if ($target === '') {
            return $atts;
        }

        $atts['to'] = [$target];
        $atts['cc'] = [];
        $atts['bcc'] = [];
        $atts['subject'] = $this->prefix($atts['subject'] ?? '', __('TEST', 'rh-smtp'));
        $atts['message'] = $this->banner($original, (string) ($atts['message'] ?? ''));

        return $atts;
    }

    /**
     * Vermerkt oben in der Mail, an wen sie eigentlich gegangen wäre. Ohne
     * diese Zeile rätselt man später, warum eine Bestellbestätigung im
     * eigenen Postfach liegt.
     *
     * @param array<int, string> $original
     */
    private function banner(array $original, string $message): string
    {
        $empfaenger = $original === [] ? __('unbekannt', 'rh-smtp') : implode(', ', $original);

        $hinweis = sprintf(
            /* translators: %s: ursprüngliche Empfänger */
            __('Testmodus: diese Mail wurde umgeleitet. Sie wäre an %s gegangen.', 'rh-smtp'),
            $empfaenger
        );

        if (stripos($message, '<html') !== false || stripos($message, '<table') !== false) {
            $html = '<div style="margin:0 0 16px;padding:12px 14px;background:#fcf8e3;border:1px solid #f0e0a0;'
                . 'border-radius:6px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
                . 'font-size:13px;line-height:1.5;color:#8a6d00;">' . esc_html($hinweis) . '</div>';

            // In ein vollständiges Dokument gehört der Hinweis hinter <body>,
            // sonst steht er ausserhalb und manche Clients schlucken ihn.
            if (stripos($message, '<body') !== false) {
                return (string) preg_replace('/(<body[^>]*>)/i', '$1' . $html, $message, 1);
            }

            return $html . $message;
        }

        return $hinweis . "\n\n" . $message;
    }

    private function prefix(string $subject, string $tag): string
    {
        return '[' . $tag . '] ' . $subject;
    }

    /**
     * @param mixed $to
     * @return array<int, string>
     */
    private function recipients(mixed $to): array
    {
        if (is_array($to)) {
            return array_values(array_map('strval', $to));
        }

        $to = (string) $to;

        return $to === '' ? [] : array_map('trim', explode(',', $to));
    }

    private function target(): string
    {
        $configured = (string) rhbp_setting(SmtpGroup::GROUP_ID, SmtpGroup::FIELD_REDIRECT_TO, '');

        if (is_email($configured)) {
            return $configured;
        }

        $admin = (string) get_option('admin_email', '');

        return is_email($admin) ? $admin : '';
    }
}
