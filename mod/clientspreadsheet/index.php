<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Course index page for the Client spreadsheet activity module.
 *
 * @package    mod_clientspreadsheet
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_course_login($course);

$PAGE->set_url('/mod/clientspreadsheet/index.php', ['id' => $course->id]);
$PAGE->set_title(get_string('modulenameplural', 'clientspreadsheet'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'clientspreadsheet'));

$instances = get_all_instances_in_course('clientspreadsheet', $course);
if (!$instances) {
    echo $OUTPUT->notification(get_string('noinstances', 'clientspreadsheet'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('name'),
        get_string('sectionname', 'format_' . $course->format),
    ];

    foreach ($instances as $instance) {
        $link = html_writer::link(
            new moodle_url('/mod/clientspreadsheet/view.php', ['id' => $instance->coursemodule]),
            format_string($instance->name)
        );

        $table->data[] = [
            $link,
            get_section_name($course, $instance->section),
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
