<?php

/**
 * Prüfungen für den Mail-Rahmen der Suite.
 *   php tests/mail-test.php
 *
 * Zwei Dinge stehen hier auf dem Spiel. Erstens die Sicherheit: in eine Mail
 * wandern Werte aus dem Log, also aus Anfragen von aussen. Was dort nicht
 * maskiert wird, landet als Markup im Postfach. Zweitens die Vollständigkeit:
 * HTML und Textfassung entstehen aus derselben Quelle, und genau deshalb muss
 * jeder Blocktyp in beiden ankommen. Ein Block, den nur eine Seite kennt, ist
 * die Art Fehler, die niemandem auffällt, bis jemand Textmails liest.
 */

declare(strict_types=1);

$base = dirname(__DIR__);
$core = $base . '/vendor/rh/blueprint-core';

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return htmlspecialchars(strip_tags($url), ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html($text);
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

require $core . '/src/Mail/MailMessage.php';
require $core . '/src/Mail/Mail.php';
require $base . '/inc/Mail/MailLayout.php';

use RhBlueprint\Core\Mail\Mail;
use RhBlueprint\Core\Mail\MailMessage;
use RhSmtp\Mail\MailLayout;

$failures = 0;

function check(string $name, bool $ok, string $hint = ''): void
{
    global $failures;

    if (! $ok) {
        $failures++;
    }

    printf("  %-58s %s%s\n", $name, $ok ? 'PASS' : 'FAIL', $ok || $hint === '' ? '' : '  ' . $hint);
}

// --- Eine Nachricht mit jedem Blocktyp ---------------------------------------

$message = (new MailMessage('Wochenbericht Sicherheit', 'www.beispiel.de'))
    ->status(MailMessage::TONE_OK, 'Nichts Auffälliges passiert.')
    ->section('Was geprüft wurde')
    ->rows(['Dateien der Website' => '3.587 Dateien, unverändert'])
    ->bullets([
        ['text' => 'Versuch, die Benutzernamen auszulesen', 'meta' => '90 mal', 'tone' => MailMessage::TONE_WARN],
        'Einfacher Eintrag ohne Zusatz',
    ])
    ->code('curl -s "https://beispiel.de/ping"')
    ->divider()
    ->button('Chronik ansehen', 'https://beispiel.de/wp-admin/admin.php?page=rh-blueprint')
    ->muted('Kommt einmal pro Woche.');

$html = MailLayout::html($message, 'Automatische Nachricht von www.beispiel.de.');
$text = Mail::plainText($message, 'Automatische Nachricht von www.beispiel.de.');

echo "\nJeder Blocktyp kommt in beiden Fassungen an\n";

$inhalte = [
    'Titel' => 'Wochenbericht Sicherheit',
    'Untertitel' => 'www.beispiel.de',
    'Kernaussage' => 'Nichts Auffälliges passiert.',
    'Abschnitt' => 'Was geprüft wurde',
    'Zeilenbeschriftung' => 'Dateien der Website',
    'Zeilenwert' => '3.587 Dateien, unverändert',
    'Aufzählung mit Zusatz' => 'Versuch, die Benutzernamen auszulesen',
    'Aufzählung ohne Zusatz' => 'Einfacher Eintrag ohne Zusatz',
    'Zusatzangabe' => '90 mal',
    'Befehl' => 'curl -s',
    'Schaltflächen-Text' => 'Chronik ansehen',
    'Kleingedrucktes' => 'Kommt einmal pro Woche.',
    'Fusszeile' => 'Automatische Nachricht von www.beispiel.de.',
];

foreach ($inhalte as $name => $nadel) {
    check($name . ' steht im HTML', str_contains($html, htmlspecialchars($nadel, ENT_QUOTES, 'UTF-8')) || str_contains($html, $nadel));
    // Ohne Rücksicht auf Gross- und Kleinschreibung: die Textfassung setzt
    // Titel und Abschnitte in Versalien, weil sie ohne Auszeichnung sonst
    // keine Gliederung hat. Geprüft wird, ob der Inhalt ankommt.
    check($name . ' steht im Text', mb_stripos($text, $nadel) !== false);
}

check('Ziel der Schaltfläche steht im Text', str_contains($text, 'https://beispiel.de/wp-admin/admin.php?page=rh-blueprint'));

echo "\nDie Textfassung ist wirklich Text\n";

check('Keine Tags in der Textfassung', ! preg_match('/<[a-z\/][^>]*>/i', $text), 'gefunden: ' . (string) (preg_match('/<[a-z\/][^>]*>/i', $text, $m) ? $m[0] : ''));

echo "\nFremde Werte werden maskiert\n";

// So etwas steht im Log, wenn jemand es in eine Anfrage schreibt.
$boese = '<script>alert(1)</script>';
$angriff = (new MailMessage('Titel ' . $boese, $boese))
    ->status(MailMessage::TONE_ALERT, 'Vorgang: ' . $boese)
    ->rows([$boese => $boese])
    ->bullets([['text' => $boese, 'meta' => $boese]])
    ->code($boese)
    ->section($boese)
    ->text($boese)
    ->muted($boese)
    ->button($boese, 'javascript:alert(1)');

$angriffHtml = MailLayout::html($angriff, $boese);

check('Kein offenes script-Tag im HTML', ! str_contains($angriffHtml, '<script>'));
check('Kein schliessendes script-Tag im HTML', ! str_contains($angriffHtml, '</script>'));
check('Der Wert steht maskiert drin', str_contains($angriffHtml, '&lt;script&gt;'));
check('Auch im Titel maskiert', str_contains($angriffHtml, 'Titel &lt;script&gt;'));

echo "\nAufbau des Dokuments\n";

check('Doctype gesetzt', str_starts_with($html, '<!DOCTYPE html'));
check('Zeichensatz erklärt', str_contains($html, 'charset=UTF-8'));
check('Fester Hellmodus angemeldet', str_contains($html, 'name="color-scheme"'));
check('Outlook bekommt seine feste Breite', str_contains($html, '<!--[if mso]>'));
check('Vorschauzeile vorhanden', str_contains($html, 'Nichts Auffälliges passiert.'));
check('Aussenbreite begrenzt', str_contains($html, 'max-width:600px'));
check('Tabellen statt moderner Layouts', ! str_contains($html, 'display:flex') && ! str_contains($html, 'display:grid'));
check('Keine Datei von aussen', ! preg_match('/(src|href)\s*=\s*"https?:\/\/(?!beispiel\.de)/i', $html));
check('Html sauber geschlossen', str_ends_with(trim($html), '</html>'));

echo "\nLeere Nachricht bleibt heil\n";

$leer = new MailMessage('Nur ein Titel');
$leerHtml = MailLayout::html($leer);
$leerText = Mail::plainText($leer);

check('HTML entsteht trotzdem', str_contains($leerHtml, 'Nur ein Titel'));
check('Text entsteht trotzdem', str_contains($leerText, 'NUR EIN TITEL'));
check('Rückfall-Fusszeile gesetzt', str_contains($leerHtml, 'automatisch'));

echo "\n";

if ($failures > 0) {
    echo "FEHLER: {$failures} Check(s) fehlgeschlagen.\n";
    exit(1);
}

echo "OK, alle Checks bestanden.\n";
