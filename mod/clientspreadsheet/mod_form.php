<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Instance settings form for the Client spreadsheet activity module.
 *
 * @package    mod_clientspreadsheet
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Moodle activity create/edit form.
 */
class mod_clientspreadsheet_mod_form extends moodleform_mod {

    /**
     * Defines the activity settings form.
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('clientspreadsheetname', 'clientspreadsheet'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();

        $mform->addElement('header', 'templatesettings', get_string('templatesettings', 'clientspreadsheet'));

        $mform->addElement(
            'textarea',
            'requiredcolumns',
            get_string('requiredcolumns', 'clientspreadsheet'),
            ['rows' => 6, 'cols' => 64]
        );
        $mform->setType('requiredcolumns', PARAM_RAW_TRIMMED);
        $mform->setDefault(
            'requiredcolumns',
            implode("\n", \mod_clientspreadsheet\local\spreadsheet_helper::DEFAULT_COLUMNS)
        );
        $mform->addRule('requiredcolumns', null, 'required', null, 'client');
        $mform->addHelpButton('requiredcolumns', 'requiredcolumns', 'clientspreadsheet');

        $mform->addElement('header', 'notificationsettings', get_string('notificationsettings', 'clientspreadsheet'));

        $mform->addElement(
            'text',
            'notificationemail',
            get_string('notificationemail', 'clientspreadsheet'),
            ['size' => '64']
        );
        $mform->setType('notificationemail', PARAM_EMAIL);
        $mform->addHelpButton('notificationemail', 'notificationemail', 'clientspreadsheet');

        $mform->addElement(
            'text',
            'completedretentiondays',
            get_string('completedretentiondays', 'clientspreadsheet'),
            ['size' => '4']
        );
        $mform->setType('completedretentiondays', PARAM_INT);
        $mform->setDefault(
            'completedretentiondays',
            \mod_clientspreadsheet\local\spreadsheet_helper::DEFAULT_COMPLETED_RETENTION_DAYS
        );
        $mform->addHelpButton('completedretentiondays', 'completedretentiondays', 'clientspreadsheet');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Validates instance settings.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array
     */
    public function validation($data, $files): array {
        global $DB;

        $errors = parent::validation($data, $files);
        $columns = \mod_clientspreadsheet\local\spreadsheet_helper::parse_required_columns($data['requiredcolumns'] ?? '');

        if (empty($columns)) {
            $errors['requiredcolumns'] = get_string('requiredcolumnserror', 'clientspreadsheet');
        }

        $notificationemail = trim($data['notificationemail'] ?? '');
        if ($notificationemail !== '') {
            if (!validate_email($notificationemail)) {
                $errors['notificationemail'] = get_string('notificationemailinvalid', 'clientspreadsheet');
            } else {
                $recipient = $DB->get_record_sql(
                    "SELECT id
                       FROM {user}
                      WHERE LOWER(email) = LOWER(:email)
                        AND deleted = 0
                        AND suspended = 0",
                    ['email' => $notificationemail]
                );
                if (!$recipient || !is_siteadmin($recipient->id)) {
                    $errors['notificationemail'] = get_string('notificationemailnotadmin', 'clientspreadsheet');
                }
            }
        }

        if (!isset($data['completedretentiondays'])
                || (int) $data['completedretentiondays'] < 1
                || (int) $data['completedretentiondays'] > 365) {
            $errors['completedretentiondays'] = get_string('completedretentiondayserror', 'clientspreadsheet');
        }

        return $errors;
    }
}
