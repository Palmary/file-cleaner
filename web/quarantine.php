<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$title = __('quarantine_title');
$alert = null;

$quarantineDir = $config['quarantine_dir'];
$logs = listQuarantineLogs($quarantineDir);

ob_start();
?>
<div class="card">
    <h2><?= h(__('quarantine_title')) ?></h2>
    <?php if (empty($logs)): ?>
        <p class="help"><?= h(__('no_quarantine')) ?></p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th><?= h(__('session')) ?></th>
                    <th><?= h(__('created')) ?></th>
                    <th><?= h(__('files')) ?></th>
                    <th><?= h(__('log_size')) ?></th>
                    <th><?= h(__('actions')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
                $pagination = paginate($logs, $page, 15);
                foreach ($pagination['items'] as $log): ?>
                    <tr>
                        <td><?= h($log['session']) ?></td>
                        <td><?= h($log['created_at']) ?></td>
                        <td><?= h((string)$log['operations']) ?></td>
                        <td><?= h(number_format($log['size'])) ?> <?= h(__('bytes')) ?></td>
                        <td class="actions">
                            <form method="post" action="run.php" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="log_path" value="<?= h($log['filename']) ?>">
                                <button type="submit" class="btn btn-secondary" onclick="return confirm('<?= h(__('restore_confirm', ['count' => (string)$log['operations']])) ?>')"><?= h(__('restore')) ?></button>
                            </form>
                            <form method="post" action="run.php" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="purge">
                                <input type="hidden" name="log_path" value="<?= h($log['filename']) ?>">
                                <button type="submit" class="btn btn-danger" onclick="return confirm('<?= h(__('purge_confirm')) ?>')"><?= h(__('purge')) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?= renderPagination($pagination, 'quarantine.php') ?>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/templates/layout.php';
