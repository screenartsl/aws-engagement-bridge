// Native DOM/JS check with real PHP/PG responses over private pipes. No listener or external network.
import { createRequire } from 'node:module';
import { readFile, writeFile } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import path from 'node:path';
const root = process.env.BRIDGE_PANEL_ROOT;
const tools = process.argv[2];
if (!root?.startsWith('/tmp/muserelay-bridge-panel-') || !tools?.startsWith('/tmp/muserelay-bridge-browser-tools-')) throw new Error('Private disposable paths required');
const { chromium } = createRequire(import.meta.url)(tools + '/node_modules/playwright');
const host = 'aws-bridge-staging.muserelay.com';
const secret = JSON.parse(await readFile(root + '/evaluation-local.json', 'utf8'));
let cookies = {};
let requestCount = 0;
const failures = [];
const responses = [];
let page;
async function php(request) {
  return await new Promise((resolve, reject) => {
    const p = spawn('php', [path.dirname(new URL(import.meta.url).pathname) + '/request-probe.php'], { env: process.env });
    let output = ''; let error = '';
    p.stdout.on('data', data => { output += data; }); p.stderr.on('data', data => { error += data; });
    p.on('error', reject); p.on('close', code => {
      if (code) return reject(new Error('Private PHP probe failed'));
      try { const result = JSON.parse(output); cookies = { ...cookies, ...result.cookies }; resolve(result); } catch { reject(new Error('Invalid private probe result')); }
    });
    p.stdin.end(JSON.stringify({ ...request, cookies }));
  });
}
const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
try {
  const context = await browser.newContext({ viewport: { width: 1600, height: 1000 }, serviceWorkers: 'block' });
  page = await context.newPage();
  page.on('pageerror', error => { failures.push(error.name + ': ' + error.message.slice(0, 180)); });
  await page.route('**/*', async route => {
    const req = route.request(); const url = new URL(req.url());
    if (url.hostname !== host) return route.abort();
    if (++requestCount > 180) throw new Error('Bounded native browser request budget exceeded');
    if (url.pathname.startsWith('/assets/')) {
      const file = path.resolve(root + '/core/public', '.' + decodeURIComponent(url.pathname));
      const ext = path.extname(file);
      if (!file.startsWith(root + '/core/public/assets/') || !['.js','.css','.svg','.woff','.woff2','.ttf','.eot','.png'].includes(ext)) return route.fulfill({ status: 404 });
      try { return await route.fulfill({ status: 200, contentType: { '.js':'application/javascript','.css':'text/css','.svg':'image/svg+xml','.woff':'font/woff','.woff2':'font/woff2','.ttf':'font/ttf','.eot':'application/vnd.ms-fontobject','.png':'image/png' }[ext], body: await readFile(file) }); }
      catch { return route.fulfill({ status: 404 }); }
    }
    if (!/^\/(login|logout|session\/status|conversations(?:\/.*)?|messages\/api\/.*)$/.test(url.pathname)) return route.fulfill({ status: 404 });
    const raw = req.postData();
    const data = raw ? (req.headers()['content-type']?.includes('application/json') ? JSON.parse(raw) : Object.fromEntries(new URLSearchParams(raw))) : {};
    const result = await php({ method: req.method(), path: url.pathname + url.search, data, headers: { 'x-csrf-token': req.headers()['x-csrf-token'], 'x-requested-with': req.headers()['x-requested-with'] }, json: (req.headers().accept ?? '').includes('application/json') });
    responses.push({path:url.pathname,status:result.status});
    const headers = {};
    for (const [name, value] of Object.entries(result.headers)) {
      if (!['set-cookie','content-length','transfer-encoding'].includes(name)) headers[name] = Array.isArray(value) ? value.join(', ') : String(value);
    }
    return route.fulfill({ status: result.status, headers, body: result.body });
  });
  // HTTP-kernel suite separately verifies login/CSRF/cookies. Here authenticate over the same private probe,
  // then exercise the untouched inbox JavaScript/DOM without requiring an actual TLS listener.
  const login = await php({ method: 'GET', path: '/login', data: {}, json: false });
  const token = /name="_token"[^>]*value="([^"]+)"/.exec(login.body)?.[1];
  if (!token) throw new Error('Missing private login token');
  const authenticated = await php({ method: 'POST', path: '/login', data: { _token: token, password: secret.password }, json: false });
  if (authenticated.status !== 302) throw new Error('Private evaluation authentication failed');
  await page.goto('https://' + host + '/conversations/channel/20');
  await page.locator('[data-conversation-id]').first().waitFor({ timeout: 20000 });
  await page.locator('[data-conversation-id]').first().click();
  await page.locator('#messages-container').waitFor({ state:'visible',timeout:20000 });
  await page.waitForFunction(() => document.querySelector('#messages-container')?.innerText.includes('Synthetic receipt visible in native MuseRelay inbox'), null, {timeout:20000});
  await page.screenshot({ path: root + '/native-inbox-synthetic.png', fullPage: true });
  const report = { result: 'PASS', kind: 'SYNTHETIC_NATIVE_BROWSER', browserVersion: browser.version(), playwright: '1.64.1',
    transport: 'real native PHP HTTP-kernel responses over private process pipes; browser network intercepted',
    realAws: false, tlsTested: false, productionModified: false, requestCount, pageErrors: failures, screenshot: 'native-inbox-synthetic.png' };
  if (failures.length) throw new Error('Native browser JavaScript errors: ' + JSON.stringify(failures));
  await writeFile(root + '/browser-result.json', JSON.stringify(report, null, 2) + '\n');
  console.log(JSON.stringify(report));
} catch(error) {
  if(page) { await page.screenshot({path:root+'/native-browser-failed.png',fullPage:true});await writeFile(root+'/native-browser-text.private.txt',await page.locator('body').innerText()); }
  console.error(JSON.stringify({pageErrors:failures,responses:responses.slice(-25)}));throw error;
} finally { await browser.close(); }
