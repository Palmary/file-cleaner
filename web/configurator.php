<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($config['enable_configurator'])) {
    http_response_code(403);
    exit('Configurator is disabled');
}

$title = __('configurator_title');
$alert = null;

$configPath = $_GET['config'] ?? $config['default_config_path'] ?? '';
$configPath = (string)$configPath;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    try {
        $configPath = $_POST['config_path'] ?? $configPath;
        validateConfigPath($configPath, $config['allowed_config_prefixes']);

        $newConfig = buildConfigFromPost($_POST);
        $json = json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode config to JSON');
        }

        $dir = dirname($configPath);
        ensureWritableDir($dir);

        if (file_put_contents($configPath, $json) === false) {
            throw new RuntimeException('Failed to write config file');
        }

        $alert = ['type' => 'success', 'message' => __('config_saved', ['path' => $configPath])];
    } catch (Throwable $e) {
        $alert = ['type' => 'danger', 'message' => $e->getMessage()];
    }
}

$current = loadOrDefaultConfig($configPath);

function buildConfigFromPost(array $post): array
{
    $sourceRoots = array_values(array_filter(array_map('trim', (array)($post['source_roots'] ?? [])), static fn($v) => $v !== ''));
    $mediaRoots = array_values(array_filter(array_map('trim', (array)($post['media_roots'] ?? [])), static fn($v) => $v !== ''));
    $exclude = array_values(array_filter(array_map('trim', (array)($post['exclude_patterns'] ?? [])), static fn($v) => $v !== ''));
    $include = array_values(array_filter(array_map('trim', (array)($post['include_patterns'] ?? [])), static fn($v) => $v !== ''));
    $protected = array_values(array_filter(array_map('trim', (array)($post['protected_patterns'] ?? [])), static fn($v) => $v !== ''));

    $origins = [];
    foreach ((array)($post['origins']['origin'] ?? []) as $i => $origin) {
        $origin = trim((string)$origin);
        $localRoot = trim((string)($post['origins']['local_root'][$i] ?? ''));
        if ($origin !== '' && $localRoot !== '') {
            $origins[] = ['origin' => $origin, 'local_root' => $localRoot];
        }
    }

    $prefixMaps = [];
    foreach ((array)($post['prefix_maps']['prefix'] ?? []) as $i => $prefix) {
        $prefix = trim((string)$prefix);
        $localRoot = trim((string)($post['prefix_maps']['local_root'][$i] ?? ''));
        if ($prefix !== '' && $localRoot !== '') {
            $prefixMaps[] = ['prefix' => $prefix, 'local_root' => $localRoot];
        }
    }

    $defaultWebRoot = trim((string)($post['default_web_root'] ?? ''));
    $rootRelativeBase = trim((string)($post['root_relative_base'] ?? ''));
    $reportDir = makeAbsolute(trim((string)($post['report_dir'] ?? 'reports')));
    $quarantineDir = makeAbsolute(trim((string)($post['quarantine_dir'] ?? 'quarantine')));
    $defaultConfigPath = trim((string)($post['scan_config_path'] ?? ''));
    $defaultReportFormat = in_array($post['scan_report_format'] ?? '', ['html', 'json', 'csv'], true)
        ? $post['scan_report_format']
        : 'html';
    $defaultDryRun = !empty($post['scan_dry_run']);

    $displayTimezone = trim((string)($post['display_timezone'] ?? ''));
    if ($displayTimezone === '' || !in_array($displayTimezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true)) {
        $displayTimezone = 'Asia/Hong_Kong';
    }

    $displayLanguage = in_array($post['display_language'] ?? '', ['en', 'zh', 'zhs'], true)
        ? $post['display_language']
        : 'en';

    $mediaRootsAsDisposable = !empty($post['media_roots_as_disposable']);
    $removeEmptyFolders = !empty($post['remove_empty_folders_on_apply']);

    $result = [
        'source_roots' => $sourceRoots,
        'media_roots' => $mediaRoots,
        'origins' => $origins,
        'prefix_maps' => $prefixMaps,
        'exclude_patterns' => $exclude,
        'include_patterns' => $include,
        'protected_patterns' => $protected,
        'report_dir' => $reportDir,
        'quarantine_dir' => $quarantineDir,
        'scan_config_path' => $defaultConfigPath,
        'scan_report_format' => $defaultReportFormat,
        'scan_dry_run' => $defaultDryRun,
        'display_timezone' => $displayTimezone,
        'media_roots_as_disposable' => $mediaRootsAsDisposable,
        'remove_empty_folders_on_apply' => $removeEmptyFolders,
    ];

    if ($defaultWebRoot !== '') {
        $result['default_web_root'] = $defaultWebRoot;
    }
    if ($rootRelativeBase !== '') {
        $result['root_relative_base'] = $rootRelativeBase;
    }

    return $result;
}

