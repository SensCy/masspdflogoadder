<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Core callbacks for the Client spreadsheet activity module.
 *
 * @package    mod_clientspreadsheet
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Declares supported module features.
 *
 * @param string $feature The requested feature.
 * @return mixed
 */
function clientspreadsheet_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
            return true;

        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_OTHER;

        case FEATURE_BACKUP_MOODLE2:
            return false;

        default:
            return null;
    }
}

/**
 * Adds a Client spreadsheet instance.
 *
 * @param stdClass $data Submitted form data.
 * @param moodleform|null $mform The submitted form.
 * @return int New instance id.
 */
function clientspreadsheet_add_instance($data, $mform = null): int {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->requiredcolumns = \mod_clientspreadsheet\local\spreadsheet_helper::normalise_required_columns_text(
        $data->requiredcolumns ?? ''
    );

    return $DB->insert_record('clientspreadsheet', $data);
}

/**
 * Updates a Client spreadsheet instance.
 *
 * @param stdClass $data Submitted form data.
 * @param moodleform|null $mform The submitted form.
 * @return bool
 */
function clientspreadsheet_update_instance($data, $mform = null): bool {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    $data->requiredcolumns = \mod_clientspreadsheet\local\spreadsheet_helper::normalise_required_columns_text(
        $data->requiredcolumns ?? ''
    );

    return $DB->update_record('clientspreadsheet', $data);
}

/**
 * Deletes a Client spreadsheet instance.
 *
 * @param int $id Instance id.
 * @return bool
 */
function clientspreadsheet_delete_instance($id): bool {
    global $DB;

    if (!$DB->record_exists('clientspreadsheet', ['id' => $id])) {
        return false;
    }

    $cm = get_coursemodule_from_instance('clientspreadsheet', $id, 0, false, IGNORE_MISSING);
    if ($cm) {
        $context = context_module::instance($cm->id);
        get_file_storage()->delete_area_files($context->id, 'mod_clientspreadsheet');
    }

    $DB->delete_records('clientspreadsheet_submission', ['clientspreadsheetid' => $id]);
    $DB->delete_records('clientspreadsheet', ['id' => $id]);

    return true;
}

/**
 * Serves stored submission files.
 *
 * @param stdClass $course Course object.
 * @param cm_info $cm Course module object.
 * @param context $context Module context.
 * @param string $filearea File area.
 * @param array $args File path arguments.
 * @param bool $forcedownload Whether to force download.
 * @param array $options Additional send options.
 * @return bool
 */
function clientspreadsheet_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []): bool {
    global $DB, $USER;

    if ($context->contextlevel !== CONTEXT_MODULE || $filearea !== 'submission') {
        return false;
    }

    require_course_login($course, true, $cm);

    $itemid = array_shift($args);
    if (!$itemid) {
        return false;
    }

    $submission = $DB->get_record('clientspreadsheet_submission', [
        'id' => $itemid,
        'clientspreadsheetid' => $cm->instance,
    ]);

    if (!$submission) {
        return false;
    }

    $canmanage = is_siteadmin();
    if (!$canmanage && (int) $submission->userid !== (int) $USER->id) {
        return false;
    }

    $filename = array_pop($args);
    if ($filename === null) {
        return false;
    }

    $filepath = '/';
    if (!empty($args)) {
        $filepath .= implode('/', $args) . '/';
    }

    $file = get_file_storage()->get_file(
        $context->id,
        'mod_clientspreadsheet',
        'submission',
        (int) $itemid,
        $filepath,
        $filename
    );

    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}
