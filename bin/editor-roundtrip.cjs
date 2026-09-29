/**
 * Editor round-trip harness.
 *
 * Opens a converted page in the real WordPress block editor, lets Gutenberg
 * parse and re-serialise post_content, and reports every block the editor
 * cannot regenerate. Block validation runs client-side against each block's
 * save() output, so this is the only place a mismatch shows up before a client
 * opens the page and sees "This block contains unexpected or invalid content".
 *
 * Usage:
 *   node bin/editor-roundtrip.cjs --post 5935 \
 *        --cookie-name <name> --cookie-value <value> \
 *        --admin-url http://site.local/wp-admin/post.php
 *
 * A template part is not editable through post.php — post.php loads the
 * classic screen for it and the block store never fills — so pass its
 * theme//slug id instead and the harness opens the site editor:
 *
 *   node bin/editor-roundtrip.cjs \
 *        --template-part 'twentytwentyfive//dxai-header-my-page' \
 *        --cookie-name <name> --cookie-value <value> \
 *        --admin-url http://site.local/wp-admin/post.php
 *
 * Exits 1 when any block is invalid, so CI can gate on it.
 */

const os = require('os');
const path = require('path');

const CHROME = process.env.DXAI_CHROME
  || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PUPPETEER = path.join(__dirname, '..', 'node_modules', 'puppeteer-core');

