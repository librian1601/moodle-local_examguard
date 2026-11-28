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
 * ExamGuard proctor dashboard and violation recap for teachers and administrators.
 *
 * Supports three scopes seamlessly:
 * 1. Quiz module scope (?cmid=X) from the Quiz secondary navigation "ExamGuard" tab.
 * 2. Course scope (?courseid=X) from Course administration.
 * 3. Site-wide scope from Site administration.
 *
 * @package    local_examguard
 * @copyright  2026 Librian Yudhista Pambudi (https://librian.my.id)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

$cmid      = optional_param('cmid', 0, PARAM_INT);
$courseid  = optional_param('courseid', 0, PARAM_INT);
$quizid    = optional_param('quizid', 0, PARAM_INT);
$search    = optional_param('search', '', PARAM_TEXT);
$q         = optional_param('q', '', PARAM_TEXT);
$action    = optional_param('action', '', PARAM_ALPHA);
$logid     = optional_param('logid', 0, PARAM_INT);
$attemptid = optional_param('attemptid', 0, PARAM_INT);
$userid    = optional_param('userid', 0, PARAM_INT);

$perpage = \local_examguard\penalty_manager::setting_int('reportperpage', 50);
$perpage = max(10, min(500, $perpage));

// Resolve scope, login, context, capability and page navigation.
$course = null;
$cm = null;
$baseparams = [];

if ($cmid > 0) {
    list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'quiz');
    $quizid = (int) $cm->instance;
    $courseid = (int) $course->id;
    require_login($course, false, $cm);
    $context = context_module::instance($cm->id);
    if (!has_capability('local/examguard:viewreport', $context)
            && !has_capability('local/examguard:manage', $context)) {
        require_capability('local/examguard:viewreport', $context);
    }
    $canmanage = has_capability('local/examguard:manage', $context);
    $baseparams = ['cmid' => $cmid];

    $PAGE->set_cm($cm, $course);
    $PAGE->set_context($context);
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_title(format_string($cm->name) . ': ' . get_string('reporttitle', 'local_examguard'));
    $PAGE->set_heading(format_string($course->fullname));
    if (method_exists($PAGE, 'set_secondary_active_tab')) {
        $PAGE->set_secondary_active_tab('local_examguard_quizreport');
    }
} else if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    require_login($course);
    $context = context_course::instance($courseid);
    if (!has_capability('local/examguard:viewreport', $context)
            && !has_capability('local/examguard:manage', $context)) {
        require_capability('local/examguard:viewreport', $context);
    }
    $canmanage = has_capability('local/examguard:manage', $context);
    $baseparams = ['courseid' => $courseid];

    $PAGE->set_context($context);
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_title(get_string('reporttitle', 'local_examguard'));
    $PAGE->set_heading(format_string($course->fullname));
} else {
    require_login();
    $context = context_system::instance();
    require_capability('local/examguard:viewall', $context);
    $canmanage = has_capability('local/examguard:manage', $context)
        || has_capability('moodle/site:config', $context);

    $PAGE->set_context($context);
    $PAGE->set_pagelayout('admin');
    $PAGE->set_title(get_string('reporttitle', 'local_examguard'));
    $PAGE->set_heading(get_string('reporttitle', 'local_examguard'));
}

// Preserve active query parameters for URLs and PRG redirects.
$stateparams = $baseparams;
if (!$cmid && $quizid > 0) {
    $stateparams['quizid'] = $quizid;
}
if ($search !== '') {
    $stateparams['search'] = $search;
}
if ($q !== '') {
    $stateparams['q'] = $q;
}
$pageurl = new moodle_url('/local/examguard/index.php', $stateparams);
$PAGE->set_url($pageurl);

