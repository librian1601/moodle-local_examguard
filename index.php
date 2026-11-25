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
 * ExamGuard violation recap dashboard for teachers and administrators.
 *
 * @package    local_examguard
 * @copyright  2026 Librian Yudhista Pambudi (https://librian.my.id)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
$quizid = optional_param('quizid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$action = optional_param('action', '', PARAM_ALPHA);
$logid = optional_param('logid', 0, PARAM_INT);

$perpage = \local_examguard\penalty_manager::setting_int('reportperpage', 50);
$perpage = max(10, min(500, $perpage));

// Work out the context and check the capability.
$course = null;
if ($courseid) {
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    require_login($course);
    $context = context_course::instance($courseid);
    require_capability('local/examguard:viewreport', $context);
    $pageurl = new moodle_url('/local/examguard/index.php', ['courseid' => $courseid]);
} else {
    require_login();
    $context = context_system::instance();
    require_capability('local/examguard:viewall', $context);
    $pageurl = new moodle_url('/local/examguard/index.php');
}

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('reporttitle', 'local_examguard'));
$PAGE->set_heading(get_string('reporttitle', 'local_examguard'));
$PAGE->set_pagelayout($course ? 'incourse' : 'admin');

// Teachers with the manage capability may release a penalty from the report.
if ($action === 'unlock' && $logid) {
    require_sesskey();

    $log = $DB->get_record('local_examguard_logs', ['id' => $logid]);
    if (!$log) {
        redirect($pageurl, get_string('unlockfailed', 'local_examguard'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    $logcontext = context_course::instance($log->courseid);
    require_capability('local/examguard:manage', $logcontext);

    if (\local_examguard\penalty_manager::unlock_from_log((int) $log->id, (int) $USER->id)) {
        redirect($pageurl, get_string('unlockdone', 'local_examguard'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }

    redirect($pageurl, get_string('unlockfailed', 'local_examguard'), null,
        \core\output\notification::NOTIFY_ERROR);
}

$canmanage = $courseid
    ? has_capability('local/examguard:manage', $context)
    : has_capability('moodle/site:config', context_system::instance());

// Filter options based on the courses and quizzes that actually have data.
$courses = $DB->get_records_sql("SELECT DISTINCT c.id, c.fullname
                                   FROM {course} c
                                   JOIN {local_examguard_logs} l ON l.courseid = c.id
                               ORDER BY c.fullname");

$quizzes = [];
if ($courseid) {
    $quizzes = $DB->get_records_sql("SELECT DISTINCT q.id, q.name
                                       FROM {quiz} q
                                       JOIN {local_examguard_logs} l ON l.quizid = q.id
                                      WHERE q.course = :courseid
                                   ORDER BY q.name", ['courseid' => $courseid]);
}

// Summary counters reuse the same conditions as the table.
$summarywhere = '1 = 1';
$summaryparams = [];
if ($courseid) {
    $summarywhere .= ' AND l.courseid = :summarycourse';
    $summaryparams['summarycourse'] = $courseid;
}
if ($quizid) {
    $summarywhere .= ' AND l.quizid = :summaryquiz';
    $summaryparams['summaryquiz'] = $quizid;
}
if ($search !== '') {
    $summarysearch = '%' . $search . '%';
    $summarywhere .= ' AND (' . $DB->sql_like('u.username', ':searchuser', false)
        . ' OR ' . $DB->sql_like($DB->sql_fullname('u.firstname', 'u.lastname'), ':searchname', false) . ')';
    $summaryparams['searchuser'] = $summarysearch;
    $summaryparams['searchname'] = $summarysearch;
}
$summaryparams['today'] = strtotime('today');

$summary = $DB->get_record_sql("SELECT COUNT(l.id) AS total,
                                       COUNT(DISTINCT l.userid) AS students,
                                       SUM(CASE WHEN l.timecreated >= :today THEN 1 ELSE 0 END) AS today
                                  FROM {local_examguard_logs} l
                                  JOIN {user} u ON u.id = l.userid
                                 WHERE $summarywhere", $summaryparams);

$totallogs = $summary ? (int) $summary->total : 0;
$totalstudents = $summary ? (int) $summary->students : 0;
$totaltoday = $summary ? (int) $summary->today : 0;

// Filter form.
$filterhtml = html_writer::start_tag('form', [
    'method' => 'get',
    'action' => new moodle_url('/local/examguard/index.php'),
    'class' => 'examguard-report-filters mb-3',
]);
$filterhtml .= html_writer::start_div('d-flex flex-wrap align-items-end');

if (!$courseid) {
    $courseoptions = [0 => get_string('allcourses', 'local_examguard')];
    foreach ($courses as $courserecord) {
        $courseoptions[$courserecord->id] = format_string($courserecord->fullname);
    }
    $filterhtml .= html_writer::start_div('me-2 mb-2');
    $filterhtml .= html_writer::label(get_string('filtercourse', 'local_examguard'), 'examguard-course',
        true, ['class' => 'd-block']);
    $filterhtml .= html_writer::select($courseoptions, 'courseid', $courseid, false,
        ['id' => 'examguard-course', 'class' => 'custom-select']);
    $filterhtml .= html_writer::end_div();
} else {
    $filterhtml .= html_writer::empty_tag('input', [
        'type' => 'hidden', 'name' => 'courseid', 'value' => $courseid,
    ]);
    if ($quizzes) {
        $quizoptions = [0 => get_string('allquizzes', 'local_examguard')];
        foreach ($quizzes as $quizrecord) {
            $quizoptions[$quizrecord->id] = format_string($quizrecord->name);
        }
        $filterhtml .= html_writer::start_div('me-2 mb-2');
        $filterhtml .= html_writer::label(get_string('filterquiz', 'local_examguard'), 'examguard-quiz',
            true, ['class' => 'd-block']);
        $filterhtml .= html_writer::select($quizoptions, 'quizid', $quizid, false,
            ['id' => 'examguard-quiz', 'class' => 'custom-select']);
        $filterhtml .= html_writer::end_div();
    }
}

$filterhtml .= html_writer::start_div('me-2 mb-2');
$filterhtml .= html_writer::label(get_string('filterstudent', 'local_examguard'), 'examguard-search',
    true, ['class' => 'd-block']);
$filterhtml .= html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'search',
    'id' => 'examguard-search',
    'value' => $search,
    'class' => 'form-control',
]);
$filterhtml .= html_writer::end_div();

$filterhtml .= html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary mb-2 me-2',
    'value' => get_string('filterapply', 'local_examguard'),
]);
$filterhtml .= html_writer::link(
    new moodle_url('/local/examguard/index.php', $courseid ? ['courseid' => $courseid] : []),
    get_string('filterreset', 'local_examguard'),
    ['class' => 'btn btn-secondary mb-2']
);
$filterhtml .= html_writer::end_div();
$filterhtml .= html_writer::end_tag('form');

// Summary cards.
$summaryhtml = html_writer::start_div('examguard-report-summary');
$cards = [
    [get_string('summarytotal', 'local_examguard'), $totallogs],
    [get_string('summarystudents', 'local_examguard'), $totalstudents],
    [get_string('summarytoday', 'local_examguard'), $totaltoday],
];
foreach ($cards as $card) {
    $summaryhtml .= html_writer::div(
        html_writer::div((string) $card[1], 'h3 mb-0')
        . html_writer::div($card[0], 'examguard-muted'),
        'card card-body'
    );
}
$summaryhtml .= html_writer::end_div();

// Build the table.
$table = new \local_examguard\table\logs_table('local_examguard_logs', [
    'courseid' => $courseid,
    'quizid' => $quizid,
    'search' => $search,
], $canmanage);

$tableparams = [];
if ($courseid) {
    $tableparams['courseid'] = $courseid;
}
if ($quizid) {
    $tableparams['quizid'] = $quizid;
}
if ($search !== '') {
    $tableparams['search'] = $search;
}
$table->define_baseurl(new moodle_url('/local/examguard/index.php', $tableparams));

// Export formats send their own headers, so nothing may be printed before this.
if ($table->is_downloading()) {
    $table->out($perpage, false);
    exit;
}

echo $OUTPUT->header();
echo html_writer::div(get_string('reportintro', 'local_examguard'), 'alert alert-info');
echo $summaryhtml;
echo $filterhtml;
$table->out($perpage, true);
$creditslink = html_writer::link(
    'https://librian.my.id',
    'https://librian.my.id',
    ['target' => '_blank', 'rel' => 'noopener noreferrer']
);
$credits = get_string('plugincredits', 'local_examguard', (object) [
    'author'  => 'Librian Yudhista Pambudi',
    'website' => $creditslink,
]);
echo html_writer::div($credits, 'text-muted small mt-4 border-top pt-2');
echo $OUTPUT->footer();
