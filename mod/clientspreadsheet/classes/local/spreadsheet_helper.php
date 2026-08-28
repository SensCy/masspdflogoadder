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
}
