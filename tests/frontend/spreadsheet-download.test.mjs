import test from 'node:test';
import assert from 'node:assert/strict';
import {saveSpreadsheet,spreadsheetMime} from '../../resources/js/lib/spreadsheet-download.ts';

function browser(t){
    const globals=new Map(['document','setTimeout'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
    const create=URL.createObjectURL,revoke=URL.revokeObjectURL;
    const calls={created:[],clicked:[],revoked:[],timers:[],removed:0};
    globalThis.document={body:{appendChild(){}},createElement:()=>({click(){calls.clicked.push({href:this.href,name:this.download});},remove(){calls.removed++;}})};
    globalThis.setTimeout=callback=>{calls.timers.push(callback);return 1;};
    URL.createObjectURL=blob=>{calls.created.push(blob);return 'blob:synthetic-download';};
    URL.revokeObjectURL=url=>calls.revoked.push(url);
    t.after(()=>{for(const [key,value] of globals){if(value)Object.defineProperty(globalThis,key,value);else delete globalThis[key];}URL.createObjectURL=create;URL.revokeObjectURL=revoke;});
    return calls;
}

test('an explicit XLSX download preserves its bytes and uses a local blob attachment',async t=>{
    const calls=browser(t);
    const response=new Response('synthetic spreadsheet bytes',{headers:{'Content-Type':spreadsheetMime+'; charset=binary'}});

    assert.equal(await saveSpreadsheet(response,'cadastro-90.xlsx'),true);
    assert.equal(await calls.created[0].text(),'synthetic spreadsheet bytes');
    assert.deepEqual(calls.clicked,[{href:'blob:synthetic-download',name:'cadastro-90.xlsx'}]);
    assert.equal(calls.removed,1);
    calls.timers[0]();
    assert.deepEqual(calls.revoked,['blob:synthetic-download']);
});

test('a forbidden response or an HTML login page never becomes a downloaded spreadsheet',async t=>{
    const calls=browser(t);
    for(const response of [new Response('forbidden',{status:403,headers:{'Content-Type':spreadsheetMime}}),new Response('<html>login</html>',{headers:{'Content-Type':'text/html'}}),Response.json({message:'error'})]){
        await assert.rejects(saveSpreadsheet(response,'cadastro-90.xlsx'),/não retornou uma planilha válida/);
    }

    assert.deepEqual(calls.created,[]);
    assert.deepEqual(calls.clicked,[]);
});

test('leaving a resource cancels an unopened body and avoids a late download',async t=>{
    const calls=browser(t);let cancelled=false;
    const response=new Response(new ReadableStream({cancel(){cancelled=true;}}),{headers:{'Content-Type':spreadsheetMime}});

    assert.equal(await saveSpreadsheet(response,'cadastro-90.xlsx',()=>false),false);
    assert.equal(cancelled,true);
    assert.deepEqual(calls.created,[]);
});

test('changing context while the response body is being read discards its file',async t=>{
    const calls=browser(t);let resolve,current=true;
    const response={ok:true,headers:new Headers({'Content-Type':spreadsheetMime}),blob:()=>new Promise(done=>resolve=done)};
    const pending=saveSpreadsheet(response,'cadastro-90.xlsx',()=>current);

    current=false;resolve(new Blob(['late bytes']));

    assert.equal(await pending,false);
    assert.deepEqual(calls.created,[]);
});

test('an empty body cannot produce an apparently successful file',async t=>{
    const calls=browser(t);

    await assert.rejects(saveSpreadsheet(new Response('',{headers:{'Content-Type':spreadsheetMime}}),'cadastro-90.xlsx'),/arquivo vazio/);
    assert.deepEqual(calls.clicked,[]);
});

test('the download filename cannot turn an XLSX into another file type or a path',async t=>{
    const calls=browser(t);

    await saveSpreadsheet(new Response('bytes',{headers:{'Content-Type':spreadsheetMime}}),'../../page.html');

    assert.equal(calls.clicked[0].name,'cadastro.xlsx');
    assert.equal(calls.clicked[0].href,'blob:synthetic-download');
});
