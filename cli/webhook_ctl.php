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
 * Command-line administration tool for local_webhookengine.
 *
 * Provides diagnostic health checks, configuration export/import, and circuit breaker controls.
 *
 * @package    local_webhookengine
 * @copyright  2026 Definite Labs <support@thedefinite.one>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_webhookengine\circuit_breaker;
use local_webhookengine\hook_repository;
use local_webhookengine\rate_limiter;
use local_webhookengine\telemetry;
use local_webhookengine\url_validator;

$longoptions = [
    'help' => false,
    'health' => false,
    'list' => false,
    'export' => '',
    'import' => '',
    'reset-circuit' => 0,
];

$shortoptions = [
    'h' => 'help',
];

[$options, $unrecognized] = cli_get_params($longoptions, $shortoptions);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized), 2);
}

$noaction = (
    !$options['health'] &&
    !$options['list'] &&
    empty($options['export']) &&
    empty($options['import']) &&
    empty($options['reset-circuit'])
);

if ($options['help'] || $noaction) {
    $help = "Command-line administration tool for Event Webhooks Engine.

Options:
  -h, --help            Print out this help message
  --health              Run system diagnostics and display fleet health
  --list                List all configured webhook destinations
  --export[=FILE]       Export webhook configurations to JSON file (secrets sanitized)
  --import=FILE         Import webhook configurations from a JSON file
  --reset-circuit=ID    Reset a tripped circuit breaker back to healthy closed state

Examples:
  \$ php local/webhookengine/cli/webhook_ctl.php --health
  \$ php local/webhookengine/cli/webhook_ctl.php --list
  \$ php local/webhookengine/cli/webhook_ctl.php --export=backup.json
  \$ php local/webhookengine/cli/webhook_ctl.php --import=backup.json
  \$ php local/webhookengine/cli/webhook_ctl.php --reset-circuit=5
";
    cli_write($help);
    exit(0);
}

// 1. Health Diagnostics.
if ($options['health']) {
    cli_heading('Event Webhooks Engine - System Health Diagnostic');

    $sysmetrics = telemetry::get_system_metrics(true);
    $killswitch = (bool) get_config('local_webhookengine', 'killswitch');

    cli_writeln('System Operational Status: ' . ($killswitch ? '[KILL SWITCH ACTIVE]' : 'OPERATIONAL'));
    cli_writeln('Pending Queue Depth:       ' . $sysmetrics['queuedcount'] . ' deliveries (queued/retrying)');
    cli_writeln('24h Delivery Success:      ' . $sysmetrics['successrate_display']);
    cli_writeln('p95 Latency Percentile:    ' . $sysmetrics['p95_display']);
    cli_writeln('Total Recorded Drops:      ' . $sysmetrics['totaldrops']);
    cli_writeln('Circuit Breakers Status:   ' . $sysmetrics['circuits_status_display']);

    if (!empty($sysmetrics['oldestage'])) {
        cli_writeln('Oldest Queued Age:         ' . $sysmetrics['oldestage']);
    }

    // Check circuit breaker status per hook.
    global $DB;
    $trippedhooks = $DB->get_records('local_webhookengine_hook', [
        'circuitstate' => circuit_breaker::STATE_OPEN,
    ]);

    if (!empty($trippedhooks)) {
        cli_writeln("\n[WARNING] Tripped Endpoints:");
        foreach ($trippedhooks as $thook) {
            $cooldownremaining = max(0, (int) $thook->circuitcooldownuntil - time());
            cli_writeln(sprintf(
                '  - #%d %s (%s): streak=%d, cooldown=%ds remaining',
                $thook->id,
                $thook->name,
                $thook->url,
                $thook->failstreak,
                $cooldownremaining
            ));
        }
    }

    exit($killswitch || !empty($trippedhooks) ? 1 : 0);
}

