<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * View page for the Client spreadsheet activity module.
 *
 * @package    mod_clientspreadsheet
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('clientspreadsheet', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$clientspreadsheet = $DB->get_record('clientspreadsheet', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/clientspreadsheet:view', $context);

$url = new moodle_url('/mod/clientspreadsheet/view.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($clientspreadsheet->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$customdata = [
    'context' => $context,
    'course' => $course,
    'instance' => $clientspreadsheet,
];
$mform = new \mod_clientspreadsheet\form\upload_form($url, $customdata);
$validationerrors = [];

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $course->id]));
} else if ($data = $mform->get_data()) {
    require_capability('mod/clientspreadsheet:submit', $context);

    $draftfile = \mod_clientspreadsheet\local\spreadsheet_helper::get_draft_file((int) $data->spreadsheet);
    if (!$draftfile) {
        $validationerrors[] = get_string('nofileuploaded', 'clientspreadsheet');
    } else {
        $columns = \mod_clientspreadsheet\local\spreadsheet_helper::get_required_columns($clientspreadsheet);
        $validator = new \mod_clientspreadsheet\local\validator();
        $result = $validator->validate($draftfile, $columns);

        if (!$result->valid) {
            $validationerrors = $result->errors;
        } else {
            $transaction = $DB->start_delegated_transaction();
            $time = time();
            $submission = (object) [
                'clientspreadsheetid' => $clientspreadsheet->id,
                'course' => $course->id,
                'userid' => $USER->id,
                'filename' => $draftfile->get_filename(),
                'filesize' => $draftfile->get_filesize(),
                'mimetype' => $draftfile->get_mimetype(),
                'status' => \mod_clientspreadsheet\local\spreadsheet_helper::STATUS_SUBMITTED,
                'validationmessage' => get_string('validationpassedmessage', 'clientspreadsheet', $result->rowcount),
                'reviewerid' => 0,
                'timereviewed' => 0,
                'timecreated' => $time,
                'timemodified' => $time,
            ];
            $submission->id = $DB->insert_record('clientspreadsheet_submission', $submission);

            $options = \mod_clientspreadsheet\local\spreadsheet_helper::get_file_options($course);
            file_save_draft_area_files(
                (int) $data->spreadsheet,
                $context->id,
                'mod_clientspreadsheet',
                'submission',
                $submission->id,
                $options
            );

            $transaction->allow_commit();
            \mod_clientspreadsheet\local\spreadsheet_helper::send_submission_notification(
                $clientspreadsheet,
                $course,
                $cm,
                $submission
            );

            redirect(new moodle_url('/mod/clientspreadsheet/submitted.php', [
                'id' => $cm->id,
                'submission' => $submission->id,
            ]));
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($clientspreadsheet->name));

if (trim($clientspreadsheet->intro ?? '') !== '') {
    echo $OUTPUT->box(
        format_module_intro('clientspreadsheet', $clientspreadsheet, $cm->id),
        'generalbox mod_introbox',
        'clientspreadsheetintro'
    );
}

if (is_siteadmin()) {
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/mod/clientspreadsheet/submissions.php', ['id' => $cm->id]),
            get_string('viewsubmissions', 'clientspreadsheet'),
            ['class' => 'btn btn-secondary']
        ),
        'clientspreadsheet-manage-link'
    );
}

if (!empty($validationerrors)) {
    echo $OUTPUT->notification(get_string('validationfailed', 'clientspreadsheet'), 'error');
    echo html_writer::alist(array_map('s', $validationerrors), ['class' => 'clientspreadsheet-error-list']);
}

echo html_writer::start_div('clientspreadsheet-layout');

echo html_writer::start_div('clientspreadsheet-panel clientspreadsheet-upload-panel');
echo $OUTPUT->heading(get_string('uploadspreadsheet', 'clientspreadsheet'), 3);
if (has_capability('mod/clientspreadsheet:submit', $context)) {
    $mform->display();
} else {
    echo $OUTPUT->notification(get_string('nopermissiontosubmit', 'clientspreadsheet'), 'warning');
}
echo html_writer::end_div();

echo html_writer::start_div('clientspreadsheet-panel clientspreadsheet-example-panel');
echo $OUTPUT->heading(get_string('examplespreadsheet', 'clientspreadsheet'), 3);
echo \mod_clientspreadsheet\local\spreadsheet_helper::render_example_table($clientspreadsheet);
echo html_writer::div(
    html_writer::link(
        new moodle_url('/mod/clientspreadsheet/template.php', ['id' => $cm->id]),
        get_string('downloadexample', 'clientspreadsheet'),
        ['class' => 'btn btn-primary']
    ),
    'clientspreadsheet-download'
);
echo html_writer::end_div();

echo html_writer::end_div();

echo $OUTPUT->footer();
