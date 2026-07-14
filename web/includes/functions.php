<?php

declare(strict_types=1);

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatReportDate(?string $iso, string $fallback = 'unknown'): string
{
    if ($iso === null || $iso === '') {
        return $fallback;
    }
    try {
        $dt = new DateTimeImmutable($iso);
        $tz = date_default_timezone_get();
        if ($tz !== '') {
            $dt = $dt->setTimezone(new DateTimeZone($tz));
        }
        return $dt->format('Y-m-d H:i:s T');
    } catch (Throwable $e) {
        return $fallback;
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(): void
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $body = $_POST['csrf_token'] ?? '';
    $token = is_string($header) && $header !== '' ? $header : (string)$body;

    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }
}

function jsonResponse(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function requireBasicAuth(array $auth): void
{
    $providedUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $providedPass = $_SERVER['PHP_AUTH_PW'] ?? '';

    $valid = is_string($providedUser)
        && is_string($providedPass)
        && $providedUser === ($auth['username'] ?? '')
        && password_verify($providedPass, $auth['password'] ?? '');

    if (!$valid) {
        header('WWW-Authenticate: Basic realm="File Cleaner Web"');
        http_response_code(401);
        exit('Authentication required');
    }
}

function validateConfigPath(string $path, array $allowedPrefixes): string
{
    $real = realpath($path);
    if ($real === false) {
        $real = $path;
    }

    foreach ($allowedPrefixes as $prefix) {
        $realPrefix = realpath($prefix);
        if ($realPrefix === false) {
            $realPrefix = $prefix;
        }
        if (str_starts_with($real, $realPrefix)) {
            return $path;
        }
    }

    throw new RuntimeException('Config path is outside allowed directories');
}

function ensureWritableDir(string $path): void
{
    if (!is_dir($path)) {
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException("Cannot create directory: {$path}");
        }
    }
    if (!is_writable($path)) {
        throw new RuntimeException("Directory is not writable: {$path}");
    }
}

/**
 * Paginate an array and return a slice plus pagination metadata.
 *
 * @param array<int|string, mixed> $items
 * @return array{items: array<int|string, mixed>, page: int, perPage: int, total: int, totalPages: int}
 */
function paginate(array $items, int $page = 1, int $perPage = 20): array
{
    $page = max(1, $page);
    $perPage = max(1, $perPage);
    $total = count($items);
    $totalPages = (int) ceil($total / $perPage);
    $page = min($page, max(1, $totalPages));
    $offset = ($page - 1) * $perPage;
    return [
        'items' => array_slice($items, $offset, $perPage, true),
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'totalPages' => $totalPages,
    ];
}

/**
 * Render simple pagination controls for a paginated result.
 */
function renderPagination(array $pagination, string $baseUrl, string $pageKey = 'page'): string
{
    if ($pagination['totalPages'] <= 1) {
        return '';
    }

    $page = $pagination['page'];
    $totalPages = $pagination['totalPages'];
    $links = [];

    if ($page > 1) {
        $links[] = '<a class="btn btn-secondary" href="' . h(addUrlParam($baseUrl, $pageKey, (string)($page - 1))) . '">Previous</a>';
    }

    for ($i = 1; $i <= $totalPages; $i++) {
        if ($i === $page) {
            $links[] = '<span class="btn btn-primary active">' . h((string)$i) . '</span>';
        } else {
            $links[] = '<a class="btn btn-secondary" href="' . h(addUrlParam($baseUrl, $pageKey, (string)$i)) . '">' . h((string)$i) . '</a>';
        }
    }

    if ($page < $totalPages) {
        $links[] = '<a class="btn btn-secondary" href="' . h(addUrlParam($baseUrl, $pageKey, (string)($page + 1))) . '">Next</a>';
    }

    return '<div class="actions" style="margin-top:1rem;justify-content:center">' . implode(' ', $links) . '</div>';
}

function addUrlParam(string $url, string $key, string $value): string
{
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . urlencode($key) . '=' . urlencode($value);
}

function binaryPath(array $config): string
{
    $path = $config['binary_path'] ?? '';
    if ($path === '') {
        throw new RuntimeException('Binary path not configured');
    }
    if (!is_executable($path)) {
        throw new RuntimeException("Binary not executable or missing: {$path}");
    }
    return $path;
}

function listReports(string $reportDir): array
{
    if (!is_dir($reportDir)) {
        return [];
    }

    $groups = [];
    $iterator = new DirectoryIterator($reportDir);
    foreach ($iterator as $file) {
        if ($file->isDot() || !$file->isFile()) {
            continue;
        }
        $name = $file->getFilename();
        if (!preg_match('/^(report-\d{8}-\d{6})\.(json|html|csv)$/', $name, $matches)) {
            continue;
        }
        $prefix = $matches[1];
        $ext = $matches[2];
        if (!isset($groups[$prefix])) {
            $groups[$prefix] = [
                'prefix' => $prefix,
                'files' => [],
                'modified' => $file->getMTime(),
            ];
        }
        $groups[$prefix]['files'][$ext] = [
            'filename' => $name,
            'type' => $ext,
            'size' => $file->getSize(),
        ];
        if ($file->getMTime() > $groups[$prefix]['modified']) {
            $groups[$prefix]['modified'] = $file->getMTime();
        }
    }

    $reports = [];
    $typeOrder = ['html' => 0, 'json' => 1, 'csv' => 2];
    foreach ($groups as $prefix => $group) {
        $totalSize = array_sum(array_column($group['files'], 'size'));
        $availableTypes = array_keys($group['files']);
        usort($availableTypes, static fn(string $a, string $b): int => ($typeOrder[$a] ?? 99) <=> ($typeOrder[$b] ?? 99));
        $primaryType = in_array('json', $availableTypes, true) ? 'json' : ($availableTypes[0] ?? 'json');
        $reports[] = [
            'prefix' => $prefix,
            'filename' => $prefix . '.' . $primaryType,
            'types' => $availableTypes,
            'type' => $primaryType,
            'size' => $totalSize,
            'modified' => date('Y-m-d H:i:s T', $group['modified']),
            'files' => $group['files'],
        ];
    }

    usort($reports, static fn(array $a, array $b): int => strcmp($b['filename'], $a['filename']));
    return $reports;
}

