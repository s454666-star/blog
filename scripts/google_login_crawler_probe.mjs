import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import http from 'node:http';
import path from 'node:path';
import { spawn } from 'node:child_process';

const startedAt = new Date();
const options = parseArgs(process.argv.slice(2));
const targetUrl = requiredOption('url');
const timeoutSeconds = Math.max(5, Number(options.timeout || 300));
const timeoutAt = Date.now() + timeoutSeconds * 1000;
const email = String(options.email || '');
const profileDir = path.resolve(String(options.profile || path.join('storage', 'app', 'google-login-crawler', 'chrome-profile')));
const htmlOutput = path.resolve(String(options.output || path.join('storage', 'app', 'google-login-crawler', 'latest.html')));
const textOutput = path.resolve(String(options['text-output'] || path.join('storage', 'app', 'google-login-crawler', 'latest.txt')));
const metaOutput = path.resolve(String(options['meta-output'] || path.join('storage', 'app', 'google-login-crawler', 'latest-meta.json')));
const waitSelector = String(options['wait-selector'] || '').trim();
const apiOutput = String(options['api-output'] || '').trim() === ''
  ? ''
  : path.resolve(String(options['api-output']));
const cookieStatePath = String(options['cookie-state'] || '').trim() === ''
  ? ''
  : path.resolve(String(options['cookie-state']));
const activeClicks = Math.max(0, Number(options['active-clicks'] || 0));
const activeSummaryOutput = String(options['active-summary-output'] || '').trim() === ''
  ? ''
  : path.resolve(String(options['active-summary-output']));
const clickGoogle = Boolean(options['click-google']);
const headless = Boolean(options.headless);
const keepOpen = Boolean(options['keep-open']);
const probe85Sugarbaby = Boolean(options['probe-85sugarbaby']);

let chromeProcess = null;
let cdp = null;
let lastTarget = null;
let websocketCtor = globalThis.WebSocket;

if (typeof websocketCtor === 'undefined') {
  // Node 20- (including some AWS runtimes) does not expose global WebSocket.
  // Keep this lazy and explicit so the script still works on older Node versions.
  try {
    const wsModule = await import('ws');
    websocketCtor = wsModule.WebSocket;
  } catch {
    // Intentionally no-op; we'll throw a detailed error in CdpClient.connect().
  }
}

