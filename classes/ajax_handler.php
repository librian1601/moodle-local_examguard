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
 * JSON endpoint handler for local_examguard.
 *
 * The browser never sends the penalty deadline. It only reports that an event
 * happened, everything else is calculated here from the server clock and the
 * database, so the client cannot shorten or skip a penalty.
 *
 * @package    local_examguard
 * @copyright  2026 Librian Yudhista Pambudi (https://librian.my.id)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_examguard;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles violation, heartbeat, status and pageload requests.
 */
class ajax_handler {

    /**
     * Entry point called from ajax.php.
     */
    public static function handle(): void {
        global $DB, $USER;

        $defaultlang = lang_helper::resolve_lang(null);
        $quizlang = $defaultlang;

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                self::respond(405, self::error('wrongmethod', lang_helper::get_string('errorwrongmethod', $defaultlang)));
            }

            if (!isloggedin() || isguestuser()) {
                self::respond(403, self::error('requireslogin', lang_helper::get_string('errornopermission', $defaultlang)));
            }

            $sesskey = optional_param('sesskey', '', PARAM_RAW);
            if ($sesskey === '' || !confirm_sesskey($sesskey)) {
                self::respond(403, self::error('invalidsesskey', lang_helper::get_string('errornopermission', $defaultlang)));
            }

            $action = optional_param('action', '', PARAM_ALPHA);
            $attemptid = optional_param('attemptid', 0, PARAM_INT);
            $cmid = optional_param('cmid', 0, PARAM_INT);

            if ($attemptid <= 0 || $action === '') {
                throw new \moodle_exception('errorinvalidattempt', 'local_examguard');
            }

            $mustbeinprogress = ($action !== 'heartbeat' && $action !== 'status');
            list($attempt, $quiz, $cm) = self::load_attempt($attemptid, $cmid, $mustbeinprogress);

            $quizcfg = penalty_manager::get_quiz_config((int) $quiz->id);
            $quizlang = lang_helper::resolve_lang($quizcfg->lang ?? null);

            $now = time();
            $state = penalty_manager::get_state($attemptid, (int) $USER->id);

            switch ($action) {
                case 'violation':
                    $eventtype = optional_param('eventtype', penalty_manager::EVENT_TABSWITCH, PARAM_ALPHANUMEXT);
                    $meta = [];
                    $rawmeta = optional_param('meta', '', PARAM_RAW);
                    if ($rawmeta !== '') {
                        $decoded = json_decode($rawmeta, true);
                        if (is_array($decoded)) {
                            $meta = $decoded;
                        }
                    }

                    $result = penalty_manager::record_violation($attempt, $eventtype, ['client' => $meta]);

                    // Re-read the attempt because the quiz timer may have been frozen.
                    $fresh = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
                    if ($fresh) {
                        $attempt = $fresh;
                    }
                    $state = penalty_manager::get_state($attemptid, (int) $USER->id);
                    $timeleft = penalty_manager::get_time_left((int) $cm->id, $attempt, $quiz);

                    self::respond(200, self::payload('violation', $state, time(), $result->deduped,
                        $timeleft, $quiz, false, !empty($result->ignored_while_locked)));
                    break;

                case 'pageload':
                    $remaining = penalty_manager::get_remaining($state, $now);
                    penalty_manager::log_passive_event($attempt, penalty_manager::EVENT_PAGELOAD, [
                        'locked' => $remaining > 0 ? 1 : 0,
                    ]);
                    $timeleft = penalty_manager::get_time_left((int) $cm->id, $attempt, $quiz);
                    self::respond(200, self::payload('pageload', $state, $now, false, $timeleft, $quiz, false));
                    break;

                case 'devtools':
                    penalty_manager::log_passive_event($attempt, penalty_manager::EVENT_DEVTOOLS, []);
                    $timeleft = penalty_manager::get_time_left((int) $cm->id, $attempt, $quiz);
                    self::respond(200, self::payload('devtools', $state, $now, false, $timeleft, $quiz, false));
                    break;

                case 'heartbeat':
                case 'status':
                    $finished = ((string) $attempt->state !== 'inprogress');
                    $timeleft = $finished ? null : penalty_manager::get_time_left((int) $cm->id, $attempt, $quiz);
                    self::respond(200, self::payload($action, $state, $now, false, $timeleft, $quiz, $finished));
                    break;

                default:
                    self::respond(400, self::error('errorunknown', lang_helper::get_string('errorunknown', $quizlang)));
            }
        } catch (\require_login_exception $e) {
            self::respond(403, self::error('requireslogin', lang_helper::get_string('errornopermission', $quizlang ?? $defaultlang)));
        } catch (\required_capability_exception $e) {
            self::respond(403, self::error('nopermission', lang_helper::get_string('errornopermission', $quizlang ?? $defaultlang)));
        } catch (\moodle_exception $e) {
            $qlang = $quizlang ?? $defaultlang;
            $message = lang_helper::get_string('errorinvalidattempt', $qlang);
            $code = 400;
            if ($e->errorcode === 'errorattemptfinished') {
                $message = lang_helper::get_string('errorattemptfinished', $qlang);
                $code = 409;
            } else if ($e->errorcode === 'nopermission') {
                $message = lang_helper::get_string('errornopermission', $qlang);
                $code = 403;
            }
            self::respond($code, self::error($e->errorcode ?: 'errorunknown', $message));
        } catch (\Throwable $e) {
            debugging('local_examguard ajax error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            self::respond(500, self::error('internalerror', lang_helper::get_string('errorunknown', $quizlang ?? $defaultlang)));
        }
    }

    /**
     * Validate the attempt, the course module and the capability of the current user.
     *
     * @param int $attemptid quiz attempt id.
     * @param int $cmid expected course module id, 0 to skip the check.
     * @param bool $mustbeinprogress whether the attempt must still be in progress.
     * @return array [attempt, quiz, cm]
     */
    protected static function load_attempt(int $attemptid, int $cmid, bool $mustbeinprogress): array {
        global $DB, $USER;

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
        if (!$attempt || (int) $attempt->userid !== (int) $USER->id) {
            throw new \moodle_exception('errorinvalidattempt', 'local_examguard');
        }

        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz]);
        if (!$quiz) {
            throw new \moodle_exception('errorinvalidattempt', 'local_examguard');
        }

        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, IGNORE_MISSING);
        if (!$cm || ($cmid && (int) $cm->id !== $cmid)) {
            throw new \moodle_exception('errorinvalidattempt', 'local_examguard');
        }

        $course = $DB->get_record('course', ['id' => $quiz->course], '*', MUST_EXIST);

        // Enrolment and session are validated by core, this throws on failure.
        require_login($course, false, $cm);

        $context = \context_module::instance($cm->id);
        if (!empty($attempt->preview)) {
            require_capability('mod/quiz:preview', $context);
        } else {
            require_capability('mod/quiz:attempt', $context);
        }

        if ($mustbeinprogress && (string) $attempt->state !== 'inprogress') {
            throw new \moodle_exception('errorattemptfinished', 'local_examguard');
        }

        return [$attempt, $quiz, $cm];
    }

    /**
     * Build the JSON payload returned to the browser.
     *
     * @param string $action action name.
     * @param \stdClass|null $state penalty state row.
     * @param int $now server time.
     * @param bool $deduped whether the event was ignored as duplicate.
     * @param int|null $timeleft server side quiz time left.
     * @param \stdClass $quiz quiz row.
     * @param bool $finished whether the attempt is no longer in progress.
     * @return array
     */
    protected static function payload(string $action, ?\stdClass $state, int $now, bool $deduped,
            ?int $timeleft, \stdClass $quiz, bool $finished, bool $ignoredwhilelocked = false): array {
        $penaltyuntil = $state ? (int) $state->penaltyuntil : 0;
        $remaining = ($state && !$finished) ? max(0, $penaltyuntil - $now) : 0;
        $activeuntil = ($remaining > 0) ? $penaltyuntil : 0;

        return [
            'status' => 'ok',
            'action' => $action,
            'violationcount' => $state ? (int) $state->violationcount : 0,
            'penaltyuntil' => $activeuntil,
            'remaining' => $remaining,
            'frozenseconds' => $state ? (int) $state->frozenseconds : 0,
            'servernow' => $now,
            'deduped' => $deduped,
            'ignored_while_locked' => $ignoredwhilelocked,
            'timelimit' => (int) $quiz->timelimit,
            'timeleft' => $timeleft,
            'locked' => $remaining > 0,
        ];
    }

    /**
     * Build an error payload.
     *
     * @param string $code machine readable error code.
     * @param string $message human readable message.
     * @return array
     */
    protected static function error(string $code, string $message): array {
        return [
            'status' => 'error',
            'errorcode' => $code,
            'message' => $message,
        ];
    }

    /**
     * Send a JSON response and stop the request.
     *
     * @param int $status HTTP status code.
     * @param array $payload response payload.
     */
    protected static function respond(int $status, array $payload): void {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
            http_response_code($status);
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        die();
    }
}
