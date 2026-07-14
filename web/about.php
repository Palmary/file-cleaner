<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$title = __('about_title');
$alert = null;

ob_start();
?>
<div class="card">
    <h2><?= h(__('about_title')) ?></h2>
    <p>
        <?= h(__('about_description')) ?>
    </p>
    <p><?= h(__('version', ['version' => $config['app_version'] ?? '1.0'])) ?></p>
    <h3><?= h(__('api')) ?></h3>
    <p>
        <?= h(__('api_description', [
            'api' => 'api.php',
            'enable_api' => 'enable_api',
            'api_key' => 'api_key',
            'header' => 'X-API-Key',
            'param' => 'api_key',
        ])) ?>
    </p>
    <h4><?= h(__('endpoints')) ?></h4>
    <ul>
        <li><code>GET  /api.php?action=ping</code> — Health check</li>
        <li><code>GET  /api.php?action=reports</code> — List generated reports</li>
        <li><code>GET  /api.php?action=report&amp;file=report-YYYYMMDD-HHMMSS.json</code> — Get report JSON</li>
        <li><code>POST /api.php?action=scan</code> — Run scan (config_path, format, dry_run)</li>
        <li><code>POST /api.php?action=approve</code> — Approve a JSON report (report_path)</li>
        <li><code>POST /api.php?action=apply</code> — Apply approved report (report_path, yes)</li>
        <li><code>POST /api.php?action=restore</code> — Restore quarantined files (log_path, yes)</li>
    </ul>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/templates/layout.php';