function makeAbsolute(string $path): string
{
    if ($path === '') {
        return '';
    }
    if (str_starts_with($path, '/')) {
        return $path;
    }
    $projectRoot = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';
    return $projectRoot . '/' . $path;
}

function loadOrDefaultConfig(string $path): array
{
    if (file_exists($path)) {
        $content = file_get_contents($path);
        if ($content !== false) {
            $data = json_decode($content, true);
            if (is_array($data)) {
                return $data;
            }
        }
    }
    return [
        'source_roots' => [],
        'media_roots' => [],
        'origins' => [],
        'prefix_maps' => [],
        'default_web_root' => '',
        'exclude_patterns' => ['**/node_modules/**', '**/.git/**', '**/quarantine/**', '**/reports/**'],
        'include_patterns' => [],
        'protected_patterns' => [],
        'report_dir' => 'reports',
        'quarantine_dir' => 'quarantine',
        'root_relative_base' => '',
        'scan_config_path' => '',
        'scan_report_format' => 'html',
        'scan_dry_run' => true,
        'display_timezone' => 'Asia/Hong_Kong',
        'media_roots_as_disposable' => false,
        'remove_empty_folders_on_apply' => false,
    ];
}

function renderListInputs(string $name, array $values): string
{
    $html = '';
    $addNew = __('add_new');
    $remove = __('remove');
    foreach ($values as $value) {
        $html .= '<div class="form-row mb-2"><input type="text" class="form-control" name="' . h($name) . '[]" value="' . h($value) . '" placeholder="' . h($name) . '"><button type="button" class="btn btn-danger" style="flex:0 0 auto;" onclick="this.parentElement.remove()">' . h($remove) . '</button></div>';
    }
    $html .= '<div class="form-row mb-2"><input type="text" class="form-control" name="' . h($name) . '[]" value="" placeholder="' . h($addNew) . '"><button type="button" class="btn btn-danger" style="flex:0 0 auto;" onclick="this.parentElement.remove()">' . h($remove) . '</button></div>';
    return $html;
}

