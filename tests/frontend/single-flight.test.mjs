import test from 'node:test';
import assert from 'node:assert/strict';
import {createSingleFlight} from '../../resources/js/lib/single-flight.ts';

test('two actions waiting for the same new conversation share one creation', async () => {
    const create=createSingleFlight();let calls=0;
    const first=create('project-a',async()=>{calls++;return 'conversation-a';});
    const second=create('project-a',async()=>{calls++;return 'duplicate';});
    assert.equal(first,second);
    assert.deepEqual(await Promise.all([first,second]),['conversation-a','conversation-a']);
    assert.equal(calls,1);
});

test('different projects never share a conversation creation', async () => {
    const create=createSingleFlight();
    assert.deepEqual(await Promise.all([create('a',async()=> 'conversation-a'),create('b',async()=> 'conversation-b')]),['conversation-a','conversation-b']);
});

test('a failed creation allows an explicit retry', async () => {
    const create=createSingleFlight();
    await assert.rejects(create('a',async()=>{throw new Error('network failed');}),/network failed/);
    assert.equal(await create('a',async()=> 'recovered-conversation'),'recovered-conversation');
});
