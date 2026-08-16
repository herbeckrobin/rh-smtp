<?php

/**
 * Prüfungen für die Versandkette: Testmodus, Betreffe, Wellenbremse, Ampel.
 *   php tests/mail-pipeline-test.php
 *
 * Der wichtigste Fall hier ist der Testmodus. Wenn der versagt, verschickt eine
 * Kopie der Kundenseite echte Mails an echte Kunden, und das lässt sich nicht
 * zurückholen. Deshalb wird nicht nur geprüft, dass umgeleitet wird, sondern
 * auch dass Kopienempfänger verschwinden: genau daran ist die frühere Fassung
 * gescheitert.
 */

declare(strict_types=1);

$base = dirname(__DIR__);
$core = $base . '/vendor/rh/blueprint-core';

define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);

$GLOBALS['__settings'] = [];
$GLOBALS['__transients'] = [];
$GLOBALS['__options'] = [];
$GLOBALS['__env'] = 'production';

function __(string $t, string $d = 'default'): string
{
    return $t;
}
function _n(string $single, string $plural, int $number, string $d = 'default'): string
{
    return $number === 1 ? $single : $plural;
}
function esc_html(string $t): string
{
    return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
}
function esc_attr(string $t): string
{
    return esc_html($t);
}
function esc_url(string $t): string
{
    return esc_html($t);
}
function esc_html__(string $t, string $d = 'default'): string
{
    return esc_html($t);
}
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return $value;
}
function add_filter(string $hook, callable $cb, int $prio = 10, int $args = 1): void
{
}
function add_action(string $hook, callable $cb, int $prio = 10, int $args = 1): void
{
}
function is_email(string $mail): string|false
{
    return filter_var($mail, FILTER_VALIDATE_EMAIL) === false ? false : $mail;
}
function home_url(string $path = '/'): string
{
    return 'https://kunde.de' . $path;
}
function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}
function wp_get_environment_type(): string
{
    return $GLOBALS['__env'];
}
function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['__options'][$name] ?? $default;
}
function get_transient(string $key): mixed
{
    $entry = $GLOBALS['__transients'][$key] ?? null;

    return $entry === null ? false : $entry['value'];
}
function set_transient(string $key, mixed $value, int $ttl = 0): bool
{
    $GLOBALS['__transients'][$key] = ['value' => $value, 'ttl' => $ttl];

    return true;
}
function rhbp_setting(string $group, ?string $field = null, mixed $default = null): mixed
{
    return $GLOBALS['__settings'][$field] ?? $default;
}

require $core . '/src/Mail/MailMessage.php';
require $core . '/src/Mail/ReportSection.php';
// SmtpGroup hält nur die Feld-Kennungen, die hier gebraucht werden. Für die
// Klasse selbst muss das Settings-Gerüst des Core mitkommen.
require $core . '/src/Settings/GroupInterface.php';
require $core . '/src/Settings/SettingField.php';
require $base . '/inc/Admin/SmtpGroup.php';
require $base . '/inc/Mail/TestMode.php';
require $core . '/src/Mail/Mail.php';
require $base . '/inc/Mail/Throttle.php';

use RhBlueprint\Core\Mail\MailMessage;
use RhBlueprint\Core\Mail\ReportSection;
use RhBlueprint\Core\Mail\Mail;
use RhSmtp\Mail\TestMode;
use RhSmtp\Mail\Throttle;

$failures = 0;

function check(string $name, bool $ok, string $hint = ''): void
{
    global $failures;

    if (! $ok) {
        $failures++;
    }

    printf("  %-56s %s%s\n", $name, $ok ? 'PASS' : 'FAIL', $ok || $hint === '' ? '' : '  ' . $hint);
}

function reset_state(): void
{
    $GLOBALS['__settings'] = [];
    $GLOBALS['__transients'] = [];
    $GLOBALS['__env'] = 'production';
}

echo "\nTestmodus richtet sich nach der Umgebung\n";

reset_state();
check('Im Livebetrieb aus', TestMode::active() === TestMode::MODE_OFF);

$GLOBALS['__env'] = 'staging';
check('Auf Staging leitet er um', TestMode::active() === TestMode::MODE_REDIRECT);

$GLOBALS['__env'] = 'local';
check('Auf einer lokalen Installation auch', TestMode::active() === TestMode::MODE_REDIRECT);

reset_state();
$GLOBALS['__settings']['test_mode'] = 'off';
$GLOBALS['__env'] = 'staging';
check('Von Hand abgeschaltet gilt das', TestMode::active() === TestMode::MODE_OFF);

reset_state();
$GLOBALS['__settings']['test_mode'] = 'redirect';
check('Von Hand eingeschaltet auch im Livebetrieb', TestMode::active() === TestMode::MODE_REDIRECT);