ob_start();
?>
<div class="card">
    <h2><?= h(__('configurator_title')) ?></h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <div class="form-group">
            <label for="config_path"><?= h(__('save_to_path')) ?></label>
            <input type="text" id="config_path" name="config_path" class="form-control" value="<?= h($configPath) ?>" required>
            <p class="help"><?= h(__('absolute_path_help')) ?></p>
        </div>

        <h3 style="margin-top: 2rem;"><?= h(__('run_scan_defaults')) ?></h3>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="scan_config_path"><?= h(__('default_config_file')) ?></label>
                    <input type="text" id="scan_config_path" name="scan_config_path" class="form-control" value="<?= h($current['scan_config_path'] ?? '') ?>" placeholder="/path/to/config.json">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label for="scan_report_format"><?= h(__('default_output_format')) ?></label>
                    <select id="scan_report_format" name="scan_report_format" class="form-select">
                        <?php $fmt = $current['scan_report_format'] ?? 'html'; ?>
                        <option value="html" <?= $fmt === 'html' ? 'selected' : '' ?>>HTML</option>
                        <option value="json" <?= $fmt === 'json' ? 'selected' : '' ?>>JSON</option>
                        <option value="csv" <?= $fmt === 'csv' ? 'selected' : '' ?>>CSV</option>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label for="scan_dry_run"><?= h(__('default_dry_run')) ?></label>
                    <select id="scan_dry_run" name="scan_dry_run" class="form-select">
                        <?php $dry = !empty($current['scan_dry_run']); ?>
                        <option value="1" <?= $dry ? 'selected' : '' ?>><?= h(__('yes')) ?></option>
                        <option value="0" <?= !$dry ? 'selected' : '' ?>><?= h(__('no')) ?></option>
                    </select>
                </div>
            </div>
        </div>

        <div class="row g-3" style="margin-top: 0rem;">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="display_timezone"><?= h(__('display_timezone')) ?></label>
                    <select id="display_timezone" name="display_timezone" class="form-select">
                        <?php $tz = $current['display_timezone'] ?? 'Asia/Hong_Kong'; ?>
                        <?php foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL) as $identifier): ?>
                            <option value="<?= h($identifier) ?>" <?= $tz === $identifier ? 'selected' : '' ?>><?= h($identifier) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="help"><?= h(__('timezone_help')) ?></p>
                </div>
            </div>
        </div>

        <h3 style="margin-top: 2rem;"><?= h(__('source_media_roots')) ?></h3>
        <div class="form-group">
            <label><?= h(__('source_roots_label')) ?></label>
            <?= renderListInputs('source_roots', $current['source_roots'] ?? []) ?>
        </div>
        <div class="form-group">
            <label><?= h(__('media_roots_label')) ?></label>
            <?= renderListInputs('media_roots', $current['media_roots'] ?? []) ?>
        </div>
        <div class="form-group">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="media_roots_as_disposable" name="media_roots_as_disposable" value="1" <?= !empty($current['media_roots_as_disposable']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="media_roots_as_disposable"><?= h(__('media_roots_as_disposable')) ?></label>
            </div>
            <p class="help"><?= h(__('media_roots_as_disposable_help')) ?></p>
        </div>
        <div class="form-group">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="remove_empty_folders_on_apply" name="remove_empty_folders_on_apply" value="1" <?= !empty($current['remove_empty_folders_on_apply']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="remove_empty_folders_on_apply"><?= h(__('remove_empty_folders_on_apply')) ?></label>
            </div>
            <p class="help"><?= h(__('remove_empty_folders_on_apply_help')) ?></p>
        </div>

        <h3><?= h(__('url_mapping')) ?></h3>
        <div class="form-group">
            <label><?= h(__('absolute_url_origins')) ?></label>
            <div id="origins">
                <?php
                $origins = $current['origins'] ?? [];
                if (empty($origins)) {
                    $origins = [['origin' => '', 'local_root' => '']];
                }
                foreach ($origins as $i => $origin): ?>
                    <div class="form-row mb-2">
                        <input type="text" class="form-control" name="origins[origin][]" value="<?= h($origin['origin'] ?? '') ?>" placeholder="<?= h(__('origin_placeholder')) ?>">
                        <input type="text" class="form-control" name="origins[local_root][]" value="<?= h($origin['local_root'] ?? '') ?>" placeholder="<?= h(__('local_root_placeholder')) ?>">
                        <button type="button" class="btn btn-danger" style="flex:0 0 auto;" onclick="this.parentElement.remove()"><?= h(__('remove')) ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-secondary" onclick="addOrigin()"><?= h(__('add_origin')) ?></button>
        </div>

        <div class="form-group">
            <label><?= h(__('root_relative_prefix_maps')) ?></label>
            <div id="prefix_maps">
                <?php
                $prefixes = $current['prefix_maps'] ?? [];
                if (empty($prefixes)) {
                    $prefixes = [['prefix' => '', 'local_root' => '']];
                }
                foreach ($prefixes as $i => $pm): ?>
                    <div class="form-row mb-2">
                        <input type="text" class="form-control" name="prefix_maps[prefix][]" value="<?= h($pm['prefix'] ?? '') ?>" placeholder="<?= h(__('prefix_placeholder')) ?>">
                        <input type="text" class="form-control" name="prefix_maps[local_root][]" value="<?= h($pm['local_root'] ?? '') ?>" placeholder="<?= h(__('prefix_local_root_placeholder')) ?>">
                        <button type="button" class="btn btn-danger" style="flex:0 0 auto;" onclick="this.parentElement.remove()"><?= h(__('remove')) ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-secondary" onclick="addPrefixMap()"><?= h(__('add_prefix_map')) ?></button>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="default_web_root"><?= h(__('default_web_root')) ?></label>
                    <input type="text" id="default_web_root" name="default_web_root" class="form-control" value="<?= h($current['default_web_root'] ?? '') ?>">
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label for="root_relative_base"><?= h(__('root_relative_base')) ?></label>
                    <input type="text" id="root_relative_base" name="root_relative_base" class="form-control" value="<?= h($current['root_relative_base'] ?? '') ?>">
                </div>
            </div>
        </div>

        <h3><?= h(__('filters')) ?></h3>
        <div class="form-group">
            <label><?= h(__('exclude_patterns')) ?></label>
            <?= renderListInputs('exclude_patterns', $current['exclude_patterns'] ?? []) ?>
        </div>
        <div class="form-group">
            <label><?= h(__('include_patterns')) ?></label>
            <?= renderListInputs('include_patterns', $current['include_patterns'] ?? []) ?>
        </div>
        <div class="form-group">
            <label><?= h(__('protected_patterns')) ?></label>
            <?= renderListInputs('protected_patterns', $current['protected_patterns'] ?? []) ?>
        </div>

        <h3><?= h(__('output_directories')) ?></h3>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="report_dir"><?= h(__('report_directory')) ?></label>
                    <input type="text" id="report_dir" name="report_dir" class="form-control" value="<?= h($current['report_dir'] ?? 'reports') ?>">
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label for="quarantine_dir"><?= h(__('quarantine_directory')) ?></label>
                    <input type="text" id="quarantine_dir" name="quarantine_dir" class="form-control" value="<?= h($current['quarantine_dir'] ?? 'quarantine') ?>">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><?= h(__('save_config')) ?></button>
    </form>
</div>

<script>
function addOrigin() {
    const div = document.createElement('div');
    div.className = 'form-row mb-2';
    div.innerHTML = '<input type="text" class="form-control" name="origins[origin][]" placeholder="<?= h(__('origin_placeholder')) ?>">' +
                    '<input type="text" class="form-control" name="origins[local_root][]" placeholder="<?= h(__('local_root_placeholder')) ?>">' +
                    '<button type="button" class="btn btn-danger" style="flex:0 0 auto;" onclick="this.parentElement.remove()"><?= h(__('remove')) ?></button>';
    document.getElementById('origins').appendChild(div);
}
function addPrefixMap() {
    const div = document.createElement('div');
    div.className = 'form-row mb-2';
    div.innerHTML = '<input type="text" class="form-control" name="prefix_maps[prefix][]" placeholder="<?= h(__('prefix_placeholder')) ?>">' +
                    '<input type="text" class="form-control" name="prefix_maps[local_root][]" placeholder="<?= h(__('prefix_local_root_placeholder')) ?>">' +
                    '<button type="button" class="btn btn-danger" style="flex:0 0 auto;" onclick="this.parentElement.remove()"><?= h(__('remove')) ?></button>';
    document.getElementById('prefix_maps').appendChild(div);
}
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/templates/layout.php';
