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
 * @package    block_fn_mentor
 * @copyright  Michael Gardener <mgardener@cissq.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

global $CFG, $COURSE, $USER, $DB, $SITE, $PAGE, $OUTPUT;

require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/fn_mentor/lib.php');
require_once($CFG->dirroot . '/notes/lib.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/grade/lib.php');

// Parameters.
$menteeid = optional_param('menteeid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$groupid  = optional_param('groupid', 0, PARAM_INT);

// Array of functions to call for grading purposes for modules.
$modgradesarray = array(
    'assign' => 'assign.submissions.fn.php',
    'quiz' => 'quiz.submissions.fn.php',
    'assignment' => 'assignment.submissions.fn.php',
    'forum' => 'forum.submissions.fn.php',
);

require_login(null, false);

// PERMISSION.
$isadmin   = has_capability('block/fn_mentor:manageall', context_system::instance());
$ismentor  = block_fn_mentor_has_system_role($USER->id, get_config('block_fn_mentor', 'mentor_role_system'));
$isteacher = block_fn_mentor_isteacherinanycourse($USER->id);
$isstudent = block_fn_mentor_isstudentinanycourse($USER->id);

$allownotes = get_config('block_fn_mentor', 'allownotes');

if ($allownotes && $ismentor) {
    $allownotes = true;
} else if ($isadmin || $isteacher ) {
    $allownotes = true;
} else {
    $allownotes = false;
}


// Find Mentees.
$mentees = array();
if ($isadmin) {
    $mentees = block_fn_mentor_get_all_mentees('', $groupid);
} else if ($isteacher) {
    if ($menteesbymentor = block_fn_mentor_get_mentees_by_mentor(0, $filter = 'teacher')) {
        foreach ($menteesbymentor as $menteebymentor) {
            if ($menteebymentor['mentee']) {
                foreach ($menteebymentor['mentee'] as $key => $value) {
                    $mentees[$key] = $value;
                }
            }
        }
    }
} else if ($ismentor) {
    $mentees = block_fn_mentor_get_mentees($USER->id, 0, '', $groupid);
}
// Pick a mentee if not selected.
if ($mentees) {
    if (!$menteeid || !in_array($menteeid, array_keys($mentees))) {
        $var = reset($mentees);
        $menteeid = $var->studentid;
    }
}

if (($USER->id <> $menteeid) && !$isadmin && !in_array($menteeid, array_keys($mentees))) {
    print_error('invalidpermission', 'block_fn_mentor');
}
if (($isstudent) && ($USER->id <> $menteeid)  && (!$isteacher && !$isadmin && !$ismentor)) {
    print_error('invalidpermission', 'block_fn_mentor');
}

$menteeuser = $DB->get_record('user', array('id' => $menteeid), '*', MUST_EXIST);

$title = get_string('pluginname', 'block_fn_mentor');
$heading = $SITE->fullname;

$PAGE->set_url('/blocks/fn_mentor/course_overview.php');

if ($pagelayout = get_config('block_fn_mentor', 'pagelayout')) {
    $PAGE->set_pagelayout($pagelayout);
} else {
    $PAGE->set_pagelayout('course');
}

$PAGE->set_context(context_system::instance());
$PAGE->set_title($title);
$PAGE->set_heading($heading);
$PAGE->set_cacheable(true);
$PAGE->requires->css('/blocks/fn_mentor/css/styles.css');

$PAGE->navbar->add(get_string('pluginname', 'block_fn_mentor'),
    new moodle_url('/blocks/fn_mentor/course_overview.php', array('menteeid' => $menteeid))
);

echo $OUTPUT->header();

echo '<div id="mentee-course-overview-page">';
// LEFT.
echo '<div id="mentee-course-overview-left">';

$lastaccess = '';
if ($menteeuser->lastaccess) {
    $lastaccess .= get_string('lastaccess').get_string('labelsep', 'langconfig').
        block_fn_mentor_format_time(time() - $menteeuser->lastaccess);
} else {
    $lastaccess .= get_string('lastaccess').get_string('labelsep', 'langconfig').get_string('never');
}

// Groups menu.
if ($isadmin) {
    $groups = $DB->get_records('block_fn_mentor_group', null, 'name ASC');
} else if ($ismentor) {
    $sql = "SELECT g.id, g.name
              FROM {block_fn_mentor_group} g
              JOIN {block_fn_mentor_group_mem} gm
                ON g.id = gm.groupid
             WHERE gm.role = ?
               AND gm.userid = ?
          ORDER BY g.name ASC";
    $groups = $DB->get_records_sql($sql, array('M', $USER->id));
}

$groupmenu = array();
$groupmenuurl = array();
$groupmenuhtml = '';

$groupmenuurl[0] = new moodle_url('/blocks/fn_mentor/course_overview.php', ['menteeid' => $menteeid, 'groupid' => 0]);
$groupmenu[$groupmenuurl[0]->out(false)] = get_string('allmentorgroups', 'block_fn_mentor');

if (!empty($groups)) {
    foreach ($groups as $group) {
        $groupmenuurl[$group->id] = new moodle_url('/blocks/fn_mentor/course_overview.php',
            ['menteeid' => $menteeid, 'groupid' => $group->id]);
        $groupmenu[$groupmenuurl[$group->id]->out(false)] = $group->name;
    }

    if ((!$isstudent) || ($isadmin || $ismentor)) {
        $groupmenuhtml = html_writer::tag('form',
            html_writer::img($OUTPUT->image_url('i/group'), get_string('group', 'block_fn_mentor')) . ' ' .
            html_writer::select(
                $groupmenu, 'groupfilter', $groupmenuurl[$groupid]->out(false), null,
                array('onChange' => 'location=document.jump2.groupfilter.options[document.jump2.groupfilter.selectedIndex].value;')
            ),
            array('id' => 'groupFilterForm', 'name' => 'jump2')
        );

        $groupmenuhtml = html_writer::div($groupmenuhtml, "mentee-course-overview-block-filter");
    }
}

// Student menu.
$studentmenu = array();
$studentmenuurl = array();


if ($showallstudents = get_config('block_fn_mentor', 'showallstudents')) {
    $studentmenuurl[0] = $CFG->wwwroot . '/blocks/fn_mentor/all_students.php';
    $studentmenu[$studentmenuurl[0]] = get_string('allstudents', 'block_fn_mentor');
}

if ($mentees) {
    foreach ($mentees as $mentee) {
        $studentmenuurl[$mentee->studentid] = new moodle_url('/blocks/fn_mentor/course_overview.php',
            ['menteeid' => $mentee->studentid, 'groupid' => $groupid]);
        $studentmenu[$studentmenuurl[$mentee->studentid]->out(false)] = $mentee->firstname .' '.$mentee->lastname;
    }
}

$studentmenuhtml = '';

if ((!$isstudent) || ($isadmin || $ismentor  || $isteacher)) {
    $studentmenuhtml = html_writer::tag('form',
        html_writer::img($OUTPUT->image_url('i/user'), get_string('user')).' '.
        html_writer::select(
            $studentmenu, 'studentfilter', $studentmenuurl[$menteeid]->out(false), null,
            array('onChange' => 'location=document.jump1.studentfilter.options[document.jump1.studentfilter.selectedIndex].value;')
        ),
        array('id' => 'studentFilterForm', 'name' => 'jump1')
    );

    $studentmenuhtml = '<div class="mentee-course-overview-block-filter">'.$studentmenuhtml.'</div>';
}

// BLOCK-1.
echo $groupmenuhtml.$studentmenuhtml.'
      <div class="mentee-course-overview-block">
          <div class="mentee-course-overview-block-title">
              '.get_string('student', 'block_fn_mentor').'
          </div>
          <div class="mentee-course-overview-block-content">'.
    $OUTPUT->container($OUTPUT->user_picture($menteeuser, array('courseid' => $COURSE->id)), "userimage").
    $OUTPUT->container(block_fn_mentor_render_link_with_window(new moodle_url('/user/profile.php', ['id' => $menteeuser->id]),
            fullname($menteeuser, true)).
        '&nbsp;&nbsp;'.
    html_writer::link(new moodle_url('/message/index.php', ['id' => $menteeuser->id]),
        html_writer::img($OUTPUT->image_url('email', 'block_fn_mentor'), 'email')), ['class' => 'userfullname']).
    '<span class="mentee-lastaccess">'.$lastaccess.'</span>' .
    '</div></div>';

// COURSES.
if (!$enrolledcourses = enrol_get_all_users_courses($menteeid, false, 'id,fullname,shortname', 'fullname ASC')) {
    $enrolledcourses = array();
}

$filtercourses = array();

if ($configcategory = get_config('block_fn_mentor', 'category')) {

    $selectedcategories = explode(',', $configcategory);

    foreach ($selectedcategories as $categoryid) {

        if ($parentcatcourses = $DB->get_records('course', array('category' => $categoryid))) {
            foreach ($parentcatcourses as $catcourse) {
                $filtercourses[] = $catcourse->id;
            }
        }
        if ($categorystructure = block_fn_mentor_get_course_category_tree($categoryid)) {
            foreach ($categorystructure as $category) {

                if ($category->courses) {
                    foreach ($category->courses as $subcatcourse) {
                        $filtercourses[] = $subcatcourse->id;
                    }
                }
                if ($category->categories) {
                    foreach ($category->categories as $subcategory) {
                        block_fn_mentor_get_selected_courses($subcategory, $filtercourses);
                    }
                }
            }
        }
    }
}

if ($configcourse = get_config('block_fn_mentor', 'course')) {
    $selectedcourses = explode(',', $configcourse);
    $filtercourses = array_merge($filtercourses, $selectedcourses);
}

if ($enrolledcourses && $filtercourses) {
    foreach ($enrolledcourses as $key => $enrolledcourse) {
        if (!in_array($enrolledcourse->id, $filtercourses)) {
            unset($enrolledcourses[$key]);
        }
    }
}

$courseids = implode(",", array_keys($enrolledcourses));

$courselist = "";

if ($courseid == 0) {
    $courselist .= '<div class="allcourses active">'.
        '<a href="'.$CFG->wwwroot.'/blocks/fn_mentor/course_overview.php?menteeid='.$menteeid.'&courseid=0">'.
        get_string('allcourses', 'block_fn_mentor').'</a></div>';
} else {
    $courselist .= '<div class="allcourses">'.
        '<a href="'.$CFG->wwwroot.'/blocks/fn_mentor/course_overview.php?menteeid='.$menteeid.'&courseid=0">'.
        get_string('allcourses', 'block_fn_mentor').'</a></div>';
}
foreach ($enrolledcourses as $enrolledcourse) {
       $coursefullname = format_string($enrolledcourse->fullname); // Allow mlang filters to process language strings.
    if ($courseid == $enrolledcourse->id) {
        $courselist .= '<div class="courselist active">
            <img class="mentees-course-bullet" src="'.$CFG->wwwroot.'/blocks/fn_mentor/pix/b.gif">'.
            '<a href="'.$CFG->wwwroot.'/blocks/fn_mentor/course_overview_single.php?menteeid='.
            $menteeid.'&courseid='.$enrolledcourse->id.'">'.$coursefullname.'</a></div>';
    } else {
        $courselist .= '<div class="courselist">
            <img class="mentees-course-bullet" src="'.$CFG->wwwroot.'/blocks/fn_mentor/pix/b.gif">'.
            '<a href="'.$CFG->wwwroot.'/blocks/fn_mentor/course_overview_single.php?menteeid='.
            $menteeid.'&courseid='.$enrolledcourse->id.'">'.$coursefullname.'</a></div>';
    }
}

echo '<div class="mentee-course-overview-block">
          <div class="mentee-course-overview-block-title">
              '.get_string('courses', 'block_fn_mentor').'
          </div>
          <div class="mentee-course-overview-block-content">

              '.$courselist.'
          </div>
      </div>';

// NOTES.
if ($view = has_capability('block/fn_mentor:viewcoursenotes', context_system::instance()) && $allownotes) {
    echo '<div class="mentee-course-overview-block">
              <div class="mentee-course-overview-block-title">
                  '.get_string('notes', 'block_fn_mentor').'
              </div>
              <div class="fz_popup_wrapper">'.
        block_fn_mentor_render_link_with_window(new moodle_url('/notes/index.php', ['user' => $menteeuser->id]),
                  html_writer::img($OUTPUT->image_url('popup_icon', 'block_fn_mentor'), '')).
              '</div>'.
              '<div class="mentee-course-overview-block-content">';

    // COURSE NOTES.
    if ($courseids && $view) {
        $sqlnotes = "SELECT p.*, c.fullname
                                FROM {post} p
                          INNER JOIN {course} c
                                  ON p.courseid = c.id
                               WHERE p.module = 'notes'
                                 AND p.userid = ?
                                 AND p.courseid IN ($courseids)
                                 AND p.publishstate IN ('site', 'public')
                            ORDER BY p.lastmodified DESC";

        if ($notes = $DB->get_records_sql($sqlnotes, array($menteeuser->id), 0, 3)) {
            foreach ($notes as $note) {
                $ccontext = context_course::instance($note->courseid);
                $cfullname = format_string($note->fullname, true, array('context' => $ccontext));
                $header = '<h3 class="notestitle"><a href="' . $CFG->wwwroot .
                    '/course/view.php?id=' . $note->courseid . '">' . $cfullname . '</a></h3>';
                echo $header;
                block_fn_mentor_note_print($note, NOTES_SHOW_FULL);
            }
            // Show all notes.
            echo block_fn_mentor_render_link_with_window(new moodle_url('/notes/index.php', ['user' => $menteeuser->id]),
                get_string('show_all_notes', 'block_fn_mentor'));
        } else {
            // Add a note.
            echo block_fn_mentor_render_link_with_window(new moodle_url('/notes/index.php', ['user' => $menteeuser->id]),
                get_string('add_a_note', 'block_fn_mentor'));
        }
    }

    echo     '</div>
          </div>';
}
echo '</div>';

// CENTER.
echo '<div id="mentee-course-overview-center">';

if ($enrolledcourses) {

    foreach ($enrolledcourses as $enrolledcourse) {
        $coursefullname = format_string($enrolledcourse->fullname); // Allow mlang filters to process language strings.

        if ($courseid && ($courseid <> $enrolledcourse->id)) {
            continue;
        }

        $course = $DB->get_record('course', array('id' => $enrolledcourse->id), '*', MUST_EXIST);

        echo '<div class="mentee-course-overview-center_course">';

        $context = context_course::instance($course->id);

        $progressdata = block_fn_mentor_activity_progress($course, $menteeid, $modgradesarray);

        $progresshtml = '';

        foreach ($progressdata->content->items as $key => $value) {
            $progresshtml .= '<div class="overview-progress-list">' . $progressdata->content->icons[$key] .
                $progressdata->content->items[$key] . '</div>';
        }

        echo '<table class="mentee-course-overview-center_table block">';
        echo '<tr>';

        echo '<td valign="top" class="mentee-grey-border">';
        echo html_writer::div(
            block_fn_mentor_render_link_with_window(new moodle_url('/course/view.php', ['id' => $enrolledcourse->id]),
            $coursefullname),
            'overview-course coursetitle');

        echo '<div class="overview-teacher">';
        echo '<table class="mentee-teacher-table">';
        // Course teachers.
        $sqltecher = "SELECT u.id,
                             u.firstname,
                             u.lastname,
                             u.lastaccess
                        FROM {context} ctx
                  INNER JOIN {role_assignments} ra
                          ON ctx.id = ra.contextid
                  INNER JOIN {user} u
                          ON ra.userid = u.id
                       WHERE ctx.contextlevel = ?
                         AND ra.roleid = ?
                         AND ctx.instanceid = ?";

        if ($teachers = $DB->get_records_sql($sqltecher, array(50, 3, $course->id))) {
            $numofteachers = count($teachers);
            $teacherlist = '';

            foreach ($teachers as $teacher) {
                $lastaccess = get_string('lastaccess') . get_string('labelsep', 'langconfig') .
                    block_fn_mentor_format_time(time() - $teacher->lastaccess);
                $teacherlist .= block_fn_mentor_teacher_link ($teacher->id, $lastaccess);
            }
            if ($numofteachers > 1) {
                $teacherlabel = get_string('teachers', 'block_fn_mentor');
            } else {
                $teacherlabel = get_string('teacher', 'block_fn_mentor');
            }

            echo '<tr><td class="mentee-teacher-table-label"><span>' . $teacherlabel . ': </span></td><td>';

            echo $teacherlist;
            echo '</td></tr>';

        }
        echo '</table>';
        echo '</div>';

        echo '<div class="overview-mentor">';
        echo '<table class="mentee-teacher-table">';
        if ($mentors = block_fn_mentor_get_mentors($menteeuser->id)) {
            $numofmentors = count($mentors);
            $mentorlist = '';
            $mentorlabel = (get_config('mentor', 'blockname')) ? get_config('mentor',
                'blockname') : get_string('mentor', 'block_fn_mentor');

            foreach ($mentors as $mentor) {
                $lastaccess = get_string('lastaccess') . get_string('labelsep', 'langconfig') .
                    block_fn_mentor_format_time(time() - $mentor->lastaccess);
                $mentorlist .= block_fn_mentor_teacher_link($mentor->mentorid, $lastaccess);
            }

            if ($numofmentors > 1) {
                $mentorlabel = (get_config('mentor', 'blockname')) ? get_config('mentor',
                    'blockname') : get_string('mentors', 'block_fn_mentor');
            } else {
                $mentorlabel = (get_config('mentor', 'blockname')) ? get_config('mentor',
                    'blockname') : get_string('mentor', 'block_fn_mentor');
            }

            echo '<tr><td class="mentee-teacher-table-label" valign="top"><span>';

            echo $mentorlabel . ': ';
            echo '</span></td><td valign="top">';
            echo $mentorlist;

            echo '</td></tr>';
        }
        echo '</table>';
        echo '</div>';
        echo '</td>';
        // Progress.
        $completedcourseclass = '';
        if ($progressdata->timecompleted) {
            $completedcourseclass = 'completed';
        }
        echo '<td valign="top" style="height: 100%;" class="mentee-blue-border">';

        echo '<table style="height: 100%; width: 100%;">';
        echo '<tr>';
        echo '<td class="overview-progress blue">';
        echo get_string('progress', 'block_fn_mentor');
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        if ($progressdata->timecompleted) {
            echo '<td class="vertical-textd completed" valign="middle">';
            echo get_string('completedon', 'block_fn_mentor', date('d M Y', $progressdata->timecompleted));
        } else {
            echo '<td class="vertical-textd" valign="middle">';
            echo $progresshtml;
        }
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        echo '</td>';
        // Grade.
        echo '<td valign="top" style="height: 100%;" class="mentee-blue-border '.$completedcourseclass.'">';

        echo '<table style="height: 100%; width: 100%;">';

        echo '<tr>';
        echo '<td class="overview-progress blue">';
        echo get_string('grade', 'block_fn_mentor');
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        if ($progressdata->timecompleted) {
            echo '<td class="vertical-textd completed" valign="middle">';
            $gradesummary = block_fn_mentor_grade_summary($menteeuser->id, $course->id);
            echo '<div class="overview-grade-completed green">'.$gradesummary->courseaverage.'%</div>';
        } else {
            echo '<td class="vertical-textd" valign="middle">';
            echo block_fn_mentor_print_grade_summary($course->id, $menteeuser->id);
        }
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        echo '</td>';

        echo '</tr>';
        echo '</table>';

        echo '</div>'; // Mentee course overview center course.
    }

} else {
    echo get_string('notenrolledanycourse', 'block_fn_mentor');
}

echo '</div>'; // Mentee course overview center.

echo '</div>'; // Mentee course overview page.

echo block_fn_mentor_footer();

echo $OUTPUT->footer();