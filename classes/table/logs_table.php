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
 * Violation report table for local_examguard.
 *
 * @package    local_examguard
 * @copyright  2026 Librian Yudhista Pambudi (https://librian.my.id)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_examguard\table;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * Sortable, downloadable table of ExamGuard violation events.
 */
class logs_table extends \table_sql {

    /** @var array active filters. */
    protected $filters;

    /** @var bool whether the current user may unlock penalties. */
    protected $canmanage;

    /**
     * Constructor.
     *
     * @param string $uniqueid unique table id.
     * @param array $filters filters with keys courseid, quizid and search.
     * @param bool $canmanage whether to show the unlock action column.
     */
    public function __construct($uniqueid, array $filters = [], $canmanage = false) {
        parent::__construct($uniqueid);

        $this->filters = $filters;
        $this->canmanage = $canmanage;

        $download = optional_param('download', '', PARAM_ALPHA);
        if ($download !== '') {
            $this->is_downloading($download, 'examguard-report',
                get_string('reporttitle', 'local_examguard'));
        } else {
            $this->is_downloadable(true);
        }
        $this->show_download_buttons_at([TABLE_P_BOTTOM]);

        $columns = ['student', 'username', 'quiz', 'course', 'violations', 'eventtype',
            'penaltyuntil', 'frozen', 'ipaddress', 'useragent', 'timecreated'];
        if ($canmanage) {
            $columns[] = 'actions';
        }
        $this->define_columns($columns);

        $headers = [
            get_string('th_student', 'local_examguard'),
            get_string('th_username', 'local_examguard'),
            get_string('th_quiz', 'local_examguard'),
            get_string('th_course', 'local_examguard'),
            get_string('th_violations', 'local_examguard'),
            get_string('th_eventtype', 'local_examguard'),
            get_string('th_penaltyuntil', 'local_examguard'),
            get_string('th_frozen', 'local_examguard'),
            get_string('th_ip', 'local_examguard'),
            get_string('th_useragent', 'local_examguard'),
            get_string('th_timecreated', 'local_examguard'),
        ];
        if ($canmanage) {
            $headers[] = get_string('th_actions', 'local_examguard');
        }
        $this->define_headers($headers);

        $this->sortable(true, 'timecreated', SORT_DESC);
        $this->no_sorting('student');
        $this->no_sorting('username');
        $this->no_sorting('quiz');
        $this->no_sorting('course');
        $this->no_sorting('eventtype');
        $this->no_sorting('frozen');
        $this->no_sorting('ipaddress');
        $this->no_sorting('useragent');
        if ($canmanage) {
            $this->no_sorting('actions');
        }
        $this->collapsible(false);
        $this->set_attribute('class', 'generaltable examguard-report-table');

        $fields = 'l.id, l.userid, l.courseid, l.cmid, l.quizid, l.attemptid, l.eventtype,'
            . ' l.violationcount, l.penaltyuntil, l.ipaddress, l.useragent, l.timecreated,'
            . ' u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename,'
            . ' u.alternatename, u.username, u.email,'
            . ' q.name AS quizname, c.fullname AS coursename,'
            . ' p.frozenseconds, p.penaltyuntil AS statepenaltyuntil';

        $from = '{local_examguard_logs} l
                 JOIN {user} u ON u.id = l.userid
                 JOIN {quiz} q ON q.id = l.quizid
                 JOIN {course} c ON c.id = l.courseid
            LEFT JOIN {local_examguard_penalty} p ON p.attemptid = l.attemptid AND p.userid = l.userid';

        list($where, $params) = $this->build_where();

        $this->set_sql($fields, $from, $where, $params);
        $this->set_count_sql('SELECT COUNT(l.id) FROM ' . $from . ' WHERE ' . $where, $params);
    }

    /**
     * Build the WHERE clause and parameters from the active filters.
     *
     * @return array [where, params]
     */
    protected function build_where(): array {
        global $DB;

        $where = '1 = 1';
        $params = [];

        if (!empty($this->filters['courseid'])) {
            $where .= ' AND l.courseid = :filtercourseid';
            $params['filtercourseid'] = (int) $this->filters['courseid'];
        }
        if (!empty($this->filters['quizid'])) {
            $where .= ' AND l.quizid = :filterquizid';
            $params['filterquizid'] = (int) $this->filters['quizid'];
        }
        if (!empty($this->filters['search'])) {
            $search = '%' . $DB->sql_like_escape(trim($this->filters['search'])) . '%';
            $where .= ' AND (' . $DB->sql_like('u.username', ':searchuser', false)
                . ' OR ' . $DB->sql_like($DB->sql_fullname('u.firstname', 'u.lastname'), ':searchname', false)
                . ' OR ' . $DB->sql_like('u.email', ':searchemail', false)
                . ' OR ' . $DB->sql_like('u.idnumber', ':searchidnumber', false)
                . ')';
            $params['searchuser'] = $search;
            $params['searchname'] = $search;
            $params['searchemail'] = $search;
            $params['searchidnumber'] = $search;
        }

        return [$where, $params];
    }

    /**
     * Student full name column.
     *
     * @param \stdClass $row table row.
     * @return string
     */
    public function col_student(\stdClass $row): string {
        if ($this->is_downloading()) {
            return fullname($row);
        }
        $url = new \moodle_url('/user/profile.php', ['id' => $row->userid]);
        return \html_writer::link($url, fullname($row));
    }

    /**
     * Username column.
     *
     * @param \stdClass $row table row.
     * @return string
     */
    public function col_username(\stdClass $row): string {
        return s($row->username);
    }

    /**
     * Quiz name column.
     *
     * @param \stdClass $row table row.
     * @return string
     */
    public function col_quiz(\stdClass $row): string {
        if ($this->is_downloading()) {
            return format_string($row->quizname);
        }
        $url = new \moodle_url('/mod/quiz/view.php', ['id' => $row->cmid]);
        return \html_writer::link($url, format_string($row->quizname));
    }

    /**
     * Course name column.
     *
     * @param \stdClass $row table row.
     * @return string
     */
    public function col_course(\stdClass $row): string {
        if ($this->is_downloading()) {
            return format_string($row->coursename);
        }
        $url = new \moodle_url('/course/view.php', ['id' => $row->courseid]);
        return \html_writer::link($url, format_string($row->coursename));
    }

    /**
     * Violation counter column.
     *
     * @param \stdClass $row table row.
     * @return string
     */
    public function col_violations(\stdClass $row): string {
        return (string) (int) $row->violationcount;
    }

    /**
     * Event type column.
     *
     * @param \stdClass $row table row.
     * @return string
     */
    public function col_eventtype(\stdClass $row): string {
        $key = 'eventtype_' . $row->eventtype;
        if (get_string_manager()->string_exists($key, 'local_examguard')) {
            return get_string($key, 'local_examguard');
        }
        return get_string('eventtype_unknown', 'local_examguard');
    }

    /**
     * Penalty end column with an active or ended badge.
     *
     * @param \stdClass $row table row.
     * @return string
     */
    public function col_penaltyuntil(\stdClass $row): string {
        $until = (int) $row->statepenaltyuntil;
        if (!$until) {
            $until = (int) $row->penaltyuntil;
        }
        if (!$until) {
            return '-';
        }

        $date = userdate($until, get_string('strftimedatetimeshort', 'langconfig'));
        if ($this->is_downloading()) {
            return $date;
        }

        $label = $until > time()
            ? \html_writer::span(get_string('penaltyactive', 'local_examguard'), 'examguard-badge-active')
            : \html_writer::span(get_string('penaltyended', 'local_examguard'), 'examguard-badge-ended');

        return $label . ' ' . $date;
    }

    /**
     * Frozen quiz time column.
     *
     * @param \stdClass $row table row.
     * @return string
     */
    public function col_frozen(\stdClass $row): string {
        $frozen = (int) $row->frozenseconds;
        if ($frozen <= 0) {
            return '-';
        }
        return $this->is_downloading() ? (string) $frozen : format_time($frozen);
    }

    /**
     * IP address column.
     *
     * @param \stdClass $row table row.
     * @return string
     */
    public function col_ipaddress(\stdClass $row): string {
        return s($row->ipaddress);
    }

    /**
     * Truncated user agent column.
     *
     * @param \stdClass $row table row.
     * @return string
     */
    public function col_useragent(\stdClass $row): string {
        $useragent = (string) $row->useragent;
        if ($this->is_downloading()) {
            return $useragent;
        }
        $short = \core_text::substr($useragent, 0, 60);
        return \html_writer::span(s($short), 'examguard-muted', ['title' => $useragent]);
    }

    /**
     * Time created column.
     *
     * @param \stdClass $row table row.
     * @return string
     */
    public function col_timecreated(\stdClass $row): string {
        return userdate($row->timecreated, get_string('strftimedatetimeshort', 'langconfig'));
    }

    /**
     * Unlock action column.
     *
     * @param \stdClass $row table row.
     * @return string
     */
    public function col_actions(\stdClass $row): string {
        if (!$this->canmanage || $this->is_downloading()) {
            return '';
        }
        if ((int) $row->statepenaltyuntil <= time()) {
            return '';
        }

        $cmid = isset($this->filters['cmid']) ? (int) $this->filters['cmid'] : 0;
        $courseid = isset($this->filters['courseid']) ? (int) $this->filters['courseid'] : 0;
        $quizid = isset($this->filters['quizid']) ? (int) $this->filters['quizid'] : 0;
        $search = isset($this->filters['search']) ? (string) $this->filters['search'] : '';
        $q = isset($this->filters['q']) ? (string) $this->filters['q'] : '';

        $studentname = fullname($row);
        $confirmmsg = get_string('resetpenalty_confirm', 'local_examguard', $studentname);

        $form = \html_writer::start_tag('form', [
            'method' => 'post',
            'action' => new \moodle_url('/local/examguard/index.php'),
            'class' => 'd-inline',
            'onsubmit' => 'return confirm(' . json_encode($confirmmsg) . ');',
        ]);
        $form .= \html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey(),
        ]);
        $form .= \html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'action', 'value' => 'resetpenalty',
        ]);
        $form .= \html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'attemptid', 'value' => (int) $row->attemptid,
        ]);
        $form .= \html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'userid', 'value' => (int) $row->userid,
        ]);
        if ($cmid > 0) {
            $form .= \html_writer::empty_tag('input', [
                'type' => 'hidden', 'name' => 'cmid', 'value' => $cmid,
            ]);
        } else if ($courseid > 0) {
            $form .= \html_writer::empty_tag('input', [
                'type' => 'hidden', 'name' => 'courseid', 'value' => $courseid,
            ]);
        }
        if (!$cmid && $quizid > 0) {
            $form .= \html_writer::empty_tag('input', [
                'type' => 'hidden', 'name' => 'quizid', 'value' => $quizid,
            ]);
        }
        if ($search !== '') {
            $form .= \html_writer::empty_tag('input', [
                'type' => 'hidden', 'name' => 'search', 'value' => $search,
            ]);
        }
        if ($q !== '') {
            $form .= \html_writer::empty_tag('input', [
                'type' => 'hidden', 'name' => 'q', 'value' => $q,
            ]);
        }
        $form .= \html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-warning btn-sm',
            'value' => get_string('resetpenalty', 'local_examguard'),
        ]);
        $form .= \html_writer::end_tag('form');

        return $form;
    }
}
