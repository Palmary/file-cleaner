<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($config['enable_api'])) {
    jsonResponse(['error' => 'API is disabled'], 403);
}

// API key authentication for external integration.
if (!empty($config['api_key'])) {
    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? $_POST['api_key'] ?? '';
    if (!is_string($providedKey) || !hash_equals($config['api_key'], $providedKey)) {
        jsonResponse(['error' => 'Invalid or missing API key'], 401);
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'ping') {
    jsonResponse([
        'ok' => true,
        'app' => $config['app_name'] ?? 'File Cleaner Web',
        'time' => date('c'),
    ]);
}

if ($action === 'reports') {
    try {
        $reports = listReports($config['report_dir']);
        jsonResponse(['reports' => $reports]);
    } catch (Throwable $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

if ($action === 'report') {
    try {
        $filename = $_GET['file'] ?? '';
        $data = loadReportJson($config['report_dir'], (string)$filename);
        jsonResponse(['report' => $data]);
    } catch (Throwable $e) {
        jsonResponse(['error' => $e->getMessage()], 404);
    }
}

if ($action === 'scan') {
    if ($method !== 'POST') {
        jsonResponse(['error' => 'POST required'], 405);
    }

    try {
        $configPath = $_POST['config_path'] ?? $config['default_config_path'] ?? '';
        validateConfigPath((string)$configPath, $config['allowed_config_prefixes']);

        $format = in_array($_POST['format'] ?? '', ['text', 'json', 'csv', 'html'], true)
            ? $_POST['format']
            : 'json';
        $dryRun = !isset($_POST['dry_run']) || $_POST['dry_run'] !== '0';

        set_time_limit($config['max_execution_time']);
        $runner = new FileCleanerRunner(binaryPath($config), $config['max_execution_time']);
        $result = $runner->scan($configPath, $format, $dryRun);

        $reports = listReports($config['report_dir']);
        $latestJson = null;
        foreach ($reports as $report) {
            if ($report['type'] === 'json') {
                $latestJson = $report['filename'];
                break;
            }
        }

        $maxOutputLength = 5000;
        $output = $result['output'];
        $truncated = false;
        if (strlen($output) > $maxOutputLength) {
            $output = substr($output, 0, $maxOutputLength) . "\n... [truncated]";
            $truncated = true;
        }

        jsonResponse([
            'success' => $result['success'],
            'exit_code' => $result['exit_code'],
            'output' => $output,
            'output_truncated' => $truncated,
            'latest_json_report' => $latestJson,
            'latest_json_report_url' => $latestJson !== null
                ? 'reports.php?file=' . urlencode($latestJson)
                : null,
        ]);
    } catch (Throwable $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

if ($action === 'approve') {
    if ($method !== 'POST') {
        jsonResponse(['error' => 'POST required'], 405);
    }
    try {
        $reportPath = $_POST['report_path'] ?? '';
        if ($reportPath === '') {
            jsonResponse(['error' => 'report_path required'], 400);
        }
        validateConfigPath((string)$reportPath, array_merge($config['allowed_config_prefixes'], [$config['report_dir']]));
        $runner = new FileCleanerRunner(binaryPath($config), $config['max_execution_time']);
        jsonResponse($runner->approve((string)$reportPath));
    } catch (Throwable $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

if ($action === 'apply') {
    if ($method !== 'POST') {
        jsonResponse(['error' => 'POST required'], 405);
    }
    try {
        $reportPath = $_POST['report_path'] ?? '';
        if ($reportPath === '') {
            jsonResponse(['error' => 'report_path required'], 400);
        }
        validateConfigPath((string)$reportPath, array_merge($config['allowed_config_prefixes'], [$config['report_dir']]));
        $yes = !empty($_POST['yes']);
        $runner = new FileCleanerRunner(binaryPath($config), $config['max_execution_time']);
        jsonResponse($runner->apply((string)$reportPath, $yes));
    } catch (Throwable $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

if ($action === 'restore') {
    if ($method !== 'POST') {
        jsonResponse(['error' => 'POST required'], 405);
    }
    try {
        $logPath = $_POST['log_path'] ?? '';
        if ($logPath === '') {
            jsonResponse(['error' => 'log_path required'], 400);
        }
        validateConfigPath((string)$logPath, array_merge($config['allowed_config_prefixes'], [$config['quarantine_dir']]));
        $yes = !empty($_POST['yes']);
        $runner = new FileCleanerRunner(binaryPath($config), $config['max_execution_time']);
        jsonResponse($runner->restore((string)$logPath, $yes));
    } catch (Throwable $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

// API documentation fallback for browser access.
if ($method === 'GET') {
    jsonResponse([
        'endpoints' => [
            'GET  /api.php?action=ping' => 'Health check',
            'GET  /api.php?action=reports' => 'List generated reports',
            'GET  /api.php?action=report&file=report-YYYYMMDD-HHMMSS.json' => 'Get report JSON',
            'POST /api.php?action=scan' => 'Run scan (config_path, format, dry_run)',
            'POST /api.php?action=approve' => 'Approve a JSON report (report_path)',
            'POST /api.php?action=apply' => 'Apply approved report (report_path, yes)',
            'POST /api.php?action=restore' => 'Restore quarantined files (log_path, yes)',
        ],
        'auth' => empty($config['api_key']) ? 'none' : 'X-API-Key header or api_key query/body parameter',
    ]);
}

jsonResponse(['error' => 'Unknown action'], 400);
