# Operação

## Instalação central

Use `.env.production.example` como modelo de `.env.production`. Defina APP_KEY, senha PostgreSQL, segredo e chave Reverb; preserve APP_KEY em cofre. Confira APP_URL/ASSET_URL, PUBLIC_HOST, PUBLIC_PATH e SESSION_PATH. Para o domínio previsto: HTTPS, `/ia-ganalise`, cookie seguro e `LOCAL_PREVIEW=false`.

O endpoint interno padrão 8089 já é utilizado por outro projeto nesta máquina. Escolha uma porta disponível no servidor e ajuste `APP_PORT` e `docker/proxy-location.example.conf` juntos. O exemplo de proxy remove o prefixo antes de encaminhar ao Nginx da aplicação. Não habilite confiança genérica em todos os proxies.

Antes de migrar, confira o projeto Compose, os nomes físicos dos volumes e o banco de destino. O pacote de produção possui volumes persistentes. Uma atualização preserva os volumes e a APP_KEY.

```sh
docker compose --env-file .env.production -f compose.production.yaml build
docker compose --env-file .env.production -f compose.production.yaml up -d postgres redis
docker compose --env-file .env.production -f compose.production.yaml run --rm app php artisan migrate --force
docker compose --env-file .env.production -f compose.production.yaml up -d
```

Configure certificado válido no proxy HTTPS e o bloco de `docker/proxy-location.example.conf` dentro do server correspondente. A configuração da OpenAI para o servidor central permanece pendente; não habilite modo OSS local em produção.

## Saúde e filas

```sh
docker compose exec -T app php artisan ganalise:health
docker compose exec -T app php artisan ganalise:backup-state
```

`ganalise:health` retorna JSON/exit code: banco, cache, scheduler, workers e disco. O primeiro heartbeat chega após a próxima virada de minuto. Scheduler/documentos: tolerância 3 minutos; worker de ajuda: 5 minutos, incluindo resposta de até 3 minutos; worker IA: 20 minutos, incluindo turno autorizado de até 15 minutos. A saída inclui tamanho das filas sem conteúdo das tarefas. Monitore exit code e crescimento das filas; `/up` verifica apenas o processo web.

A migração `add_preview_revision_to_projects_table` acrescenta a revisão confirmada e sua data ao projeto. Aplique as migrações antes de iniciar os workers com o código atualizado. Registros anteriores continuam válidos com esses campos vazios; não é necessário exportar imagens ou recriar projetos.

As migrações `create_user_guide_progress_table` e `create_help_questions_table` acrescentam o progresso individual e o histórico do atendimento. Aplique-as antes de iniciar a versão com ajuda. O serviço **help** consome somente a fila `help`, com timeout de 180 segundos e uma tentativa; deve iniciar junto de `worker`, `documents` e `scheduler`. A manutenção expira atendimentos interrompidos, sem reenviar perguntas à OpenAI. `ganalise:backup-state` inclui `active_help`; o backup aguarda não haver resposta ativa. A restauração isolada invalida perguntas pendentes.

A migração `2026_11_01_191249_add_attachment_ids_to_agent_runs_table` acrescenta a seleção de arquivos por tarefa. Aplique-a antes de carregar o código atualizado no web e nas filas. Novos pedidos gravam a seleção no envio, incluindo uma lista vazia; retomadas preservam essa lista. Registros antigos com `null` capturam uma única vez os primeiros 12 anexos disponíveis quando forem utilizados, sem reconstruir uma seleção histórica. A atualização não apaga anexos nem elimina conteúdo já enviado ao contexto de uma conversa. Depois de migrar, recarregue os workers de forma graciosa e confira `ganalise:health`.

Os workers têm `restart: unless-stopped`. Alterações no código exigem reiniciar os workers; a fila é preservada. Em produção, use a nova imagem em todos os serviços. Timeout da fila é maior que o turno; ações remotas não têm repetição automática após resultado incerto. Não monte Docker socket no worker.

A leitura completa do catálogo confere o `status --filter clean` da versão homologada do Lumo, com tenant e caminhos relativos dos documentos sincronizados. Formato desconhecido ou identidade divergente impede confirmar detalhes; não substitua a resposta por arquivos antigos nem remova essa conferência para reduzir latência. Arquivos fora de escopo continuam preservados. Cobertura diária e dependências de exclusão usam a mesma leitura confirmada. Essa revisão não exige migração de banco; recarregue os workers após atualizar o código e confira a saúde.

Após alterações no BI, os serviços invalidam o snapshot completo e os caches de categoria da mesma conexão, geração de sessão e tenant. A abertura de um resultado salvo, uma publicação confirmada ou uma exclusão confirmada renova a categoria correspondente; o botão **Atualizar catálogo** continua solicitando todas as áreas. Quantidades de outras áreas podem voltar a **Consultar**. Essa invalidação remove somente chaves de cache e não apaga YAML de autoria. A revisão também não exige migração de banco; use o mesmo procedimento de atualização graciosa dos workers e conferência de saúde.

## Sandbox do Codex e limites do host

A imagem inclui `codex-resources` da mesma distribuição oficial fixada do Codex, incluindo seu auxiliar `bwrap`; não instala um pacote adicional nem baixa um auxiliar de outra origem. O build e `scripts/codex-smoke.php` verificam que o auxiliar executa. O smoke faz initialize/thread/start sem solicitar inferência e não verifica o isolamento completo de um usuário. Falhas do auxiliar ou do protocolo encerram o comando com código 1, para interromper o CI.

