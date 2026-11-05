"""
FinTrust browser audit (optional): accessibility + responsive layout + keyboard focus.

For every page (public, customer, admin) in light AND dark theme it:
  1. runs axe-core (WCAG 2.x rules) and lists violations,
  2. checks 320 / 375 / 768 / 1024 / 1366 px widths for horizontal page scrolling,
  3. presses Tab 25 times and reports any focused element without a visible focus style,
  4. reports JavaScript errors in the console.

Needs Python 3 + Playwright and axe-core (not needed to run FinTrust itself):
    pip install playwright && python -m playwright install chromium
    npm install axe-core@4.10.2          (in any folder; pass its path below)

Run (Apache + MySQL running, seed data loaded):
    python scripts/browser-audit.py --axe path/to/node_modules/axe-core/axe.min.js
    python scripts/browser-audit.py --axe ... --base http://localhost/fintrust/ transactions   (only matching pages)
"""
import argparse, sys
from playwright.sync_api import sync_playwright

ap = argparse.ArgumentParser()
ap.add_argument('--axe', required=True, help='path to axe.min.js')
ap.add_argument('--base', default='http://localhost/fintrust/')
ap.add_argument('only', nargs='*', help='only audit paths containing these words')
args = ap.parse_args()
BASE = args.base.rstrip('/') + '/'
AXE = open(args.axe, encoding='utf-8').read()
only = args.only
PUBLIC = ['', 'login', 'register', 'forgot-password', 'reset-password?token=abc', 'verify-email?token=abc',
          'privacy', 'terms', 'nope-404', 'setup-check']
CUSTOMER = ['dashboard', 'wallet', 'transfer', 'transactions', 'settings', 'notifications']
ADMIN = ['admin/', 'admin/users', 'admin/transactions', 'admin/fraud-alerts', 'admin/security', 'admin/audit-logs']
WIDTHS = [320, 375, 768, 1024, 1366]

def login(ctx, email, pw):
    p = ctx.new_page()
    p.goto(BASE + 'login')
    p.fill('#f-email', email); p.fill('#f-password', pw)
    p.click('button[type=submit]')
    p.wait_for_url(lambda u: 'login' not in u, timeout=15000)
    p.close()

results = {}
with sync_playwright() as pw:
    b = pw.chromium.launch()
    for group, pages, creds in [('public', PUBLIC, None), ('customer', CUSTOMER, ('ada@fintrust.test', 'Demo@Pass2026')),
                                ('admin', ADMIN, ('admin@fintrust.test', 'Admin@FinTrust2026'))]:
        for theme in ['light', 'dark']:
            ctx = b.new_context(bypass_csp=True, viewport={'width': 1366, 'height': 900},
                                color_scheme=theme, reduced_motion='reduce')
            ctx.add_init_script(f"try{{localStorage.setItem('ft-theme','{theme}')}}catch(e){{}}")
            if creds: login(ctx, *creds)
            page = ctx.new_page()
            errors = []
            # Ignored: offline web-font requests (net::ERR_*) and the deliberate 404 page.
            page.on('console', lambda m: errors.append(m.text) if m.type == 'error' and 'net::ERR_' not in m.text and '404 (Not Found)' not in m.text else None)
            page.on('pageerror', lambda e: errors.append(str(e)))
            for path in pages:
                if only and not any(o in path for o in only): continue
                errors.clear()
                page.set_viewport_size({'width': 1366, 'height': 900})
                page.goto(BASE + path, wait_until='networkidle')
                page.wait_for_timeout(300)
                page.evaluate(AXE)
                r = page.evaluate("""() => axe.run(document, {resultTypes:['violations']}).then(r => r.violations.map(v => ({
                    id: v.id, impact: v.impact, help: v.help,
                    nodes: v.nodes.slice(0,4).map(n => n.target.join(' ') + ' :: ' + (n.failureSummary||'').split('\\n').slice(1,2).join(''))})))""")
                overflow = {}
                for w in WIDTHS:
                    page.set_viewport_size({'width': w, 'height': 800})
                    page.wait_for_timeout(120)
                    o = page.evaluate("""() => { const d = document.documentElement; const over = d.scrollWidth - d.clientWidth;
                        if (over <= 0) return null;
                        const bad = [...document.querySelectorAll('body *')].filter(e => { const r = e.getBoundingClientRect(); return r.right > d.clientWidth + 1 && r.width > 0; })
                          .slice(0,3).map(e => e.tagName.toLowerCase() + '.' + [...e.classList].join('.'));
                        return {over, bad}; }""")
                    if o: overflow[w] = o
                # Keyboard: first 25 Tab stops must show a visible focus indicator.
                page.set_viewport_size({'width': 1366, 'height': 900})
                page.goto(BASE + path, wait_until='networkidle')
                nofocus = []
                for i in range(25):
                    page.keyboard.press('Tab')
                    page.wait_for_timeout(220)
                    info = page.evaluate("""() => { const e = document.activeElement; if (!e || e === document.body) return null;
                        const s = getComputedStyle(e);
                        const hit = e.matches('.chart__bar') && e.previousElementSibling && e.previousElementSibling.classList.contains('is-active');
                        const visible = hit || (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0) || (s.boxShadow && s.boxShadow !== 'none' && !s.boxShadow.startsWith('rgba(0, 0, 0, 0)'));
                        return {el: e.tagName.toLowerCase() + (e.type === 'date' ? '[type=date]' : '') + (e.id ? '#' + e.id : '') + '.' + [...e.classList].slice(0,2).join('.') + ' "' + (e.textContent||e.getAttribute('aria-label')||'').trim().slice(0,25) + '"', visible}; }""")
                    # Chromium date inputs move focus between their day/month/year parts on Tab,
                    # which reads as "no style" mid-move; they use the same .input:focus ring.
                    if info and not info['visible'] and 'type=date' not in info['el']: nofocus.append(info['el'])
                key = f'{group}/{theme}/{path or "home"}'
                results[key] = {'axe': r, 'overflow': overflow, 'nofocus': sorted(set(nofocus)), 'js_errors': list(errors)}
            ctx.close()
    b.close()

bad = 0
for k, v in results.items():
    if v['axe'] or v['overflow'] or v['nofocus'] or v['js_errors']:
        bad += 1
        print('==', k)
        for a in v['axe']:
            print(f"   axe {a['impact']}: {a['id']} – {a['help']}")
            for n in a['nodes']: print('       ', n[:220])
        if v['overflow']: print('   overflow:', v['overflow'])
        if v['nofocus']: print('   no visible focus:', v['nofocus'])
        if v['js_errors']: print('   js errors:', v['js_errors'][:3])
print(f'\n{len(results)} page/theme combinations audited, {bad} with findings')
sys.exit(1 if bad else 0)
