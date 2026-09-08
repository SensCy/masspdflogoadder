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
$activeuserpage = optional_param('activeuserpage', 0, PARAM_INT);
$tab = optional_param('tab', 'active', PARAM_ALPHA);
$search = trim(optional_param('q', '', PARAM_TEXT));
$rolefilter = optional_param('role', 'all', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);
$success = optional_param('success', '', PARAM_ALPHA);

if (!in_array($tab, ['active', 'pending', 'all'], true)) {
    $tab = 'active';
}
if (!in_array($rolefilter, ['all', 'admin', 'member'], true)) {
    $rolefilter = 'all';
}

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
$singleusererrors = [];
$cansubmit = has_capability('mod/clientspreadsheet:submit', $context);

if ($action === 'addsingleuser') {
    require_sesskey();
    require_capability('mod/clientspreadsheet:submit', $context);

    $firstname = trim(optional_param('firstname', '', PARAM_TEXT));
    $lastname = trim(optional_param('lastname', '', PARAM_TEXT));
    $email = trim(optional_param('email', '', PARAM_EMAIL));

    if ($firstname === '') {
        $singleusererrors[] = get_string('missingfirstname', 'clientspreadsheet');
    }
    if ($lastname === '') {
        $singleusererrors[] = get_string('missinglastname', 'clientspreadsheet');
    }
    if ($email === '' || !validate_email($email)) {
        $singleusererrors[] = get_string('invalidemail', 'clientspreadsheet');
    }

    if (empty($singleusererrors)) {
        $cohortids = \mod_clientspreadsheet\local\spreadsheet_helper::get_user_cohort_ids($USER->id);
        $time = time();
        $requesteditems = [[
            'first name' => $firstname,
            'last name' => $lastname,
            'email' => $email,
        ]];
        $submission = (object) [
            'clientspreadsheetid' => $clientspreadsheet->id,
            'course' => $course->id,
            'cohortid' => !empty($cohortids) ? (int) $cohortids[0] : 0,
            'userid' => $USER->id,
            'filename' => get_string('manualadditionfilename', 'clientspreadsheet'),
            'filesize' => 0,
            'mimetype' => '',
            'status' => \mod_clientspreadsheet\local\spreadsheet_helper::STATUS_SUBMITTED,
            'validationmessage' => get_string('manualadditionvalidationmessage', 'clientspreadsheet', $email),
            'requesteditems' => \mod_clientspreadsheet\local\spreadsheet_helper::encode_requested_items($requesteditems),
            'reviewerid' => 0,
            'timereviewed' => 0,
            'timecreated' => $time,
            'timemodified' => $time,
        ];
        $submission->id = $DB->insert_record('clientspreadsheet_submission', $submission);

        \mod_clientspreadsheet\local\spreadsheet_helper::send_submission_notification(
            $clientspreadsheet,
            $course,
            $cm,
            $submission
        );

        redirect(new moodle_url($url, ['success' => 'single', 'tab' => 'pending']));
    }
} else if ($mform->is_cancelled()) {
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

            redirect(new moodle_url($url, ['success' => 'bulk', 'tab' => 'pending']));
        }
    }
}

$allcohortusers = \mod_clientspreadsheet\local\spreadsheet_helper::get_cohort_users_for_user($USER->id);
$pendinggroups = \mod_clientspreadsheet\local\spreadsheet_helper::get_pending_requests_for_user($clientspreadsheet, $USER->id);
$searchterm = core_text::strtolower($search);

$matchessearch = static function(string $haystack) use ($searchterm): bool {
    if ($searchterm === '') {
        return true;
    }

    return strpos(core_text::strtolower($haystack), $searchterm) !== false;
};

