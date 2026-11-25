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
 * Scheduled cleanup of old ExamGuard data.
 *
 * @package    local_examguard
 * @copyright  2026 Librian Yudhista Pambudi (https://librian.my.id)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_examguard\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Deletes old violation logs, stale penalty state and stale user preferences.
 */
class cleanup_logs extends \core\task\scheduled_task {

    /**
     * Task name shown in the scheduled task administration page.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskcleanuplogs', 'local_examguard');
    }

    /**
     * Run the cleanup.
     */
    public function execute() {
        $days = \local_examguard\penalty_manager::setting_int('retentiondays',
            \local_examguard\penalty_manager::DEFAULT_RETENTIONDAYS);
        $days = max(1, $days);

        $result = \local_examguard\penalty_manager::cleanup($days);

        mtrace('ExamGuard: removed ' . $result['logs'] . ' log rows older than ' . $days . ' days.');
        mtrace('ExamGuard: removed ' . $result['states'] . ' stale penalty state rows.');
        mtrace('ExamGuard: removed ' . $result['preferences'] . ' stale user preferences.');
    }
}
