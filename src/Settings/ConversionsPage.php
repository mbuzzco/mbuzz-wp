<?php
/**
 * Mbuzz → Conversions overview screen, and the top-level admin menu it anchors.
 *
 * Thin controller: it registers the menu (Mbuzz ▸ Conversions + Settings),
 * collects each form's saved map from the form plugin, and hands a view-model
 * to the presenter + template. All display logic lives there; all role-counting
 * logic lives in the presenter — both unit-tested without WordPress.
 *
 * @package Mbuzz\WP\Settings
 */

declare(strict_types=1);

namespace Mbuzz\WP\Settings;

use Mbuzz\WP\Support\View;
use Mbuzz\WP\Tracking\FieldMapRepository;
use Mbuzz\WP\Tracking\Source;

final class ConversionsPage
{
    public const MENU_SLUG = 'mbuzz';

    private const CAP            = 'manage_options';
    private const MENU_ICON      = 'dashicons-filter'; // funnel — matches the mbuzz mark
    private const MENU_POSITION  = 58;                 // just above Appearance
    private const TEMPLATE       = 'admin/conversions-overview';
    private const CF7_FORM_CLASS = 'WPCF7_ContactForm';
    private const CF7_EDIT_PAGE  = 'wpcf7';

    public static function registerMenu(): void
    {
        // Top-level menu so it's discoverable in the sidebar (a Settings
        // submenu is invisible under a collapsed, plugin-crowded menu).
        add_menu_page(
            __('Mbuzz Attribution', 'mbuzz-attribution'),
            __('Mbuzz', 'mbuzz-attribution'),
            self::CAP,
            self::MENU_SLUG,
            [self::class, 'render'],
            self::MENU_ICON,
            self::MENU_POSITION
        );

        // Rename the auto-created first submenu from "Mbuzz" to "Conversions".
        add_submenu_page(
            self::MENU_SLUG,
            __('Conversions', 'mbuzz-attribution'),
            __('Conversions', 'mbuzz-attribution'),
            self::CAP,
            self::MENU_SLUG,
            [self::class, 'render']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Mbuzz Settings', 'mbuzz-attribution'),
            __('Settings', 'mbuzz-attribution'),
            self::CAP,
            Page::SETTINGS_SLUG,
            [Page::class, 'render']
        );
    }

    public static function render(): void
    {
        if (! current_user_can(self::CAP)) {
            return;
        }

        $presenter = new ConversionsOverviewPresenter();
        $view      = $presenter->present(self::collectForms(), Repository::current()['api_key'] !== '');

        // WP-derived URLs are injected here so the presenter stays pure.
        $view['settings_url'] = admin_url('admin.php?page=' . Page::SETTINGS_SLUG);

        View::render(self::TEMPLATE, $view);
    }

    /**
     * @return array<int, FormSummary>
     */
    private static function collectForms(): array
    {
        if (! class_exists(self::CF7_FORM_CLASS)) {
            return [];
        }

        $forms = [];
        foreach (\WPCF7_ContactForm::find() as $contactForm) {
            $id      = (int) $contactForm->id();
            $forms[] = new FormSummary(
                (string) $contactForm->title(),
                admin_url('admin.php?page=' . self::CF7_EDIT_PAGE . '&post=' . $id . '&action=edit'),
                FieldMapRepository::for(Source::CF7, $id)
            );
        }

        return $forms;
    }
}
