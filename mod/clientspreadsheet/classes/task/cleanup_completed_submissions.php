<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace mod_clientspreadsheet\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Removes completed submissions after their retention period expires.
 *
 * @package    mod_clientspreadsheet
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_completed_submissions extends \core\task\scheduled_task {

    /**
     * Returns the admin-visible task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('cleanupcompletedtask', 'clientspreadsheet');
    }

    /**
     * Executes cleanup.
     */
    public function execute(): void {
        $removed = \mod_clientspreadsheet\local\spreadsheet_helper::cleanup_completed_submissions();

        if ($removed > 0) {
            mtrace(get_string('cleanupcompletedtaskremoved', 'clientspreadsheet', $removed));
        }
    }
}
