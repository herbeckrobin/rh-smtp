<?php

declare(strict_types=1);

namespace RhSmtp\Mail;

/**
 * Wellenbremse.
 *
 * Wenn eine Ursache viele Vorgänge auslöst (ein Scanner, eine Endlosschleife,
 * ein kaputter Cron), soll daraus keine Mailflut werden. Zwei Gründe: das
 * Postfach wird unbrauchbar, und der Mailserver stuft den Absender ab.
 *
 * rh-hardening hatte so eine Bremse schon für sich allein. Sie gehört aber
 * nicht in ein einzelnes Modul, sonst zählt bei fünf Modulen jedes für sich
 * bis zehn, und es gehen trotzdem fünfzig Mails raus.
 *
 * Dringende Meldungen laufen bewusst nicht durch die Bremse. Wer einen
 * Einbruch meldet, darf nicht am eigenen Rate-Limit hängen bleiben.
 */
final class Throttle
{
    private const TRANSIENT = 'rhsmtp_throttle';

    /** Mehr als das pro Stunde ist keine Meldung mehr, sondern Lärm. */
    private const MAX_PER_HOUR = 12;

    /**
     * Darf jetzt verschickt werden? Zählt den Versuch gleich mit.
     */
    public static function allow(bool $urgent = false): bool
    {
        if ($urgent) {
            return true;
        }

        /** @var int $count */
        $count = (int) get_transient(self::TRANSIENT);

        if ($count >= self::limit()) {
            return false;
        }

        // Die Laufzeit bleibt an der ersten Mail der Stunde hängen, sonst
        // schiebt jede weitere das Fenster nach hinten und die Bremse löst
        // nach einer Dauerwelle nie wieder.
        set_transient(self::TRANSIENT, $count + 1, $count === 0 ? HOUR_IN_SECONDS : self::remaining());

        return true;
    }

    public static function limit(): int
    {
        /**
         * Obergrenze pro Stunde für nicht dringende Mails.
         *
         * @param int $limit
         */
        return (int) apply_filters('rh-blueprint/mail/throttle_limit', self::MAX_PER_HOUR);
    }

    /**
     * Restlaufzeit des laufenden Fensters, damit set_transient es nicht verlängert.
     */
    private static function remaining(): int
    {
        $timeout = (int) get_option('_transient_timeout_' . self::TRANSIENT, 0);
        $rest = $timeout - time();

        return $rest > 0 ? $rest : HOUR_IN_SECONDS;
    }
}