// 2. List Webhooks.
if ($options['list']) {
    cli_heading('Configured Webhook Destinations');

    $hooks = hook_repository::get_hooks();
    if (empty($hooks)) {
        cli_writeln('No active webhooks configured.');
        exit(0);
    }

    cli_writeln(sprintf(
        "%-4s %-25s %-35s %-8s %-12s %-6s %-8s",
        'ID',
        'NAME',
        'DESTINATION URL',
        'STATUS',
        'CIRCUIT',
        'STREAK',
        'SUCCESS%'
    ));
    cli_writeln(str_repeat('-', 102));

    foreach ($hooks as $hook) {
        $circuit = circuit_breaker::get_state($hook);
        $metrics = telemetry::get_hook_metrics($hook->id);
        $urlshort = strlen($hook->url) > 35 ? substr($hook->url, 0, 32) . '...' : $hook->url;
        $nameshort = strlen($hook->name) > 25 ? substr($hook->name, 0, 22) . '...' : $hook->name;

        cli_writeln(sprintf(
            "%-4d %-25s %-35s %-8s %-12s %-6d %-8s",
            $hook->id,
            $nameshort,
            $urlshort,
            $hook->status,
            $circuit,
            $hook->failstreak,
            $metrics['successrate_display']
        ));
    }

    exit(0);
}

// 3. Reset Circuit Breaker.
if (!empty($options['reset-circuit'])) {
    $hookid = (int) $options['reset-circuit'];
    $hook = hook_repository::get_hook($hookid);
    if (!$hook) {
        cli_error('Error: Webhook with ID ' . $hookid . ' not found.');
    }

    $res = circuit_breaker::reset_circuit($hookid);
    rate_limiter::release_all_hook_slots($hookid);

    if ($res) {
        $msg = sprintf('Circuit breaker for hook #%d ("%s") reset to healthy closed state.', $hookid, $hook->name);
        cli_writeln($msg);
        exit(0);
    } else {
        cli_error('Error: Failed to reset circuit breaker for hook #' . $hookid . '.');
    }
}

