# Solicitação à OpenAI para a instalação central

Página oficial: https://openai.com/pt-BR/form/sign-in-with-chatgpt-interest/

Referências: [visão geral](https://developers.openai.com/siwc/token-sharing-open-source) e [cookbook](https://developers.openai.com/cookbook/articles/sign-in-with-chatgpt).

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

Confirmar elegibilidade da aplicação aberta/gratuita hospedada remotamente e o fluxo oficial para esse cenário; registro/configuração do cliente e callback HTTPS; escopos e autorização de uso do plano individual; regras de armazenamento/renovação/revogação, limites e eventuais requisitos de disponibilização.

A documentação não publica uma garantia de aprovação, prazo ou custo para esse caso. O formulário não foi submetido pelo agente. Após a resposta, configurar o modo hosted e repetir OAuth/inferência no domínio real. Não reaproveitar o client_id nativo do Codex nem transportar credenciais do desenvolvedor para todos os usuários.