async function main() {
  ensureParentDirectory(profileDir);
  ensureParentDirectory(htmlOutput);
  ensureParentDirectory(textOutput);
  ensureParentDirectory(metaOutput);
  if (apiOutput !== '') {
    ensureParentDirectory(apiOutput);
  }
  if (cookieStatePath !== '') {
    ensureParentDirectory(cookieStatePath);
  }
  if (activeSummaryOutput !== '') {
    ensureParentDirectory(activeSummaryOutput);
  }

  const chromePath = resolveChromePath(String(options.chrome || '').trim());
  const { port, target: initialTarget } = await startChromeWithRetry(chromePath, profileDir, headless);

  log(`Chrome: ${chromePath}`);
  log(`Profile: ${profileDir}`);
  log(`Opening: ${targetUrl}`);
  log('If Google asks for password, passkey, or 2-step verification, complete it in the visible Chrome window.');

  const version = await waitForJson(`http://127.0.0.1:${port}/json/version`, 12000);
  const target = initialTarget || (await firstPageTarget(port));
  lastTarget = target;
  cdp = await CdpClient.connect(target.webSocketDebuggerUrl || version.webSocketDebuggerUrl);

  await cdp.send('Page.enable');
  await cdp.send('Runtime.enable');
  await cdp.send('Network.enable');
  const cookieStateLoad = await loadCookieState(cdp, cookieStatePath);
  await cdp.send('Page.navigate', { url: targetUrl });
  await waitForDocumentReady(cdp, timeoutAt);

  let status = 'captured';
  let reason = 'page-ready';
  let emailPrefillAttempted = false;
  let googleLoginClickAttempted = false;
  let lastProgressAt = 0;
  let snapshot = null;

  while (Date.now() < timeoutAt) {
    snapshot = await captureSnapshot(cdp, waitSelector);

    if (waitSelector !== '' && snapshot.waitSelectorMatched) {
      status = 'selector_matched';
      reason = `wait selector matched: ${waitSelector}`;
      break;
    }

    if (detectCloudflareVerification(snapshot)) {
      status = 'cloudflare_verification_needed';
      reason = 'waiting for 85sugarbaby Cloudflare security verification';
      if (Date.now() - lastProgressAt > 5000) {
        lastProgressAt = Date.now();
        log(`Waiting for Cloudflare verification... currentUrl=${snapshot.url} title=${JSON.stringify(snapshot.title)}`);
      }
      await sleep(1000);
      continue;
    }

    if (clickGoogle && !isGoogleLoginUrl(snapshot.url) && detect85SugarbabyLogin(snapshot) && !googleLoginClickAttempted) {
      googleLoginClickAttempted = true;
      const clicked = await clickGoogleLoginButton(cdp);
      if (clicked?.clicked) {
        log(`Clicked Google login button: ${clicked.text || '(matched element)'}`);
        await sleep(1500);
        continue;
      }

      log('No visible Google login button was found on the target page.');
    }

    if (isGoogleLoginUrl(snapshot.url) && email !== '' && !emailPrefillAttempted) {
      const filled = await prefillGoogleEmail(cdp, email);
      emailPrefillAttempted = true;
      if (filled?.filled) {
        log(`Prefilled Google email: ${email}`);
        await sleep(1500);
        continue;
      }
    }

    if (!isGoogleLoginUrl(snapshot.url) && snapshot.readyState === 'complete') {
      if (waitSelector === '' && snapshot.textLength > 0) {
        status = 'captured';
        reason = 'loaded non-Google page';
        break;
      }
    }

    if (Date.now() - lastProgressAt > 5000) {
      lastProgressAt = Date.now();
      log(`Waiting... currentUrl=${snapshot.url} title=${JSON.stringify(snapshot.title)}`);
    }

    await sleep(1000);
  }

  snapshot = await captureSnapshot(cdp, waitSelector);
  let apiProbe = null;
  if (probe85Sugarbaby && !isGoogleLoginUrl(snapshot.url)) {
    apiProbe = await run85SugarbabyApiProbe(cdp);
    if (apiOutput !== '') {
      fs.writeFileSync(apiOutput, JSON.stringify(apiProbe, null, 2), 'utf8');
      log(`Saved API probe: ${apiOutput}`);
    }
  }
  let activeClickSummary = null;
  if (activeClicks > 0 && !isGoogleLoginUrl(snapshot.url)) {
    activeClickSummary = await run85SugarbabyActiveClickSummary(cdp, activeClicks);
    if (activeSummaryOutput !== '') {
      fs.writeFileSync(activeSummaryOutput, JSON.stringify(activeClickSummary, null, 2), 'utf8');
      log(`Saved active-click summary: ${activeSummaryOutput}`);
    }
  }

  const cloudflareChallenge = detectCloudflareVerification(snapshot);
  const siteLoginRequired = detect85SugarbabyLogin(snapshot);

  if (cloudflareChallenge) {
    status = 'cloudflare_verification_needed';
    reason = '85sugarbaby returned a Cloudflare security verification page';
  } else if (Date.now() >= timeoutAt && status === 'captured' && isGoogleLoginUrl(snapshot.url)) {
    status = detectGoogleVerification(snapshot.text) ? 'google_verification_needed' : 'google_login_incomplete';
    reason = 'timed out while still on Google login';
  } else if (siteLoginRequired && !clickGoogle) {
    status = 'site_login_required';
    reason = '85sugarbaby redirected to login page';
  } else if (Date.now() >= timeoutAt && waitSelector !== '' && !snapshot.waitSelectorMatched) {
    status = 'wait_selector_timeout';
    reason = `timed out before selector appeared: ${waitSelector}`;
  }

  fs.writeFileSync(htmlOutput, snapshot.html, 'utf8');
  fs.writeFileSync(textOutput, snapshot.text, 'utf8');

  const meta = {
    started_at: startedAt.toISOString(),
    captured_at: new Date().toISOString(),
    requested_url: targetUrl,
    final_url: snapshot.url,
    title: snapshot.title,
    ready_state: snapshot.readyState,
    status,
    reason,
    wait_selector: waitSelector || null,
    wait_selector_matched: snapshot.waitSelectorMatched,
    profile_dir: profileDir,
    html_output: htmlOutput,
    text_output: textOutput,
    meta_output: metaOutput,
    api_output: apiOutput || null,
    api_probe_enabled: probe85Sugarbaby,
    api_probe_summary: summarizeApiProbe(apiProbe),
    cookie_state_path: cookieStatePath || null,
    cookie_state_loaded: cookieStateLoad,
    cookie_state_saved: null,
    active_clicks: activeClicks,
    active_summary_output: activeSummaryOutput || null,
    active_click_summary: summarizeActiveClickProbe(activeClickSummary),
    text_length: snapshot.textLength,
    html_length: snapshot.html.length,
    chrome_kept_open: keepOpen || (!headless && status.startsWith('google_')),
  };

  if (shouldPersistCookieState(apiProbe)) {
    meta.cookie_state_saved = await saveCookieState(cdp, cookieStatePath);
  }

  fs.writeFileSync(metaOutput, JSON.stringify(meta, null, 2), 'utf8');
  log(`Saved HTML: ${htmlOutput}`);
  log(`Saved text: ${textOutput}`);
  log(`Saved meta: ${metaOutput}`);
  log(`Status: ${status} (${reason})`);

  if (meta.chrome_kept_open) {
    log('Chrome is still open so you can finish Google verification, then rerun this command with the same profile.');
  }

  await cleanup(port, meta.chrome_kept_open);
  process.exitCode = status.startsWith('google_')
    || status === 'cloudflare_verification_needed'
    || status === 'site_login_required'
    || status.endsWith('_timeout')
    ? 2
    : 0;
}

