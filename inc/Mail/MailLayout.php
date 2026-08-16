<?php

declare(strict_types=1);

namespace RhSmtp\Mail;

use RhBlueprint\Core\Mail\MailMessage;

/**
 * Der gemeinsame Rahmen für alle Mails der Suite.
 *
 * Warum Tabellen und Inline-CSS: Mailprogramme sind kein Browser. Outlook
 * rendert mit der Word-Engine (kein Flexbox, kein Grid, kein float), Gmail
 * wirft Style-Blöcke im Kopf weg, und die meisten Clients kennen keine
 * externen Dateien. Was hier steht, ist bewusst altmodisch, dafür kommt es
 * überall gleich an.
 *
 * Ohne Bilder, solange niemand eines mitgibt: ein Logo aus dem Netz wird von
 * den meisten Clients erst nach Nachfrage geladen, dann steht in der Mail ein
 * leerer Rahmen. Ausserdem verrät ein solcher Abruf dem Absender, wann jemand
 * die Mail geöffnet hat, was bei einer Sicherheitsmeldung niemand braucht.
 *
 * Bei Mails an Endkunden ist die Rechnung eine andere: dort ist ein Logo
 * erwartbar, und eine Bestellbestätigung ohne Absender-Erkennung wirkt wie
 * Spam. Deshalb der Haken `rh-blueprint/mail/brand_logo`, den nur externe
 * Mails nutzen. Systemmeldungen bleiben reine Schrift auf Farbfläche.
 *
 * Die Farben sind dieselben wie im Backend der Suite (assets/settings.css).
 */
final class MailLayout
{
    // Palette, spiegelt die Design-Tokens des Backends.
    private const BLUE = '#3858e9';
    private const INK = '#1d2327';
    private const TEXT = '#2c3338';
    private const MUTED = '#50575e';
    private const FAINT = '#757575';
    private const BORDER = '#dcdcde';
    private const CANVAS = '#f6f7f7';
    private const WHITE = '#ffffff';

    /** @var array<string, array{bg: string, fg: string, line: string}> */
    private const TONES = [
        MailMessage::TONE_OK => ['bg' => '#edf7ed', 'fg' => '#1e6b2e', 'line' => '#b8e0bf'],
        MailMessage::TONE_INFO => ['bg' => '#f0f3ff', 'fg' => '#2c3fc4', 'line' => '#c7d1fb'],
        MailMessage::TONE_WARN => ['bg' => '#fcf8e3', 'fg' => '#8a6d00', 'line' => '#f0e0a0'],
        MailMessage::TONE_ALERT => ['bg' => '#fcf0f1', 'fg' => '#b32d2e', 'line' => '#f3c9cb'],
    ];

    private const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    /**
     * Vollständiges HTML-Dokument der Mail.
     */
    public static function html(MailMessage $message, string $footerNote = ''): string
    {
        $body = '';

        foreach ($message->blocks() as $block) {
            $body .= self::renderBlock($block);
        }

        $preheader = self::preheader($message);

        return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
            . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="de">'
            . '<head>'
            . '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'
            . '<meta name="viewport" content="width=device-width, initial-scale=1" />'
            . '<meta http-equiv="X-UA-Compatible" content="IE=edge" />'
            // Sagt Apple Mail und Outlook, dass die Mail für Hell gebaut ist. Ohne das
            // invertieren sie im Dunkelmodus eigenmächtig und zerlegen die Kontraste.
            . '<meta name="color-scheme" content="light only" />'
            . '<meta name="supported-color-schemes" content="light" />'
            . '<title>' . esc_html($message->title) . '</title>'
            . '<!--[if mso]><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->'
            . '<style type="text/css">'
            . 'body{margin:0;padding:0;width:100%!important;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}'
            . 'table,td{border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt}'
            . 'a{color:' . self::BLUE . '}'
            . '@media only screen and (max-width:620px){'
            . '.rhbp-shell{width:100%!important}'
            . '.rhbp-pad{padding-left:20px!important;padding-right:20px!important}'
            . '}'
            . '</style>'
            . '</head>'
            . '<body style="margin:0;padding:0;background:' . self::CANVAS . ';">'
            . $preheader
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . self::CANVAS . ';">'
            . '<tr><td align="center" style="padding:24px 12px;">'
            . '<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->'
            . '<table role="presentation" class="rhbp-shell" width="600" cellpadding="0" cellspacing="0" border="0"'
            . ' style="width:600px;max-width:600px;background:' . self::WHITE . ';border:1px solid ' . self::BORDER . ';border-radius:8px;">'
            . self::header($message)
            . '<tr><td class="rhbp-pad" style="padding:24px 28px 28px 28px;font-family:' . self::FONT . ';font-size:15px;line-height:1.6;color:' . self::TEXT . ';">'
            . $body
            . '</td></tr>'
            . self::footer($footerNote)
            . '</table>'
            . '<!--[if mso]></td></tr></table><![endif]-->'
            . '</td></tr></table>'
            . '</body></html>';
    }

