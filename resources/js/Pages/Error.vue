<script setup lang="ts">
import {computed} from 'vue';
import {Head} from '@inertiajs/vue3';
import {ArrowLeft,LifeBuoy} from 'lucide-vue-next';
import {appUrl} from '../lib/urls';

const props=defineProps<{status:number}>();
const messages:Record<number,{title:string;description:string}>={
 403:{title:'Seu acesso não permite abrir esta página',description:'Entre com a conta correta ou peça ao responsável pelo sistema para conferir seu acesso.'},
 404:{title:'Não encontramos esta página',description:'O endereço pode ter mudado ou a página pode não estar disponível para sua conta. Volte ao início para encontrar seus projetos e dashboards.'},
 419:{title:'Sua sessão expirou',description:'Abra o sistema novamente para continuar. Confira o estado das solicitações na conversa ou em Atividade antes de decidir o próximo passo.'},
 429:{title:'Vamos aguardar um instante',description:'Recebemos muitas tentativas em um curto período. Aguarde um pouco antes de tentar novamente.'},
 500:{title:'Não foi possível abrir esta página',description:'Ocorreu uma falha ao carregar o sistema. Tente voltar ao início em alguns instantes.'},
 503:{title:'Serviço temporariamente indisponível',description:'Seu espaço está indisponível no momento. Tente novamente em alguns instantes.'},
};
const message=computed(()=>messages[props.status]??messages[500]);
</script>

<template>
 <main class="recovery-page">
  <Head :title="message.title"/>
  <section class="recovery-card" aria-labelledby="recovery-title">
   <a class="brand recovery-brand" :href="appUrl('/')" aria-label="G-Analise IA — início"><img class="brand-logo" :src="appUrl('/brand/g-analise-icon.png')" alt=""><span class="brand-wordmark"><span class="brand-product">G-Analise <span class="brand-ai">IA</span></span><small>UM PRODUTO INOVADORA</small></span></a>
   <span class="recovery-code">Código {{status}}</span>
   <h1 id="recovery-title">{{message.title}}</h1>
   <p>{{message.description}}</p>
   <a class="primary-button recovery-home" :href="appUrl('/app')"><ArrowLeft :size="18"/>{{status===419?'Abrir o sistema novamente':'Voltar ao início'}}</a>
   <div class="recovery-help"><LifeBuoy :size="18"/><p>Se o problema continuar, informe ao responsável pelo sistema o código acima e o horário da ocorrência.</p></div>
  </section>
 </main>
</template>

<style scoped>
.recovery-page{min-height:100vh;min-height:100dvh;display:grid;place-items:center;padding:32px 20px;background:radial-gradient(ellipse at 50% 0,#eaf1ff 0,transparent 58%),#f7f8fc}.recovery-card{width:100%;max-width:620px;padding:clamp(26px,5vw,48px);border:1px solid #e1e7f0;border-radius:22px;background:#fff;box-shadow:0 18px 64px #1535630b}.recovery-brand{margin-bottom:40px;width:fit-content;color:#27364b}.recovery-brand .brand-product{color:#27364b}.recovery-brand .brand-ai{border-color:#c5d8f4;color:#0861ee}.recovery-brand small{color:#6c85a5}.recovery-code{display:inline-block;padding:5px 10px;border-radius:7px;background:#edf3ff;color:#4f79b4;font-size:11px;font-weight:600;letter-spacing:.03em}.recovery-card h1{font-size:clamp(26px,4vw,34px);line-height:1.22;letter-spacing:-.035em;margin:18px 0 16px;color:#25334b}.recovery-card>p{font-size:14px;line-height:1.8;color:#71829d;margin:0 0 28px}.recovery-home{width:fit-content;justify-content:center;text-decoration:none}.recovery-home:focus-visible,.recovery-brand:focus-visible{outline:3px solid #80b4ff;outline-offset:5px}.recovery-help{display:flex;align-items:flex-start;gap:10px;margin-top:34px;padding-top:22px;border-top:1px solid #edf0f6;color:#8a9db7}.recovery-help svg{flex-shrink:0;margin-top:2px}.recovery-help p{font-size:11px;line-height:1.7;margin:0}@media(max-width:480px){.recovery-page{padding:20px 14px}.recovery-card{border-radius:16px}.recovery-brand{margin-bottom:30px}.recovery-home{width:100%}.recovery-help{margin-top:26px}}
</style>
