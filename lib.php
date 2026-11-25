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
 * Legacy callbacks and navigation hooks for local_examguard.
 *
 * The legacy callbacks below keep the plugin working on Moodle 3.9 to 4.3.
 * On Moodle 4.4 and above the same logic is registered through db/hooks.php,
 * and core automatically ignores the legacy callback to avoid duplicate output.
 * The static guards inside hook_listener make the behaviour safe either way.
 *
 * @package    local_examguard
 * @copyright  2026 Librian Yudhista Pambudi (https://librian.my.id)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Legacy callback: inject the anti-flash lock CSS and bootstrap into the page head.
 *
 * @return string
 */
function local_examguard_before_standard_html_head() {
    return \local_examguard\hook_listener::get_head_html();
}

/**
 * Legacy callback: inject the configuration JSON and guard.js before the body closes.
 *
 * @return string
 */
function local_examguard_before_footer() {
    return \local_examguard\hook_listener::get_footer_html();
}

/**
 * Add the ExamGuard report link to the course administration navigation.
 *
 * @param navigation_node $navigation course navigation node.
 * @param stdClass $course course record.
 * @param context $context course context.
 */
function local_examguard_extend_navigation_course($navigation, $course, $context) {
    if (!has_capability('local/examguard:viewreport', $context)) {
        return;
    }

    $url = new moodle_url('/local/examguard/index.php', ['courseid' => $course->id]);
    $navigation->add(
        get_string('reporttitle', 'local_examguard'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_examguard_report',
        new pix_icon('i/report', '')
    );
}

/**
 * Inject per-quiz ExamGuard settings into the quiz mod_form.
 *
 * Called by Moodle core when building the activity edit form. Only acts when
 * the module being edited is a quiz. Adds an "ExamGuard Security" header and
 * three fields: enabled, penaltyseconds and freezetimermax.
 *
 * @param \moodleform_mod $formwrapper the mod_form wrapper instance.
 * @param \MoodleQuickForm  $mform     the underlying QuickForm object.
 */
function local_examguard_coursemodule_standard_elements($formwrapper, $mform) {
    global $DB;

    // Check if the current form is for the quiz module.
    // 1) Verify instance of mod_quiz_mod_form (standard Moodle form class for quiz).
    // 2) Fallback to checking modulename from current form data.
    $isquiz = ($formwrapper instanceof \mod_quiz_mod_form);
    if (!$isquiz && method_exists($formwrapper, 'get_current')) {
        $current = $formwrapper->get_current();
        if (!empty($current) && isset($current->modulename) && $current->modulename === 'quiz') {
            $isquiz = true;
        }
    }
    if (!$isquiz) {
        return;
    }

    // ── Section header ────────────────────────────────────────────────────
    $mform->addElement('header', 'examguard_header',
        get_string('quizsettingsheading', 'local_examguard'));
    $mform->addHelpButton('examguard_header', 'quizsettingsheading', 'local_examguard');
    $mform->setExpanded('examguard_header', false);

    // ── enabled (Yes / No) ───────────────────────────────────────────────
    $mform->addElement('select', 'examguard_enabled',
        get_string('quiz_enabled', 'local_examguard'),
        [0 => get_string('no'), 1 => get_string('yes')]);
    $mform->addHelpButton('examguard_enabled', 'quiz_enabled', 'local_examguard');
    $mform->setDefault('examguard_enabled', 0);
    $mform->setType('examguard_enabled', PARAM_INT);

    // ── penaltyseconds ───────────────────────────────────────────────────
    $mform->addElement('text', 'examguard_penaltyseconds',
        get_string('quiz_penaltyseconds', 'local_examguard'));
    $mform->addHelpButton('examguard_penaltyseconds', 'quiz_penaltyseconds', 'local_examguard');
    $mform->setDefault('examguard_penaltyseconds', 120);
    $mform->setType('examguard_penaltyseconds', PARAM_INT);
    $mform->addRule('examguard_penaltyseconds', null, 'numeric', null, 'client');
    $mform->hideIf('examguard_penaltyseconds', 'examguard_enabled', 'eq', 0);

    // ── freezetimermax ───────────────────────────────────────────────────
    $mform->addElement('text', 'examguard_freezetimermax',
        get_string('quiz_freezetimermax', 'local_examguard'));
    $mform->addHelpButton('examguard_freezetimermax', 'quiz_freezetimermax', 'local_examguard');
    $mform->setDefault('examguard_freezetimermax', 600);
    $mform->setType('examguard_freezetimermax', PARAM_INT);
    $mform->addRule('examguard_freezetimermax', null, 'numeric', null, 'client');
    $mform->hideIf('examguard_freezetimermax', 'examguard_enabled', 'eq', 0);

    // ── watermark (Yes / No) ─────────────────────────────────────────────
    $defaultwatermark = \local_examguard\penalty_manager::setting_bool('watermarkenabled', true) ? 1 : 0;
    $mform->addElement('select', 'examguard_watermark',
        get_string('quiz_watermark', 'local_examguard'),
        [0 => get_string('no'), 1 => get_string('yes')]);
    $mform->addHelpButton('examguard_watermark', 'quiz_watermark', 'local_examguard');
    $mform->setDefault('examguard_watermark', $defaultwatermark);
    $mform->setType('examguard_watermark', PARAM_INT);
    $mform->hideIf('examguard_watermark', 'examguard_enabled', 'eq', 0);

    // ── lang (id / en / auto) ────────────────────────────────────────────
    $defaultlang = \local_examguard\lang_helper::resolve_lang(null);
    $langoptions = [
        'id'   => 'Bahasa Indonesia',
        'en'   => 'English',
        'auto' => get_string('quiz_lang_auto', 'local_examguard'),
    ];
    $mform->addElement('select', 'examguard_lang',
        get_string('quiz_lang', 'local_examguard'),
        $langoptions);
    $mform->addHelpButton('examguard_lang', 'quiz_lang', 'local_examguard');
    $mform->setDefault('examguard_lang', $defaultlang);
    $mform->setType('examguard_lang', PARAM_ALPHANUMEXT);
    $mform->hideIf('examguard_lang', 'examguard_enabled', 'eq', 0);

    // ── Pre-populate when editing an existing quiz ────────────────────────
    $quizid = 0;
    if (method_exists($formwrapper, 'get_instance')) {
        $quizid = (int) $formwrapper->get_instance();
    }
    if ($quizid <= 0 && method_exists($formwrapper, 'get_coursemodule')) {
        $cm = $formwrapper->get_coursemodule();
        if ($cm && !empty($cm->instance)) {
            $quizid = (int) $cm->instance;
        }
    }
    if ($quizid > 0) {
        $existing = $DB->get_record('local_examguard_quiz', ['quizid' => $quizid]);
        if ($existing) {
            $mform->setDefault('examguard_enabled',        (int) $existing->enabled);
            $mform->setDefault('examguard_penaltyseconds', (int) $existing->penaltyseconds);
            $mform->setDefault('examguard_freezetimermax', (int) $existing->freezetimermax);
            $mform->setDefault('examguard_watermark',      isset($existing->watermark) ? (int) $existing->watermark : $defaultwatermark);
            $mform->setDefault('examguard_lang',           isset($existing->lang) ? $existing->lang : $defaultlang);
        }
    }
}

/**
 * Save per-quiz ExamGuard settings after the quiz mod_form is submitted.
 *
 * Called by Moodle core after the activity settings form is saved. Performs an
 * upsert into local_examguard_quiz for the saved quiz instance.
 *
 * @param stdClass $data   the submitted form data / moduleinfo object.
 * @param stdClass $course the course record (or coursemodule if passed by caller).
 * @return stdClass the unmodified $data object (required by the callback contract).
 */
function local_examguard_coursemodule_edit_post_actions($data, $course) {
    global $DB;

    // Resolve module name from $data->modulename (standard Moodle) or $course->modname.
    $modulename = $data->modulename ?? ($course->modname ?? '');
    if ($modulename !== 'quiz') {
        return $data;
    }

    $quizid = (int) ($data->instance ?? ($course->instance ?? 0));
    if ($quizid <= 0) {
        return $data;
    }

    $now       = time();
    $enabled   = (int) ($data->examguard_enabled ?? 0);
    $penalty   = max(1, (int) ($data->examguard_penaltyseconds ?? 120));
    $freeze    = max(0, (int) ($data->examguard_freezetimermax ?? 600));
    $watermark = (int) ($data->examguard_watermark ?? 0);
    $rawlang   = (string) ($data->examguard_lang ?? 'id');
    $lang      = in_array($rawlang, ['id', 'en', 'auto'], true) ? $rawlang : 'id';

    $existing = $DB->get_record('local_examguard_quiz', ['quizid' => $quizid]);
    if ($existing) {
        $existing->enabled        = $enabled;
        $existing->penaltyseconds = $penalty;
        $existing->freezetimermax = $freeze;
        $existing->watermark      = $watermark;
        $existing->lang           = $lang;
        $existing->timemodified   = $now;
        $DB->update_record('local_examguard_quiz', $existing);
    } else {
        $DB->insert_record('local_examguard_quiz', (object) [
            'quizid'         => $quizid,
            'enabled'        => $enabled,
            'penaltyseconds' => $penalty,
            'freezetimermax' => $freeze,
            'watermark'      => $watermark,
            'lang'           => $lang,
            'timecreated'    => $now,
            'timemodified'   => $now,
        ]);
    }

    return $data;
}