// Handle POST proctor reset actions (strict CSRF + capability + PRG redirect).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action !== '') {
    require_sesskey();

    if ($action === 'resetpenalty' && $attemptid > 0 && $userid > 0) {
        $targetstate = \local_examguard\penalty_manager::get_state($attemptid, $userid);
        $targetcontext = $context;
        if ($targetstate && !empty($targetstate->cmid)) {
            $targetcontext = context_module::instance((int) $targetstate->cmid, IGNORE_MISSING) ?: $context;
        } else if ($targetstate && !empty($targetstate->courseid)) {
            $targetcontext = context_course::instance((int) $targetstate->courseid, IGNORE_MISSING) ?: $context;
        }
        if (!has_capability('local/examguard:manage', $targetcontext)
                && !has_capability('moodle/site:config', context_system::instance())) {
            require_capability('local/examguard:manage', $targetcontext);
        }

        $userrec = $DB->get_record('user', ['id' => $userid]);
        $studentname = $userrec ? fullname($userrec) : ('#' . $userid);

        if (\local_examguard\penalty_manager::reset_attempt_penalty($attemptid, $userid, (int) $USER->id)) {
            \core\notification::success(get_string('resetpenalty_done', 'local_examguard', $studentname));
        } else {
            \core\notification::error(get_string('resetpenalty_failed', 'local_examguard'));
        }
        redirect($pageurl);
    }

    if ($action === 'resetallactive') {
        if (!has_capability('local/examguard:manage', $context)
                && !has_capability('moodle/site:config', context_system::instance())) {
            require_capability('local/examguard:manage', $context);
        }

        $resetcount = \local_examguard\penalty_manager::reset_all_active_penalties(
            $courseid,
            $quizid,
            (int) $USER->id
        );
        if ($resetcount > 0) {
            \core\notification::success(get_string('resetall_done', 'local_examguard', $resetcount));
        } else {
            \core\notification::info(get_string('resetall_none', 'local_examguard'));
        }
        redirect($pageurl);
    }

    if ($action === 'unlock' && $logid > 0) {
        $log = $DB->get_record('local_examguard_logs', ['id' => $logid]);
        if (!$log) {
            \core\notification::error(get_string('unlockfailed', 'local_examguard'));
            redirect($pageurl);
        }

        $logcontext = context_course::instance((int) $log->courseid);
        if (!has_capability('local/examguard:manage', $logcontext)
                && !has_capability('moodle/site:config', context_system::instance())) {
            require_capability('local/examguard:manage', $logcontext);
        }

        if (\local_examguard\penalty_manager::unlock_from_log((int) $log->id, (int) $USER->id)) {
            \core\notification::success(get_string('unlockdone', 'local_examguard'));
        } else {
            \core\notification::error(get_string('unlockfailed', 'local_examguard'));
        }
        redirect($pageurl);
    }
}

// Build the logs table early so download requests (CSV/Excel) send headers cleanly before HTML.
$table = new \local_examguard\table\logs_table('local_examguard_logs', [
    'cmid'     => $cmid,
    'courseid' => $courseid,
    'quizid'   => $quizid,
    'search'   => $search,
    'q'        => $q,
], $canmanage);
$table->define_baseurl($pageurl);

if ($table->is_downloading()) {
    $table->out($perpage, false);
    exit;
}

// Fetch active locked students for the top proctor panel.
$activelocks = \local_examguard\penalty_manager::get_active_locked_penalties($courseid, $quizid, $q);
$totalactivelocks = ($q !== '')
    ? count(\local_examguard\penalty_manager::get_active_locked_penalties($courseid, $quizid, ''))
    : count($activelocks);