function parseArgs(args) {
  const parsed = {};
  for (let i = 0; i < args.length; i += 1) {
    const raw = args[i];
    if (!raw.startsWith('--')) {
      continue;
    }

    const withoutPrefix = raw.slice(2);
    const equalsAt = withoutPrefix.indexOf('=');
    if (equalsAt !== -1) {
      parsed[withoutPrefix.slice(0, equalsAt)] = withoutPrefix.slice(equalsAt + 1);
      continue;
    }

    const next = args[i + 1];
    if (next && !next.startsWith('--')) {
      parsed[withoutPrefix] = next;
      i += 1;
    } else {
      parsed[withoutPrefix] = true;
    }
  }

  return parsed;
}

function requiredOption(name) {
  const value = String(options[name] || '').trim();
  if (value === '') {
    throw new Error(`Missing required option --${name}`);
  }

  return value;
}

function log(message) {
  console.log(`[crawler] ${message}`);
}

function ensureParentDirectory(fileOrDirectory) {
  const ext = path.extname(fileOrDirectory);
  const directory = ext === '' ? fileOrDirectory : path.dirname(fileOrDirectory);
  fs.mkdirSync(directory, { recursive: true });
}

function resolveChromePath(configuredPath) {
  const candidates = [
    configuredPath,
    process.env.CHROME_PATH,
    process.env.GOOGLE_CHROME_BIN,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    path.join(os.homedir(), 'AppData\\Local\\Google\\Chrome\\Application\\chrome.exe'),
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
    '/usr/bin/chromium-browser',
    '/usr/bin/chromium',
    '/usr/bin/microsoft-edge',
  ].filter(Boolean);

  for (const candidate of candidates) {
    if (fs.existsSync(candidate)) {
      return candidate;
    }
  }

  throw new Error('Chrome or Edge executable was not found. Pass --chrome=PATH.');
}

async function findOpenPort() {
  return await new Promise((resolve, reject) => {
    const server = net.createServer();
    server.on('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      const port = typeof address === 'object' && address ? address.port : null;
      server.close(() => {
        if (!port) {
          reject(new Error('Unable to allocate a local debugging port.'));
          return;
        }
        resolve(port);
      });
    });
  });
}

