<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace mod_clientspreadsheet\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy metadata provider for the Client spreadsheet activity.
 *
 * @package    mod_clientspreadsheet
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\provider {

    /**
     * Describes stored personal data.
     *
     * @param \core_privacy\local\metadata\collection $collection Metadata collection.
     * @return \core_privacy\local\metadata\collection
     */
    public static function get_metadata(\core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {
        $collection->add_database_table('clientspreadsheet_submission', [
            'clientspreadsheetid' => 'privacy:metadata:clientspreadsheet_submission:clientspreadsheetid',
            'userid' => 'privacy:metadata:clientspreadsheet_submission:userid',
            'filename' => 'privacy:metadata:clientspreadsheet_submission:filename',
            'filesize' => 'privacy:metadata:clientspreadsheet_submission:filesize',
            'mimetype' => 'privacy:metadata:clientspreadsheet_submission:mimetype',
            'status' => 'privacy:metadata:clientspreadsheet_submission:status',
            'validationmessage' => 'privacy:metadata:clientspreadsheet_submission:validationmessage',
            'reviewerid' => 'privacy:metadata:clientspreadsheet_submission:reviewerid',
            'timecreated' => 'privacy:metadata:clientspreadsheet_submission:timecreated',
            'timereviewed' => 'privacy:metadata:clientspreadsheet_submission:timereviewed',
        ], 'privacy:metadata:clientspreadsheet_submission');

        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');

        return $collection;
    }
}