Em 01/11/2026 o ensaio em contêiner descartável, sem rede, volumes ou credenciais, confirmou que este host Docker recusa a criação dos namespaces exigidos pelo sandbox nativo. A presença do auxiliar não resolve essa restrição do host. Não usar `privileged`, `seccomp=unconfined`, capacidades extras ou fallback sem sandbox para contorná-la. Os comandos e pedidos de permissões nativos continuam desabilitados/recusados pelo portal.

O processo da aplicação ainda não tem isolamento completo de leitura de arquivos por identidade demonstrado no sistema operacional. Antes de uma oferta central, homologar uma fronteira adequada para os processos por usuário e as restrições do host escolhido, além dos testes lógicos de propriedade/projeto. O sucesso de `ganalise:health`, do protocolo ou das recusas de escrita não substitui essa evidência. Não há implantação pública nesta etapa.

## Backup criptografado

Requer Python 3, Docker e imagem oficial Restic 0.19.1. Prepare um arquivo de senha 0600 em cofre, separado do repositório; nunca coloque seu conteúdo na linha de comando. Repositório e arquivos de senha reais não entram no Git.

```sh
python3 scripts/backup.py backup --repository /CAMINHO/backup-criptografado --password-file /CAMINHO/senha-0600
python3 scripts/backup.py restore-test --repository /CAMINHO/backup-criptografado --password-file /CAMINHO/senha-0600
python3 scripts/backup.py prune --repository /CAMINHO/backup-criptografado --password-file /CAMINHO/senha-0600
```

Para a instalação central acrescente `--env-file .env.production --compose-file compose.production.yaml`. Confira também `--database` e `--db-user` se alterados.

O backup verifica tarefas ativas, usa manutenção breve para copiar banco/arquivos/configuração e depois libera o portal. Inclui dump PostgreSQL, armazenamento privado e configuração com APP_KEY em snapshot criptografado. Sessões temporárias/cache de catálogo são excluídos. Depois verifica todos os dados do repositório. Falha de leitura não é aceita como backup bem-sucedido.

`restore-test` restaura em diretório novo e banco `ganalise_restore_<id>` independente. Verifica arquivos referenciados e descriptografia; desabilita integrações e expira tarefas da cópia; remove o banco de ensaio ao terminar. Nunca substitui o banco em uso. Ele valida recuperabilidade, não realiza failover de produção.

`prune` simula por padrão. `--apply-retention` aplica 7 diários, 4 semanais e 6 mensais, somente com tag/host do produto. Faça cópia externa do repositório criptografado e guarde a senha em local independente: um backup apenas no disco do servidor não cobre perda do host. Configure o agendamento no host após escolher o destino externo. Um mutex impede duas operações simultâneas.

## Restauração em incidente

1. Isolar a instalação e parar workers/scheduler; preservar cópia do estado atual.
2. Conferir snapshot, senha, APP_KEY e versão do código; executar `restore-test` primeiro.
3. Recuperar snapshot com Restic em diretório novo, verificar seu bundle `snapshot.tar` e extrair o dump/arquivos/configuração.
4. Criar nova instalação com banco e volumes vazios, usando a APP_KEY recuperada. Restaurar o dump nesse banco novo e arquivos no armazenamento privado.
5. Manter integrações e workers desabilitados; expirar tarefas/revisões antigas e exigir reconexão das contas. Conferir migrações, propriedade dos arquivos, login, prévias e saúde.
6. Somente depois da validação e autorização operacional trocar o proxy para a instalação recuperada. Não repetir ações remotas que possam já ter ocorrido antes do incidente.

## Retenção

Conversas, projetos, anexos originais e histórico da ajuda são preservados. O titular pode apagar seu histórico pessoal pela própria ajuda; essa ação não afeta as conversas dos projetos. Exportações PNG geradas: 30 dias; eventos de auditoria: 180 dias; logs diários: 14 dias. Configure `EXPORT_RETENTION_DAYS`, `AUDIT_RETENTION_DAYS` e `LOG_DAILY_DAYS`. Valor zero desliga a expiração da categoria. A retenção local não apaga recursos no BI.

```sh
docker compose exec -T app php artisan ganalise:retention
docker compose exec -T app php artisan ganalise:retention --apply
```

A primeira opção simula; a segunda aplica a política. O scheduler aplica a política às 03h40 de Brasília, apenas em arquivos gerados cujos caminhos pertencem ao usuário/projeto. Defina retenção adicional de conversas/anexos conforme a política da instalação antes do piloto.

## Antes do piloto

Confirmar enquadramento OpenAI remoto, certificado público, proxy/cookies/WebSocket no domínio final, backup externo agendado, alerta de saúde, capacidade/limites do host e piloto com pelo menos dois usuários e contas BI restritas. Esses itens dependem da infraestrutura e acessos escolhidos; a homologação local não declara a implantação pública concluída.


## Reproduzir o ensaio HTTPS local

Requer Python 3 e OpenSSL no host. O Compose de homologação tem nome próprio, banco/cache em memória e integrações externas desabilitadas. A porta 8443 precisa estar disponível. Não compartilha volumes com o portal normal.

```sh
python3 scripts/prepare-https-test.py
docker compose --env-file .env.homologacao -f compose.homologacao.yaml build app web
docker compose --env-file .env.homologacao -f compose.homologacao.yaml up -d
docker compose --env-file .env.homologacao -f compose.homologacao.yaml exec -T app php artisan migrate --force
python3 scripts/test-https.py
docker compose --env-file .env.homologacao -f compose.homologacao.yaml down
```

O teste cria uma conta sintética somente após conferir o nome exclusivo do banco efêmero. Verifica certificado, assets, prefixo Inertia, CSRF/login, redirecionamento e sessão autenticada. A senha aleatória não é exibida. Não contorna avisos de certificado do navegador nem instala certificados no sistema. Encerrar o Compose descarta o banco/cache de ensaio.