function spawnChrome(chromePath, port, userDataDir, shouldRunHeadless) {
  const args = [
    `--remote-debugging-port=${port}`,
    '--remote-debugging-address=127.0.0.1',
    `--user-data-dir=${userDataDir}`,
    '--no-sandbox',
    '--disable-dev-shm-usage',
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-popup-blocking',
    '--lang=zh-TW',
    'about:blank',
  ];

  if (shouldRunHeadless) {
    args.splice(args.length - 1, 0, '--headless=new', '--disable-gpu');
  }

  return spawn(chromePath, args, {
    stdio: 'ignore',
    windowsHide: false,
  });
}

async function waitForJson(url, timeoutMs) {
  const stopAt = Date.now() + timeoutMs;
  let lastError = null;

  while (Date.now() < stopAt) {
    try {
      const response = await requestJson(url);
      if (response) {
        return response;
      }
      lastError = new Error(`${url} returned ${response.status}`);
    } catch (error) {
      lastError = error;
    }
    await sleep(250);
  }

  throw lastError || new Error(`Timed out waiting for ${url}`);
}

function requestJson(url) {
  return new Promise((resolve, reject) => {
    const request = http.get(url, { timeout: 3000 }, (res) => {
      if (!res.statusCode || res.statusCode < 200 || res.statusCode >= 300) {
        res.resume();
        reject(new Error(`${url} returned ${res.statusCode}`));
        return;
      }

      let body = '';
      res.setEncoding('utf8');
      res.on('data', (chunk) => {
        body += chunk;
      });
      res.on('end', () => {
        try {
          resolve(JSON.parse(body || '{}'));
        } catch (error) {
          reject(error);
        }
      });
    });

    request.on('timeout', () => {
      request.destroy(new Error(`Timeout waiting for ${url}`));
    });

    request.on('error', reject);
  });
}

async function startChromeWithRetry(chromePath, userDataDir, shouldRunHeadless) {
  const maxAttempts = 4;
  let lastError = null;

  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    const port = await findOpenPort();
    log(`Launching Chrome attempt ${attempt}/${maxAttempts} on port ${port}`);
    try {
      chromeProcess = spawnChrome(chromePath, port, userDataDir, shouldRunHeadless);
      const target = await firstPageTarget(port);
      return { port, target };
    } catch (error) {
      lastError = error;
      const message = error?.message || String(error);
      log(`Chrome connect retry #${attempt} failed: ${message}`);

      await cleanup(port, true).catch(() => {});

      if (attempt >= maxAttempts) {
        throw error;
      }

      await sleep(700);
    }
  }

  throw lastError || new Error('Unable to launch Chrome with retries.');
}

async function firstPageTarget(port) {
  const targets = await waitForJson(`http://127.0.0.1:${port}/json/list`, 12000);
  const page = targets.find((target) => target.type === 'page' && target.webSocketDebuggerUrl);
  if (!page) {
    throw new Error('No debuggable Chrome page target was found.');
  }

  return page;
}

class CdpClient {
  constructor(ws) {
    this.ws = ws;
    this.nextId = 1;
    this.pending = new Map();
    this.events = new Map();

    ws.addEventListener('message', (event) => {
      const message = JSON.parse(event.data);
      if (message.id && this.pending.has(message.id)) {
        const { resolve, reject } = this.pending.get(message.id);
        this.pending.delete(message.id);
        if (message.error) {
          reject(new Error(message.error.message || JSON.stringify(message.error)));
        } else {
          resolve(message.result || {});
        }
        return;
      }

      if (message.method && this.events.has(message.method)) {
        for (const listener of this.events.get(message.method)) {
          listener(message.params || {});
        }
      }
    });
  }

  static async connect(wsUrl) {
    if (websocketCtor === undefined) {
      throw new Error('This Node.js runtime does not expose WebSocket. Use Node 22 or install a browser automation dependency.');
    }

    const ws = new websocketCtor(wsUrl);
    await new Promise((resolve, reject) => {
      ws.addEventListener('open', resolve, { once: true });
      ws.addEventListener('error', reject, { once: true });
    });

    return new CdpClient(ws);
  }

