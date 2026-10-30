# Homologação local

Os testes automatizados usam SQLite em memória ou PostgreSQL 17 descartável, cache em memória e HTTP simulado. Não acessam tenants reais. A homologação integrada abaixo foi realizada separadamente com as contas conectadas pelo titular.

## Evidências reais

| Fluxo | Resultado |
|---|---|
| ChatGPT OSS local | Login oficial, cliente dinâmico, consentimento do plano, catálogo e resposta completa confirmados |
| Codex | Conversa, retomada e chamadas das ferramentas por JSON-RPC confirmadas |
| BI | Oito tenants recuperados; catálogo completo lido em todos eles |
| Sessão BI | Renovação após expiração salva; comandos seguintes continuaram funcionando |
| Rascunho | Clone identificado pelo novo ID, edição e PNG real; exclusão somente da cópia criada no teste |
| Criação por IA | Nova aplicação com KPI e dois gráficos, três consultas reais aprovadas e correções verificadas no BI |
| Anexos | CSV, PDF, XLSX e imagens sintéticos interpretados pela IA real; totais e cruzamento entre PDF e duas abas XLSX conferidos |
| Análise visual | PNG específico autorizado pelo usuário enviado ao modelo e interpretado |
| Carga | Fonte única, agenda diária preservada às 05h de Brasília; teste concluído com 14 linhas de entrada/saída e run_id novo confirmado |
| Backup | Snapshot Restic criptografado e integridade verificada |
| Restauração | Banco separado, arquivos privados recuperados e duas conexões descriptografadas; integrações desabilitadas e banco original preservado |
| HTTPS/subpasta | Certificado de teste validado, assets, login, sessão segura, redirect e Inertia em /ia-ganalise; WebSocket retornou HTTP 101 e pusher:connection_established; origem indevida recebeu erro 4009 |
| Saúde/retencão | Heartbeats de workers/scheduler e limites de disco conferidos; retenção simulada sem apagar dados atuais |

Publicação: a rodada inicial preparou a revisão; em 30/10 a publicação como nova foi autorizada pelo titular no portal, executada uma vez e confirmada em duas consultas. O relatório privado da instalação registra tenant, aplicação e revisão específicos; não integra o repositório público.

## Cobertura automatizada

Inclui propriedade/roles, CSRF/autenticação, callbacks inválidos, PKCE/nonce/JWKS/audience, escopos de inferência, bloqueio OSS remoto, criptografia e rotação de sessão Lumo; envelope/paginação/permissões do catálogo; conflitos de workspace, cancelamento, revisão expirada/alterada, execução única e retomada assíncrona; ingestão e integridade de anexos; consentimento de PNG por arquivo/hash/conversa; fontes compartilhadas, agendas mais frequentes, fonte privada, logs antigos/ambíguos e publicação bloqueada sem carga confirmada; saúde e retenção com preservação de anexos/caminhos de outros projetos.

Resultado da versão inicial: **138 testes e 453 verificações aprovados**, além do build Vue/TypeScript, Pint e smoke do Codex. Auditorias Composer/npm de produção sem vulnerabilidades conhecidas na data do teste. O relatório privado registra as evidências da instalação. CI repete os testes sem conexões pessoais.

## Limites

- Não foi realizado teste destrutivo em tenant de clientes em uso.
- A conta real utilizada tem seus próprios acessos. Permissão negada de módulos foi observada e tratada; não foi modificada uma conta de cliente para simular revogação/2FA.
- Imagem de um dashboard só é enviada à OpenAI após autorização por arquivo e conversa. As prévias locais não autorizadas permanecem privadas.
- PDF digitalizado não possui OCR automático. XLSX usa valores armazenados, não executa fórmulas, e pode exibir datas seriais.
- O teste HTTPS usa certificado local, sem contornar avisos do navegador. O certificado público do domínio e a instalação remota ainda não existem nesta entrega.
- Não houve piloto de carga com múltiplas contas BI/ChatGPT reais. A concorrência e a separação de usuários têm testes automatizados, mas o dimensionamento final depende do servidor e das cotas individuais.

