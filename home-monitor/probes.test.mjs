import {test} from 'node:test';
import assert from 'node:assert/strict';
import {validateTargets,record,metrics} from './probes.mjs';
test('outage transitions and recovery',()=>{
  let s=record(null,{up:true,seconds:0.02},100);
  s=record(s,{up:false,seconds:null},110);
  s=record(s,{up:false,seconds:null},120);
  s=record(s,{up:true,seconds:0.01},130);
  assert.equal(s.outageCount,1);
  assert.equal(s.outageSeconds,20);
  assert.equal(s.failures,2);
});
test('only aliases and numeric measurements are exported',()=>{
  const t=validateTargets([{name:'router',type:'tcp',host:'192.168.1.1',port:443}])[0];
  const output=metrics(new Map([[0,record(null,{up:false,seconds:null},100)]]),110);
  assert.ok(output.includes('probe="probe_1"'));
  assert.ok(!output.includes(t.host));
  assert.ok(!output.includes(t.name));
  assert.ok(!output.includes('latency_seconds'));
});
test('invalid aliases rejected',()=>assert.throws(()=>validateTargets([{name:'192.168.1.1',type:'tcp',host:'localhost',port:80}])));
