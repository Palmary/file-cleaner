<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$title = 'Dashboard';
$alert = null;

$scanConfigPath = realpath($config['default_config_path'] ?? '') ?: ($config['default_config_path'] ?? '');
$scanConfig = [];
if ($scanConfigPath !== '' && is_file($scanConfigPath)) {
    $scanConfigContent = file_get_contents($scanConfigPath);
    if ($scanConfigContent !== false) {
        $scanConfig = json_decode($scanConfigContent, true) ?: [];
    }
}
$scanConfigPath = $scanConfig['scan_config_path'] ?? $scanConfigPath;
$defaultFormat = $scanConfig['scan_report_format'] ?? $config['default_report_format'] ?? 'html';
$defaultDryRun = $scanConfig['scan_dry_run'] ?? $config['default_dry_run'] ?? true;

$reports = listReports($config['report_dir']);
$latestReport = null;
$latestJsonFilename = null;
if (!empty($reports)) {
    foreach ($reports as $report) {
        if ($report['type'] === 'json') {
            $latestJsonFilename = $report['filename'];
            try {
                $latestReport = loadReportJson($config['report_dir'], $report['filename']);
            } catch (Throwable $e) {
                $latestReport = null;
            }
            break;
        }
    }
}

ob_start();
?>
<div class="card">
    <h2><?= h(__('run_scan')) ?></h2>
    <form method="post" action="run.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="scan">
        <div class="row g-3 align-items-end">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="config_path"><?= h(__('config_file')) ?></label>
                    <input type="text" id="config_path" name="config_path" class="form-control" value="<?= h($scanConfigPath) ?>" readonly>
                    <p class="help"><?= h(__('edit_in_configurator')) ?></p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label for="format"><?= h(__('output_format')) ?></label>
                    <select id="format" name="format" class="form-select" disabled>
                        <option value="html" <?= $defaultFormat === 'html' ? 'selected' : '' ?>>HTML</option>
                        <option value="json" <?= $defaultFormat === 'json' ? 'selected' : '' ?>>JSON</option>
                        <option value="csv" <?= $defaultFormat === 'csv' ? 'selected' : '' ?>>CSV</option>
                    </select>
                    <input type="hidden" name="format" value="<?= h($defaultFormat) ?>">
                    <p class="help"><?= h(__('change_default_format')) ?></p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label for="dry_run"><?= h(__('dry_run')) ?></label>
                    <input type="hidden" name="dry_run" value="<?= $defaultDryRun ? '1' : '0' ?>">
                    <select id="dry_run" class="form-select" disabled>
                        <option value="1" <?= $defaultDryRun ? 'selected' : '' ?>><?= h(__('yes')) ?></option>
                        <option value="0" <?= !$defaultDryRun ? 'selected' : '' ?>><?= h(__('no')) ?></option>
                    </select>
                    <p class="help"><?= h(__('change_default_dry_run')) ?></p>
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><?= h(__('start_scan')) ?></button>
    </form>
</div>

<?php if ($latestReport): ?>
<div class="card">
    <h2><?= h(__('latest_report_summary')) ?></h2>
    <div class="grid">
        <div class="stat">
            <div class="value"><?= h((string)($latestReport['summary']['source_files_scanned'] ?? 0)) ?></div>
            <div class="label"><?= h(__('source_files')) ?></div>
        </div>
        <div class="stat">
            <div class="value"><?= h((string)($latestReport['summary']['media_files_scanned'] ?? 0)) ?></div>
            <div class="label"><?= h(__('media_files')) ?></div>
        </div>
        <div class="stat">
            <div class="value"><?= h((string)($latestReport['summary']['referenced_paths_found'] ?? 0)) ?></div>
            <div class="label"><?= h(__('referenced_paths')) ?></div>
        </div>
        <div class="stat">
            <div class="value"><?= h((string)($latestReport['summary']['unused_files_count'] ?? 0)) ?></div>
            <div class="label"><?= h(__('unused_files')) ?></div>
        </div>
        <div class="stat">
            <div class="value"><?= h((string)($latestReport['summary']['broken_references_count'] ?? 0)) ?></div>
            <div class="label"><?= h(__('broken_references')) ?></div>
        </div>
    </div>
    <p class="help" style="margin-top: 1rem;">
        <?= h(__('generated')) ?>: <?= h(formatReportDate($latestReport['meta']['generated_at'] ?? null)) ?>
        &middot; <?= h(__('dry_run')) ?>: <?= h(($latestReport['meta']['dry_run'] ?? false) ? __('yes') : __('no')) ?>
        &middot; <?= h(__('approved')) ?>: <?= h(($latestReport['meta']['approved'] ?? false) ? __('yes') : __('no')) ?>
    </p>
    <div class="actions" style="margin-top: 1rem;">
        <?php if ($latestJsonFilename !== null): ?>
            <a class="btn btn-secondary" href="reports.php?file=<?= urlencode($latestJsonFilename) ?>"><?= h(__('view_report')) ?></a>
            <form method="post" action="run.php" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="approve_apply">
                <input type="hidden" name="report_path" value="<?= h($latestJsonFilename) ?>">
                <button type="submit" class="btn btn-primary" onclick="return confirm('<?= h(__('approve_apply_confirm')) ?>')"><?= h(__('approve_apply')) ?></button>
            </form>
        <?php endif; ?>
    </div>
    <?php if (!empty($latestReport['unused_files'])): ?>
    <h3><?= h(__('unused_files_sample')) ?></h3>
    <ul>
        <?php foreach (array_slice($latestReport['unused_files'], 0, 10) as $entry): ?>
            <li><?= h($entry['path'] ?? '') ?></li>
        <?php endforeach; ?>
        <?php if (count($latestReport['unused_files']) > 10): ?>
            <li class="help">... <?= h(__('and_more', ['count' => (string)(count($latestReport['unused_files']) - 10)])) ?></li>
        <?php endif; ?>
    </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2><?= h(__('recent_reports')) ?></h2>
    <?php if (empty($reports)): ?>
        <p class="help"><?= h(__('no_reports')) ?></p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th><?= h(__('filename')) ?></th>
                    <th><?= h(__('type')) ?></th>
                    <th><?= h(__('size')) ?></th>
                    <th><?= h(__('modified')) ?></th>
                    <th><?= h(__('actions')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $recentPage = filter_input(INPUT_GET, 'recent_page', FILTER_VALIDATE_INT) ?: 1;
                $recentPagination = paginate($reports, $recentPage, 10);
                foreach ($recentPagination['items'] as $report):
                    $recentJsonFilename = $report['files']['json']['filename'] ?? $report['filename'];
                ?>
                    <tr>
                        <td><?= h($report['prefix']) ?></td>
                        <td>
                            <?php foreach ($report['types'] as $t): ?>
                                <span class="tag tag-<?= h($t) ?>"><?= h(strtoupper($t)) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td><?= h(number_format($report['size'])) ?> <?= h(__('bytes')) ?></td>
                        <td><?= h($report['modified']) ?></td>
                        <td class="actions">
                            <a class="btn btn-secondary" href="reports.php?file=<?= urlencode($recentJsonFilename) ?>" target="_blank" rel="noopener noreferrer"><?= h(__('view')) ?></a>
                            <a class="btn btn-secondary" href="reports.php?file=<?= urlencode($recentJsonFilename) ?>&download=1"><?= h(__('download_json')) ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
            <?= renderPagination($recentPagination, 'index.php', 'recent_page') ?>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/templates/layout.php';
