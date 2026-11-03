# Operação

## Preparação do piloto interno — 03/11/2026

O piloto foi priorizado para cerca de dez pessoas, sem limite fixo de usuários cadastrados. A capacidade simultânea depende do host e das operações. O pacote central inicia com ChatGPT, MCP e visualizador interativo desabilitados até as respectivas configurações e homologações; a integração ChatGPT local não deve ser transferida para um servidor compartilhado. Login local/Suíte e gestão BI não exigem habilitar inferência. Isso é um candidato a piloto interno, não uma declaração de disponibilidade de todas as entregas.

Para preparar uma entrega, primeiro conclua os testes e o commit; então gere somente o código versionado:

```sh
python3 scripts/package-release.py --output /tmp/g-analise-ia-piloto.tar.gz
```

O script exige árvore Git limpa, recusa caminhos privados versionados, dependências de runtime do Composer, links e submódulos; preserva os assets públicos licenciados usados pela interface e cria `.sha256` e `.json` com o commit e o hash. Arquivos ignorados da instalação não são lidos nem copiados. O pacote não contém `.git`, dependências instaladas, conexões, banco ou credenciais de execução. A revisão de segredos do código continua necessária: a verificação de caminhos não é uma análise de todo o conteúdo dos arquivos. Transfira também o checksum, confira-o no destino e extraia a entrega em um diretório próprio. Não envie a pasta de trabalho inteira da máquina local.

Na **primeira instalação**, com o código extraído e Python 3 disponível:

```sh
python3 scripts/prepare-production.py --url https://suite.inovadora.com.br/ia-ganalise --port 8089
```

Escolha uma porta livre e ajuste o proxy para a mesma porta. O gerador cria `.env.production` com permissão 0600 e segredos aleatórios distintos. Recusa substituir qualquer arquivo existente; uma atualização deve preservar a APP_KEY e os dados do ambiente. Ele não inicia serviços, migra bancos, faz login ou habilita a OpenAI. Guarde o arquivo em backup criptografado, fora do repositório.

Depois do build e antes de iniciar a instalação, confira a configuração:

```sh
docker compose --env-file .env.production -f compose.production.yaml run --rm --no-deps app php artisan ganalise:preflight
```

`ganalise:preflight` retorna JSON e código 1 se houver inconsistências: produção/debug/demo, chave de criptografia, URL/asset/subdiretório, cookies, drivers compartilhados, reserva da fila, origens WebSocket, proxy e configurações de integrações habilitadas. Avisos distinguem funcionalidades desligadas de falhas da instalação. Nenhum segredo é exibido, e a conferência de configuração não chama IA, Suíte ou API BI. Resultado positivo não comprova certificado público, autorização do provedor, isolamento do processo, capacidade do host ou backup externo.

Após migrar e iniciar todos os serviços, aguarde a próxima virada de minuto e confira também o runtime:

```sh
docker compose --env-file .env.production -f compose.production.yaml exec -T app php artisan ganalise:preflight --runtime
```

Essa opção confere migrações pendentes, leitura das conexões criptografadas com a APP_KEY atual, assets, permissões de diretórios, executáveis e a saúde dos serviços; não aplica migrações nem regrava credenciais. Se a chave não conseguir ler os registros existentes, recupere a configuração original ou o backup antes de liberar o acesso; não gere outra APP_KEY para tentar corrigir. Verifique os avisos e o endpoint HTTPS final antes de liberar os acessos.

Colaboradores podem entrar pela Suíte, com perfil administrativo conforme o contrato de autenticação. Para criar o primeiro responsável local, se necessário, use o comando interativo abaixo; a senha é solicitada no terminal sem aparecer em argumentos e deve ser trocada no primeiro acesso:

```sh
docker compose --env-file .env.production -f compose.production.yaml exec app php artisan horus:user SEU_EMAIL --name="Responsável pelo piloto" --role=owner
```

