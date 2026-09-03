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

    /** @var string Internal score-account suffix hidden from client views. */
    public const EXCLUDED_ACCOUNT_SUFFIX = '@senscyscore.com';

    /** @var string Addition request type. */
    public const REQUEST_TYPE_ADDITION = 'addition';

    /** @var string Removal request type. */
    public const REQUEST_TYPE_REMOVAL = 'removal';

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
     * Gets visible cohort ids for a user.
     *
     * @param int $userid User id.
     * @return int[]
     */
    public static function get_user_cohort_ids(int $userid): array {
        global $CFG;

        require_once($CFG->dirroot . '/cohort/lib.php');

        $cohorts = cohort_get_user_cohorts($userid);

        return array_values(array_map('intval', array_keys($cohorts)));
    }

    /**
     * Finds one visible cohort shared by two users.
     *
     * @param int $userid Requesting user id.
     * @param int $targetuserid Target user id.
     * @return int Shared cohort id, or 0 when no shared cohort exists.
     */
    public static function get_shared_cohort_id(int $userid, int $targetuserid): int {
        $cohortids = self::get_user_cohort_ids($userid);
        if (empty($cohortids)) {
            return 0;
        }

        $targetcohortids = self::get_user_cohort_ids($targetuserid);
        $shared = array_values(array_intersect($cohortids, $targetcohortids));

        return !empty($shared) ? (int) $shared[0] : 0;
    }

    /**
     * Gets active users in the logged-in user's visible cohorts.
     *
     * @param int $userid User id.
     * @return \stdClass[] User records.
     */
    public static function get_cohort_users_for_user(int $userid): array {
        global $DB;

        $cohortids = self::get_user_cohort_ids($userid);
        if (empty($cohortids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($cohortids, \SQL_PARAMS_NAMED, 'cohortid');
        $params['excludedemail'] = '%' . self::EXCLUDED_ACCOUNT_SUFFIX;
        $params['excludedusername'] = '%' . self::EXCLUDED_ACCOUNT_SUFFIX;

        return $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username, u.deleted, u.suspended,
                    u.idnumber, u.alternatename, u.middlename, u.firstnamephonetic, u.lastnamephonetic
               FROM {user} u
               JOIN {cohort_members} cm ON cm.userid = u.id
              WHERE cm.cohortid {$insql}
                AND u.deleted = 0
                AND u.suspended = 0
                AND LOWER(u.email) NOT LIKE :excludedemail
                AND LOWER(u.username) NOT LIKE :excludedusername
           ORDER BY u.lastname ASC, u.firstname ASC, u.email ASC",
            $params
        );
    }

    /**
     * Checks whether a user is an internal score account hidden from client lists.
     *
     * @param \stdClass $user User record.
     * @return bool
     */
    public static function user_is_excluded_account(\stdClass $user): bool {
        $email = \core_text::strtolower(trim($user->email ?? ''));
        $username = \core_text::strtolower(trim($user->username ?? ''));

        return self::ends_with($email, self::EXCLUDED_ACCOUNT_SUFFIX)
            || self::ends_with($username, self::EXCLUDED_ACCOUNT_SUFFIX);
    }

    /**
     * Gets pending removal records keyed by target user id.
     *
     * @param int $instanceid Activity instance id.
     * @param int[] $targetuserids Target user ids.
     * @return \stdClass[]
     */
    public static function get_pending_removal_targets(int $instanceid, array $targetuserids): array {
        global $DB;

        $targetuserids = array_values(array_unique(array_filter(array_map('intval', $targetuserids))));
        if (empty($targetuserids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($targetuserids, \SQL_PARAMS_NAMED, 'targetuserid');
        $params['clientspreadsheetid'] = $instanceid;
        $params['status'] = self::STATUS_SUBMITTED;

        $records = $DB->get_records_select(
            'clientspreadsheet_removal',
            "clientspreadsheetid = :clientspreadsheetid AND status = :status AND targetuserid {$insql}",
            $params,
            '',
            'id, targetuserid'
        );

        $targets = [];
        foreach ($records as $record) {
            $targets[(int) $record->targetuserid] = $record;
        }

        return $targets;
    }

    /**
     * Gets one pending removal request for a target user and cohort.
     *
     * @param int $instanceid Activity instance id.
     * @param int $targetuserid Target user id.
     * @param int $cohortid Cohort id.
     * @return \stdClass|null
     */
    public static function get_pending_removal_request(int $instanceid, int $targetuserid, int $cohortid): ?\stdClass {
        global $DB;

        $record = $DB->get_record('clientspreadsheet_removal', [
            'clientspreadsheetid' => $instanceid,
            'targetuserid' => $targetuserid,
            'cohortid' => $cohortid,
            'status' => self::STATUS_SUBMITTED,
        ]);

        return $record ?: null;
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
     * Encodes requested spreadsheet rows for later display.
     *
     * @param array $items Requested row summaries.
     * @return string
     */
    public static function encode_requested_items(array $items): string {
        $json = json_encode(array_values($items), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return $json !== false ? $json : '[]';
    }

    /**
     * Decodes requested spreadsheet rows.
     *
     * @param string|null $value Stored JSON.
     * @return array
     */
    public static function decode_requested_items(?string $value): array {
        if (trim((string) $value) === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * Renders requested items as a compact list.
     *
     * @param array $items Requested row summaries.
     * @param string $fallback Fallback text.
     * @param int $limit Maximum rows to render.
     * @return string
     */
    public static function render_requested_items(array $items, string $fallback = '', int $limit = 12): string {
        if (empty($items)) {
            return $fallback !== '' ? s($fallback) : '-';
        }

        $lines = [];
        foreach (array_slice($items, 0, $limit) as $item) {
            $line = self::format_requested_item($item);
            if ($line !== '') {
                $lines[] = s($line);
            }
        }

        $remaining = count($items) - count($lines);
        if ($remaining > 0) {
            $lines[] = s(get_string('additionalrequesteditems', 'clientspreadsheet', $remaining));
        }

        return !empty($lines)
            ? \html_writer::alist($lines, ['class' => 'clientspreadsheet-requested-items'])
            : ($fallback !== '' ? s($fallback) : '-');
    }

    /**
     * Formats one requested user item.
     *
     * @param array $item Requested row summary.
     * @return string
     */
    public static function format_requested_item(array $item): string {
        $firstname = self::get_requested_item_value($item, 'firstname');
        $lastname = self::get_requested_item_value($item, 'lastname');
        $email = self::get_requested_item_value($item, 'email');
        $name = trim($firstname . ' ' . $lastname);

        if ($name !== '' && $email !== '') {
            return $name . ' (' . $email . ')';
        }

        if ($email !== '') {
            return $email;
        }

        if ($name !== '') {
            return $name;
        }

        return implode(', ', array_filter(array_map('trim', array_map('strval', $item))));
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
     * Gets a value from a requested item by normalised key.
     *
     * @param array $item Requested row summary.
     * @param string $wanted Normalised key.
     * @return string
     */
    private static function get_requested_item_value(array $item, string $wanted): string {
        foreach ($item as $key => $value) {
            $normalised = \core_text::strtolower(trim((string) $key));
            $normalised = preg_replace('/[^a-z0-9]+/', '', $normalised);

            if ($normalised === $wanted) {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * Checks whether a string ends with a suffix.
     *
     * @param string $value String to check.
     * @param string $suffix Required suffix.
     * @return bool
     */
    private static function ends_with(string $value, string $suffix): bool {
        if ($suffix === '') {
            return true;
        }

        return substr($value, -strlen($suffix)) === $suffix;
    }

    /**
     * Gets user records by id.
     *
     * @param int[] $userids User ids.
     * @return \stdClass[] Records keyed by user id.
     */
    public static function get_users_by_ids(array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
        if (empty($userids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, \SQL_PARAMS_NAMED, 'userid');

        return $DB->get_records_select(
            'user',
            "id {$insql}",
            $params,
            '',
            'id, firstname, lastname, email, username, idnumber, alternatename, middlename, firstnamephonetic, lastnamephonetic'
        );
    }

    /**
     * Gets user records referenced by submissions.
     *
     * @param \stdClass[] $records Submission records.
     * @return \stdClass[] Records keyed by user id.
     */
    public static function get_submission_users(array $records): array {
        $userids = [];
        foreach ($records as $record) {
            $userids[] = (int) $record->userid;
            if (!empty($record->reviewerid)) {
                $userids[] = (int) $record->reviewerid;
            }
        }

        return self::get_users_by_ids($userids);
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
     * Gets pending addition and removal requests visible to a user through shared cohorts.
     *
     * @param \stdClass $instance Activity instance.
     * @param int $userid User id.
     * @return array Request groups keyed by requesting user.
     */
    public static function get_pending_requests_for_user(\stdClass $instance, int $userid): array {
        $additions = self::get_pending_addition_requests_for_user((int) $instance->id, $userid);
        $removals = self::get_pending_removal_requests_for_user((int) $instance->id, $userid);

        $userids = [];
        foreach ($additions as $addition) {
            $userids[] = (int) $addition->userid;
        }
        foreach ($removals as $removal) {
            $userids[] = (int) $removal->userid;
            $userids[] = (int) $removal->targetuserid;
        }

        $users = self::get_users_by_ids($userids);
        $groups = [];

        foreach ($additions as $addition) {
            $requester = $users[$addition->userid] ?? null;
            self::add_grouped_request($groups, $addition->userid, $requester, [
                'type' => self::REQUEST_TYPE_ADDITION,
                'timecreated' => (int) $addition->timecreated,
                'filename' => $addition->filename,
                'items' => self::decode_requested_items($addition->requesteditems ?? ''),
            ]);
        }

        foreach ($removals as $removal) {
            $requester = $users[$removal->userid] ?? null;
            $target = $users[$removal->targetuserid] ?? null;
            self::add_grouped_request($groups, $removal->userid, $requester, [
                'type' => self::REQUEST_TYPE_REMOVAL,
                'timecreated' => (int) $removal->timecreated,
                'target' => $target,
            ]);
        }

        uasort($groups, static function(array $left, array $right): int {
            return $right['lastcreated'] <=> $left['lastcreated'];
        });

        return array_values($groups);
    }

    /**
     * Creates a pending removal request.
     *
     * @param \stdClass $instance Activity instance.
     * @param \stdClass $course Course record.
     * @param int $targetuserid Target user id.
     * @param int $cohortid Cohort id.
     * @return \stdClass Removal request.
     */
    public static function create_removal_request(
        \stdClass $instance,
        \stdClass $course,
        int $targetuserid,
        int $cohortid
    ): \stdClass {
        global $DB, $USER;

        $existing = self::get_pending_removal_request((int) $instance->id, $targetuserid, $cohortid);
        if ($existing) {
            return $existing;
        }

        $time = time();
        $request = (object) [
            'clientspreadsheetid' => (int) $instance->id,
            'course' => (int) $course->id,
            'userid' => (int) $USER->id,
            'targetuserid' => $targetuserid,
            'cohortid' => $cohortid,
            'status' => self::STATUS_SUBMITTED,
            'reviewerid' => 0,
            'timereviewed' => 0,
            'timecreated' => $time,
            'timemodified' => $time,
        ];
        $request->id = $DB->insert_record('clientspreadsheet_removal', $request);

        return $request;
    }

    /**
     * Gets pending spreadsheet additions visible through a user's cohorts.
     *
     * @param int $instanceid Activity instance id.
     * @param int $userid User id.
     * @return \stdClass[]
     */
    private static function get_pending_addition_requests_for_user(int $instanceid, int $userid): array {
        global $DB;

        $cohortids = self::get_user_cohort_ids($userid);
        if (empty($cohortids)) {
            return [];
        }

        [$storedcohortsql, $storedparams] = $DB->get_in_or_equal($cohortids, \SQL_PARAMS_NAMED, 'storedcohort');
        [$membercohortsql, $memberparams] = $DB->get_in_or_equal($cohortids, \SQL_PARAMS_NAMED, 'membercohort');
        $params = array_merge($storedparams, $memberparams, [
            'clientspreadsheetid' => $instanceid,
            'status' => self::STATUS_SUBMITTED,
        ]);

        return $DB->get_records_sql(
            "SELECT DISTINCT s.*
               FROM {clientspreadsheet_submission} s
          LEFT JOIN {cohort_members} cm ON cm.userid = s.userid
              WHERE s.clientspreadsheetid = :clientspreadsheetid
                AND s.status = :status
                AND (s.cohortid {$storedcohortsql}
                     OR cm.cohortid {$membercohortsql})
           ORDER BY s.timecreated DESC, s.id DESC",
            $params
        );
    }

    /**
     * Gets pending user-removal requests visible through a user's cohorts.
     *
     * @param int $instanceid Activity instance id.
     * @param int $userid User id.
     * @return \stdClass[]
     */
    private static function get_pending_removal_requests_for_user(int $instanceid, int $userid): array {
        global $DB;

        $cohortids = self::get_user_cohort_ids($userid);
        if (empty($cohortids)) {
            return [];
        }

        [$cohortsql, $params] = $DB->get_in_or_equal($cohortids, \SQL_PARAMS_NAMED, 'removalcohort');
        $params['clientspreadsheetid'] = $instanceid;
        $params['status'] = self::STATUS_SUBMITTED;

        return $DB->get_records_sql(
            "SELECT DISTINCT r.*
               FROM {clientspreadsheet_removal} r
              WHERE r.clientspreadsheetid = :clientspreadsheetid
                AND r.status = :status
                AND r.cohortid {$cohortsql}
           ORDER BY r.timecreated DESC, r.id DESC",
            $params
        );
    }

    /**
     * Adds a request to a requester group.
     *
     * @param array $groups Request groups.
     * @param int $userid Requesting user id.
     * @param \stdClass|null $requester Requesting user record.
     * @param array $request Request details.
     */
    private static function add_grouped_request(array &$groups, int $userid, ?\stdClass $requester, array $request): void {
        if (!isset($groups[$userid])) {
            $groups[$userid] = [
                'user' => $requester,
                'lastcreated' => 0,
                'requests' => [],
            ];
        }

        $groups[$userid]['lastcreated'] = max($groups[$userid]['lastcreated'], (int) $request['timecreated']);
        $groups[$userid]['requests'][] = $request;
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
     * Marks a user-removal request as completed.
     *
     * @param \stdClass $request Removal request.
     */
    public static function complete_removal_request(\stdClass $request): void {
        global $DB, $USER;

        $time = time();
        $request->status = self::STATUS_COMPLETED;
        $request->reviewerid = $USER->id;
        $request->timereviewed = $time;
        $request->timemodified = $time;

        $DB->update_record('clientspreadsheet_removal', $request);
    }

    /**
     * Deletes completed requests whose retention period has expired.
     *
     * @return int Number of removed requests.
     */
    public static function cleanup_completed_submissions(): int {
        global $DB;

        $submissionrecords = $DB->get_records_sql(
            "SELECT s.*, cs.completedretentiondays
               FROM {clientspreadsheet_submission} s
               JOIN {clientspreadsheet} cs ON cs.id = s.clientspreadsheetid
              WHERE s.status = :status
                AND s.timereviewed > 0",
            ['status' => self::STATUS_COMPLETED]
        );

        $removed = 0;
        $now = time();

        foreach ($submissionrecords as $record) {
            $retentiondays = self::normalise_retention_days($record->completedretentiondays);
            if ($record->timereviewed + ($retentiondays * \DAYSECS) > $now) {
                continue;
            }

            self::delete_submission($record);
            $removed++;
        }

        $removalrecords = $DB->get_records_sql(
            "SELECT r.*, cs.completedretentiondays
               FROM {clientspreadsheet_removal} r
               JOIN {clientspreadsheet} cs ON cs.id = r.clientspreadsheetid
              WHERE r.status = :status
                AND r.timereviewed > 0",
            ['status' => self::STATUS_COMPLETED]
        );

        foreach ($removalrecords as $record) {
            $retentiondays = self::normalise_retention_days($record->completedretentiondays);
            if ($record->timereviewed + ($retentiondays * \DAYSECS) > $now) {
                continue;
            }

            self::delete_removal_request($record);
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
     * Deletes a user-removal request.
     *
     * @param \stdClass $request Removal request.
     */
    public static function delete_removal_request(\stdClass $request): void {
        global $DB;

        $DB->delete_records('clientspreadsheet_removal', ['id' => $request->id]);
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
        global $USER;

        $recipient = self::get_notification_recipient($instance);
        if (!$recipient) {
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

    /**
     * Sends a user-removal request notification to the configured site admin.
     *
     * @param \stdClass $instance Activity instance.
     * @param \stdClass $course Course record.
     * @param \cm_info|\stdClass $cm Course module.
     * @param \stdClass $request Removal request.
     * @param \stdClass $targetuser Target user record.
     * @return bool True when an email was sent.
     */
    public static function send_removal_notification(
        \stdClass $instance,
        \stdClass $course,
        $cm,
        \stdClass $request,
        \stdClass $targetuser
    ): bool {
        global $USER;

        $recipient = self::get_notification_recipient($instance);
        if (!$recipient) {
            return false;
        }

        $submissionsurl = new \moodle_url('/mod/clientspreadsheet/submissions.php', ['id' => $cm->id]);
        $subject = get_string('removalnotificationsubject', 'clientspreadsheet', format_string($instance->name));
        $data = (object) [
            'activity' => format_string($instance->name),
            'course' => format_string($course->fullname),
            'requestedby' => fullname($USER),
            'requestedbyemail' => $USER->email,
            'targetuser' => fullname($targetuser),
            'targetemail' => $targetuser->email,
            'submittedtime' => userdate($request->timecreated),
            'url' => $submissionsurl->out(false),
        ];

        $messagetext = get_string('removalnotificationbodytext', 'clientspreadsheet', $data);
        $htmldata = (object) [
            'activity' => s($data->activity),
            'course' => s($data->course),
            'requestedby' => s($data->requestedby),
            'requestedbyemail' => s($data->requestedbyemail),
            'targetuser' => s($data->targetuser),
            'targetemail' => s($data->targetemail),
            'submittedtime' => s($data->submittedtime),
            'url' => s($data->url),
        ];
        $messagehtml = \html_writer::tag('p', get_string('removalnotificationbodyintro', 'clientspreadsheet'))
            . \html_writer::alist([
                get_string('notificationbodyactivity', 'clientspreadsheet', $htmldata),
                get_string('notificationbodycourse', 'clientspreadsheet', $htmldata),
                get_string('removalnotificationbodyrequester', 'clientspreadsheet', $htmldata),
                get_string('removalnotificationbodytarget', 'clientspreadsheet', $htmldata),
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

    /**
     * Gets the configured notification recipient.
     *
     * @param \stdClass $instance Activity instance.
     * @return \stdClass|null
     */
    private static function get_notification_recipient(\stdClass $instance): ?\stdClass {
        global $DB;

        $email = trim($instance->notificationemail ?? '');
        if ($email === '') {
            return null;
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
            return null;
        }

        return $recipient;
    }
}
