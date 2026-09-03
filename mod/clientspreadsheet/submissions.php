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
$type = optional_param('type', \mod_clientspreadsheet\local\spreadsheet_helper::REQUEST_TYPE_ADDITION, PARAM_ALPHA);
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
\mod_clientspreadsheet\local\spreadsheet_helper::cleanup_completed_submissions();

if ($action !== '') {
    require_sesskey();

    if (!in_array($action, ['confirmcomplete', 'complete'], true)) {
        throw new moodle_exception('invalidaction');
    }

    if (!in_array($type, [
        \mod_clientspreadsheet\local\spreadsheet_helper::REQUEST_TYPE_ADDITION,
        \mod_clientspreadsheet\local\spreadsheet_helper::REQUEST_TYPE_REMOVAL,
    ], true)) {
        throw new moodle_exception('invalidrequesttype', 'clientspreadsheet');
    }

    if ($type === \mod_clientspreadsheet\local\spreadsheet_helper::REQUEST_TYPE_REMOVAL) {
        $submission = $DB->get_record('clientspreadsheet_removal', [
            'id' => $submissionid,
            'clientspreadsheetid' => $clientspreadsheet->id,
        ], '*', MUST_EXIST);
        $targetuser = $DB->get_record('user', ['id' => $submission->targetuserid]);
        $confirmmessage = get_string('confirmcompleteremovalmessage', 'clientspreadsheet', (object) [
            'name' => $targetuser ? s(fullname($targetuser)) : get_string('unknownuser', 'clientspreadsheet'),
            'days' => \mod_clientspreadsheet\local\spreadsheet_helper::get_retention_days($clientspreadsheet),
        ]);
    } else {
        $submission = $DB->get_record('clientspreadsheet_submission', [
            'id' => $submissionid,
            'clientspreadsheetid' => $clientspreadsheet->id,
        ], '*', MUST_EXIST);
        $confirmmessage = get_string('confirmcompleteadditionmessage', 'clientspreadsheet', (object) [
            'filename' => s($submission->filename),
            'days' => \mod_clientspreadsheet\local\spreadsheet_helper::get_retention_days($clientspreadsheet),
        ]);
    }

    if ($action === 'confirmcomplete') {
        $PAGE->set_url(new moodle_url($url, [
            'action' => 'confirmcomplete',
            'type' => $type,
            'submission' => $submissionid,
            'sesskey' => sesskey(),
        ]));
        $PAGE->set_title(get_string('confirmcompleteheading', 'clientspreadsheet'));
        $PAGE->set_heading(format_string($course->fullname));
        $PAGE->set_context($context);

        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('confirmcompleteheading', 'clientspreadsheet'));
        echo $OUTPUT->confirm(
            $confirmmessage,
            new moodle_url($url, [
                'action' => 'complete',
                'type' => $type,
                'submission' => $submission->id,
                'sesskey' => sesskey(),
            ]),
            $url
        );
        echo $OUTPUT->footer();
        exit;
    }

    if ($submission->status !== \mod_clientspreadsheet\local\spreadsheet_helper::STATUS_COMPLETED) {
        if ($type === \mod_clientspreadsheet\local\spreadsheet_helper::REQUEST_TYPE_REMOVAL) {
            \mod_clientspreadsheet\local\spreadsheet_helper::complete_removal_request($submission);
        } else {
            \mod_clientspreadsheet\local\spreadsheet_helper::complete_submission($submission);
        }
    }

    redirect($url, get_string('submissioncompleted', 'clientspreadsheet'));
}

