<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$config = require __DIR__ . '/../config.php';

$localConfigPath = __DIR__ . '/../config.local.php';
if (file_exists($localConfigPath)) {
    $local = require $localConfigPath;
    if (is_array($local)) {
        $config = array_merge($config, $local);
    }
}

require_once __DIR__ . '/functions.php';

// Allow the Rust config JSON to override the web UI display timezone.
if (!empty($config['default_config_path']) && is_file($config['default_config_path'])) {
    $jsonConfigContent = file_get_contents($config['default_config_path']);
    if ($jsonConfigContent !== false) {
        $jsonConfig = json_decode($jsonConfigContent, true);
        if (is_array($jsonConfig) && !empty($jsonConfig['display_timezone'])) {
            $tz = $jsonConfig['display_timezone'];
            if (in_array($tz, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true)) {
                $config['timezone'] = $tz;
            }
        }
    }
}

if (!empty($config['timezone'])) {
    date_default_timezone_set($config['timezone']);
}

// Load translations (allow session override).
$lang = $_SESSION['language'] ?? $config['language'] ?? 'en';
$langFile = __DIR__ . '/../lang/' . preg_replace('/[^a-z]/', '', $lang) . '.php';

// Persist language and timezone into web/config.json so Rust scan uses them.
if (!empty($config['default_config_path']) && is_file($config['default_config_path'])) {
    $jsonConfigPath = $config['default_config_path'];
    $jsonConfigContent = file_get_contents($jsonConfigPath);
    if ($jsonConfigContent !== false) {
        $jsonConfig = json_decode($jsonConfigContent, true);
        if (is_array($jsonConfig)) {
            $needsUpdate = false;
            $currentTz = $config['timezone'] ?? 'Asia/Hong_Kong';
            if (!isset($jsonConfig['display_timezone']) || $jsonConfig['display_timezone'] !== $currentTz) {
                $jsonConfig['display_timezone'] = $currentTz;
                $needsUpdate = true;
            }
            $currentLang = $config['language'] ?? 'en';
            if (!isset($jsonConfig['display_language']) || $jsonConfig['display_language'] !== $currentLang) {
                $jsonConfig['display_language'] = $currentLang;
                $needsUpdate = true;
            }
            // Sync media roots from the project root config.json so isolated
            // folders added there are also scanned from the web UI.
            // Boolean flags set via the Web UI Configurator are kept as-is and
            // are NOT overwritten by the root config.json.
            $rootConfigPath = dirname(dirname(__DIR__)) . '/config.json';
            if (is_file($rootConfigPath)) {
                $rootConfigContent = file_get_contents($rootConfigPath);
                if ($rootConfigContent !== false) {
                    $rootConfig = json_decode($rootConfigContent, true);
                    if (is_array($rootConfig) && !empty($rootConfig['media_roots'])) {
                        $webMedia = $jsonConfig['media_roots'] ?? [];
                        sort($webMedia);
                        $rootMedia = $rootConfig['media_roots'];
                        sort($rootMedia);
                        if ($webMedia !== $rootMedia) {
                            $jsonConfig['media_roots'] = $rootConfig['media_roots'];
                            $needsUpdate = true;
                        }
                    }
                }
            }
            if ($needsUpdate && is_writable(dirname($jsonConfigPath)) && is_writable($jsonConfigPath)) {
                file_put_contents($jsonConfigPath, json_encode($jsonConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }
    }
}
$translations = [];
if (is_file($langFile)) {
    $loaded = require $langFile;
    if (is_array($loaded)) {
        $translations = $loaded;
    }
}

function __(string $key, array $replacements = []): string
{
    global $translations;
    $text = $translations[$key] ?? $key;
    foreach ($replacements as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

if (!empty($config['memory_limit'])) {
    ini_set('memory_limit', $config['memory_limit']);
}

// Optional basic auth.
if (!empty($config['auth']) && is_array($config['auth'])) {
    requireBasicAuth($config['auth']);
}
