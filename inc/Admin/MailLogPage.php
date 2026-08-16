<?php

declare(strict_types=1);

namespace RhSmtp\Admin;

use RhSmtp\MailLog;

/**
 * Zeigt das Mail-Log im SMTP-Tab (unter den Test-Werkzeugen), wenn es aktiviert ist.
 * Reine Anzeige der letzten Einträge, kein Formular.
 */
final class MailLogPage
{
    public const TAB = 'smtp';

    public function __construct(private readonly MailLog $log)
    {
    }

    public function boot(): void
    {
        // Priorität 20: nach den Test-Werkzeugen (die auf 10 hängen).
        add_action('rh-smtp/pane', [$this, 'renderPane'], 20);
    }

    public function renderPane(string $pane): void
    {
        if ($pane === SmtpTabs::PANE_LOG) {
            $this->render(SmtpTabs::TAB_ID);
        }
    }

    public function render(string $tab): void
    {
        if ($tab !== self::TAB || ! current_user_can('manage_options') || ! $this->log->enabled()) {
            return;
        }

        echo '<hr style="margin:2rem 0">';
        echo '<h2>' . esc_html__('Mail-Log', 'rh-smtp') . '</h2>';
        echo '<p class="description">' . esc_html__('Die zuletzt versendeten Mails. Nur Zeit, Empfänger, Betreff und Status, nicht der Inhalt.', 'rh-smtp') . '</p>';

        $entries = $this->log->recent(100);
        if ($entries === []) {
            echo '<p>' . esc_html__('Noch keine Einträge.', 'rh-smtp') . '</p>';
            return;
        }

        $dateFormat = get_option('date_format') . ' ' . get_option('time_format');

        echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
        foreach ([
            __('Zeitpunkt', 'rh-smtp'),
            __('Empfänger', 'rh-smtp'),
            __('Betreff', 'rh-smtp'),
            __('Quelle', 'rh-smtp'),
            __('Status', 'rh-smtp'),
        ] as $head) {
            echo '<th>' . esc_html($head) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            $failed = ($entry->status ?? '') === 'failed';
            $pill = $failed
                ? '<span style="color:#b3261e;font-weight:600">' . esc_html__('fehlgeschlagen', 'rh-smtp') . '</span>'
                : '<span style="color:#1a7f37;font-weight:600">' . esc_html__('gesendet', 'rh-smtp') . '</span>';
            // Fehlertext als Tooltip an der Status-Zelle.
            $title = $failed && ! empty($entry->error) ? ' title="' . esc_attr((string) $entry->error) . '"' : '';

            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td%s>%s</td></tr>',
                esc_html(mysql2date($dateFormat, (string) $entry->sent_at)),
                esc_html((string) $entry->recipient),
                esc_html((string) $entry->subject),
                esc_html((string) ($entry->source ?? '')),
                $title, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $title escapt.
                $pill   // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- statisch/escapt.
            );
        }

        echo '</tbody></table>';
    }
}
