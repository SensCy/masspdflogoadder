<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Upgrade steps for the Client spreadsheet activity module.
 *
 * @package    mod_clientspreadsheet
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Runs database upgrades.
 *
 * @param int $oldversion Previously installed version.
 * @return bool
 */
function xmldb_clientspreadsheet_upgrade($oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026090200) {
        $table = new xmldb_table('clientspreadsheet');

        $notificationemail = new xmldb_field(
            'notificationemail',
            XMLDB_TYPE_CHAR,
            '255',
            null,
            XMLDB_NOTNULL,
            null,
            '',
            'requiredcolumns'
        );
        if (!$dbman->field_exists($table, $notificationemail)) {
            $dbman->add_field($table, $notificationemail);
        }

        $completedretentiondays = new xmldb_field(
            'completedretentiondays',
            XMLDB_TYPE_INTEGER,
            '4',
            null,
            XMLDB_NOTNULL,
            null,
            '30',
            'notificationemail'
        );
        if (!$dbman->field_exists($table, $completedretentiondays)) {
            $dbman->add_field($table, $completedretentiondays);
        }

        upgrade_mod_savepoint(true, 2026090200, 'clientspreadsheet');
    }

    return true;
}
