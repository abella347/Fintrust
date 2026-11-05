<?php
/**
 * One-time messages set with Session::flash('success', '...').
 * @var array<string,string> $flashes
 */
$map = [
    'success' => ['alert--success', 'check-circle'],
    'error'   => ['alert--error', 'x-circle'],
    'warning' => ['alert--warning', 'alert-triangle'],
    'info'    => ['alert--info', 'info'],
];
foreach ($flashes ?? [] as $type => $message):
    [$class, $iconName] = $map[$type] ?? $map['info'];
?>
    <div class="alert <?= e($class) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
        <?= icon($iconName) ?>
        <p><?= e($message) ?></p>
        <button type="button" class="alert__close" data-dismiss="alert" aria-label="Dismiss message"><?= icon('x', '', 16) ?></button>
    </div>
<?php endforeach; ?>
