<?php
/**
 * Layout for public marketing pages: header + content + footer.
 * @var string $content  HTML of the page template (already escaped inside it)
 * @var array  $flashes
 */
use App\Core\View;

echo View::partial('partials/head', get_defined_vars());
echo View::partial('partials/public-nav');
?>
<main id="main" tabindex="-1">
    <?php if (!empty($flashes)): ?>
        <div class="container flash-stack"><?= View::partial('partials/flash', ['flashes' => $flashes]) ?></div>
    <?php endif; ?>
    <?= $content /* already-rendered template; its dynamic values were escaped there */ ?>
</main>
<?= View::partial('partials/public-footer') ?>
<div class="toast-region" aria-live="polite" aria-atomic="false" data-toast-region></div>
</body>
</html>