$filteredactiveusers = array_values(array_filter($allcohortusers, static function($cohortuser) use ($rolefilter, $matchessearch): bool {
    $isadmin = is_siteadmin($cohortuser->id);
    if ($rolefilter === 'admin' && !$isadmin) {
        return false;
    }
    if ($rolefilter === 'member' && $isadmin) {
        return false;
    }

    $searchable = implode(' ', [
        $cohortuser->firstname,
        $cohortuser->lastname,
        $cohortuser->email,
    ]);

    return $matchessearch($searchable);
}));

$activeuserperpage = 25;
$activeusertotal = count($filteredactiveusers);
$maxactiveuserpage = $activeusertotal > 0 ? (int) ceil($activeusertotal / $activeuserperpage) - 1 : 0;
$activeuserpage = max(0, min($activeuserpage, $maxactiveuserpage));
$activeuseroffset = $activeuserpage * $activeuserperpage;
$cohortusers = array_slice($filteredactiveusers, $activeuseroffset, $activeuserperpage);
$pendingremovals = \mod_clientspreadsheet\local\spreadsheet_helper::get_pending_removal_targets(
    $clientspreadsheet->id,
    array_keys($allcohortusers)
);

$pendingrows = [];
foreach ($pendinggroups as $group) {
    $requester = $group['user'];
    $requestername = $requester ? fullname($requester) : get_string('unknownuser', 'clientspreadsheet');
    $requesteremail = $requester ? $requester->email : '';

    foreach ($group['requests'] as $request) {
        $type = $request['type'];
        $typeclass = $type === \mod_clientspreadsheet\local\spreadsheet_helper::REQUEST_TYPE_ADDITION
            ? 'clientspreadsheet-badge-addition'
            : 'clientspreadsheet-badge-removal';
        $typelabel = $type === \mod_clientspreadsheet\local\spreadsheet_helper::REQUEST_TYPE_ADDITION
            ? get_string('additionrequest', 'clientspreadsheet')
            : get_string('removalrequest', 'clientspreadsheet');

        if ($type === \mod_clientspreadsheet\local\spreadsheet_helper::REQUEST_TYPE_ADDITION) {
            $items = $request['items'];
            $requestedhtml = \mod_clientspreadsheet\local\spreadsheet_helper::render_requested_items(
                $items,
                $request['filename']
            );
            $itemtext = implode(' ', array_map(
                ['\mod_clientspreadsheet\local\spreadsheet_helper', 'format_requested_item'],
                $items
            ));
            if ($itemtext === '') {
                $itemtext = $request['filename'];
            }
            $emailcell = '-';
        } else {
            $target = $request['target'];
            $itemtext = $target ? fullname($target) . ' ' . $target->email : get_string('unknownuser', 'clientspreadsheet');
            $requestedhtml = html_writer::span(s($itemtext), 'clientspreadsheet-requested-fallback');
            $emailcell = $target ? s($target->email) : '-';
        }

        $searchable = implode(' ', [
            $typelabel,
            $itemtext,
            $requestername,
            $requesteremail,
        ]);
        if (!$matchessearch($searchable)) {
            continue;
        }

        $pendingrows[] = [
            'type' => $typelabel,
            'typeclass' => $typeclass,
            'requestedhtml' => $requestedhtml,
            'requestedtext' => $itemtext,
            'email' => $emailcell,
            'requestedby' => $requestername,
            'requestedbyemail' => $requesteremail,
            'timecreated' => (int) $request['timecreated'],
        ];
    }
}

$pendingcount = count($pendingrows);

$renderrolebadge = static function($userid): string {
    if (is_siteadmin($userid)) {
        return html_writer::span(get_string('roleadmin', 'clientspreadsheet'), 'badge clientspreadsheet-role-badge clientspreadsheet-role-admin');
    }

    return html_writer::span(get_string('rolemember', 'clientspreadsheet'), 'badge clientspreadsheet-role-badge clientspreadsheet-role-member');
};

