# Entrega local e pendências de homologação

Instalação de desenvolvimento: http://127.0.0.1:8088. Estado consolidado em 01/11/2026. O código está preparado para execução Docker; esta entrega não declara a implantação central concluída.

| Entrega | Implementado e verificado | Etapa restante |
|---|---|---|
| Catálogo por tenant | Oito tenants da conta principal e um na conta restrita; 13 tipos Lumo, dashboards, Minha Mesa, mesas, etiquetas, fontes, agendas, metadados confirmados pelo arquivo sincronizado e variáveis sem valores | Homologar autoria dos módulos indisponíveis e restrições entre mesas; segunda identidade com tenant restrito e revogação/restauração de Criar mesas já conferidas |
| ChatGPT | Login oficial OSS local, plano individual, modelos autorizados, inferência remota, Codex e ferramentas reais | Enquadramento/configuração oficial para hospedagem central; repetir no domínio público |
| Criação completa | Clone, manutenção, consultas, criação nova, prévia PNG/interativa, histórico de prévias no projeto, revisão assíncrona, ações manuais de duplicação/exclusão e carga de teste | Ampliar a homologação real da substituição de versões; publicação como nova já autorizada e confirmada no BI |
| Anexos | Extração privada de texto/CSV/JSON/YAML/MD/PDF/XLSX/imagens; seleção ordenada por pedido, preservada nas retomadas e conferida sem inferência; interpretação real anterior de CSV, PDF, XLSX e imagens; preparo visual de PDF sem texto conferido sem nova inferência | Homologar interpretação real do PDF digitalizado com autorização; OCR não implementado; validar amostras do piloto |
| Agendamento | Fontes compartilhadas, frequência preservada, duplicação recusada, agenda ativa e execução nova concluída; fonte geográfica publicada após autorização e carga concluída | Novas fontes privadas continuam exigindo autorização específica; não reutilizar a autorização geográfica em outro recurso |
| Operação | HTTPS/subpasta/WebSocket em ensaio isolado, backup criptografado/restauração independente, retenção e saúde; testes de propriedade e concorrência; empacotamento do auxiliar oficial de sandbox corrigido | Isolamento completo do processo por usuário ainda não comprovado; este Docker bloqueia namespaces nativos; domínio/certificado, backup externo, alertas e piloto com contas independentes |

## Evidências e uso

