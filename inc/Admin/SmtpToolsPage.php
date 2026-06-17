<?php

declare(strict_types=1);

namespace RhSmtp\Admin;

use RhBlueprint\Core\Settings\SettingsPage;
use RhSmtp\Secret;
use RhSmtp\Smtp;

/**
 * Test-Werkzeuge im SMTP-Tab: Verbindung testen und Testmail senden.
 *
 * Bespoke Panel unter dem Settings-Formular (tab_content_after) mit eigenen
 * admin-post-Aktionen. Die Tests nutzen die GESPEICHERTEN Einstellungen, also
 * vorher speichern. Ergebnis wird per kurzem User-Transient zurückgereicht.
 */
final class SmtpToolsPage
{
    public const TAB = 'smtp';
    private const ACTION_CONNECT = 'rhsmtp_test_connection';
    private const ACTION_MAIL = 'rhsmtp_test_mail';
    private const ACTION_PASS = 'rhsmtp_set_password';
    private const NONCE = 'rhsmtp_tools_nonce';

    public function __construct(private readonly Smtp $smtp)
    {
    }

    public function boot(): void
    {
        add_action('rh-blueprint/settings/tab_content_after', [$this, 'render']);
        add_action('admin_post_' . self::ACTION_CONNECT, [$this, 'handleConnect']);
        add_action('admin_post_' . self::ACTION_MAIL, [$this, 'handleMail']);
        add_action('admin_post_' . self::ACTION_PASS, [$this, 'handlePassword']);
    }

    public function render(string $tab): void
    {
        if ($tab !== self::TAB || ! current_user_can('manage_options')) {
            return;
        }

        echo '<hr style="margin:2rem 0">';
        echo '<h2>' . esc_html__('Test-Werkzeuge', 'rh-smtp') . '</h2>';
        echo '<p class="description">' . esc_html__('Nutzen die gespeicherten Einstellungen. Erst oben speichern, dann testen.', 'rh-smtp') . '</p>';

        $result = get_transient('rhsmtp_result_' . get_current_user_id());
        if (is_array($result) && isset($result['message'])) {
            delete_transient('rhsmtp_result_' . get_current_user_id());
            $class = ! empty($result['ok']) ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . ' inline"><p>' . esc_html((string) $result['message']) . '</p></div>';
        }

        // SMTP-Passwort (write-only, verschlüsselt gespeichert)
        $hasConstant = defined('RH_SMTP_PASS') && \RH_SMTP_PASS !== '';
        $hasStored = (string) get_option(Smtp::PASS_OPTION, '') !== '';
        echo '<h3>' . esc_html__('SMTP-Passwort', 'rh-smtp') . '</h3>';
        if ($hasConstant) {
            echo '<p class="description">' . esc_html__('Passwort ist über die Konstante RH_SMTP_PASS (wp-config.php) gesetzt, das ist die sicherste Variante. Das Feld unten wird ignoriert, solange die Konstante existiert.', 'rh-smtp') . '</p>';
        } else {
            echo '<p class="description">' . esc_html__('Wird verschlüsselt in der Datenbank gespeichert (libsodium, Schlüssel aus der wp-config). Noch sicherer: define(\'RH_SMTP_PASS\', \'...\') in der wp-config.php, dann steht es gar nicht in der DB.', 'rh-smtp') . '</p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:.8rem 0;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_PASS) . '">';
        wp_nonce_field(self::ACTION_PASS, self::NONCE);
        echo '<input type="password" name="smtp_password" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr($hasStored ? __('•••••••• (gesetzt, leer lassen = unverändert)', 'rh-smtp') : __('Passwort eingeben', 'rh-smtp')) . '">';
        if ($hasStored) {
            echo '<label style="white-space:nowrap"><input type="checkbox" name="smtp_password_clear" value="1"> ' . esc_html__('entfernen', 'rh-smtp') . '</label>';
        }
        submit_button(__('Passwort speichern', 'rh-smtp'), 'secondary', 'submit', false);
        echo '</form>';

        // Verbindung testen
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:.8rem 0">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_CONNECT) . '">';
        wp_nonce_field(self::ACTION_CONNECT, self::NONCE);
        submit_button(__('Verbindung testen', 'rh-smtp'), 'secondary', 'submit', false);
        echo '</form>';

        // Testmail
        $defaultTo = (string) get_option('admin_email');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:.8rem 0;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_MAIL) . '">';
        wp_nonce_field(self::ACTION_MAIL, self::NONCE);
        echo '<input type="email" name="test_to" class="regular-text" value="' . esc_attr($defaultTo) . '" placeholder="' . esc_attr__('Empfänger der Testmail', 'rh-smtp') . '">';
        submit_button(__('Testmail senden', 'rh-smtp'), 'secondary', 'submit', false);
        echo '</form>';
    }

    public function handleConnect(): void
    {
        $this->guard(self::ACTION_CONNECT);
        $this->store($this->smtp->testConnection());
        $this->back();
    }

    public function handleMail(): void
    {
        $this->guard(self::ACTION_MAIL);
        $to = isset($_POST['test_to']) ? sanitize_email(wp_unslash($_POST['test_to'])) : '';
        $this->store($this->smtp->sendTestMail($to));
        $this->back();
    }

    public function handlePassword(): void
    {
        $this->guard(self::ACTION_PASS);

        if (! empty($_POST['smtp_password_clear'])) {
            delete_option(Smtp::PASS_OPTION);
            $this->store(['ok' => true, 'message' => __('SMTP-Passwort entfernt.', 'rh-smtp')]);
            $this->back();
        }

        // Bewusst kein sanitize_text_field: ein Passwort darf alle Zeichen enthalten.
        $password = isset($_POST['smtp_password']) ? (string) wp_unslash($_POST['smtp_password']) : '';
        if ($password === '') {
            $this->store(['ok' => true, 'message' => __('Passwort unverändert (Feld war leer).', 'rh-smtp')]);
            $this->back();
        }

        update_option(Smtp::PASS_OPTION, Secret::encrypt($password), false);
        $this->store(['ok' => true, 'message' => __('SMTP-Passwort verschlüsselt gespeichert.', 'rh-smtp')]);
        $this->back();
    }

    private function guard(string $action): void
    {
        $nonce = isset($_POST[self::NONCE]) ? sanitize_text_field(wp_unslash($_POST[self::NONCE])) : '';
        if ($nonce === '' || ! wp_verify_nonce($nonce, $action)) {
            wp_die(esc_html__('Sicherheitsprüfung fehlgeschlagen.', 'rh-smtp'));
        }
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-smtp'));
        }
    }

    /**
     * @param array{ok: bool, message: string} $result
     */
    private function store(array $result): void
    {
        set_transient('rhsmtp_result_' . get_current_user_id(), $result, 60);
    }

    private function back(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . SettingsPage::MENU_SLUG . '&tab=' . self::TAB));
        exit;
    }
}