$renderactiveaction = static function($cohortuser) use ($USER, $cm, $cansubmit, $pendingremovals): string {
    if ((int) $cohortuser->id === (int) $USER->id) {
        return html_writer::span(get_string('currentuser', 'clientspreadsheet'), 'clientspreadsheet-muted-action');
    }
    if (is_siteadmin($cohortuser->id)) {
        return html_writer::span(get_string('noactionneeded', 'clientspreadsheet'), 'clientspreadsheet-muted-action');
    }
    if (isset($pendingremovals[$cohortuser->id])) {
        return html_writer::span(get_string('removalpending', 'clientspreadsheet'), 'badge clientspreadsheet-badge clientspreadsheet-badge-warning');
    }
    if ($cansubmit) {
        return html_writer::link(
            new moodle_url('/mod/clientspreadsheet/remove.php', [
                'id' => $cm->id,
                'user' => $cohortuser->id,
            ]),
            get_string('remove'),
            ['class' => 'clientspreadsheet-text-action clientspreadsheet-text-danger']
        );
    }

    return '-';
};

$filterform = static function() use ($cm, $tab, $search, $rolefilter): string {
    $roleoptions = [
        'all' => get_string('allroles', 'clientspreadsheet'),
        'admin' => get_string('roleadmin', 'clientspreadsheet'),
        'member' => get_string('rolemember', 'clientspreadsheet'),
    ];

    $output = html_writer::start_tag('form', [
        'method' => 'get',
        'action' => (new moodle_url('/mod/clientspreadsheet/view.php'))->out(false),
        'class' => 'clientspreadsheet-filters',
    ]);
    $output .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
    $output .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => $tab]);
    $output .= html_writer::start_div('clientspreadsheet-search-wrap');
    $output .= html_writer::tag('label', get_string('searchusers', 'clientspreadsheet'), [
        'class' => 'sr-only',
        'for' => 'clientspreadsheet-search',
    ]);
    $output .= html_writer::empty_tag('input', [
        'type' => 'search',
        'class' => 'form-control',
        'id' => 'clientspreadsheet-search',
        'name' => 'q',
        'value' => $search,
        'placeholder' => get_string('searchusersplaceholder', 'clientspreadsheet'),
    ]);
    $output .= html_writer::end_div();
    $output .= html_writer::select($roleoptions, 'role', $rolefilter, false, [
        'class' => 'custom-select clientspreadsheet-role-filter',
        'aria-label' => get_string('filterbyrole', 'clientspreadsheet'),
    ]);
    $output .= html_writer::tag('button', get_string('search'), [
        'class' => 'btn btn-secondary',
        'type' => 'submit',
    ]);
    $output .= html_writer::link(
        new moodle_url('/mod/clientspreadsheet/view.php', ['id' => $cm->id, 'tab' => $tab]),
        get_string('clear'),
        ['class' => 'btn btn-link clientspreadsheet-clear-filter']
    );
    $output .= html_writer::end_tag('form');

    return $output;
};

$renderactivefooter = static function() use ($OUTPUT, $cm, $tab, $search, $rolefilter, $activeusertotal, $activeuseroffset, $cohortusers, $activeuserperpage, $activeuserpage): string {
    if ($activeusertotal === 0) {
        return '';
    }

    $output = html_writer::div(
        get_string('showingusers', 'clientspreadsheet', (object) [
            'start' => $activeuseroffset + 1,
            'end' => min($activeuseroffset + count($cohortusers), $activeusertotal),
            'total' => $activeusertotal,
        ]),
        'clientspreadsheet-table-count'
    );

    if ($activeusertotal > $activeuserperpage) {
        $pagingurl = new moodle_url('/mod/clientspreadsheet/view.php', [
            'id' => $cm->id,
            'tab' => $tab,
            'q' => $search,
            'role' => $rolefilter,
        ]);
        $output .= html_writer::div(
            $OUTPUT->paging_bar($activeusertotal, $activeuserpage, $activeuserperpage, $pagingurl, 'activeuserpage'),
            'clientspreadsheet-user-paging'
        );
    }

    return $output;
};