// 4. Export Configurations.
if ($options['export'] !== false && $options['export'] !== '') {
    $targetfile = is_string($options['export']) && $options['export'] !== '1'
        ? $options['export']
        : 'webhook_export_' . date('Ymd_His') . '.json';

    cli_heading('Exporting Webhook Configurations');

    $hooks = hook_repository::get_hooks();
    $exportdata = [
        'version' => '1.0.3',
        'exported_at' => time(),
        'site_url' => $CFG->wwwroot,
        'hooks' => [],
    ];

    foreach ($hooks as $hook) {
        $exportdata['hooks'][] = [
            'name' => $hook->name,
            'url' => $hook->url,
            'enabled' => (int) $hook->enabled,
            'status' => $hook->status,
            'events' => json_decode($hook->events, true) ?: [],
            'filters' => json_decode($hook->filters, true) ?: null,
            'payloadopts' => json_decode($hook->payloadopts, true) ?: null,
            'timeout' => (int) $hook->timeout,
            'maxattempts' => (int) $hook->maxattempts,
            'usemtls' => (int) ($hook->usemtls ?? 0),
            'authmode' => $hook->authmode ?? 'standard',
            'oauth2tokenurl' => $hook->oauth2tokenurl ?? null,
            'oauth2clientid' => $hook->oauth2clientid ?? null,
            'oauth2clientsecret' => !empty($hook->oauth2clientsecret) ? '[REDACTED_CLI_MANAGED_KEY]' : null,
            'oauth2scope' => $hook->oauth2scope ?? null,
            'secret' => '[REDACTED_CLI_MANAGED_KEY]',
            'headers' => null, // Never export sensitive authorization headers.
        ];
    }

    $json = json_encode($exportdata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $bytes = file_put_contents($targetfile, $json);

    if ($bytes === false) {
        cli_error('Error: Unable to write export file to: ' . $targetfile);
    }

    cli_writeln('Successfully exported ' . count($exportdata['hooks']) . ' webhook configurations to: ' . $targetfile);
    cli_writeln('Note: Signing secrets and custom headers are securely redacted from export files.');
    exit(0);
}

// 5. Import Configurations.
if (!empty($options['import'])) {
    $sourcefile = $options['import'];
    cli_heading('Importing Webhook Configurations');

    if (!file_exists($sourcefile)) {
        cli_error('Error: Import file not found: ' . $sourcefile);
    }

    $raw = file_get_contents($sourcefile);
    $data = @json_decode($raw, true);

    if (empty($data) || !isset($data['hooks']) || !is_array($data['hooks'])) {
        cli_error('Error: Invalid JSON format. Expected root "hooks" array.');
    }

    global $DB, $USER;
    $transaction = $DB->start_delegated_transaction();

    $imported = 0;
    $now = time();
    $adminuser = get_admin();
    $creatorid = $adminuser ? (int) $adminuser->id : 2;

    foreach ($data['hooks'] as $item) {
        if (empty($item['name']) || empty($item['url']) || empty($item['events'])) {
            cli_writeln('[SKIP] Hook record missing mandatory fields (name, url, or events).');
            continue;
        }

        // Validate URL.
        if (!url_validator::validate($item['url'])) {
            cli_writeln('[SKIP] Invalid destination URL: ' . $item['url']);
            continue;
        }

        // Generate fresh 32-byte cryptographic secret.
        $newsecret = random_bytes(32);
        $encryptedsecret = hook_repository::encrypt_secret($newsecret);

        $record = (object) [
            'name' => clean_param($item['name'], PARAM_TEXT),
            'url' => clean_param($item['url'], PARAM_URL),
            'secret' => $encryptedsecret,
            'prevsecret' => null,
            'secretrotatedat' => null,
            'enabled' => isset($item['enabled']) ? (int) $item['enabled'] : 1,
            'status' => in_array($item['status'] ?? '', ['active', 'paused']) ? $item['status'] : 'active',
            'events' => json_encode(array_values((array) $item['events'])),
            'filters' => !empty($item['filters']) ? json_encode($item['filters']) : null,
            'payloadopts' => !empty($item['payloadopts']) ? json_encode($item['payloadopts']) : null,
            'headers' => null,
            'timeout' => isset($item['timeout']) ? min(30, max(1, (int) $item['timeout'])) : 10,
            'maxattempts' => isset($item['maxattempts']) ? min(10, max(1, (int) $item['maxattempts'])) : 9,
            'usemtls' => !empty($item['usemtls']) ? 1 : 0,
            'authmode' => in_array($item['authmode'] ?? '', ['standard', 'oauth2']) ? $item['authmode'] : 'standard',
            'oauth2tokenurl' => !empty($item['oauth2tokenurl']) ? clean_param($item['oauth2tokenurl'], PARAM_URL) : null,
            'oauth2clientid' => !empty($item['oauth2clientid']) ? clean_param($item['oauth2clientid'], PARAM_TEXT) : null,
            'oauth2clientsecret' => null,
            'oauth2scope' => !empty($item['oauth2scope']) ? clean_param($item['oauth2scope'], PARAM_TEXT) : null,
            'failstreak' => 0,
            'circuitstate' => circuit_breaker::STATE_CLOSED,
            'circuitcooldownuntil' => 0,
            'lastsuccess' => null,
            'lastfailure' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'createdby' => $creatorid,
        ];

        $DB->insert_record('local_webhookengine_hook', $record);
        $imported++;
    }

    $transaction->allow_commit();

    // Purge event map cache.
    try {
        $cache = \cache::make('local_webhookengine', 'eventmap');
        $cache->purge();
    } catch (\Throwable $e) {
        unset($e);
    }

    cli_writeln('Successfully imported ' . $imported . ' webhook configurations.');
    exit(0);
}