A regressão usada como base desta auditoria, commit `9686d8b`, passou com **1.215 testes / 9.015 asserções em SQLite**, mais três casos próprios do PostgreSQL ignorados nesse banco; **1.218 testes / 9.063 asserções em PostgreSQL isolado**; **188 testes de frontend**, TypeScript/build, Pint, testes operacionais Python e smoke do protocolo Codex. [CI aprovado dessa revisão](https://github.com/raafaelb/g-analise-ia/actions/runs/41275015775). As contagens nas seções históricas descrevem as respectivas revisões, não a cobertura atual. O teste do binário Codex com provedor sintético conferiu o catálogo real de ferramentas e a recusa de escrita nativa, sem usar a conta OpenAI. As evidências reais e seus limites estão em [homologação](homologacao.md). Os testes automatizados não usam as contas reais.

As duas conexões foram conferidas no portal. Renovação BI preserva a sessão criptografada. Desconectar cancela revisões pendentes e tarefas pausadas; ações já iniciadas remotamente não são desfeitas. Consultas sem permissão são diferenciadas de listas vazias.

A publicação como nova foi autorizada pelo próprio portal e confirmada na mesa de teste da Inovadora. Uma segunda consulta independente confirmou recurso, nome, quantidade de dashboards e destino. A versão compartilhada permanece vinculada ao projeto. Substituição de uma versão existente passou pelos testes isolados; o ciclo real de substituição ainda precisa de uma revisão e decisão próprias. Uma revisão expirada ou alterada exige nova validação antes da execução.

## Auditoria das seis entregas — 01/11/2026

O inventário real dos 13 grupos foi comparado novamente com as consultas nativas: IDs, resumos e disponibilidade coincidiram, sem mudar as contagens do portal. Essa primeira conferência utilizou a conta BI principal. Em seguida, a segunda identidade real foi conectada a um perfil local de teste sem administração. Ela recebeu somente o tenant `teste_` (1290); a validação nativa e concorrente está descrita abaixo.

A publicação autorizada de `flow:100344` e `table:93362` foi confirmada na mesa de dados 1585 do tenant Inovadora. A agenda 4324 permaneceu ativa às 03h de Brasília; a execução de teste terminou com sucesso em 31/10/2026 às 18h11min52s. A aplicação geográfica `app:41453` continua privada. Essa autorização já foi consumida e não será repetida.

O backup criptografado e a restauração em banco independente possuem recibos de integridade e conferência dos arquivos/credenciais. Não equivalem a backup externo agendado ou failover. A consulta de saúde de 01/11/2026 confirmou banco, cache, scheduler, três workers e disco, com filas vazias; esse comando não testa a elegibilidade OpenAI nem o isolamento de sistema operacional.

O auxiliar `bubblewrap` já integra a dependência Codex 0.165.0; a imagem passou a preservá-lo. O ensaio sem rede, volumes ou credenciais encontrou inicialmente a ausência do auxiliar e, depois da correção, a recusa de criação de namespaces deste host Docker. Não foram ampliados privilégios, desativados filtros do Docker nem habilitada execução nativa. O smoke de CI confere o auxiliar e o protocolo, e não afirma que essa fronteira de isolamento foi homologada.

A correção do empacotamento passou no build separado, no teste real do auxiliar e no initialize/thread/start sem inferência. O smoke passou a retornar código 1 ao falhar; a imagem antiga reproduziu esse erro e a corrigida terminou com código 0. Os dez testes do protocolo (37 asserções), os dois testes operacionais Python e o Pint passaram; o login local respondeu HTTP 200. Não houve alteração de interface, rotas, ajuda ou âncoras de tour nesta revisão interna.

As pendências externas continuam explícitas: acesso oficial para oferta ChatGPT remota, validação do isolamento do processo/capacidade e infraestrutura definitiva. Nenhuma implantação em servidor foi autorizada ou realizada.

## Segunda identidade BI e abertura do catálogo — 01/11/2026

A conta de teste foi autenticada pelo formulário normal do portal e pelo Lumo, em uma sessão de navegador separada. Recebeu somente o tenant 1290. **29 verificações HTTP** confirmaram o perfil editor, ausência de projetos/conversas/anexos/revisões alheios, recusa da administração, dos tenants 923 e 1214 e de criação de projeto em tenant não autorizado. Conversas, arquivos e revisões de outro usuário retornaram 404. Trocar apenas o identificador de uma aplicação ou tabela mantendo o tenant permitido também foi recusado, sem retornar o recurso.

Os **13 grupos** do catálogo coincidiram em IDs, quantidades e disponibilidade com consultas nativas independentes. Investigações retornou código 6, inicialmente classificado como falta de permissão; o diagnóstico detalhado abaixo corrigiu a causa para módulo indisponível no ambiente, mantendo a quantidade desconhecida. Havia um agente autorizado e nenhum outro recurso na linha de base. O Lumo recusou a leitura de uma aplicação privada e de uma tabela publicada de outro tenant, sem transformar a recusa em expiração da sessão.

Duas consultas reais ocorreram simultaneamente, com sobreposição de cerca de 2,9 segundos: 73 aplicações para a conta principal na Inovadora e nenhuma para a conta restrita em `teste_`. Os contextos permaneceram separados. Esse ensaio não substitui testes de capacidade com muitos usuários nem demonstra isolamento completo do processo no sistema operacional.

A conta restrita criou e editou uma mesa vazia pelo formulário, com revisão e confirmação nativa em cada ação. A descrição final foi conferida no BI. Depois, somente essa mesa foi removida pelo Lumo, com checkpoint Git do YAML e sem cascata; o inventário voltou à linha de base. Isso não acrescenta uma interface de exclusão de mesas ao produto. O perfil local de ensaio foi inativado, sua sessão BI desconectada e suas credenciais removidas; o projeto foi arquivado e os cookies temporários apagados. A conta BI fornecida pelo titular foi preservada.

No primeiro acesso ao catálogo, um único tenant autorizado agora abre automaticamente quando não há preferência válida. Uma escolha anterior ainda permitida é preservada; contas com vários ambientes continuam oferecendo a seleção. Desconectar, ausência de tenants ou identificação inválida não iniciam uma consulta automática. Os seis novos casos exercitam o componente Vue compilado, incluindo preferências antigas e a conexão ausente.

O guia `2026-11-01.3` atualiza o passo **Confira o ambiente** e os artigos de tenants e carregamento. Computador e celular de 390 × 844 foram conferidos; não houve transbordamento horizontal. O tour visto anteriormente não reapareceu após a atualização; a repetição manual exibiu o texto novo e preservou sua primeira versão. O botão **Escolher um projeto** exibiu somente os projetos do usuário conectado, sem solicitar outra conexão BI.

Validação local: **194 testes de frontend**, **41 testes de backend / 263 asserções** sobre guia, isolamento e perfis, TypeScript e build. As evidências privadas preservam os recibos nativos; senhas, cookies e credenciais não integram o código publicado.


## Disponibilidade de módulos e revogação nativa — 01/11/2026

A resposta nativa de `list investigation --remote` foi inspecionada sem expor tokens, nas contas principal e de teste: **“A Sentinela não está disponível para este ambiente.”** O código de saída 6, usado também para recusas de acesso, havia levado o portal a exibir falta de permissão. A conta de teste já tinha as funções Usar IA e Usar a Sentinela liberadas. Não foi necessário ampliar seu acesso nem mudar plano ou habilitar consumo de IA.

O catálogo completo e a consulta por categoria agora distinguem esse erro conhecido de uma negativa de permissão. Exibem **Indisponível**, o motivo e quantidade desconhecida, preservando a conexão. A classificação é restrita à resposta nativa e ao comando correspondentes; erros de autenticação continuam exigindo reconexão, e negativas reais continuam como acesso negado. A disponibilidade do módulo não comprova autoria, execução ou publicação de investigações: essas etapas continuam sem homologação real.

No HEC, somente Criar em Mesas Publicadas foi temporariamente retirado da conta de teste, mantendo as outras funções. A sessão Lumo já conectada recusou a tentativa com código 6; nenhum recurso foi criado. Após remover a personalização e restaurar a herança original do grupo, a mesma sessão criou uma mesa vazia. O recurso foi confirmado por uma nova leitura e removido somente após conferir identidade, nome, ausência de aplicações e checkpoint do YAML canônico. Não foi usada cascata; o inventário final voltou a zero mesas. O HEC confirmou a herança original e nenhuma alteração pendente. O ensaio não demonstra todas as combinações de funções ou restrições por mesa.

A regressão passou com **1.225 testes / 9.047 asserções em SQLite**, mais três casos próprios do PostgreSQL ignorados; **1.228 testes / 9.095 asserções em PostgreSQL isolado**; e **194 testes de frontend**.

Tour e ajuda usam a versão `2026-11-01.4`, explicando a diferença entre indisponibilidade do módulo e permissão do usuário. A revisão não introduz regras específicas para um tenant. Não houve publicação, execução de IA nem escrita em ambiente de clientes.

## Disponibilização

Repositório público oficial: [raafaelb/g-analise-ia](https://github.com/raafaelb/g-analise-ia), licença MIT. O workflow de CI valida cada push e pull request. Antes do piloto, confira a execução correspondente ao commit implantado. Ambientes reais, tokens, arquivos do BI, bancos, backups e relatórios privados não integram o código publicado.

Para a OpenAI, apresentar [a solicitação preparada](solicitacao-openai.md). A gratuidade/código aberto não dispensa a confirmação do caso hospedado remotamente. Não há garantia documental de prazo, custo ou aprovação. O formulário não foi enviado automaticamente.

Operação e reprodução dos ensaios: [operação](operacao.md). A configuração do domínio público está preparada para https://suite.inovadora.com.br/ia-ganalise. O modo OSS local permanece limitado à instalação local elegível.

## Revisão ampliada de gestão (30/10/2026)

Mesas agora exibem tabelas, ETLs, cadastros e aplicações vinculadas; detalhes navegam entre dependências. Colunas físicas e etapas de autoria são interpretadas conforme os formatos reais do Lumo. Há amostras de tabela, última execução/histórico de ETL, histórico de agenda, saúde e ocorrências do agente. As seis consultas foram homologadas nos recursos de teste do tenant Inovadora sem executar novas cargas ou alterar recursos. Referências de conexões por chave são resolvidas no servidor; conexões dinâmicas são sinalizadas como incompletas.

O painel de plano/limites está em Conexões e junto ao chat. A conta real em modo OSS não disponibilizou plano ou saldo pelos métodos oficiais consultados; a interface informa essa ausência e oferece acesso ao uso no ChatGPT. Respostas oficiais com plano, janelas, saldo e renovação possuem testes isolados, inclusive separação de cache por conta. Não há estimativa fictícia de franquia.

O runtime local usa Lumo 3.44.0, depois da conferência em imagem separada, testes SQLite/PostgreSQL e consultas reais. Versão e SHA-256 permanecem fixados; o build recusa substituições silenciosas no canal oficial. A revisão ampliada é mantida na branch `feature/gestao-completa` do repositório público. Cada push tem sua própria execução de CI; confira o resultado vinculado ao hash da versão em avaliação. Nenhuma implantação pública foi feita.

A [análise de gestão completa](gestao-completa.md) distingue as funcionalidades implementadas, as possíveis pelo CLI, as dependentes de APIs/permissões adicionais e os limites de homologação do visualizador interativo e de Claude Code. O painel de prévias acompanha novas imagens sem recarregar a página; a navegação de projetos e o padrão global das barras de rolagem também foram corrigidos e conferidos no navegador.


## Limites de segurança ainda relevantes ao servidor central

Os processos do Lumo/Codex e de extração usam ambiente explicitamente isolado, retirando também variáveis carregadas por dotenv. Escritas de arquivos existentes exigem hash da leitura, bloqueio e substituição atômica. A configuração do Codex desativa colaboração e ferramentas nativas de execução/navegação; as solicitações nativas de arquivo/comando são recusadas. O ensaio com o binário instalado demonstrou a recusa de um `apply_patch` sintético e ausência de escrita dentro e fora do workspace. Isto não comprova isolamento completo de leitura do sistema operacional por usuário: essa fronteira precisa de containers/processos e permissões próprios no piloto central. Nenhuma implantação pública foi realizada.


## Publicação e isolamento de contexto — rodada de 30/10/2026

Aplicações em rascunho registradas pelo portal oferecem publicação manual, sem iniciar IA: seleção da mesa por nome, escolha explícita entre nova aplicação e substituição, revisão do alvo e execução única. Alterações locais precisam estar sincronizadas, e a cobertura diária precisa estar comprovada. O plano vincula sessão e hashes do rascunho/original; uma mudança no alvo invalida a revisão. A substituição usa apenas a confirmação não interativa do CLI, preservando sua proteção de conflito. O projeto passa a apontar para o recurso que sobrevive à publicação. Resultado aceito pelo CLI sem conferência remota fica pendente, sem repetição automática; o botão **Conferir publicação**, disponível no histórico de novas tentativas com registro de envio, consulta o resultado novamente com os acessos atuais. Essa conferência não republica e não retoma a IA. A tentativa é vinculada ao plano revisado; uma troca de sessão durante a consulta impede confirmar o resultado.

Uma entrega duplicada de tarefa já concluída não reinicia a inferência. Cancelamento entre leitura e execução foi testado, assim como troca de tenant/arquivamento durante tarefas e revisões. Projetos vinculados a recursos ou conversas iniciadas precisam de outro projeto para trabalhar em outro tenant. Projetos arquivados recusam novas tarefas e anexos.

O ensaio sintético do Codex inicialmente detectou instruções carregadas automaticamente do perfil e do workspace. Agora o workspace Lumo é separado do diretório de execução; a descoberta de instruções do projeto foi desativada, e o perfil privado recebe uma orientação controlada. As regras do projeto são obtidas pelo broker. Um novo ensaio real com ChatGPT recuperou produto, tenant e nome corretos, sem operações BI, anexos ou recursos criados. O projeto descartável foi arquivado com sua conversa preservada. Isto continua sem equivaler a um isolamento completo do sistema operacional por usuário.

Consultas indisponíveis, incompletas ou com estado de publicação desconhecido exibem contagens desconhecidas, em vez de totais fictícios iguais a zero. Os novos estados possuem testes de frontend.


## Atualização segura e correção de metadados

**Atualizar do BI** recebe a versão atual do recurso vinculado ao projeto, sem iniciar IA ou enviar alterações ao servidor do BI. O broker oferece a mesma operação como `refresh_resource`. Edições locais pendentes, revisões em andamento, sessão alterada e dados de diff desconhecidos impedem a substituição. Não há descarte forçado no workspace de autoria.

A homologação real em projeto local separado confirmou o recebimento da versão publicada, o reconhecimento de uma versão já atual e a preservação de uma edição temporária. A mensagem de conflito foi observada no navegador, o hash permaneceu intacto e o arquivo foi restaurado ao conteúdo exato anterior. Não houve tarefas de IA, aprovações ou recursos remotos criados. O projeto descartável foi arquivado, preservando o histórico.

Um cache antigo do catálogo mostrava o rascunho mesmo depois da publicação confirmada. O cache de consulta agora é reconstruído separadamente da autoria; em falha, metadados antigos não são apresentados como atuais. O navegador confirmou a aplicação publicada na mesa correta e a contagem atual de Minha Mesa.

## Login da Suíte, acesso local e avisos de prévia — 30/10/2026

O portal oferece login de colaborador pela Suíte e gestão administrativa de acessos locais, com troca obrigatória da senha inicial. O fluxo local foi conferido em navegador separado: cadastro, primeiro login restrito, troca de senha, entrada no workspace sem perfil administrativo e recusa após inativação. O titular concluiu o login real da Suíte; a sessão, o perfil de administrador, a vinculação ao emissor oficial e a auditoria foram confirmados. Não foram lidas nem importadas senhas de outras aplicações. O primeiro login de um novo colaborador abre um workspace próprio, sem copiar projetos ou conexões da conta de demonstração.

O aviso técnico de widget personalizado foi reproduzido em leitura e substituído por explicação em português com atalhos de visualização. O ensaio confirmou a navegação para a prévia interativa e a geração de um PNG real, sem enviar seus pixels à IA. A consulta tabular continua indisponível para esse formato; o portal não executa o código personalizado no servidor para tentar reconstruí-la.

O formulário de conexões possui revisão e protege os campos privados. Um ensaio real encontrou e corrigiu limite de coluna no PostgreSQL e validação indevida de todo o inventário local. A configuração de uma conexão de teste já criada foi retomada e conferida no BI, preservando sua referência. Uma nova criação completa pelo formulário também foi confirmada, incluindo o agente selecionado. As três conexões descartáveis desta rodada foram removidas; os IDs das 31 conexões preexistentes permaneceram iguais. As limitações restantes estão na [matriz de gestão](gestao-completa.md).


## Decisões na conversa

A proposta de alteração aparece no chat com tenant, recurso, destino, impacto e comparação. Um aviso junto ao campo de mensagem mantém a pendência visível. Autorizar e Recusar registram a decisão na própria conversa; a página Atividade conserva o histórico. Trocar de conversa não mistura revisões, e a atualização em segundo plano preserva a leitura enquanto há uma decisão pendente.

A conferência visual foi realizada em desktop e celular com conta e propostas sintéticas, sem conexões reais de BI/IA. A recusa cancelou a solicitação e preservou as mensagens. A autorização gerou um único recibo; a execução de teste parou pela ausência deliberada de conexão BI e não foi apresentada como concluída. A conta de teste foi inativada e o projeto arquivado. As garantias de execução/publicação reais continuam cobertas pelos ensaios anteriores e pelos testes do broker.


## Primeira rodada de desempenho

A leitura específica de uma aplicação com identidade completa evita a nova listagem de todas as aplicações; cabeçalho incompleto conserva a validação de pertencimento. A amostra real no tenant Inovadora caiu de 4–5 segundos para cerca de 1,4 segundo, com catálogo quente. Prévias e consultas pontuais também dispensam vínculos não utilizados. A atualização completa do catálogo continua em torno de 17 segundos e permanece uma pendência. O ensaio foi somente de leitura; resultados e limites estão na [matriz de gestão](gestao-completa.md).


## Criar e editar mesas pelo catálogo

As mesas de aplicações e de dados possuem formulário de criação e de nome/descrição, com revisão, decisão e resultado na própria interface. Criação e edição dos dois tipos foram confirmadas no BI em mesas descartáveis da Inovadora; a limpeza sem cascata preservou as mesas preexistentes. O acesso de membros e as opções avançadas continuam fora do formulário. A rodada passou com 403 testes PHP / 2.362 verificações em SQLite/PostgreSQL, 32 testes de frontend, build Vue/TypeScript e Pint.


## PDF sem texto selecionável — 01/11/2026

Anexos PDF digitalizados agora podem preparar até as três primeiras páginas como imagens privadas para o contexto da tarefa. A interface informa a quantidade preparada, e o manifesto informa as páginas efetivamente incluídas no limite compartilhado de três imagens. Não há OCR local ou transcrição pesquisável. Upload, preservação do original, conversão, integridade e montagem dos inputs foram conferidos com um PDF fictício em instalação isolada; não foi iniciada outra inferência na conta real do ChatGPT nesta revisão. Detalhes, testes e limites estão na [matriz atualizada](gestao-completa.md).

## Rascunhos com base fictícia e segunda identidade — 01/11/2026

No tenant de teste, um ETL e uma tabela privados receberam seis registros fictícios, sem usar conexões de clientes. A aplicação privada de conferência mostrou total **2.100**, quantidade **6**, Sul **600** e Norte **1.500**. Esses resultados foram comparados com a leitura do DW, os quatro widgets nativos de dados e a interface do BI. A exportação visual também foi inspecionada. As execuções de nós não foram confundidas com uma carga integral agendada. A consulta avulsa do CLI recusou o acesso nesse cenário; a conferência por widget funcionou, sem uso de outra identidade como alternativa.

A conta de teste não listou nem leu por ID o rascunho privado da conta proprietária, apesar de estar no mesmo tenant. As consultas da conta proprietária usando um contexto de tenant diferente também foram recusadas. Isso amplia a evidência de isolamento de rascunhos; permissões entre mesas publicadas e restrições por linha ainda não foram homologadas. A herança nativa da conta permaneceu intacta. A conta local descartável foi novamente inativada e desconectada, com credenciais removidas e zero sessões.

A publicação da fonte de homologação aguarda autorização específica. A revisão temporária expirou sem publicar; será reconferida antes de qualquer envio autorizado. Os recursos continuam privados, sem agendas e sem alegação de cobertura diária. A mesa de dados proposta está vazia. Os comprovantes e imagens permanecem fora do Git. O detalhe do ensaio e das etapas pendentes está na [análise de gestão completa](gestao-completa.md). Não houve implantação, inferência adicional ou escrita em Novo Hamburgo.


## Referências escolhidas na conversa — 01/11/2026

O seletor **Arquivos** permite escolher e ordenar até 12 referências do projeto para cada pedido, inclusive arquivos além dos primeiros 12. A mensagem mostra a escolha enviada. A seleção fica gravada na tarefa e nas suas retomadas; uploads e mudanças posteriores valem para pedidos futuros. O usuário pode enviar sem anexos novos, sabendo que isso não apaga informações anteriores da conversa. O limite compartilhado de três imagens/páginas continua explícito.

O envio, a preparação do contexto, a recuperação após recarga e a separação entre dois projetos foram conferidos com CSVs fictícios em instalação temporária sem BI, IA ou consumidores de fila. A interface foi corrigida para manter busca e confirmação acessíveis também em telas baixas. Tour e ajuda acompanham a revisão `2026-11-01.6`. A regressão passou com 1.242 testes em SQLite, 1.245 em PostgreSQL, 202 de frontend, Pint e TypeScript/build; evidências e limites estão na [matriz de gestão](gestao-completa.md). Não foi iniciada nova inferência externa.


## Confirmação dos metadados atuais — 01/11/2026

O catálogo completo e as conferências de agendas, cobertura diária e dependências de exclusão passaram a usar os documentos identificados como sincronizados pelo status oficial do Lumo. Arquivos retidos fora desse conjunto, inclusive outro arquivo com o mesmo ID, não substituem detalhes atuais. Os arquivos permanecem preservados. Foram acrescentados 27 casos de regressão, com validação completa em SQLite/PostgreSQL, 202 testes de frontend e Pint; a ajuda e o tour acompanham a revisão `2026-11-01.7`.

A medição real preservou IDs, quantidades e capacidades dos 13 grupos. A atualização completa ainda leva dezenas de segundos: as alternativas de leitura direcionada não melhoraram o tempo e foram retiradas. A investigação, os tempos e as próximas etapas estão na [matriz de gestão](gestao-completa.md). Essa rodada foi somente de leitura no BI e não iniciou inferência ou implantação.


## Renovação da categoria após salvar — 01/11/2026

Os atalhos para abrir uma conexão, mesa ou agenda salva consultam somente sua categoria e os grupos relacionados necessários. Publicações e exclusões confirmadas também renovam a área correspondente, sem repetir a escrita. As demais quantidades podem voltar a **Consultar**; **Atualizar catálogo** continua renovando todas as áreas. A invalidação no servidor agora inclui os caches de categoria da mesma conexão, sessão e tenant, preservando os arquivos de autoria.

A criação revisada de uma mesa vazia no tenant de teste foi conferida pela interface. Seu resultado abriu após a consulta da seção; a mesa foi depois removida com checkpoint, simulação e conferência de ausência, sem cascata. O inventário preexistente permaneceu igual. A amostra da seção levou cerca de um segundo, sem comprovar um novo tempo para o inventário completo. Tour e ajuda acompanham a revisão **2026-11-01.8**, conferida em computador e celular sem reiniciar o tour já visto.

Passaram 1.269 testes em SQLite, 1.272 em PostgreSQL isolado, 214 de frontend, TypeScript/build e Pint. O atendimento de ajuda foi reconferido com a conversa preservada: o histórico ocupou aproximadamente 439 px no computador e 395 px no celular, mantendo envio visível e rolagem própria. A [matriz de gestão](gestao-completa.md) registra a evidência e os limites. Não houve implantação, inferência ou alteração em ambiente de clientes.


## Editar a identificação de aplicações — 01/11/2026

Em **Catálogo BI → Aplicações → Gerenciar aplicação**, **Editar nome e descrição** consulta a versão atual, mostra a comparação e solicita a decisão antes de salvar. Funciona em rascunhos e aplicações publicadas quando a conta possui autorização nativa; a revisão informa o efeito para a mesa. Dashboards, filtros, fontes e agendas são preservados, e o nome do projeto continua independente. **Ver aplicação atualizada** renova a categoria e abre os detalhes confirmados. Fechar o formulário, sozinho, não equivale a essa consulta.

A conferência posterior usa uma nova leitura e o diff integral. Uma resposta interrompida oferece **Conferir resultado** no formulário e em Atividade, sem repetir a edição nem iniciar IA. O ensaio real alterou e restaurou nome/descrição de uma aplicação privada de teste e de uma aplicação publicada de homologação; a remoção da descrição também foi confirmada. Os checkpoints completos e o inventário original foram preservados. A serialização foi corrigida para distinguir listas/mapas vazios e preparar descrição vazia no formato devolvido pelo BI.

Passaram **1.313 testes em SQLite**, **1.316 em PostgreSQL isolado**, **232 de frontend**, TypeScript/build e Pint. O guia/tour acompanha a revisão **2026-11-01.9**, conferida em computador/celular sem reiniciar os tours já vistos. A [matriz de gestão](gestao-completa.md) registra o detalhe e os limites. Não há migração nova, dependência adicional, publicação BI ou implantação em servidor nesta revisão.


## Configuração privada de variáveis — 02/11/2026

**Catálogo BI → Variáveis → Adicionar variável** e a edição de uma referência permitem configurar descrição, proteção de segredo, valor padrão e valores próprios por agente. Os valores existentes não são exibidos. A revisão mostra o efeito para o tenant e solicita uma decisão antes de salvar; o resultado é conferido por nova leitura e diff. Segredos possuem confirmação de envio separada da leitura, que o BI não permite. Uma resposta incerta não gera reenvio automático; **Conferir resultado** também está em Atividade.

A bateria passou com **1.367 testes em SQLite**, **1.370 em PostgreSQL**, **250 de frontend**, TypeScript/build e Pint. O guia/tour acompanha a revisão **2026-11-02.1**. A [matriz de gestão](gestao-completa.md) detalha a proteção dos valores, os limites e a homologação. Não há migração nem dependência nova. A exclusão de variáveis e a análise de suas dependências continuam fora deste formulário.

A criação/edição de variável comum com valor por agente e a criação/rotação de segredo fictício foram conferidas no BI. Espaços e quebras de linha foram preservados. As duas chaves descartáveis foram removidas após checkpoint e simulação; o documento original e o inventário dos demais recursos foram restaurados/conferidos. Formulário, artigo e Atividade foram inspecionados em computador/celular, preservando os nove registros de progresso de tours existentes. Esse ensaio não publicou recursos BI; a fonte de homologação tem autorização de publicação própria recebida em 02/11/2026 e ainda precisa de reconferência antes da execução.
