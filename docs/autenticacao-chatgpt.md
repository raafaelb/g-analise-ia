# Viabilidade: ChatGPT, código aberto e servidor central

Decisão registrada em 29/10/2026: produto gratuito, código aberto, hospedado em servidor central da Inovadora. Cada usuário usa suas próprias contas ChatGPT e G-Analise BI. Inferência permanece na OpenAI.

## O que os documentos permitem concluir

A [visão geral oficial](https://developers.openai.com/siwc/token-sharing-open-source) descreve o fluxo aberto de uso do plano para ferramentas de código aberto executadas localmente. A mesma página orienta ferramentas pagas **ou hospedadas remotamente** ao formulário de interesse. Portanto, ser gratuito e aberto não basta para presumir elegibilidade do portal central.

O [cookbook](https://developers.openai.com/cookbook/articles/sign-in-with-chatgpt) demonstra integração local, incluindo cliente Electron. O [fluxo de registro e login](https://developers.openai.com/siwc/token-sharing-open-source/sign-in) usa PKCE, cliente dinâmico e retorno HTTP em 127.0.0.1. Esse endereço aponta para a máquina do navegador. Em um navegador de cliente remoto ele não aponta para o servidor da Inovadora. Trocar o callback por um domínio central não é uma adaptação documentada desse fluxo.

A [orientação para VMs próprias](https://developers.openai.com/siwc/token-sharing-open-source/self-hosted-vms) descreve autorização local e transferência segura de credenciais para o ambiente próprio do usuário. Isso não equivale a uma autorização genérica para um portal central multiusuário. Um aplicativo auxiliar local mudaria o requisito de não configurar nada no computador e exigiria confirmação de elegibilidade do caso hospedado.

O [guia do Codex app-server com SIWC](https://developers.openai.com/siwc/token-sharing-open-source/codex-app-server) confirma que é possível orquestrar o agente localmente, repassar uma autorização válida de uso do plano e fazer inferência remota. Não é necessário GPU. O app-server é uma peça técnica; não concede por si só elegibilidade ao fluxo de autenticação.

## Distinções necessárias

1. Identidade OpenAI: depois de entrar no portal com conta local ou Suíte, verificar qual conta ChatGPT está sendo vinculada ao mesmo usuário. O ChatGPT não é uma opção de entrada no portal.
2. Autorização de uso do plano: consentimento/escopos explicitamente concedidos para inferência. O ID token não é uma credencial de inferência.
3. Orquestração: Codex app-server e ferramentas Lumo, executados pelo worker.
4. Inferência: requisições autorizadas processadas pela OpenAI, sujeitas a limites e modelos da conta.

O projeto separa essas quatro partes. Não pede chave de API, não copia auth.json do Codex do desenvolvedor e não usa endpoint privado ou cliente nativo como substituto de uma integração própria.

## Caminho recomendado para a arquitetura escolhida

- Manter o portal central e os adaptadores implementados.
- Solicitar à OpenAI enquadramento para aplicação aberta/gratuita hospedada remotamente, com descrição de isolamento de contas, callback e armazenamento de tokens.
- Obter configuração oficial e confirmação do escopo de inferência por plano. Um client_id apenas para identidade não encerra essa etapa.
- Homologar OAuth, renovação, revogação, limites, modelos e uma criação completa em tenant de teste.
- Habilitar para clientes após a homologação. A instalação central mantém o login desabilitado até essa configuração. A homologação local já foi concluída.

O modo OSS local é útil para ensaiar o adaptador na instalação elegível; não altera a decisão de manter o modo central desabilitado. As regras do provedor podem mudar e devem ser consultadas novamente na homologação.


## Resultado local

Em 29/10/2026 o titular concluiu o login/consentimento no navegador oficial. O portal validou a identidade, consultou modelos autorizados e recebeu resposta concluída com o plano da conta. Codex executou conversa e ferramentas reais, incluindo criação de rascunho BI e análise de um PNG especificamente autorizado. A inferência ocorreu na OpenAI; não foi usada API key.

O verificador envia input em array, store=false e stream=true, aguarda response.completed e recusa resposta parcial/erro de limite. Não envia parâmetros não suportados pelo preview. O botão Gerenciar uso leva às configurações do ChatGPT; disponibilidade e limites continuam individuais.

Esse resultado comprova a integração **local**. A documentação oficial continua orientando aplicações hospedadas remotamente ao formulário de interesse, mesmo gratuitas e abertas. Não há base documental para afirmar que seja obrigatório comprar uma conta comercial; é necessário obter o enquadramento/configuração apropriados. Veja [o texto preparado para a solicitação](solicitacao-openai.md).
