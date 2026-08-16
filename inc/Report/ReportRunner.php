<?php

declare(strict_types=1);

namespace RhSmtp\Report;

use RhBlueprint\Core\Mail\Mail;
use RhBlueprint\Core\Mail\MailMessage;
use RhBlueprint\Core\Mail\ReportSection;
use RhBlueprint\Core\Settings\SettingsPage;
use RhSmtp\Admin\SmtpGroup;

/**
 * Der Sammelbericht der Suite.
 *
 * Das Problem, das er löst: bei fünfzehn Websites mit je vier berichtenden
 * Modulen wären das sechzig Mails pro Woche. Das liest niemand, und in der
 * Menge geht die eine unter, die zählt. Also ein Bericht pro Website, in dem
 * jedes Modul einen Abschnitt bekommt.
 *
 * Abgeholt wird per Filter beim Termin (siehe ReportSection). Kein Puffer, in
 * den Module laufend schreiben: der könnte volllaufen, verloren gehen oder
 * doppelt ausliefern. Die Daten liegen ohnehin bei den Modulen.
 *
 * Angetrieben von einem täglichen Cron, der prüft, ob der Termin erreicht ist.
 * Der naheliegendere Weg wäre ein Cron im gewählten Rhythmus, aber dann muss
 * bei jeder Änderung der Einstellung umgeplant werden, und wer das einmal
 * vergisst, hat einen Bericht, der nie oder doppelt kommt.
 */
final class ReportRunner
{
    public const CRON = 'rhsmtp_report_tick';

    private const OPTION_LAST = 'rhsmtp_report_last';

    public const RHYTHM_DAILY = 'daily';
    public const RHYTHM_WEEKLY = 'weekly';
    public const RHYTHM_MONTHLY = 'monthly';

