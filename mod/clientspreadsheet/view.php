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
            $cohortids = \mod_clientspreadsheet\local\spreadsheet_helper::get_user_cohort_ids($USER->id);
            $transaction = $DB->start_delegated_transaction();
            $time = time();
            $submission = (object) [
                'clientspreadsheetid' => $clientspreadsheet->id,
                'course' => $course->id,
                'cohortid' => !empty($cohortids) ? (int) $cohortids[0] : 0,
                'userid' => $USER->id,
                'filename' => $draftfile->get_filename(),
                'filesize' => $draftfile->get_filesize(),
                'mimetype' => $draftfile->get_mimetype(),
                'status' => \mod_clientspreadsheet\local\spreadsheet_helper::STATUS_SUBMITTED,
                'validationmessage' => get_string('validationpassedmessage', 'clientspreadsheet', $result->rowcount),
                'requesteditems' => \mod_clientspreadsheet\local\spreadsheet_helper::encode_requested_items($result->items),
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

$cansubmit = has_capability('mod/clientspreadsheet:submit', $context);
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

$cohortusers = \mod_clientspreadsheet\local\spreadsheet_helper::get_cohort_users_for_user($USER->id);
$pendingremovals = \mod_clientspreadsheet\local\spreadsheet_helper::get_pending_removal_targets(
    $clientspreadsheet->id,
    array_keys($cohortusers)
);
$pendinggroups = \mod_clientspreadsheet\local\spreadsheet_helper::get_pending_requests_for_user($clientspreadsheet, $USER->id);

echo html_writer::start_div('clientspreadsheet-console');

echo html_writer::start_tag('section', ['class' => 'clientspreadsheet-section clientspreadsheet-active-users']);
echo $OUTPUT->heading(get_string('activeusers', 'clientspreadsheet'), 3);
echo html_writer::tag('p', get_string('activeusersintro', 'clientspreadsheet'), ['class' => 'clientspreadsheet-muted']);

if (empty($cohortusers)) {
    echo $OUTPUT->notification(get_string('nocohortusers', 'clientspreadsheet'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable clientspreadsheet-user-table';
    $table->head = [
        get_string('firstname'),
        get_string('lastname'),
        get_string('email'),
        get_string('actions'),
    ];

    foreach ($cohortusers as $cohortuser) {
        if ((int) $cohortuser->id === (int) $USER->id) {
            $action = html_writer::span(get_string('currentuser', 'clientspreadsheet'), 'badge badge-secondary');
        } else if (is_siteadmin($cohortuser->id)) {
            $action = html_writer::span(get_string('admin'), 'badge badge-primary');
        } else if (isset($pendingremovals[$cohortuser->id])) {
            $action = html_writer::span(get_string('removalpending', 'clientspreadsheet'), 'badge badge-warning');
        } else if ($cansubmit) {
            $action = html_writer::link(
                new moodle_url('/mod/clientspreadsheet/remove.php', [
                    'id' => $cm->id,
                    'user' => $cohortuser->id,
                ]),
                get_string('remove'),
                ['class' => 'btn btn-sm btn-outline-danger']
            );
        } else {
            $action = '-';
        }

        $table->data[] = [
            s($cohortuser->firstname),
            s($cohortuser->lastname),
            s($cohortuser->email),
            $action,
        ];
    }

    echo html_writer::table($table);
    echo html_writer::div(
        get_string('showingrows', 'clientspreadsheet', (object) [
            'shown' => count($cohortusers),
            'total' => count($cohortusers),
        ]),
        'clientspreadsheet-table-count'
    );
}
echo html_writer::end_tag('section');

echo html_writer::start_tag('section', ['class' => 'clientspreadsheet-section clientspreadsheet-additions-section']);
echo $OUTPUT->heading(get_string('requestuseradditions', 'clientspreadsheet'), 3);
echo html_writer::start_div('clientspreadsheet-layout');

echo html_writer::start_div('clientspreadsheet-panel clientspreadsheet-upload-panel');
echo $OUTPUT->heading(get_string('uploadspreadsheet', 'clientspreadsheet'), 3);
if (!empty($validationerrors)) {
    echo $OUTPUT->notification(get_string('validationfailed', 'clientspreadsheet'), 'error');
    echo html_writer::alist(array_map('s', $validationerrors), ['class' => 'clientspreadsheet-error-list']);
}
if ($cansubmit) {
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
echo html_writer::end_tag('section');

echo html_writer::start_tag('section', ['class' => 'clientspreadsheet-section clientspreadsheet-pending-section']);
echo $OUTPUT->heading(get_string('pendingrequests', 'clientspreadsheet'), 3);
if (empty($pendinggroups)) {
    echo $OUTPUT->notification(get_string('nopendingrequests', 'clientspreadsheet'), 'info');
} else {
    foreach ($pendinggroups as $group) {
        $requester = $group['user'];
        $requestername = $requester ? fullname($requester) : get_string('unknownuser', 'clientspreadsheet');
        $requesteremail = $requester ? $requester->email : '';

        echo html_writer::start_div('clientspreadsheet-request-group');
        echo html_writer::tag('h4', s($requestername), ['class' => 'clientspreadsheet-requester']);
        if ($requesteremail !== '') {
            echo html_writer::div(s($requesteremail), 'clientspreadsheet-requester-email');
        }

        foreach ($group['requests'] as $request) {
            $type = $request['type'];
            $label = $type === \mod_clientspreadsheet\local\spreadsheet_helper::REQUEST_TYPE_ADDITION
                ? get_string('additionrequest', 'clientspreadsheet')
                : get_string('removalrequest', 'clientspreadsheet');

            echo html_writer::start_div('clientspreadsheet-request-item');
            echo html_writer::span($label, 'badge badge-info clientspreadsheet-request-type');
            echo html_writer::span(userdate($request['timecreated']), 'clientspreadsheet-request-time');

            if ($type === \mod_clientspreadsheet\local\spreadsheet_helper::REQUEST_TYPE_ADDITION) {
                echo \mod_clientspreadsheet\local\spreadsheet_helper::render_requested_items(
                    $request['items'],
                    $request['filename']
                );
            } else {
                $target = $request['target'];
                $targetline = $target
                    ? fullname($target) . ' (' . $target->email . ')'
                    : get_string('unknownuser', 'clientspreadsheet');
                echo html_writer::alist([s($targetline)], ['class' => 'clientspreadsheet-requested-items']);
            }

            echo html_writer::end_div();
        }

        echo html_writer::end_div();
    }
}
echo html_writer::end_tag('section');

echo html_writer::end_div();

echo $OUTPUT->footer();
