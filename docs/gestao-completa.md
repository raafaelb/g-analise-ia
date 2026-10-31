# Evolução do G-Analise IA para central de gestão

Análise de 30/10/2026. O objetivo passa a incluir a gestão cotidiana do BI por usuários não técnicos, além da criação por conversa. O desenvolvimento e a homologação continuam locais; não houve implantação em servidor. O nome exibido é G-Analise, com a marca da Inovadora.

## Cobertura e limites atuais

| Área | Disponível no portal | Evolução possível / ainda necessária |
|---|---|---|
| Conta e tenants | Login individual, renovação de sessão, seleção entre os tenants autorizados, separação por usuário/sessão/tenant | Homologar contas independentes, 2FA e revogação real de permissões |
| Catálogo | 13 tipos Lumo; busca por nome, referência, dashboard e etiqueta; filtros de mesa e situação; paginação | Favoritos e recentes do portal; visões de lista/grade; organização pessoal; categorias adicionais somente após confirmar API própria |
| Minha Mesa | Identificação por `deskId: null`; duplicação com nome confirmado, publicação com destino/efeito explícitos e exclusão apenas de rascunhos registrados pelo portal, com revisão e acompanhamento em Atividade | Renomear outros rascunhos, comparar versões e ampliar a homologação de substituição de versões; estado desconhecido quando o servidor não fornece metadados |
| Mesas de aplicações | Aplicações vinculadas, dashboards e contagens; navegação; criação e edição de nome/descrição por formulário com revisão | Gestão de membros, opções avançadas de acesso e exclusão com dependências exigem evolução própria; seleção do destino de publicação já existe |
| Mesas de dados | Tabelas, ETLs e cadastros vinculados; contagens; criação e edição de nome/descrição por formulário com revisão | Gestão de membros, exclusão com dependências e permissões conforme contratos oficiais disponíveis |
| Aplicações e dashboards | Estrutura, fontes, PNG real, valores/resumos dos widgets; visualizador nativo isolado no Chrome compatível; seleção de dashboard; painel ampliável de prévias no projeto, controles recolhíveis, histórico e acompanhamento de novas versões; criação/manutenção por IA com revisão | Assistente de criação e edição visual; ampliar a homologação de filtros e navegadores; o visualizador nativo continua sujeito às permissões e opções do provedor |
| Indicadores | Expressões e widgets acessíveis no fluxo de autoria; validações e consultas pelo broker | Biblioteca de métricas, explicação de fórmulas, testes de reconciliação e reutilização entre projetos |
| Tabelas | Colunas físicas e metadados de apresentação; amostra limitada; ETL de origem, aplicações dependentes e agendas quando disponíveis; formulário de nomes, descrições e formatos numéricos com revisão e conferência posterior | Ampliar a homologação real para tabelas publicadas e contas independentes; qualidade dos dados e comparação de schema. Alteração física pertence ao ETL; não editar o overlay como se fosse DDL |
| ETLs | Etapas de autoria, conexões referenciadas, tabelas produzidas, testes de nós em rascunhos com revisão, última execução e histórico com estado confirmado | Editor visual, criação por assistente, acompanhamento ao vivo e comparação antes da publicação |
| Conexões de dados | Lista/tipo e ETLs vinculados; formulário privado para oito conectores, revisão, criação/edição, escolha de agente na criação, teste de acesso e navegação limitada de tabelas/colunas; entradas protegidas ficam criptografadas e não chegam à IA | Transferência de conexões existentes, substituição de conexões em ETLs, exclusão com dependências e formato avançado de string SQL Server; ampliar testes reais nos demais bancos e estender a busca/paginação de metadados a outros conectores |
| Agendas | Atividade, frequência, fuso, fontes, histórico; planejamento e execução revisada; preservação de frequências maiores e deduplicação | Editor completo, seleção guiada de fontes/agente, calendário e acompanhamento de execução. Enfileiramento ou término do agendador, sozinho, não comprova sucesso de todas as cargas |
| Agentes | Configuração disponível, vínculos, saúde/uso de memória/fila, linha do tempo de disponibilidade e ocorrências do tenant | Gestão de configuração, migração e reinício com confirmação específica. Não reiniciar agentes de clientes durante testes de leitura |
| Cadastros | Listagem e autoria quando autorizada; colunas de `fields[]` | Formulários de definição; prévia, exportação XLSX, importação e histórico/rollback de lotes pelos comandos oficiais. Entrada manual cotidiana de linhas ainda depende de API específica. `fields[]` é schema físico: remoções podem apagar dados |
| Decks e apresentações | Listagem e metadados quando disponíveis | Autoria guiada, prévias, validação de widgets vivos e publicação explícita de links/telões; utilizar os gates de verificação do Lumo |
| Investigações | Capacidade consultada e erro de permissão apresentado como tal | Autoria, agenda e ocorrências quando uma conta autorizada estiver disponível. A conta testada não possui acesso; isto não significa ausência de investigações |
| Templates e linhas de produto | Inventário de comandos oficiais confirmado; ainda sem interface própria | Listagem, versões, conteúdo e diff, changelog/documentação, seleção de versão corrente e aplicação por tenant com dry-run e revisão. O CLI não oferece arquivamento de linhas nem movimentação entre linhas; não prometer equivalência nesses pontos |
| Variáveis | Nomes e referências; valores protegidos no navegador e nas ferramentas da IA | Formulário privado de gestão com validação de referências e tratamento de valores sensíveis |
| Usuários do G-Analise IA | Login pela Suíte, colaboradores administradores, cadastro local, perfis, inativação e senha inicial com troca obrigatória; sessões e tarefas protegidas por revisão de acesso | Ampliar o piloto com contas independentes de clientes; a API atual da Suíte não oferece encerramento global das sessões emitidas pelo portal |
| Usuários, grupos e administração de tenants | Sessão individual e permissões já existentes são respeitadas | O Lumo não substitui provisionamento administrativo. A [API oficial](https://docs.horusbi.com.br/api/) distingue Client Token de Tenant Token; é necessária configuração administrativa específica e escopo adequado. Não reutilizar o JWT do Lumo como se fosse esse token nem elevar os privilégios de um usuário comum |
| Operação central | Docker, HTTPS/subpasta em ensaio isolado, canais privados, filas, saúde, backup/restauração e retenção | Infraestrutura pública, certificado, backup fora do host, monitoramento externo e piloto de capacidade, quando a implantação for autorizada |

Os vínculos usam o inventário autorizado da conta e metadados WORKING do mesmo tenant. Listas indisponíveis têm contagem desconhecida, e não zero. Conexões de ETL são resolvidas pela chave nativa `options.Credential` contra a listagem autorizada, mantendo a chave no servidor. Referências dinâmicas `var-...` permanecem explicitamente incompletas. Como `info` pode devolver apenas um resumo, detalhes de ETL/tabela/agenda/agente também consultam a autoria do recurso. O portal confirma a identidade remota antes de exibir detalhes: para aplicações, o cabeçalho completo de `info --full` precisa coincidir em tenant, tipo e ID; nos demais formatos, confere o pertencimento à lista autorizada do tenant. Uma identidade divergente é recusada, e um cabeçalho incompleto mantém a consulta de pertencimento.

Uma prévia sem linhas brutas pode conter `semantic.value`, `total`, `top` e contagens. A interface apresenta esses resultados como indicadores/destaques; não interpreta a ausência de `rows` como ausência de dados nem reconstrói o gráfico completo a partir de um top parcial.

Painéis personalizados que consultam os dados no navegador são apresentados como **Painel personalizado**. Em **Conferir dados**, a limitação tabular recebe uma explicação em português e atalhos para prévia interativa/visual, respeitando a disponibilidade de cada opção. Avisos internos e comandos do Lumo não são exibidos como dados ou como falha do dashboard. Erros reais permanecem distintos, com mensagem acessível.

## Visualização interativa e acompanhamento

O comando oficial `lumo app open --session` fornece um link de uso único, válido por dois minutos, para uma sessão de 30 minutos com o mesmo usuário, tenant e permissões. A documentação do próprio CLI alerta que abri-lo no contexto normal do navegador substituiria a sessão BI existente.

O visualizador implementado usa iframe com contexto efêmero `credentialless`, detecção de suporte e alternativa PNG. A [documentação do Chrome](https://developer.chrome.com/blog/iframe-credentialless) descreve o isolamento de cookies e storage por documento superior e origem do frame. Popups são contextos normais; devem permanecer bloqueados. O ensaio sintético em Chrome confirmou isolamento de cookies e storage, persistência dentro do frame e descarte após troca do documento superior; o contexto normal permaneceu intacto. Os marcadores e contêineres temporários foram removidos. O ensaio real no Chrome confirmou três widgets renderizados, maximização de widget e preservação da sessão BI normal do usuário. A troca de tenant e a reconexão/desconexão do portal substituem o documento superior para descartar o contexto efêmero. O temporizador e os contratos de expiração possuem cobertura isolada; o decurso real dos 30 minutos e todos os filtros/navegadores ainda não foram homologados. Os menus nativos continuam visíveis: suas ações obedecem às permissões do BI e não passam pela revisão do broker do portal. Por isso o visualizador é oferecido apenas aos perfis autorizados, com alternativa PNG e origem HTTPS explicitamente permitida. O link temporário não deve ser gravado em histórico, auditoria ou armazenamento persistente do portal.

Prévia durante a criação deve acompanhar checkpoints consistentes do workspace: validação → sincronização autorizada do rascunho → nova imagem/consulta. YAML incompleto ou uma escrita ainda em andamento não pode disparar publicação ou render repetido. O painel de prévia acompanha a revisão de uma alteração concluída na aplicação vinculada, pelos eventos privados e pela atualização do estado do projeto, mesmo sem exportar PNG. O histórico de imagens mantém versões anteriores quando o usuário as seleciona e filtra por projeto, aplicação e dashboard. Uma prévia foi gerada e atualizada sem recarregar a página no ensaio real; gerar a imagem não inicia inferência nem autoriza seu envio à IA. O visualizador usa o motor nativo do BI.

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

O usuário relatou lentidão ao abrir informações, páginas e modais do BI. A primeira rodada mediu consultas reais de leitura na Inovadora, separando tempo total e comandos. A abertura repetia uma listagem completa de aplicações e atualização de estado antes da consulta específica. A leitura oficial `info --full` já retorna a identidade remota completa da aplicação; quando confirmada, essa identidade elimina as duas chamadas redundantes. Respostas legadas/incompletas mantêm a listagem de pertencimento. Prévias, histórico/saúde e seleção de projeto não reconstroem mais vínculos de todo o catálogo quando esses vínculos não são usados.

- Medir o tempo percebido no navegador e o tempo de cada etapa do portal: espera de locks, preparação do workspace, processo Lumo, renovação de sessão e resposta remota; comparar cache frio e quente, com percentis e quantidade de consultas por ação.
- Conferir chamadas repetidas, varredura de metadados e reconstrução do catálogo completo ao abrir um único recurso. Avaliar o carregamento de vínculos e configurações técnicas somente quando necessários.
- Revisar cache e compartilhamento de solicitações em andamento por usuário, tenant e geração da sessão, com invalidação após alterações. Não usar cache de outra conta nem dispensar autorização atual para ações e dados sensíveis.
- Revisar atualização de bootstrap, polling e eventos, evitando pedidos sobrepostos. Não paralelizar comandos que disputam a renovação do mesmo token sem preservar os bloqueios e a atualização transacional já implementados.
- Dar retorno visual imediato, manter estados coerentes durante a consulta e descartar respostas de uma seleção antiga. Validar novamente troca de tenant, revogação de acesso, desconexão e concorrência após qualquer otimização.
- Ampliar as medições antes/depois e a homologação de leitura, incluindo percentis com amostras maiores, outras contas e o tempo percebido no navegador. A primeira rodada abaixo comprova apenas o cenário observado; as demais oportunidades permanecem em análise.

Na amostra local de 30/10/2026, com catálogo previamente carregado, os detalhes da aplicação de teste passaram de 5,08/3,99 segundos para 1,46/1,43 segundo. Os comandos remotos por abertura passaram de quatro para dois, mantendo a consulta atual de tenants e `info`. São duas leituras por fase, sem base para estimar percentis ou garantir o mesmo ganho em outras contas. A atualização completa ficou em 16,6/17,9 segundos e não foi acelerada nesta rodada; cache quente levou cerca de 3 ms no serviço. Nenhuma escrita nativa ou inferência foi executada. Testes cobrem identidade divergente, resposta incompleta, recusa atual de permissão e imagem em cache, sem dispensar a conferência de acesso.


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

## Autenticação pela Suíte Inovadora

Contrato: [Autenticação de colaboradores pela Suíte](https://suite.inovadora.com.br/global/wiki/central/autenticacao-colaboradores-suite). A integração usa `POST /api/auth/suite/autenticate/check_login.json`, somente do backend para a URL HTTPS configurada. Não há callback OAuth nem JWT da Suíte nesse contrato. O consumidor envia os headers de credencial documentados e os metadados de aplicação, URL de login, IP validado e identificador da solicitação. Só HTTP 200, `success === true` e identidade válida/coerente permitem uma sessão local. Redirecionamentos, erro de rede, JSON inválido e respostas de erro recusam o login; HTTP 429 respeita `Retry-After`, sem repetição automática.

A identidade é vinculada por emissor + UUID. Uma conta local com o mesmo e-mail pode ser vinculada após verificação positiva; seu login local é então desabilitado, e sessões, tarefas e revisões anteriores são revogadas. Contas vinculadas à Suíte entram pela Suíte, inclusive quando já possuem ChatGPT conectado. Não existe fallback automático para a senha local se a Suíte estiver indisponível. A senha enviada à Suíte não é salva em sessão, banco, auditoria ou revisão. Espaços significativos nas extremidades e quebras de linha são recusados antes do envio, pois headers HTTP não preservam esse formato; o usuário recebe orientação para ajustar sua senha na Suíte.

Colaboradores entram como administradores do portal, preservando um eventual administrador principal existente. Isso não altera tenants, permissões nem credenciais no BI. Usuários locais recebem perfis Administrador, Autor ou Leitura. O formulário não cria administradores principais nem permite que o administrador inative/rebaixe o próprio acesso; administradores comuns não modificam o administrador principal. Identidades da Suíte não têm nome, e-mail, perfil ou senha alterados pelo formulário local.

A senha inicial local permite somente sua troca obrigatória. Atualizações de acesso usam versão do cadastro para recusar sobrescritas antigas. Inativação, alteração de perfil/e-mail e redefinição de senha revogam sessões e revisões pendentes; tarefas em andamento recebem cancelamento cooperativo. Entradas futuras no Lumo/broker e novas assinaturas privadas verificam o acesso atual. As revisões carregam uma assinatura vinculada ao usuário, ao ID da revisão e à versão do acesso; reativar a pessoa não restaura uma autorização antiga. A troca pessoal de senha também cancela tarefas/revisões anteriores e mantém somente a sessão que concluiu a troca. Operações já recebidas pelo BI não são desfeitas. A inativação central na Suíte bloqueia novos logins; a API documentada não revoga as sessões locais já emitidas, que seguem o prazo local ou a inativação no portal.

## Conferência de conexões

Os conectores do formulário são PostgreSQL, MySQL, SQL Server estruturado, Oracle, Firebird, BigQuery, Informix e ODBC. A revisão é vinculada ao usuário, sessão BI, tenant, recurso, tipo, agente e configuração; os campos privados ficam no cache criptografado por prazo curto, fora do payload público de aprovação. Senhas em branco na edição preservam o valor anterior nos conectores que permitem omissão. A revisão expirada/rejeitada, a desconexão e o término da execução removem o conteúdo temporário. Chaves BigQuery e strings ODBC continuam sendo campos protegidos.

A criação nativa primeiro gera um recurso vazio. Sua referência é registrada antes das demais etapas; uma falha posterior apresenta o recurso criado e não repete a criação. Se o agente inicial difere do autorizado, a nova conexão é vinculada pelo comando oficial e lida novamente. A validação offline recebe somente o arquivo selecionado: um workspace inicializado com inventário remoto pode conter centenas de recursos não materializados que não pertencem à operação. A configuração salva é conferida novamente no servidor. Isso não comprova conectividade: o botão **Testar conexão** exige confirmação explícita do agente.

O teste real de PostgreSQL confirmou conectividade e leitura de estrutura. A resposta nativa de schema retornou erro de UNION nessa fonte; para esse erro específico em PostgreSQL, o portal usa consulta fixa e somente de leitura a `information_schema`, com validação do schema e limites explícitos. Resultado vazio ou formato não reconhecido permanece sem confirmação, sem presumir um banco vazio. Consultas grandes são sinalizadas como limitadas; a paginação completa ainda é uma evolução pendente. Os demais conectores têm testes de contrato com dados sintéticos, sem declarar conexão real a todos esses bancos.


No modo OSS local, as páginas de entrada e workspace são normalizadas para a origem de loopback do callback configurado. Iniciar o vínculo do ChatGPT a partir de um alias abre primeiro Conexões na origem correta, evitando iniciar OAuth na sessão errada. A mudança não afeta APIs, POSTs, callbacks, instalações hospedadas ou domínios externos, e não copia cookies entre hosts. A validação de `state`, PKCE e identidade continua obrigatória.


O login real da Suíte foi concluído pelo titular em 30/10/2026 e confirmado no cadastro e na auditoria com perfil de administrador. A conta de um novo colaborador tem workspace próprio; projetos e conexões de demonstração não são copiados nem compartilhados automaticamente.

A tela de entrada contém somente conta local e Suíte. O vínculo OAuth do ChatGPT exige uma sessão autenticada e uma transação vinculada ao mesmo usuário; um retorno anônimo ou de versão revogada não cria nem troca a sessão do portal. O botão de conexão permanece em Conexões.


## Revisões diretamente na conversa — 30/10/2026

A solicitação agora é exibida no fluxo da conversa como um cartão destacado, com a proposta existente e botões **Autorizar** e **Recusar**. O aviso junto ao campo de mensagem permanece visível e leva ao cartão sem trocar de página. Cada revisão mostra seu estado: aguardando decisão, autorizada, em execução, concluída, recusada, expirada, cancelada ou com falha. Autorizar não é apresentado como comprovação de sucesso.

A consulta inclui apenas revisões do usuário, projeto e conversa selecionados, inclusive as mais antigas que a janela de dez runs recentes. Novas respostas da IA identificam o run da mensagem; revisões antigas usam a mensagem de entrada e, quando não há uma âncora conhecida, continuam disponíveis no fim da conversa. O retorno de uma seleção antiga é descartado. A atualização em segundo plano não força a rolagem durante a leitura da proposta.

Os controles bloqueiam envio duplo e decisões expiradas/incompletas. Uma resposta de rede incerta exige nova leitura antes de oferecer outra tentativa; não há repetição automática de autorização. A decisão continua usando o endpoint existente, propriedade, fingerprint, prazo, versão de acesso, auditoria e execução única. A página Atividade permanece disponível como histórico.

Foram conferidos os cartões em desktop (1.440 × 1.000) e celular (390 × 844), a troca de conversa, a recusa e o registro de autorização no próprio chat, sem navegação para Atividade. O teste de interface usou somente dados sintéticos e uma conta sem conexões BI/IA; a execução aprovada parou nesse bloqueio e foi mostrada como falha, sem reivindicar alteração no BI. A conta foi inativada e o projeto de interface arquivado depois da conferência.


## Formulários de mesas — 30/10/2026

As áreas Mesas de aplicações e Mesas de dados oferecem **Nova mesa**. Nos detalhes, **Editar nome e descrição** carrega a configuração atual. A revisão mostra tenant, tipo, valores atuais/propostos e impacto. Autorizar/Recusar e o acompanhamento permanecem no formulário; não há inferência de IA. A criação produz uma mesa vazia, sem publicar aplicações ou fontes. O formulário não muda membros nem opções avançadas de acesso.

A execução compara a versão atual, a sessão BI e o acesso local com a revisão, usa o conflito nativo do Lumo sem sobrescrita forçada e confirma nome/descrição por nova leitura. A autoria acontece em diretório descartável próprio, preservando os demais campos do documento. A tentativa e a referência criada são registradas antes de continuar a configuração. Uma falha parcial mantém esse ID disponível para conferência e não repete a criação. A recusa e a expiração não autorizam escrita.

Criação e edição dos dois tipos foram conferidas no tenant Inovadora pelo formulário, incluindo a edição móvel. As mesas vazias descartáveis foram removidas sem cascata; os IDs preexistentes permaneceram presentes. Não houve publicação, carga de dados, alteração em Novo Hamburgo ou tarefa de IA. O formulário foi inspecionado a 390 × 844 pixels, sem transbordamento horizontal; o viewport foi restaurado. Membros/permissões avançadas e exclusão cotidiana ainda não possuem interface neste módulo.


## Fila de análise: API, MCP e recursos de IA — solicitação de 30/10/2026

O usuário enviou as referências abaixo e solicitou apenas seu registro na fila de melhorias, sem iniciar esta nova análise agora. Esta seção não declara contratos lidos, compatibilidade validada ou integração MCP já implementada.

- [API oficial](https://docs.horusbi.com.br/api/): inventariar recursos, operações, autenticação, escopos e permissões; comparar com o Lumo e o portal e priorizar lacunas importantes de gestão. Conferir especialmente usuários/grupos/tenants, aplicações, mesas, conexões, ETLs, tabelas, agendas e publicação, conforme o que a documentação efetivamente oferecer.
- [MCP oficial](https://docs.horusbi.com.br/ia/mcp): avaliar a integração para consultas de dados, ferramentas semânticas e descoberta de recursos; confirmar transporte, autenticação e limitações. Projetar seleção de tenant, respeito aos acessos individuais, isolamento entre contas, controle de envio de dados à IA, erros e rastreabilidade. Não presumir que as credenciais do Lumo possam ser reutilizadas como tokens de outra integração.
- [Recursos de IA](https://docs.horusbi.com.br/ia/): mapear capacidades adicionais e orientações oficiais, identificar sobreposição com os recursos existentes e propor melhorias de consulta e gestão para usuários não técnicos.

A entrega dessa análise deve distinguir disponibilidade documental, capacidade implementada e homologação real, com prioridades e dependências. Nenhum token administrativo, conector MCP ou permissão adicional será ativado apenas pelo registro desses links.


## Correções de investigação e prévia no chat — 30/10/2026

O teste de manutenção mostrou uma lacuna do conjunto de ferramentas: a IA conseguia criar um fluxo, mas não executar uma etapa nem consultar a estrutura de uma conexão no chat. A ferramenta de prévia de aplicação também estava sendo usada para um fluxo. Foram acrescentados consulta de estrutura sem senhas, busca de tabelas/colunas e paginação PostgreSQL, teste de etapa ETL com revisão e leitura de resultado já executado. Resultados nulos de prévia não são tratados como banco vazio. A execução só é confirmada quando o comando oficial retorna o estado final da etapa; tabela e valores precisam de conferência adicional.

A consulta geral pode ser extensa. A IA recebe uma lista resumida de tabelas e pode pesquisar o assunto solicitado, sem pedir SQL ou nomes técnicos ao usuário. A busca refinada e a paginação estão homologadas para PostgreSQL; outros conectores mantêm a introspecção geral e informam quando a busca refinada ainda não é suportada.

O acompanhamento do projeto oferece a prévia interativa, escolha de dashboard, imagem opcional e link oficial sem credenciais. Links históricos de imagem na conversa são tratados pelo portal: os do recurso atual abrem a prévia, e imagens de recursos anteriores permanecem identificadas como histórico. O ticket temporário do visualizador é usado apenas no iframe isolado, nunca enviado para a IA como URL comum. A cópia de manutenção passa a ser o recurso de trabalho do projeto, preservando a aplicação publicada.

A validação de autoria passa a tratar somente o YAML do recurso escolhido e devolver os erros ao agente. Um arquivo inválido deve ser corrigido antes de preparar uma revisão de sincronização. Falhas de validação após uma aprovação podem retomar a IA para corrigir o arquivo, com limite de tentativas e nova revisão obrigatória; isso não autoriza repetir operações de resultado incerto.

Os links novos de prévia usam o caminho do portal, preservando o host e a sessão atual do navegador. Links antigos entre localhost e 127.0.0.1 também abrem o painel da mesma conversa, somente no mesmo protocolo/porta e para o próprio projeto. Outros hosts/projetos não são interceptados; o subdiretório da Suíte é preservado.

As consultas de contexto da prévia agora acompanham mudanças reais de projeto/recurso, em vez de reiniciar a cada atualização do chat. A disputa pela sessão BI é distinguida de uma operação já iniciada: etapas simples ainda não iniciadas podem aguardar com a mesma autorização; operações de resultado incerto não são repetidas. Cancelamentos continuam sendo respeitados. A execução longa de ETL mantém o sinal de atividade para não ser interrompida pelo monitor da fila.

A homologação real confirmou descoberta da estrutura da demonstração, extração e transformação, criação/carga de tabela privada, correção de YAML pela própria IA e inclusão do mapa em uma cópia privada. A prévia interativa foi aberta no portal; selecionar e limpar um responsável atualizou mapa e indicador. O componente de mapa nativo usa pins de cor fixa, com identificação e filtro por responsável. A exportação PNG falhou no renderizador nativo e permanece uma limitação a investigar; ela não é requisito para abrir o visualizador interativo. A fonte nova permanece privada, sem agenda diária ativa: sua publicação e o destino de dados ainda precisam de autorização para concluir essa cobertura. A aplicação publicada e os demais recursos preexistentes foram preservados.

A regressão passou em SQLite e PostgreSQL (453 testes em cada banco, 2.542 asserções), mais 41 testes de interface e compilação com verificação TypeScript. Esses resultados cobrem esta revisão e não declaram concluídas as integrações ainda listadas como pendentes.

### Área útil da prévia — implementada em 30/10/2026

No computador, conversa e prévia usam 50% da largura cada. O espaço vertical livre é ocupado pelo dashboard, sem limite máximo artificial de altura. Modos e escolha de dashboard ficam na mesma barra; acompanhamento, atualização da lista e geração de imagem ficam em Opções. **Ampliar** usa toda a área de trabalho e **Ver conversa** restaura a disposição lado a lado. A expansão preserva o mesmo iframe e a sessão BI. A prévia interativa não tem rolagem externa concorrendo com a do próprio BI; o histórico de imagens conserva sua rolagem.

Na conferência a 1.440 × 1.000, com menu fixo aberto, a área visível passou de aproximadamente 519 × 518 para 593 × 712 pixels, preservando a divisão 50/50. Com o menu recolhido, a área útil medida é de 683 × 712 pixels. A expansão chegou a 1.187 pixels de largura útil. No celular (390 × 844), o painel ocupa a área de trabalho, com **Conversa** para retornar ao chat; a área útil medida foi de 390 × 521 pixels. A sessão temporária e o acesso ao BI permanecem disponíveis. Os menus internos do provedor continuam nativos; nenhuma ocultação por API não confirmada foi introduzida.

### Menu recolhível — implementado em 30/10/2026

O usuário pode recolher o menu para uma faixa de ícones de 72 pixels ou fixá-lo aberto. Ao passar o mouse sobre a faixa, o menu abre com sombra por cima do conteúdo, sem deslocar a página. A largura aberta é de 252 pixels, ou 226 em janelas intermediárias. O foco visível do teclado também o abre; Escape retorna ao controle do menu. Um clique de mouse não mantém a abertura temporária depois de sair da área.

A preferência fica no próprio navegador, separada por usuário do portal, e é mantida na navegação e na recarga. Os itens têm nomes acessíveis mesmo quando só o ícone aparece. A rolagem dos itens é independente do rodapé para preservar acesso à conta e à ajuda. No celular permanece a abertura por botão sobre o conteúdo, com fechamento pelo fundo; a preferência de computador não cria uma margem vazia no celular. Foram conferidos mouse, teclado, persistência, navegação e layouts de 1.440 × 1.000, 1.024 × 768 e 390 × 844, além da compilação Vue/TypeScript e dos 41 testes de interface.


## Boas-vindas, tours por página e tira-dúvidas — 30/10/2026

Implementados com [Driver.js 1.10.2](https://driverjs.com/docs/configuration), de licença MIT; o aviso de licença acompanha os assets em `public/vendor/driverjs-LICENSE.txt`. A fonte de conteúdo é `resources/help/product-guide.json`: a mesma versão abastece os tours, os guias locais e as orientações enviadas à IA. A regra de atualização obrigatória está em `AGENTS.md`.

### Boas-vindas e persistência

A primeira entrada apresenta o G-Analise IA, suas principais funcionalidades e o botão **Conhecer esta página**, depois da troca inicial obrigatória de senha. **Explorar sozinho** ou fechar permite continuar sem completar o tour.

`user_guide_progress` tem unicidade por usuário/tipo de página. Registra início, primeira exibição efetiva, fechamento, conclusão, versão, instância e repetições manuais. A reserva do início automático é atômica para evitar duplicação entre abas. Fechar ou concluir não dispara novo tour automático; abrir outro projeto usa a mesma chave `project`. Atualizar o conteúdo preserva a primeira versão e não reinicia tours já apresentados. Respostas tardias de uma instância antiga não substituem o progresso da instância atual.

**Ajuda**, no topo, e **Ajuda e primeiros passos**, no menu, oferecem **Refazer tour** e **Rever boas-vindas**. O tour espera janelas, tarefas, rascunhos de mensagem e a consulta do catálogo para apresentar seus controles já carregados; considera perfil e controles visíveis e usa âncoras `data-tour` do portal. A apresentação tem teclado, fechamento acessível, foco contido nos diálogos e redução de movimento. Não depende de ler o DOM externo do BI.

### Páginas cobertas

| Página | Orientações principais |
|---|---|
| Criar com IA | Objetivo em linguagem de negócio, conexões, escolha do projeto e ajuda |
| Meus projetos | Projeto versus conversa/dashboard, localizar e organizar o trabalho |
| Conversa do projeto | Pedidos, configuração do projeto, revisão na conversa e prévia disponível |
| Catálogo BI | Tenant, tipos de recursos, busca, consultas e ações disponíveis |
| Conexões | Contas ChatGPT/BI, limites quando fornecidos e consulta MCP autorizada separadamente |
| Atualização de dados | Cobertura, agendas, frequência e confirmação do resultado da carga |
| Atividade | Histórico e diferença entre revisão, execução e confirmação |
| Segurança da conta | Ações conforme a origem da conta e gestão pela Suíte |
| Usuários e acessos | Cadastro local, perfis e inativação, somente para administradores |

Passos de controles ainda fechados, indisponíveis, desabilitados ou fora do menu móvel visível são omitidos. Os guias explicam também dispositivos da prévia, expansão, acompanhamento, anexos, conexões de dados, mesas e edição de apresentação de tabelas.

### Guias e atendimento com IA

**Guias e respostas** oferece 18 artigos pesquisáveis sem exigir conexão de IA, incluindo “Para que servem os projetos?”, “Onde encontro minhas dashboards?” e “Como crio um dashboard financeiro?”. Os artigos e seus botões são filtrados pelo perfil; a busca prioriza a dúvida e o contexto da página.

**Conversar com IA** usa a autorização do plano ChatGPT do próprio usuário pelo endpoint público Responses. A chamada seleciona um modelo visível no catálogo autorizado da conta, usa `store:false`, `stream:true`, `tools:[]` e `tool_choice:none`; a inferência permanece na OpenAI. A implementação não pede API key. A elegibilidade para hospedagem central continua dependente da configuração oficial; o módulo respeita a mesma configuração da instalação. Consulte [o contrato oficial de modelos e inferência](https://developers.openai.com/siwc/token-sharing-open-source/models-and-inference).

São enviados a pergunta, a página, o perfil, as orientações da versão e um histórico limitado do próprio atendimento na mesma autorização e versão de acesso. O módulo não carrega dados BI, projetos, anexos ou credenciais no contexto e não possui ferramentas de execução. Uma pergunta de suporte não cria ETL, aplicação, publicação ou revisão. A resposta orienta o caminho correto e a interface oferece guias relacionados e **Abrir Criar com IA**, mantendo as revisões normais desse fluxo. Links e imagens arbitrários do modelo não são renderizados como ações.

`help_questions` mantém histórico pessoal separado das conversas dos projetos. A interface mostra texto progressivo, interrupção, falha, conclusão, cópia e limpeza do histórico. Texto parcial não é apresentado como resposta concluída. Só o evento de conclusão confirmado permite finalizar; o leitor aceita o texto já recebido quando o gateway não repete a cópia no último evento. Campos e conteúdo de erro do provedor não são expostos ao usuário.

Uma identificação idempotente evita duplicar uma pergunta ao repetir uma requisição. Há uma pergunta pendente por usuário; cancelamento, limpeza, troca de acesso/senha ou desconexão ChatGPT impedem gravar respostas tardias. A autorização é vinculada à própria conexão e sua geração; reconectar não reaproveita o histórico de outra autorização. Desconectar apenas o BI não encerra orientações do produto que não usam esse acesso. As requisições do workspace identificam a conta exibida: uma aba antiga não usa a sessão de outra conta que entrou no mesmo navegador e não encerra a sessão nova. O histórico permanece até a limpeza pelo titular ou política adicional da instalação; a limpeza da ajuda não apaga projetos ou conversas de trabalho.

A fila `help`, em worker próprio, evita aguardar tarefas longas de autoria. Não há repetição automática de inferência após falha. Perguntas enfileiradas sem início por dez minutos e execuções sem heartbeat por quatro minutos expiram, inclusive pela manutenção periódica. O monitoramento inclui fila e heartbeat da ajuda; o backup recusa respostas ativas. A restauração isolada desabilita integrações e invalida atendimentos pendentes.

A validação automatizada cobre persistência, perfis, primeira senha, instâncias antigas, repetição manual, versão de conteúdo, isolamento de histórico, idempotência, erros e formatos do stream, bloqueio de ferramentas, cancelamento e revogação. A homologação no navegador inclui uma resposta real sobre projetos e a orientação de um pedido de criação para o fluxo correto, sem operar o BI. Os 624 testes de backend passaram em SQLite e PostgreSQL isolado, e os 66 testes de interface passaram. A abertura dos tours foi conferida nas nove áreas; a ajuda foi conferida em 390 × 667, 390 × 844, 768 × 1024 e 1440 × 1000, com foco inicial e retorno do foco/rolagem ao fechar. O histórico abre na última resposta, a conversa ocupa a altura disponível e a criação solicitada na ajuda recebeu orientação sem gerar tarefas BI. A restauração criptografada foi comprovada em banco isolado com dez registros de progresso e sete atendimentos de homologação, com integrações externas desabilitadas e o banco em uso preservado. Os registros descartáveis foram depois removidos pela interface da ajuda e por uma limpeza restrita aos IDs dos tours de teste, preservando o primeiro acesso real. A automação de teclas físicas não estava disponível no navegador conectado; o tratamento de Tab/Escape foi revisado no código, sem declarar esse ensaio nativo executado.


## Prévia por dispositivo e compatibilidade de incorporação — 30/10/2026

Os botões da prévia oferecem **Computador (1280 × 800)**, **Tablet (768 × 1024)** e **Celular (390 × 844)**, além de **Ajustar à área disponível**, que usa a largura e toda a altura livre. O iframe recebe as dimensões lógicas do dispositivo; somente seu enquadramento é reduzido para caber no painel. Assim, o layout responsivo é calculado para o tamanho selecionado, em vez de apenas encolher o desenho do computador. Trata-se de uma prévia de tamanho/layout, sem emulação de sistema operacional ou navegador.

A troca de dispositivo preserva o iframe e sua sessão, sem usar novamente o ticket de autenticação. A seleção do dispositivo fica no painel do projeto e é mantida quando o visualizador é recriado para uma nova prévia. A validação real conferiu os três tamanhos e a interação com um filtro: a seleção foi preservada ao trocar de computador para celular, e a limpeza restaurou o total anterior. As dimensões e o enquadramento também possuem testes isolados; são 47 testes de interface nesta revisão.

Impacto no tour e tira-dúvidas: ensinar o ajuste automático, os três botões de dispositivo, a expansão e o retorno à conversa. As âncoras `chat-preview`, `chat-preview-devices`, `chat-preview-expand` e `chat-preview-options` identificam esses controles sem depender de posições ou nomes de projetos. Esses controles alimentam os tours e os guias descritos acima; os passos usam somente âncoras presentes e visíveis.

### Correção de perda de sessão durante redimensionamento

O usuário relatou rolagem intermitente ao alternar celular, tablet e computador. A análise reproduziu uma falha concreta: reduzir temporariamente a altura útil do painel a zero removia o iframe; restaurar a área criava outro iframe com o mesmo link de login, já consumido. O BI exibia “Este link expirou ou já foi usado”.

O visualizador agora espera uma primeira medição válida para abrir o dashboard e mantém o iframe autenticado com a última geometria válida durante reduções temporárias a zero. Recarregar deliberadamente continua solicitando uma nova sessão. As camadas de enquadramento usam `overflow: clip`, sem criar áreas extras de rolagem; a rolagem do conteúdo permanece no próprio BI. Nenhuma recarga automática por troca de dispositivo foi introduzida.

A conferência no Chrome reproduziu a falha antes da correção e repetiu o mesmo ciclo depois dela: com altura útil zero, o iframe permaneceu presente; ao restaurar o painel, o dashboard estava autenticado com a mesma sessão. Um filtro ativo também foi preservado ao trocar de dispositivo e reduzir/restaurar a altura; a limpeza restaurou o resultado completo. A rolagem de celular, tablet e computador foi conferida por entrada de roda do mouse e deslocamento real do documento. Esta correção não declara eliminadas todas as possíveis falhas de rolagem do conteúdo externo. A ocorrência original permanece no acompanhamento de compatibilidade para novos testes em outros dashboards, Safari/Firefox e dispositivos de toque.

Impacto no tour e tira-dúvidas: a troca de dispositivo ajusta o layout sem exigir recarga nem perder os filtros. Explicar a diferença entre ajuste de tamanho e **Recarregar**, que inicia uma nova sessão. Manter **Abrir no BI** como alternativa para explorar a aplicação completa.

### Mostrar somente o dashboard: dependência ainda aberta

A [documentação de login transparente](https://docs.horusbi.com.br/whitelabel/embed/login) descreve `showMenu=0` e `showDesksIcon=0`. A [visão geral de incorporação](https://docs.horusbi.com.br/whitelabel/embed/) vincula esse fluxo ao login SSO emitido pela API com Tenant Token administrativo. Esse token não é o token da sessão individual do Lumo, e a integração não deve assumir que sejam intercambiáveis.

A homologação com Lumo 3.44 e a sessão temporária atual mostrou que acrescentar essas opções ao link `/auth/cli/...` não removeu a navegação. Carregar a rota do app diretamente depois desse login mostrou a página de entrada, sem preservar aquela sessão. A tentativa foi retirada e o visualizador autenticado original foi restaurado e conferido. A seleção de dispositivos e a divisão 50/50 funcionam com esse visualizador; o menu e o cabeçalho nativos ainda permanecem visíveis.

Para concluir o modo que mostra somente o dashboard, será necessário integrar e homologar o SSO de incorporação com configuração administrativa autorizada e vínculo seguro ao usuário/tenant, ou obter suporte explícito de incorporação no fluxo do Lumo. A navegação completa continua acessível em **Abrir no BI**. A visualização incorporada deve manter as permissões individuais e o isolamento da sessão; a preferência de exibição não pode ser apresentada como uma restrição de autorização.


## Acompanhamento de alterações e revisão a partir da prévia — 30/10/2026

### Alteração confirmada sem depender de PNG

O projeto registra uma revisão de prévia quando a sincronização do app termina, quando uma publicação é confirmada ou quando sua cópia é concluída. A gravação acompanha a conclusão da autorização na mesma transação. O vínculo inclui o usuário, o projeto, o tenant e a aplicação atual. Outra aplicação, uma etapa de ETL, uma carga apenas enfileirada, uma revisão pendente ou uma execução com falha não sinalizam uma alteração concluída na configuração do dashboard. A conferência posterior de uma publicação inicialmente incerta também atualiza a revisão, sem reenviar a publicação. Repetir a conferência já concluída não inicia uma atualização nova.

A interface observa essa revisão estável, em vez da identidade dos objetos recebidos a cada consulta. Quando **Acompanhar alterações** está ativo, atualiza a lista de dashboards e abre a aplicação atual com uma sessão temporária nova. Novas imagens não provocam outra reconexão para a mesma revisão confirmada. Projetos antigos sem revisão mantêm compatibilidade com o acompanhamento de exportações. O modo **Imagem** e uma escolha histórica não são substituídos por essa atualização interativa.

Pausar o acompanhamento mantém a visualização atual; a indicação **Pausado** fica nas opções. Ao reativá-lo, uma alteração ainda não visualizada é carregada. Se a prévia estiver temporariamente oculta para analisar uma autorização, aguarda o retorno ao painel antes de abrir a nova sessão. O visualizador responsivo só pede um ticket quando está ativo e possui área medida; a redução temporária a zero mantém um iframe já autenticado. Um ticket devolvido durante a ocultação inicial não é guardado para ser aberto depois de expirar.

A configuração do app e a atualização dos dados têm confirmações próprias: o sucesso de um pedido de agenda continua sem comprovar a conclusão da carga. A homologação desta revisão não criou fontes nem novas agendas e não altera a regra de cobertura diária.

### Autorização visível durante a visualização

Quando há uma revisão pendente na conversa atual, a prévia mostra um aviso destacado e **Revisar na conversa**, inclusive no painel ampliado e no celular. O botão apresenta o cartão existente no próprio projeto e o recebe com foco de teclado, sem navegar para Atividade. O iframe continua conectado, temporariamente oculto, para preservar a sessão e os filtros. **Voltar à prévia** restaura o painel. Não há uma segunda cópia dos botões de decisão nem um novo caminho de execução: autorização, fingerprint, expiração, permissões e estados permanecem no fluxo existente.

### Ordem das mensagens e tarefas

A homologação encontrou mensagens gravadas no mesmo segundo fora de ordem no PostgreSQL: a resposta final estava salva, mas aparecia antes do aviso de execução. As consultas da conversa agora usam horário e ID como desempate, preservando a ordem dos identificadores de criação. A seleção das tarefas mais recentes também tem desempate explícito, tanto na conversa quanto no bootstrap, para não ocultar uma tarefa nova entre registros do mesmo segundo. Dois testes reproduziram a falha anterior antes da correção.

### Evidência e limites

A homologação real usou uma cópia privada e identificada no tenant Inovadora. Três trocas restritas de texto passaram por revisão: uma atualizou a prévia automaticamente; outra aguardou o retorno do painel oculto, sem emitir um novo ticket durante a ocultação; a terceira preservou a visualização pausada e carregou a versão atual ao retomar. As três alterações foram vistas no renderizador nativo. Não foram gerados PNGs, publicações, ETLs ou cargas nessa conversa. Os registros das seis tarefas de IA e das três alterações ficaram concluídos. Ao final, a cópia descartável foi excluída por revisão e seu projeto foi arquivado, com histórico preservado. A fonte compartilhada manteve sua agenda diária ativa às 05h de Brasília, carga concluída e teste confirmado, sem duplicar agendas. O aviso e o retorno à revisão foram conferidos em computador e tamanho de celular, com foco no cartão e preservação da mesma sessão antes da execução.

A abertura inicial com altura útil zero permaneceu sem iframe e sem emitir sessão; depois de restaurar a área, a visualização pôde iniciar normalmente. A perda de sessão no redimensionamento de um iframe existente continua coberta pela verificação registrada na seção anterior. Safari, Firefox, toque real e outros tipos de dashboard ainda precisam de homologação própria.

Impacto no tour e tira-dúvidas: acrescentar o aviso de autorização na prévia, **Revisar na conversa**, **Voltar à prévia**, o significado de **Pausado** e como retomar o acompanhamento. As âncoras `chat-preview-review`, `chat-preview-toggle`, `chat-preview-options` e `chat-preview-follow` permitem orientar esses passos. A ajuda deve explicar que uma pergunta de suporte não autoriza alterações e que a revisão só termina com um resultado de execução conferido. Essas orientações fazem parte dos guias do projeto e de revisões; os passos dependem dos controles visíveis.

Validação desta revisão: 465 testes de backend em SQLite e em PostgreSQL, com 2.624 asserções em cada execução, mais 55 testes de interface e compilação com TypeScript. A evidência local e os resultados do CI devem ser conferidos no commit correspondente.


## Consultas MCP individuais — implementação e homologação local em 30/10/2026

A página **Conexões** inclui **Consultas de dados com IA**. A pessoa escolhe o ambiente e uma aplicação para conferir seu acesso, continua para a página oficial do BI e autoriza somente leitura. A aplicação não é alterada. Cada usuário pode manter uma autorização separada por ambiente; **Conferir acesso** valida a conexão e **Desconectar** encerra seu uso no portal. A permissão nativa **Conectar via MCP** continua sendo aplicada pelo provedor.

A integração utiliza descoberta OAuth do domínio configurado, registro dinâmico de cliente público quando necessário, PKCE S256, estado de uso único vinculado à sessão do navegador e escopos mcp:read e offline_access. O retorno usa HTTPS; apenas a instalação local aceita loopback HTTP. Os endpoints ficam restritos à origem HTTPS configurada e não seguem redirecionamentos. Credenciais são cifradas no banco, nunca enviadas ao navegador, à conversa ou à OpenAI. A renovação é serializada por usuário/ambiente e não sobrescreve um grant substituído. Estado antigo, reconexão do Lumo, mudança de acesso, alteração de aplicação do projeto ou desconexão descartam resultados que ficaram em andamento.

A autorização MCP é própria. O provedor verifica as permissões da conta escolhida no consentimento. Conferir uma aplicação identificada globalmente valida acesso ao ambiente pretendido; isso **não comprova igualdade de identidade, colunas ou regras de linha entre duas contas distintas**. A interface orienta a conferir a conta e o ambiente no BI. O portal também exige acesso atual pelo Lumo à aplicação selecionada e mantém os grants separados por usuário/tenant, sem reutilizar o conector MCP do ambiente de desenvolvimento.

A ferramenta de IA bi_data usa somente a aplicação vinculada ao projeto e oferece search_catalog, get_app, search_values e query. A IA descobre conceitos, refs e valores existentes antes de calcular; não depende de SQL ou nomes de tabelas fornecidos pela pessoa. Consulta, campos, filtros, ordenação, tamanho e retorno são validados. Não aceita substituição de app/tenant, SQL, escrita ou ferramentas adicionais anunciadas pelo servidor. Consultas retornam até 500 linhas, totais, indicação de corte, unidades, data dos dados e permalink quando disponíveis. A auditoria guarda contexto e contagens, sem copiar linhas ou credenciais. JSON e respostas SSE são aceitos com limites de leitura e fechamento das sessões.

A homologação no ambiente de testes confirmou as quatro ferramentas, o consentimento no navegador, a conferência da aplicação e a renovação real com rotação do refresh token. Uma pergunta de negócio no chat retornou o saldo da última competência, sua data de atualização e um link real do relatório. Não foi inferida uma moeda ausente no retorno do MCP. O projeto interno de homologação foi arquivado ao final; não houve criação/alteração/publicação de recurso BI, revisão de escrita, ETL, agenda ou PNG nessa conversa. Identificadores, valores reais e evidências detalhadas ficam nos relatórios privados, fora do repositório público.

A validação de argumentos agora volta à IA como resposta da ferramenta com campos e mensagem para correção na mesma tarefa. Ela não encerra toda a conversa com um erro genérico. Um teste de protocolo reproduziu a falha antes da correção e confirmou, depois, argumento recusado → nova chamada válida → resposta concluída, sem revisão ou escrita. Parâmetros opcionais nulos são normalizados; filtros de texto em branco e seus espaços são preservados na API MCP.

### Revogação remota: limitação observada

O portal apaga as credenciais e bloqueia imediatamente novas consultas e respostas em andamento após desconectar. Solicita ao BI a revogação dos tokens de acesso e renovação. Na homologação, o endpoint remoto confirmou recebimento, mas uma chamada protegida com o token de acesso anterior ainda retornou dados. Portanto, a resposta usa **remote_revocation_acknowledged**, não declara bloqueio remoto imediato, e a mensagem informa somente recebimento da solicitação. A causa e o prazo de efetivação precisam ser confirmados com o provedor; não presumir que inicialização MCP ou HTTP 200 da revogação comprovem encerramento de acesso. Tokens descartados não ficam disponíveis na interface ou no histórico.

### Configuração e acompanhamento

A funcionalidade é habilitada por BI_MCP_ENABLED; BI_MCP_RESOURCE define o endereço copiado do BI e BI_MCP_REDIRECT_URI deve coincidir com o callback e a origem de acesso desta instalação; não misturar localhost e 127.0.0.1 na mesma autorização. BI_MCP_CLIENT_ID é opcional quando o servidor aceita registro dinâmico. Os exemplos permanecem desabilitados; a homologação foi habilitada apenas na instalação local. O ensaio de restauração desabilita MCP, ChatGPT, Lumo, visualizador e Suíte, impede HTTP externo e exige nova autenticação nas conexões restauradas.

Impacto no tour e tira-dúvidas: usar a âncora bi-mcp-connection na página Conexões; explicar autorização por ambiente, aplicação de conferência, escolha da própria conta no consentimento, consulta sem SQL, unidade/data/link, **Conferir acesso**, reconexão e **Desconectar**. Diferenciar consultas de dados, autoria/ETL pelo Lumo e inferência pelo plano ChatGPT. O guia de MCP explica esses conceitos; o passo da página usa a âncora disponível da conexão.

Critério adicional da ocorrência de rolagem registrada pelo usuário: repetir alternâncias entre celular, tablet e computador em diferentes dashboards, com rolagem vertical, filtros ativos, painel ampliado/retorno e atualização acompanhada. Conferir roda do mouse, teclado e toque nos navegadores disponíveis. O resultado esperado é continuar rolando sem recarregar nem perder sessão/filtros; os ensaios já realizados no Chrome não encerram a verificação de compatibilidade nos demais navegadores e dispositivos.

Validação desta revisão: 525 testes de backend em SQLite e PostgreSQL, com 2.853 asserções em cada banco; 55 testes de interface; TypeScript/build local; Pint; teste operacional de configuração HTTPS e smoke do Codex sem inferência. A página Conexões foi conferida em 390, 768 e 1440 px, sem rolagem horizontal ou controles cortados. A disposição dos cartões existentes também foi ajustada em tablet.


## Organizar nomes e formatos das tabelas

Em **Catálogo BI → Tabelas → recurso**, o botão **Organizar nomes e formatos** abre a configuração atual da tabela. Permite editar nome e descrição da tabela, nomes de exibição e descrições dos campos, além dos formatos numéricos inteiro, duas casas, valor monetário, percentual e padrão do BI. A busca, o filtro de campos alterados, a contagem de mudanças e a paginação de 25 campos ajudam em tabelas maiores. Campos escondidos pela busca ou paginação mantêm suas edições. Formatos de data e formatos estruturados desconhecidos são preservados; um formato personalizado numérico existente continua disponível enquanto a pessoa não decidir substituí-lo.

O formulário envia somente os campos editados. A revisão apresenta **Atual / Proposto**, tenant, tabela e impacto, sem iniciar uma conversa de IA. A pessoa autoriza ou recusa dentro do mesmo detalhe do catálogo. O resultado diferencia autorização, execução, confirmação, recusa, expiração e falha. **Ver tabela atualizada** recebe o detalhe do recurso e atualiza seu nome na lista, sem exigir outra reconstrução completa do catálogo. Perfis de visualização não podem editar.

A operação fica vinculada ao conteúdo canônico da tabela, à pessoa, à versão do acesso e à sessão BI. A revisão expira em 12 minutos; alterações remotas posteriores exigem nova conferência. Há validação nativa antes da revisão e antes do envio, bloqueio por tabela, idempotência de solicitação e registro do início do envio. Depois da gravação, outra leitura confere os valores solicitados e todos os demais metadados. Um resultado parcial ou incerto preserva a falha no histórico e exige conferir o estado atual; não ocorre reenvio automático. Não há edição de nomes físicos de colunas, tipos, chaves, partições, origem ETL, linhas ou agendas nesse formulário, nem publicação implícita.

A homologação real usou um ETL privado no tenant Inovadora que gerou três linhas inteiramente fictícias, com seis campos. A tabela foi criada, carregada e conferida por amostra efetiva, sem consultar bancos de clientes. O portal salvou dez mudanças, incluindo os quatro formatos numéricos, e as leu de volta. As três linhas, nomes físicos, tipos e todos os campos fora da revisão coincidiram com a linha de base. Os IDs físicos também foram comparados entre a primeira e a última gravação.

O teste de restauração do formato padrão encontrou uma particularidade real: a máscara enviada como `null` não limpava a máscara anterior, embora a descrição fosse removida. A conferência recusou declarar sucesso para esse resultado parcial. A representação de limpeza foi ajustada para string vazia no envio nativo, mantendo **Padrão do BI** e valor nulo na interface/revisão. Uma nova revisão foi autorizada e confirmou o padrão restaurado, a limpeza da descrição da tabela e uma nova descrição de coluna. Reabrir o formulário mostrou o padrão correto e zero alterações pendentes. O histórico conserva a tentativa parcial anterior.

Também foi corrigida a serialização das listas e mapas vazios do YAML: a gravação preserva sua diferença, inclusive em metadados desconhecidos e mapas com chaves numéricas. O linter oficial detectou esse problema antes da integração real. Outro defeito foi encontrado no catálogo: o resumo físico do BI substituía as colunas de autoria e ocultava descrições e formatos. Agora os IDs, nomes físicos e tipos do resumo são combinados com a apresentação canônica da mesma coluna, por nome e tipo; tipos divergentes recebem estado de metadados parciais. Uma identidade explícita de tabela diferente ou malformada é recusada. A descrição salva foi conferida visivelmente no catálogo.

A criação de tabela pelo broker também aceita o caminho comprovado no Lumo instalado: a etapa de origem com esquema capturado, diretamente ligada a uma única inserção ainda sem tabela. Fluxos publicados e qualquer tabela já vinculada são recusados. No caminho pela origem, esquemas ausentes, destinos ambíguos e múltiplas entradas também são recusados. O caminho anterior pela etapa de inserção foi preservado. O guia da IA orienta preferir a origem capturada, executar a inserção depois da criação e confirmar as linhas no DW. Também distingue `columns` (nomes, tipos e apresentação) de `info` da tabela (IDs físicos quando retornados pelo provedor).

Os dois recursos fictícios foram removidos após conferir vínculos e guardar um checkpoint privado dos seus YAMLs. O inventário remoto voltou a 171 tabelas e 171 ETLs, sem os recursos de teste, e manteve a tabela e a fonte originais de conferência. Nenhuma aplicação, publicação ou agenda foi criada nesta homologação. A validação real de gravação desta entrega ocorreu em tabela privada; contratos de edição de tabelas publicadas, concorrência e isolamento passaram por testes isolados e ainda precisam de ensaios ampliados com contas independentes.

Impacto no tour e tira-dúvidas: usar a âncora estável `table-presentation`. Orientar **Catálogo BI → Tabelas → Organizar nomes e formatos → Conferir alterações → Autorizar/Recusar → Ver tabela atualizada**, conforme perfil e tamanho de tela. Explicar nomes de exibição, padrões da tabela versus escolhas do dashboard, campos alterados, filtros/paginação, revisão vencida e resultado não confirmado. Alterar apresentação não equivale a alterar estrutura ou autorizar publicação. O guia de edição de tabelas acompanha esse fluxo e suas limitações, e pode ser encontrado na ajuda contextual.

Validação final desta entrega: **571 testes de backend, com 3.294 asserções em SQLite e novamente em PostgreSQL isolado**; 61 testes de interface; TypeScript/build local; Pint; teste operacional e smoke do protocolo Codex sem inferência. Formulário e revisão foram conferidos em 390, 768 e 1440 px. O relato de rolagem na troca de dispositivos permanece na fila de compatibilidade descrita acima; a edição de tabelas não altera o visualizador externo.
