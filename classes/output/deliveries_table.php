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

namespace local_webhookengine\output;

defined('MOODLE_INTERNAL') || die();

use table_sql;
use moodle_url;
use html_writer;

require_once($CFG->libdir . '/tablelib.php');

/**
 * Flexible SQL table displaying webhook delivery log entries with safe export streaming.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deliveries_table extends table_sql {
    /** @var int|null Target hook ID filter. */
    protected ?int $hookid;

    /** @var string|null Status filter. */
    protected ?string $status;

    /** @var bool Can the current user replay deliveries? */
    protected bool $canreplay;

    /** @var bool Whether personal data and payloads are included in export. */
    protected bool $includepersonal;

    /**
     * Constructor.
     *
     * @param string $uniqueid Table unique ID.
     * @param moodle_url $baseurl Page base URL.
     * @param int|null $hookid Hook ID filter.
     * @param bool $includepersonal Whether personal data is included in export.
     * @param string|null $status Status filter (queued, success, failed, retrying, dropped).
     */
    public function __construct(
        string $uniqueid,
        moodle_url $baseurl,
        ?int $hookid = null,
        bool $includepersonal = false,
        ?string $status = null
    ) {
        parent::__construct($uniqueid);
        $this->hookid = $hookid;
        $this->status = $status;
        $this->includepersonal = $includepersonal;
        $this->canreplay = has_capability('local/webhookengine:replay', \context_system::instance());

        $this->define_baseurl($baseurl);

        $columns = [];
        $headers = [];

        if (!$this->is_downloading() && $this->canreplay) {
            $columns[] = 'select';
            $headers[] = html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'id' => 'select_all_deliveries',
                'title' => get_string('select_all', 'local_webhookengine'),
                'style' => 'width: 1.15rem; height: 1.15rem; cursor: pointer; margin: 0;',
            ]);
        }

        $basecols = ['id', 'uuid', 'eventname', 'status', 'attempts', 'httpstatus', 'duration', 'timecreated'];
        $columns = array_merge($columns, $basecols);
        $headers = array_merge($headers, [
            get_string('id', 'local_webhookengine'),
            get_string('uuid', 'local_webhookengine'),
            get_string('event_name', 'local_webhookengine'),
            get_string('status', 'local_webhookengine'),
            get_string('attempts', 'local_webhookengine'),
            get_string('http_status', 'local_webhookengine'),
            get_string('duration_ms', 'local_webhookengine'),
            get_string('time_created', 'local_webhookengine'),
        ]);

        // Include personal data and payloads only when authorized and requested during export.
        if ($this->is_downloading() && $this->includepersonal) {
            $columns[] = 'userid';
            $headers[] = get_string('user_id', 'local_webhookengine');
            $columns[] = 'relateduserid';
            $headers[] = get_string('related_user_id', 'local_webhookengine');
            $columns[] = 'courseid';
            $headers[] = get_string('course_id', 'local_webhookengine');
            $columns[] = 'payload';
            $headers[] = get_string('payload', 'local_webhookengine');
        }

        if (!$this->is_downloading() && $this->canreplay) {
            $columns[] = 'actions';
            $headers[] = get_string('actions', 'local_webhookengine');
        }

        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->sortable(true, 'timecreated', SORT_DESC);
        if (!$this->is_downloading()) {
            $this->no_sorting('actions');
            $this->no_sorting('select');
        }

        // Enable standard export support if user has the export capability.
        // Download controls are unified cleanly into the dedicated top export action card.
        if (has_capability('local/webhookengine:exportlogs', \context_system::instance())) {
            $this->is_downloadable(true);
            $this->show_download_buttons_at([]);
        }

        $this->set_sql_params();
    }

    /**
     * Configure SQL query and parameters.
     */
    protected function set_sql_params(): void {
        $fields = 'id, hookid, uuid, eventname, status, attempts, httpstatus, errorcode, duration, '
            . 'userid, relateduserid, courseid, payload, timecreated';
        $from = '{local_webhookengine_delivery}';
        $where = ['1=1'];
        $params = [];

        if ($this->hookid !== null && $this->hookid > 0) {
            $where[] = 'hookid = :hookid';
            $params['hookid'] = $this->hookid;
        }

        if (!empty($this->status)) {
            $where[] = 'status = :status';
            $params['status'] = $this->status;
        }

        $this->set_sql($fields, $from, implode(' AND ', $where), $params);
    }

    /**
     * Format select checkbox column for bulk replay actions.
     *
     * @param object $row Data row.
     * @return string Checkbox HTML or dash.
     */
    public function col_select(object $row): string {
        if (in_array($row->status, ['failed', 'dropped', 'retrying']) && !empty($row->payload)) {
            return html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => 'deliveryids[]',
                'value' => (int) $row->id,
                'form' => 'bulk_replay_form',
                'class' => 'delivery-select-cb',
                'style' => 'width: 1.15rem; height: 1.15rem; cursor: pointer; margin: 0;',
            ]);
        }
        return html_writer::tag('span', '-', ['class' => 'text-muted small']);
    }

    /**
     * Format UUID column.
     *
     * @param object $row Data row.
     * @return string Formatted UUID.
     */
    public function col_uuid(object $row): string {
        if ($this->is_downloading()) {
            return $this->sanitize_export_cell((string) $row->uuid);
        }
        return s($row->uuid);
    }

    /**
     * Format event name column.
     *
     * @param object $row Data row.
     * @return string Formatted event name.
     */
    public function col_eventname(object $row): string {
        if ($this->is_downloading()) {
            return $this->sanitize_export_cell((string) $row->eventname);
        }
        return s($row->eventname);
    }

    /**
     * Format status column with visual badge or sanitized plain text.
     *
     * @param object $row Data row.
     * @return string Formatted HTML or plain text.
     */
    public function col_status(object $row): string {
        $label = get_string('status_' . $row->status, 'local_webhookengine');
        if (!empty($row->errorcode)) {
            $label .= ' (' . $row->errorcode . ')';
        }

        if ($this->is_downloading()) {
            return $this->sanitize_export_cell($label);
        }

        $class = 'badge ';
        switch ($row->status) {
            case 'success':
                $class .= 'badge-success bg-success';
                break;
            case 'retrying':
                $class .= 'badge-warning bg-warning text-dark';
                break;
            case 'failed':
            case 'dropped':
                $class .= 'badge-danger bg-danger';
                break;
            case 'queued':
                $class .= 'badge-info bg-info text-white';
                break;
            default:
                $class .= 'badge-secondary bg-secondary';
        }
        return html_writer::span(s($label), $class);
    }

    /**
     * Format payload column when exporting.
     *
     * @param object $row Data row.
     * @return string Formatted payload string.
     */
    public function col_payload(object $row): string {
        if (empty($row->payload)) {
            return '';
        }
        return $this->sanitize_export_cell((string) $row->payload);
    }

    /**
     * Format time created column.
     *
     * @param object $row Data row.
     * @return string Formatted date string.
     */
    public function col_timecreated(object $row): string {
        return userdate($row->timecreated, get_string('strftimedatetimeshort', 'core_langconfig'));
    }

    /**
     * Format duration column.
     *
     * @param object $row Data row.
     * @return string Formatted duration.
     */
    public function col_duration(object $row): string {
        return $row->duration !== null ? $row->duration . ' ms' : '-';
    }

    /**
     * Format actions column with replay capability.
     *
     * @param object $row Data row.
     * @return string Action buttons HTML.
     */
    public function col_actions(object $row): string {
        if (!$this->canreplay) {
            return '';
        }

        if (in_array($row->status, ['failed', 'dropped', 'retrying'])) {
            if (empty($row->payload)) {
                return html_writer::tag(
                    'span',
                    get_string('payload_purged', 'local_webhookengine'),
                    ['class' => 'badge badge-light text-muted']
                );
            }

            $actionurl = new moodle_url('/local/webhookengine/action.php');
            $hiddens = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'replay'])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'deliveryid', 'value' => (int) $row->id])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            if ($this->hookid) {
                $hiddens .= html_writer::empty_tag('input', [
                    'type' => 'hidden',
                    'name' => 'hookid',
                    'value' => (int) $this->hookid,
                ]);
            }
            $submitbtn = html_writer::empty_tag('input', [
                'type' => 'submit',
                'value' => get_string('replay', 'local_webhookengine'),
                'class' => 'btn btn-sm btn-outline-primary',
            ]);
            return html_writer::tag('form', $hiddens . $submitbtn, [
                'method' => 'post',
                'action' => $actionurl->out(false),
                'class' => 'd-inline m-0 p-0',
            ]);
        }

        return '';
    }

    /**
     * Neutralize CSV and spreadsheet formula injection attempts (CWE-1236).
     *
     * @param string $value Cell content.
     * @return string Sanitized content safe for spreadsheet rendering.
     */
    protected function sanitize_export_cell(string $value): string {
        if (preg_match('/^[\=\+\-\@\t\r]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }
}
