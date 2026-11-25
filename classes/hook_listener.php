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
 * Page injection logic for local_examguard.
 *
 * Works on Moodle 3.9 to 4.5: the legacy callbacks in lib.php call the
 * get_head_html() and get_footer_html() helpers, while db/hooks.php registers
 * the same helpers as hook callbacks on Moodle 4.4 and above. Core skips the
 * legacy callback once the hook is registered, and the static guards below
 * make sure the assets are never injected twice.
 *
 * @package    local_examguard
 * @copyright  2026 Librian Yudhista Pambudi (https://librian.my.id)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_examguard;

defined('MOODLE_INTERNAL') || die();

/**
 * Injects the ExamGuard assets into quiz attempt pages only.
 */
class hook_listener {

    /** @var bool Whether the head HTML was already produced. */
    protected static $headinjected = false;

    /** @var bool Whether the footer HTML was already produced. */
    protected static $footerinjected = false;

    /** @var bool Whether the attempt context was already resolved. */
    protected static $contextchecked = false;

    /** @var \stdClass|null Cached attempt context. */
    protected static $context = null;

    /**
     * Hook callback for core\hook\output\before_standard_head_html_generation.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook
     */
    public static function inject_head(\core\hook\output\before_standard_head_html_generation $hook): void {
        $hook->add_html(self::get_head_html());
    }

    /**
     * Hook callback for core\hook\output\before_footer_html_generation.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function inject_footer(\core\hook\output\before_footer_html_generation $hook): void {
        $hook->add_html(self::get_footer_html());
    }

    /**
     * Return the head HTML for the current page, empty when not applicable.
     *
     * @return string
     */
    public static function get_head_html(): string {
        if (self::$headinjected) {
            return '';
        }
        self::$headinjected = true;

        $context = self::get_attempt_context();
        if ($context === null || $context->remaining <= 0) {
            return '';
        }
        if (!penalty_manager::setting_bool('headlock', true)) {
            return '';
        }

        return self::build_head_lock_html();
    }

    /**
     * Return the footer HTML for the current page, empty when not applicable.
     *
     * @return string
     */
    public static function get_footer_html(): string {
        if (self::$footerinjected) {
            return '';
        }
        self::$footerinjected = true;

        $context = self::get_attempt_context();
        if ($context === null) {
            return '';
        }

        return self::build_footer_html($context);
    }

    /**
     * Resolve and cache the quiz attempt context for the current request.
     *
     * @return \stdClass|null
     */
    protected static function get_attempt_context(): ?\stdClass {
        global $PAGE, $USER, $DB;

        if (self::$contextchecked) {
            return self::$context;
        }
        self::$contextchecked = true;
        self::$context = null;

        if (!penalty_manager::setting_bool('enabled', true)) {
            return null;
        }
        if (CLI_SCRIPT || AJAX_SCRIPT || WS_SERVER) {
            return null;
        }
        $cm = null;
        try {
            $cm = $PAGE->cm;
        } catch (\Throwable $e) {
            return null;
        }
        if (!$cm || $cm->modname !== 'quiz') {
            return null;
        }

        $url = $PAGE->url ? $PAGE->url->out(false) : '';
        if ($PAGE->pagetype !== 'mod-quiz-attempt' && strpos($url, '/mod/quiz/attempt.php') === false) {
            return null;
        }

        try {
            $attemptid = optional_param('attempt', 0, PARAM_INT);
            if (!$attemptid) {
                return null;
            }

            $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
            if (!$attempt || (int) $attempt->userid !== (int) $USER->id) {
                return null;
            }
            if ((string) $attempt->state !== 'inprogress') {
                return null;
            }
            if ((int) $attempt->quiz !== (int) $PAGE->cm->instance) {
                return null;
            }

            $preview = !empty($attempt->preview);
            if ($preview && !penalty_manager::setting_bool('applytoteachers', false)) {
                return null;
            }

            $context = \context_module::instance($PAGE->cm->id);
            if (!$preview && !has_capability('mod/quiz:attempt', $context)) {
                return null;
            }

            // Per-quiz guard: abort if ExamGuard is not enabled for this specific quiz.
            $quizcfg = penalty_manager::get_quiz_config((int) $PAGE->cm->instance);
            if ($quizcfg === null) {
                return null;
            }

            $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);
            $state = penalty_manager::get_state($attemptid, (int) $USER->id);
            $now = time();

            self::$context = (object) [
                'attempt'  => $attempt,
                'quiz'     => $quiz,
                'cmid'     => (int) $PAGE->cm->id,
                'state'    => $state,
                'remaining'=> penalty_manager::get_remaining($state, $now),
                'timeleft' => penalty_manager::get_time_left((int) $PAGE->cm->id, $attempt, $quiz),
                'timenow'  => $now,
                'quizcfg'  => $quizcfg,
            ];
        } catch (\Throwable $e) {
            debugging('local_examguard: ' . $e->getMessage(), DEBUG_DEVELOPER);
            self::$context = null;
        }

        return self::$context;
    }

    /**
     * Build the anti-flash lock CSS and bootstrap script.
     *
     * @return string
     */
    protected static function build_head_lock_html(): string {
        $css = 'html.examguard-lock #page,html.examguard-lock #page-footer{visibility:hidden !important}'
            . 'html.examguard-lock body{background:#fff !important}';

        $bootstrap = "(function(){"
            . "var d=document.documentElement;"
            . "d.className+=' examguard-lock';"
            . "window.__examguardFailsafe=window.setTimeout(function(){"
            . "if(!window.__examguardReady){"
            . "d.className=d.className.replace(/(^|\\s)examguard-lock(\\s|$)/g,' ');"
            . "}},10000);"
            . "})();";

        return \html_writer::tag('style', $css, ['id' => 'examguard-lock-css'])
            . \html_writer::tag('script', $bootstrap, ['id' => 'examguard-bootstrap']);
    }

    /**
     * Build the configuration JSON, the root container and the script tag.
     *
     * @param \stdClass $context attempt context.
     * @return string
     */
    protected static function build_footer_html(\stdClass $context): string {
        $config = self::build_js_config($context);
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $jsurl = new \moodle_url('/local/examguard/js/guard.js', ['v' => self::plugin_version()]);

        return \html_writer::tag('script', $json, ['type' => 'application/json', 'id' => 'examguard-config'])
            . \html_writer::tag('div', '', ['id' => 'examguard-root', 'aria-hidden' => 'true'])
            . \html_writer::tag('script', '', [
                'src' => $jsurl->out(false),
                'defer' => 'defer',
                'id' => 'examguard-js',
            ]);
    }

    /**
     * Current installed version of the plugin, used to bust the browser cache.
     *
     * @return string
     */
    protected static function plugin_version(): string {
        $version = get_config('local_examguard', 'version');
        if ($version === false || $version === '') {
            $info = \core_plugin_manager::instance()->get_plugin_info('local_examguard');
            $version = $info ? $info->versiondb : 0;
        }
        return (string) $version;
    }

    /**
     * Build the JSON configuration handed to js/guard.js.
     *
     * @param \stdClass $context attempt context.
     * @return array
     */
    protected static function build_js_config(\stdClass $context): array {
        global $USER;

        $attempt = $context->attempt;
        $quiz    = $context->quiz;
        $state   = $context->state;
        $quizcfg = $context->quizcfg;

        $identity = (object) [
            'fullname' => fullname($USER),
            'username' => $USER->username,
            'ip'       => getremoteaddr(),
        ];

        $quizlang = lang_helper::resolve_lang($quizcfg->lang ?? null);

        $strings = [
            'penaltyheading'      => lang_helper::get_string('js_penaltyheading', $quizlang),
            'penaltybody'         => lang_helper::get_string('js_penaltybody', $quizlang),
            'penaltycount'        => lang_helper::get_string('js_penaltycount', $quizlang),
            'penaltyremaining'    => lang_helper::get_string('js_penaltyremaining', $quizlang),
            'penaltyidentity'     => lang_helper::get_string('js_penaltyidentity', $quizlang, $identity),
            'penaltynotice'       => lang_helper::get_string('js_penaltynotice', $quizlang),
            'penaltytamper'       => lang_helper::get_string('js_penaltytamper', $quizlang),
            'penaltychecking'     => lang_helper::get_string('js_penaltychecking', $quizlang),
            'ajaxerror'           => lang_helper::get_string('js_ajaxerror', $quizlang),
            'printblocked'        => lang_helper::get_string('js_printblocked', $quizlang),
            'domswap_placeholder' => lang_helper::get_string('domswap_placeholder', $quizlang),
        ];

        $traptext = get_config('local_examguard', 'traptext');
        if ($traptext === false || trim((string) $traptext) === '') {
            $traptext = get_string('defaulttraptext', 'local_examguard');
        }

        // Per-quiz values override the global defaults.
        $penaltyseconds = max(1, (int) $quizcfg->penaltyseconds);
        $freezetimermax = (int) $quizcfg->freezetimermax;
        $watermark      = ((int) ($quizcfg->watermark ?? 1) === 1);
        // Freeze timer toggle remains a global setting; the per-quiz
        // freezetimermax (0 = unlimited) controls the cap, not the toggle.
        $freezetimer = penalty_manager::setting_bool('freezetimer', true);

        $now = (int) $context->timenow;
        $remaining = (int) $context->remaining;
        $isactive = ($remaining > 0 && $state && (int) $state->penaltyuntil > $now);
        $penaltyuntil = $isactive ? (int) $state->penaltyuntil : 0;

        return [
            'ajaxurl'        => (new \moodle_url('/local/examguard/ajax.php'))->out(false),
            'sesskey'        => sesskey(),
            'attemptid'      => (int) $attempt->id,
            'cmid'           => (int) $context->cmid,
            'quizid'         => (int) $quiz->id,
            'courseid'       => (int) $quiz->course,
            'lang'           => $quizlang,
            'user'           => $identity,
            'servernow'      => $now,
            'penaltyuntil'   => $penaltyuntil,
            'remaining'      => $remaining,
            'violationcount' => $state ? (int) $state->violationcount : 0,
            'frozenseconds'  => $state ? (int) $state->frozenseconds : 0,
            'timelimit'      => (int) $quiz->timelimit,
            'timeleft'       => $context->timeleft,
            'watermark'      => $watermark,
            'settings'       => [
                'lang'             => $quizlang,
                'penaltyseconds'   => $penaltyseconds,
                'dedupewindow'     => max(0, penalty_manager::setting_int('dedupewindow', 3)),
                'trapenabled'      => penalty_manager::setting_bool('trapenabled', true),
                'traptext'         => (string) $traptext,
                'zwspenabled'      => penalty_manager::setting_bool('zwspenabled', true),
                'blockcopy'        => penalty_manager::setting_bool('blockcopy', true),
                'blockdevtools'    => penalty_manager::setting_bool('blockdevtools', false),
                'watermark'        => $watermark,
                'watermarkenabled' => $watermark,
                'watermarkopacity' => max(0.02, min(0.6,
                    penalty_manager::setting_float('watermarkopacity', 0.12))),
                'freezetimer'      => $freezetimer,
                'freezetimermax'   => $freezetimermax,
            ],
            'strings'        => $strings,
        ];
    }
}