$renderactivetable = static function() use ($OUTPUT, $cohortusers, $renderrolebadge, $renderactiveaction, $renderactivefooter): string {
    if (empty($cohortusers)) {
        return $OUTPUT->notification(get_string('nocohortusers', 'clientspreadsheet'), 'info');
    }

    $table = new html_table();
    $table->attributes['class'] = 'generaltable clientspreadsheet-user-table';
    $table->head = [
        get_string('firstname'),
        get_string('lastname'),
        get_string('email'),
        get_string('role', 'clientspreadsheet'),
        get_string('actions'),
    ];

    foreach ($cohortusers as $cohortuser) {
        $table->data[] = [
            s($cohortuser->firstname),
            s($cohortuser->lastname),
            s($cohortuser->email),
            $renderrolebadge($cohortuser->id),
            $renderactiveaction($cohortuser),
        ];
    }

    return html_writer::table($table) . $renderactivefooter();
};

$renderpendingtable = static function() use ($OUTPUT, $pendingrows): string {
    if (empty($pendingrows)) {
        return $OUTPUT->notification(get_string('nopendingrequests', 'clientspreadsheet'), 'info');
    }

    $table = new html_table();
    $table->attributes['class'] = 'generaltable clientspreadsheet-user-table clientspreadsheet-pending-table';
    $table->head = [
        get_string('request', 'clientspreadsheet'),
        get_string('requestedusers', 'clientspreadsheet'),
        get_string('email'),
        get_string('requestedby', 'clientspreadsheet'),
        get_string('submitted'),
        get_string('status', 'clientspreadsheet'),
    ];

    foreach ($pendingrows as $row) {
        $requester = s($row['requestedby']);
        if ($row['requestedbyemail'] !== '') {
            $requester .= html_writer::empty_tag('br') . html_writer::span(s($row['requestedbyemail']), 'clientspreadsheet-requester-email');
        }

        $table->data[] = [
            html_writer::span(s($row['type']), 'badge clientspreadsheet-badge ' . $row['typeclass']),
            $row['requestedhtml'],
            $row['email'],
            $requester,
            userdate($row['timecreated']),
            html_writer::span(get_string('status_pending', 'clientspreadsheet'), 'badge clientspreadsheet-badge clientspreadsheet-badge-warning'),
        ];
    }

    return html_writer::table($table);
};

$renderalltable = static function() use ($OUTPUT, $cohortusers, $pendingrows, $renderrolebadge, $renderactiveaction, $renderactivefooter): string {
    if (empty($cohortusers) && empty($pendingrows)) {
        return $OUTPUT->notification(get_string('nousersmatchfilters', 'clientspreadsheet'), 'info');
    }

    $table = new html_table();
    $table->attributes['class'] = 'generaltable clientspreadsheet-user-table clientspreadsheet-all-table';
    $table->head = [
        get_string('userorrequest', 'clientspreadsheet'),
        get_string('email'),
        get_string('role', 'clientspreadsheet'),
        get_string('status', 'clientspreadsheet'),
        get_string('requestedby', 'clientspreadsheet'),
        get_string('submitted'),
        get_string('actions'),
    ];

    foreach ($cohortusers as $cohortuser) {
        $table->data[] = [
            s(fullname($cohortuser)),
            s($cohortuser->email),
            $renderrolebadge($cohortuser->id),
            html_writer::span(get_string('status_active', 'clientspreadsheet'), 'badge clientspreadsheet-badge clientspreadsheet-badge-active'),
            '-',
            '-',
            $renderactiveaction($cohortuser),
        ];
    }

    foreach ($pendingrows as $row) {
        $requester = s($row['requestedby']);
        if ($row['requestedbyemail'] !== '') {
            $requester .= html_writer::empty_tag('br') . html_writer::span(s($row['requestedbyemail']), 'clientspreadsheet-requester-email');
        }

        $table->data[] = [
            html_writer::span(s($row['type']), 'badge clientspreadsheet-badge ' . $row['typeclass'])
                . html_writer::div($row['requestedhtml'], 'clientspreadsheet-all-request-detail'),
            $row['email'],
            '-',
            html_writer::span(get_string('status_pending', 'clientspreadsheet'), 'badge clientspreadsheet-badge clientspreadsheet-badge-warning'),
            $requester,
            userdate($row['timecreated']),
            '-',
        ];
    }

    return html_writer::table($table) . $renderactivefooter();
};