    /**
     * Kopfzeile: Titel auf Akzentfläche, darunter klein die Website.
     *
     * Interne Meldungen tragen das Blau der Suite, damit sie im Postfach sofort
     * als Systemmeldung erkennbar sind. Mails an Endkunden tragen dagegen die
     * Farbe der Website: dort ist die Suite kein Absender, den jemand kennen
     * müsste, und ein fremdes Blau im Kundenpostfach wirkt wie ein Fremdkörper.
     */
    private static function header(MailMessage $message): string
    {
        $accent = self::accent($message);
        $subColor = $message->isExternal() ? 'rgba(255,255,255,.75)' : '#dfe4fd';

        $subtitle = $message->subtitle !== ''
            ? '<div style="margin-top:4px;font-size:13px;line-height:1.4;color:' . $subColor . ';">' . esc_html($message->subtitle) . '</div>'
            : '';

        /**
         * Ein Logo über dem Titel, für Mails an Endkunden.
         *
         * Der Shop hatte dafür einen eigenen Rahmen gebaut, mit eigenem Logo
         * und eigener Farbe. Damit gab es zwei Mail-Optiken auf einer Website.
         * Hier ist der Haken, damit ein Modul sein Aussehen mitbringen kann,
         * ohne den Rahmen zu ersetzen.
         *
         * @param string      $url     Adresse des Logos. Leer: nur der Titel.
         * @param MailMessage $message
         */
        $logoUrl = $message->isExternal()
            ? (string) apply_filters('rh-blueprint/mail/brand_logo', '', $message)
            : '';

        $logo = $logoUrl !== ''
            ? '<div style="margin-bottom:10px;"><img src="' . esc_url($logoUrl) . '" alt="" style="max-height:40px;max-width:220px;display:inline-block;"></div>'
            : '';

        return '<tr><td class="rhbp-pad" style="background:' . esc_attr($accent) . ';padding:20px 28px;border-radius:8px 8px 0 0;font-family:' . self::FONT . ';">'
            . $logo // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- oben escapt.
            . '<div style="font-size:17px;font-weight:600;line-height:1.3;color:' . self::WHITE . ';">' . esc_html($message->title) . '</div>'
            . $subtitle // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- oben escapt.
            . '</td></tr>';
    }

    /**
     * Akzentfarbe der Mail. Für Kundenmails darf ein Modul die Farbe der
     * Website durchreichen, der Shop tut das für seine Bestellmails.
     */
    private static function accent(MailMessage $message): string
    {
        if (! $message->isExternal()) {
            return self::BLUE;
        }

        /**
         * Akzentfarbe für Mails an Endkunden.
         *
         * @param string      $accent  Hex-Farbe.
         * @param MailMessage $message Die Nachricht, für modulabhängige Farben.
         */
        $accent = (string) apply_filters('rh-blueprint/mail/brand_accent', self::BLUE, $message);

        return preg_match('/^#[0-9a-f]{3,8}$/i', $accent) === 1 ? $accent : self::BLUE;
    }

    private static function footer(string $note): string
    {
        /**
         * Was unten in jeder Mail steht, wenn der Aufrufer nichts mitgibt.
         *
         * Bei Mails an Endkunden gehört dort die Anschrift des Absenders hin.
         * Der Shop hatte das im eigenen Rahmen, hier ist der Haken dafür.
         *
         * @param string $note
         */
        if ($note === '') {
            $note = (string) apply_filters('rh-blueprint/mail/footer_note', '');
        }

        $text = $note !== ''
            ? nl2br(esc_html($note))
            : esc_html__('Diese Nachricht kommt automatisch von deiner Website.', 'rh-smtp');

        return '<tr><td class="rhbp-pad" style="padding:16px 28px;background:' . self::CANVAS . ';border-top:1px solid ' . self::BORDER . ';border-radius:0 0 8px 8px;'
            . 'font-family:' . self::FONT . ';font-size:12px;line-height:1.5;color:' . self::FAINT . ';">'
            . $text // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- oben escapt.
            . '</td></tr>';
    }