## Ampliação da conferência do catálogo

Nomes/referências de variáveis conferidos nos oito tenants, com todos os valores protegidos. Detalhes das duas apresentações da Inovadora recuperados sem usar comando info incompatível. Cenário de segunda agenda testado contra a configuração real, somente em leitura: recusou a duplicação de fonte já agendada, sem escrita remota. Foram adicionados testes para formatos incompletos, isolamento por tenant, proteção de valores, tipos sem info e novas ativações de fontes compartilhadas.

A desconexão cancela revisões pendentes/aprovadas e tarefas sem worker ativo, impedindo execução de uma autorização enfileirada. Tarefas em andamento recebem cancelamento cooperativo; isso não desfaz uma operação já iniciada no BI. Os testes confirmaram que a conversa aceita uma nova tarefa após o cancelamento e que os dados de outro usuário permanecem intactos.

Cadastros não existiam nos tenants consultados; o tratamento do tipo foi verificado com fixtures. Investigações retornaram acesso negado na conta real. Esses módulos não têm uma homologação funcional real com dados autorizados nesta instalação.

Regressões adicionais: redução de cinco minutos para diária, desativação/remoção de fonte, restrições de dias/datas e perda de um segundo horário são recusadas; frequência equivalente/maior e reordenação YAML são aceitas. Na configuração real, uma proposta mais lenta foi recusada em leitura. A renovação BI foi observada novamente na sessão real após a proteção de geração. Troca de login durante rotação, desconexão durante comando, falha tardia e resultado antigo de tenants foram simulados sem acesso à conta real.

## Interpretação real de PDF e XLSX

Arquivos sintéticos enviados pela entrada do controlador do portal, com validação de formato/propriedade, armazenamento privado e fila de documentos reais. A conversa Codex recebeu ambos os conteúdos e identificou dois códigos de verificação, duas abas e todos os valores esperados. Receita de 2.800, custo variável de 800 e despesa fixa de 600 produziram resultado de 1.400; a meta de 2.500 gerou desvio de 300 (12%). A linha TOTAL não foi contada novamente. JSON da resposta foi comparado ao resultado esperado, campo a campo; tarefa e resposta terminaram como completed. A IA reconheceu os limites do PDF e os valores de fórmulas armazenados, sem executá-las.

A tentativa de upload automatizado no Chrome foi recusada pela extensão com Not allowed. Por isso a prova acima cobre controlador, armazenamento, extração e inferência reais; não declara concluída a automação do seletor de arquivos para esses dois formatos. Para repetir pelo navegador, a extensão exige Allow access to file URLs. Nenhuma conta de cliente ou recurso do BI foi alterado.

## Compatibilidade Lumo 3.41 e primeiro CI público

O primeiro build no GitHub recusou o pacote 3.41 porque a versão fixada ainda era 3.40. O fornecedor substitui o arquivo do canal de download. A atualização foi validada antes da troca: comandos de listagem/aplicações preservados, catálogo dos oito tenants consultado com os 13 tipos, ausência de falhas de formato e três consultas da prévia de teste com status ok. Essa conferência foi somente em leitura remota, com workspaces locais separados. A imagem 3.41 passou nos 138 testes/453 verificações e no smoke do Codex. O build agora também fixa o SHA-256 do pacote homologado. Novas versões do canal exigem nova conferência; o projeto não aceita uma troca silenciosa de binário.


## Publicação, contexto e concorrência — 30/10/2026

A publicação manual como nova foi homologada com a conta real, após autorização no portal. Nome, destino e quantidade de dashboards foram confirmados; o projeto permaneceu vinculado ao recurso publicado. Não houve nova carga ou agenda duplicada. O modo de substituição e os cenários de conflito/resultado incerto ainda têm evidência isolada, sem ensaio mutativo real dessa modalidade.

