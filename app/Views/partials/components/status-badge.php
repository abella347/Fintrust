<?php
/**
 * @var string $status
 */
?>
<span class="badge <?= e(App\Helpers\Format::badgeClass($status)) ?>"><?= e(ucfirst($status)) ?></span>
