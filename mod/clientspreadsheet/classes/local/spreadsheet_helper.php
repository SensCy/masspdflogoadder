<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace mod_clientspreadsheet\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared helpers for the Client spreadsheet activity.
 *
 * @package    mod_clientspreadsheet
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spreadsheet_helper {

    /** @var string Submitted status. */
    public const STATUS_SUBMITTED = 'submitted';

    /** @var string Completed status. */
    public const STATUS_COMPLETED = 'completed';

    /** @var int Default number of days completed submissions remain visible. */
    public const DEFAULT_COMPLETED_RETENTION_DAYS = 30;

    /** @var string[] Default required columns for Moodle user-upload style sheets. */
    public const DEFAULT_COLUMNS = [
        'email',
        'first name',
        'last name',
    ];

    /**
     * Returns filepicker and file area options for spreadsheets.
     *
     * @param \stdClass $course Course record.
     * @return array
     */
    public static function get_file_options(\stdClass $course): array {
        global $CFG;

        require_once($CFG->dirroot . '/repository/lib.php');

        return [
            'subdirs' => 0,
            'maxbytes' => get_max_upload_file_size($CFG->maxbytes, $course->maxbytes ?? 0),
            'maxfiles' => 1,
            'accepted_types' => ['.csv', '.xlsx'],
            'return_types' => \FILE_INTERNAL,
        ];
    }

    /**
     * Parses configured required columns.
     *
     * @param string $value Text from the module settings form.
     * @return string[]
     */
    public static function parse_required_columns(string $value): array {
        $parts = preg_split('/[\r\n,]+/', $value);
        $columns = [];

        foreach ($parts as $part) {
            $column = trim($part);
            if ($column !== '') {
                $columns[] = $column;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Normalises the required columns text before it is saved.
     *
     * @param string $value Text from the module settings form.
     * @return string
     */
    public static function normalise_required_columns_text(string $value): string {
        $columns = self::parse_required_columns($value);

        if (empty($columns)) {
            $columns = self::DEFAULT_COLUMNS;
        }

        return implode("\n", $columns);
    }

    /**
     * Normalises completed-submission retention days.
     *
     * @param mixed $value Form value.
     * @return int
     */
    public static function normalise_retention_days($value): int {
        $days = (int) $value;

        if ($days < 1) {
            return self::DEFAULT_COMPLETED_RETENTION_DAYS;
        }

        return min($days, 365);
    }

    /**
     * Gets required columns for an instance.
     *
     * @param \stdClass $instance Activity instance.
     * @return string[]
     */
    public static function get_required_columns(\stdClass $instance): array {
        $columns = self::parse_required_columns($instance->requiredcolumns ?? '');

        return !empty($columns) ? $columns : self::DEFAULT_COLUMNS;
    }

    /**
     * Gets retention days for an instance.
     *
     * @param \stdClass $instance Activity instance.
     * @return int
     */
    public static function get_retention_days(\stdClass $instance): int {
        return self::normalise_retention_days($instance->completedretentiondays ?? self::DEFAULT_COMPLETED_RETENTION_DAYS);
    }

    /**
     * Gets the single draft file from a submitted filepicker element.
     *
     * @param int $draftitemid Draft item id.
     * @return \stored_file|null
     */
    public static function get_draft_file(int $draftitemid): ?\stored_file {
        global $USER;

        if ($draftitemid <= 0) {
            return null;
        }

        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id ASC', false);

        foreach ($files as $file) {
            if (!$file->is_directory()) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Renders the visible example table.
     *
     * @param \stdClass $instance Activity instance.
     * @return string HTML table.
     */
    public static function render_example_table(\stdClass $instance): string {
        $columns = self::get_required_columns($instance);
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable clientspreadsheet-example-table';
        $table->head = array_map('s', $columns);
        $table->data = [
            array_map('s', self::get_example_row($columns)),
        ];

        return \html_writer::table($table);
    }

    /**
     * Builds one example data row for the configured columns.
     *
     * @param string[] $columns Required columns.
     * @return string[]
     */
    public static function get_example_row(array $columns): array {
        $row = [];

        foreach ($columns as $column) {
            $row[] = self::get_example_value($column);
        }

        return $row;
    }

    /**
     * Returns a readable example value for a column.
     *
     * @param string $column Column name.
     * @return string
     */
    private static function get_example_value(string $column): string {
        $key = strtolower(trim($column));
        $key = preg_replace('/[^a-z0-9_]+/', '', $key);

        $examples = [
            'username' => 'client.user001',
            'email' => 'jamie.rivera@example.com',
            'firstname' => 'Jamie',
            'lastname' => 'Rivera',
            'password' => 'ChangeMe123!',
            'institution' => 'Example Client',
            'department' => 'Operations',
            'city' => 'New York',
            'country' => 'US',
            'idnumber' => 'EMP001',
            'phone1' => '555-0100',
        ];

        return $examples[$key] ?? get_string('examplevalue', 'clientspreadsheet');
    }

    /**
     * Gets user records referenced by submissions.
     *
     * @param \stdClass[] $records Submission records.
     * @return \stdClass[] Records keyed by user id.
     */
    public static function get_submission_users(array $records): array {
        global $DB;

        $userids = [];
        foreach ($records as $record) {
            $userids[] = (int) $record->userid;
            if (!empty($record->reviewerid)) {
                $userids[] = (int) $record->reviewerid;
            }
        }

        $userids = array_values(array_unique(array_filter($userids)));
        if (empty($userids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, \SQL_PARAMS_NAMED);

        return $DB->get_records_select(
            'user',
            "id {$insql}",
            $params,
            '',
            'id, firstname, lastname, email, idnumber, alternatename, middlename, firstnamephonetic, lastnamephonetic'
        );
    }

    /**
     * Returns a download link for a stored submission file.
     *
     * @param \context_module $context Module context.
     * @param \stdClass $record Submission record.
     * @return string HTML link.
     */
    public static function get_submission_download_link(\context_module $context, \stdClass $record): string {
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_clientspreadsheet',
            'submission',
            $record->id,
            'filename ASC',
            false
        );

        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }

            $url = \moodle_url::make_pluginfile_url(
                $context->id,
                'mod_clientspreadsheet',
                'submission',
                $record->id,
                $file->get_filepath(),
                $file->get_filename(),
                true
            );

            return \html_writer::link($url, s($file->get_filename()));
        }

        return get_string('missingfile', 'clientspreadsheet');
    }

    /**
     * Marks a submitted spreadsheet as completed.
     *
     * @param \stdClass $submission Submission record.
     */
    public static function complete_submission(\stdClass $submission): void {
        global $DB, $USER;

        $time = time();
        $submission->status = self::STATUS_COMPLETED;
        $submission->reviewerid = $USER->id;
        $submission->timereviewed = $time;
        $submission->timemodified = $time;

        $DB->update_record('clientspreadsheet_submission', $submission);
    }

    /**
     * Deletes completed submissions whose retention period has expired.
     *
     * @return int Number of removed submissions.
     */
    public static function cleanup_completed_submissions(): int {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT s.*, cs.completedretentiondays
               FROM {clientspreadsheet_submission} s
               JOIN {clientspreadsheet} cs ON cs.id = s.clientspreadsheetid
              WHERE s.status = :status
                AND s.timereviewed > 0",
            ['status' => self::STATUS_COMPLETED]
        );

        $removed = 0;
        $now = time();

        foreach ($records as $record) {
            $retentiondays = self::normalise_retention_days($record->completedretentiondays);
            if ($record->timereviewed + ($retentiondays * \DAYSECS) > $now) {
                continue;
            }

            self::delete_submission($record);
            $removed++;
        }

        return $removed;
    }

    /**
     * Deletes a submission and its stored file.
     *
     * @param \stdClass $submission Submission record.
     */
    public static function delete_submission(\stdClass $submission): void {
        global $DB;

        $cm = get_coursemodule_from_instance(
            'clientspreadsheet',
            $submission->clientspreadsheetid,
            $submission->course,
            false,
            \IGNORE_MISSING
        );

        if ($cm) {
            $context = \context_module::instance($cm->id);
            get_file_storage()->delete_area_files(
                $context->id,
                'mod_clientspreadsheet',
                'submission',
                $submission->id
            );
        }

        $DB->delete_records('clientspreadsheet_submission', ['id' => $submission->id]);
    }

    /**
     * Sends a new-submission notification to the configured site admin.
     *
     * @param \stdClass $instance Activity instance.
     * @param \stdClass $course Course record.
     * @param \cm_info|\stdClass $cm Course module.
     * @param \stdClass $submission Submission record.
     * @return bool True when an email was sent.
     */
    public static function send_submission_notification(
        \stdClass $instance,
        \stdClass $course,
        $cm,
        \stdClass $submission
    ): bool {
        global $CFG, $DB, $USER;

        $email = trim($instance->notificationemail ?? '');
        if ($email === '') {
            return false;
        }

        $recipient = $DB->get_record_sql(
            "SELECT *
               FROM {user}
              WHERE LOWER(email) = LOWER(:email)
                AND deleted = 0
                AND suspended = 0",
            ['email' => $email]
        );

        if (!$recipient || !is_siteadmin($recipient->id)) {
            debugging(
                'Client spreadsheet notification email is not an active Moodle site admin: ' . $email,
                \DEBUG_DEVELOPER
            );
            return false;
        }

        $submissionsurl = new \moodle_url('/mod/clientspreadsheet/submissions.php', ['id' => $cm->id]);
        $subject = get_string('notificationsubject', 'clientspreadsheet', format_string($instance->name));
        $data = (object) [
            'activity' => format_string($instance->name),
            'course' => format_string($course->fullname),
            'submittedby' => fullname($USER),
            'submittedbyemail' => $USER->email,
            'filename' => $submission->filename,
            'submittedtime' => userdate($submission->timecreated),
            'url' => $submissionsurl->out(false),
        ];

        $messagetext = get_string('notificationbodytext', 'clientspreadsheet', $data);
        $htmldata = (object) [
            'activity' => s($data->activity),
            'course' => s($data->course),
            'submittedby' => s($data->submittedby),
            'submittedbyemail' => s($data->submittedbyemail),
            'filename' => s($data->filename),
            'submittedtime' => s($data->submittedtime),
            'url' => s($data->url),
        ];
        $messagehtml = \html_writer::tag('p', get_string('notificationbodyintro', 'clientspreadsheet'))
            . \html_writer::alist([
                get_string('notificationbodyactivity', 'clientspreadsheet', $htmldata),
                get_string('notificationbodycourse', 'clientspreadsheet', $htmldata),
                get_string('notificationbodysubmitter', 'clientspreadsheet', $htmldata),
                get_string('notificationbodyfile', 'clientspreadsheet', $htmldata),
                get_string('notificationbodytime', 'clientspreadsheet', $htmldata),
            ])
            . \html_writer::tag('p', \html_writer::link($submissionsurl, get_string('viewsubmissions', 'clientspreadsheet')));

        return (bool) email_to_user(
            $recipient,
            \core_user::get_noreply_user(),
            $subject,
            $messagetext,
            $messagehtml,
            '',
            '',
            true,
            $USER->email,
            fullname($USER)
        );
    }
}
