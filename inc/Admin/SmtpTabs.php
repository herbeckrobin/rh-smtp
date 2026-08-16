<?php

declare(strict_types=1);

namespace RhSmtp\Admin;

use RhBlueprint\Core\Admin\Guard;
use RhBlueprint\Core\Admin\MailPanel;
use RhBlueprint\Core\Settings\SettingField;
use RhBlueprint\Core\Settings\SettingsHub;
use RhBlueprint\Core\Settings\SettingsPage;

/**
 * Gliedert den SMTP-Bereich in Reiter.
 *
 * Vorher stand alles untereinander auf einer Seite: Zugangsdaten, Testmodus,
 * Sammelbericht, Protokoll und die Werkzeuge. Das sind vier Themen, die man zu
 * verschiedenen Zeitpunkten anfasst. Die Zugangsdaten stellt man einmal ein,
 * den Testmodus beim Klonen einer Seite, den Bericht wenn man ihn einrichtet,
 * und ins Protokoll schaut man nur, wenn eine Mail vermisst wird.
 *
 * Eine Ebene, vier Reiter, echte Links. Kein zweites Menü darüber: der Punkt
 * hängt in derselben Leiste wie alles andere.
 *
 * Die Felder rendert diese Klasse selbst und speichert sie über einen eigenen
 * Handler. Der Weg über die Automatik des Core geht hier nicht: sie rendert
 * alle Felder einer Gruppe in ein Formular, und ihr Sanitizer setzt jedes Feld
 * zurück, das nicht mitgeschickt wurde. Bei aufgeteilten Reitern würde das
 * Speichern eines Reiters die Werte der anderen löschen. Dasselbe Muster fährt
 * rh-hardening aus demselben Grund.
 */
final class SmtpTabs
{
    public const TAB_ID = 'smtp';
    public const PARAM = 'sub';

    public const PANE_SEND = 'versand';
    public const PANE_TEST = 'testmodus';
    public const PANE_REPORT = 'bericht';
    public const PANE_LOG = 'protokoll';

    private const ACTION = 'rhsmtp_save_fields';

    /**
     * @return array<string, string>
     */
    public static function panes(): array
    {
        return [
            self::PANE_SEND => __('Versand', 'rh-smtp'),
            self::PANE_TEST => __('Testmodus', 'rh-smtp'),
            self::PANE_REPORT => __('Bericht', 'rh-smtp'),
            self::PANE_LOG => __('Protokoll', 'rh-smtp'),
        ];
    }

    /**
     * Welche Felder gehören in welchen Reiter. Was hier nicht steht, wird auch
     * nicht gerendert und nicht gespeichert.
     *
     * @return array<string, array<int, string>>
     */
    public static function fieldsFor(string $pane): array
    {
        $map = [
            self::PANE_SEND => [
                __('Zugang', 'rh-smtp') => [
                    SmtpGroup::FIELD_ENABLED,
                    SmtpGroup::FIELD_HOST,
                    SmtpGroup::FIELD_PORT,
                    SmtpGroup::FIELD_ENCRYPTION,
                    SmtpGroup::FIELD_USERNAME,
                    SmtpGroup::FIELD_TIMEOUT,
                ],
                __('Absender', 'rh-smtp') => [
                    SmtpGroup::FIELD_FROM_EMAIL,
                    SmtpGroup::FIELD_FROM_NAME,
                ],
            ],
            self::PANE_TEST => [
                __('Ausgehende Mails abfangen', 'rh-smtp') => [
                    SmtpGroup::FIELD_TEST_MODE,
                    SmtpGroup::FIELD_REDIRECT_TO,
                ],
            ],
            self::PANE_REPORT => [
                __('Sammelbericht', 'rh-smtp') => [
                    SmtpGroup::FIELD_REPORT_ENABLED,
                    SmtpGroup::FIELD_REPORT_RHYTHM,
                    SmtpGroup::FIELD_REPORT_EMAIL,
                ],
            ],
            self::PANE_LOG => [
                __('Protokoll', 'rh-smtp') => [
                    SmtpGroup::FIELD_LOG_ENABLED,
                    SmtpGroup::FIELD_LOG_RETENTION,
                ],
            ],
        ];

        return $map[$pane] ?? [];
    }

    public static function current(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Anzeige-Auswahl.
        $wunsch = isset($_GET[self::PARAM]) ? sanitize_key((string) wp_unslash($_GET[self::PARAM])) : '';

        return array_key_exists($wunsch, self::panes()) ? $wunsch : self::PANE_SEND;
    }

    public static function url(string $pane): string
    {
        return add_query_arg(
            ['page' => SettingsPage::MENU_SLUG, 'tab' => self::TAB_ID, self::PARAM => $pane],
            admin_url('admin.php')
        );
    }

