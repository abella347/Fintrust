<section class="section">
    <div class="container container--narrow text-center">
        <?= App\Core\View::partial('partials/components/empty-state', [
            'icon'        => 'search',
            'title'       => 'Page not found',
            'message'     => "The page you're looking for doesn't exist or has moved.",
            'actionLabel' => 'Back to home',
            'actionHref'  => url(''),
            'level'       => 1,
        ]) ?>
    </div>
</section>