$PAGE->set_url($url);
$PAGE->set_title(get_string('submissions', 'clientspreadsheet'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($clientspreadsheet->name));
echo $OUTPUT->heading(get_string('submissions', 'clientspreadsheet'), 3);

$additionrecords = $DB->get_records_sql(
    "SELECT *
       FROM {clientspreadsheet_submission}
      WHERE clientspreadsheetid = :clientspreadsheetid
      ORDER BY CASE WHEN status = :completed THEN 1 ELSE 0 END ASC, timecreated DESC, id DESC",
    [
        'clientspreadsheetid' => $clientspreadsheet->id,
        'completed' => \mod_clientspreadsheet\local\spreadsheet_helper::STATUS_COMPLETED,
    ]
);
$removalrecords = $DB->get_records_sql(
    "SELECT *
       FROM {clientspreadsheet_removal}
      WHERE clientspreadsheetid = :clientspreadsheetid
      ORDER BY CASE WHEN status = :completed THEN 1 ELSE 0 END ASC, timecreated DESC, id DESC",
    [
        'clientspreadsheetid' => $clientspreadsheet->id,
        'completed' => \mod_clientspreadsheet\local\spreadsheet_helper::STATUS_COMPLETED,
    ]
);

echo html_writer::start_div('clientspreadsheet-admin-split');

echo html_writer::start_div('clientspreadsheet-panel clientspreadsheet-admin-panel clientspreadsheet-addition-panel');
echo $OUTPUT->heading(get_string('useradditions', 'clientspreadsheet'), 3);
if (empty($additionrecords)) {
    echo $OUTPUT->notification(get_string('noadditionrequests', 'clientspreadsheet'), 'info');
} else {
    $users = \mod_clientspreadsheet\local\spreadsheet_helper::get_submission_users($additionrecords);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable clientspreadsheet-submissions';
    $table->head = [
        get_string('submitted', 'clientspreadsheet'),
        get_string('client', 'clientspreadsheet'),
        get_string('filename', 'clientspreadsheet'),
        get_string('requestedusers', 'clientspreadsheet'),
        get_string('status', 'clientspreadsheet'),
        get_string('reviewedby', 'clientspreadsheet'),
        get_string('removeafter', 'clientspreadsheet'),
        get_string('actions'),
    ];

    foreach ($additionrecords as $record) {
        $user = $users[$record->userid] ?? null;
        $reviewer = !empty($record->reviewerid) && isset($users[$record->reviewerid]) ? $users[$record->reviewerid] : null;
        $downloadlink = \mod_clientspreadsheet\local\spreadsheet_helper::get_submission_download_link($context, $record);
        $iscompleted = $record->status === \mod_clientspreadsheet\local\spreadsheet_helper::STATUS_COMPLETED;

        if ($iscompleted) {
            $actions = html_writer::span(get_string('noactionneeded', 'clientspreadsheet'), 'text-muted');
            $reviewedby = $reviewer ? fullname($reviewer) . html_writer::empty_tag('br') . userdate($record->timereviewed) : '-';
            $removeafter = userdate(
                $record->timereviewed
                    + (\mod_clientspreadsheet\local\spreadsheet_helper::get_retention_days($clientspreadsheet) * DAYSECS)
            );
        } else {
            $actions = html_writer::link(new moodle_url($url, [
                'action' => 'confirmcomplete',
                'type' => \mod_clientspreadsheet\local\spreadsheet_helper::REQUEST_TYPE_ADDITION,
                'submission' => $record->id,
                'sesskey' => sesskey(),
            ]), get_string('complete', 'clientspreadsheet'), ['class' => 'btn btn-sm btn-success']);
            $reviewedby = '-';
            $removeafter = '-';
        }

        $row = new html_table_row([
            userdate($record->timecreated),
            $user ? fullname($user) . html_writer::empty_tag('br') . s($user->email) : get_string('unknownuser', 'clientspreadsheet'),
            $downloadlink,
            \mod_clientspreadsheet\local\spreadsheet_helper::render_requested_items(
                \mod_clientspreadsheet\local\spreadsheet_helper::decode_requested_items($record->requesteditems ?? ''),
                $record->validationmessage ?? ''
            ),
            html_writer::span(
                get_string('status_' . $record->status, 'clientspreadsheet'),
                $iscompleted ? 'badge badge-danger' : 'badge badge-info'
            ),
            $reviewedby,
            $removeafter,
            $actions,
        ]);

        if ($iscompleted) {
            $row->attributes['class'] = 'clientspreadsheet-completed-row';
        }

        $table->data[] = $row;
    }

    echo html_writer::table($table);
}
echo html_writer::end_div();

echo html_writer::start_div('clientspreadsheet-panel clientspreadsheet-admin-panel clientspreadsheet-removal-panel');
echo $OUTPUT->heading(get_string('userremovals', 'clientspreadsheet'), 3);
if (empty($removalrecords)) {
    echo $OUTPUT->notification(get_string('noremovalrequests', 'clientspreadsheet'), 'info');
} else {
    $userids = [];
    $cohortids = [];
    foreach ($removalrecords as $record) {
        $userids[] = (int) $record->userid;
        $userids[] = (int) $record->targetuserid;
        if (!empty($record->reviewerid)) {
            $userids[] = (int) $record->reviewerid;
        }
        if (!empty($record->cohortid)) {
            $cohortids[] = (int) $record->cohortid;
        }
    }
    $users = \mod_clientspreadsheet\local\spreadsheet_helper::get_users_by_ids($userids);
    $cohorts = [];
    $cohortids = array_values(array_unique(array_filter($cohortids)));
    if (!empty($cohortids)) {
        [$cohortsql, $cohortparams] = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED, 'cohort');
        $cohorts = $DB->get_records_select('cohort', "id {$cohortsql}", $cohortparams, '', 'id, name, idnumber');
    }

    $table = new html_table();
    $table->attributes['class'] = 'generaltable clientspreadsheet-submissions';
    $table->head = [
        get_string('submitted', 'clientspreadsheet'),
        get_string('requestedby', 'clientspreadsheet'),
        get_string('usertoremove', 'clientspreadsheet'),
        get_string('cohort', 'cohort'),
        get_string('status', 'clientspreadsheet'),
        get_string('reviewedby', 'clientspreadsheet'),
        get_string('removeafter', 'clientspreadsheet'),
        get_string('actions'),
    ];

    foreach ($removalrecords as $record) {
        $user = $users[$record->userid] ?? null;
        $targetuser = $users[$record->targetuserid] ?? null;
        $reviewer = !empty($record->reviewerid) && isset($users[$record->reviewerid]) ? $users[$record->reviewerid] : null;
        $cohort = !empty($record->cohortid) && isset($cohorts[$record->cohortid]) ? $cohorts[$record->cohortid] : null;
        $iscompleted = $record->status === \mod_clientspreadsheet\local\spreadsheet_helper::STATUS_COMPLETED;

        if ($iscompleted) {
            $actions = html_writer::span(get_string('noactionneeded', 'clientspreadsheet'), 'text-muted');
            $reviewedby = $reviewer ? fullname($reviewer) . html_writer::empty_tag('br') . userdate($record->timereviewed) : '-';
            $removeafter = userdate(
                $record->timereviewed
                    + (\mod_clientspreadsheet\local\spreadsheet_helper::get_retention_days($clientspreadsheet) * DAYSECS)
            );
        } else {
            $actions = html_writer::link(new moodle_url($url, [
                'action' => 'confirmcomplete',
                'type' => \mod_clientspreadsheet\local\spreadsheet_helper::REQUEST_TYPE_REMOVAL,
                'submission' => $record->id,
                'sesskey' => sesskey(),
            ]), get_string('complete', 'clientspreadsheet'), ['class' => 'btn btn-sm btn-success']);
            $reviewedby = '-';
            $removeafter = '-';
        }

        $row = new html_table_row([
            userdate($record->timecreated),
            $user ? fullname($user) . html_writer::empty_tag('br') . s($user->email) : get_string('unknownuser', 'clientspreadsheet'),
            $targetuser ? fullname($targetuser) . html_writer::empty_tag('br') . s($targetuser->email)
                : get_string('unknownuser', 'clientspreadsheet'),
            $cohort ? s(format_string($cohort->name)) : '-',
            html_writer::span(
                get_string('status_' . $record->status, 'clientspreadsheet'),
                $iscompleted ? 'badge badge-danger' : 'badge badge-info'
            ),
            $reviewedby,
            $removeafter,
            $actions,
        ]);

        if ($iscompleted) {
            $row->attributes['class'] = 'clientspreadsheet-completed-row';
        }

        $table->data[] = $row;
    }

    echo html_writer::table($table);
}
echo html_writer::end_div();

echo html_writer::end_div();
echo $OUTPUT->single_button(new moodle_url('/mod/clientspreadsheet/view.php', ['id' => $cm->id]), get_string('backtoactivity', 'clientspreadsheet'), 'get');

echo $OUTPUT->footer();
