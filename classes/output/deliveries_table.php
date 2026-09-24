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
 * Flexible SQL table displaying webhook delivery log entries.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deliveries_table extends table_sql {
    /** @var int|null Target hook ID filter. */
    protected ?int $hookid;

    /** @var bool Can the current user replay deliveries? */
    protected bool $canreplay;

    /**
     * Constructor.
     *
     * @param string $uniqueid Table unique ID.
     * @param moodle_url $baseurl Page base URL.
     * @param int|null $hookid Hook ID filter.
     * @param string|null $statusfilter Status filter.
     */
    public function __construct(string $uniqueid, moodle_url $baseurl, ?int $hookid = null, ?string $statusfilter = null) {
        parent::__construct($uniqueid);
        $this->hookid = $hookid;
        $this->canreplay = has_capability('local/webhookengine:replay', \context_system::instance());

        $this->define_baseurl($baseurl);

        $columns = ['id', 'uuid', 'eventname', 'status', 'attempts', 'httpstatus', 'duration', 'timecreated'];
        $headers = [
            get_string('id', 'local_webhookengine'),
            get_string('uuid', 'local_webhookengine'),
            get_string('event_name', 'local_webhookengine'),
            get_string('status', 'local_webhookengine'),
            get_string('attempts', 'local_webhookengine'),
            get_string('http_status', 'local_webhookengine'),
            get_string('duration_ms', 'local_webhookengine'),
            get_string('time_created', 'local_webhookengine'),
        ];

        if ($this->canreplay) {
            $columns[] = 'actions';
            $headers[] = get_string('actions', 'local_webhookengine');
        }

        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->sortable(true, 'timecreated', SORT_DESC);
        $this->no_sorting('actions');

        $this->set_sql_params();
    }

    /**
     * Configure SQL query and parameters.
     */
    protected function set_sql_params(): void {
        $fields = 'id, hookid, uuid, eventname, status, attempts, httpstatus, errorcode, duration, timecreated';
        $from = '{local_webhookengine_delivery}';
        $where = ['1=1'];
        $params = [];

        if ($this->hookid !== null && $this->hookid > 0) {
            $where[] = 'hookid = :hookid';
            $params['hookid'] = $this->hookid;
        }

        $this->set_sql($fields, $from, implode(' AND ', $where), $params);
    }

    /**
     * Format status column with visual badge.
     *
     * @param object $row Data row.
     * @return string Formatted HTML.
     */
    public function col_status(object $row): string {
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
            default:
                $class .= 'badge-secondary bg-secondary';
        }
        $label = s(get_string('status_' . $row->status, 'local_webhookengine'));
        if (!empty($row->errorcode)) {
            $label .= ' (' . s($row->errorcode) . ')';
        }
        return html_writer::span($label, $class);
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
            $replayurl = new moodle_url('/local/webhookengine/action.php', [
                'action' => 'replay',
                'deliveryid' => $row->id,
                'sesskey' => sesskey(),
            ]);
            $btn = html_writer::link($replayurl, get_string('replay', 'local_webhookengine'), [
                'class' => 'btn btn-sm btn-outline-primary',
            ]);
            return $btn;
        }

        return '';
    }
}