echo $OUTPUT->header();

echo html_writer::start_div('clientspreadsheet-console');
echo html_writer::start_div('clientspreadsheet-page-header');
echo html_writer::start_div('clientspreadsheet-title-block');
echo $OUTPUT->heading(get_string('usermanagement', 'clientspreadsheet'), 2);
echo html_writer::end_div();

echo html_writer::start_div('clientspreadsheet-header-actions');
if ($cansubmit) {
    echo html_writer::tag('button', get_string('adduser', 'clientspreadsheet'), [
        'type' => 'button',
        'class' => 'btn btn-primary',
        'data-toggle' => 'modal',
        'data-target' => '#clientspreadsheet-add-user-modal',
        'data-bs-toggle' => 'modal',
        'data-bs-target' => '#clientspreadsheet-add-user-modal',
    ]);
    echo html_writer::link(
        '#clientspreadsheet-bulk-import',
        get_string('bulkimportcsv', 'clientspreadsheet'),
        ['class' => 'btn btn-secondary']
    );
}
if (is_siteadmin()) {
    echo html_writer::link(
        new moodle_url('/mod/clientspreadsheet/submissions.php', ['id' => $cm->id]),
        get_string('viewsubmissions', 'clientspreadsheet'),
        ['class' => 'btn btn-secondary']
    );
}
echo html_writer::end_div();
echo html_writer::end_div();

if (trim($clientspreadsheet->intro ?? '') !== '') {
    echo $OUTPUT->box(
        format_module_intro('clientspreadsheet', $clientspreadsheet, $cm->id),
        'generalbox mod_introbox',
        'clientspreadsheetintro'
    );
}

if ($success === 'bulk') {
    echo $OUTPUT->notification(get_string('submittedmessage', 'clientspreadsheet'), 'success');
} else if ($success === 'single') {
    echo $OUTPUT->notification(get_string('singleusersubmittedmessage', 'clientspreadsheet'), 'success');
}

if (!empty($singleusererrors)) {
    echo $OUTPUT->notification(get_string('singleuserrequestfailed', 'clientspreadsheet'), 'error');
    echo html_writer::alist(array_map('s', $singleusererrors), ['class' => 'clientspreadsheet-error-list']);
}

if ($cansubmit) {
    echo html_writer::start_tag('section', [
        'id' => 'clientspreadsheet-bulk-import',
        'class' => 'clientspreadsheet-section clientspreadsheet-upload-module',
    ]);
    echo html_writer::start_div('clientspreadsheet-upload-heading');
    echo $OUTPUT->heading(get_string('bulkimportcsv', 'clientspreadsheet'), 3);
    echo html_writer::link(
        new moodle_url('/mod/clientspreadsheet/template.php', ['id' => $cm->id]),
        get_string('downloadcsvtemplate', 'clientspreadsheet'),
        ['class' => 'clientspreadsheet-template-link']
    );
    echo html_writer::end_div();
    if (!empty($validationerrors)) {
        echo $OUTPUT->notification(get_string('validationfailed', 'clientspreadsheet'), 'error');
        echo html_writer::alist(array_map('s', $validationerrors), ['class' => 'clientspreadsheet-error-list']);
    }
    $mform->display();
    echo html_writer::end_tag('section');
} else {
    echo $OUTPUT->notification(get_string('nopermissiontosubmit', 'clientspreadsheet'), 'warning');
}

