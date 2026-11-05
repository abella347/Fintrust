<?php
/** Public landing page. */
$features = [
    ['send',         'Secure Money Transfers',        'Send money to any FinTrust wallet. Every transfer is PIN-authorised, checked against limits and recorded atomically — it fully happens or not at all.'],
    ['wallet',       'Digital Wallet Management',     'Your own 10-digit wallet number, a live balance and simulated top-ups, with amounts stored in exact kobo so nothing gets lost to rounding.'],
    ['activity',     'Real-Time Transaction Tracking','See every credit, debit and failed attempt the moment it happens. Search by reference, filter by date or status, and download receipts.'],
    ['lock',         'Advanced Account Protection',   'Hashed passwords and PINs, secure sessions that expire when idle, login rate limiting and automatic lockouts after repeated failures.'],
    ['chart',        'Financial Insights',            'Track money in versus money out month by month, with clear totals for what you have received and sent.'],
    ['shield-alert', 'Fraud Monitoring',              'Rule-based checks flag unusual patterns — large or rapid transfers, repeated PIN failures — for review by an administrator.'],
];

$steps = [
    ['Create your FinTrust account', 'Sign up with your name, email, phone number and a strong password. A wallet is created for you automatically.'],
    ['Verify your account',          'Confirm your email address and set a 4-digit transaction PIN that authorises every transfer.'],
    ['Access your digital wallet',   'Get your unique wallet number and fund it with simulated money to start exploring.'],
    ['Start transacting securely',   'Send and receive simulated transfers, track your history and download receipts.'],
];

$security = [
    ['lock',         'Encrypted communication',             'Designed to run over HTTPS in production, with secure, HTTP-only session cookies and strict browser security headers.'],
    ['key',          'Secure authentication',               'Passwords are hashed with PHP’s password_hash(), sessions are regenerated at login, and repeated failed logins trigger a temporary lockout.'],
    ['shield-check', 'Protected transaction authorization', 'Each transfer needs your separate transaction PIN, and a one-time request key stops the same transfer being processed twice.'],
    ['activity',     'Suspicious activity monitoring',      'Rule-based monitoring flags unusual transfers and repeated failures so they can be investigated.'],
    ['users',        'Account access controls',             'Role-based access keeps administrator tools separate, and accounts or wallets can be frozen during an investigation.'],
    ['file-text',    'Transaction audit trails',            'A double-entry ledger records every naira moved, and audit logs record who did what. Neither can be edited or deleted.'],
];

$faqs = [
    ['Is this real money?', 'No. FinTrust is a portfolio and demonstration project. Every balance, deposit and transfer is simulated, and FinTrust is not a licensed financial institution.'],
    ['How is a transfer protected?', 'Your PIN is checked, your balance and limits are verified while both wallets are locked, and the debit, credit and ledger records are saved in a single database transaction. If any step fails, nothing is saved.'],
    ['What happens if I enter the wrong PIN?', 'You can try a few times. After repeated incorrect PINs, transfers are locked for a while and the activity is flagged for review.'],
    ['Can a transaction be edited or deleted?', 'No. Completed records are permanent. A mistake is corrected by recording a new reversal transaction, so the full history is always preserved.'],
];
?>