// Filter dropdown options (only needed outside single-quiz cmid mode).
$courses = [];
$quizzes = [];
if (!$cmid) {
    if (!$courseid) {
        $courses = $DB->get_records_sql("SELECT DISTINCT c.id, c.fullname
                                           FROM {course} c
                                           JOIN {local_examguard_logs} l ON l.courseid = c.id
                                       ORDER BY c.fullname");
    } else {
        $quizzes = $DB->get_records_sql("SELECT DISTINCT q.id, q.name
                                           FROM {quiz} q
                                           JOIN {local_examguard_logs} l ON l.quizid = q.id
                                          WHERE q.course = :courseid
                                       ORDER BY q.name", ['courseid' => $courseid]);
    }
}

// Summary counters reuse the same scope/search conditions as the history table.
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
    $summarysearch = '%' . $DB->sql_like_escape(trim($search)) . '%';
    $summarywhere .= ' AND (' . $DB->sql_like('u.username', ':searchuser', false)
        . ' OR ' . $DB->sql_like($DB->sql_fullname('u.firstname', 'u.lastname'), ':searchname', false)
        . ' OR ' . $DB->sql_like('u.email', ':searchemail', false)
        . ' OR ' . $DB->sql_like('u.idnumber', ':searchidnumber', false)
        . ')';
    $summaryparams['searchuser'] = $summarysearch;
    $summaryparams['searchname'] = $summarysearch;
    $summaryparams['searchemail'] = $summarysearch;
    $summaryparams['searchidnumber'] = $summarysearch;
}
$summaryparams['today'] = strtotime('today');

$summary = $DB->get_record_sql("SELECT COUNT(l.id) AS total,
                                       COUNT(DISTINCT l.userid) AS students,
                                       SUM(CASE WHEN l.timecreated >= :today THEN 1 ELSE 0 END) AS today
                                  FROM {local_examguard_logs} l
                                  JOIN {user} u ON u.id = l.userid
                                 WHERE $summarywhere", $summaryparams);

$totallogs     = $summary ? (int) $summary->total : 0;
$totalstudents = $summary ? (int) $summary->students : 0;
$totaltoday    = $summary ? (int) $summary->today : 0;

// Helper to render mm:ss or hh:mm:ss for active remaining seconds.
$formatremaining = function(int $secs): string {
    $secs = max(0, $secs);
    $h = (int) floor($secs / 3600);
    $m = (int) floor(($secs % 3600) / 60);
    $s = $secs % 60;
    if ($h > 0) {
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%02d:%02d', $m, $s);
};

// Build Top Panel: "Sedang Terkunci Saat Ini" (Active Locks Panel).
$activepanel = html_writer::start_div('card mb-4 examguard-active-locks-panel');
$activepanel .= html_writer::start_div('card-header d-flex flex-wrap justify-content-between align-items-center gap-2');

$badgeclass = $totalactivelocks > 0 ? 'badge badge-danger bg-danger text-white ms-2' : 'badge badge-secondary bg-secondary text-white ms-2';
$activepanel .= html_writer::div(
    html_writer::tag('strong', get_string('activelocks_heading', 'local_examguard'), ['class' => 'h5 mb-0'])
    . ' '
    . html_writer::span(get_string('activelocks_badge', 'local_examguard', $totalactivelocks), $badgeclass),
    'd-flex align-items-center flex-wrap'
);

if ($canmanage && $totalactivelocks > 0) {
    $bulkconfirm = get_string('resetall_confirm', 'local_examguard', $totalactivelocks);
    $bulkform = html_writer::start_tag('form', [
        'method'   => 'post',
        'action'   => new moodle_url('/local/examguard/index.php'),
        'class'    => 'd-inline mb-0',
        'onsubmit' => 'return confirm(' . json_encode($bulkconfirm) . ');',
    ]);
    $bulkform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $bulkform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'resetallactive']);
    if ($cmid > 0) {
        $bulkform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid', 'value' => $cmid]);
    } else if ($courseid > 0) {
        $bulkform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
    }
    if (!$cmid && $quizid > 0) {
        $bulkform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'quizid', 'value' => $quizid]);
    }
    if ($search !== '') {
        $bulkform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'search', 'value' => $search]);
    }
    if ($q !== '') {
        $bulkform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'q', 'value' => $q]);
    }
    $bulkform .= html_writer::empty_tag('input', [
        'type'  => 'submit',
        'class' => 'btn btn-danger btn-sm',
        'value' => get_string('resetall_btn', 'local_examguard'),
    ]);
    $bulkform .= html_writer::end_tag('form');
    $activepanel .= $bulkform;
}
$activepanel .= html_writer::end_div(); // card-header.

$activepanel .= html_writer::start_div('card-body');