function arg(name, fallback) {
  const i = process.argv.indexOf('--' + name);
  return i > -1 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

(async () => {
  const postId = arg('post');
  const templatePart = arg('template-part');
  const cookieName = arg('cookie-name');
  const cookieValue = arg('cookie-value');
  const adminUrl = arg('admin-url');
  const timeout = Number(arg('timeout', '90000'));

  if ((!postId && !templatePart) || !cookieName || !cookieValue || !adminUrl) {
    console.error('missing required argument (--post or --template-part, --cookie-name --cookie-value --admin-url)');
    process.exit(2);
  }

  const label = templatePart ? `template part ${templatePart}` : `post ${postId}`;
  const editUrl = templatePart
    ? `${adminUrl.replace(/post\.php$/, 'site-editor.php')}`
      + `?postType=wp_template_part&postId=${encodeURIComponent(templatePart)}&canvas=edit`
    : `${adminUrl}?post=${postId}&action=edit`;

  const puppeteer = require(PUPPETEER);
  /*
   * A profile directory of this run's own.
   *
   * Puppeteer's default profile name is random but the temp directory is
   * shared, and eighteen launches in a row — right after the geometry stage has
   * been driving its own Chrome hard — collided on the profile lockfile:
   *
   *   path: '…\puppeteer_dev_chrome_profile-l52DlP\lockfile'
   *   at ChromeLauncher.launch (…/BrowserLauncher.js:166:23)
   *
   * Three of eighteen targets died that way in one run and two more timed out,
   * and the suite reported "blocks 13/18 clean" — which reads as five invalid
   * blocks and was nothing of the kind. Naming the directory after the process
   * keeps two launches from ever choosing the same one.
   */
  const profile = path.join(
    os.tmpdir(),
    'dxai-editor-roundtrip-' + process.pid + '-' + Math.abs(Number(process.hrtime.bigint() % 100000n))
  );

  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'shell',
    // Booting the block editor over a 100 KB+ post takes longer than
    // puppeteer's 180s default CDP timeout, which surfaces as a bare
    // "Page.navigate timed out" with nothing measured.
    protocolTimeout: timeout + 60000,
    userDataDir: profile,
    args: ['--no-sandbox'],
  });

  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1400, height: 1000 });

    const origin = new URL(adminUrl).origin;
    const cookies = [{ name: cookieName, value: cookieValue, url: origin, path: '/', httpOnly: true }];
    // wp-admin checks the auth cookie; the logged_in one alone bounces to
    // wp-login.php with reauth=1.
    const authName = arg('auth-cookie-name');
    const authValue = arg('auth-cookie-value');
    if (authName && authValue) {
      cookies.push({ name: authName, value: authValue, url: origin, path: '/wp-admin', httpOnly: true });
      cookies.push({ name: authName, value: authValue, url: origin, path: '/wp-includes', httpOnly: true });
    }
    await page.setCookie(...cookies);

    const pageErrors = [];
    page.on('pageerror', e => pageErrors.push(String(e).slice(0, 200)));

    // Not networkidle: wp-admin keeps the heartbeat and autosave requests
    // going, so the editor never reports an idle network and the navigation
    // times out with nothing measured. The block store below is the real
    // readiness signal.
    await page.goto(editUrl, {
      waitUntil: 'domcontentloaded',
      timeout,
    });

    /*
     * Two things have to be true, and only the first one used to be checked.
     *
     * The store fills from post_content as soon as the editor parses it, which
     * happens BEFORE our blocks-editor.js has registered anything — and a
     * block whose type is not registered yet reads as `core/missing`. One run
     * caught a page in exactly that window and reported `unregistered=18`,
     * which was its 8 dxai-ui/text + 8 dxai-ui/html + 2 dxai-ui/link: every
     * block we define, "missing" because the definitions had not arrived. The
     * same page re-measured clean, so the harness was accusing the conversion
     * of a fault that was its own timing.
     *
     * `dxai-ui/form` is the LAST type blocks-editor.js registers, so its
     * presence means that file ran to the end. An `unregistered` after this
     * gate is a real one.
     */
    await page.waitForFunction(
      () => {
        const sel = window.wp && window.wp.data && window.wp.data.select('core/block-editor');
        if (!sel || !sel.getBlocks || !sel.getBlocks().length) { return false; }
        const blocks = window.wp && window.wp.blocks;
        return !!(blocks && blocks.getBlockType && blocks.getBlockType('dxai-ui/form'));
      },
      { timeout, polling: 500 }
    );

    const report = await page.evaluate(() => {
      const sel = wp.data.select('core/block-editor');
      const out = { total: 0, invalid: [], unregistered: [], byName: {} };

      const walk = (blocks, trail) => {
        blocks.forEach((b, i) => {
          const at = trail.concat(`${b.name || 'unknown'}[${i}]`);
          out.total++;
          out.byName[b.name] = (out.byName[b.name] || 0) + 1;

          if (b.name === 'core/missing') {
            out.unregistered.push({
              path: at.join(' > '),
              originalName: (b.attributes && b.attributes.originalName) || '?',
            });
          } else if (b.isValid === false) {
            // validationIssues carry the whole block type as an %o argument;
            // keep only the short string args that name the actual mismatch.
            const issues = (b.validationIssues || []).map(v => {
              const args = (v.args || [])
                .filter(a => typeof a === 'string' && a.length < 160 && !a.includes('\n'))
                .map(a => a.trim());
              return args.join(' | ') || 'validation failed';
            }).filter(Boolean);
            out.invalid.push({
              path: at.join(' > '),
              name: b.name,
              className: (b.attributes && b.attributes.className) || '',
              issues: issues.slice(0, 3),
              savedStart: String(b.originalContent || '').slice(0, 180),
            });
          }
          if (b.innerBlocks && b.innerBlocks.length) walk(b.innerBlocks, at);
        });
      };

      walk(sel.getBlocks(), []);
      return out;
    });

    report.pageErrors = pageErrors.slice(0, 5);

    const names = Object.entries(report.byName).sort((a, b) => b[1] - a[1]);
    console.log(`${label}: ${report.total} blocks`);
    console.log('  ' + names.map(([n, c]) => `${n}=${c}`).join('  '));
    console.log(`invalid=${report.invalid.length}  unregistered=${report.unregistered.length}`);

    for (const u of report.unregistered) {
      console.log(`\n  UNREGISTERED ${u.originalName}\n    at ${u.path}`);
    }
    for (const b of report.invalid) {
      console.log(`\n  INVALID ${b.name}${b.className ? ' .' + b.className.split(' ')[0] : ''}`);
      console.log(`    at ${b.path}`);
      b.issues.forEach(i => console.log(`    ${i}`));
      console.log(`    saved: ${b.savedStart.replace(/\s+/g, ' ')}`);
    }
    if (report.pageErrors.length) {
      console.log('\n  page errors:');
      report.pageErrors.forEach(e => console.log('    ' + e));
    }

    await browser.close();
    process.exit(report.invalid.length || report.unregistered.length ? 1 : 0);
  } catch (err) {
    await browser.close();
    console.error('harness error: ' + err.message);
    process.exit(2);
  }
})();
