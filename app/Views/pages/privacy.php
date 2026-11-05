<section class="section">
    <div class="container prose">
        <span class="section-label">Legal</span>
        <h1>Privacy policy</h1>
        <p class="text-muted">Last updated 14 October 2026 · Applies to the FinTrust demonstration application.</p>

        <div class="alert alert--info" role="note"><?= icon('info') ?>
            <p>FinTrust is a portfolio project. Please don’t enter real financial details. Use demo data where you can.</p></div>

        <h2>What we collect</h2>
        <ul>
            <li><strong>Account details:</strong> first and last name, email address and phone number.</li>
            <li><strong>Credentials:</strong> your password and transaction PIN, stored only as one-way hashes. We cannot see or recover them.</li>
            <li><strong>Activity:</strong> simulated transactions, notifications and login attempts (time, IP address and browser type).</li>
        </ul>

        <h2>Why we collect it</h2>
        <ul>
            <li>To run your account and wallet and show your transaction history.</li>
            <li>To protect accounts: rate-limiting logins, detecting suspicious activity and keeping an audit trail.</li>
            <li>To send account emails, such as verification and password resets (simulated in development).</li>
        </ul>

        <h2>How we protect it</h2>
        <p>Data is accessed only through parameterised database queries by a least-privilege database account.
            Sessions use HTTP-only, same-site cookies, and sensitive changes require your current password.
            Financial records and audit logs are append-only.</p>

        <h2>Sharing</h2>
        <p>We do not sell or share your data. Other users see only the masked name attached to your wallet
            number when they send you money (for example, “Ada N.”).</p>

        <h2>Retention</h2>
        <p>Transaction and audit records are kept permanently so history can’t be rewritten. Password reset and
            verification links expire and can be used only once.</p>

        <h2>Contact</h2>
        <p>Questions about this policy: <a href="mailto:privacy@fintrust.test">privacy@fintrust.test</a>.</p>
    </div>
</section>
