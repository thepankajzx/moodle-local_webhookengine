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

/**
 * Dedicated secure one-time secret presentation page.
 *
 * Implements strict non-cacheable responses (Cache-Control: no-store) to prevent
 * secrets from being cached in browser history, proxy logs, or session stores.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Strict non-caching HTTP headers sent before any output.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_login();
$context = context_system::instance();
require_capability('local/webhookengine:manage', $context);

$id = required_param('id', PARAM_INT);
$token = required_param('token', PARAM_ALPHANUM);
$isrotated = optional_param('rotated', 0, PARAM_INT);

$pageurl = new moodle_url('/local/webhookengine/secret_reveal.php', ['id' => $id, 'token' => $token]);
$PAGE->set_context($context);
$PAGE->set_url($pageurl);

$heading = $isrotated
    ? get_string('secret_rotated_title', 'local_webhookengine')
    : get_string('new_secret_title', 'local_webhookengine');

$PAGE->set_title($heading);
$PAGE->set_heading($heading);

// Retrieve and IMMEDIATELY destroy one-time token from cache.
$cache = \cache::make('local_webhookengine', 'ratelimit');
$cachekey = 'secret_reveal_' . $token;
$secretdata = $cache->get($cachekey);

if ($secretdata !== false) {
    // Single-use destruction: token cannot be reused or reloaded.
    $cache->delete($cachekey);
}

// Verify token authenticity and user match.
$isvalid = ($secretdata !== false
    && is_array($secretdata)
    && (int) ($secretdata['hookid'] ?? 0) === $id
    && (int) ($secretdata['userid'] ?? 0) === (int) $USER->id
    && !empty($secretdata['secret']));

echo $OUTPUT->header();

$manageurl = new moodle_url('/local/webhookengine/manage.php');

if ($isvalid) {
    $rawsecret = (string) $secretdata['secret'];

    $copybtn = html_writer::tag('button', get_string('copy_secret', 'local_webhookengine'), [
        'type' => 'button',
        'class' => 'btn btn-primary ms-2',
        'onclick' => 'navigator.clipboard.writeText(' . json_encode($rawsecret) . ').then(function() { alert(' .
            json_encode(get_string('secret_copied', 'local_webhookengine')) . '); });',
    ]);

    $secretbox = html_writer::div(
        html_writer::tag('code', s($rawsecret), [
            'class' => 'p-2 bg-light border rounded text-monospace font-weight-bold fs-6 flex-grow-1 select-all',
            'style' => 'word-break: break-all;',
        ]) . $copybtn,
        'd-flex align-items-center flex-wrap my-3'
    );

    $cardbody = html_writer::tag('h4', $heading, ['class' => 'card-title text-success mb-3']) .
        html_writer::tag('p', get_string('new_secret_warning', 'local_webhookengine'), ['class' => 'lead fs-6 text-muted']) .
        $secretbox .
        html_writer::div(
            html_writer::link($manageurl, get_string('continue_to_manage', 'local_webhookengine'), [
                'class' => 'btn btn-outline-secondary mt-3',
            ]),
            'mt-4'
        );

    echo html_writer::div(
        html_writer::div($cardbody, 'card-body p-4'),
        'card shadow-sm border-success mb-4'
    );
} else {
    // Secret already revealed or expired.
    $notice = html_writer::div(
        html_writer::tag('h4', get_string('secret_already_revealed_title', 'local_webhookengine'), ['class' => 'alert-heading']) .
        html_writer::tag('p', get_string('secret_already_revealed_desc', 'local_webhookengine')) .
        html_writer::link($manageurl, get_string('continue_to_manage', 'local_webhookengine'), [
            'class' => 'btn btn-outline-primary mt-2',
        ]),
        'alert alert-warning py-4 px-4'
    );

    echo $notice;
}

echo $OUTPUT->footer();