O teste do binário Codex com provedor sintético detectou e depois confirmou a retirada de instruções automáticas do workspace e do perfil não controlado. A tarefa real seguinte leu o contexto correto pelos arquivos do projeto: produto, tenant e nome. O diretório de execução permaneceu separado e vazio, sem operações BI, aprovações ou recursos criados. O projeto do ensaio foi arquivado, preservando a conversa.

A regressão ampliada passou com **265 testes PHP / 1.415 verificações**, **19 testes de frontend**, build Vue/TypeScript e Pint. A suíte de publicação verifica criação como nova, substituição explícita, atualização do vínculo do projeto, alteração do alvo, fonte/destino não autorizado, estado desconhecido, falta de cobertura diária, autoria suja e aceitação sem comprovação. A suíte de contexto/concorrrência verifica tarefas duplicadas, cancelamento entre leitura e reivindicação e proteção de projetos arquivados ou com outra propriedade. Os testes não usam contas pessoais.


## Atualização da autoria, recuperação e cache — 30/10/2026

A regressão de 265 testes inclui confirmação atrasada, resposta nativa perdida, idempotência da conferência, reconexão, troca de sessão durante a consulta, isolamento de proprietário e preservação do resultado de publicação quando a resposta nativa excede o resumo do histórico. Esses cenários de falha foram simulados em testes; não foi provocada uma publicação real incerta. A conferência usa somente comandos de leitura remota.

A atualização de autoria passou por testes de arquivos ausentes, versão atual, mudanças locais, conflito nativo, mudança posterior do servidor, falta de acesso e tarefas/revisões pendentes. A conta real confirmou recebimento, consulta sem reescrita e preservação de edição temporária no projeto descartável. O hash do arquivo modificado permaneceu igual após a tentativa recusada; o conteúdo original foi restaurado e o projeto de teste foi arquivado. Não houve inferência nem escrita remota nesse ciclo.

A aplicação já publicada aparecia como rascunho no catálogo por metadados locais antigos. Uma consulta independente confirmou o estado publicado. Após reconstruir somente o cache de leitura, o portal exibiu a mesa e os totais corretos. Os testes também confirmam que uma atualização de cache malsucedida não usa metadados antigos como se fossem atuais nem modifica a autoria dos projetos.


## Suíte, acessos locais, conexões e prévia — 30/10/2026

A regressão ampliada passou com **370 testes PHP / 2.148 verificações**, tanto em SQLite em memória quanto em PostgreSQL 17 descartável, **25 testes de frontend**, um teste operacional Python, build Vue/TypeScript, Pint e smoke do protocolo Codex sem inferência. O runner PostgreSQL fixa um serviço e um banco de testes sem portas públicas nem volume persistente; o banco da instalação não é usado pelas suítes. O CI reproduz as duas modalidades.

A autenticação da Suíte foi coberta com respostas simuladas do contrato documentado: criação/vínculo por UUID, perfil administrativo, conta inativa, colisão, credenciais inválidas, JSON inválido, indisponibilidade, redirecionamento recusado, limite de tentativas e preservação de senhas. O primeiro vínculo encerra sessões locais anteriores; o ChatGPT não contorna a entrada pela Suíte de uma conta vinculada. O titular concluiu depois o login real no navegador. A conta criada foi confirmada como ativa, vinculada ao emissor oficial, com perfil de administrador, sem senha local e com auditoria do login. O workspace próprio iniciou sem projetos nem conexões herdadas da demonstração.

O cadastro local foi homologado em navegador isolado: primeiro acesso restrito à troca de senha, senha pessoal nova, entrada no workspace sem menu administrativo e recusa após inativação pelo administrador. A conta sintética permanece inativa. Os testes de regressão cobrem concorrência de cadastros, proteção do administrador principal, revogação de sessões e filas, versões antigas, assinaturas de revisão não reutilizáveis e bloqueio de callbacks/assinaturas privadas após revogação. A normalização local entre localhost e 127.0.0.1 foi conferida sem copiar cookies nem dispensar o estado OAuth.

