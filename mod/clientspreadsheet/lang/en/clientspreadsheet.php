<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * English strings for the Client spreadsheet activity module.
 *
 * @package    mod_clientspreadsheet
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['additionalvalueerrors'] = 'Additional rows have missing required values.';
$string['backtoactivity'] = 'Back to activity';
$string['client'] = 'Client';
$string['clientspreadsheet:addinstance'] = 'Add a Client spreadsheet activity';
$string['clientspreadsheet:manage'] = 'Manage Client spreadsheet submissions';
$string['clientspreadsheet:submit'] = 'Submit a Client spreadsheet';
$string['clientspreadsheet:view'] = 'View Client spreadsheet';
$string['clientspreadsheetname'] = 'Activity name';
$string['complete'] = 'Completed';
$string['confirmcompleteheading'] = 'Complete submission';
$string['confirmcompletemessage'] = 'Mark "{$a}" as completed? This removes the submission and its stored spreadsheet from the request list.';
$string['coursemodulename'] = 'Client spreadsheet';
$string['downloadexample'] = 'Download example spreadsheet';
$string['duplicateheaders'] = 'Duplicate column headers found: {$a}.';
$string['emptyspreadsheet'] = 'The spreadsheet is empty.';
$string['examplespreadsheet'] = 'Example spreadsheet';
$string['examplevalue'] = 'Example value';
$string['filename'] = 'File';
$string['filereaderror'] = 'The spreadsheet could not be read: {$a}';
$string['invalidxml'] = 'The XLSX file contains invalid XML.';
$string['missingfile'] = 'File missing';
$string['missingheaderrow'] = 'The spreadsheet must have a header row.';
$string['missingrequiredcolumns'] = 'Missing required columns: {$a}.';
$string['modulename'] = 'Client spreadsheet';
$string['modulename_help'] = 'Collect validated CSV or XLSX spreadsheets from clients. Each upload is stored for staff review and download.';
$string['modulenameplural'] = 'Client spreadsheets';
$string['nodatarows'] = 'The spreadsheet must include at least one data row below the headers.';
$string['nofileuploaded'] = 'No spreadsheet was uploaded.';
$string['noinstances'] = 'No Client spreadsheet activities were found in this course.';
$string['nopermissiontosubmit'] = 'You can view this activity, but you do not have permission to submit spreadsheets.';
$string['nosubmissions'] = 'No spreadsheets have been submitted yet.';
$string['pluginadministration'] = 'Client spreadsheet administration';
$string['pluginname'] = 'Client spreadsheet';
$string['privacy:metadata:clientspreadsheet_submission'] = 'Information about spreadsheets submitted through a Client spreadsheet activity.';
$string['privacy:metadata:clientspreadsheet_submission:clientspreadsheetid'] = 'The activity instance where the spreadsheet was submitted.';
$string['privacy:metadata:clientspreadsheet_submission:filename'] = 'The uploaded spreadsheet filename.';
$string['privacy:metadata:clientspreadsheet_submission:filesize'] = 'The uploaded spreadsheet file size.';
$string['privacy:metadata:clientspreadsheet_submission:mimetype'] = 'The uploaded spreadsheet MIME type.';
$string['privacy:metadata:clientspreadsheet_submission:reviewerid'] = 'The user who reviewed the submission.';
$string['privacy:metadata:clientspreadsheet_submission:status'] = 'The review status of the submission.';
$string['privacy:metadata:clientspreadsheet_submission:timecreated'] = 'The time the spreadsheet was submitted.';
$string['privacy:metadata:clientspreadsheet_submission:timereviewed'] = 'The time the spreadsheet was reviewed.';
$string['privacy:metadata:clientspreadsheet_submission:userid'] = 'The user who submitted the spreadsheet.';
$string['privacy:metadata:clientspreadsheet_submission:validationmessage'] = 'The validation summary saved with the submission.';
$string['privacy:metadata:core_files'] = 'The Client spreadsheet activity stores submitted spreadsheet files using Moodle files.';
$string['requiredcolumns'] = 'Required columns';
$string['requiredcolumns_help'] = 'Enter the column headers that must exist in uploaded spreadsheets. Use one column per line or separate columns with commas. Header matching ignores case, spaces, and punctuation.';
$string['requiredcolumnserror'] = 'Enter at least one required column.';
$string['requiredvalueempty'] = 'Row {$a->row} is missing a value for "{$a->column}".';
$string['returntocourse'] = 'Return to course';
$string['reviewedby'] = 'Reviewed by';
$string['spreadsheetfile'] = 'Spreadsheet file';
$string['spreadsheetfile_help'] = 'Upload one .xlsx or .csv file. The first row must contain the required column headers shown in the example.';
$string['status'] = 'Status';
$string['status_submitted'] = 'Submitted';
$string['submitted'] = 'Submitted';
$string['submittedheading'] = 'Spreadsheet submitted';
$string['submittedmessage'] = 'Your spreadsheet has been submitted. Please allow us 24 hours to process it.';
$string['submissions'] = 'Submissions';
$string['submissioncompleted'] = 'Submission completed and removed.';
$string['submitanother'] = 'Submit another spreadsheet';
$string['submitspreadsheet'] = 'Submit spreadsheet';
$string['templatesettings'] = 'Spreadsheet template';
$string['unknownuser'] = 'Unknown user';
$string['unsupportedfiletype'] = 'Upload a .xlsx or .csv spreadsheet.';
$string['uploadspreadsheet'] = 'Upload spreadsheet';
$string['validationfailed'] = 'The spreadsheet needs changes before it can be submitted.';
$string['validationpassedmessage'] = 'Validated {$a} data row(s).';
$string['viewsubmissions'] = 'View submissions';
$string['worksheetmissing'] = 'The first worksheet could not be found in the XLSX file.';
$string['xlsxopenfailed'] = 'The XLSX file could not be opened.';
$string['ziparchivemissing'] = 'This server needs the PHP Zip extension to validate XLSX files.';