Depois, cadastre os demais acessos pela página **Usuários**, sem compartilhar uma conta BI entre pessoas. A primeira instalação utiliza banco vazio: o pacote de código não migra as contas e conexões pessoais usadas na homologação local. Para transferência de dados existente, use o procedimento de backup/restauração e preserve a APP_KEY.

## Critério para liberar o piloto com IA

O piloto solicitado depende da IA. A saúde dos serviços, o CI aprovado e uma instalação Docker com a OpenAI desligada comprovam somente parte da infraestrutura; não comprovam a entrega funcional. Antes de liberar usuários, concluir a habilitação oficial do ChatGPT para o portal central e validar no domínio definitivo login individual, consentimento para uso do plano, resposta real, ferramentas BI, renovação e isolamento entre processos. O envio do formulário de interesse foi informado pelo responsável em 03/11/2026; ainda aguardamos uma configuração aprovada aplicável ao cenário.

A [documentação de autenticação do Codex App Server](https://learn.chatgpt.com/docs/app-server#auth-endpoints) explicita que a autenticação nativa do App Server não é permitida para serviços hospedados. O suporte técnico a código de dispositivo não elimina essa restrição. Não tratar a alteração de flags ou do domínio como homologação do acesso hospedado.

## Instalação central

Use `.env.production.example` como modelo de `.env.production`. Defina APP_KEY, senha PostgreSQL, segredo e chave Reverb; preserve APP_KEY em cofre. Confira APP_URL/ASSET_URL, PUBLIC_HOST, PUBLIC_PATH e SESSION_PATH. Para o domínio previsto: HTTPS, `/ia-ganalise`, cookie seguro e `LOCAL_PREVIEW=false`.

O endpoint interno padrão 8089 já é utilizado por outro projeto nesta máquina. Escolha uma porta disponível no servidor e ajuste `APP_PORT` e `docker/proxy-location.example.conf` juntos. O exemplo de proxy remove o prefixo antes de encaminhar ao Nginx da aplicação. Configure `TRUSTED_PROXIES` somente com os IPs/CIDRs que realmente encaminham ao Nginx interno. Um proxy no host Docker normalmente chega pelo gateway da rede desse projeto; confira o endereço no servidor escolhido. Vazio não confia em cabeçalhos encaminhados. Curingas, `REMOTE_ADDR` e redes `/0` são recusados pelo middleware. O proxy substitui os cabeçalhos de IP/protocolo/porta e o portal não confia em host ou prefixo encaminhado. Não habilite confiança genérica em todos os proxies.

O entrypoint da imagem prepara os diretórios de armazenamento, views e logs com o próprio usuário do serviço e recusa um volume sem escrita. Ele não altera arquivos existentes, gera chaves, aplica migrações ou ajusta permissões com privilégios elevados. A verificação também funciona em um volume vazio após recriar os contêineres.

Antes de migrar, confira o projeto Compose, os nomes físicos dos volumes e o banco de destino. O pacote de produção possui volumes persistentes. Uma atualização preserva os volumes e a APP_KEY.

```sh
docker compose --env-file .env.production -f compose.production.yaml build
docker compose --env-file .env.production -f compose.production.yaml up -d --wait postgres redis
docker compose --env-file .env.production -f compose.production.yaml run --rm app php artisan migrate --force
docker compose --env-file .env.production -f compose.production.yaml up -d
```

Configure certificado válido no proxy HTTPS e o bloco de `docker/proxy-location.example.conf` dentro do server correspondente. O Nginx interno renova os endereços de aplicação e Reverb pelo DNS do Docker, com resolução dinâmica e configuração conferida no build. A descoberta evita manter um IP antigo depois da recriação de um contêiner; não garante atualização sem interrupção. O recurso usa a versão suportada na imagem (Nginx 1.30.6 no ensaio), conforme a [documentação oficial de upstream](https://nginx.org/en/docs/http/ngx_http_upstream_module.html#server). A configuração da OpenAI para o servidor central permanece pendente; não habilite modo OSS local em produção.

## Saúde e filas

```sh
docker compose exec -T app php artisan ganalise:health
docker compose exec -T app php artisan ganalise:backup-state
```

`ganalise:health` retorna JSON/exit code: banco, cache, scheduler, workers e disco. O primeiro heartbeat chega após a próxima virada de minuto. Scheduler/documentos: tolerância 3 minutos; worker de ajuda: 5 minutos, incluindo resposta de até 3 minutos; workers de IA e ações BI: 20 minutos, incluindo execução autorizada de até 15 minutos. A saída inclui tamanho das filas sem conteúdo das tarefas. Monitore exit code e crescimento das filas; `/up` verifica apenas o processo web.

A migração `add_preview_revision_to_projects_table` acrescenta a revisão confirmada e sua data ao projeto. Aplique as migrações antes de iniciar os workers com o código atualizado. Registros anteriores continuam válidos com esses campos vazios; não é necessário exportar imagens ou recriar projetos.

As migrações `create_user_guide_progress_table` e `create_help_questions_table` acrescentam o progresso individual e o histórico do atendimento. Aplique-as antes de iniciar a versão com ajuda. O serviço **actions** consome `actions,default`, **worker** consome `agents` e **documents** consome `documents`. Uma tarefa longa de IA não ocupa o processo de execução das ações manuais. O serviço **help** consome somente a fila `help`, com timeout de 180 segundos e uma tentativa; deve iniciar junto de `worker`, `actions`, `documents` e `scheduler`. A manutenção expira atendimentos interrompidos, sem reenviar perguntas à OpenAI. `ganalise:backup-state` inclui `active_help`; o backup aguarda não haver resposta ativa. A restauração isolada invalida perguntas pendentes.

O acompanhamento de cargas (`ObserveBiLoadExecution`) usa **actions**, incluindo envio, consulta manual, acompanhamento seguinte e manutenção. Assim, a conferência fica no worker BI enquanto agents atende as tarefas de IA. A revisão não exige migração; recarregue os workers de forma graciosa após atualizar o código e confira a saúde. Jobs já gravados no Redis conservam a fila até serem processados; o acompanhamento seguinte usa a definição atual. As filas e as tentativas existentes são preservadas.

A migração `2026_11_01_191249_add_attachment_ids_to_agent_runs_table` acrescenta a seleção de arquivos por tarefa. Aplique-a antes de carregar o código atualizado no web e nas filas. Novos pedidos gravam a seleção no envio, incluindo uma lista vazia; retomadas preservam essa lista. Registros antigos com `null` capturam uma única vez os primeiros 12 anexos disponíveis quando forem utilizados, sem reconstruir uma seleção histórica. A atualização não apaga anexos nem elimina conteúdo já enviado ao contexto de uma conversa. Depois de migrar, recarregue os workers de forma graciosa e confira `ganalise:health`.

Os workers têm `restart: unless-stopped`. Os processos de IA e ações recebem até 990 segundos para encerrar de forma graciosa, acima do timeout de 960 segundos; não reduza esse prazo para matar uma execução durante atualização. O Compose não fixa nomes ou portas para esses workers e permite aumentar suas réplicas com `--scale worker=N --scale actions=N`, conforme capacidade e monitoramento das filas; o padrão inicial é uma réplica por serviço. Alterações no código exigem reiniciar os workers; a fila é preservada. Em produção, use a nova imagem em todos os serviços. Timeout da fila é maior que o turno; ações remotas não têm repetição automática após resultado incerto. Não monte Docker socket no worker.

A leitura completa do catálogo confere o `status --filter clean` da versão homologada do Lumo, com tenant e caminhos relativos dos documentos sincronizados. Formato desconhecido ou identidade divergente impede confirmar detalhes; não substitua a resposta por arquivos antigos nem remova essa conferência para reduzir latência. Arquivos fora de escopo continuam preservados. Cobertura diária e dependências de exclusão usam a mesma leitura confirmada. Essa revisão não exige migração de banco; recarregue os workers após atualizar o código e confira a saúde.

Após alterações no BI, os serviços invalidam o snapshot completo e os caches de categoria da mesma conexão, geração de sessão e tenant. A abertura de um resultado salvo, uma publicação confirmada ou uma exclusão confirmada renova a categoria correspondente; o botão **Atualizar catálogo** continua solicitando todas as áreas. Quantidades de outras áreas podem voltar a **Consultar**. Essa invalidação remove somente chaves de cache e não apaga YAML de autoria. A revisão também não exige migração de banco; use o mesmo procedimento de atualização graciosa dos workers e conferência de saúde.

A edição manual de nome/descrição de aplicações também usa revisões e execução pela fila `actions`. Depois de atualizar o código, recarregue os workers de forma graciosa e confira a saúde; não há migração ou dependência nova nessa revisão. A consulta de resultado em Atividade é somente leitura e não deve ser substituída por um novo envio. Os workspaces temporários de edição/conferência são separados dos projetos existentes e removidos ao terminar; os arquivos de autoria dos projetos não são sobrescritos.

A gestão de variáveis usa o mesmo processo de atualização graciosa dos workers, sem migração nova. Valores digitados ficam em cache criptografado apenas até a decisão/execução ou expiração da revisão; eles não entram no payload público nem no histórico da IA. A configuração completa é recebida em workspace temporário, preservando os projetos existentes. Não repetir uma escrita por falta de resposta: usar a conferência de resultado. O recebimento de segredos precisa de confirmação do envio, pois a leitura do BI é mascarada.

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

Requer Python 3 e OpenSSL no host. O Compose de homologação tem nome próprio, banco/cache e armazenamento compartilhado entre serviços em memória e integrações externas desabilitadas. A porta 8443 precisa estar disponível. Não compartilha volumes com o portal normal.

```sh
python3 scripts/prepare-https-test.py
docker compose --env-file .env.homologacao -f compose.homologacao.yaml build app web
docker compose --env-file .env.homologacao -f compose.homologacao.yaml up -d --wait postgres redis
docker compose --env-file .env.homologacao -f compose.homologacao.yaml run --rm app php artisan migrate --force
docker compose --env-file .env.homologacao -f compose.homologacao.yaml up -d --scale worker=2 --scale actions=2
python3 scripts/test-https.py
python3 scripts/test-team-pilot.py --disposable --realtime
docker compose --env-file .env.homologacao -f compose.homologacao.yaml exec -T app php scripts/test-queues.php --disposable --parallel
docker compose --env-file .env.homologacao -f compose.homologacao.yaml down --volumes
```

A preparação normaliza para desligadas as integrações ChatGPT, uso do plano, Lumo, visualizador, Suíte e MCP, inclusive ao reutilizar `.env.homologacao`; preserva as chaves existentes, corrige a permissão para 0600 e recusa um arquivo de configuração que seja link simbólico. Esse comportamento pertence somente ao ensaio descartável.

Para conferir também a prontidão dos serviços, execute `docker compose --env-file .env.homologacao -f compose.homologacao.yaml exec -T app php artisan ganalise:preflight --runtime` antes de encerrar o ensaio. Aguarde o primeiro ciclo de um minuto do scheduler e a emissão real dos heartbeats dos workers. Uma recusa inicial por heartbeat ausente não deve ser ignorada: repita a leitura depois desse ciclo e investigue os serviços se continuar negativa. A conferência não gera heartbeats nem inicia chamadas externas.

O teste cria uma conta sintética somente após conferir o nome exclusivo do banco efêmero. Verifica certificado, assets, prefixo Inertia, CSRF/login, redirecionamento e sessão autenticada. A senha aleatória não é exibida. Não contorna avisos de certificado do navegador nem instala certificados no sistema. O ensaio concorrente adicional usa dez contas fictícias, alternando autoria e consulta: valida login com CSRF, cookies HTTPS, propriedade de projetos/conversas, recusa entre identidades e os controles de criação de cada perfil. Mede latência somente das rotas locais do portal; não mede APIs BI, inferência ou capacidade de outro servidor. Remove suas próprias contas e arquivos fictícios ao terminar. Opcionalmente, `--pdf-fixture /CAMINHO/documento-digitalizado-ficticio.pdf` verifica upload multipart com CSRF, processamento de três páginas pelo worker, integridade do original e recusa de download por outra identidade, sem envio a modelos. O teste separado de filas executa uma espera sintética de oito segundos em `agents` e verifica se ações, documentos e ajuda concluem durante essa espera, incluindo a leitura de um arquivo privado pelo worker de documentos, sem chamadas externas. Só aceita o banco efêmero e integrações desligadas, usa chaves próprias com expiração e não limpa outras filas. Encerrar o Compose descarta o banco/cache de ensaio.


## Homologação da fonte e compilação local — 02/11/2026

A fonte sintética de permissões no tenant de teste foi publicada com autorização específica e possui uma única agenda ativa diária às 03h de Brasília. Uma carga completa terminou com sucesso, com identificador conferido e nova leitura dos seis registros. A aplicação de teste segue privada e as restrições entre mesas/filtros por linha não devem ser apresentadas como homologadas. A sessão administrativa expirou; nenhuma permissão nativa foi alterada nesta rodada. O perfil local da segunda identidade terminou novamente inativo e desconectado, sem credenciais. Os identificadores e o alcance da prova constam na análise de gestão completa; os recibos e dados continuam fora do Git.

No HTTP local, usar **`npm run build:local`**, como nos scripts de preparação e no CI. `npm run build` lê a configuração de produção; neste ambiente ela aponta para o ensaio HTTPS em outro endereço e subdiretório. Um build direcionado ao endereço errado deixou módulos de páginas indisponíveis e a tela em branco; a compilação local foi restaurada e a página de login voltou a renderizar sem erros de JavaScript na conferência do navegador. Isso não constitui implantação nem homologação do domínio público. A configuração final de produção precisa corresponder ao endereço que será efetivamente publicado.

Publicações novas de aplicações passam a conferir também o conteúdo revisado e os vínculos dos dashboards, com recibo explícito. Uma divergência permanece pendente e deve ser investigada por leitura; não reenviar automaticamente. O Lumo instalado não oferece `--dry-run` para `app publish`; a preparação usa plano, validação, comparação remota e checkpoint, mantendo a decisão de publicação separada.


## Logs HTTP no piloto

O Nginx interno registra JSON com horário, método, caminho original sem query string, status, duração, status/tempo do upstream e bytes. Não inclui cabeçalhos, corpo, cookies, referer ou parâmetros de retorno de autenticação. A configuração usa `escape=json`, conforme a [referência do Nginx](https://nginx.org/en/docs/http/ngx_http_log_module.html#log_format). O exemplo de proxy externo desativa o access log somente no subdiretório do produto, delegando a observação HTTP ao web interno. Adapte também outros proxies ou balanceadores existentes: a configuração deste projeto não controla seus logs.

O error log do Nginx nesses contextos usa nível `crit`, para não repetir a URI completa nas mensagens comuns de erro do upstream. A contrapartida é menor detalhe nesses erros: acompanhe status, duração e upstream no JSON e os logs da aplicação. Falhas críticas de processo e ferramentas externas exigem análise própria antes de compartilhar diagnósticos. A configuração não apaga registros antigos.

No Compose de produção, cada serviço usa rotação Docker `json-file`, com até três arquivos de 10 MB por contêiner. O log Laravel permanece diário, com 14 dias no exemplo de produção. O limite Docker é por volume de log, não por dias; retenção de anexos, backups e auditoria do banco continua sendo uma política separada. Essas opções entram em vigor ao criar/recriar os contêineres.

Após compilar a imagem, `python3 scripts/test-web-privacy.py --image horus-ia-web:production` testa GET/POST, parâmetros, cabeçalhos, corpo e falha de upstream usando somente marcadores fictícios. Ele cria contêineres efêmeros sem rede, portas públicas, aplicação ou banco e os remove ao terminar. O CI executa essa mesma conferência.

O ensaio HTTPS também envia um retorno de autenticação com código/estado fictícios, confere o redirecionamento no subdiretório e procura seus marcadores e a senha sintética nos logs de TLS, web e PHP-FPM. A preparação do certificado registra o UID/GID do operador para o contêiner TLS poder ler sua chave privada; não altera as chaves existentes. Esse ambiente e os testes de filas passam a ser executados no CI, separados da instalação principal.

O armazenamento em memória do ensaio usa `volume.nocopy`: os processos preparam os diretórios pelo entrypoint, com o UID já configurado no tmpfs. Isso evita a cópia simultânea da mesma árvore de `storage` pelo Docker ao criar vários contêineres. A instalação de produção continua usando volume persistente e a inicialização por migração documentada antes de subir os consumidores.


## Limites de upload

A imagem PHP usa `upload_max_filesize=10M` e `post_max_size=11M`, alinhados ao limite de 10 MB por arquivo no portal e à margem multipart do Nginx. A memória PHP não foi ampliada. Recompile a imagem e recrie o serviço web para aplicar a configuração; uma troca apenas dos arquivos PHP não muda as diretivas já carregadas pelo processo. O frontend informa o excesso antes do envio e o backend mantém sua própria validação.

O CI envia um texto inteiramente fictício com exatamente 10 MiB pelo HTTPS local, usando `scripts/test-team-pilot.py --disposable --text-fixture CAMINHO`. A preparação deve concluir com aviso de conteúdo limitado; o download do original precisa preservar o hash e ser recusado para outra identidade. O parâmetro alternativo `--pdf-fixture` continua disponível para conferir até três páginas visuais de um PDF digitalizado fictício. Esses ensaios não enviam anexos à IA.


O parâmetro **`--realtime`** acrescenta dez assinaturas reais de canais privados pelo HTTPS/Reverb. Cada conta autoriza seu canal e recebe 403 para o canal de outra; o servidor WebSocket também precisa recusar uma assinatura reaproveitada em outro canal ou socket. Eventos `WorkspaceChanged` inteiramente fictícios são emitidos pelo aplicativo do ensaio e conferidos por identidade. As autorizações não podem aparecer nos logs de TLS/web/PHP/Reverb. O resultado não equivale a consultas BI ou inferências concorrentes.

O parâmetro **`--parallel`** do teste de filas exige pelo menos duas réplicas de `worker` e de `actions`. Tarefas fictícias precisam se sobrepor em processos distintos, enquanto documentos e ajuda continuam disponíveis. As réplicas são aumentadas somente no Compose descartável acima; o padrão de produção continua conservador e pode ser ajustado pelo operador conforme memória, filas e homologação do ambiente. Esses números de réplica não limitam o cadastro ou o número de usuários do produto.


## Intermediador de inferência em desenvolvimento

A rota `/internal/inference/v1/responses` é uma base para o executor isolado e permanece desligada por padrão (`AGENT_INFERENCE_ENABLED=false`). Não habilitar essa função no serviço público. Quando houver um serviço privado homologado, seu proxy deverá acrescentar `X-G-Analise-Inference` com o segredo `AGENT_INFERENCE_SECRET` (32 a 256 caracteres); o executor recebe somente uma autorização temporária da tarefa no bearer. O segredo do gateway e o token OAuth do usuário não devem entrar no ambiente do executor. Não existe rota HTTP para emitir autorizações temporárias.

O limite interno padrão é 48 MiB (`CODEX_GATEWAY_MAX_BYTES`), para acomodar imagens autorizadas sem ampliar os uploads públicos. O serviço privado também precisará de configuração própria de PHP/Nginx e memória compatível: o limite de aplicação não substitui `post_max_size` nem `client_max_body_size`. As definições atuais de produção continuam inalteradas nesse aspecto e ainda não provisionam o transporte privado. O orçamento de 64 chamadas por tarefa (`CODEX_GATEWAY_MAX_REQUESTS`) é um limite operacional, não uma leitura do saldo do plano ChatGPT.

Os testes automatizados desta base usam respostas e credenciais fictícias. Eles não homologam o login hospedado, não demonstram autorização da OpenAI e não tornam a instalação apta para um piloto com IA sem a integração e os ensaios completos descritos acima.
