import {after, before, test} from 'node:test';
import {readFile} from 'node:fs/promises';
import {parse,compileScript} from '@vue/compiler-sfc';
import ts from 'typescript';
import assert from 'node:assert/strict';
import {createServer} from 'vite';
import vue from '@vitejs/plugin-vue';
import {createRenderer, h, nextTick, ref, ssrContextKey} from 'vue';

let server;
let Viewer;

before(async () => {
    server = await createServer({configFile: false, plugins: [vue()], server: {middlewareMode: true, watch: null, hmr: false, ws: false}, appType: 'custom', logLevel: 'error'});
    Viewer = (await server.ssrLoadModule('/resources/js/Components/BiLiveViewer.vue')).default;
});

after(async () => server?.close());

function mountViewer(t, props) {
    const previous = Object.getOwnPropertyDescriptor(globalThis, 'HTMLIFrameElement');
    globalThis.HTMLIFrameElement = class {};
    globalThis.HTMLIFrameElement.prototype.credentialless = false;
    const renderer = createRenderer({insert() {}, remove() {}, createElement() {return {};}, createText() {return {};}, createComment() {return {};}, setText() {}, setElementText() {}, parentNode() {return null;}, nextSibling() {return null;}, patchProp() {}});
    // Exercise the compiled setup and lifecycle without loading an external iframe.
    const component = {...Viewer, render: () => h('div')};
    const app = renderer.createApp({render: () => h(component, props())});
    app.provide(ssrContextKey, {modules: new Set()});
    t.after(() => {
        app.unmount();
        if (previous) Object.defineProperty(globalThis, 'HTMLIFrameElement', previous);
        else delete globalThis.HTMLIFrameElement;
    });
    app.mount({});
}

const session = {url: 'https://bi.example.test/preview', origin: 'https://bi.example.test', session_expires_at: '2099-01-01T00:00:00Z'};

test('the catalog viewer requests one session when active is omitted', async t => {
    const requests = [];
    const request = async (...args) => {requests.push(args); return session;};

    mountViewer(t, () => ({tenant: '100', appId: '200', dashboard: '300', request}));
    await nextTick();
    await nextTick();

    assert.deepEqual(requests, [['/catalog/100/apps/200/viewer', 'POST', {isolated_context: true, dashboard: 300}]]);
});

test('an explicitly inactive viewer waits for activation and reuses its existing session', async t => {
    const active = ref(false);
    const requests = [];
    const request = async (...args) => {requests.push(args); return session;};
    mountViewer(t, () => ({tenant: '100', appId: '200', active: active.value, request}));
    await nextTick();
    assert.deepEqual(requests, []);

    active.value = true;
    await nextTick();
    await nextTick();
    assert.deepEqual(requests, [['/catalog/100/apps/200/viewer', 'POST', {isolated_context: true}]]);

    active.value = false;
    await nextTick();
    active.value = true;
    await nextTick();
    await nextTick();

    assert.equal(requests.length, 1);
});


