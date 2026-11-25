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
 * Admin settings for local_examguard.
 *
 * @package    local_examguard
 * @copyright  2026 Librian Yudhista Pambudi (https://librian.my.id)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_examguard', get_string('pluginname', 'local_examguard'));
    $ADMIN->add('localplugins', $settings);

    // General behaviour.
    $settings->add(new admin_setting_heading('local_examguard/generalheading',
        get_string('settingsheading_general', 'local_examguard'), ''));

    $settings->add(new admin_setting_configcheckbox('local_examguard/enabled',
        get_string('enabled', 'local_examguard'), get_string('enabled_desc', 'local_examguard'), 1));

    $settings->add(new admin_setting_configselect('local_examguard/defaultlang',
        get_string('defaultlang', 'local_examguard'), get_string('defaultlang_desc', 'local_examguard'),
        'id', [
            'id'   => 'Bahasa Indonesia',
            'en'   => 'English',
            'auto' => get_string('quiz_lang_auto', 'local_examguard'),
        ]));

    $settings->add(new admin_setting_configcheckbox('local_examguard/applytoteachers',
        get_string('applytoteachers', 'local_examguard'),
        get_string('applytoteachers_desc', 'local_examguard'), 0));

    $settings->add(new admin_setting_configtext('local_examguard/penaltyseconds',
        get_string('penaltyseconds', 'local_examguard'), get_string('penaltyseconds_desc', 'local_examguard'),
        120, PARAM_INT));

    $settings->add(new admin_setting_configtext('local_examguard/dedupewindow',
        get_string('dedupewindow', 'local_examguard'), get_string('dedupewindow_desc', 'local_examguard'),
        3, PARAM_INT));

    $settings->add(new admin_setting_configcheckbox('local_examguard/freezetimer',
        get_string('freezetimer', 'local_examguard'), get_string('freezetimer_desc', 'local_examguard'), 1));

    $settings->add(new admin_setting_configtext('local_examguard/freezetimermax',
        get_string('freezetimermax', 'local_examguard'), get_string('freezetimermax_desc', 'local_examguard'),
        600, PARAM_INT));

    $settings->add(new admin_setting_configcheckbox('local_examguard/headlock',
        get_string('headlock', 'local_examguard'), get_string('headlock_desc', 'local_examguard'), 1));

    // AI / DOM poisoning.
    $settings->add(new admin_setting_heading('local_examguard/domheading',
        get_string('settingsheading_dom', 'local_examguard'), ''));

    $settings->add(new admin_setting_configcheckbox('local_examguard/trapenabled',
        get_string('trapenabled', 'local_examguard'), get_string('trapenabled_desc', 'local_examguard'), 1));

    $settings->add(new admin_setting_configtextarea('local_examguard/traptext',
        get_string('traptext', 'local_examguard'), get_string('traptext_desc', 'local_examguard'),
        get_string('defaulttraptext', 'local_examguard'), PARAM_RAW, 6, 60));

    $settings->add(new admin_setting_configcheckbox('local_examguard/zwspenabled',
        get_string('zwspenabled', 'local_examguard'), get_string('zwspenabled_desc', 'local_examguard'), 1));

    // Copy / screenshot protection.
    $settings->add(new admin_setting_heading('local_examguard/protectionheading',
        get_string('settingsheading_protection', 'local_examguard'), ''));

    $settings->add(new admin_setting_configcheckbox('local_examguard/blockcopy',
        get_string('blockcopy', 'local_examguard'), get_string('blockcopy_desc', 'local_examguard'), 1));

    $settings->add(new admin_setting_configcheckbox('local_examguard/blockdevtools',
        get_string('blockdevtools', 'local_examguard'), get_string('blockdevtools_desc', 'local_examguard'), 0));

    $settings->add(new admin_setting_configcheckbox('local_examguard/watermarkenabled',
        get_string('watermarkenabled', 'local_examguard'),
        get_string('watermarkenabled_desc', 'local_examguard'), 1));

    $settings->add(new admin_setting_configtext('local_examguard/watermarkopacity',
        get_string('watermarkopacity', 'local_examguard'),
        get_string('watermarkopacity_desc', 'local_examguard'), '0.12', PARAM_FLOAT));

    // Data retention and reports.
    $settings->add(new admin_setting_heading('local_examguard/privacyheading',
        get_string('settingsheading_privacy', 'local_examguard'), ''));

    $settings->add(new admin_setting_configtext('local_examguard/retentiondays',
        get_string('retentiondays', 'local_examguard'), get_string('retentiondays_desc', 'local_examguard'),
        90, PARAM_INT));

    $settings->add(new admin_setting_configtext('local_examguard/reportperpage',
        get_string('reportperpage', 'local_examguard'), get_string('reportperpage_desc', 'local_examguard'),
        50, PARAM_INT));

    // Credits.
    $creditslink = html_writer::link(
        'https://librian.my.id',
        'https://librian.my.id',
        ['target' => '_blank', 'rel' => 'noopener noreferrer']
    );
    $credits = get_string('plugincredits', 'local_examguard', (object) [
        'author'  => 'Librian Yudhista Pambudi',
        'website' => $creditslink,
    ]);
    $settings->add(new admin_setting_heading('local_examguard/creditsheading', '',
        html_writer::div($credits, 'text-muted small mt-3 pt-2 border-top')));

    // Report page (external page in the admin tree).
    $ADMIN->add('localplugins', new admin_externalpage('local_examguard_report',
        get_string('reporttitle', 'local_examguard'),
        new moodle_url('/local/examguard/index.php'), 'local/examguard:viewall'));
}
