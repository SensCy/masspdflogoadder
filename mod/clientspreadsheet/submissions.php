<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Admin submission list for the Client spreadsheet activity module.
 *
 * @package    mod_clientspreadsheet
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$submissionid = optional_param('submission', 0, PARAM_INT);

$cm = get_coursemodule_from_id('clientspreadsheet', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$clientspreadsheet = $DB->get_record('clientspreadsheet', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);

if (!is_siteadmin()) {
    throw new required_capability_exception($context, 'moodle/site:config', 'nopermissions', '');
}

$url = new moodle_url('/mod/clientspreadsheet/submissions.php', ['id' => $cm->id]);

if ($action !== '') {
    require_sesskey();

    if (!in_array($action, ['confirmcomplete', 'complete'], true)) {
        throw new moodle_exception('invalidaction');
    }

    $submission = $DB->get_record('clientspreadsheet_submission', [
        'id' => $submissionid,
        'clientspreadsheetid' => $clientspreadsheet->id,
    ], '*', MUST_EXIST);

    if ($action === 'confirmcomplete') {
        $PAGE->set_url(new moodle_url($url, [
            'action' => 'confirmcomplete',
            'submission' => $submissionid,
            'sesskey' => sesskey(),
        ]));
        $PAGE->set_title(get_string('confirmcompleteheading', 'clientspreadsheet'));
        $PAGE->set_heading(format_string($course->fullname));
        $PAGE->set_context($context);

        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('confirmcompleteheading', 'clientspreadsheet'));
        echo $OUTPUT->confirm(
            get_string('confirmcompletemessage', 'clientspreadsheet', s($submission->filename)),
            new moodle_url($url, [
                'action' => 'complete',
                'submission' => $submission->id,
                'sesskey' => sesskey(),
            ]),
            $url
        );
        echo $OUTPUT->footer();
        exit;
    }

    $transaction = $DB->start_delegated_transaction();
    get_file_storage()->delete_area_files($context->id, 'mod_clientspreadsheet', 'submission', $submission->id);
    $DB->delete_records('clientspreadsheet_submission', ['id' => $submission->id]);
    $transaction->allow_commit();

    redirect($url, get_string('submissioncompleted', 'clientspreadsheet'));
}

$PAGE->set_url($url);
$PAGE->set_title(get_string('submissions', 'clientspreadsheet'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($clientspreadsheet->name));
echo $OUTPUT->heading(get_string('submissions', 'clientspreadsheet'), 3);

$records = $DB->get_records('clientspreadsheet_submission', [
    'clientspreadsheetid' => $clientspreadsheet->id,
], 'timecreated DESC, id DESC');

if (empty($records)) {
    echo $OUTPUT->notification(get_string('nosubmissions', 'clientspreadsheet'), 'info');
    echo $OUTPUT->single_button(new moodle_url('/mod/clientspreadsheet/view.php', ['id' => $cm->id]), get_string('backtoactivity', 'clientspreadsheet'), 'get');
    echo $OUTPUT->footer();
    exit;
}

$users = \mod_clientspreadsheet\local\spreadsheet_helper::get_submission_users($records);
$table = new html_table();
$table->attributes['class'] = 'generaltable clientspreadsheet-submissions';
$table->head = [
    get_string('submitted', 'clientspreadsheet'),
    get_string('client', 'clientspreadsheet'),
    get_string('filename', 'clientspreadsheet'),
    get_string('actions'),
];

foreach ($records as $record) {
    $user = $users[$record->userid] ?? null;
    $downloadlink = \mod_clientspreadsheet\local\spreadsheet_helper::get_submission_download_link($context, $record);

    $actions = html_writer::link(new moodle_url($url, [
        'action' => 'confirmcomplete',
        'submission' => $record->id,
        'sesskey' => sesskey(),
    ]), get_string('complete', 'clientspreadsheet'), ['class' => 'btn btn-sm btn-success']);

    $table->data[] = [
        userdate($record->timecreated),
        $user ? fullname($user) . html_writer::empty_tag('br') . s($user->email) : get_string('unknownuser', 'clientspreadsheet'),
        $downloadlink,
        $actions,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->single_button(new moodle_url('/mod/clientspreadsheet/view.php', ['id' => $cm->id]), get_string('backtoactivity', 'clientspreadsheet'), 'get');

echo $OUTPUT->footer();
