import http from 'node:http';
import { readFile } from 'node:fs/promises';
import { validateTargets, probe, record, metrics } from './probes.mjs';

const targets = validateTargets(JSON.parse(await readFile(process.env.TARGETS_FILE || '/config/targets.json','utf8')));
const interval = Number(process.env.INTERVAL_MS ?? 15000);
if (!Number.isInteger(interval) || interval < 10000 || interval > 300000) throw new Error('INTERVAL_MS must be between 10000 and 300000');
const states = new Map();
let busy = false;
async function tick() {
  if (busy) return;
  busy = true;
  try {
    await Promise.all(targets.map(async (target, index) => {
      const sample = await probe(target);
      states.set(index, record(states.get(index), sample, Date.now()/1000));
    }));
  } finally { busy = false; }
}
const server = http.createServer((req,res) => {
  if (req.method !== 'GET') { res.writeHead(405); return res.end(); }
  if (req.url === '/metrics') {
    res.writeHead(200,{'Content-Type':'text/plain; version=0.0.4; charset=utf-8','Cache-Control':'no-store'});
    return res.end(metrics(states,Date.now()/1000));
  }
  if (req.url === '/health') {
    const ready = states.size === targets.length && [...states.values()].every(s => Date.now()/1000-s.checkedAt < interval/1000*3);
    res.writeHead(ready?200:503,{'Content-Type':'application/json'});
    return res.end(JSON.stringify({status:ready?'ok':'unavailable'}));
  }
  res.writeHead(404); res.end();
});
server.maxConnections = 64;
server.requestTimeout = 5000;
server.headersTimeout = 5000;
server.listen(8090,'0.0.0.0');
await tick();
setInterval(tick,interval);