reset_state();
$GLOBALS['__settings']['redirect_enabled'] = true;
check('Der alte Staging-Schalter gilt weiter', TestMode::active() === TestMode::MODE_REDIRECT);

echo "\nUmleitung lässt nichts durchrutschen\n";

reset_state();
$GLOBALS['__env'] = 'staging';
$GLOBALS['__settings']['redirect_to'] = 'test@robinherbeck.com';

$mode = new TestMode();
$atts = $mode->apply([
    'to' => ['kunde@example.com', 'zweiter@example.com'],
    'cc' => ['chef@example.com'],
    'bcc' => ['buchhaltung@example.com'],
    'subject' => 'Ihre Bestellung',
    'message' => 'Danke für Ihre Bestellung.',
]);

check('Empfänger ist die Testadresse', $atts['to'] === ['test@robinherbeck.com']);
check('Kopienempfänger sind weg', $atts['cc'] === [] && $atts['bcc'] === []);
check('Betreff ist gekennzeichnet', str_starts_with((string) $atts['subject'], '[TEST] '));
check('Der eigentliche Empfänger steht in der Mail', str_contains((string) $atts['message'], 'kunde@example.com'));
check('Der ursprüngliche Text bleibt erhalten', str_contains((string) $atts['message'], 'Danke für Ihre Bestellung.'));

$html = $mode->apply([
    'to' => 'kunde@example.com',
    'subject' => 'Rechnung',
    'message' => '<html><body><p>Inhalt</p></body></html>',
]);
check('In HTML sitzt der Hinweis im Rumpf', (bool) preg_match('/<body[^>]*><div/i', (string) $html['message']));

echo "\nBlockieren verschickt gar nichts\n";

reset_state();
$GLOBALS['__settings']['test_mode'] = 'block';
$blocked = (new TestMode())->apply([
    'to' => 'kunde@example.com',
    'subject' => 'Rechnung',
    'message' => 'Inhalt',
]);
check('Kein Empfänger übrig', $blocked['to'] === []);
check('Als blockiert gekennzeichnet', str_starts_with((string) $blocked['subject'], '[BLOCKIERT] '));

echo "\nBetreffe folgen einer Konvention\n";

reset_state();
$intern = new MailMessage('Wochenbericht');
check('Die Domain steht vorn', Mail::subject('Wochenbericht Sicherheit', $intern) === '[kunde.de] Wochenbericht Sicherheit');
check('Alte Themen-Klammer wird ersetzt', Mail::subject('[Sicherheit] Wochenbericht', $intern) === '[kunde.de] Wochenbericht');
check('Nicht doppelt voranstellen', Mail::subject('[kunde.de] Wochenbericht', $intern) === '[kunde.de] Wochenbericht');

$extern = (new MailMessage('Bestellung'))->audience(MailMessage::AUDIENCE_EXTERNAL);
check('Kundenmails bleiben unangetastet', Mail::subject('Ihre Bestellung bei uns', $extern) === 'Ihre Bestellung bei uns');

echo "\nWellenbremse\n";

reset_state();
$durch = 0;
for ($i = 0; $i < 40; $i++) {
    if (Throttle::allow()) {
        $durch++;
    }
}
check('Bremst bei Dauerfeuer', $durch === Throttle::limit(), 'durchgelassen: ' . $durch);
check('Dringendes kommt trotzdem durch', Throttle::allow(true));

reset_state();
Throttle::allow();
$ttlErste = $GLOBALS['__transients']['rhsmtp_throttle']['ttl'];
Throttle::allow();
$ttlZweite = $GLOBALS['__transients']['rhsmtp_throttle']['ttl'];
check('Das Zeitfenster wird nicht verlängert', $ttlZweite <= $ttlErste, "erste={$ttlErste} zweite={$ttlZweite}");

echo "\nAmpel des Sammelberichts\n";

$ok = new ReportSection('a', 'A', ReportSection::STATUS_OK, 'alles gut');
$warn = new ReportSection('b', 'B', ReportSection::STATUS_WARN, 'ansehen');
$alert = new ReportSection('c', 'C', ReportSection::STATUS_ALERT, 'dringend');

$sortiert = [$ok, $alert, $warn];
usort($sortiert, static fn (ReportSection $x, ReportSection $y): int => $x->weight() <=> $y->weight());

check('Dringendes steht oben', $sortiert[0]->module === 'c');
check('Danach das zum Ansehen', $sortiert[1]->module === 'b');
check('Ruhiges zuletzt', $sortiert[2]->module === 'a');
check('Ein Abschnitt ohne Inhalt hat keine Einzelheiten', ! $ok->hasDetail());
check('Mit Inhalt schon', $ok->detail((new MailMessage(''))->text('etwas'))->hasDetail());

echo "\n";

if ($failures > 0) {
    echo "FEHLER: {$failures} Check(s) fehlgeschlagen.\n";
    exit(1);
}

echo "OK, alle Checks bestanden.\n";