// Quick search form (GET, param q) + Refresh button.
$quickform = html_writer::start_tag('form', [
    'method' => 'get',
    'action' => new moodle_url('/local/examguard/index.php'),
    'class'  => 'd-flex flex-wrap align-items-center mb-3 gap-2',
]);
if ($cmid > 0) {
    $quickform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid', 'value' => $cmid]);
} else if ($courseid > 0) {
    $quickform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
}
if (!$cmid && $quizid > 0) {
    $quickform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'quizid', 'value' => $quizid]);
}
if ($search !== '') {
    $quickform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'search', 'value' => $search]);
}
$quickform .= html_writer::empty_tag('input', [
    'type'        => 'text',
    'name'        => 'q',
    'value'       => $q,
    'placeholder' => get_string('activelocks_search_placeholder', 'local_examguard'),
    'class'       => 'form-control form-control-sm me-2 mb-1',
    'style'       => 'max-width:360px;',
    'aria-label'  => get_string('activelocks_search_placeholder', 'local_examguard'),
]);
$quickform .= html_writer::empty_tag('input', [
    'type'  => 'submit',
    'class' => 'btn btn-primary btn-sm me-2 mb-1',
    'value' => get_string('activelocks_search_btn', 'local_examguard'),
]);
$quickform .= html_writer::link(
    $pageurl,
    get_string('activelocks_refresh', 'local_examguard'),
    ['class' => 'btn btn-outline-secondary btn-sm me-2 mb-1']
);
if ($q !== '') {
    $clearqparams = $stateparams;
    unset($clearqparams['q']);
    $quickform .= html_writer::link(
        new moodle_url('/local/examguard/index.php', $clearqparams),
        get_string('filterreset', 'local_examguard'),
        ['class' => 'btn btn-link btn-sm mb-1']
    );
}
$quickform .= html_writer::end_tag('form');
$activepanel .= $quickform;

if (empty($activelocks)) {
    $activepanel .= html_writer::div(
        get_string('activelocks_empty', 'local_examguard'),
        'text-muted mb-0'
    );
} else {
    $activepanel .= html_writer::start_div('table-responsive');
    $activepanel .= html_writer::start_tag('table', ['class' => 'table table-sm table-striped table-hover mb-0']);
    $activepanel .= html_writer::start_tag('thead');
    $activepanel .= html_writer::start_tag('tr');
    $activepanel .= html_writer::tag('th', get_string('th_student', 'local_examguard'));
    if (!$cmid) {
        $activepanel .= html_writer::tag('th', get_string('th_quiz', 'local_examguard'));
    }
    $activepanel .= html_writer::tag('th', get_string('th_remaining', 'local_examguard'));
    $activepanel .= html_writer::tag('th', get_string('th_totalviolations', 'local_examguard'));
    $activepanel .= html_writer::tag('th', get_string('th_frozen', 'local_examguard'));
    if ($canmanage) {
        $activepanel .= html_writer::tag('th', get_string('th_actions', 'local_examguard'));
    }
    $activepanel .= html_writer::end_tag('tr');
    $activepanel .= html_writer::end_tag('thead');
    $activepanel .= html_writer::start_tag('tbody');

    foreach ($activelocks as $lock) {
        $studentname = fullname($lock);
        $meta = s($lock->username);
        if (!empty($lock->idnumber)) {
            $meta .= ' &middot; ' . s($lock->idnumber);
        }
        $studentcell = html_writer::link(
            new moodle_url('/user/profile.php', ['id' => (int) $lock->userid]),
            s($studentname),
            ['class' => 'font-weight-bold fw-bold']
        ) . html_writer::div($meta, 'examguard-muted');

        $remainingcell = html_writer::span(
            $formatremaining((int) $lock->remaining),
            'examguard-countdown-live',
            ['data-examguard-until' => (int) $lock->penaltyuntil]
        );

        $frozencell = ((int) $lock->frozenseconds > 0) ? format_time((int) $lock->frozenseconds) : '-';

        $activepanel .= html_writer::start_tag('tr');
        $activepanel .= html_writer::tag('td', $studentcell);
        if (!$cmid) {
            $quizlink = !empty($lock->cmid)
                ? html_writer::link(new moodle_url('/local/examguard/index.php', ['cmid' => (int) $lock->cmid]),
                    format_string($lock->quizname))
                : format_string($lock->quizname);
            $activepanel .= html_writer::tag('td', $quizlink);
        }
        $activepanel .= html_writer::tag('td', $remainingcell);
        $activepanel .= html_writer::tag('td', (string) (int) $lock->violationcount);
        $activepanel .= html_writer::tag('td', $frozencell);

        if ($canmanage) {
            $confirmmsg = get_string('resetpenalty_confirm', 'local_examguard', $studentname);
            $rowform = html_writer::start_tag('form', [
                'method'   => 'post',
                'action'   => new moodle_url('/local/examguard/index.php'),
                'class'    => 'd-inline',
                'onsubmit' => 'return confirm(' . json_encode($confirmmsg) . ');',
            ]);
            $rowform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $rowform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'resetpenalty']);
            $rowform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => (int) $lock->attemptid]);
            $rowform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => (int) $lock->userid]);
            if ($cmid > 0) {
                $rowform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid', 'value' => $cmid]);
            } else if ($courseid > 0) {
                $rowform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
            }
            if (!$cmid && $quizid > 0) {
                $rowform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'quizid', 'value' => $quizid]);
            }
            if ($search !== '') {
                $rowform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'search', 'value' => $search]);
            }
            if ($q !== '') {
                $rowform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'q', 'value' => $q]);
            }
            $rowform .= html_writer::empty_tag('input', [
                'type'  => 'submit',
                'class' => 'btn btn-warning btn-sm',
                'value' => get_string('resetpenalty', 'local_examguard'),
            ]);
            $rowform .= html_writer::end_tag('form');
            $activepanel .= html_writer::tag('td', $rowform);
        }
        $activepanel .= html_writer::end_tag('tr');
    }

    $activepanel .= html_writer::end_tag('tbody');
    $activepanel .= html_writer::end_tag('table');
    $activepanel .= html_writer::end_div(); // table-responsive.
}

