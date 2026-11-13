<?php

/*
 * The colour themes of the modern template.
 *
 * Each theme is a set of colour tokens in templates/modern/css/brand.css
 * (html[data-palette="<id>"]). "mode" tells the browser whether the theme
 * is light or dark, so form controls and scrollbars match. "swatch" is only
 * used to draw the preview in the admin centre.
 */
class Services_Admin_Themes
{
    const DEFAULT_THEME = 'auto';

    public static function getThemes()
    {
        return [
            'auto'     => ['name' => _('Automatic'), 'mode' => 'auto', 'description' => _('Midnight or Daylight, following the visitor\'s device'), 'swatch' => ['#0B0F14', '#F5F6F9', '#8B7CFF']],
            'midnight' => ['name' => _('Midnight'), 'mode' => 'dark', 'description' => _('Dark with a violet accent (house style)'), 'swatch' => ['#0B0F14', '#10151C', '#8B7CFF']],
            'daylight' => ['name' => _('Daylight'), 'mode' => 'light', 'description' => _('Light with a violet accent'), 'swatch' => ['#F5F6F9', '#FFFFFF', '#6D5DF5']],
            'ocean'    => ['name' => _('Ocean'), 'mode' => 'dark', 'description' => _('Deep blue-green with a cyan accent'), 'swatch' => ['#071318', '#0C1B22', '#22D3EE']],
            'forest'   => ['name' => _('Forest'), 'mode' => 'dark', 'description' => _('Dark green with an emerald accent'), 'swatch' => ['#0A120E', '#0F1A14', '#34D399']],
            'ember'    => ['name' => _('Ember'), 'mode' => 'dark', 'description' => _('Warm dark brown with an amber accent'), 'swatch' => ['#120D0A', '#1A1310', '#F59E0B']],
            'paper'    => ['name' => _('Paper'), 'mode' => 'light', 'description' => _('Warm off-white with a terracotta accent'), 'swatch' => ['#F7F3EC', '#FFFDF8', '#C2410C']],
            'contrast' => ['name' => _('High contrast'), 'mode' => 'dark', 'description' => _('Black and white with a yellow accent, for readability'), 'swatch' => ['#000000', '#0A0A0A', '#FFD400']],
        ];
    }

    public static function isValid($themeId)
    {
        return is_string($themeId) && array_key_exists($themeId, self::getThemes());
    }

    public static function getSiteTheme(Services_Settings_Container $settings)
    {
        $theme = $settings->get('site_theme');

        return self::isValid($theme) ? $theme : self::DEFAULT_THEME;
    }

    /*
     * The spot list layouts: cards (default) or a compact table
     */
    const DEFAULT_VIEW = 'cards';

    public static function getViews()
    {
        return ['cards' => _('Cards'), 'table' => _('Table')];
    }

    public static function isValidView($view)
    {
        return is_string($view) && array_key_exists($view, self::getViews());
    }

    public static function getSiteView(Services_Settings_Container $settings)
    {
        $view = $settings->get('site_view');

        return self::isValidView($view) ? $view : self::DEFAULT_VIEW;
    }

    // when locked, everyone gets the site view and the View button is hidden
    public static function isViewLocked(Services_Settings_Container $settings)
    {
        return (bool) $settings->get('site_view_locked');
    }

    /*
     * The view to render: the locked site view, else ?view=, else the cookie, else the site view
     */
    public static function resolveView(Services_Settings_Container $settings, $requested, $cookie)
    {
        if (!self::isViewLocked($settings)) {
            foreach ([$requested, $cookie] as $candidate) {
                if (self::isValidView($candidate)) {
                    return $candidate;
                } // if
            } // foreach
        } // if

        return self::getSiteView($settings);
    }

    /*
     * Theme id => light/dark/auto, for the script that applies a theme
     */
    public static function getModes()
    {
        $modes = [];
        foreach (self::getThemes() as $id => $theme) {
            $modes[$id] = $theme['mode'];
        } // foreach

        return $modes;
    }
}
