import net from 'node:net';
import { Resolver } from 'node:dns/promises';
import { performance } from 'node:perf_hooks';

export function validateTargets(targets) {
  if (!Array.isArray(targets) || !targets.length || targets.length > 32) throw new Error('Configure 1-32 targets');
  const names = new Set();
  for (const t of targets) {
    if (!t || !/^[a-z][a-z0-9_-]{0,39}$/.test(t.name) || names.has(t.name)) throw new Error('Invalid or duplicate anonymous alias');
    names.add(t.name);
    if (t.type === 'http') {
      const url = new URL(t.url);
      if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error('Invalid HTTP target');
    } else if (t.type === 'tcp') {
      if (typeof t.host !== 'string' || !t.host || !Number.isInteger(t.port) || t.port < 1 || t.port > 65535) throw new Error('Invalid TCP target');
    } else if (t.type === 'dns') {
      if (typeof t.hostname !== 'string' || !t.hostname) throw new Error('Invalid DNS target');
      if (t.server) new Resolver().setServers([t.server]);
    } else throw new Error('Unsupported probe type');
  }
  return targets;
}

function tcp(target, timeout) {
  return new Promise((resolve, reject) => {
    const socket = net.createConnection({host:target.host, port:target.port});
    const timer = setTimeout(() => socket.destroy(new Error('Timeout')), timeout);
    socket.once('connect', () => { clearTimeout(timer); socket.destroy(); resolve(); });
    socket.once('error', error => { clearTimeout(timer); socket.destroy(); reject(error); });
  });
}

export async function probe(target, timeout = 3000) {
  const start = performance.now();
  try {
    if (target.type === 'tcp') await tcp(target, timeout);
    if (target.type === 'dns') {
      const resolver = new Resolver({timeout, tries:1});
      if (target.server) resolver.setServers([target.server]);
      const timer = setTimeout(() => resolver.cancel(), timeout);
      try { await resolver.resolve4(target.hostname); } finally { clearTimeout(timer); }
    }
    if (target.type === 'http') {
      const response = await fetch(target.url, {method:'HEAD', redirect:'manual', signal:AbortSignal.timeout(timeout)});
      if (response.status < 200 || response.status >= 400) throw new Error('Unhealthy HTTP status');
    }
    return {up:true, seconds:(performance.now()-start)/1000};
  } catch {
    return {up:false, seconds:null};
  }
}

export function record(previous, sample, now) {
  const old = previous ?? {attempts:0, failures:0, outageCount:0, outageSeconds:0, downSince:null};
  const next = {...old, ...sample, checkedAt:now, attempts:old.attempts+1, failures:old.failures+Number(!sample.up)};
  if (!sample.up && old.downSince === null) {
    next.downSince = now;
    next.outageCount++;
  } else if (sample.up && old.downSince !== null) {
    next.outageSeconds += Math.max(0, now-old.downSince);
    next.downSince = null;
  }
  return next;
}

export function metrics(states, now) {
  const lines = [];
  for (const [index, s] of states) {
    const label = '{probe="probe_'+(index + 1)+'"}';
    const values = {
      up:Number(s.up), attempts_total:s.attempts, failures_total:s.failures,
      outages_total:s.outageCount, outage_seconds_total:s.outageSeconds,
      current_outage_seconds:s.downSince === null ? 0 : Math.max(0, now-s.downSince),
      last_check_timestamp_seconds:s.checkedAt
    };
    if (s.seconds !== null) values.latency_seconds = s.seconds;
    for (const [key,value] of Object.entries(values)) lines.push('home_network_'+key+label+' '+value);
  }
  return lines.join('\n')+'\n';
}