<section class="hero hero--image">
    <div class="hero__bg" aria-hidden="true" data-hero-bg>
        <div class="hero__bg-img"></div>
    </div>
    <div class="hero__beam" aria-hidden="true"></div>
    <div class="container hero__grid">
        <div class="hero__copy">
            <span class="eyebrow"><?= icon('shield-check', '', 14) ?> Security-first digital wallet · simulated funds</span>
            <h1>Your Money. Your Security. <span class="hero__accent">Our Priority.</span></h1>
            <p class="hero__lead">
                Experience smarter, safer, and more convenient financial transactions with FinTrust.
                We combine modern financial technology with security-focused design to help you
                manage your money confidently.
            </p>
            <div class="cluster hero__actions">
                <a class="btn btn-accent btn-lg" href="<?= e(url('register')) ?>">Get Started <?= icon('arrow-right', '', 18) ?></a>
                <a class="btn btn-on-dark btn-lg" href="#features">Explore Features</a>
            </div>
            <ul class="hero__trust">
                <li><?= icon('check-circle', '', 16) ?> PIN-authorised transfers</li>
                <li><?= icon('check-circle', '', 16) ?> Double-entry ledger</li>
                <li><?= icon('check-circle', '', 16) ?> Real-time tracking</li>
            </ul>
        </div>

        <div class="hero__visual" role="img" aria-label="Preview of the FinTrust dashboard showing a wallet balance, a monthly chart and recent transfers">
            <div class="mock" aria-hidden="true">
                <div class="mock__bar"><span></span><span></span><span></span></div>
                <div class="mock__body">
                    <div class="mock__balance">
                        <small>Available balance</small>
                        <strong>₦234,999.50</strong>
                        <span>Wallet · 302 145 6789</span>
                    </div>
                    <div class="mock__chart">
                        <?php foreach ([[55, 30], [70, 42], [40, 58], [85, 36], [62, 48], [90, 40]] as [$in, $out]): ?>
                            <div class="mock__col"><i class="mock__in mock-h-<?= $in ?>"></i><i class="mock__out mock-h-<?= $out ?>"></i></div>
                        <?php endforeach; ?>
                    </div>
                    <ul class="mock__list">
                        <li><span class="mock__dot mock__dot--in"><?= icon('arrow-down-left', '', 14) ?></span><b>Ifeoma Obi</b><em class="is-in">+₦75,000.00</em></li>
                        <li><span class="mock__dot"><?= icon('arrow-up-right', '', 14) ?></span><b>Ben Adeyemi</b><em>−₦15,000.50</em></li>
                        <li><span class="mock__dot"><?= icon('arrow-up-right', '', 14) ?></span><b>Ngozi Okeke</b><em>−₦41,000.75</em></li>
                    </ul>
                </div>
            </div>
            <div class="float-chip float-chip--top" aria-hidden="true"><?= icon('check-circle', '', 18) ?> <span>Transfer successful<br><small>₦15,000.50 · FT2026…C6D7</small></span></div>
            <div class="float-chip float-chip--bottom" aria-hidden="true"><?= icon('shield-check', '', 18) ?> <span>PIN verified<br><small>Ledger balanced</small></span></div>
        </div>
    </div>
</section>

<section class="strip" aria-label="How FinTrust protects every transaction">
    <div class="container strip__grid">
        <div><strong>ACID</strong><span>Atomic database transactions</span></div>
        <div><strong>2×</strong><span>Every transfer: one debit, one credit</span></div>
        <div><strong>0</strong><span>Floating-point rounding — exact kobo</span></div>
        <div><strong>4</strong><span>Digit PIN authorises every transfer</span></div>
    </div>
</section>

<section class="section" id="about" aria-labelledby="about-title">
    <div class="container about">
        <div>
            <span class="section-label">About us</span>
            <h2 id="about-title">Built around trust, from the database up</h2>
            <p class="lead">
                FinTrust’s mission is to ensure secure, reliable, transparent, and seamless financial
                transactions by providing a digital financial platform built around security, trust,
                and convenience.
            </p>
            <p class="text-muted">
                Behind a simple interface sits careful engineering: every naira is tracked in a
                double-entry ledger, every transfer runs inside a locked database transaction, and every
                sensitive action is recorded. FinTrust is a portfolio project, so all money is simulated —
                but the protections are real code you can read.
            </p>
        </div>
        <ul class="values">
            <li><span class="values__icon"><?= icon('shield-check') ?></span><div><h3>Security</h3><p>Defence in depth: validation, hashing, locking, limits and monitoring at every layer.</p></div></li>
            <li><span class="values__icon"><?= icon('zap') ?></span><div><h3>Reliability</h3><p>All-or-nothing transfers mean money is never debited without the matching credit.</p></div></li>
            <li><span class="values__icon"><?= icon('scale') ?></span><div><h3>Transparency</h3><p>Clear references, receipts and a permanent history of every transaction.</p></div></li>
        </ul>
    </div>
</section>

