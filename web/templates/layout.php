<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title ?? $config['app_name'] ?? 'File Cleaner Web') ?></title>
    <style>
<?php
$assetDir = realpath(__DIR__ . '/../../assets/vendor');
$assets = [
    'bootstrap.min.css',
];
foreach ($assets as $asset) {
    $path = $assetDir . '/' . $asset;
    if ($assetDir !== false && is_file($path)) {
        echo file_get_contents($path);
    }
}
?>
        body { padding-top: 4.5rem; background-color: #f8f9fa; }
        .navbar-brand { font-weight: 700; }
        main { padding: 1.5rem 0.75rem 2rem; }
        .card { margin-bottom: 1.5rem; padding: 1.25rem; border: 1px solid #dee2e6; border-radius: 0.5rem; background-color: #fff; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.05); }
        .card h2 { margin-top: 0; margin-bottom: 1.25rem; font-size: 1.35rem; }
        .table-actions { white-space: nowrap; }
        table { margin-bottom: 0; }
        h1, h2, h3 { margin-bottom: 1rem; }
        p { margin-bottom: 1rem; }
        .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem; align-items: center; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { font-weight: 500; margin-bottom: 0.35rem; display: block; }
        .form-row { display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap; }
        .form-row > * { flex: 1; min-width: 180px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; }
        .stat { text-align: center; padding: 1rem; background: #f8f9fa; border-radius: 0.5rem; border: 1px solid #e9ecef; }
        .stat .value { font-size: 2rem; font-weight: 700; color: #0d6efd; }
        .stat .label { color: #6c757d; font-size: 0.875rem; }
        .help { color: #6c757d; font-size: 0.875rem; margin-top: 0.25rem; }
        .tag {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-right: 0.25rem;
        }
        .tag-json { background: #dbeafe; color: #1e40af; }
        .tag-html { background: #fce7f3; color: #9d174d; }
        .tag-csv { background: #d1fae5; color: #065f46; }
        pre {
            background: #1f2937;
            color: #f3f4f6;
            padding: 1rem;
            border-radius: 0.375rem;
            overflow-x: auto;
            font-size: 0.875rem;
        }
        .report-iframe { width: 100%; height: 70vh; border: 1px solid #dee2e6; border-radius: 0.375rem; }
        .app-icon { height: 2rem; width: 2rem; margin-right: 0.5rem; }
        .app-icon path { fill: #fff; }
        .footer { padding: 1.5rem 0.75rem; text-align: center; color: #6c757d; font-size: 0.875rem; border-top: 1px solid #dee2e6; margin-top: auto; }
        html, body { height: 100%; }
        body { display: flex; flex-direction: column; }
        main { flex: 1 0 auto; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <svg class="app-icon" viewBox="0 0 32 32" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                    <path d="M6 3a3 3 0 0 0-3 3v18a4 4 0 0 0 4 4h16a3 3 0 0 0 3-3V9.5a1 1 0 0 0-.293-.707l-6.5-6.5A1 1 0 0 0 18.5 2H9a3 3 0 0 0-3 1Zm3 0h9.5l6.5 6.5V23a2 2 0 0 1-2 2H9a3 3 0 0 1-3-3V6a2 2 0 0 1 2-2Z" opacity=".5"/>
                    <path d="M11 12h10v2H11zm0 4h8v2h-8zm0 4h10v2H11z"/>
                    <circle cx="23.5" cy="20.5" r="5.5" fill="#fff"/>
                    <path d="m21.5 20.5 1.5-1.5 1.5 1.5-1.5 1.5-1.5-1.5z" fill="#0d6efd"/>
                </svg>
                <?= h($config['app_name'] ?? 'File Cleaner Web') ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <ul class="navbar-nav align-items-center">
                    <li class="nav-item"><a class="nav-link" href="index.php"><?= h(__('nav_dashboard')) ?></a></li>
                    <li class="nav-item"><a class="nav-link" href="reports.php"><?= h(__('nav_reports')) ?></a></li>
                    <li class="nav-item"><a class="nav-link" href="quarantine.php"><?= h(__('nav_quarantine')) ?></a></li>
                    <li class="nav-item"><a class="nav-link" href="configurator.php"><?= h(__('nav_configurator')) ?></a></li>
                    <li class="nav-item"><a class="nav-link" href="about.php"><?= h(__('nav_about')) ?></a></li>
                    <?php
                    $currentLang = $_SESSION['language'] ?? $config['language'] ?? 'en';
                    $langLabels = ['en' => 'EN', 'zh' => '繁', 'zhs' => '简'];
                    ?>
                    <li class="nav-item dropdown ms-lg-2">
                        <a class="nav-link dropdown-toggle" href="#" id="langDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false"><?= h($langLabels[$currentLang] ?? strtoupper($currentLang)) ?></a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="langDropdown">
                            <li><a class="dropdown-item <?= $currentLang === 'en' ? 'active' : '' ?>" href="run.php?action=set_language&amp;lang=en">English</a></li>
                            <li><a class="dropdown-item <?= $currentLang === 'zh' ? 'active' : '' ?>" href="run.php?action=set_language&amp;lang=zh">繁體中文</a></li>
                            <li><a class="dropdown-item <?= $currentLang === 'zhs' ? 'active' : '' ?>" href="run.php?action=set_language&amp;lang=zhs">简体中文</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <main class="container">
        <?php if (!empty($_SESSION['alert'])): ?>
            <div class="alert alert-<?= h($_SESSION['alert']['type']) ?> alert-dismissible fade show" role="alert">
                <?= nl2br(h($_SESSION['alert']['message']), false) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['alert']); ?>
        <?php endif; ?>
        <?php if (!empty($alert)): ?>
            <div class="alert alert-<?= h($alert['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
                <?= nl2br(h($alert['message'] ?? ''), false) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($alert); ?>
        <?php endif; ?>
        <?= $content ?? '' ?>
    </main>
    <footer class="footer">
        <div class="container">
            <div><?= __('copyright', ['year' => h((string)date('Y'))]) ?> <?= h(__('version', ['version' => h($config['app_version'] ?? '1.0')])) ?></div>
        </div>
    </footer>
    <script>
<?php
$scripts = [
    'bootstrap.bundle.min.js',
];
foreach ($scripts as $asset) {
    $path = $assetDir . '/' . $asset;
    if ($assetDir !== false && is_file($path)) {
        echo file_get_contents($path);
    }
}
?>
    </script>
</body>
</html>
