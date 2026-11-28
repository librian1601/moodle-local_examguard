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
 * Server side penalty timer and violation logging for local_examguard.
 *
 * All timing decisions are made here using the server clock and the database.
 * The browser only ever receives the result, it never decides when a penalty
 * ends, so refreshing the page, clearing the browser storage or changing the
 * device clock cannot remove a penalty.
 *
 * @package    local_examguard
 * @copyright  2026 Librian Yudhista Pambudi (https://librian.my.id)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_examguard;

defined('MOODLE_INTERNAL') || die();

/**
 * Penalty timer and violation log manager.
 */
class penalty_manager {

    /** @var string Event type: tab, application or window switch. */
    const EVENT_TABSWITCH = 'tabswitch';

    /** @var string Event type: browser window lost focus. */
    const EVENT_WINDOWBLUR = 'windowblur';

    /** @var string Event type: attempt page loaded while a penalty was active. */
    const EVENT_PAGELOAD = 'pageload';

    /** @var string Event type: penalty overlay was removed or altered. */
    const EVENT_OVERLAYREMOVED = 'overlayremoved';

    /** @var string Event type: developer tools shortcut detected. */
    const EVENT_DEVTOOLS = 'devtools';

    /** @var string Event type: a teacher removed the penalty manually. */
    const EVENT_MANUALUNLOCK = 'manualunlock';

    /** @var string Event type: a teacher/proctor reset an active penalty while keeping audit history. */
    const EVENT_MANUALRESET = 'manualreset';

    /** @var int Default penalty duration in seconds. */
    const DEFAULT_PENALTYSECONDS = 120;

    /** @var int Default hard cap for maximum active penalty screen duration in seconds. */
    const DEFAULT_MAXPENALTYSECONDS = 600;

    /** @var int Default duplicate event window in seconds. */
    const DEFAULT_DEDUPEWINDOW = 3;

    /** @var int Default maximum frozen quiz seconds per attempt. */
    const DEFAULT_FREEZEMAX = 600;

    /** @var int Default log retention in days. */
    const DEFAULT_RETENTIONDAYS = 90;

    /**
     * Read an integer setting with a default that also works before the settings page was saved.
     *
     * @param string $name setting name without the component prefix.
     * @param int $default fallback value.
     * @return int
     */
    public static function setting_int(string $name, int $default): int {
        $value = get_config('local_examguard', $name);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (int) $value;
    }

    /**
     * Read a boolean setting with a default that also works before the settings page was saved.
     *
     * @param string $name setting name without the component prefix.
     * @param bool $default fallback value.
     * @return bool
     */
    public static function setting_bool(string $name, bool $default): bool {
        $value = get_config('local_examguard', $name);
        if ($value === false || $value === null) {
            return $default;
        }
        return (bool) (int) $value;
    }

    /**
     * Read a float setting with a default that also works before the settings page was saved.
     *
     * @param string $name setting name without the component prefix.
     * @param float $default fallback value.
     * @return float
     */
    public static function setting_float(string $name, float $default): float {
        $value = get_config('local_examguard', $name);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (float) $value;
    }

    /**
     * Return the per-quiz ExamGuard configuration row.
     *
     * Returns null when the quiz has no row in local_examguard_quiz or when
     * enabled is 0, which is the signal to skip all ExamGuard activity for
     * that quiz entirely.
     *
     * @param int $quizid quiz id.
     * @return \stdClass|null configuration row, or null when ExamGuard is inactive.
     */
    public static function get_quiz_config(int $quizid): ?\stdClass {
        global $DB;

        if ($quizid <= 0) {
            return null;
        }

        $cfg = $DB->get_record('local_examguard_quiz', ['quizid' => $quizid]);
        if (!$cfg || !(int) $cfg->enabled) {
            return null;
        }

        return $cfg;
    }

    /**
     * Determine whether the ExamGuard tab should appear in a quiz's secondary navigation.
     *
     * Shown if ExamGuard is enabled for the quiz OR if historical logs/penalties exist.
     *
     * @param int $quizid quiz id.
     * @return bool
     */
    public static function should_show_quiz_tab(int $quizid): bool {
        global $DB;

        if ($quizid <= 0) {
            return false;
        }

        $cfg = $DB->get_record('local_examguard_quiz', ['quizid' => $quizid]);
        if ($cfg && (int) $cfg->enabled === 1) {
            return true;
        }
        if ($DB->record_exists('local_examguard_logs', ['quizid' => $quizid])) {
            return true;
        }
        if ($DB->record_exists('local_examguard_penalty', ['quizid' => $quizid])) {
            return true;
        }

        return false;
    }

    /**
     * Check if an active penalty exists for the given attempt.
     *
     * @param int $attemptid quiz attempt id.
     * @param int $userid user id that owns the attempt.
     * @param int|null $timenow server time to use, defaults to time().
     * @return bool true if penalty is currently active.
     */
    public static function is_penalty_active(int $attemptid, int $userid, ?int $timenow = null): bool {
        $state = self::get_state($attemptid, $userid);
        return self::get_remaining($state, $timenow) > 0;
    }

    /**
     * Return the penalty state row for an attempt, if any.
     *
     * @param int $attemptid quiz attempt id.
     * @param int $userid user id that owns the attempt.
     * @return \stdClass|null
     */
    public static function get_state(int $attemptid, int $userid): ?\stdClass {
        global $DB;

        $state = $DB->get_record('local_examguard_penalty', [
            'attemptid' => $attemptid,
            'userid' => $userid,
        ]);

        return $state ?: null;
    }

    /**
     * Remaining penalty seconds for a state row.
     *
     * @param \stdClass|null $state penalty state row.
     * @param int|null $timenow server time to use, defaults to time().
     * @return int
     */
    public static function get_remaining(?\stdClass $state, ?int $timenow = null): int {
        if (!$state) {
            return 0;
        }
        if ($timenow === null) {
            $timenow = time();
        }
        return max(0, (int) $state->penaltyuntil - $timenow);
    }

    /**
     * Server side remaining quiz time for a timed quiz.
     *
     * Uses the mod_quiz access manager when available so time close dates and
     * user overrides are honoured, and falls back to the plain time limit
     * calculation used by the timelimit access rule.
     *
     * @param int $cmid course module id of the quiz.
     * @param \stdClass $attempt quiz_attempts row.
     * @param \stdClass $quiz quiz row.
     * @return int|null remaining seconds, or null when the quiz has no timer.
     */
    public static function get_time_left(int $cmid, \stdClass $attempt, \stdClass $quiz): ?int {
        if (empty($quiz->timelimit)) {
            return null;
        }

        $timenow = time();

        if (class_exists('\mod_quiz\quiz_settings')) {
            try {
                $quizobj = \mod_quiz\quiz_settings::create_for_cmid($cmid, (int) $attempt->userid);
                $timeleft = $quizobj->get_access_manager($timenow)->get_time_left_display($attempt, $timenow);
                if ($timeleft === false) {
                    return null;
                }
                return max(0, (int) $timeleft);
            } catch (\Throwable $e) {
                // Fall through to the plain calculation below.
                debugging('local_examguard: access manager unavailable: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        $end = (int) $attempt->timestart + (int) $quiz->timelimit;
        if (!empty($quiz->timeclose)) {
            $end = min($end, (int) $quiz->timeclose);
        }

        return max(0, $end - $timenow);
    }

    /**
     * Normalise an incoming event type to one of the supported values.
     *
     * @param string $eventtype raw event type.
     * @return string
     */
    protected static function normalise_event_type(string $eventtype): string {
        $eventtype = strtolower(clean_param($eventtype, PARAM_ALPHANUMEXT));
        $allowed = [
            self::EVENT_TABSWITCH,
            self::EVENT_WINDOWBLUR,
            self::EVENT_PAGELOAD,
            self::EVENT_OVERLAYREMOVED,
            self::EVENT_DEVTOOLS,
            self::EVENT_MANUALUNLOCK,
            self::EVENT_MANUALRESET,
        ];

        return in_array($eventtype, $allowed, true) ? $eventtype : self::EVENT_TABSWITCH;
    }

    /**
     * Record a violation, extend the penalty and freeze quiz time when configured.
     *
     * @param \stdClass $attempt quiz_attempts row.
     * @param string $eventtype event type.
     * @param array $extra additional client meta data.
     * @param bool $retry internal flag used once when a concurrent request inserted the state row first.
     * @return \stdClass result containing violationcount, penaltyuntil, remaining, servernow and deduped.
     */
    public static function record_violation(\stdClass $attempt, string $eventtype, array $extra = [],
            bool $retry = false): \stdClass {
        try {
            return self::do_record_violation($attempt, $eventtype, $extra);
        } catch (\dml_write_exception $e) {
            if ($retry) {
                throw $e;
            }
            // Another request created the state row for this attempt at the same time, retry once.
            return self::record_violation($attempt, $eventtype, $extra, true);
        }
    }

    /**
     * Perform the actual violation write inside a single transaction.
     *
     * @param \stdClass $attempt quiz_attempts row.
     * @param string $eventtype event type.
     * @param array $extra additional client meta data.
     * @return \stdClass
     */
    protected static function do_record_violation(\stdClass $attempt, string $eventtype, array $extra): \stdClass {
        global $DB;

        $now = time();
        $attemptid = (int) $attempt->id;
        $userid = (int) $attempt->userid;
        $eventtype = self::normalise_event_type($eventtype);

        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz]);
        if (!$quiz) {
            throw new \moodle_exception('errorinvalidattempt', 'local_examguard');
        }

        // Resolve per-quiz config; abort silently if ExamGuard is inactive for this quiz.
        $quizcfg = self::get_quiz_config((int) $quiz->id);
        if ($quizcfg === null) {
            // Return a neutral result without recording anything.
            $nullstate = (object) [
                'violationcount' => 0,
                'penaltyuntil'   => 0,
                'frozenseconds'  => 0,
                'lastpenalty'    => 0,
            ];
            return self::build_result($nullstate, $now, true);
        }

        $penaltyseconds = max(1, (int) $quizcfg->penaltyseconds);
        $maxpenalty = max(
            $penaltyseconds,
            (int) ($quizcfg->maxpenaltyseconds ?? self::setting_int('maxpenaltyseconds', self::DEFAULT_MAXPENALTYSECONDS))
        );
        $dedupewindow = max(0, self::setting_int('dedupewindow', self::DEFAULT_DEDUPEWINDOW));

        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, IGNORE_MISSING);
        $cmid = $cm ? (int) $cm->id : 0;

        $state = self::get_state($attemptid, $userid);

        // LOCK-PAUSE RULE: While a penalty is actively locking the screen, ignore normal
        // focus/tab violations so idle tabs or background tabs cannot snowball the lock.
        // Only DevTools overlay tampering ('overlayremoved') is allowed to extend while locked.
        if ($state && (int) $state->penaltyuntil > $now) {
            if ($eventtype !== self::EVENT_OVERLAYREMOVED) {
                return self::build_result($state, $now, true, true);
            }
            if (($now - (int) $state->lastpenalty) <= $dedupewindow) {
                return self::build_result($state, $now, true, false);
            }
        }

        $violationcount = ($state ? (int) $state->violationcount : 0) + 1;
        $olduntil = $state ? (int) $state->penaltyuntil : 0;
        $baseuntil = max($now, $olduntil);
        $rawnewuntil = $baseuntil + $penaltyseconds;

        // HARD CAP MAX PENALTY SCREEN: Penalty deadline may never exceed now + maxpenaltyseconds.
        $maxuntil = $now + $maxpenalty;
        $penaltyuntil = min($rawnewuntil, $maxuntil);
        $actualadded = max(0, $penaltyuntil - $baseuntil);

        $frozenseconds = $state ? (int) $state->frozenseconds : 0;

        $granted = 0;
        // Use per-quiz freezetimermax (0 = unlimited) synced with actualAdded penalty time.
        $maxfrozen = (int) $quizcfg->freezetimermax;
        if ($actualadded > 0 && !empty($quiz->timelimit) && self::setting_bool('freezetimer', true)
                && (string) $attempt->state === 'inprogress') {
            $room = ($maxfrozen > 0) ? max(0, $maxfrozen - $frozenseconds) : $actualadded;
            $granted = (int) min($actualadded, $room);
            $frozenseconds += $granted;
        }

        $extra = self::clean_extra($extra);
        $extra['actual_added'] = $actualadded;
        $extra['capped'] = ($rawnewuntil > $maxuntil) ? 1 : 0;
        $extra['frozen_granted'] = $granted;
        $extra['frozen_total'] = $frozenseconds;

        $transaction = $DB->start_delegated_transaction();

        if ($granted > 0) {
            // The quiz timer is computed as timestart + timelimit, so shifting timestart freezes it.
            $DB->execute("UPDATE {quiz_attempts}
                             SET timestart = timestart + :grant
                           WHERE id = :attemptid AND state = :attemptstate",
                ['grant' => $granted, 'attemptid' => $attemptid, 'attemptstate' => 'inprogress']);
        }

        self::insert_log($attempt, $quiz, $cmid, $eventtype, $violationcount, $penaltyuntil, $extra, $now);

        if ($state) {
            $state->violationcount = $violationcount;
            $state->penaltyuntil = $penaltyuntil;
            $state->frozenseconds = $frozenseconds;
            $state->lastpenalty = $now;
            $state->timemodified = $now;
            $DB->update_record('local_examguard_penalty', $state);
        } else {
            $state = (object) [
                'userid' => $userid,
                'courseid' => (int) $quiz->course,
                'cmid' => $cmid,
                'quizid' => (int) $quiz->id,
                'attemptid' => $attemptid,
                'violationcount' => $violationcount,
                'penaltyuntil' => $penaltyuntil,
                'frozenseconds' => $frozenseconds,
                'firstpenalty' => $now,
                'lastpenalty' => $now,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $state->id = $DB->insert_record('local_examguard_penalty', $state);
        }

        $DB->commit_delegated_transaction($transaction);

        self::write_preference($attemptid, $userid, $state);

        return self::build_result($state, $now, false);
    }

    /**
     * Insert one row into the violation log table.
     *
     * @param \stdClass $attempt quiz_attempts row.
     * @param \stdClass $quiz quiz row.
     * @param int $cmid course module id.
     * @param string $eventtype normalised event type.
     * @param int $violationcount cumulative violation count.
     * @param int $penaltyuntil penalty end timestamp.
     * @param array $extra extra meta data.
     * @param int $now server time.
     * @return int new log id.
     */
    protected static function insert_log(\stdClass $attempt, \stdClass $quiz, int $cmid, string $eventtype,
            int $violationcount, int $penaltyuntil, array $extra, int $now): int {
        global $DB;

        $useragent = '';
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            $useragent = \core_text::substr(clean_param($_SERVER['HTTP_USER_AGENT'], PARAM_TEXT), 0, 512);
        }

        $log = (object) [
            'userid' => (int) $attempt->userid,
            'courseid' => (int) $quiz->course,
            'cmid' => $cmid,
            'quizid' => (int) $quiz->id,
            'attemptid' => (int) $attempt->id,
            'eventtype' => $eventtype,
            'violationcount' => $violationcount,
            'penaltyuntil' => $penaltyuntil,
            'ipaddress' => getremoteaddr(),
            'useragent' => $useragent,
            'extra' => json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timecreated' => $now,
        ];

        return (int) $DB->insert_record('local_examguard_logs', $log);
    }

    /**
     * Sanitise the extra meta data before it is stored as JSON.
     *
     * @param array $extra raw meta data.
     * @return array
     */
    protected static function clean_extra(array $extra): array {
        $clean = [];
        foreach ($extra as $key => $value) {
            $key = clean_param((string) $key, PARAM_ALPHANUMEXT);
            if ($key === '') {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            if ($value !== null && !is_scalar($value)) {
                continue;
            }
            $clean[$key] = is_string($value) ? \core_text::substr($value, 0, 255) : $value;
        }
        return $clean;
    }

    /**
     * Build the JSON friendly result object for the browser.
     *
     * @param \stdClass $state penalty state row.
     * @param int $now server time.
     * @param bool $deduped whether the event was ignored as a duplicate.
     * @param bool $ignoredwhilelocked whether the event was ignored because a penalty is already active.
     * @return \stdClass
     */
    protected static function build_result(\stdClass $state, int $now, bool $deduped,
            bool $ignoredwhilelocked = false): \stdClass {
        $remaining = max(0, (int) $state->penaltyuntil - $now);
        return (object) [
            'violationcount'       => (int) $state->violationcount,
            'penaltyuntil'         => ($remaining > 0) ? (int) $state->penaltyuntil : 0,
            'remaining'            => $remaining,
            'frozenseconds'        => (int) $state->frozenseconds,
            'servernow'            => $now,
            'deduped'              => $deduped,
            'ignored_while_locked' => $ignoredwhilelocked,
        ];
    }

    /**
     * Log an event that must not extend the penalty, such as an attempt page
     * reload while the penalty is already active.
     *
     * @param \stdClass $attempt quiz_attempts row.
     * @param string $eventtype one of pageload or devtools.
     * @param array $extra extra meta data.
     * @return bool true when a log row was created.
     */
    public static function log_passive_event(\stdClass $attempt, string $eventtype, array $extra = []): bool {
        global $DB;

        $eventtype = self::normalise_event_type($eventtype);
        if (!in_array($eventtype, [self::EVENT_PAGELOAD, self::EVENT_DEVTOOLS], true)) {
            return false;
        }

        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz]);
        if (!$quiz) {
            return false;
        }

        $now = time();
        $last = $DB->get_field_sql("SELECT MAX(timecreated)
                                      FROM {local_examguard_logs}
                                     WHERE attemptid = :attemptid AND eventtype = :eventtype",
            ['attemptid' => (int) $attempt->id, 'eventtype' => $eventtype]);

        // Avoid flooding the log when a student keeps reloading the page.
        if ($last && ($now - (int) $last) < 30) {
            return false;
        }

        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, IGNORE_MISSING);
        $state = self::get_state((int) $attempt->id, (int) $attempt->userid);

        self::insert_log($attempt, $quiz, $cm ? (int) $cm->id : 0, $eventtype,
            $state ? (int) $state->violationcount : 0,
            $state ? (int) $state->penaltyuntil : 0,
            self::clean_extra($extra), $now);

        return true;
    }

    /**
     * Fetch all currently locked attempts (penaltyuntil > time()) with user and quiz info.
     *
     * Supports filtering by courseid, quizid and case-insensitive search against
     * full name, username, email or idnumber.
     *
     * @param int $courseid optional course filter (0 = all).
     * @param int $quizid optional quiz filter (0 = all).
     * @param string $search optional text search.
     * @return array list of active lock records.
     */
    public static function get_active_locked_penalties(int $courseid = 0, int $quizid = 0, string $search = ''): array {
        global $DB;

        $now = time();
        $where = 'p.penaltyuntil > :now';
        $params = ['now' => $now];

        if ($courseid > 0) {
            $where .= ' AND p.courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($quizid > 0) {
            $where .= ' AND p.quizid = :quizid';
            $params['quizid'] = $quizid;
        }
        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $DB->sql_like_escape($search) . '%';
            $where .= ' AND ('
                . $DB->sql_like($DB->sql_fullname('u.firstname', 'u.lastname'), ':qfullname', false)
                . ' OR ' . $DB->sql_like('u.username', ':qusername', false)
                . ' OR ' . $DB->sql_like('u.email', ':qemail', false)
                . ' OR ' . $DB->sql_like('u.idnumber', ':qidnumber', false)
                . ')';
            $params['qfullname'] = $like;
            $params['qusername'] = $like;
            $params['qemail'] = $like;
            $params['qidnumber'] = $like;
        }

        $sql = "SELECT p.id, p.attemptid, p.userid, p.courseid, p.cmid, p.quizid,
                       p.violationcount, p.penaltyuntil, p.frozenseconds, p.lastpenalty,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.username, u.email, u.idnumber,
                       q.name AS quizname, c.fullname AS coursename
                  FROM {local_examguard_penalty} p
                  JOIN {user} u ON u.id = p.userid
                  JOIN {quiz} q ON q.id = p.quizid
                  JOIN {course} c ON c.id = p.courseid
                 WHERE $where
              ORDER BY p.penaltyuntil DESC, u.lastname ASC, u.firstname ASC";

        $records = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($records as $record) {
            $record->remaining = max(0, (int) $record->penaltyuntil - $now);
            $result[] = $record;
        }

        return $result;
    }

    /**
     * Reset an active penalty for an attempt while preserving violationcount, frozenseconds and historical logs.
     *
     * Sets penaltyuntil = 0, removes the user_preferences mirror, and appends one
     * 'manualreset' audit log row with the preserved historical violationcount.
     *
     * @param int $attemptid quiz attempt id.
     * @param int $userid student user id.
     * @param int $actorid proctor/teacher user id performing the reset.
     * @return bool true if a penalty row was reset.
     */
    public static function reset_attempt_penalty(int $attemptid, int $userid, int $actorid): bool {
        global $DB;

        if ($attemptid <= 0 || $userid <= 0) {
            return false;
        }

        $now = time();
        $state = self::get_state($attemptid, $userid);
        if (!$state || (int) $state->penaltyuntil <= $now) {
            return false;
        }

        $transaction = $DB->start_delegated_transaction();

        // Preserve violationcount and frozenseconds untouched for audit history.
        $state->penaltyuntil = 0;
        $state->timemodified = $now;
        $DB->update_record('local_examguard_penalty', $state);

        $resetlog = (object) [
            'userid' => (int) $state->userid,
            'courseid' => (int) $state->courseid,
            'cmid' => (int) $state->cmid,
            'quizid' => (int) $state->quizid,
            'attemptid' => (int) $state->attemptid,
            'eventtype' => self::EVENT_MANUALRESET,
            'violationcount' => (int) $state->violationcount,
            'penaltyuntil' => 0,
            'ipaddress' => getremoteaddr(),
            'useragent' => '',
            'extra' => json_encode([
                'actorid' => $actorid,
                'action' => 'manualreset',
            ], JSON_UNESCAPED_SLASHES),
            'timecreated' => $now,
        ];
        $DB->insert_record('local_examguard_logs', $resetlog);

        $DB->commit_delegated_transaction($transaction);

        // Remove the user_preferences mirror for this attempt.
        $prefname = 'local_examguard_penalty_' . $attemptid;
        try {
            unset_user_preference($prefname, $userid);
        } catch (\Throwable $e) {
            debugging('local_examguard: could not unset penalty preference: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        $DB->delete_records('user_preferences', ['userid' => $userid, 'name' => $prefname]);

        return true;
    }

    /**
     * Reset all currently active penalties matching the optional courseid/quizid filter.
     *
     * @param int $courseid optional course filter (0 = all).
     * @param int $quizid optional quiz filter (0 = all).
     * @param int $actorid proctor/teacher user id performing the reset.
     * @return int number of attempts reset.
     */
    public static function reset_all_active_penalties(int $courseid = 0, int $quizid = 0, int $actorid = 0): int {
        global $DB;

        $where = 'penaltyuntil > :now';
        $params = ['now' => time()];
        if ($courseid > 0) {
            $where .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($quizid > 0) {
            $where .= ' AND quizid = :quizid';
            $params['quizid'] = $quizid;
        }

        $rows = $DB->get_records_select('local_examguard_penalty', $where, $params, '', 'id, attemptid, userid');
        $count = 0;
        foreach ($rows as $row) {
            if (self::reset_attempt_penalty((int) $row->attemptid, (int) $row->userid, $actorid)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Remove an active penalty after a teacher confirmed it in the report page.
     *
     * Delegates to reset_attempt_penalty() so violation history and audit logging
     * remain consistent across all reset entry points.
     *
     * @param int $logid id of a log row that identifies the attempt.
     * @param int $teacherid id of the teacher performing the action.
     * @return bool true when a penalty was active and is now removed.
     */
    public static function unlock_from_log(int $logid, int $teacherid): bool {
        global $DB;

        $log = $DB->get_record('local_examguard_logs', ['id' => $logid]);
        if (!$log) {
            return false;
        }

        return self::reset_attempt_penalty((int) $log->attemptid, (int) $log->userid, $teacherid);
    }

    /**
     * Delete old logs, stale penalty state and stale penalty user preferences.
     *
     * @param int $retentiondays number of days to keep.
     * @return array counters with keys logs, states and preferences.
     */
    public static function cleanup(int $retentiondays): array {
        global $DB;

        $days = max(1, $retentiondays);
        $cutoff = time() - ($days * DAYSECS);

        $logs = $DB->count_records_select('local_examguard_logs', 'timecreated < ?', [$cutoff]);
        $DB->delete_records_select('local_examguard_logs', 'timecreated < ?', [$cutoff]);

        // Collect the attempt ids first so their user preferences can be removed too.
        $staleattempts = $DB->get_fieldset_select('local_examguard_penalty', 'attemptid',
            'penaltyuntil < ? AND timemodified < ?', [$cutoff, $cutoff]);

        $states = $DB->count_records_select('local_examguard_penalty',
            'penaltyuntil < ? AND timemodified < ?', [$cutoff, $cutoff]);
        $DB->delete_records_select('local_examguard_penalty',
            'penaltyuntil < ? AND timemodified < ?', [$cutoff, $cutoff]);

        $prefs = 0;
        foreach (array_unique($staleattempts) as $attemptid) {
            $name = 'local_examguard_penalty_' . $attemptid;
            $prefs += $DB->count_records('user_preferences', ['name' => $name]);
            $DB->delete_records('user_preferences', ['name' => $name]);
        }

        return ['logs' => $logs, 'states' => $states, 'preferences' => $prefs];
    }

    /**
     * Mirror the authoritative server state into a user preference.
     *
     * This is only a convenience mirror: the database row always wins.
     *
     * @param int $attemptid quiz attempt id.
     * @param int $userid user id.
     * @param \stdClass $state penalty state row.
     */
    protected static function write_preference(int $attemptid, int $userid, \stdClass $state): void {
        $value = json_encode([
            'until' => (int) $state->penaltyuntil,
            'count' => (int) $state->violationcount,
            'frozen' => (int) $state->frozenseconds,
            'synced' => time(),
        ], JSON_UNESCAPED_SLASHES);

        try {
            set_user_preference('local_examguard_penalty_' . $attemptid, $value, $userid);
        } catch (\Throwable $e) {
            debugging('local_examguard: could not store penalty preference: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
