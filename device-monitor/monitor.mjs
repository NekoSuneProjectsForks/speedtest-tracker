import http from 'node:http';
import { createHmac, timingSafeEqual } from 'node:crypto';
import { readFile } from 'node:fs/promises';
const token = process.env.DEVICE_MONITOR_TOKEN;
if (!token || token.length < 32) throw new Error('Set a strong DEVICE_MONITOR_TOKEN (32+ characters)');
const source = process.env.COUNTERS_FILE || '/config/counters.json';
const interval = Math.max(10000, Number(process.env.INTERVAL_MS) || 30000);
let snapshot = {available:false, devices:[]}, previous = new Map(), lastSample = null;
let busy = false;
const alias = mac => 'device-'+createHmac('sha256',token).update(mac).digest('hex').slice(0,16);
async function poll() {
  if (busy) return;
  busy = true;
  try {
    const raw = JSON.parse(await readFile(source,'utf8'));
    const {columns,data} = raw;
    if (!Array.isArray(columns) || !Array.isArray(data) || data.length > 100000) throw new Error('Invalid counters');
    const indices = ['mac','rx_bytes','tx_bytes'].map(k=>columns.indexOf(k));
    if (indices.some(i=>i<0)) throw new Error('Missing columns');
    const time = Number(raw.sampled_at);
    if (!Number.isFinite(time) || time > Date.now()/1000+5 || Date.now()/1000-time > interval/1000*3) throw new Error('Stale counters');
    if (lastSample !== null && time <= lastSample) return;
    const rows = new Map();
    for (const row of data) {
      if (!Array.isArray(row)) continue;
      const [mac,rx,tx] = indices.map(i=>row[i]);
      if (typeof mac !== 'string' || !/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i.test(mac) || /^00(:00){5}$/.test(mac)) continue;
      if (![rx,tx].every(v=>Number.isSafeInteger(v)&&v>=0)) continue;
      const key=alias(mac.toLowerCase()), old=rows.get(key) || [0,0];
      rows.set(key,[old[0]+rx,old[1]+tx]);
    }
    const elapsed = lastSample === null ? 0 : time-lastSample;
    const devices = [...rows].map(([id,[rx,tx]])=>{
      const old = previous.get(id);
      const rate = (n,p) => elapsed>0 && elapsed<=interval/1000*3 && p!==undefined && n>=p ? (n-p)*8/elapsed/1e6 : null;
      return {id,download_bytes:rx,upload_bytes:tx,download_mbps:rate(rx,old?.[0]),upload_mbps:rate(tx,old?.[1])};
    }).sort((a,b)=>(b.download_mbps??0)+(b.upload_mbps??0)-(a.download_mbps??0)-(a.upload_mbps??0));
    previous=rows; lastSample=time; snapshot={available:true,sampled_at:time,devices};
  } catch { snapshot={...snapshot,available:false}; }
  finally { busy=false; }
}
function authorized(req) {
  const got=Buffer.from(req.headers.authorization||'');
  const expected=Buffer.from('Bearer '+token);
  return got.length===expected.length && timingSafeEqual(got,expected);
}
const server=http.createServer((req,res)=>{
  res.setHeader('Cache-Control','no-store');
  if(!authorized(req)){res.writeHead(401);return res.end();}
  if(req.method!=='GET'||req.url!=='/devices'){res.writeHead(404);return res.end();}
  res.writeHead(snapshot.available?200:503,{'Content-Type':'application/json'});
  res.end(JSON.stringify(snapshot));
});
server.listen(8091,'0.0.0.0');
await poll();setInterval(poll,interval);