echo html_writer::start_tag('nav', ['class' => 'clientspreadsheet-tabs', 'aria-label' => get_string('userstatusviews', 'clientspreadsheet')]);
echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs']);
$tabs = [
    'active' => get_string('activetablabel', 'clientspreadsheet', $activeusertotal),
    'pending' => get_string('pendingtablabel', 'clientspreadsheet', $pendingcount),
    'all' => get_string('allusers', 'clientspreadsheet'),
];
foreach ($tabs as $key => $label) {
    $taburl = new moodle_url('/mod/clientspreadsheet/view.php', [
        'id' => $cm->id,
        'tab' => $key,
        'q' => $search,
        'role' => $rolefilter,
    ]);
    echo html_writer::tag(
        'li',
        html_writer::link($taburl, $label, ['class' => 'nav-link' . ($tab === $key ? ' active' : '')]),
        ['class' => 'nav-item']
    );
}
echo html_writer::end_tag('ul');
echo html_writer::end_tag('nav');

echo html_writer::start_tag('section', ['class' => 'clientspreadsheet-section clientspreadsheet-users-section']);
echo $filterform();
if ($tab === 'pending') {
    echo $renderpendingtable();
} else if ($tab === 'all') {
    echo $renderalltable();
} else {
    echo $renderactivetable();
}
echo html_writer::end_tag('section');

if ($cansubmit) {
    echo html_writer::start_div('modal fade', [
        'id' => 'clientspreadsheet-add-user-modal',
        'tabindex' => '-1',
        'role' => 'dialog',
        'aria-labelledby' => 'clientspreadsheet-add-user-title',
        'aria-hidden' => 'true',
    ]);
    echo html_writer::start_div('modal-dialog', ['role' => 'document']);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
    echo html_writer::start_div('modal-content');
    echo html_writer::start_div('modal-header');
    echo html_writer::tag('h3', get_string('adduser', 'clientspreadsheet'), [
        'class' => 'modal-title',
        'id' => 'clientspreadsheet-add-user-title',
    ]);
    echo html_writer::tag(
        'button',
        html_writer::tag('span', '&times;', ['aria-hidden' => 'true']),
        [
            'type' => 'button',
            'class' => 'close',
            'data-dismiss' => 'modal',
            'data-bs-dismiss' => 'modal',
            'aria-label' => get_string('closebuttontitle'),
        ]
    );
    echo html_writer::end_div();
    echo html_writer::start_div('modal-body');
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'addsingleuser']);
    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', get_string('firstname'), ['for' => 'clientspreadsheet-firstname']);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'class' => 'form-control',
        'id' => 'clientspreadsheet-firstname',
        'name' => 'firstname',
        'required' => 'required',
    ]);
    echo html_writer::end_div();
    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', get_string('lastname'), ['for' => 'clientspreadsheet-lastname']);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'class' => 'form-control',
        'id' => 'clientspreadsheet-lastname',
        'name' => 'lastname',
        'required' => 'required',
    ]);
    echo html_writer::end_div();
    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', get_string('email'), ['for' => 'clientspreadsheet-email']);
    echo html_writer::empty_tag('input', [
        'type' => 'email',
        'class' => 'form-control',
        'id' => 'clientspreadsheet-email',
        'name' => 'email',
        'required' => 'required',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('modal-footer');
    echo html_writer::link(
        '#clientspreadsheet-bulk-import',
        get_string('bulkimportcsv', 'clientspreadsheet'),
        [
            'class' => 'btn btn-secondary mr-auto',
            'data-dismiss' => 'modal',
            'data-bs-dismiss' => 'modal',
        ]
    );
    echo html_writer::tag('button', get_string('cancel'), [
        'type' => 'button',
        'class' => 'btn btn-secondary',
        'data-dismiss' => 'modal',
        'data-bs-dismiss' => 'modal',
    ]);
    echo html_writer::tag('button', get_string('submitrequest', 'clientspreadsheet'), [
        'type' => 'submit',
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::end_div();

echo $OUTPUT->footer();