  send(method, params = {}) {
    const id = this.nextId;
    this.nextId += 1;

    const payload = JSON.stringify({ id, method, params });
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      this.ws.send(payload);
    });
  }

  close() {
    this.ws.close();
  }
}

async function waitForDocumentReady(client, stopAt) {
  while (Date.now() < stopAt) {
    const readyState = await evaluate(client, 'document.readyState', true).catch(() => null);
    if (readyState === 'interactive' || readyState === 'complete') {
      return;
    }
    await sleep(250);
  }

  throw new Error('Timed out waiting for the page document to become ready.');
}

async function evaluate(client, expression, returnByValue = true) {
  const result = await client.send('Runtime.evaluate', {
    expression,
    awaitPromise: true,
    returnByValue,
  });

  if (result.exceptionDetails) {
    throw new Error(result.exceptionDetails.text || 'Runtime.evaluate failed');
  }

  return result.result?.value;
}

async function clickGoogleLoginButton(client) {
  return await evaluate(client, `
(() => {
  const patterns = [
    /google/i,
    /使用\\s*google/i,
    /google\\s*登入/i,
    /continue\\s+with\\s+google/i,
    /sign\\s+in\\s+with\\s+google/i,
    /log\\s+in\\s+with\\s+google/i
  ];
  const candidates = Array.from(document.querySelectorAll('a, button, [role="button"], input[type="button"], input[type="submit"]'));
  for (const el of candidates) {
    const text = [el.innerText, el.textContent, el.value, el.getAttribute('aria-label'), el.title]
      .filter(Boolean)
      .join(' ')
      .replace(/\\s+/g, ' ')
      .trim();
    if (text && patterns.some((pattern) => pattern.test(text))) {
      el.scrollIntoView({ block: 'center', inline: 'center' });
      el.click();
      return { clicked: true, text: text.slice(0, 200) };
    }
  }
  return { clicked: false, text: '' };
})()
`, true);
}

async function prefillGoogleEmail(client, googleEmail) {
  return await evaluate(client, `
(() => {
  const email = ${JSON.stringify(googleEmail)};
  const input = document.querySelector('input[type="email"], input#identifierId');
  if (!input) {
    return { filled: false, reason: 'email input not found' };
  }
  input.focus();
  input.value = email;
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.dispatchEvent(new Event('change', { bubbles: true }));
  const next = document.querySelector('#identifierNext button, #identifierNext, button[jsname]');
  if (next) {
    next.click();
  }
  return { filled: true };
})()
`, true);
}

async function captureSnapshot(client, selector) {
  return await evaluate(client, `
(() => {
  const selector = ${JSON.stringify(selector)};
  const body = document.body;
  const text = body ? body.innerText : '';
  return {
    url: location.href,
    title: document.title || '',
    readyState: document.readyState,
    text,
    textLength: text.length,
    html: document.documentElement ? document.documentElement.outerHTML : '',
    waitSelectorMatched: selector ? document.querySelector(selector) !== null : false
  };
})()
`, true);
}

async function run85SugarbabyApiProbe(client) {
  return await evaluate(client, `
(async () => {
  const endpoints = [
    '/GetLoginListByLoginTime',
    '/GetAllMemberList',
    '/GetRecommnetList',
    '/GetCreateListByCreateTime',
    '/GetProfileVerify',
    '/GetCustomeMemberList'
  ];
  const payload = { timeout: 5000 };
  const results = {};
  for (const endpoint of endpoints) {
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json, text/plain, */*',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(payload)
      });
      const text = await response.text();
      let parsed = null;
      try {
        parsed = JSON.parse(text);
      } catch {}
      results[endpoint] = {
        ok: response.ok,
        status: response.status,
        contentType: response.headers.get('content-type'),
        length: text.length,
        rows: Array.isArray(parsed) ? parsed.length : null,
        data: parsed === null ? text : parsed
      };
    } catch (error) {
      results[endpoint] = {
        ok: false,
        error: error?.message || String(error)
      };
    }
  }

  return {
    capturedAt: new Date().toISOString(),
    url: location.href,
    title: document.title || '',
    isLoggedIn: document.querySelector('a[href="logout"], a[href="/logout"]') !== null,
    hasProfileLink: document.querySelector('a[href="view.html"], a[href="/view.html"]') !== null,
    endpoints: results
  };
})()
`, true);
}