    public function boot(): void
    {
        // Priorität 5: vor allen, die Karten beisteuern.
        add_action('rh-blueprint/settings/tab_content_after', [$this, 'render'], 5);
        add_action('admin_post_' . self::ACTION, [$this, 'save']);
    }

    public function render(string $tabId): void
    {
        if ($tabId !== self::TAB_ID || ! current_user_can('manage_options')) {
            return;
        }

        $aktiv = self::current();

        echo '<div class="rhbp-subtabs">';
        foreach (self::panes() as $key => $label) {
            printf(
                '<a class="rhbp-subtab%s" href="%s">%s</a>',
                $key === $aktiv ? ' is-active' : '',
                esc_url(self::url($key)),
                esc_html($label)
            );
        }
        echo '</div>';

        $this->renderFields($aktiv);

        /**
         * Karten für diesen Reiter. Werkzeuge, Protokoll und die
         * Mail-Einstellungen hängen sich hier ein.
         *
         * @param string $pane
         */
        do_action('rh-smtp/pane', $aktiv);
    }

    /**
     * Die Felder des Reiters, gruppiert, in einem Formular.
     */
    private function renderFields(string $pane): void
    {
        $gruppen = self::fieldsFor($pane);

        if ($gruppen === []) {
            return;
        }

        $felder = new SmtpGroup();
        $werte = get_option(SettingsHub::optionName(SmtpGroup::GROUP_ID), []);
        $werte = is_array($werte) ? $werte : [];

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhbp-card rhsmtp-form">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<input type="hidden" name="sub" value="' . esc_attr($pane) . '">';
        wp_nonce_field(self::ACTION);

        foreach ($gruppen as $titel => $ids) {
            printf('<h3 class="rhsmtp-form__title">%s</h3>', esc_html((string) $titel));

            foreach ($ids as $id) {
                $field = $this->field($felder, $id);

                if ($field === null) {
                    continue;
                }

                // Die Kennung mitschicken, auch für abgewählte Schalter: eine
                // nicht angehakte Checkbox steht nicht im Formulardatensatz,
                // und ohne diese Liste liesse sich ein Schalter nie ausschalten.
                printf('<input type="hidden" name="felder[]" value="%s">', esc_attr($id));

                // Den Erklärtext gibt das Feld selbst aus, und ein Schalter
                // bringt seine Beschriftung ebenfalls mit. Nur die übrigen
                // Feldarten brauchen hier eine, sonst steht alles doppelt da.
                echo '<div class="rhbp-field">';

                if ($field->type !== SettingField::TYPE_BOOLEAN) {
                    printf('<label>%s</label>', esc_html($field->label));
                }

                $field->render('werte[' . $id . ']', $werte[$id] ?? $field->default);
                echo '</div>';
            }
        }

        echo '<p class="rhsmtp-form__foot"><button type="submit" class="rhbp-btn rhbp-btn--primary">'
            . esc_html__('Änderungen speichern', 'rh-smtp')
            . '</button></p>';

        echo '</form>';
    }

    public function save(): void
    {
        Guard::form(self::ACTION);

        $pane = isset($_POST['sub']) ? sanitize_key((string) wp_unslash($_POST['sub'])) : self::PANE_SEND;

        /** @var array<int, string> $gesendet */
        $gesendet = isset($_POST['felder']) && is_array($_POST['felder'])
            ? array_map('sanitize_key', wp_unslash($_POST['felder']))
            : [];

        // Nur die Felder dieses Reiters, und davon nur die, die auch wirklich
        // gerendert wurden. Alles andere bleibt unangetastet.
        $erlaubt = [];
        foreach (self::fieldsFor($pane) as $ids) {
            foreach ($ids as $id) {
                $erlaubt[$id] = true;
            }
        }

        $gruppe = new SmtpGroup();
        $option = SettingsHub::optionName(SmtpGroup::GROUP_ID);
        $stand = get_option($option, []);
        $stand = is_array($stand) ? $stand : [];

        /** @var array<string, mixed> $roh */
        $roh = isset($_POST['werte']) && is_array($_POST['werte']) ? wp_unslash($_POST['werte']) : [];

        foreach ($gesendet as $id) {
            if (! isset($erlaubt[$id])) {
                continue;
            }

            $field = $this->field($gruppe, $id);

            if ($field === null) {
                continue;
            }

            $stand[$id] = $field->type === SettingField::TYPE_BOOLEAN
                ? ! empty($roh[$id])
                : $field->sanitize($roh[$id] ?? null);
        }

        update_option($option, $stand);

        wp_safe_redirect(add_query_arg('rhsmtp-saved', '1', self::url($pane)));
        exit;
    }

    private function field(SmtpGroup $group, string $id): ?SettingField
    {
        foreach ($group->fields() as $field) {
            if ($field->id === $id) {
                return $field;
            }
        }

        return null;
    }
}
