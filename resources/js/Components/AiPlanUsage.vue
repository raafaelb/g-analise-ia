<script setup lang="ts">
import {computed,onMounted,onUnmounted,ref,watch} from 'vue';
import {ChevronDown,ExternalLink,RefreshCw,LoaderCircle} from 'lucide-vue-next';
type Item=Record<string,any>;
const props=withDefaults(defineProps<{connected:boolean;accountKey?:string;compact?:boolean;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>(),{compact:false});
const data=ref<Item|null>(null);const busy=ref(false);const error=ref('');const expanded=ref(!props.compact);let generation=0;let timer:ReturnType<typeof setInterval>|undefined;
const plan=computed(()=>{const type=data.value?.plan;return type?({plus:'ChatGPT Plus',pro:'ChatGPT Pro',free:'ChatGPT Free',team:'ChatGPT Team',business:'ChatGPT Business',enterprise:'ChatGPT Enterprise',edu:'ChatGPT Edu'} as Record<string,string>)[type]??type:'Plano ChatGPT';});
const firstWindow=computed(()=>data.value?.buckets?.[0]?.windows?.[0]);
function date(value:string){return new Date(value).toLocaleString('pt-BR',{timeZone:'America/Sao_Paulo',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});}
function windowName(value:number|null){if(value===null)return 'Limite de uso';if(value%10080===0)return value/10080+' semana(s)';if(value%1440===0)return value/1440+' dia(s)';if(value%60===0)return value/60+' hora(s)';return value+' minutos';}
async function load(){if(!props.connected||busy.value)return;const version=++generation;busy.value=true;error.value='';try{const result=await props.request('/connections/openai/usage');if(version===generation)data.value=result;}catch(e){if(version===generation)error.value=(e as Error).message;}finally{if(version===generation)busy.value=false;}}
watch(()=>[props.connected,props.accountKey],()=>{generation++;busy.value=false;data.value=null;error.value='';load();});
onMounted(()=>{load();timer=setInterval(()=>{if(!document.hidden)load();},120000);});
onUnmounted(()=>{generation++;if(timer)clearInterval(timer);});
</script>
<template>
 <section v-if="connected" class="ai-plan-usage" :class="{compact}"><button v-if="compact" class="ai-usage-toggle" :aria-expanded="expanded" @click="expanded=!expanded"><span>{{plan}}</span><span v-if="firstWindow">{{firstWindow.remaining_percent}}% disponível</span><span v-else>Consultar limites</span><ChevronDown :size="13"/></button>
  <div v-if="expanded" class="ai-usage-detail"><header><strong>{{plan}}</strong><button class="icon-button" :disabled="busy" aria-label="Atualizar limites da IA" @click="load"><LoaderCircle v-if="busy" :size="14" class="spin"/><RefreshCw v-else :size="14"/></button></header><p v-if="busy&&!data" role="status">Consultando informações da sua conta…</p><p v-if="error" role="alert">{{error}}</p><template v-if="data"><p v-if="!data.buckets.length">{{data.message}}</p><article v-for="bucket in data.buckets" :key="bucket.id"><strong>{{bucket.name}}</strong><div v-for="window in bucket.windows" :key="window.name" class="ai-quota-window"><div><span>{{windowName(window.window_minutes)}}</span><strong>{{window.remaining_percent}}% disponível</strong></div><meter min="0" max="100" :value="window.remaining_percent" :aria-label="'Saldo disponível: '+window.remaining_percent+'%'"/><small v-if="window.resets_at">Renovação em {{date(window.resets_at)}} · Brasília</small></div></article><p v-if="data.buckets.length">{{data.message}}</p><small class="ai-usage-checked">Consulta: {{date(data.checked_at)}} · Atualização a cada 2 minutos</small></template><a href="https://chatgpt.com/settings/usage" target="_blank" rel="noopener noreferrer">Ver uso na conta ChatGPT <ExternalLink :size="12"/></a></div>
 </section>
</template>
