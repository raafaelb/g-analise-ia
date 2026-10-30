import '../css/app.css';
import '../css/catalog.css';
import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
const pages=import.meta.glob<{default: DefineComponent}>('./Pages/**/*.vue');
createInertiaApp({title:t=>t?`${t} · G-Analise IA`:'G-Analise IA · Inovadora',resolve:async name=>{const page=pages[`./Pages/${name}.vue`];if(!page)throw new Error('Página não encontrada');return (await page()).default;},setup({el,App,props,plugin}){createApp({render:()=>h(App,props)}).use(plugin).mount(el);},progress:{color:'#0861ee'}});
