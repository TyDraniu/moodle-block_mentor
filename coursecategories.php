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

global $CFG, $USER, $SITE, $PAGE, $OUTPUT;

require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/fn_mentor/lib.php');
require_once($CFG->dirroot . '/blocks/fn_mentor/coursecategories_form.php');

require_login(null, false);

// Permission.
require_capability('block/fn_mentor:addinstance', context_system::instance(), $USER->id);

$title = get_string('coursecategories', 'block_fn_mentor');
$heading = $SITE->fullname;

$PAGE->set_url('/blocks/fn_mentor/coursecategories.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_context(context_system::instance());
$PAGE->set_title($title);
$PAGE->set_heading($heading);
$PAGE->set_cacheable(true);

$PAGE->requires->css('/blocks/fn_mentor/css/styles.css');

$PAGE->requires->jquery();
$PAGE->requires->js('/blocks/fn_mentor/js/selection.js');

// Breadcrumb.
$PAGE->navbar->add(get_string('pluginname', 'block_fn_mentor'));
$PAGE->navbar->add(get_string('coursecategories', 'block_fn_mentor'));

$parameters = array();
$configcategory = get_config('block_fn_mentor', 'category');
$configcourse = get_config('block_fn_mentor', 'course');

$parameters = array(
    'course' => $configcourse,
    'category' => $configcategory
);

$mform = new coursecategory_form(null, $parameters, 'post', '', array('id' => 'notification_form', 'class' => 'notification_form'));

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/admin/settings.php', array('section' => 'blocksettingfn_mentor')),
        get_string('successful', 'block_fn_mentor'));
} else if ($fromform = $mform->get_data()) {
    set_config('category', '',  'block_fn_mentor');
    set_config('course', '',  'block_fn_mentor');

    foreach ($_POST as $key => $value) {
        if (strpos($key, "category_") === 0) {
            if (isset($value)) {
                $fromform->category[] = optional_param('category_'.$value, 0, PARAM_INT);
            }
        } else if (strpos($key, "course_") === 0) {
            if ($value <> '0') {
                $fromform->course[] = optional_param('course_'.$value, 0, PARAM_INT);
            }
        } else {
            if (is_array($value)) {
                $fromform->$key = optional_param_array($key, array(), PARAM_RAW);
            } else {
                $fromform->$key = optional_param($key, '', PARAM_RAW);
            }
        }
    }

    if (isset($fromform->category)) {
        $fromform->category = implode(',', $fromform->category);
        set_config('category', $fromform->category,  'block_fn_mentor');
    }

    if (isset($fromform->course)) {
        $fromform->course = implode(',', $fromform->course);
        set_config('course', $fromform->course,  'block_fn_mentor');
    }
    redirect(new moodle_url('/admin/settings.php', array('section' => 'blocksettingfn_mentor')),
        get_string('successful', 'block_fn_mentor'));
    die;
}
echo $OUTPUT->header();

$currenttab = 'activecategories';
require('tabs.php');

echo html_writer::div(
    get_string('markinmanagerscoursecatsdesc', 'block_fn_mentor'), 'fn_mentor-category-selection-desc'
);
$mform->display();
echo $OUTPUT->footer();