    public function boot(): void
    {
        add_action(self::CRON, [$this, 'maybeSend']);
        add_filter('rh-blueprint/mail/has_reporting', '__return_true');

        if (! wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON);
        }
    }

    public static function clearCron(): void
    {
        $timestamp = wp_next_scheduled(self::CRON);

        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::CRON);
        }
    }

    /**
     * Läuft täglich und entscheidet selbst, ob heute ein Bericht fällig ist.
     */
    public function maybeSend(): void
    {
        if (! $this->enabled() || ! $this->due()) {
            return;
        }

        $this->send();
    }

    /**
     * Baut den Bericht und verschickt ihn. Auch von Hand auslösbar.
     */
    public function send(bool $force = false): bool
    {
        $recipient = $this->recipient();

        if ($recipient === '') {
            return false;
        }

        $since = $this->periodStart();
        $sections = self::collect($since);

        // Ohne einen einzigen Abschnitt gibt es nichts zu berichten. Das ist
        // etwas anderes als "alles ruhig": dann melden die Module ausdrücklich,
        // dass sie nichts gefunden haben, und das ist der Nachweis, auf den es
        // ankommt.
        if ($sections === [] && ! $force) {
            $this->remember();

            return false;
        }

        $message = $this->compose($sections, $since);

        $sent = Mail::send(
            $recipient,
            $this->subject($sections),
            $message,
            $this->footerNote()
        );

        if ($sent) {
            $this->remember();
        }

        return $sent;
    }

    /**
     * Fragt alle Module nach ihrem Beitrag.
     *
     * @return array<int, ReportSection>
     */
    public static function collect(int $since): array
    {
        /**
         * Beiträge zum Sammelbericht.
         *
         * @param array<int, ReportSection> $sections
         * @param int                       $since Beginn des Zeitraums als Zeitstempel.
         */
        $sections = apply_filters('rh-blueprint/report/sections', [], $since);

        $sections = array_values(array_filter(
            is_array($sections) ? $sections : [],
            static fn ($section): bool => $section instanceof ReportSection
        ));

        // Dringendes nach oben, Ruhiges nach unten.
        usort(
            $sections,
            static fn (ReportSection $a, ReportSection $b): int => $a->weight() <=> $b->weight()
        );

        return $sections;
    }

    /**
     * @param array<int, ReportSection> $sections
     */
    private function compose(array $sections, int $since): MailMessage
    {
        $message = new MailMessage($this->title(), Mail::host());
        $message->kind(\RhSmtp\Plugin::KIND_REPORT);

        $alerts = $this->countBy($sections, ReportSection::STATUS_ALERT);
        $warns = $this->countBy($sections, ReportSection::STATUS_WARN);

        if ($alerts > 0) {
            $message->status(
                MailMessage::TONE_ALERT,
                sprintf(
                    /* translators: %d: Anzahl der Bereiche mit ernstem Befund */
                    _n('%d Bereich braucht deine Aufmerksamkeit.', '%d Bereiche brauchen deine Aufmerksamkeit.', $alerts, 'rh-smtp'),
                    $alerts
                )
            );
        } elseif ($warns > 0) {
            $message->status(
                MailMessage::TONE_WARN,
                sprintf(
                    /* translators: %d: Anzahl der Bereiche zum Ansehen */
                    _n('Nichts Ernstes, %d Bereich gehört angesehen.', 'Nichts Ernstes, %d Bereiche gehören angesehen.', $warns, 'rh-smtp'),
                    $warns
                )
            );
        } else {
            $message->status(
                MailMessage::TONE_OK,
                __('Alles in Ordnung. Kein Bereich meldet ein Problem.', 'rh-smtp')
            );
        }

        $message->text(sprintf(
            /* translators: 1: Beginn des Zeitraums, 2: Ende */
            __('Zeitraum: %1$s bis %2$s', 'rh-smtp'),
            wp_date('d.m.Y', $since),
            wp_date('d.m.Y')
        ));

        // Die Ampel: eine Zeile je Modul, damit man in fünf Sekunden sieht, ob
        // man weiterlesen muss.
        $message->section(__('Überblick', 'rh-smtp'));
        $message->bullets(array_map(
            static fn (ReportSection $section): array => [
                'text' => $section->label,
                'meta' => $section->summary,
                'tone' => match ($section->status) {
                    ReportSection::STATUS_ALERT => MailMessage::TONE_ALERT,
                    ReportSection::STATUS_WARN => MailMessage::TONE_WARN,
                    ReportSection::STATUS_OK => MailMessage::TONE_OK,
                    default => MailMessage::TONE_INFO,
                },
            ],
            $sections
        ));

        foreach ($sections as $section) {
            if (! $section->hasDetail()) {
                continue;
            }

            $message->divider();
            $message->section($section->label);

            $detail = $section->detailMessage();

            if ($detail !== null) {
                foreach ($detail->blocks() as $block) {
                    $message->raw($block);
                }
            }
        }

        $message->button(
            __('Zustand der Website ansehen', 'rh-smtp'),
            admin_url('admin.php?page=' . SettingsPage::MENU_SLUG)
        );

        return $message;
    }

    /**
     * @param array<int, ReportSection> $sections
     */
    private function subject(array $sections): string
    {
        if ($this->countBy($sections, ReportSection::STATUS_ALERT) > 0) {
            return __('Bericht, bitte ansehen', 'rh-smtp');
        }

        return $this->title();
    }

    private function title(): string
    {
        return match ($this->rhythm()) {
            self::RHYTHM_DAILY => __('Tagesbericht', 'rh-smtp'),
            self::RHYTHM_MONTHLY => __('Monatsbericht', 'rh-smtp'),
            default => __('Wochenbericht', 'rh-smtp'),
        };
    }

    /**
     * @param array<int, ReportSection> $sections
     */
    private function countBy(array $sections, string $status): int
    {
        return count(array_filter($sections, static fn (ReportSection $s): bool => $s->status === $status));
    }

    private function due(): bool
    {
        return time() >= $this->nextDue();
    }

    public function nextDue(): int
    {
        $last = (int) get_option(self::OPTION_LAST, 0);

        if ($last === 0) {
            return 0;
        }

        return $last + $this->interval();
    }

    public function interval(): int
    {
        return match ($this->rhythm()) {
            self::RHYTHM_DAILY => DAY_IN_SECONDS,
            self::RHYTHM_MONTHLY => 30 * DAY_IN_SECONDS,
            default => WEEK_IN_SECONDS,
        };
    }

    private function periodStart(): int
    {
        $last = (int) get_option(self::OPTION_LAST, 0);

        return $last > 0 ? $last : time() - $this->interval();
    }

    private function remember(): void
    {
        update_option(self::OPTION_LAST, time(), false);
    }

    public function rhythm(): string
    {
        $value = (string) rhbp_setting(SmtpGroup::GROUP_ID, SmtpGroup::FIELD_REPORT_RHYTHM, self::RHYTHM_WEEKLY);

        return in_array($value, [self::RHYTHM_DAILY, self::RHYTHM_WEEKLY, self::RHYTHM_MONTHLY], true)
            ? $value
            : self::RHYTHM_WEEKLY;
    }

    public function enabled(): bool
    {
        return (bool) rhbp_setting(SmtpGroup::GROUP_ID, SmtpGroup::FIELD_REPORT_ENABLED, false);
    }

    public function recipient(): string
    {
        $configured = (string) rhbp_setting(SmtpGroup::GROUP_ID, SmtpGroup::FIELD_REPORT_EMAIL, '');
        $email = $configured !== '' ? $configured : (string) get_option('admin_email', '');

        return is_email($email) ? $email : '';
    }

    private function footerNote(): string
    {
        return sprintf(
            /* translators: %s: Domain der Website */
            __('Sammelbericht von %s. Ernste Vorfälle werden sofort gemeldet, unabhängig von diesem Bericht.', 'rh-smtp'),
            Mail::host()
        );
    }
}