function summarizeApiProbe(apiProbe) {
  if (!apiProbe || typeof apiProbe !== 'object') {
    return null;
  }

  const endpoints = {};
  for (const [endpoint, result] of Object.entries(apiProbe.endpoints || {})) {
    endpoints[endpoint] = {
      ok: Boolean(result?.ok),
      status: result?.status ?? null,
      rows: result?.rows ?? null,
      length: result?.length ?? null,
      error: result?.error ?? null,
    };
  }

  return {
    isLoggedIn: Boolean(apiProbe.isLoggedIn),
    endpointCount: Object.keys(endpoints).length,
    endpoints,
  };
}

async function run85SugarbabyActiveClickSummary(client, clicks) {
  return await evaluate(client, `
(async () => {
  const clicks = ${JSON.stringify(activeClicks)};
  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  const cities = {
    1: '台北',
    2: '新北',
    3: '基隆',
    4: '宜蘭',
    5: '花蓮',
    6: '桃園',
    7: '新竹',
    8: '苗栗',
    9: '台中',
    10: '彰化',
    11: '南投',
    12: '雲林',
    13: '嘉義',
    14: '台南',
    15: '高雄',
    16: '屏東',
    17: '台東',
    18: '金門',
    19: '連江',
    20: '澎湖',
    21: '香港',
    22: '澳門'
  };

  const normalizeArea = (value) => {
    if (typeof value === 'number') {
      return cities[value] || String(value);
    }
    return String(value || '').trim();
  };

  const isTarget = (item) => {
    const area = normalizeArea(item?.Area);
    const age = Number(item?.Age);
    return ['台北', '新北'].includes(area) && age >= 18 && age <= 22;
  };

  const clickActiveTab = () => {
    const candidates = Array.from(document.querySelectorAll('a, button, [role="button"], .nav-link, .dropdown-item'));
    const active = candidates.find((element) => (element.innerText || element.textContent || '').trim() === '活躍');
    if (active) {
      active.click();
      return true;
    }
    if (globalThis.vm && typeof globalThis.vm.toggleTab === 'function') {
      globalThis.vm.toggleTab(1);
      return true;
    }
    return false;
  };

  const waitForVue = async () => {
    for (let i = 0; i < 50; i += 1) {
      if (!globalThis.vm || globalThis.vm.loading === false) {
        return;
      }
      await sleep(100);
    }
  };

  const fetchActiveRows = async () => {
    const response = await fetch('/GetLoginListByLoginTime', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json, text/plain, */*',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ timeout: 5000 })
    });
    const text = await response.text();
    let parsed = null;
    try {
      parsed = JSON.parse(text);
    } catch {}
    return {
      ok: response.ok,
      status: response.status,
      rows: Array.isArray(parsed) ? parsed : [],
      rawLength: text.length
    };
  };

  const iterations = [];
  const fieldNames = new Set();
  let sawDetailKeys = false;
  let clickedCount = 0;
  let totalRows = 0;
  let totalMatches = 0;

  for (let i = 1; i <= clicks; i += 1) {
    const clicked = clickActiveTab();
    if (clicked) {
      clickedCount += 1;
    }
    await sleep(800);
    await waitForVue();

    let rows = Array.isArray(globalThis.vm?.logins) ? globalThis.vm.logins : [];
    let source = 'vue';
    let apiStatus = null;
    let rawLength = null;

    if (rows.length === 0) {
      const fetched = await fetchActiveRows();
      rows = fetched.rows;
      source = 'api';
      apiStatus = fetched.status;
      rawLength = fetched.rawLength;
    }

    rows.forEach((row) => {
      Object.keys(row || {}).forEach((key) => fieldNames.add(key));
      if (row && (Object.prototype.hasOwnProperty.call(row, 'UserId') || Object.prototype.hasOwnProperty.call(row, 'Id'))) {
        sawDetailKeys = true;
      }
    });

    const matchedCount = rows.filter(isTarget).length;
    totalRows += rows.length;
    totalMatches += matchedCount;
    iterations.push({
      iteration: i,
      clicked,
      source,
      apiStatus,
      rowCount: rows.length,
      matchedTaipeiNewTaipeiAge18To22Count: matchedCount,
      rawLength
    });
  }

  return {
    capturedAt: new Date().toISOString(),
    url: location.href,
    title: document.title || '',
    isLoggedIn: document.querySelector('a[href="logout"], a[href="/logout"]') !== null,
    requestedClicks: clicks,
    clickedCount,
    totalRowsSeenAcrossIterations: totalRows,
    totalMatchedRowsAcrossIterations: totalMatches,
    detailUrlTechnicallyDerivable: sawDetailKeys,
    emittedPersonalIdentifiers: false,
    filter: {
      areas: ['台北', '新北'],
      ageMin: 18,
      ageMax: 22
    },
    fieldNames: Array.from(fieldNames).sort(),
    iterations
  };
})()
`, true);
}

