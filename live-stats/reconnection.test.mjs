import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import http from 'node:http';
import { createInterface } from 'node:readline';
import { test } from 'node:test';
import { WebSocket } from 'ws';

const origin = 'https://tracker.example.test';
const privateData = {
  external_ip: '198.51.100.21',
  internal_ip: '192.0.2.50',
  isp: 'Private Provider',
  city: 'Private City',
  devices: [{ name: 'Private Laptop', mac: '02:00:00:00:00:01' }],
};

function messages(url, socketOrigin = origin) {
  const socket = new WebSocket(url, { origin: socketOrigin });
  const queue = [];
  socket.on('message', data => queue.push(JSON.parse(data)));
  socket.on('error', () => {});
  return {
    socket,
    async next(predicate) {
      const deadline = Date.now() + 8000;
      while (Date.now() < deadline) {
        const index = queue.findIndex(predicate);
        if (index !== -1) return queue.splice(index, 1)[0];
        await new Promise(resolve => setTimeout(resolve, 20));
      }
      assert.fail('Timed out waiting for WebSocket message');
    },
  };
}

test('live updates and reconnect snapshots stay anonymous across upstream failures', { timeout: 24000 }, async t => {
  let mode = 'ok';
  let runId = 1;
  const upstream = http.createServer((req, res) => {
    assert.equal(req.url, '/api/v2/latest');
    if (mode === 'oversized') return res.end('x'.repeat(20_000));
    res.end(JSON.stringify({
      data: { id: runId, timestamp: '2026-01-01T00:00:00Z', download_mbps: 123, ...privateData },
    }));
  });
  upstream.listen(0, '127.0.0.1');
  await once(upstream, 'listening');
  t.after(() => { upstream.close(); upstream.closeAllConnections(); });
  const sidecar = spawn(process.execPath, [new URL('./server.mjs', import.meta.url).pathname], {
    env: { ...process.env, TRACKER_URL: `http://127.0.0.1:${upstream.address().port}`, PUBLIC_ORIGIN: origin, PORT: '0' },
    stdio: ['ignore', 'pipe', 'inherit'],
  });
  t.after(() => sidecar.kill());
  const line = await once(createInterface({ input: sidecar.stdout }), 'line');
  const port = line[0].match(/port (\d+)$/)[1];
  const url = `ws://127.0.0.1:${port}/ws/speedtest`;
  const denied = messages(url, 'https://wrong.example.test');
  const rejection = await new Promise(resolve => denied.socket.once('error', resolve));
  assert.match(rejection.message, /403/);

  const first = messages(url);
  t.after(() => first.socket.terminate());
  const event = await first.next(packet => packet.available && packet.data?.id === 1);
  assert.deepEqual(event.data, {
    id: 1, timestamp: '2026-01-01T00:00:00.000Z', download_mbps: 123, upload_mbps: null,
    ping_ms: null, jitter_ms: null, packet_loss_pct: null,
  });

  mode = 'oversized';
  await first.next(packet => packet.type === 'status' && !packet.available);
  first.socket.close();
  await once(first.socket, 'close');

  const reconnected = messages(url);
  t.after(() => reconnected.socket.terminate());
  const stale = await reconnected.next(packet => packet.type === 'snapshot');
  assert.equal(stale.available, false);
  assert.deepEqual(stale.data, event.data);
  mode = 'ok';
  runId = 2;
  const recovered = await reconnected.next(packet => packet.type === 'speedtest.updated');
  assert.equal(recovered.available, true);
  assert.deepEqual(recovered.data, { ...event.data, id: 2 });
});