function loadReportJson(string $reportDir, string $filename): array
{
    if (!preg_match('/^report-\d{8}-\d{6}\.json$/', $filename)) {
        throw new RuntimeException('Invalid report filename');
    }
    $path = $reportDir . '/' . $filename;
    $real = realpath($path);
    if ($real === false || !str_starts_with($real, realpath($reportDir))) {
        throw new RuntimeException('Report path is invalid');
    }
    $content = file_get_contents($real);
    if ($content === false) {
        throw new RuntimeException('Cannot read report');
    }
    $data = json_decode($content, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid report JSON');
    }
    return $data;
}

function listQuarantineLogs(string $quarantineDir): array
{
    if (!is_dir($quarantineDir)) {
        return [];
    }

    $logs = [];
    $iterator = new DirectoryIterator($quarantineDir);
    foreach ($iterator as $sessionDir) {
        if ($sessionDir->isDot() || !$sessionDir->isDir()) {
            continue;
        }
        $logPath = $sessionDir->getPathname() . '/quarantine-log.json';
        if (!is_file($logPath)) {
            continue;
        }
        $content = file_get_contents($logPath);
        if ($content === false) {
            continue;
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            continue;
        }
        $logs[] = [
            'filename' => $sessionDir->getFilename() . '/quarantine-log.json',
            'session' => $sessionDir->getFilename(),
            'created_at' => formatReportDate($data['created_at'] ?? null),
            'operations' => count($data['operations'] ?? []),
            'size' => filesize($logPath),
            'modified' => date('Y-m-d H:i:s T', filemtime($logPath)),
        ];
    }

    usort($logs, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
    return $logs;
}

function loadQuarantineLog(string $quarantineDir, string $filename): array
{
    if (!preg_match('/^session-\d+-\/quarantine-log\.json$/', $filename)) {
        throw new RuntimeException('Invalid quarantine log filename');
    }
    $path = $quarantineDir . '/' . $filename;
    $real = realpath($path);
    if ($real === false || !str_starts_with($real, realpath($quarantineDir))) {
        throw new RuntimeException('Quarantine log path is invalid');
    }
    $content = file_get_contents($real);
    if ($content === false) {
        throw new RuntimeException('Cannot read quarantine log');
    }
    $data = json_decode($content, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid quarantine log JSON');
    }
    return $data;
}

class FileCleanerRunner
{
    private string $binary;
    private int $timeout;

    public function __construct(string $binary, int $timeout)
    {
        $this->binary = $binary;
        $this->timeout = $timeout;
    }

    public function scan(string $configPath, string $format = 'json', bool $dryRun = true): array
    {
        $allowedFormats = ['text', 'json', 'csv', 'html'];
        if (!in_array($format, $allowedFormats, true)) {
            throw new InvalidArgumentException('Invalid report format');
        }

        $args = [
            escapeshellarg($this->binary),
            'scan',
            '--config',
            escapeshellarg($configPath),
            '--format',
            escapeshellarg($format),
        ];
        if ($dryRun) {
            $args[] = '--dry-run';
        }

        $command = implode(' ', $args) . ' 2>&1';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Run with the binary's parent directory as working directory so relative
        // report_dir/quarantine_dir paths in config.json resolve predictably.
        $cwd = dirname($this->binary);
        if ($cwd === '' || !is_dir($cwd)) {
            $cwd = null;
        }

        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start scan process');
        }

        stream_set_timeout($pipes[1], $this->timeout);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => $output !== false ? $output : '',
        ];
    }

    public function approve(string $reportPath): array
    {
        $command = implode(' ', [
            escapeshellarg($this->binary),
            'approve',
            '--yes',
            escapeshellarg($reportPath),
            '2>&1',
        ]);
        return $this->execWithTimeout($command);
    }

    public function apply(string $reportPath, bool $yes = false): array
    {
        $args = [
            escapeshellarg($this->binary),
            'apply',
            escapeshellarg($reportPath),
        ];
        if ($yes) {
            $args[] = '--yes';
        }
        $command = implode(' ', $args) . ' 2>&1';
        return $this->execWithTimeout($command);
    }

    public function restore(string $logPath, bool $yes = false): array
    {
        $args = [
            escapeshellarg($this->binary),
            'restore',
            escapeshellarg($logPath),
        ];
        if ($yes) {
            $args[] = '--yes';
        }
        $command = implode(' ', $args) . ' 2>&1';
        return $this->execWithTimeout($command);
    }

    private function execWithTimeout(string $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cwd = dirname($this->binary);
        if ($cwd === '' || !is_dir($cwd)) {
            $cwd = null;
        }

        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start process');
        }

        stream_set_timeout($pipes[1], $this->timeout);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => $output !== false ? $output : '',
        ];
    }
}
