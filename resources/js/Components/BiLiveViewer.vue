<script setup lang="ts">
import {computed,nextTick,onMounted,onUnmounted,ref,watch} from 'vue';
import {Image,LoaderCircle,RefreshCw,Clock3,AlertCircle,Maximize2,Monitor,Tablet,Smartphone,Scan,CircleHelp,MousePointer2,X} from 'lucide-vue-next';
import {canStartPreviewSession,fitPreview,previewDevices,type PreviewDevice,type PreviewGeometry} from '../lib/responsive-preview';
const props=withDefaults(defineProps<{tenant:string;appId:string;dashboard?:string;compact?:boolean;deviceControls?:boolean;device?:PreviewDevice;active?:boolean;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>(),{active:true});
const emit=defineEmits<{fallback:[];started:[];'update:device':[device:PreviewDevice]}>();
const source=ref('');const busy=ref(false);const loading=ref(false);const error=ref('');const supported=ref(false);const expires=ref('');const now=ref(Date.now());const delayed=ref(false);const frame=ref<HTMLIFrameElement|null>(null);
const deviceMode=ref<PreviewDevice>(props.device??'auto');const stage=ref<HTMLElement|null>(null);const stageWidth=ref(0);const stageHeight=ref(0);let stageObserver:ResizeObserver|undefined;
const navigationOpen=ref(false);const navigationButton=ref<HTMLButtonElement|null>(null);const navigationId='preview-navigation-'+crypto.randomUUID();
const canFocus=computed(()=>Boolean(frame.value&&source.value&&!expired.value&&!loading.value&&props.active!==false));
const deviceIcons={auto:Scan,desktop:Monitor,tablet:Tablet,phone:Smartphone};
const geometry=computed(()=>fitPreview(deviceMode.value,stageWidth.value,stageHeight.value));
const displayGeometry=ref<PreviewGeometry|null>(null);
// Keep the authenticated frame mounted during transient zero-sized layouts; its login link is single-use.
watch(geometry,value=>{if(value.ready)displayGeometry.value=value;},{flush:'sync'});
const deviceHint=computed(()=>deviceMode.value==='auto'?'Área':`${geometry.value.viewportWidth} × ${geometry.value.viewportHeight}`);
function chooseDevice(device:PreviewDevice){deviceMode.value=device;emit('update:device',device);}
function deviceLabel(device:typeof previewDevices[number]){return device.id==='auto'?device.label:`Visualizar como ${device.label.toLowerCase()} (${device.width} × ${device.height})`;}
watch(()=>props.device,value=>{if(value)deviceMode.value=value;});
watch(stage,element=>{stageObserver?.disconnect();stageWidth.value=0;stageHeight.value=0;if(!element)return;stageObserver=new ResizeObserver(entries=>{const box=entries[0]?.contentRect;if(!box)return;stageWidth.value=box.width;stageHeight.value=box.height;});stageObserver.observe(element);},{flush:'post'});
let version=0;let clock:ReturnType<typeof setInterval>|undefined;let timeout:ReturnType<typeof setTimeout>|undefined;
const remaining=computed(()=>expires.value?Math.max(0,Math.ceil((new Date(expires.value).getTime()-now.value)/60000)):0);
const expired=computed(()=>Boolean(expires.value)&&remaining.value===0);
function canStartSession(){return canStartPreviewSession({supported:supported.value,active:props.active!==false,responsive:props.deviceControls===true,measured:geometry.value.ready});}
watch([supported,geometry,()=>props.active],()=>{if(canStartSession()&&!source.value&&!busy.value&&!error.value&&!expired.value)start();},{flush:'post'});
async function start(){if(!canStartSession()||busy.value)return;const current=++version;source.value='';displayGeometry.value=geometry.value.ready?geometry.value:null;busy.value=true;loading.value=true;error.value='';delayed.value=false;expires.value='';if(timeout)clearTimeout(timeout);try{const result=await props.request(`/catalog/${props.tenant}/apps/${props.appId}/viewer`,'POST',{isolated_context:true,...(props.dashboard?{dashboard:Number(props.dashboard)}:{})});if(current!==version)return;if(props.active===false){loading.value=false;return;}const url=new URL(result.url);if(url.protocol!=='https:'||url.origin!==result.origin)throw new Error('O visualizador não retornou um endereço válido.');expires.value=result.session_expires_at;now.value=Date.now();source.value=result.url;emit('started');timeout=setTimeout(()=>{if(loading.value)delayed.value=true;},30000);}catch(e){if(current===version){error.value=(e as Error).message;loading.value=false;}}finally{if(current===version)busy.value=false;}}
async function closeNavigation(){navigationOpen.value=false;await nextTick();navigationButton.value?.focus({preventScroll:true});}
async function enterDashboard(){
 if(!canFocus.value)return;
 navigationOpen.value=false;
 await nextTick();
 if(!canFocus.value)return;
 try{frame.value?.focus({preventScroll:true});}catch{error.value='Clique dentro da prévia para usar o teclado no dashboard.';}
}
function loaded(){loading.value=false;if(timeout)clearTimeout(timeout);}
async function fullscreen(){try{await frame.value?.requestFullscreen();}catch{error.value='Não foi possível abrir em tela cheia. A prévia continua disponível abaixo.';}}
onMounted(()=>{supported.value='credentialless' in HTMLIFrameElement.prototype;if(supported.value)start();clock=setInterval(()=>{now.value=Date.now();if(expired.value)source.value='';},1000);});
onUnmounted(()=>{stageObserver?.disconnect();version++;source.value='';if(clock)clearInterval(clock);if(timeout)clearTimeout(timeout);});
</script>
<template>
 <section class="bi-live-viewer" :class="{'is-compact':compact,'has-device-controls':deviceControls}">
  <header>
   <div><strong v-if="!deviceControls">Prévia interativa</strong><small v-if="expires&&!expired"><Clock3 :size="12"/>{{compact?'Sessão: '+remaining+' min':'Sessão disponível por até '+remaining+' min'}}</small></div>
   <div v-if="deviceControls" class="bi-preview-devices" role="group" aria-label="Dispositivo da prévia" data-tour="chat-preview-devices">
    <button v-for="device in previewDevices" :key="device.id" type="button" :aria-label="deviceLabel(device)" :title="deviceLabel(device)" :aria-pressed="deviceMode===device.id" @click="chooseDevice(device.id)"><component :is="deviceIcons[device.id]" :size="15"/></button>
    <span class="bi-device-size" aria-live="polite">{{deviceHint}}</span>
   </div>
   <div class="bi-live-viewer-actions"><button v-if="supported" ref="navigationButton" class="bi-navigation-toggle" type="button" data-tour="chat-preview-navigation" :aria-controls="navigationId" :aria-expanded="navigationOpen" aria-label="Ajuda para rolar e navegar no dashboard" title="Ajuda para rolar e navegar" @click="navigationOpen=!navigationOpen" @keydown.esc.stop.prevent="closeNavigation"><CircleHelp :size="16"/></button><button v-if="source&&!compact" class="icon-button" aria-label="Ampliar prévia interativa" @click="fullscreen"><Maximize2 :size="16"/></button><button v-if="supported" class="secondary-button" :disabled="busy||active===false" @click="start"><RefreshCw :size="14" :class="{spin:busy}"/>{{expired?'Reconectar':'Recarregar'}}</button><button v-if="!compact" class="secondary-button" @click="emit('fallback')"><Image :size="14"/>Prévia visual</button></div>
  </header>
  <section v-if="navigationOpen&&supported" :id="navigationId" class="bi-navigation-help" role="region" aria-label="Ajuda de navegação da prévia" @keydown.esc.stop.prevent="closeNavigation">
   <header><strong>Como navegar no dashboard</strong><button type="button" class="icon-button" aria-label="Fechar ajuda da prévia" @click="closeNavigation"><X :size="15"/></button></header>
   <p>Para rolar a página, use a margem interna ou a barra à direita do dashboard. Sobre mapas e alguns gráficos, a roda do mouse pode controlar o zoom.</p>
   <p>Quando o mapa agrupa pontos, o número no círculo mostra quantos estão reunidos. Use os controles de zoom para explorar os locais.</p>
   <div><p>Para usar o teclado dentro do dashboard, selecione <strong>Entrar no dashboard</strong>. A sessão e os filtros são preservados.</p><button type="button" class="secondary-button" :disabled="!canFocus" @click="enterDashboard"><MousePointer2 :size="14"/>Entrar no dashboard</button></div>
  </section>
  <p v-if="!supported" class="resource-note">A prévia interativa não está disponível neste navegador. A prévia visual continua disponível.</p><p v-else-if="expired" class="resource-note">A sessão temporária terminou. Reconecte para continuar explorando o dashboard.</p><p v-if="busy||loading" class="live-viewer-loading" role="status"><LoaderCircle :size="18" class="spin"/> Abrindo o dashboard com a sua conta…</p><p v-if="delayed" class="resource-note">O carregamento está demorando. Você pode continuar pela prévia visual.</p><p v-if="error" class="form-error" role="alert"><AlertCircle :size="16"/>{{error}}</p>
  <div v-if="deviceControls&&supported" ref="stage" class="bi-viewer-stage" :class="{'is-device':deviceMode!=='auto'}" aria-label="Área responsiva do dashboard">
   <div v-if="displayGeometry&&source&&!expired" class="bi-device-shell" :class="'device-'+deviceMode" :style="{width:displayGeometry.displayWidth+'px',height:displayGeometry.displayHeight+'px'}">
    <iframe ref="frame" :src="source" :style="{width:displayGeometry.viewportWidth+'px',height:displayGeometry.viewportHeight+'px',transform:`scale(${displayGeometry.scale})`}" credentialless sandbox="allow-scripts allow-same-origin allow-forms" referrerpolicy="no-referrer" allow="fullscreen" title="Dashboard interativo do G-Analise BI" @load="loaded"></iframe>
   </div>
  </div>
  <iframe v-else-if="source&&!expired" ref="frame" :src="source" credentialless sandbox="allow-scripts allow-same-origin allow-forms" referrerpolicy="no-referrer" allow="fullscreen" title="Dashboard interativo do G-Analise BI" @load="loaded"></iframe>
  <p v-if="source&&!compact" class="catalog-footnote">Esta sessão usa as permissões da sua conta G-Analise BI. Se o conteúdo não aparecer, utilize a prévia visual.</p>
 </section>
</template>

<style scoped>
.bi-navigation-toggle{display:inline-flex;align-items:center;justify-content:center;min-width:29px;height:29px;flex-shrink:0;border:1px solid transparent;border-radius:6px;background:transparent;color:#8295b0}.bi-navigation-toggle:hover,.bi-navigation-toggle[aria-expanded="true"]{color:#0861ee;border-color:#d8e6fa;background:#eef5ff}.bi-navigation-toggle:focus-visible,.bi-navigation-help button:focus-visible{outline:2px solid #0861ee;outline-offset:2px}.bi-navigation-help{flex-shrink:0;padding:10px 14px;border-bottom:1px solid #dce7f5;background:#f4f8ff;color:#6c85a5}.bi-navigation-help>header{display:flex;align-items:center;justify-content:space-between;gap:10px}.bi-navigation-help strong{font-size:11px;font-weight:600;color:#45668f}.bi-navigation-help p{font-size:11px;line-height:1.8;margin:5px 0;max-width:750px}.bi-navigation-help>div{display:flex;gap:14px;align-items:center;justify-content:space-between}.bi-navigation-help .secondary-button{font-size:10px;white-space:nowrap;padding:7px 9px;min-height:31px}.bi-navigation-help>header .icon-button{width:25px;height:25px;flex-shrink:0}
@media(max-width:600px){.bi-navigation-help>div{align-items:flex-start;flex-direction:column;gap:5px}.bi-navigation-help{padding:10px}}

.bi-live-viewer>header>.bi-preview-devices{display:flex;flex-wrap:nowrap;align-items:center;gap:2px;margin-left:auto}.bi-preview-devices>button{display:inline-flex;align-items:center;justify-content:center;width:29px;height:29px;flex-shrink:0;border:1px solid transparent;border-radius:5px;color:#8394ac;background:transparent}.bi-preview-devices>button:hover{color:#3c73b6;background:#f1f6ff}.bi-preview-devices>button[aria-pressed="true"]{color:#0861ee;background:#eaf2ff;border-color:#d3e3fb}.bi-preview-devices>button:focus-visible{outline:2px solid #0861ee;outline-offset:2px}.bi-device-size{margin-left:5px;color:#8a9ab1;font-size:9px;white-space:nowrap;font-variant-numeric:tabular-nums}.bi-live-viewer>header>.bi-live-viewer-actions{flex-wrap:nowrap}
.bi-viewer-stage{position:relative;display:flex;align-items:center;justify-content:center;flex:1;min-width:0;min-height:0;overflow:clip;background:#fff}.bi-viewer-stage.is-device{background:#eef3f9}.bi-device-shell{position:relative;flex-shrink:0;overflow:clip;background:#fff}.bi-device-shell:not(.device-auto){box-shadow:0 0 0 2px #58708d,0 8px 22px #27496c1a;border-radius:5px}.bi-device-shell.device-phone{border-radius:18px;box-shadow:0 0 0 3px #334c69,0 8px 24px #27496c24}.bi-device-shell.device-tablet{border-radius:10px}.bi-device-shell>iframe{position:absolute;left:0;top:0;display:block;border:0;background:#fff;transform-origin:top left}
@media(max-width:540px){.bi-device-size{display:none}.bi-preview-devices>button{height:31px;width:31px}}
</style>
