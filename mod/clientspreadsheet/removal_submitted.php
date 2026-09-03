<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * User-removal request confirmation page.
 *
 * @package    mod_clientspreadsheet
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$removalid = required_param('removal', PARAM_INT);

$cm = get_coursemodule_from_id('clientspreadsheet', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$clientspreadsheet = $DB->get_record('clientspreadsheet', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
$removal = $DB->get_record('clientspreadsheet_removal', [
    'id' => $removalid,
    'clientspreadsheetid' => $clientspreadsheet->id,
], '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/clientspreadsheet:view', $context);

if ((int) $removal->userid !== (int) $USER->id && !is_siteadmin()) {
    throw new required_capability_exception($context, 'moodle/site:config', 'nopermissions', '');
}

$url = new moodle_url('/mod/clientspreadsheet/removal_submitted.php', ['id' => $cm->id, 'removal' => $removal->id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($clientspreadsheet->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('removalrequestedheading', 'clientspreadsheet'));
echo $OUTPUT->notification(get_string('removalrequestedmessage', 'clientspreadsheet'), 'success');

echo html_writer::div(
    html_writer::link(
        new moodle_url('/mod/clientspreadsheet/view.php', ['id' => $cm->id]),
        get_string('backtoactivity', 'clientspreadsheet'),
        ['class' => 'btn btn-primary']
    ) . ' ' .
    html_writer::link(
        new moodle_url('/course/view.php', ['id' => $course->id]),
        get_string('returntocourse', 'clientspreadsheet'),
        ['class' => 'btn btn-secondary']
    ),
    'clientspreadsheet-confirm-actions'
);

echo $OUTPUT->footer();
