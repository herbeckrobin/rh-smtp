<?php

declare(strict_types=1);

namespace RhSmtp;

use RhBlueprint\Core\Core;
use RhBlueprint\Core\Settings\SettingsPage;
use RhSmtp\Admin\SmtpGroup;
use RhSmtp\Admin\SmtpToolsPage;

/**
 * Bootstrap von rh-smtp. Hängt am Core-Hook `rh-blueprint/core/booted`. Braucht nur den Core.
 */
final class Plugin
{
    public static function boot(): void
    {
        if (class_exists(UpdateChecker::class)) {
            (new UpdateChecker())->boot();
        }

        add_action('rh-blueprint/core/booted', [self::class, 'onCoreBooted']);
    }

    public static function onCoreBooted(Core $core): void
    {
        $core->settings()->registerTab('smtp', __('SMTP', 'rh-smtp'), 70);
        $core->settings()->registerGroup(new SmtpGroup());

        $smtp = new Smtp();
        $smtp->boot();
        (new SmtpToolsPage($smtp))->boot();

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
