import {after, before, test} from 'node:test';
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
