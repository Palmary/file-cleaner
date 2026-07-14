<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$title = __('reports_title');
$alert = null;

$reportDir = $config['report_dir'];
$filename = $_GET['file'] ?? '';
$download = !empty($_GET['download']);

if ($filename !== '') {
    try {
        $filename = basename((string)$filename);
        $path = $reportDir . '/' . $filename;
        $real = realpath($path);
        if ($real === false || !str_starts_with($real, realpath($reportDir))) {
            throw new RuntimeException('Invalid report path');
        }
        if (!is_file($real)) {
            throw new RuntimeException('Report not found');
        }

        $mimeTypes = [
            'json' => 'application/json',
            'html' => 'text/html',
            'csv' => 'text/csv',
        ];
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';

        if ($download) {
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($real));
            readfile($real);
            exit;
        }

        if ($ext === 'json') {
            $data = loadReportJson($reportDir, $filename);
            $isApproved = !empty($data['meta']['approved']);
            $base = substr($filename, 0, -5);
            $htmlFilename = $base . '.html';
            $htmlPath = $reportDir . '/' . $htmlFilename;
            $htmlExists = is_file($htmlPath);
            ob_start();
            ?>
            <div class="card">
                <h2><?= h($base) ?></h2>
                <div class="actions" style="margin-bottom:1rem;">
                    <a class="btn btn-secondary" href="reports.php?file=<?= urlencode($filename) ?>&download=1"><?= h(__('download_json')) ?></a>
                    <?php if ($htmlExists): ?>
                        <a class="btn btn-secondary" href="reports.php?file=<?= urlencode($htmlFilename) ?>&download=1"><?= h(__('download_html')) ?></a>
                    <?php endif; ?>
                    <a class="btn btn-secondary" href="reports.php"><?= h(__('back_to_list')) ?></a>
                <form method="post" action="run.php" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="approve_apply">
                    <input type="hidden" name="report_path" value="<?= h($filename) ?>">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('<?= h(__('approve_apply_confirm')) ?>')"><?= h(__('approve_apply')) ?></button>
                </form>
                </div>
                <?php if ($htmlExists): ?>
                    <iframe src="reports.php?file=<?= urlencode($htmlFilename) ?>" class="report-iframe"></iframe>
                <?php else: ?>
                    <pre><?= h(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
            </div>
            <?php
            $content = ob_get_clean();
        } elseif ($ext === 'html') {
            header('Content-Type: text/html');
            readfile($real);
            exit;
        } else {
            header('Content-Type: ' . $mime);
            readfile($real);
            exit;
        }
    } catch (Throwable $e) {
        $alert = ['type' => 'danger', 'message' => $e->getMessage()];
    }
} else {
    $reports = listReports($reportDir);
    ob_start();
    ?>
    <div class="card">
        <h2><?= h(__('reports_title')) ?></h2>
        <?php if (empty($reports)): ?>
            <p class="help"><?= h(__('no_reports')) ?></p>
        <?php else: ?>
            <div class="actions" style="margin-bottom:1rem;">
                <button type="button" class="btn btn-danger" id="batch-delete-btn"><?= h(__('delete_selected')) ?></button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th style="width:2rem"><input type="checkbox" id="select-all" title="<?= h(__('select_all')) ?>"></th>
                        <th><?= h(__('filename')) ?></th>
                        <th><?= h(__('type')) ?></th>
                        <th><?= h(__('size')) ?></th>
                        <th><?= h(__('modified')) ?></th>
                        <th><?= h(__('actions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
                    $pagination = paginate($reports, $page, 15);
                    foreach ($pagination['items'] as $report): ?>
                        <tr>
                            <?php
                            $jsonFilename = $report['files']['json']['filename'] ?? $report['filename'];
                            $htmlFilename = $report['files']['html']['filename'] ?? null;
                            ?>
                            <td><input type="checkbox" name="report_files[]" value="<?= h($jsonFilename) ?>" form="batch-delete-form"></td>
                            <td><?= h($report['prefix']) ?></td>
                            <td>
                                <?php foreach ($report['types'] as $t): ?>
                                    <span class="tag tag-<?= h($t) ?>"><?= h(strtoupper($t)) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td><?= h(number_format($report['size'])) ?> <?= h(__('bytes')) ?></td>
                            <td><?= h($report['modified']) ?></td>
                            <td class="actions">
                                <a class="btn btn-secondary" href="reports.php?file=<?= urlencode($jsonFilename) ?>"><?= h(__('view')) ?></a>
                                <a class="btn btn-secondary" href="reports.php?file=<?= urlencode($jsonFilename) ?>&download=1"><?= h(__('download_json')) ?></a>
                                <?php if ($htmlFilename !== null): ?>
                                    <a class="btn btn-secondary" href="reports.php?file=<?= urlencode($htmlFilename) ?>&download=1"><?= h(__('download_html')) ?></a>
                                <?php endif; ?>
                                <?php if (in_array('json', $report['types'], true)): ?>
                                    <form method="post" action="run.php" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="approve_apply">
                                        <input type="hidden" name="report_path" value="<?= h($jsonFilename) ?>">
                                        <button type="submit" class="btn btn-primary" onclick="return confirm('<?= h(__('approve_apply_confirm')) ?>')"><?= h(__('approve_apply')) ?></button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="run.php" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_reports">
                                    <input type="hidden" name="return_page" value="<?= h((string)$page) ?>">
                                    <input type="hidden" name="report_files[]" value="<?= h($jsonFilename) ?>">
                                    <button type="submit" class="btn btn-danger" onclick="return confirm('<?= h(__('delete_confirm', ['name' => $report['prefix']])) ?>')"><?= h(__('delete')) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?= renderPagination($pagination, 'reports.php') ?>
            <form id="batch-delete-form" method="post" action="run.php" style="display:none">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="delete_reports">
                <input type="hidden" name="return_page" value="<?= h((string)$page) ?>">
            </form>
        <?php endif; ?>
    </div>
    <script>
    (function () {
        const selectAll = document.getElementById('select-all');
        const batchBtn = document.getElementById('batch-delete-btn');
        const batchForm = document.getElementById('batch-delete-form');
        if (!selectAll || !batchBtn || !batchForm) return;

        selectAll.addEventListener('change', function () {
            const checked = this.checked;
            document.querySelectorAll('input[name="report_files[]"]').forEach(function (box) {
                box.checked = checked;
            });
        });

        batchBtn.addEventListener('click', function () {
            const checked = document.querySelectorAll('input[name="report_files[]"]:checked');
            if (checked.length === 0) {
                alert('<?= h(__('delete_selected_none')) ?>');
                return;
            }
            if (!confirm('<?= h(__('delete_selected_confirm')) ?>'.replace('{count}', checked.length))) {
                return;
            }
            batchForm.submit();
        });
    })();
    </script>
    <?php
    $content = ob_get_clean();
}

require __DIR__ . '/templates/layout.php';