    /**
     * Unsichtbare Vorschauzeile. Bestimmt, was in der Inbox-Liste hinter dem
     * Betreff steht. Ohne sie zeigen die Clients dort die erste Textzeile,
     * was meist unbrauchbar aussieht.
     */
    private static function preheader(MailMessage $message): string
    {
        $text = '';

        foreach ($message->blocks() as $block) {
            if (in_array($block['type'], ['status', 'text'], true)) {
                $text = (string) $block['text'];
                break;
            }
        }

        if ($text === '') {
            return '';
        }

        return '<div style="display:none;font-size:1px;color:' . self::CANVAS . ';line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">'
            . esc_html($text)
            // Füllzeichen, sonst zieht der Client Text aus dem Rumpf hinterher.
            . str_repeat('&#847;&zwnj;&nbsp;', 40)
            . '</div>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function renderBlock(array $block): string
    {
        return match ($block['type']) {
            'status' => self::renderStatus((string) $block['tone'], (string) $block['text']),
            'section' => '<div style="margin:26px 0 10px 0;font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:' . self::MUTED . ';">'
                . esc_html((string) $block['text']) . '</div>',
            'text' => '<p style="margin:0 0 12px 0;">' . esc_html((string) $block['text']) . '</p>',
            'muted' => '<p style="margin:16px 0 0 0;font-size:13px;line-height:1.5;color:' . self::FAINT . ';">' . esc_html((string) $block['text']) . '</p>',
            'code' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:10px 0 14px 0;">'
                . '<tr><td style="background:' . self::CANVAS . ';border:1px solid ' . self::BORDER . ';border-radius:6px;padding:12px 14px;'
                . "font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;font-size:13px;line-height:1.5;color:" . self::INK . ';word-break:break-all;">'
                . esc_html((string) $block['text']) . '</td></tr></table>',
            'rows' => self::renderRows($block['rows']),
            'bullets' => self::renderBullets($block['items']),
            'button' => self::renderButton((string) $block['label'], (string) $block['url']),
            'divider' => '<div style="height:1px;background:' . self::BORDER . ';font-size:0;line-height:0;margin:22px 0;">&nbsp;</div>',
            // Fertiges HTML, vom Modul gebaut und dort escapt. Für Inhalte,
            // die sich nicht in Bausteine zerlegen lassen: eine Rechnung mit
            // Positionstabelle ist kein Textabsatz. Der Shop liefert so seine
            // Bestellmails, ohne dafür einen zweiten Rahmen zu brauchen.
            'html' => (string) ($block['html'] ?? ''),
            default => '',
        };
    }

    private static function renderStatus(string $tone, string $text): string
    {
        $colors = self::TONES[$tone] ?? self::TONES[MailMessage::TONE_INFO];

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px 0;">'
            . '<tr><td style="background:' . $colors['bg'] . ';border:1px solid ' . $colors['line'] . ';border-radius:6px;'
            . 'padding:14px 16px;font-family:' . self::FONT . ';font-size:15px;line-height:1.5;font-weight:600;color:' . $colors['fg'] . ';">'
            . esc_html($text)
            . '</td></tr></table>';
    }

    /**
     * @param array<string, string> $rows
     */
    private static function renderRows(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 6px 0;font-family:' . self::FONT . ';font-size:14px;line-height:1.5;">';

        foreach ($rows as $key => $value) {
            $html .= '<tr>'
                . '<td valign="top" style="padding:7px 12px 7px 0;color:' . self::MUTED . ';white-space:nowrap;border-bottom:1px solid #f0f0f1;">'
                . esc_html((string) $key) . '</td>'
                . '<td valign="top" style="padding:7px 0;color:' . self::INK . ';font-weight:600;border-bottom:1px solid #f0f0f1;">'
                . esc_html((string) $value) . '</td>'
                . '</tr>';
        }

        return $html . '</table>';
    }

    /**
     * @param array<int, string|array{text: string, tone?: string, meta?: string}> $items
     */
    private static function renderBullets(array $items): string
    {
        if ($items === []) {
            return '';
        }

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 6px 0;font-family:' . self::FONT . ';font-size:14px;line-height:1.5;">';

        foreach ($items as $item) {
            $text = is_array($item) ? (string) $item['text'] : (string) $item;
            $tone = is_array($item) ? (string) ($item['tone'] ?? '') : '';
            $meta = is_array($item) ? (string) ($item['meta'] ?? '') : '';
            $colors = self::TONES[$tone] ?? null;

            $marker = $colors !== null
                ? '<td width="4" style="width:4px;background:' . $colors['fg'] . ';border-radius:2px;font-size:0;line-height:0;">&nbsp;</td><td width="12" style="width:12px;">&nbsp;</td>'
                : '<td width="4" style="width:4px;background:' . self::BORDER . ';border-radius:2px;font-size:0;line-height:0;">&nbsp;</td><td width="12" style="width:12px;">&nbsp;</td>';

            // Zusatzangabe auf eine eigene Zeile: im Fliesstext hinter der
            // Aussage laufen beide ineinander und die Liste wird unruhig.
            $metaHtml = $meta !== ''
                ? '<div style="margin-top:2px;color:' . self::FAINT . ';font-size:13px;line-height:1.4;">' . esc_html($meta) . '</div>'
                : '';

            $html .= '<tr><td style="padding:0 0 8px 0;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . $marker // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- feste Farbwerte.
                . '<td style="color:' . self::TEXT . ';font-family:' . self::FONT . ';font-size:14px;line-height:1.5;">'
                . esc_html($text) . $metaHtml // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- oben escapt.
                . '</td></tr></table>'
                . '</td></tr>';
        }

        return $html . '</table>';
    }

    /**
     * Schaltfläche als Tabellenzelle, nicht als gestyltes <a>. Outlook ignoriert
     * Polsterung an Links, die Fläche wäre dort sonst nur so gross wie der Text.
     */
    private static function renderButton(string $label, string $url): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0 4px 0;">'
            . '<tr><td style="background:' . self::BLUE . ';border-radius:6px;">'
            . '<a href="' . esc_url($url) . '" style="display:inline-block;padding:11px 20px;font-family:' . self::FONT . ';'
            . 'font-size:14px;font-weight:600;line-height:1;color:' . self::WHITE . ';text-decoration:none;">'
            . esc_html($label)
            . '</a></td></tr></table>';
    }
}