$activepanel .= html_writer::end_div(); // card-body.
$activepanel .= html_writer::end_div(); // card.

// Filter form for historical logs table.
$filterhtml = html_writer::start_tag('form', [
    'method' => 'get',
    'action' => new moodle_url('/local/examguard/index.php'),
    'class'  => 'examguard-report-filters mb-3',
]);
$filterhtml .= html_writer::start_div('d-flex flex-wrap align-items-end');

if ($cmid > 0) {
    $filterhtml .= html_writer::empty_tag('input', [
        'type' => 'hidden', 'name' => 'cmid', 'value' => $cmid,
    ]);
} else if (!$courseid) {
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

if ($q !== '') {
    $filterhtml .= html_writer::empty_tag('input', [
        'type' => 'hidden', 'name' => 'q', 'value' => $q,
    ]);
}

$filterhtml .= html_writer::start_div('me-2 mb-2');
$filterhtml .= html_writer::label(get_string('filterstudent', 'local_examguard'), 'examguard-search',
    true, ['class' => 'd-block']);
$filterhtml .= html_writer::empty_tag('input', [
    'type'  => 'text',
    'name'  => 'search',
    'id'    => 'examguard-search',
    'value' => $search,
    'class' => 'form-control',
]);
$filterhtml .= html_writer::end_div();

$filterhtml .= html_writer::empty_tag('input', [
    'type'  => 'submit',
    'class' => 'btn btn-primary mb-2 me-2',
    'value' => get_string('filterapply', 'local_examguard'),
]);
$filterhtml .= html_writer::link(
    new moodle_url('/local/examguard/index.php', $baseparams),
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

// Lightweight live countdown script for the Active Locks table (vanilla ES5).
$servernow = time();
$countdownjs = "(function(){"
    . "var nodes=document.querySelectorAll('[data-examguard-until]');"
    . "if(!nodes.length){return;}"
    . "var offset=" . $servernow . "-Math.floor(new Date().getTime()/1000);"
    . "function pad(n){return n<10?'0'+n:String(n);}"
    . "function fmt(s){"
    . "s=Math.max(0,Math.floor(s));"
    . "var h=Math.floor(s/3600),m=Math.floor((s%3600)/60),r=s%60;"
    . "return h>0?pad(h)+':'+pad(m)+':'+pad(r):pad(m)+':'+pad(r);"
    . "}"
    . "function tick(){"
    . "var now=Math.floor(new Date().getTime()/1000)+offset;"
    . "for(var i=0;i<nodes.length;i++){"
    . "var until=parseInt(nodes[i].getAttribute('data-examguard-until'),10)||0;"
    . "nodes[i].textContent=fmt(until-now);"
    . "}"
    . "}"
    . "tick();window.setInterval(tick,1000);"
    . "})();";

echo $OUTPUT->header();
echo html_writer::div(get_string('reportintro', 'local_examguard'), 'alert alert-info');
echo $activepanel;
echo $summaryhtml;
echo html_writer::tag('h4', get_string('historylogs_heading', 'local_examguard'), ['class' => 'mt-3 mb-2']);
echo $filterhtml;
$table->out($perpage, true);
if (!empty($activelocks)) {
    echo html_writer::tag('script', $countdownjs);
}
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