<section class="section section--tint" id="features" aria-labelledby="features-title">
    <div class="container">
        <div class="section-head">
            <span class="section-label">Features</span>
            <h2 id="features-title">Everything you need to move money with confidence</h2>
            <p class="text-muted">A complete wallet experience, designed security-first.</p>
        </div>
        <div class="grid-3">
            <?php foreach ($features as [$iconName, $title, $text]): ?>
                <article class="card card--hover feature">
                    <span class="feature__icon"><?= icon($iconName, '', 24) ?></span>
                    <h3><?= e($title) ?></h3>
                    <p class="text-muted mb-0"><?= e($text) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section" id="how-it-works" aria-labelledby="how-title">
    <div class="container">
        <div class="section-head">
            <span class="section-label">How it works</span>
            <h2 id="how-title">Up and running in four steps</h2>
        </div>
        <ol class="steps">
            <?php foreach ($steps as $i => [$title, $text]): ?>
                <li class="steps__item">
                    <span class="steps__num" aria-hidden="true"><?= $i + 1 ?></span>
                    <h3><?= e($title) ?></h3>
                    <p class="text-muted mb-0"><?= e($text) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
        <div class="text-center mt-6">
            <a class="btn btn-primary btn-lg" href="<?= e(url('register')) ?>">Create your account</a>
        </div>
    </div>
</section>

<section class="section security" id="security" aria-labelledby="security-title">
    <div class="container">
        <div class="section-head section-head--light">
            <span class="section-label section-label--light">Security</span>
            <h2 id="security-title">A security-first approach at every layer</h2>
            <p>Protection isn’t a single feature — it’s a set of controls that back each other up.</p>
        </div>
        <div class="grid-3">
            <?php foreach ($security as [$iconName, $title, $text]): ?>
                <article class="security__item">
                    <span class="security__icon"><?= icon($iconName, '', 22) ?></span>
                    <h3><?= e($title) ?></h3>
                    <p class="mb-0"><?= e($text) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="security__note"><?= icon('info', '', 16) ?>
            These controls demonstrate good security practice in a portfolio application. They are not a
            certification, regulatory approval or guarantee, and no system is completely risk-free.</p>
    </div>
</section>

<section class="section" aria-labelledby="faq-title">
    <div class="container container--narrow">
        <div class="section-head">
            <span class="section-label">FAQ</span>
            <h2 id="faq-title">Common questions</h2>
        </div>
        <div class="faq">
            <?php foreach ($faqs as [$q, $a]): ?>
                <details>
                    <summary><?= e($q) ?><?= icon('chevron-right', 'faq__chev', 18) ?></summary>
                    <p><?= e($a) ?></p>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section" id="contact" aria-labelledby="contact-title">
    <div class="container">
        <div class="cta">
            <div>
                <h2 id="contact-title">Ready to experience secure finance?</h2>
                <p>Create a free account in under a minute and explore every feature with simulated funds.</p>
            </div>
            <div class="cluster">
                <a class="btn btn-accent btn-lg" href="<?= e(url('register')) ?>">Get Started</a>
                <a class="btn btn-lg btn-on-dark" href="<?= e(url('login')) ?>">Log in</a>
            </div>
        </div>
        <div class="grid-3 mt-5 contact">
            <a class="card card--hover contact__card" href="mailto:support@fintrust.test">
                <span class="feature__icon"><?= icon('mail') ?></span>
                <div><h3>Email support</h3><p class="mb-0 text-muted">support@fintrust.test</p></div>
            </a>
            <a class="card card--hover contact__card" href="tel:+2348000000000">
                <span class="feature__icon"><?= icon('phone') ?></span>
                <div><h3>Call us</h3><p class="mb-0 text-muted">+234 800 000 0000 · Mon–Fri, 8am–6pm WAT</p></div>
            </a>
            <div class="card contact__card">
                <span class="feature__icon"><?= icon('shield-alert') ?></span>
                <div><h3>Report suspicious activity</h3><p class="mb-0 text-muted">Freeze-first: email security@fintrust.test and we’ll lock the wallet while we investigate.</p></div>
            </div>
        </div>
    </div>
</section>
