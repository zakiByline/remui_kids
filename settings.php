<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Settings for theme_remui_kids
 *
 * @package    theme_remui_kids
 * @copyright  2025 Kodeit
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    $settings = new admin_settingpage('themesettingremui_kids', get_string('configtitle', 'theme_remui_kids'));

    // Preset.
    $name = 'theme_remui_kids/preset';
    $title = get_string('preset', 'theme_remui_kids');
    $description = get_string('preset_desc', 'theme_remui_kids');
    $default = 'default.scss';

    $context = context_system::instance();
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'theme_remui_kids', 'preset', 0, 'itemid, filepath, filename', false);

    $choices = [];
    foreach ($files as $file) {
        $choices[$file->get_filename()] = $file->get_filename();
    }
    // These are the built in presets.
    $choices['default.scss'] = 'default.scss';
    $choices['plain.scss'] = 'plain.scss';

    $setting = new admin_setting_configselect($name, $title, $description, $default, $choices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);

    // Preset files setting.
    $name = 'theme_remui_kids/presetfiles';
    $title = get_string('presetfiles','theme_remui_kids');
    $description = get_string('presetfiles_desc', 'theme_remui_kids');

    $setting = new admin_setting_configstoredfile($name, $title, $description, 'preset', 0,
        array('maxfiles' => 20, 'accepted_types' => array('.scss')));
    $settings->add($setting);

    // Background image setting.
    $name = 'theme_remui_kids/backgroundimage';
    $title = get_string('backgroundimage', 'theme_remui_kids');
    $description = get_string('backgroundimage_desc', 'theme_remui_kids');
    $setting = new admin_setting_configstoredfile($name, $title, $description, 'backgroundimage');
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);

    // Raw SCSS to include before the main SCSS.
    $setting = new admin_setting_scsscode('theme_remui_kids/scsspre',
        get_string('rawscsspre', 'theme_remui_kids'), get_string('rawscsspre_desc', 'theme_remui_kids'), '', PARAM_RAW);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);

    // Raw SCSS to include after the main SCSS.
    $setting = new admin_setting_scsscode('theme_remui_kids/scss', get_string('rawscss', 'theme_remui_kids'),
        get_string('rawscss_desc', 'theme_remui_kids'), '', PARAM_RAW);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $settings->add($setting);
}
