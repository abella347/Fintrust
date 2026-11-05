<footer class="site-footer">
    <div class="container site-footer__grid">
        <div class="site-footer__brand">
            <?= App\Core\View::partial('partials/logo', ['light' => true]) ?>
            <p><?= e(config('app.tagline')) ?></p>
            <p class="site-footer__note">
                FinTrust is a portfolio and demonstration project. All balances and
                transactions are simulated; it is not a licensed financial institution
                and does not hold or move real money.
            </p>
        </div>

        <nav aria-label="Quick links">
            <h2 class="site-footer__heading">Quick links</h2>
            <ul>
                <li><a href="<?= e(url('#features')) ?>">Features</a></li>
                <li><a href="<?= e(url('#security')) ?>">Security</a></li>
                <li><a href="<?= e(url('#how-it-works')) ?>">How it works</a></li>
                <li><a href="<?= e(url('register')) ?>">Create account</a></li>
            </ul>
        </nav>

        <nav aria-label="Legal">
            <h2 class="site-footer__heading">Legal</h2>
            <ul>
                <li><a href="<?= e(url('privacy')) ?>">Privacy policy</a></li>
                <li><a href="<?= e(url('terms')) ?>">Terms of service</a></li>
            </ul>
        </nav>

        <div>
            <h2 class="site-footer__heading">Contact</h2>
            <ul class="site-footer__contact">
                <li><?= icon('mail', '', 16) ?> <a href="mailto:support@fintrust.test">support@fintrust.test</a></li>
                <li><?= icon('phone', '', 16) ?> <span>+234 800 000 0000</span></li>
                <li><?= icon('map-pin', '', 16) ?> <span>Lagos, Nigeria (demo address)</span></li>
            </ul>
        </div>
    </div>
    <div class="container site-footer__bottom">
        <p>&copy; <?= e(date('Y')) ?> FinTrust. All rights reserved.</p>
    </div>
</footer>
