import {test} from 'node:test';
import assert from 'node:assert/strict';
import {sanitize} from './metrics.mjs';
test('allowlist strips all raw identifying fields',()=>{
  const result = sanitize({id:1,timestamp:'2026-11-25T12:00:00Z',download_mbps:100,upload_mbps:20,ping_ms:0,jitter_ms:'secret',isp:'secret',ip:'192.0.2.1',data:{interface:{externalIp:'192.0.2.1'}},server:{name:'secret'}});
  assert.deepEqual(result,{id:1,timestamp:'2026-11-25T12:00:00.000Z',download_mbps:100,upload_mbps:20,ping_ms:0,jitter_ms:null,packet_loss_pct:null});
});
test('reject malformed rows',()=>{assert.equal(sanitize(null),null);assert.equal(sanitize({id:1,timestamp:'bad'}),null);});
