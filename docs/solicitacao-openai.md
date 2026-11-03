# Solicitação à OpenAI para a instalação central

Página oficial: [formulário de interesse — Sign in with ChatGPT](https://openai.com/form/sign-in-with-chatgpt-interest/)

Referências: [visão geral](https://developers.openai.com/siwc/token-sharing-open-source) e [cookbook](https://developers.openai.com/cookbook/articles/sign-in-with-chatgpt).

## Situação da solicitação — 03/11/2026

O responsável pelo projeto informou que enviou o formulário de interesse. O envio ainda não representa aprovação; não recebemos uma configuração habilitada para o portal central. A IA funcionando com as contas individuais é um requisito do piloto. Uma instalação com a IA central desligada não deve ser apresentada como entrega funcional desse piloto.

## Dados para apresentar

- Produto: G-Analise IA, da Inovadora. Complemento gratuito e de código aberto ao produto white label G-Analise BI, sem assinatura SaaS da ferramenta de IA.
- Hospedagem desejada: servidor central da Inovadora. Cada usuário conecta suas próprias contas ChatGPT e BI.
- URL prevista: https://suite.inovadora.com.br/ia-ganalise
- Callback desejado: https://suite.inovadora.com.br/ia-ganalise/auth/openai/callback
- Código: MIT; repositório oficial https://github.com/raafaelb/g-analise-ia. Conferir que o código e a execução de CI estejam disponíveis antes de enviar a solicitação.
- Finalidade: criar/manter dashboards por conversa, consultar fontes autorizadas, validar resultados e publicar somente após revisão humana.
- Inferência remota na OpenAI via Codex app-server/Responses. Sem pesos de modelo locais, API key do usuário ou compartilhamento de uma conta de IA.
- Segurança: PKCE/state/nonce, validação OIDC, tokens criptografados, renovação por usuário, ferramentas limitadas por projeto/tenant, revisões vinculadas ao estado do recurso e consentimento específico para imagens do BI.
- Homologação local do fluxo aberto concluída com resposta e ferramentas reais.

## O que solicitar

No formulário, selecionar **Sign in and ChatGPT plan use for AI requests**, porque o projeto precisa de uso do plano, além da identificação da conta. Informar e-mail profissional, nome, empresa, site, função e a descrição do produto acima. Explicar expressamente que a ferramenta é gratuita e MIT, porém será hospedada em um servidor central para vários usuários.

A documentação oficial foi reconferida em 01/11/2026: aplicações hospedadas remotamente precisam solicitar acesso mesmo quando são abertas e gratuitas. O fluxo aberto local já homologado não autoriza, por si só, essa oferta central. A orientação de VM própria trata do mesmo usuário/workspace; não confirma elegibilidade de uma instalação compartilhada entre clientes. As fontes consultadas não estabelecem a compra de ChatGPT Business como requisito para solicitar acesso e não garantem gratuidade do acesso solicitado.

Confirmar elegibilidade da aplicação aberta/gratuita hospedada remotamente e o fluxo oficial para esse cenário; registro/configuração do cliente e callback HTTPS; escopos e autorização de uso do plano individual; regras de armazenamento/renovação/revogação, limites e eventuais requisitos de disponibilização.

A documentação não publica uma garantia de aprovação, prazo ou custo para esse caso. O formulário foi enviado pelo responsável, conforme informado em 03/11/2026; o agente não realizou esse envio. Após a resposta, configurar o modo hosted e repetir OAuth/inferência no domínio real. Não reaproveitar o client_id nativo do Codex nem transportar credenciais do desenvolvedor para todos os usuários.

## Autenticação nativa do Codex

A [documentação do Codex App Server, Auth endpoints](https://learn.chatgpt.com/docs/app-server#auth-endpoints), consultada em 03/11/2026, descreve os fluxos de navegador e código de dispositivo, mas explicita que a autenticação do App Server não é permitida para serviços comerciais ou hospedados. Esses fluxos nativos não devem substituir a habilitação Sign in with ChatGPT do nosso portal central. A existência do método no executável não comprova elegibilidade para oferecer esse serviço. O uso do harness aberto continua separado do acesso ao modelo.