function summarizeActiveClickProbe(activeClickSummary) {
  if (!activeClickSummary || typeof activeClickSummary !== 'object') {
    return null;
  }

  return {
    isLoggedIn: Boolean(activeClickSummary.isLoggedIn),
    requestedClicks: activeClickSummary.requestedClicks ?? null,
    clickedCount: activeClickSummary.clickedCount ?? null,
    totalRowsSeenAcrossIterations: activeClickSummary.totalRowsSeenAcrossIterations ?? null,
    totalMatchedRowsAcrossIterations: activeClickSummary.totalMatchedRowsAcrossIterations ?? null,
    detailUrlTechnicallyDerivable: Boolean(activeClickSummary.detailUrlTechnicallyDerivable),
    emittedPersonalIdentifiers: Boolean(activeClickSummary.emittedPersonalIdentifiers),
  };
}

async function loadCookieState(client, statePath) {
  if (!statePath) {
    return { loaded: false, reason: 'not configured', cookieCount: 0 };
  }

  if (!fs.existsSync(statePath)) {
    return { loaded: false, reason: 'file missing', cookieCount: 0 };
  }

  try {
    const parsed = JSON.parse(fs.readFileSync(statePath, 'utf8'));
    const cookies = Array.isArray(parsed?.cookies) ? parsed.cookies : [];
    const normalized = cookies
      .filter(is85SugarbabyCookie)
      .map(normalizeCookieForSet)
      .filter(Boolean);

    if (normalized.length === 0) {
      return { loaded: false, reason: 'no 85sugarbaby cookies', cookieCount: 0 };
    }

    await client.send('Network.setCookies', { cookies: normalized });
    log(`Loaded 85sugarbaby cookie state: ${statePath} (${normalized.length} cookies)`);

    return { loaded: true, reason: 'loaded', cookieCount: normalized.length };
  } catch (error) {
    const message = error?.message || String(error);
    log(`Failed to load 85sugarbaby cookie state: ${message}`);

    return { loaded: false, reason: message, cookieCount: 0 };
  }
}

async function saveCookieState(client, statePath) {
  if (!statePath) {
    return { saved: false, reason: 'not configured', cookieCount: 0 };
  }

  try {
    const result = await client.send('Network.getAllCookies');
    const cookies = (Array.isArray(result?.cookies) ? result.cookies : [])
      .filter(is85SugarbabyCookie)
      .map(normalizeCookieForState)
      .filter(Boolean);

    fs.writeFileSync(statePath, JSON.stringify({
      saved_at: new Date().toISOString(),
      source: '85sugarbaby',
      cookies,
    }, null, 2), 'utf8');
    log(`Saved 85sugarbaby cookie state: ${statePath} (${cookies.length} cookies)`);

    return { saved: true, reason: 'saved', cookieCount: cookies.length };
  } catch (error) {
    const message = error?.message || String(error);
    log(`Failed to save 85sugarbaby cookie state: ${message}`);

    return { saved: false, reason: message, cookieCount: 0 };
  }
}

