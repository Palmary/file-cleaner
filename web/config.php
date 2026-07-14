<?php

declare(strict_types=1);

/**
 * Web UI configuration.
 *
 * Copy this file to config.local.php and adjust for your environment.
 * config.local.php, if present, overrides these defaults.
 */

return [
    // Title shown in the web interface.
    'app_name' => 'File Cleaner',

    // Path to the file-cleaner Rust binary.
    // Relative to the web/ directory, or absolute.
    'binary_path' => __DIR__ . '/../target/release/file-cleaner',

    // Default config file used by the web UI.
    // Using web/config.json keeps web-managed settings isolated.
    'default_config_path' => __DIR__ . '/config.json',

    // Default scan settings shown on the dashboard.
    'default_report_format' => 'html',
    'default_dry_run' => true,

    // Directory where the Rust tool writes reports.
    'report_dir' => __DIR__ . '/../reports',

    // Directory where the Rust tool writes quarantine data.
    'quarantine_dir' => __DIR__ . '/../quarantine',

    // Allow creating/editing config files via the configurator.
    'enable_configurator' => true,

    // Enable the JSON API endpoints.
    'enable_api' => true,

    // Require a simple API key for external integration endpoints.
    // Leave empty to disable.
    'api_key' => '',

    // Maximum execution time (seconds) for a scan.
    'max_execution_time' => 600,

    // Maximum memory limit for PHP.
    'memory_limit' => '512M',

    // Optional basic authentication.
    // 'auth' => [
    //     'username' => 'admin',
    //     'password' => password_hash('secret', PASSWORD_DEFAULT),
    // ],

    // Allowed config file locations (for security).
    // Config paths outside these prefixes will be rejected.
    'allowed_config_prefixes' => [
        realpath(__DIR__ . '/..') ?: __DIR__ . '/..',
    ],

    // Timezone for displayed dates.
    'timezone' => 'Asia/Hong_Kong',

    // UI language: en, zh (Traditional Chinese), zhs (Simplified Chinese).
    'language' => 'en',

    // App version shown in the footer.
    'app_version' => '1.0',
];
