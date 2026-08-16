<?php

declare(strict_types=1);

namespace RhSmtp\Mail;

use RhBlueprint\Core\Settings\SettingsPage;

/**
 * Warnt, wenn der Mailversand nicht mehr funktioniert.
 *
 * Der Fehlermodus, den das abfängt, ist der unangenehmste überhaupt: die
 * Zugangsdaten laufen ab oder der Anbieter sperrt, und ab da verschwindet
 * jede Mail lautlos. Bestellbestätigungen, Formulare, Sicherheitsmeldungen.
 * Auffallen würde es erst, wenn sich ein Kunde beschwert.
 *
 * Gemeldet wird im Backend und nicht per Mail. Eine Mail über kaputten
 * Mailversand ist genau die, die nicht ankommt.
 *
 * Bewusst mit eigener Zählung statt über das Mail-Log: das ist Opt-in und auf
 * den meisten Installationen aus. Diese Warnung muss immer laufen.
 */
final class Deliverability
{
    private const OPTION = 'rhsmtp_delivery_state';

    /** Erst nach mehreren Fehlschlägen warnen, ein einzelner ist Alltag. */
    private const THRESHOLD = 3;

    public function boot(): void
    {
        add_action('wp_mail_succeeded', [$this, 'onSucceeded']);
        add_action('wp_mail_failed', [$this, 'onFailed']);
        add_action('admin_notices', [$this, 'notice']);
    }

    /**
     * @param array<string, mixed> $mailData
     */
    public function onSucceeded(array $mailData = []): void
    {
        if ($this->state()['failures'] === 0) {
            return;
        }

        delete_option(self::OPTION);
    }

    public function onFailed(\WP_Error $error): void
    {
        $state = $this->state();

        update_option(self::OPTION, [
            'failures' => $state['failures'] + 1,
            'last' => time(),
            'error' => mb_substr($error->get_error_message(), 0, 300),
        ], false);
    }

    public function notice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $state = $this->state();

        if ($state['failures'] < self::THRESHOLD) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><em>%s</em></p><p><a href="%s">%s</a></p></div>',
            esc_html__('Der Mailversand dieser Website schlägt fehl.', 'rh-smtp'),
            esc_html(sprintf(
                /* translators: 1: Anzahl Fehlschläge, 2: Zeitpunkt */
                __('%1$d Versuche hintereinander sind nicht durchgekommen, zuletzt am %2$s. Bis das behoben ist, erreicht keine Mail dieser Website ihren Empfänger.', 'rh-smtp'),
                $state['failures'],
                wp_date('d.m.Y H:i', $state['last'])
            )),
            esc_html($state['error']),
            esc_url(admin_url('admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=smtp')),
            esc_html__('Einstellungen prüfen und Verbindung testen', 'rh-smtp')
        );
    }

    /**
     * @return array{failures: int, last: int, error: string}
     */
    private function state(): array
    {
        $stored = get_option(self::OPTION, []);

        return [
            'failures' => (int) ($stored['failures'] ?? 0),
            'last' => (int) ($stored['last'] ?? 0),
            'error' => (string) ($stored['error'] ?? ''),
        ];
    }
}
