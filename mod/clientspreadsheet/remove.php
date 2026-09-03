<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Confirms a client user-removal request.
 *
 * @package    mod_clientspreadsheet
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$targetuserid = required_param('user', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$cm = get_coursemodule_from_id('clientspreadsheet', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$clientspreadsheet = $DB->get_record('clientspreadsheet', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
$targetuser = $DB->get_record('user', [
    'id' => $targetuserid,
    'deleted' => 0,
    'suspended' => 0,
], '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/clientspreadsheet:view', $context);
require_capability('mod/clientspreadsheet:submit', $context);

$returnurl = new moodle_url('/mod/clientspreadsheet/view.php', ['id' => $cm->id]);
$cohortid = \mod_clientspreadsheet\local\spreadsheet_helper::get_shared_cohort_id($USER->id, $targetuser->id);

if ($cohortid === 0
        || (int) $targetuser->id === (int) $USER->id
        || is_siteadmin($targetuser->id)
        || \mod_clientspreadsheet\local\spreadsheet_helper::user_is_excluded_account($targetuser)) {
    throw new moodle_exception('removalnotallowed', 'clientspreadsheet', $returnurl);
}

if ($confirm) {
    require_sesskey();

    $existing = \mod_clientspreadsheet\local\spreadsheet_helper::get_pending_removal_request(
        (int) $clientspreadsheet->id,
        (int) $targetuser->id,
        $cohortid
    );
    $removal = $existing ?: \mod_clientspreadsheet\local\spreadsheet_helper::create_removal_request(
        $clientspreadsheet,
        $course,
        (int) $targetuser->id,
        $cohortid
    );

    if (!$existing) {
        \mod_clientspreadsheet\local\spreadsheet_helper::send_removal_notification(
            $clientspreadsheet,
            $course,
            $cm,
            $removal,
            $targetuser
        );
    }

    redirect(new moodle_url('/mod/clientspreadsheet/removal_submitted.php', [
        'id' => $cm->id,
        'removal' => $removal->id,
    ]));
}

$url = new moodle_url('/mod/clientspreadsheet/remove.php', ['id' => $cm->id, 'user' => $targetuser->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('confirmremovalheading', 'clientspreadsheet'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('confirmremovalheading', 'clientspreadsheet'));
echo $OUTPUT->confirm(
    get_string('confirmremovalmessage', 'clientspreadsheet', (object) [
        'name' => s(fullname($targetuser)),
        'email' => s($targetuser->email),
    ]),
    new moodle_url($url, [
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]),
    $returnurl
);
echo $OUTPUT->footer();
