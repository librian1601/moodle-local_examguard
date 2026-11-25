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
 * Privacy provider for local_examguard.
 *
 * @package    local_examguard
 * @copyright  2026 Librian Yudhista Pambudi (https://librian.my.id)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_examguard\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Stores violation logs and penalty state which include the student IP address
 * and browser user agent, so full export and deletion support is provided.
 */
class provider implements \core_privacy\local\metadata\provider, core_userlist_provider, plugin_provider {

    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection collection to add metadata to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_examguard_logs', [
            'userid' => 'privacy:metadata:local_examguard_logs:userid',
            'eventtype' => 'privacy:metadata:local_examguard_logs:eventtype',
            'violationcount' => 'privacy:metadata:local_examguard_logs:violationcount',
            'ipaddress' => 'privacy:metadata:local_examguard_logs:ipaddress',
            'useragent' => 'privacy:metadata:local_examguard_logs:useragent',
            'extra' => 'privacy:metadata:local_examguard_logs:extra',
            'timecreated' => 'privacy:metadata:local_examguard_logs:timecreated',
        ], 'privacy:metadata:local_examguard_logs');

        $collection->add_database_table('local_examguard_penalty', [
            'violationcount' => 'privacy:metadata:local_examguard_penalty:violationcount',
            'penaltyuntil' => 'privacy:metadata:local_examguard_penalty:penaltyuntil',
            'frozenseconds' => 'privacy:metadata:local_examguard_penalty:frozenseconds',
        ], 'privacy:metadata:local_examguard_penalty');

        return $collection;
    }

    /**
     * Return the course contexts where the user has ExamGuard data.
     *
     * @param int $userid user id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_examguard_logs} l ON l.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :contextlevel AND l.userid = :userid";
        $contextlist->add_from_sql($sql, ['contextlevel' => CONTEXT_COURSE, 'userid' => $userid]);

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_examguard_penalty} p ON p.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :contextlevel AND p.userid = :userid";
        $contextlist->add_from_sql($sql, ['contextlevel' => CONTEXT_COURSE, 'userid' => $userid]);

        return $contextlist;
    }

    /**
     * Return the users with ExamGuard data in a course context.
     *
     * @param userlist $userlist user list to add users to.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_course) {
            return;
        }

        $params = ['courseid' => $context->instanceid];
        $userlist->add_from_sql('userid',
            "SELECT userid FROM {local_examguard_logs} WHERE courseid = :courseid", $params);
        $userlist->add_from_sql('userid',
            "SELECT userid FROM {local_examguard_penalty} WHERE courseid = :courseid", $params);
    }

    /**
     * Export the ExamGuard data for the approved course contexts.
     *
     * @param approved_contextlist $contextlist approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }

            $params = ['courseid' => $context->instanceid, 'userid' => $userid];

            $logs = $DB->get_records_sql("SELECT l.*, q.name AS quizname
                                           FROM {local_examguard_logs} l
                                      LEFT JOIN {quiz} q ON q.id = l.quizid
                                          WHERE l.courseid = :courseid AND l.userid = :userid
                                       ORDER BY l.timecreated ASC", $params);
            if ($logs) {
                $data = [];
                foreach ($logs as $log) {
                    $data[] = (object) [
                        'eventtype' => $log->eventtype,
                        'quiz' => $log->quizname,
                        'violationcount' => (int) $log->violationcount,
                        'ipaddress' => $log->ipaddress,
                        'useragent' => $log->useragent,
                        'extra' => $log->extra,
                        'penaltyuntil' => $log->penaltyuntil ? transform::datetime($log->penaltyuntil) : '',
                        'timecreated' => transform::datetime($log->timecreated),
                    ];
                }
                writer::with_context($context)->export_data(
                    [get_string('privacy:path:logs', 'local_examguard')],
                    (object) ['logs' => $data]
                );
            }

            $states = $DB->get_records_sql("SELECT p.*, q.name AS quizname
                                              FROM {local_examguard_penalty} p
                                         LEFT JOIN {quiz} q ON q.id = p.quizid
                                             WHERE p.courseid = :courseid AND p.userid = :userid", $params);
            if ($states) {
                $data = [];
                foreach ($states as $state) {
                    $data[] = (object) [
                        'quiz' => $state->quizname,
                        'violationcount' => (int) $state->violationcount,
                        'penaltyuntil' => $state->penaltyuntil ? transform::datetime($state->penaltyuntil) : '',
                        'frozenseconds' => (int) $state->frozenseconds,
                    ];
                }
                writer::with_context($context)->export_data(
                    [get_string('privacy:path:penalty', 'local_examguard')],
                    (object) ['states' => $data]
                );
            }
        }
    }

    /**
     * Delete all ExamGuard data for a whole course context.
     *
     * @param \context $context context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_course) {
            return;
        }

        $attemptids = $DB->get_fieldset_select('local_examguard_penalty', 'attemptid',
            'courseid = ?', [$context->instanceid]);

        $DB->delete_records('local_examguard_logs', ['courseid' => $context->instanceid]);
        $DB->delete_records('local_examguard_penalty', ['courseid' => $context->instanceid]);

        foreach (array_unique($attemptids) as $attemptid) {
            $DB->delete_records('user_preferences', ['name' => 'local_examguard_penalty_' . $attemptid]);
        }
    }

    /**
     * Delete ExamGuard data for one user in the approved contexts.
     *
     * @param approved_contextlist $contextlist approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }

            $attemptids = $DB->get_fieldset_select('local_examguard_penalty', 'attemptid',
                'courseid = ? AND userid = ?', [$context->instanceid, $userid]);

            $DB->delete_records('local_examguard_logs', [
                'courseid' => $context->instanceid,
                'userid' => $userid,
            ]);
            $DB->delete_records('local_examguard_penalty', [
                'courseid' => $context->instanceid,
                'userid' => $userid,
            ]);

            foreach (array_unique($attemptids) as $attemptid) {
                $DB->delete_records('user_preferences', [
                    'name' => 'local_examguard_penalty_' . $attemptid,
                    'userid' => $userid,
                ]);
            }
        }

        // Remove any leftover penalty preference of this user.
        $DB->delete_records_select('user_preferences',
            "userid = :userid AND " . $DB->sql_like('name', ':prefix'),
            ['userid' => $userid, 'prefix' => 'local_examguard_penalty_%']);
    }

    /**
     * Delete ExamGuard data for a list of users in one course context.
     *
     * @param approved_userlist $userlist approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_course) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = $inparams + ['courseid' => $context->instanceid];

        $attemptids = $DB->get_fieldset_select('local_examguard_penalty', 'attemptid',
            "courseid = :courseid AND userid $insql", $params);

        $DB->delete_records_select('local_examguard_logs',
            "courseid = :courseid AND userid $insql", $params);
        $DB->delete_records_select('local_examguard_penalty',
            "courseid = :courseid AND userid $insql", $params);

        foreach (array_unique($attemptids) as $attemptid) {
            list($prefinsql, $prefparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $prefparams['prefname'] = 'local_examguard_penalty_' . $attemptid;
            $DB->delete_records_select('user_preferences',
                "name = :prefname AND userid $prefinsql", $prefparams);
        }
    }
}
