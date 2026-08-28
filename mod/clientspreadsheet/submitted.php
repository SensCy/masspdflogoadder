<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Submission confirmation page for the Client spreadsheet activity module.
 *
 * @package    mod_clientspreadsheet
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$submissionid = optional_param('submission', 0, PARAM_INT);

$cm = get_coursemodule_from_id('clientspreadsheet', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$clientspreadsheet = $DB->get_record('clientspreadsheet', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/clientspreadsheet:view', $context);

if ($submissionid) {
    $submission = $DB->get_record('clientspreadsheet_submission', [
        'id' => $submissionid,
        'clientspreadsheetid' => $clientspreadsheet->id,
    ], '*', MUST_EXIST);

    if ((int) $submission->userid !== (int) $USER->id && !is_siteadmin()) {
        throw new required_capability_exception($context, 'moodle/site:config', 'nopermissions', '');
    }
}

$url = new moodle_url('/mod/clientspreadsheet/submitted.php', ['id' => $cm->id, 'submission' => $submissionid]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($clientspreadsheet->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('submittedheading', 'clientspreadsheet'));
echo $OUTPUT->notification(get_string('submittedmessage', 'clientspreadsheet'), 'success');

echo html_writer::div(
    html_writer::link(
        new moodle_url('/mod/clientspreadsheet/view.php', ['id' => $cm->id]),
        get_string('submitanother', 'clientspreadsheet'),
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