O aviso técnico de widget personalizado foi reproduzido em aplicação real apenas em leitura. A modal passou a explicar a limitação em português e oferecer atalhos funcionais para a visualização interativa e o PNG real. Uma exportação PNG de 3.840 × 4.436 foi confirmada, sem enviar pixels à OpenAI. Números e linhas tabulares válidos continuam visíveis; falhas reais e dados vazios não são apresentados como a limitação de um painel personalizado.

Os formulários de conexões receberam testes de contrato para PostgreSQL, MySQL, SQL Server estruturado, Oracle, Firebird, BigQuery, Informix e ODBC, incluindo proteção de campos privados e alterações de contexto. A conferência real de PostgreSQL confirmou conectividade e estrutura, com limite explícito nos metadados. O retorno nativo de schema dessa fonte teve erro de UNION; a alternativa usa uma consulta fixa de leitura a information_schema, sem aceitar SQL do navegador.

A homologação mutativa ficou restrita a três conexões descartáveis criadas para o teste no tenant autorizado. Ela encontrou e corrigiu o tamanho do estado de acompanhamento no PostgreSQL e a validação global indevida de recursos não materializados. Uma configuração parcial foi retomada na mesma referência; uma criação completa pelo formulário foi salva e relida, com o agente autorizado. A limpeza removeu somente essas três conexões. A comparação final preservou os IDs das 31 conexões preexistentes. A consulta nativa de dependências tinha cobertura parcial; o resultado não foi apresentado como prova de inventário global completo. Nenhuma aplicação, tabela, ETL ou agenda preexistente foi alterada.

A versão oficial de Lumo disponível mudou para **3.42.0** durante a rodada. Antes da atualização local, uma imagem separada passou pela suíte PostgreSQL e por consultas reais dos 13 tipos do catálogo, prévia de aplicação e teste PostgreSQL. O SHA-256 e a versão foram atualizados explicitamente no Dockerfile; as verificações de integridade continuam obrigatórias. A imagem local foi atualizada preservando banco, filas e arquivos. As evidências específicas, IDs e relatórios de dados permanecem privados. Nenhuma implantação em servidor foi realizada.


## Verificação da versão recebida no CI — 30/10/2026

O CI da revisão de autenticação parou antes dos testes porque o pacote oficial, antes 3.42.0, passou a oferecer 3.43.1. A comparação com o SHA-256 fixado recusou a troca. A nova distribuição foi comparada ao checksum do fornecedor e conferida por versão/commit em uma imagem candidata; os **365 testes / 2.098 verificações PostgreSQL** e o smoke Codex passaram nessa imagem. O catálogo real dos 13 tipos e a prévia de quatro indicadores da aplicação de teste foram consultados novamente apenas em leitura; a ausência de permissão de investigações permaneceu explícita.

A versão e o hash foram atualizados após essa conferência. Não foi desativada nenhuma verificação de integridade, não houve atualização automática do CLI em execução e nenhum binário de terceiros foi incluído no repositório. O canal documentado de download permanece mutável; novas alterações incompatíveis com a pinagem continuam interrompendo o build até uma nova revisão.

A opção ChatGPT foi retirada da tela de entrada por determinação do usuário. O início e o retorno do vínculo exigem uma conta já autenticada; transação anônima antiga, troca de usuário e versão revogada são recusadas antes de vincular. O fluxo positivo para a conta autenticada tem regressão própria. Depois dessa alteração, a suíte completa passou novamente com **370 testes / 2.148 verificações** em SQLite e PostgreSQL na imagem Lumo 3.43.1; a tela sem ChatGPT foi conferida em navegador isolado.


## Revisões no chat e distribuição das ferramentas — 30/10/2026

A ampliação passou com **374 testes PHP / 2.180 verificações**, **32 testes de frontend**, build Vue/TypeScript e Pint. O PostgreSQL descartável e o smoke Codex também passaram na imagem candidata Lumo **3.44.0**, recebida após nova substituição do pacote oficial. Os 13 tipos do catálogo, quatro indicadores de prévia e teste/estrutura PostgreSQL foram novamente conferidos somente em leitura. A imagem local foi atualizada após essa validação, preservando a versão anterior.

