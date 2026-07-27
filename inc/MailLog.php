<?php

declare(strict_types=1);

namespace RhSmtp;

use RhSmtp\Admin\SmtpGroup;

/**
 * Protokolliert jede ausgehende WordPress-Mail (nicht nur die vom Shop): Zeitpunkt,
 * Empfänger, Betreff, Status und bei Fehlern den Grund. Bewusst hier im SMTP-Modul, weil
 * es die Transport-Ebene ist, die jede Mail sieht.
 *
 * Nur Metadaten, NICHT der Mail-Inhalt (DSGVO + Platz). Opt-in (Default aus), mit
 * automatischer Aufräumung alter Einträge über einen täglichen Cron.
 */
final class MailLog
{
    public const DB_VERSION = '1';
    public const OPTION_DB_VERSION = 'rhsmtp_mail_log_db';
    public const CRON_HOOK = 'rhsmtp_purge_mail_log';

    /** Aufrufer-Modul der gerade versendeten Mail (im wp_mail-Filter ermittelt). */
    private string $pendingSource = '';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'rhsmtp_mail_log';
    }

    public function boot(): void
    {
        add_action(self::CRON_HOOK, [$this, 'purge']);

        if (! $this->enabled()) {
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
            if ($timestamp !== false) {
                wp_unschedule_event($timestamp, self::CRON_HOOK);
            }
            return;
        }

        $this->maybeInstall();

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }

        // Aufrufer merken (der wp_mail-Filter läuft mit dem Aufrufer im Stack), dann beim
        // Erfolg/Fehler protokollieren. wp_mail ist synchron, die Reihenfolge ist sicher.
        add_filter('wp_mail', [$this, 'captureSource']);
        add_action('wp_mail_succeeded', [$this, 'onSucceeded']);
        add_action('wp_mail_failed', [$this, 'onFailed']);
    }

    /**
     * @param array<string, mixed> $atts
     * @return array<string, mixed>
     */
    public function captureSource(array $atts): array
    {
        $this->pendingSource = $this->detectSource();

        return $atts;
    }

    /**
     * @param array<string, mixed> $mailData to, subject, message, headers, attachments
     */
    public function onSucceeded(array $mailData): void
    {
        $this->record($mailData['to'] ?? '', (string) ($mailData['subject'] ?? ''), 'sent', '');
    }

    public function onFailed(\WP_Error $error): void
    {
        $data = $error->get_error_data();
        $mailData = is_array($data) ? $data : [];
        $this->record($mailData['to'] ?? '', (string) ($mailData['subject'] ?? ''), 'failed', $error->get_error_message());
    }

    /**
     * @param string|array<int, string> $to
     */
    private function record(string|array $to, string $subject, string $status, string $error): void
    {
        global $wpdb;

        $recipient = is_array($to) ? implode(', ', $to) : $to;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert(
            self::table(),
            [
                'sent_at' => current_time('mysql'),
                'recipient' => mb_substr($recipient, 0, 255),
                'subject' => mb_substr($subject, 0, 255),
                'status' => $status,
                'error' => $error !== '' ? mb_substr($error, 0, 2000) : null,
                'source' => mb_substr($this->pendingSource, 0, 100),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        $this->pendingSource = '';
    }

    /**
     * Die letzten Log-Einträge, neueste zuerst.
     *
     * @return array<int, object>
     */
    public function recent(int $limit = 100): array
    {
        global $wpdb;
        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max(1, $limit)));

        return is_array($rows) ? $rows : [];
    }

    /**
     * Einträge löschen, die älter als die Aufbewahrungsfrist sind (Cron, täglich).
     */
    public function purge(): void
    {
        global $wpdb;
        $days = $this->retentionDays();
        $table = self::table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE sent_at < %s", $cutoff));
    }

    /**
     * Tabelle anlegen, wenn sie fehlt (kein Aktivierungs-Hook im Modul, darum bei Bedarf).
     */
    public function maybeInstall(): void
    {
        if (get_option(self::OPTION_DB_VERSION) === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sent_at DATETIME NOT NULL,
            recipient VARCHAR(255) NOT NULL DEFAULT '',
            subject VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'sent',
            error TEXT NULL,
            source VARCHAR(100) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY sent_at (sent_at)
        ) {$charset};";

        dbDelta($sql);
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    /**
     * Aufrufer-Modul aus dem Call-Stack: der erste Plugin-/Theme-Ordner. Leer, wenn die
     * Mail aus dem WordPress-Core kommt.
     */
    private function detectSource(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file !== '' && preg_match('#/(?:plugins|themes)/([^/]+)/#', $file, $m) === 1) {
                // Der Log-Hook selbst läuft in rh-smtp, den überspringen: gesucht ist der
                // eigentliche Aufrufer von wp_mail.
                if ($m[1] === 'rh-smtp') {
                    continue;
                }

                return $m[1];
            }
        }

        return '';
    }

    public function enabled(): bool
    {
        return (bool) rhbp_setting(SmtpGroup::GROUP_ID, SmtpGroup::FIELD_LOG_ENABLED, false);
    }

    private function retentionDays(): int
    {
        $days = (int) rhbp_setting(SmtpGroup::GROUP_ID, SmtpGroup::FIELD_LOG_RETENTION, '30');

        return max(1, min(365, $days));
    }
}
