<?php

declare(strict_types=1);

namespace RhSmtp;

use RhBlueprint\Core\Core;
use RhBlueprint\Core\UpdateChecker;
use RhBlueprint\Core\Admin\MailPanel;
use RhBlueprint\Core\Mail\MailKind;
use RhBlueprint\Core\Settings\SettingsPage;
use RhSmtp\Admin\MailLogPage;
use RhSmtp\Admin\SmtpGroup;
use RhSmtp\Admin\SmtpTabs;
use RhSmtp\Admin\SmtpToolsPage;
use RhSmtp\Mail\Deliverability;
use RhSmtp\Mail\Dispatcher;
use RhSmtp\Mail\TestMode;
use RhSmtp\Report\ReportRunner;

/**
 * Bootstrap von rh-smtp. Hängt am Core-Hook `rh-blueprint/core/booted`. Braucht nur den Core.
 */
final class Plugin
{
    public static function boot(): void
    {
        add_action('plugins_loaded', static function (): void {
            (new UpdateChecker('rh-smtp', RHSMTP_PLUGIN_FILE))->boot();
        }, 0);

        // Ohne das läuft die Zeitsteuerung des Berichts weiter, obwohl das
        // Modul aus ist. Sie fände dann nichts mehr vor und liefe ins Leere.
        register_deactivation_hook(RHSMTP_PLUGIN_FILE, [ReportRunner::class, 'clearCron']);

        add_action('rh-blueprint/core/booted', [self::class, 'onCoreBooted']);
    }

    /** Kennungen der Mail-Arten dieses Moduls. */
    public const KIND_REPORT = 'smtp.report';
    public const KIND_TEST = 'smtp.test';

    /**
     * Der Sammelbericht selbst ist auch eine Mail-Art. Damit taucht er im
     * Briefumschlag dieses Tabs auf, und man kann ihn dort abschalten, ohne
     * die Einstellungen darunter zu suchen.
     */
    private static function registerKinds(): void
    {
        MailKind::register(self::KIND_REPORT, [
            'module' => 'smtp',
            'label' => __('Sammelbericht', 'rh-smtp'),
            'summary' => __('Fasst die Beiträge aller Module zu einer Mail zusammen.', 'rh-smtp'),
        ]);

        MailKind::register(self::KIND_TEST, [
            'module' => 'smtp',
            'label' => __('Testmail', 'rh-smtp'),
            'summary' => __('Nur auf Knopfdruck, zum Prüfen des Versands.', 'rh-smtp'),
        ]);
    }

    public static function onCoreBooted(Core $core): void
    {
        $core->settings()->registerTab('smtp', __('SMTP', 'rh-smtp'), 70);

        // Die Gruppe wird bewusst NICHT beim Core angemeldet: SmtpTabs rendert
        // die Felder pro Reiter selbst. Angemeldet käme zusätzlich das
        // Sammelformular des Core dazu, und dessen Sanitizer würde beim
        // Speichern eines Reiters die Felder der anderen zurücksetzen.

        $smtp = new Smtp();
        $smtp->boot();
        (new SmtpToolsPage($smtp))->boot();

        $mailLog = new MailLog();
        $mailLog->boot();
        (new MailLogPage($mailLog))->boot();

        self::registerKinds();

        // Aussehen und Versandregeln für die Mails der ganzen Suite.
        (new Dispatcher())->boot();
        (new TestMode())->boot();
        (new Deliverability())->boot();
        (new ReportRunner())->boot();

        (new SmtpTabs())->boot();

        // Was dieses Modul selbst verschickt, gehört zum Bericht-Reiter: der
        // Sammelbericht ist eine seiner beiden Mail-Arten.
        add_action('rh-smtp/pane', static function (string $pane): void {
            if ($pane === SmtpTabs::PANE_REPORT) {
                (new MailPanel())->render('smtp');
            }
        });

        add_filter('rh-blueprint/dashboard/quick_links', static function (array $links): array {
            $links[] = [
                'label' => __('SMTP', 'rh-smtp'),
                'url' => admin_url('admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=smtp'),
                'icon' => 'email',
            ];
            return $links;
        });
    }
}