A interface das revisões foi conferida em navegador isolado no desktop e no celular. O aviso fixo e o cartão foram visíveis; uma atualização em segundo plano preservou a leitura. Ao trocar de conversa, somente a revisão correspondente foi mostrada. A recusa e a autorização foram registradas no chat e não navegaram para Atividade. A conta sintética não tinha conexão BI ou IA: a execução autorizada parou pela ausência deliberada dessa conexão e apareceu como falha. Não houve inferência nem alteração de recursos reais nesse ensaio. O projeto foi arquivado, a conta inativada e a conexão desconectada de exemplo removida.

A terceira distribuição foi comparada ao SHA-256 publicado e fixada após homologação. O CI passou a importar/exportar cache das camadas de ferramentas com as ações oficiais do Docker, carregando a imagem apenas no runner, com publicação desabilitada. As verificações de integridade e versão permanecem no build. O cache reduz a dependência do canal mutável nas recompilações compatíveis; um cache ausente ou invalidado ainda precisa do pacote oficial correspondente à pinagem. Não há substituição automática por uma versão diferente.

Referência da configuração de cache: [documentação oficial do Docker](https://docs.docker.com/build/cache/backends/gha/). As duas ações Docker também estão fixadas por hash de commit correspondente às versões oficiais verificadas.


## Consulta específica e desempenho — 30/10/2026

A abertura de aplicação usa a identidade atual retornada por `info --full` quando tenant, tipo e ID estão completos e corretos. Respostas divergentes são recusadas; identidade incompleta exige a listagem autorizada. A atualização de estado e a listagem integral não são repetidas na abertura normal. As consultas de prévia, saúde/histórico e contexto de projeto evitam reconstruir dependências que não utilizam.

Duas leituras por fase da aplicação de teste da Inovadora, com catálogo quente, passaram de 5,08/3,99 s para 1,46/1,43 s. O catálogo completo permaneceu em 16,6/17,9 s; esse trecho ainda precisa de otimização. O cache quente do catálogo levou aproximadamente 3 ms no serviço. A medição não inclui uma amostra suficiente para percentis nem comprova o tempo total percebido no navegador. As operações foram somente de leitura, sem BI alterado e sem IA.

A suíte ampliada contém 383 testes PHP / 2.232 verificações. Os nove novos casos cobrem o caminho de leitura específico, formatos legados, divergências de identidade, imagem em cache e recusa atual de permissão.


## Mesas de aplicações e de dados — 30/10/2026

Os formulários usam `lumo new bi-desk` / `new dw-desk`, autoria canônica, lint do arquivo selecionado e push com controle nativo de conflito. A revisão e o resultado são próprios do tenant e da sessão; não exigem projeto ou conta IA. Testes cobrem preparação sem escrita, idempotência, propriedade/perfil, validação de campos, identidade do arquivo, conflito, troca de sessão, rebaixamento de acesso antes do envio, criação parcial e ausência de confirmação após aceite.

A homologação real criou e editou uma mesa vazia de cada tipo, pela interface, no tenant Inovadora. A comparação e as decisões foram apresentadas no formulário; a edição da mesa de dados foi conferida em viewport móvel. Uma revisão adicional recusada não criou outra mesa. Depois da conferência, somente as mesas descartáveis desta rodada foram removidas, sem cascata, e o inventário de IDs preexistentes foi preservado. Não houve publicação ou execução de fontes. Um erro de userscript de uma extensão do Chrome foi observado no console; a origem é externa ao aplicativo e não impediu o ciclo.

A revisão ampliada passou com **403 testes PHP / 2.362 verificações** em SQLite e PostgreSQL descartável, **32 testes de frontend**, Pint e build Vue/TypeScript. As opções avançadas de acesso e a administração de membros ainda não são alteradas pelo formulário.
