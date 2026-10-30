# Homologação local — 29/10/2026

Os testes automatizados usam SQLite em memória, cache em memória e HTTP simulado. Não acessam tenants reais. A homologação integrada abaixo foi realizada separadamente com as contas conectadas pelo titular.

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
