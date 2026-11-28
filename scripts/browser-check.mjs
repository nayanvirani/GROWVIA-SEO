// Visits pages in headless Chrome (via the DevTools protocol) and reports, per page and width:
// JavaScript exceptions, console errors, empty renders and horizontal overflow.
// Usage: node --experimental-websocket scripts/browser-check.mjs <config.json>
//   config: { port, cookie: {name, value}, urls: [...], widths: [1366, 1920] }
import { readFileSync } from 'node:fs';

const cfg = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const target = await (await fetch(`http://127.0.0.1:${cfg.port}/json/new?about:blank`, { method: 'PUT' })).json();
const ws = new WebSocket(target.webSocketDebuggerUrl);
let id = 0;
const pending = new Map();
let problems = [];
const send = (method, params = {}) =>
    new Promise((resolve) => {
        const i = ++id;
        pending.set(i, resolve);
        ws.send(JSON.stringify({ id: i, method, params }));
    });
ws.onmessage = (m) => {
    const msg = JSON.parse(m.data);
    if (msg.id && pending.has(msg.id)) {
        pending.get(msg.id)(msg.result);
        pending.delete(msg.id);
        return;
    }
    if (msg.method === 'Runtime.exceptionThrown') {
        problems.push('EXCEPTION ' + (msg.params.exceptionDetails.exception?.description ?? msg.params.exceptionDetails.text).split('\n')[0]);
    }
    if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
        problems.push(
            'CONSOLE ' +
                msg.params.args
                    .map((a) => a.value ?? a.description)
                    .join(' ')
                    .slice(0, 300),
        );
    }
};
await new Promise((r) => (ws.onopen = r));
await send('Runtime.enable');
await send('Network.enable');
await send('Network.setCookie', { name: cfg.cookie.name, value: cfg.cookie.value, url: cfg.urls[0] });

const probe = `(() => {
  const vw = document.documentElement.clientWidth, sw = document.documentElement.scrollWidth;
  const wide = [...document.querySelectorAll('body *')].filter(e => e.getBoundingClientRect().right > vw + 1 && e.getBoundingClientRect().width > 0)
    .map(e => e.tagName.toLowerCase() + ': ' + (e.innerText || '').slice(0, 50).replace(/\\s+/g, ' ')).slice(0, 3);
  return { text: (document.getElementById('app')?.innerText || '').length, vw, sw, wide };
})()`;

let failures = 0;
for (const width of cfg.widths) {
    await send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
    for (const url of cfg.urls) {
        problems = [];
        await send('Page.navigate', { url });
        await new Promise((r) => setTimeout(r, 3000));
        const res = (await send('Runtime.evaluate', { expression: probe, returnByValue: true })).result.value;
        const bad = problems.length > 0 || !res.text || res.sw > res.vw;
        failures += bad ? 1 : 0;
        console.log(
            `${bad ? 'FAIL' : 'ok  '} ${width}px ${new URL(url).pathname}${res.sw > res.vw ? ` (overflow ${res.sw}px > ${res.vw}px)` : ''}${!res.text ? ' (blank page)' : ''}`,
        );
        for (const p of [...new Set(problems)]) console.log('     ' + p);
        if (res.sw > res.vw) for (const w of res.wide) console.log('     too wide → ' + w);
    }
}
process.exit(failures ? 1 : 0);
