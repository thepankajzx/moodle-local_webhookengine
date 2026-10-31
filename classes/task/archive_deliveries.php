<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_webhookengine\task;

/**
 * Scheduled maintenance task to archive old webhook deliveries into compressed packages.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class archive_deliveries extends \core\task\scheduled_task {
    /**
     * Get task name for administration interface.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_archive_deliveries', 'local_webhookengine');
    }

    /**
     * Execute scheduled archival of delivery logs into compressed .csv.gz archives.
     */
    public function execute(): void {
        global $DB;

        $enabled = get_config('local_webhookengine', 'enableautoarchive');
        if ($enabled !== false && !(int)$enabled) {
            mtrace('Automated log archiving is disabled in settings.');
            return;
        }

        $frequency = (int) get_config('local_webhookengine', 'archivefrequency');
        if ($frequency <= 0) {
            $frequency = 7;
        }

        $includepayload = (bool) get_config('local_webhookengine', 'archiveincludepayload');

        $now = time();
        $cutoff = $now - ($frequency * 86400);

        // Fetch distinct active hooks that have completed deliveries older than cutoff.
        $sql = "SELECT DISTINCT hookid
                  FROM {local_webhookengine_delivery}
                 WHERE timecreated < :cutoff
                   AND status IN ('success', 'failed', 'dropped')";
        $hooks = $DB->get_records_sql($sql, ['cutoff' => $cutoff]);
        if (empty($hooks)) {
            mtrace('No delivery records pending archival.');
            return;
        }

        $fs = get_file_storage();
        $syscontext = \context_system::instance();

        foreach ($hooks as $hookentry) {
            $hookid = (int) $hookentry->hookid;
            $hook = $DB->get_record('local_webhookengine_hook', ['id' => $hookid]);
            $hookname = $hook ? clean_param($hook->name, PARAM_ALPHANUMEXT) : 'hook_' . $hookid;
            if (empty($hookname)) {
                $hookname = 'hook_' . $hookid;
            }

            // Create temporary file path in Moodle temp storage.
            $tempdir = make_temp_directory('local_webhookengine/archives');
            $tempfile = $tempdir . '/' . uniqid('arch_', true) . '.csv.gz';

            $gz = gzopen($tempfile, 'w9');
            if (!$gz) {
                mtrace("Could not open gzip file for writing: $tempfile");
                continue;
            }

            // Write CSV headers.
            $headers = ['id', 'uuid', 'eventname', 'status', 'attempts', 'httpstatus', 'duration', 'timecreated'];
            if ($includepayload) {
                $headers[] = 'userid';
                $headers[] = 'relateduserid';
                $headers[] = 'courseid';
                $headers[] = 'payload';
            }
            gzwrite($gz, implode(',', $headers) . "\n");

            // Query records for this hook via recordset to avoid memory exhaustion.
            $select = "hookid = :hookid AND timecreated < :cutoff AND status IN ('success', 'failed', 'dropped')";
            $params = ['hookid' => $hookid, 'cutoff' => $cutoff];
            $rs = $DB->get_recordset_select('local_webhookengine_delivery', $select, $params, 'id ASC');

            $count = 0;
            $archivedids = [];

            foreach ($rs as $rec) {
                $row = [
                    $rec->id,
                    $this->sanitize_export_cell((string) $rec->uuid),
                    $this->sanitize_export_cell((string) $rec->eventname),
                    $this->sanitize_export_cell((string) $rec->status),
                    $rec->attempts,
                    $rec->httpstatus !== null ? $rec->httpstatus : '',
                    $rec->duration !== null ? $rec->duration : '',
                    userdate($rec->timecreated, get_string('strftimedatetimeshort', 'core_langconfig')),
                ];

                if ($includepayload) {
                    $row[] = $rec->userid !== null ? $rec->userid : '';
                    $row[] = $rec->relateduserid !== null ? $rec->relateduserid : '';
                    $row[] = $rec->courseid !== null ? $rec->courseid : '';
                    $row[] = !empty($rec->payload) ? $this->sanitize_export_cell((string) $rec->payload) : '';
                }

                // Format row as CSV with proper quoting.
                $escaped = array_map(function ($field) {
                    return '"' . str_replace('"', '""', (string)$field) . '"';
                }, $row);

                gzwrite($gz, implode(',', $escaped) . "\n");
                $archivedids[] = $rec->id;
                $count++;
            }
            $rs->close();
            gzclose($gz);

            if ($count > 0) {
                // Store in Moodle File Storage.
                $filename = 'archive_' . $hookname . '_' . date('Ymd_His', $cutoff) . '.csv.gz';
                $filerecord = [
                    'contextid'    => $syscontext->id,
                    'component'    => 'local_webhookengine',
                    'filearea'     => 'archives',
                    'itemid'       => $hookid,
                    'filepath'     => '/',
                    'filename'     => $filename,
                    'timecreated'  => $now,
                    'timemodified' => $now,
                ];

                $fs->create_file_from_pathname($filerecord, $tempfile);
                mtrace("Created archive for hook ID $hookid ($hookname): $filename ($count deliveries archived).");

                // Delete archived records from database in chunks of 500.
                foreach (array_chunk($archivedids, 500) as $chunkids) {
                    [$insql, $inparams] = $DB->get_in_or_equal($chunkids, SQL_PARAMS_NAMED);
                    $DB->delete_records_select('local_webhookengine_delivery', "id $insql", $inparams);
                }
            }

            if (file_exists($tempfile)) {
                @unlink($tempfile);
            }
        }

        // Clean up ancient archive files past retention limit.
        $retentiondays = (int) get_config('local_webhookengine', 'archiveretention');
        if ($retentiondays > 0) {
            $retentioncutoff = $now - ($retentiondays * 86400);
            $files = $fs->get_area_files($syscontext->id, 'local_webhookengine', 'archives', false, 'timecreated ASC', false);
            foreach ($files as $file) {
                if ($file->get_timecreated() < $retentioncutoff) {
                    $file->delete();
                    mtrace("Deleted expired archive file past {$retentiondays}d retention: " . $file->get_filename());
                }
            }
        }
    }

    /**
     * Neutralize CSV and spreadsheet formula injection attempts (CWE-1236).
     *
     * @param string $value Raw string content.
     * @return string Sanitized string with neutralizer prepended if required.
     */
    protected function sanitize_export_cell(string $value): string {
        if (preg_match('/^[\=\+\-\@\t\r]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }
}
