<?php
/**
 * Adds the "Mbuzz" panel to the Contact Form 7 form editor and saves it. Thin
 * controller: wires CF7's hooks, prepares data, and delegates to a presenter +
 * template (render) and the sanitizer + repository (save). No markup, no rules.
 *
 * Security: the panel rides CF7's editor form, which verifies its own nonce
 * before `wpcf7_save_contact_form`; we re-check the edit capability and
 * sanitize every value (FormMapFields).
 *
 * @package Mbuzz\WP\Settings
 */

declare(strict_types=1);

namespace Mbuzz\WP\Settings;

use Mbuzz\WP\Support\View;
use Mbuzz\WP\Tracking\AutoDetector;
use Mbuzz\WP\Tracking\FieldMapRepository;
use Mbuzz\WP\Tracking\Source;

final class Cf7EditorPanel
{
    public const PANEL_KEY = 'mbuzz';
    public const POST_KEY  = 'mbuzz_map';
    public const CAP_EDIT  = 'wpcf7_edit_contact_form';

    private const PANEL_TEMPLATE   = 'admin/cf7-panel';
    private const PANEL_TITLE_KEY  = 'title';
    private const PANEL_CB_KEY     = 'callback';
    /**
     * Tag types that are never data: the submit button, and the layout/plugin
     * containers some add-ons register (Conditional Fields' `group`, CF7MLS's
     * `cf7mls_step`). Listing them as mappable fields is noise at best and an
     * invitation to map a container at worst.
     */
    private const NON_FIELD_BASETYPES = ['submit', 'group', 'cf7mls_step'];

    /**
     * Panel behaviour ships as an enqueued asset, never inline: CF7 6.1+ routes
     * editor panel output through WPCF7_HTMLFormatter::print(), which runs
     * wp_kses() with the admin allowlist. That list has no `script` element, so
     * an inline <script> loses its tags and its body renders as page text.
     */
    public const SCRIPT_HANDLE = 'mbuzz-cf7-panel';

    private const SCRIPT_PATH = 'assets/admin/cf7-panel.js';

    /** CF7 registers its editor as a top-level admin page (slug `wpcf7`). */
    private const CF7_SCREEN_HOOK = 'toplevel_page_wpcf7';

    public static function register(): void
    {
        if (! class_exists('WPCF7_ContactForm')) {
            return;
        }
        add_filter('wpcf7_editor_panels', [self::class, 'addPanel']);
        add_action('wpcf7_save_contact_form', [self::class, 'save'], 10, 1);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets'], 10, 1);
    }

    /**
     * @param array<string, array<string, mixed>> $panels
     * @return array<string, array<string, mixed>>
     */
    public static function addPanel(array $panels): array
    {
        $panels[self::PANEL_KEY] = [
            self::PANEL_TITLE_KEY => __('Mbuzz', 'mbuzz-attribution'),
            self::PANEL_CB_KEY    => [self::class, 'render'],
        ];

        return $panels;
    }

    /**
     * Load the panel's behaviour on the CF7 form editor screen only.
     */
    public static function enqueueAssets(string $hookSuffix = ''): void
    {
        if ($hookSuffix !== self::CF7_SCREEN_HOOK) {
            return;
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            MBUZZ_ATTRIBUTION_URL . self::SCRIPT_PATH,
            [],
            MBUZZ_ATTRIBUTION_VERSION,
            true
        );
    }

    /**
     * @param object $contactForm
     */
    public static function render($contactForm): void
    {
        $fieldNames = self::scanFieldNames($contactForm);
        $map = FieldMapRepository::for(Source::CF7, (int) $contactForm->id())
            ?? AutoDetector::suggest($fieldNames, (string) $contactForm->title());

        View::render(self::PANEL_TEMPLATE, (new Cf7PanelPresenter(self::POST_KEY))->present($map, $fieldNames));
    }

    /**
     * @param object $contactForm
     */
    public static function save($contactForm): void
    {
        if (! current_user_can(self::CAP_EDIT, $contactForm->id())) {
            return;
        }
        if (! isset($_POST[self::POST_KEY]) || ! is_array($_POST[self::POST_KEY])) {
            return;
        }

        // CF7 verifies its editor nonce before this hook fires.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $raw = wp_unslash($_POST[self::POST_KEY]);
        FieldMapRepository::save(Source::CF7, (int) $contactForm->id(), FormMapFields::sanitize($raw));
    }

    /**
     * @param object $contactForm
     * @return array<int, string>
     */
    private static function scanFieldNames($contactForm): array
    {
        if (! method_exists($contactForm, 'scan_form_tags')) {
            return [];
        }

        $names = [];
        foreach ((array) $contactForm->scan_form_tags() as $tag) {
            $name = '';
            $type = '';
            if (is_object($tag)) {
                $name = isset($tag->name) ? (string) $tag->name : '';
                $type = isset($tag->basetype) ? (string) $tag->basetype : (isset($tag->type) ? (string) $tag->type : '');
            } elseif (is_array($tag)) {
                $name = (string) ($tag['name'] ?? '');
                $type = (string) ($tag['basetype'] ?? ($tag['type'] ?? ''));
            }
            if ($name === '' || in_array($type, self::NON_FIELD_BASETYPES, true)) {
                continue;
            }
            $names[$name] = true; // dedupe
        }

        return array_keys($names);
    }
}