function shouldPersistCookieState(apiProbe) {
  if (!apiProbe || typeof apiProbe !== 'object') {
    return false;
  }

  if (apiProbe.isLoggedIn === true) {
    return true;
  }

  return Object.values(apiProbe.endpoints || {}).some((result) => {
    if (Array.isArray(result?.data) && result.data.length > 0) {
      return true;
    }

    return Number(result?.rows || 0) > 0;
  });
}

function is85SugarbabyCookie(cookie) {
  const domain = String(cookie?.domain || '').replace(/^\./, '').toLowerCase();

  return domain === '85sugarbaby.com.tw' || domain.endsWith('.85sugarbaby.com.tw');
}

function normalizeCookieForSet(cookie) {
  const name = String(cookie?.name || '');
  const value = String(cookie?.value || '');
  if (name === '') {
    return null;
  }

  const normalized = {
    name,
    value,
    domain: String(cookie.domain || '.85sugarbaby.com.tw'),
    path: String(cookie.path || '/'),
  };

  if (typeof cookie.secure === 'boolean') {
    normalized.secure = cookie.secure;
  }
  if (typeof cookie.httpOnly === 'boolean') {
    normalized.httpOnly = cookie.httpOnly;
  }
  if (typeof cookie.expires === 'number' && cookie.expires > 0) {
    normalized.expires = cookie.expires;
  }

  const sameSite = normalizeSameSite(cookie.sameSite);
  if (sameSite) {
    normalized.sameSite = sameSite;
  }

  return normalized;
}

function normalizeCookieForState(cookie) {
  const normalized = normalizeCookieForSet(cookie);
  if (!normalized) {
    return null;
  }

  return {
    ...normalized,
    session: Boolean(cookie.session),
  };
}

function normalizeSameSite(value) {
  const candidate = String(value || '').toLowerCase();
  if (candidate === 'strict') {
    return 'Strict';
  }
  if (candidate === 'lax') {
    return 'Lax';
  }
  if (candidate === 'none' || candidate === 'no_restriction') {
    return 'None';
  }

  return null;
}

function isGoogleLoginUrl(url) {
  try {
    const host = new URL(url).hostname.toLowerCase();
    return host === 'accounts.google.com' || host.endsWith('.accounts.google.com');
  } catch {
    return false;
  }
}

function detectGoogleVerification(text) {
  const normalized = String(text || '').toLowerCase();
  return [
    'verify',
    'verification',
    '2-step',
    'two-step',
    'passkey',
    'password',
    'your google account',
    '使用您的 google 帳戶',
    '驗證',
    '密碼',
    '安全性',
  ].some((needle) => normalized.includes(needle));
}

function detectCloudflareVerification(snapshot) {
  const title = String(snapshot?.title || '').toLowerCase();
  const text = String(snapshot?.text || '').toLowerCase();

  return title.includes('just a moment')
    || text.includes('performing security verification')
    || text.includes('cloudflare')
    || text.includes('ray id:');
}

function detect85SugarbabyLogin(snapshot) {
  const url = String(snapshot?.url || '').toLowerCase();
  const title = String(snapshot?.title || '');
  const text = String(snapshot?.text || '');

  return url.includes('/login')
    || title.includes('登入')
    || (text.includes('會員登入') && text.includes('GOOGLE'));
}

async function cleanup(port, shouldKeepOpen) {
  if (cdp) {
    try {
      cdp.close();
    } catch {
      // Ignore close errors.
    }
  }

  if (shouldKeepOpen) {
    return;
  }

  if (port && lastTarget?.id) {
    try {
      await fetch(`http://127.0.0.1:${port}/json/close/${encodeURIComponent(lastTarget.id)}`);
    } catch {
      // Ignore DevTools close errors.
    }
  }

  if (chromeProcess && !chromeProcess.killed) {
    try {
      chromeProcess.kill();
    } catch {
      // Ignore process kill errors.
    }
  }
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

try {
  await main();
} catch (error) {
  await cleanup(null, keepOpen).catch(() => {});
  console.error(error?.stack || error?.message || String(error));
  process.exitCode = 1;
}
