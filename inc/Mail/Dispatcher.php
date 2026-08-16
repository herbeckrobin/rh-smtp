<?php

declare(strict_types=1);

namespace RhSmtp\Mail;

use RhBlueprint\Core\Mail\Mail;
use RhBlueprint\Core\Mail\MailMessage;

/**
 * Nimmt dem Core den Mailversand ab.
 *
 * Der Core kennt nur `Mail::send()` und eine schlichte Textfassung als
 * Rückfallebene. Sobald dieses Modul aktiv ist, hängt es sich hier ein und
 * macht die volle Fassung daraus: Betreff nach Konvention, Wellenbremse,
 * HTML im Suite-Rahmen, dazu die Textfassung als zweiter Teil.
 *
 * Der Testmodus greift bewusst NICHT hier, sondern eine Ebene tiefer am
 * wp_mail-Filter. Sonst würde er nur die Mails der Suite umleiten und die des
 * Kontaktformulars weiter an echte Empfänger schicken.
 */
final class Dispatcher
{
    public function boot(): void
    {
        add_filter('rh-blueprint/mail/send', [$this, 'send'], 10, 5);
    }

    /**
     * @param bool|null                 $handled
     * @param string|array<int, string> $to
     */
    public function send(?bool $handled, string|array $to, string $subject, MailMessage $message, string $footerNote): bool
    {
        // Hat sich schon jemand anders darum gekümmert, nicht dazwischenfunken.
        if ($handled !== null) {
            return $handled;
        }

        if (! Throttle::allow($message->isUrgent())) {
            return false;
        }

        $html = MailLayout::html($message, $footerNote);
        $text = Mail::plainText($message, $footerNote);

        $attachText = static function ($phpmailer) use ($text): void {
            $phpmailer->AltBody = $text;
        };

        add_action('phpmailer_init', $attachText);

        try {
            return (bool) wp_mail(
                $to,
                $subject,
                $html,
                ['Content-Type: text/html; charset=UTF-8']
            );
        } finally {
            remove_action('phpmailer_init', $attachText);
        }
    }
}
