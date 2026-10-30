# Entrega local e pendências de homologação

Instalação de desenvolvimento: http://127.0.0.1:8088. Estado conferido em 30/10/2026. O código está preparado para execução Docker; esta entrega não declara a implantação central concluída.

| Entrega | Implementado e verificado | Etapa restante |
|---|---|---|
| Catálogo por tenant | Oito tenants da conta real; 13 tipos Lumo, dashboards, Minha Mesa, mesas, etiquetas, fontes, agendas, metadados e variáveis sem valores | Homologar módulos sem dados/permissão e conta real com acesso restrito |
| ChatGPT | Login oficial OSS local, plano individual, modelos autorizados, inferência remota, Codex e ferramentas reais | Enquadramento/configuração oficial para hospedagem central; repetir no domínio público |
| Criação completa | Clone, manutenção, consultas, criação nova, prévia PNG/interativa, histórico de prévias no projeto, revisão assíncrona, ações manuais de duplicação/exclusão e carga de teste | Ampliar a homologação real da substituição de versões; publicação como nova já autorizada e confirmada no BI |
| Anexos | Extração limitada e privada de texto/CSV/JSON/YAML/MD/PDF/XLSX/imagens; interpretação real de CSV, PDF, XLSX e imagens; consentimento individual de PNG BI | OCR não implementado; validar amostras de cada cliente no piloto |
| Agendamento | Fontes compartilhadas, frequência preservada, duplicação recusada, agenda ativa e execução nova concluída confirmadas | Validar cenários adicionais de fontes privadas com autorização do titular |
| Operação | HTTPS/subpasta/WebSocket em ensaio isolado, backup criptografado/restauração independente, retenção e saúde; testes de propriedade e concorrência | Domínio/certificado públicos, backup externo agendado, alertas e piloto de capacidade com contas independentes |

## Evidências e uso

374 testes PHP e 2.180 verificações aprovados em SQLite e PostgreSQL isolado na revisão local ampliada; 32 testes de frontend e um teste operacional Python aprovados; Pint e build Vue/TypeScript aprovados. O teste do binário Codex com provedor sintético conferiu o catálogo real de ferramentas e a recusa de escrita nativa, sem usar a conta OpenAI. As evidências reais e seus limites estão em [homologação](homologacao.md). Os testes automatizados não usam as contas reais.

As duas conexões foram conferidas no portal. Renovação BI preserva a sessão criptografada. Desconectar cancela revisões pendentes e tarefas pausadas; ações já iniciadas remotamente não são desfeitas. Consultas sem permissão são diferenciadas de listas vazias.

A publicação como nova foi autorizada pelo próprio portal e confirmada na mesa de teste da Inovadora. Uma segunda consulta independente confirmou recurso, nome, quantidade de dashboards e destino. A versão compartilhada permanece vinculada ao projeto. Substituição de uma versão existente passou pelos testes isolados; o ciclo real de substituição ainda precisa de uma revisão e decisão próprias. Uma revisão expirada ou alterada exige nova validação antes da execução.

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
