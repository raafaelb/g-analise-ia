<script setup lang="ts">
import {computed,onMounted,onUnmounted,ref} from 'vue';
import {Image,LoaderCircle,RefreshCw,Clock3,AlertCircle,Maximize2} from 'lucide-vue-next';
const props=defineProps<{tenant:string;appId:string;dashboard?:string;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>();
const emit=defineEmits<{fallback:[];started:[]}>();
const source=ref('');const busy=ref(false);const loading=ref(false);const error=ref('');const supported=ref(false);const expires=ref('');const now=ref(Date.now());const delayed=ref(false);const frame=ref<HTMLIFrameElement|null>(null);
let version=0;let clock:ReturnType<typeof setInterval>|undefined;let timeout:ReturnType<typeof setTimeout>|undefined;
const remaining=computed(()=>expires.value?Math.max(0,Math.ceil((new Date(expires.value).getTime()-now.value)/60000)):0);
const expired=computed(()=>Boolean(expires.value)&&remaining.value===0);
async function start(){if(!supported.value||busy.value)return;const current=++version;source.value='';busy.value=true;loading.value=true;error.value='';delayed.value=false;expires.value='';if(timeout)clearTimeout(timeout);try{const result=await props.request(`/catalog/${props.tenant}/apps/${props.appId}/viewer`,'POST',{isolated_context:true,...(props.dashboard?{dashboard:Number(props.dashboard)}:{})});if(current!==version)return;const url=new URL(result.url);if(url.protocol!=='https:'||url.origin!==result.origin)throw new Error('O visualizador não retornou um endereço válido.');expires.value=result.session_expires_at;now.value=Date.now();source.value=result.url;emit('started');timeout=setTimeout(()=>{if(loading.value)delayed.value=true;},30000);}catch(e){if(current===version){error.value=(e as Error).message;loading.value=false;}}finally{if(current===version)busy.value=false;}}
function loaded(){loading.value=false;if(timeout)clearTimeout(timeout);}
async function fullscreen(){try{await frame.value?.requestFullscreen();}catch{error.value='Não foi possível abrir em tela cheia. A prévia continua disponível abaixo.';}}
onMounted(()=>{supported.value='credentialless' in HTMLIFrameElement.prototype;if(supported.value)start();clock=setInterval(()=>{now.value=Date.now();if(expired.value)source.value='';},1000);});
onUnmounted(()=>{version++;source.value='';if(clock)clearInterval(clock);if(timeout)clearTimeout(timeout);});
</script>
<template>
 <section class="bi-live-viewer"><header><div><strong>Prévia interativa</strong><small v-if="expires&&!expired"><Clock3 :size="12"/> Sessão disponível por até {{remaining}} min</small></div><div><button v-if="source" class="icon-button" aria-label="Ampliar prévia interativa" @click="fullscreen"><Maximize2 :size="16"/></button><button v-if="supported" class="secondary-button" :disabled="busy" @click="start"><RefreshCw :size="14" :class="{spin:busy}"/>{{expired?'Reconectar':'Recarregar'}}</button><button class="secondary-button" @click="emit('fallback')"><Image :size="14"/>Prévia visual</button></div></header>
  <p v-if="!supported" class="resource-note">A prévia interativa não está disponível neste navegador. A prévia visual continua disponível.</p><p v-else-if="expired" class="resource-note">A sessão temporária terminou. Reconecte para continuar explorando o dashboard.</p><p v-if="busy||loading" class="live-viewer-loading" role="status"><LoaderCircle :size="18" class="spin"/> Abrindo o dashboard com a sua conta…</p><p v-if="delayed" class="resource-note">O carregamento está demorando. Você pode continuar pela prévia visual.</p><p v-if="error" class="form-error" role="alert"><AlertCircle :size="16"/>{{error}}</p>
  <iframe v-if="source&&!expired" ref="frame" :src="source" credentialless sandbox="allow-scripts allow-same-origin allow-forms" referrerpolicy="no-referrer" allow="fullscreen" title="Dashboard interativo do G-Analise BI" @load="loaded"></iframe>
  <p v-if="source" class="catalog-footnote">Esta sessão usa as permissões da sua conta G-Analise BI. Se o conteúdo não aparecer, utilize a prévia visual.</p>
 </section>
</template>
