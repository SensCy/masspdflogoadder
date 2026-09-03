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

    if ($oldversion < 2026090300) {
        $submissiontable = new xmldb_table('clientspreadsheet_submission');

        $cohortid = new xmldb_field(
            'cohortid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'course'
        );
        if (!$dbman->field_exists($submissiontable, $cohortid)) {
            $dbman->add_field($submissiontable, $cohortid);
        }

        $requesteditems = new xmldb_field(
            'requesteditems',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            'validationmessage'
        );
        if (!$dbman->field_exists($submissiontable, $requesteditems)) {
            $dbman->add_field($submissiontable, $requesteditems);
        }

        $cohortindex = new xmldb_index('cohortid', XMLDB_INDEX_NOTUNIQUE, ['cohortid']);
        if (!$dbman->index_exists($submissiontable, $cohortindex)) {
            $dbman->add_index($submissiontable, $cohortindex);
        }

        $removaltable = new xmldb_table('clientspreadsheet_removal');
        if (!$dbman->table_exists($removaltable)) {
            $removaltable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $removaltable->add_field('clientspreadsheetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $removaltable->add_field('course', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $removaltable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $removaltable->add_field('targetuserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $removaltable->add_field('cohortid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $removaltable->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'submitted');
            $removaltable->add_field('reviewerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $removaltable->add_field('timereviewed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $removaltable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $removaltable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $removaltable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $removaltable->add_key(
                'clientspreadsheet_fk',
                XMLDB_KEY_FOREIGN,
                ['clientspreadsheetid'],
                'clientspreadsheet',
                ['id']
            );

            $removaltable->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $removaltable->add_index('targetuserid', XMLDB_INDEX_NOTUNIQUE, ['targetuserid']);
            $removaltable->add_index('cohortid', XMLDB_INDEX_NOTUNIQUE, ['cohortid']);
            $removaltable->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);

            $dbman->create_table($removaltable);
        }

        upgrade_mod_savepoint(true, 2026090300, 'clientspreadsheet');
    }

    return true;
}
