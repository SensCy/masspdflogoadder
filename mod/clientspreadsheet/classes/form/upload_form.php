<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace mod_clientspreadsheet\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Upload form for client spreadsheets.
 *
 * @package    mod_clientspreadsheet
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_form extends \moodleform {

    /**
     * Defines the upload form.
     */
    public function definition(): void {
        $mform = $this->_form;
        $course = $this->_customdata['course'];
        $options = \mod_clientspreadsheet\local\spreadsheet_helper::get_file_options($course);

        $mform->addElement(
            'filepicker',
            'spreadsheet',
            get_string('spreadsheetfile', 'clientspreadsheet'),
            null,
            $options
        );
        $mform->addHelpButton('spreadsheet', 'spreadsheetfile', 'clientspreadsheet');
        $mform->addRule('spreadsheet', null, 'required', null, 'client');

        $this->add_action_buttons(false, get_string('submitspreadsheet', 'clientspreadsheet'));
    }

    /**
     * Validates the upload form.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (empty($data['spreadsheet'])) {
            $errors['spreadsheet'] = get_string('required');
        }

        return $errors;
    }
}
