<?php

/**
 * Plugin Name:       RH SMTP
 * Plugin URI:        https://github.com/herbeckrobin/rh-smtp
 * Update URI:        https://github.com/herbeckrobin/rh-smtp
 * Description:       Versendet WordPress-Mails über einen echten SMTP-Server statt PHP mail(). Teil der rh-blueprint Kollektion.
 * Version:           0.4.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Robin Herbeck
 * Author URI:        https://robinherbeck.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rh-smtp
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('RHSMTP_VERSION', '0.4.0');
define('RHSMTP_PLUGIN_FILE', __FILE__);
define('RHSMTP_PLUGIN_DIR', plugin_dir_path(__FILE__));

$rhsmtp_autoload = RHSMTP_PLUGIN_DIR . 'vendor/autoload.php';

if (! is_readable($rhsmtp_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>RH SMTP:</strong> Composer-Dependencies fehlen. Bitte <code>composer install</code> im Plugin-Verzeichnis ausführen.</p></div>';
    });
    return;
}

require_once $rhsmtp_autoload;

RhSmtp\Plugin::boot();
