import http from 'node:http';
import { WebSocketServer, WebSocket } from 'ws';
import { sanitize } from './metrics.mjs';

const base = process.env.TRACKER_URL;
const token = process.env.TRACKER_TOKEN;
const origin = process.env.PUBLIC_ORIGIN;
if (!base || !token || !origin) throw new Error('TRACKER_URL, TRACKER_TOKEN and PUBLIC_ORIGIN are required');
const upstream = new URL('/api/v2/latest', base);
if (!['http:', 'https:'].includes(upstream.protocol)) throw new Error('Invalid tracker URL');
const interval = Math.max(5000, Number(process.env.POLL_MS) || 5000);
let latest = null, available = false, busy = false;
const server = http.createServer((req, res) => {
  if (req.url === '/health') {
    res.writeHead(available ? 200 : 503, {'Content-Type':'application/json'});
    return res.end(JSON.stringify({status: available ? 'ok' : 'unavailable'}));
  }
  res.writeHead(404); res.end();
});
const wss = new WebSocketServer({noServer:true, maxPayload:1024, perMessageDeflate:false});
function send(ws, message) {
  if (ws.readyState !== WebSocket.OPEN) return;
  if (ws.bufferedAmount > 65536) return ws.terminate();
  ws.send(JSON.stringify(message));
}
function broadcast(message) { for (const ws of wss.clients) send(ws,message); }
server.on('upgrade', (req, socket, head) => {
  if (req.url !== '/ws/speedtest' || req.headers.origin !== origin || wss.clients.size >= 200) {
    socket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n'); return;
  }
  wss.handleUpgrade(req,socket,head,ws => wss.emit('connection',ws));
});
wss.on('connection',ws => {
  ws.alive = true;
  ws.on('error', () => ws.terminate());
  ws.on('pong', () => { ws.alive = true; });
  ws.on('message', () => ws.close(1008,'Read only'));
  send(ws,{type:'snapshot',available,data:latest});
});
async function poll() {
  if (busy) return;
  busy = true;
  try {
    const response = await fetch(upstream,{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:AbortSignal.timeout(4000),redirect:'error'});
    if (!response.ok) throw new Error('Upstream unavailable');
    const payload = await response.json();
    const next = sanitize(payload.data);
    if (payload.data !== null && !next) throw new Error('Invalid upstream response');
    const changed = JSON.stringify(next) !== JSON.stringify(latest);
    const recovered = !available;
    latest = next; available = true;
    if (changed || recovered) broadcast({type:'speedtest.updated',available,data:latest});
  } catch {
    if (available) { available = false; broadcast({type:'status',available:false}); }
  } finally { busy = false; }
}
setInterval(poll,interval).unref();
setInterval(() => {
  for (const ws of wss.clients) {
    if (!ws.alive) { ws.terminate(); continue; }
    ws.alive = false; ws.ping();
  }
},30000).unref();
server.listen(8080,'0.0.0.0');
poll();
