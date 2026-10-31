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

namespace local_webhookengine;

/**
 * Standardized reason codes for delivery outcomes, drops, and throttling.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reasons {
    /** @var string Event dropped due to per-hook rate limit cap. */
    public const RATE_LIMITED = 'rate_limited';

    /** @var string Event dropped due to global queue threshold overload. */
    public const QUEUE_OVERLOAD = 'queue_overload';

    /** @var string Event skipped/dropped because hook is paused. */
    public const HOOK_PAUSED = 'hook_paused';

    /** @var string Event dropped due to global emergency kill switch. */
    public const KILL_SWITCH = 'killswitch_enabled';

    /** @var string Event dropped because target hook accumulated too many retrying failures. */
    public const HOOK_BACKLOG_EXCEEDED = 'hook_backlog_exceeded';

    /** @var string Delivery dropped at dispatch time because hook was disabled or deleted. */
    public const HOOK_DISABLED_OR_MISSING = 'hook_disabled_or_missing';

    /** @var string Drop recorded due to an unexpected exception inside observer processing. */
    public const OBSERVER_ERROR = 'observer_error';
}
