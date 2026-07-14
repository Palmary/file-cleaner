<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$binary = $config['binary_path'] ?? __DIR__ . '/../target/release/file-cleaner';
$configPath = $_GET['config'] ?? $config['default_config_path'] ?? __DIR__ . '/../config.json';

$testCommand = escapeshellarg($binary) . ' --help 2>&1';
exec($testCommand, $helpOutput, $helpCode);

$scanCommand = sprintf(
    '%s scan --config %s --dry-run --format json 2>&1',
    escapeshellarg($binary),
    escapeshellarg($configPath)
);

$start = microtime(true);
exec($scanCommand, $scanOutput, $scanCode);
$duration = round(microtime(true) - $start, 3);

$result = [
    'php_user' => function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user(),
    'binary_path' => $binary,
    'binary_exists' => file_exists($binary),
    'binary_executable' => is_executable($binary),
    'binary_help_exit_code' => $helpCode,
    'binary_help_output' => implode("\n", $helpOutput),
    'config_path' => $configPath,
    'config_exists' => file_exists($configPath),
    'config_readable' => is_readable($configPath),
    'reports_writable' => is_writable(dirname($configPath) . '/reports'),
    'quarantine_writable' => is_writable(dirname($configPath) . '/quarantine'),
    'scan_exit_code' => $scanCode,
    'scan_duration_seconds' => $duration,
    'scan_output_head' => implode("\n", array_slice($scanOutput, 0, 50)),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
