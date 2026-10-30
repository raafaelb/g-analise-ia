# Evolução do G-Analise IA para central de gestão

Análise de 30/10/2026. O objetivo passa a incluir a gestão cotidiana do BI por usuários não técnicos, além da criação por conversa. O desenvolvimento e a homologação continuam locais; não houve implantação em servidor. O nome exibido é G-Analise, com a marca da Inovadora.

## Cobertura e limites atuais

| Área | Disponível no portal | Evolução possível / ainda necessária |
|---|---|---|
| Conta e tenants | Login individual, renovação de sessão, seleção entre os tenants autorizados, separação por usuário/sessão/tenant | Homologar contas independentes, 2FA e revogação real de permissões |
| Catálogo | 13 tipos Lumo; busca por nome, referência, dashboard e etiqueta; filtros de mesa e situação; paginação | Favoritos e recentes do portal; visões de lista/grade; organização pessoal; categorias adicionais somente após confirmar API própria |
| Minha Mesa | Identificação por `deskId: null`; duplicação com nome confirmado, publicação com destino/efeito explícitos e exclusão apenas de rascunhos registrados pelo portal, com revisão e acompanhamento em Atividade | Renomear outros rascunhos, comparar versões e ampliar a homologação de substituição de versões; estado desconhecido quando o servidor não fornece metadados |
| Mesas de aplicações | Aplicações vinculadas, dashboards e contagens; navegação aos recursos | Criação/configuração de mesas e seleção de destino em formulários acessíveis |
| Mesas de dados | Tabelas, ETLs e cadastros vinculados; contagens e navegação | Gestão de mesas, dependências e permissões conforme contratos oficiais disponíveis |
| Aplicações e dashboards | Estrutura, fontes, PNG real, valores/resumos dos widgets; visualizador nativo isolado no Chrome compatível; seleção de dashboard; painel de prévias no projeto com histórico e acompanhamento de novas versões; criação/manutenção por IA com revisão | Assistente de criação e edição visual; ampliar a homologação de filtros e navegadores; o visualizador nativo continua sujeito às permissões e opções do provedor |
| Indicadores | Expressões e widgets acessíveis no fluxo de autoria; validações e consultas pelo broker | Biblioteca de métricas, explicação de fórmulas, testes de reconciliação e reutilização entre projetos |
| Tabelas | Colunas físicas e metadados de apresentação; amostra limitada; ETL de origem, aplicações dependentes e agendas quando disponíveis | Formulários de rótulos/máscaras/descrições, qualidade dos dados e comparação de schema. Alteração física pertence ao ETL; não editar o overlay como se fosse DDL |
| ETLs | Etapas de autoria, conexões referenciadas, tabelas produzidas, última execução e histórico com estado confirmado | Editor visual, criação por assistente, testes de nós em rascunhos, validação de contexto, acompanhamento ao vivo e comparação antes da publicação |
| Conexões de dados | Lista e tipo, vínculos com ETLs; valores sensíveis protegidos | Teste de conectividade, navegação de schema, criação/edição por formulário privado e transferência de agente com revisão. Nunca incluir senhas em mensagens para IA ou no payload aberto de aprovações |
| Agendas | Atividade, frequência, fuso, fontes, histórico; planejamento e execução revisada; preservação de frequências maiores e deduplicação | Editor completo, seleção guiada de fontes/agente, calendário e acompanhamento de execução. Enfileiramento ou término do agendador, sozinho, não comprova sucesso de todas as cargas |
| Agentes | Configuração disponível, vínculos, saúde/uso de memória/fila, linha do tempo de disponibilidade e ocorrências do tenant | Gestão de configuração, migração e reinício com confirmação específica. Não reiniciar agentes de clientes durante testes de leitura |
| Cadastros | Listagem e autoria quando autorizada; colunas de `fields[]` | Formulários de definição; prévia, exportação XLSX, importação e histórico/rollback de lotes pelos comandos oficiais. Entrada manual cotidiana de linhas ainda depende de API específica. `fields[]` é schema físico: remoções podem apagar dados |
| Decks e apresentações | Listagem e metadados quando disponíveis | Autoria guiada, prévias, validação de widgets vivos e publicação explícita de links/telões; utilizar os gates de verificação do Lumo |
| Investigações | Capacidade consultada e erro de permissão apresentado como tal | Autoria, agenda e ocorrências quando uma conta autorizada estiver disponível. A conta testada não possui acesso; isto não significa ausência de investigações |
| Templates e linhas de produto | Inventário de comandos oficiais confirmado; ainda sem interface própria | Listagem, versões, conteúdo e diff, changelog/documentação, seleção de versão corrente e aplicação por tenant com dry-run e revisão. O CLI não oferece arquivamento de linhas nem movimentação entre linhas; não prometer equivalência nesses pontos |
| Variáveis | Nomes e referências; valores protegidos no navegador e nas ferramentas da IA | Formulário privado de gestão com validação de referências e tratamento de valores sensíveis |
| Usuários, grupos e administração de tenants | Sessão individual e permissões já existentes são respeitadas | O Lumo não substitui provisionamento administrativo. A [API oficial](https://docs.horusbi.com.br/api/) distingue Client Token de Tenant Token; é necessária configuração administrativa específica e escopo adequado. Não reutilizar o JWT do Lumo como se fosse esse token nem elevar os privilégios de um usuário comum |
| Operação central | Docker, HTTPS/subpasta em ensaio isolado, canais privados, filas, saúde, backup/restauração e retenção | Infraestrutura pública, certificado, backup fora do host, monitoramento externo e piloto de capacidade, quando a implantação for autorizada |

Os vínculos usam o inventário autorizado da conta e metadados WORKING do mesmo tenant. Listas indisponíveis têm contagem desconhecida, e não zero. Conexões de ETL são resolvidas pela chave nativa `options.Credential` contra a listagem autorizada, mantendo a chave no servidor. Referências dinâmicas `var-...` permanecem explicitamente incompletas. Como `info` pode devolver apenas um resumo, detalhes de ETL/tabela/agenda/agente também consultam a autoria do recurso. O portal confere o pertencimento à lista do tenant antes de abrir detalhes, inclusive quando o payload nativo não contém cabeçalho de tenant.

Uma prévia sem linhas brutas pode conter `semantic.value`, `total`, `top` e contagens. A interface apresenta esses resultados como indicadores/destaques; não interpreta a ausência de `rows` como ausência de dados nem reconstrói o gráfico completo a partir de um top parcial.

## Visualização interativa e acompanhamento

O comando oficial `lumo app open --session` fornece um link de uso único, válido por dois minutos, para uma sessão de 30 minutos com o mesmo usuário, tenant e permissões. A documentação do próprio CLI alerta que abri-lo no contexto normal do navegador substituiria a sessão BI existente.

O visualizador implementado usa iframe com contexto efêmero `credentialless`, detecção de suporte e alternativa PNG. A [documentação do Chrome](https://developer.chrome.com/blog/iframe-credentialless) descreve o isolamento de cookies e storage por documento superior e origem do frame. Popups são contextos normais; devem permanecer bloqueados. O ensaio sintético em Chrome confirmou isolamento de cookies e storage, persistência dentro do frame e descarte após troca do documento superior; o contexto normal permaneceu intacto. Os marcadores e contêineres temporários foram removidos. O ensaio real no Chrome confirmou três widgets renderizados, maximização de widget e preservação da sessão BI normal do usuário. A troca de tenant e a reconexão/desconexão do portal substituem o documento superior para descartar o contexto efêmero. O temporizador e os contratos de expiração possuem cobertura isolada; o decurso real dos 30 minutos e todos os filtros/navegadores ainda não foram homologados. Os menus nativos continuam visíveis: suas ações obedecem às permissões do BI e não passam pela revisão do broker do portal. Por isso o visualizador é oferecido apenas aos perfis autorizados, com alternativa PNG e origem HTTPS explicitamente permitida. O link temporário não deve ser gravado em histórico, auditoria ou armazenamento persistente do portal.

Prévia durante a criação deve acompanhar checkpoints consistentes do workspace: validação → sincronização autorizada do rascunho → nova imagem/consulta. YAML incompleto ou uma escrita ainda em andamento não pode disparar publicação ou render repetido. O painel de prévia do projeto já acompanha novas exportações concluídas pelos eventos privados, mantém versões anteriores quando o usuário as seleciona e filtra por projeto, aplicação e dashboard. Uma prévia foi gerada e atualizada sem recarregar a página no ensaio real; gerar a imagem não inicia inferência nem autoriza seu envio à IA. O visualizador usa o motor nativo do BI.

## Plano, saldo e consumo de IA

O painel em Conexões e junto ao chat consulta somente `account/read` e `account/rateLimits/read` do [Codex app-server oficial](https://learn.chatgpt.com/docs/app-server), usando processo temporário isolado, sem iniciar uma tarefa de inferência. Quando o provedor fornece esses dados, exibe plano, saldo percentual, janelas e renovação. Há cache de dois minutos, mutex e testes de isolamento entre usuários.

No teste real do fluxo Sign in with ChatGPT OSS, a consulta retornou conta sem plano e nenhum saldo disponível. A interface informa essa ausência e oferece [ChatGPT → Uso](https://chatgpt.com/settings/usage). Não estima saldo a partir de mensagens/tokens locais. A [documentação de contas e sessões](https://developers.openai.com/siwc/token-sharing-open-source/profiles-and-sessions) esclarece que aplicativos compartilham limites do plano; o portal não tem uma franquia independente.

O login e a inferência OSS local já foram homologados. A operação central continua dependendo do enquadramento/configuração oficial para hospedagem, conforme [solicitação preparada](solicitacao-openai.md). Ser gratuito e ter licença aberta não garante automaticamente a elegibilidade remota. Tanto o [fluxo OSS](https://developers.openai.com/siwc/token-sharing-open-source) quanto o [Cookbook oficial](https://developers.openai.com/cookbook/articles/sign-in-with-chatgpt) orientam solicitar acesso para aplicativos hospedados remotamente. A documentação de [VM própria](https://developers.openai.com/siwc/token-sharing-open-source/self-hosted-vms) explica a transferência protegida para um host do mesmo usuário/workspace; ela não comprova a elegibilidade de um serviço central multiusuário. Não há confirmação de preço ou exigência de assinatura comercial para esse caso.

A [referência oficial dos tokens](https://developers.openai.com/siwc/token-sharing-open-source/token-reference) não apresenta nome de plano ou saldo nesses campos e orienta tratar os metadados de autenticação como opacos. As [diretrizes de interface](https://developers.openai.com/siwc/ui-ux-guidelines) orientam mostrar o uso do plano e acesso ao gerenciamento no ChatGPT. A confirmação informativa uma única vez após o primeiro login e um seletor de múltiplas contas são melhorias de onboarding ainda pendentes; não há extração de supostos limites de campos opacos.

## Claude Code

As [regras oficiais da Anthropic](https://code.claude.com/docs/en/legal-and-compliance) distinguem duas situações: oferecer login Claude.ai em um chat próprio/intermediar credenciais de planos de consumo é proibido; hospedar o binário oficial, sem modificá-lo nem restringir seus métodos de autenticação, pode ser permitido nas condições publicadas, com aceite dos termos aplicáveis e cada usuário autenticando sua própria conta. Isso não equivale a uma aprovação automática do fluxo deste portal.

A alternativa a estudar é uma sessão nativa isolada por usuário, com o login realizado pelo próprio Claude Code, sem copiar seus tokens para o banco de conexões do portal, integrada às ferramentas por um broker controlado. O [login da Console](https://code.claude.com/docs/en/cli-reference) pode usar cobrança de API e não deve ser apresentado como uso de uma assinatura Pro/Max. Nenhuma cobrança por API ou assinatura compartilhada será ativada como substituição silenciosa. Ainda não há login Claude homologado nem resposta real desse provedor neste projeto.

## Operações manuais e escolha de projeto

O catálogo permite duplicar uma aplicação sem iniciar uma tarefa de IA: nome → revisão do tenant/origem/destino → autorização → acompanhamento → projeto da cópia. Também permite excluir somente rascunhos criados e registrados pelo portal, com nova revisão e checkpoint local. IDs de solicitação evitam duplicações por reenvio; a execução valida novamente sessão, papel, origem e revisão. Sucesso parcial aparece como pendência, sem repetir a criação automaticamente. Desconectar o ChatGPT preserva uma revisão manual BI; desconectar o BI a cancela. Revisões pendentes ou aprovadas expiram sem afetar operações já em execução.

Na conta real da Inovadora, o CLI não aplicou o nome solicitado no comando de clone. O portal passou a conferir o nome e, apenas na cópia privada recém-registrada, preparar uma alteração restrita a esse campo, validar e sincronizar com a proteção nativa de conflito. A conferência final preserva estrutura, IDs, fórmulas e fontes, tratando separadamente os campos de auditoria das expressões que o servidor atualiza ao salvar. Uma nova cópia confirmou o nome solicitado, sem pendência. A exclusão das quatro cópias descartáveis foi homologada com novas revisões. A listagem nativa voltou a 72 aplicações e confirmou o original preservado. Os quatro projetos vazios de teste foram arquivados, mantendo histórico e prévias. A cópia final também confirmou cobertura diária e execução concluída das fontes existentes, sem novas cargas ou publicações. Recursos preexistentes não são alvos de exclusão.

Na página inicial, **Escolher um projeto** agora abre a seleção de projetos quando a conexão BI já está ativa. O diálogo de conexão é usado quando necessário. Busca, abertura de um projeto e ausência de resultados foram conferidas no navegador. Rascunhos e respostas assíncronas mantêm o contexto de cada projeto.

## Publicação manual e proteção de contexto

O botão **Publicar aplicação** aparece nos rascunhos registrados pelo portal e vinculados ao projeto atual. A mesa é selecionada pelo nome. Publicar como nova usa `--new`; substituir exige identificar a aplicação original autorizada na mesma mesa e uma decisão explícita com esse alvo. O CLI preserva a proteção nativa de conflito. O portal recusa publicação com autoria local ainda não sincronizada ou atualização diária sem comprovação. A conferência posterior identifica o recurso sobrevivente e atualiza o projeto.

A publicação como nova foi autorizada pelo titular no próprio portal e confirmada no BI; uma segunda leitura conferiu o mesmo resultado. Substituição, alvo alterado, permissão insuficiente e aceitação sem confirmação passaram em testes isolados, sem publicação automática. Um resultado remoto incerto não é reenviado automaticamente. Novas tentativas registram o hash do plano e o início do envio; **Conferir publicação**, no histórico, valida a revisão e consulta o destino atual. É possível reconectar o BI antes dessa consulta, mas a sessão deve permanecer a mesma enquanto ela ocorre. A conferência preserva a falha anterior no histórico e não retoma a IA.

Arquivamento e troca de tenant são recusados durante tarefas ou revisões ativas. Depois de vincular um recurso ou iniciar uma conversa, outro tenant exige um novo projeto. A tarefa é reivindicada atomicamente, impedindo que entregas duplicadas reiniciem um trabalho concluído. O runtime Codex usa diretório separado da autoria Lumo e recebe as regras pelas ferramentas controladas. A validação real confirmou o contexto do projeto, sem operações BI ou criação de recursos.

## Atualizar autoria sem perder edições

O projeto oferece **Atualizar do BI** para o recurso vinculado. A IA usa `refresh_resource` pelo mesmo serviço. O diff oficial distingue arquivo ausente, autoria sem modificações e alterações locais pendentes. Somente arquivos ausentes ou sem edições locais são recebidos; o comando nativo continua recusando conflitos, sem `--discard-local` ou sobrescrita forçada na autoria. Após receber, outra leitura confere a versão do servidor; uma mudança posterior aparece como pendência. Revisões/tarefas ativas impedem a atualização manual.

O catálogo usa um workspace exclusivo de cache, sem autoria do usuário, que pode ser reconstruído. Uma falha de atualização não autoriza usar seus arquivos antigos como metadados atuais. Esse problema foi encontrado após a publicação de teste e a correção foi confirmada no navegador.

## Sequência de desenvolvimento

1. Corrigir e homologar inspeção de catálogo, vínculos, dados e estados reais; manter evidências de cobertura e testes de isolamento.
2. Homologar visualização interativa e atualização de prévias durante a criação, com alternativa para navegadores incompatíveis.
3. Acrescentar ações manuais e assistentes sobre o mesmo mecanismo de revisão, conflito, auditoria e execução única já usado pela IA; não exigir uma conversa para operações usuais.
4. Evoluir editores de aplicações/indicadores, ETLs, conexões, tabelas e agendas, preservando limites de publicação e o cuidado com dados de clientes.
5. Completar módulos que dependem de permissões/contratos separados: cadastros, investigações, apresentações e administração de usuários/tenants.
6. Homologar uma integração Claude oficialmente elegível, sem atalhos de OAuth ou uso indevido da assinatura.
7. Repetir a revisão integral, testes automatizados e ciclos reais em recursos descartáveis antes de publicar a próxima versão. A implantação central continua fora desta etapa autorizada.

O catálogo ampliado e o painel de plano não significam que todos os editores e módulos administrativos desta matriz já estejam concluídos. As próximas entregas devem atualizar esta tabela e registrar o que foi demonstrado com conta real, o que passou apenas por testes isolados e o que depende de acesso externo.


## Desempenho e fluidez — melhoria solicitada em 30/10/2026

O usuário relatou lentidão ao abrir informações, páginas e modais do BI. A causa ainda não foi medida; não atribuir automaticamente toda a demora ao provedor. A análise e as otimizações ficam registradas para uma rodada própria, após a entrega de gestão em andamento.

- Medir o tempo percebido no navegador e o tempo de cada etapa do portal: espera de locks, preparação do workspace, processo Lumo, renovação de sessão e resposta remota; comparar cache frio e quente, com percentis e quantidade de consultas por ação.
- Conferir chamadas repetidas, varredura de metadados e reconstrução do catálogo completo ao abrir um único recurso. Avaliar o carregamento de vínculos e configurações técnicas somente quando necessários.
- Revisar cache e compartilhamento de solicitações em andamento por usuário, tenant e geração da sessão, com invalidação após alterações. Não usar cache de outra conta nem dispensar autorização atual para ações e dados sensíveis.
- Revisar atualização de bootstrap, polling e eventos, evitando pedidos sobrepostos. Não paralelizar comandos que disputam a renovação do mesmo token sem preservar os bloqueios e a atualização transacional já implementados.
- Dar retorno visual imediato, manter estados coerentes durante a consulta e descartar respostas de uma seleção antiga. Validar novamente troca de tenant, revogação de acesso, desconexão e concorrência após qualquer otimização.
- Registrar as medições antes/depois e homologar com a conta real em operações de leitura. Esta seção é um backlog; ainda não representa ganho de desempenho comprovado.


## Ações claras por área — melhoria solicitada em 30/10/2026

O usuário solicitou que cada área ofereça um caminho direto e compreensível para sua ação principal, sem exigir comandos ou uma conversa com IA. O exemplo informado é **Adicionar nova conexão** em Conexões de dados. Esta é uma diretriz para todo o produto; a análise das demais áreas fica no backlog e não implica que todos os formulários já existam.

- Mapear a ação de cada área contra o contrato oficial do Lumo/API: criar, editar, testar, duplicar, executar, visualizar e publicar. Separar disponibilidade técnica, permissão do usuário e recursos que exigem integração administrativa adicional.
- Propor botões como Adicionar conexão, Nova aplicação/dashboard, Criar ETL, Nova mesa, Nova agenda e Novo cadastro onde houver suporte confirmado. Explicar a indisponibilidade ou a permissão necessária quando a ação não puder ser oferecida.
- Criar assistentes de poucos passos, com rótulos de negócio, seleção de tenant/agente/fonte e validação dos campos. Os estados vazios também devem orientar a próxima ação.
- Para conexões de dados, confirmar tipos e campos suportados, criação/edição, teste de conectividade, schema e escolha do agente. Senhas, chaves e outros valores sensíveis devem ficar em formulário privado e armazenamento protegido, fora do chat, dos logs e da revisão técnica aberta.
- Reutilizar o mecanismo de revisão, conflitos, auditoria e execução única. Publicação, exclusão e cargas reais precisam apresentar o impacto concreto antes da decisão do usuário.
- Homologar os formulários com recursos descartáveis da Inovadora, sem modificar recursos preexistentes ou ambientes de clientes. Não disponibilizar botões que aparentem executar uma função ainda não implementada.


## Padrão global das barras de rolagem — melhoria solicitada em 30/10/2026

O usuário ampliou o pedido para **todo o sistema**, incluindo menu lateral, páginas, modais, tabelas, painéis e listas. Implementado no CSS global: trilho discreto, indicador arredondado, área nativa de 12 px em navegadores WebKit/Blink e variante `thin` nos demais compatíveis. As cores são claras nas áreas de conteúdo e adaptadas ao fundo azul escuro do menu e da apresentação de login. O indicador ganha contraste ao interagir; não há interceptação de rolagem ou alterações na navegação por teclado. Em modo de cores forçadas, o navegador preserva sua apresentação nativa.

O build Vue/TypeScript passou. A aplicação do estilo foi conferida no Chrome real em página, menu e modal, incluindo as propriedades calculadas do indicador e do trilho. Firefox/Safari e dispositivos de toque ainda precisam de inspeção visual própria. O CSS do portal não altera conteúdo de iframes de outra origem; o visualizador BI depende das opções oficiais do provedor.