const {descriptor}=parse(await readFile(new URL('../../resources/js/Components/BiLiveViewer.vue',import.meta.url),'utf8'));
let responsiveScript=compileScript(descriptor,{id:'responsive-viewer-regression',inlineTemplate:true}).content;
responsiveScript=responsiveScript.replace(/from (['"])(vue|lucide-vue-next)\1/g,(_,quote,name)=>`from ${quote}${import.meta.resolve(name)}${quote}`);
responsiveScript=responsiveScript.replace(/from (['"])\.\.\/lib\/responsive-preview\1/g,(_,quote)=>`from ${quote}${new URL('../../resources/js/lib/responsive-preview.ts',import.meta.url)}${quote}`);
const responsiveCode=ts.transpileModule(responsiveScript,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ESNext}}).outputText;
const ResponsiveViewer=(await import('data:text/javascript;base64,'+Buffer.from(responsiveCode).toString('base64'))).default;
function element(kind,text=''){return {kind,text,props:{},children:[],parent:null};}
const responsiveRenderer=createRenderer({
    createElement:kind=>element(kind),createText:text=>element('text',text),createComment:text=>element('comment',text),
    setText:(item,text)=>item.text=text,setElementText:(item,text)=>{item.text=text;item.children=[];},patchProp:(item,key,previous,next)=>item.props[key]=next,
    insert:(item,parent,anchor=null)=>{if(item.parent){const old=item.parent.children.indexOf(item);if(old>=0)item.parent.children.splice(old,1);}const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;},
    remove:item=>{if(item.parent){const index=item.parent.children.indexOf(item);if(index>=0)item.parent.children.splice(index,1);}item.parent=null;},
    parentNode:item=>item.parent,nextSibling:item=>item.parent?.children[item.parent.children.indexOf(item)+1]??null,
    insertStaticContent:(text,parent,anchor)=>{const item=element('static',text),index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;return [item,item];},
});
function findElement(item,predicate){if(predicate(item))return item;return item.children.map(child=>findElement(child,predicate)).find(Boolean);}
async function mountResponsiveViewer(t,request){
    const descriptors=['HTMLIFrameElement','ResizeObserver'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]);
    globalThis.HTMLIFrameElement=class {};
    globalThis.HTMLIFrameElement.prototype.credentialless=false;
    let observer;
    globalThis.ResizeObserver=class {constructor(callback){this.callback=callback;observer=this;}observe(){}disconnect(){}};
    const root=element('root'),device=ref('auto'),active=ref(true);
    const app=responsiveRenderer.createApp({render:()=>h(ResponsiveViewer,{tenant:'100',appId:'200',deviceControls:true,device:device.value,active:active.value,request})});
    t.after(()=>{app.unmount();for(const [key,value] of descriptors){if(value)Object.defineProperty(globalThis,key,value);else delete globalThis[key];}});
    app.mount(root);await nextTick();
    return {root,device,active,frame:()=>findElement(root,item=>item.kind==='iframe'),measure:async(width,height)=>{observer.callback([{contentRect:{width,height}}]);await nextTick();await nextTick();}};
}

test('device switches keep one authenticated frame and the selected native viewport',async t=>{
    const requests=[];
    const view=await mountResponsiveViewer(t,async(...args)=>{requests.push(args);return session;});
    await view.measure(833,714);
    const frame=view.frame();
    assert.ok(frame);
    assert.equal(frame.props.style.width,'833px');
    assert.equal(frame.props.style.height,'714px');

    for(const [device,width,height] of [['phone',390,844],['tablet',768,1024],['desktop',1280,800],['auto',833,714]]){
        view.device.value=device;await nextTick();

        assert.equal(view.frame(),frame);
        assert.equal(frame.props.src,session.url);
        assert.equal(frame.props.style.width,width+'px');
        assert.equal(frame.props.style.height,height+'px');
    }
    assert.equal(requests.length,1);
    assert.equal(frame.props.credentialless,'');
    assert.equal(frame.props.sandbox,'allow-scripts allow-same-origin allow-forms');
});

test('a transient zero-sized or hidden panel retains its frame and resumes without another ticket',async t=>{
    const requests=[];
    const view=await mountResponsiveViewer(t,async(...args)=>{requests.push(args);return session;});
    assert.equal(requests.length,0);
    await view.measure(833,714);
    const frame=view.frame();

    view.active.value=false;await nextTick();await view.measure(0,0);
    assert.equal(view.frame(),frame);
    view.device.value='phone';await nextTick();
    assert.equal(view.frame(),frame);
    assert.equal(frame.props.style.width,'833px');

    view.active.value=true;await nextTick();await view.measure(640,680);
    assert.equal(view.frame(),frame);
    assert.equal(frame.props.style.width,'390px');
    assert.equal(frame.props.style.height,'844px');
    assert.equal(frame.props.src,session.url);
    assert.equal(requests.length,1);
});


test('an expired preview waits for reconnection and preserves the selected device', async t => {
    const startedAt = Date.parse('2026-11-03T12:00:00Z');
    t.mock.timers.enable({apis: ['Date', 'setInterval', 'setTimeout'], now: startedAt});
    const requests = [];
    const view = await mountResponsiveViewer(t, async (...args) => {
        requests.push(args);
        return {...session, url: `https://bi.example.test/preview/${requests.length}`, session_expires_at: new Date(Date.now() + 30 * 60 * 1000).toISOString()};
    });
    await view.measure(833,714);
    view.device.value = 'phone';
    await nextTick();
    const firstFrame = view.frame();
    assert.ok(firstFrame);
    firstFrame.props.onLoad();

    t.mock.timers.tick(30 * 60 * 1000 - 1000);
    await nextTick();
    assert.equal(view.frame(), firstFrame);
    t.mock.timers.tick(1000);
    await nextTick();
    assert.equal(view.frame(), undefined);
    assert.equal(requests.length, 1);

    view.device.value = 'tablet';
    await nextTick();
    await view.measure(640,680);
    view.active.value = false;
    await nextTick();
    view.active.value = true;
    await nextTick();
    assert.equal(requests.length, 1);
    assert.equal(view.frame(), undefined);
    const textOf = item => item.text + item.children.map(textOf).join('');
    const reconnect = findElement(view.root, item => item.kind === 'button' && textOf(item).trim() === 'Reconectar');
    assert.ok(reconnect);
    assert.equal(reconnect.props.disabled, false);

    await reconnect.props.onClick();
    await nextTick();
    assert.equal(requests.length, 2);
    assert.deepEqual(requests[1], requests[0]);
    assert.ok(view.frame());
    assert.notEqual(view.frame(), firstFrame);
    assert.equal(view.frame().props.src, 'https://bi.example.test/preview/2');
    assert.equal(view.frame().props.style.width, '768px');
    assert.equal(view.frame().props.style.height, '1024px');
});
