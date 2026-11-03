# G-Analise IA

Repositório oficial: [raafaelb/g-analise-ia](https://github.com/raafaelb/g-analise-ia).

Produto gratuito e aberto da Inovadora, complementar ao G-Analise BI. PHP 8.5/Laravel 13, Vue 3/TypeScript/Inertia, PostgreSQL 17, Redis 7 e Reverb. O worker orquestra os clientes oficiais Codex e Lumo; **a inferência ocorre na OpenAI**, usando a autorização do plano individual. Não há modelo local, API key do usuário ou conta compartilhada de IA.

## Estado atual

- Login ChatGPT OSS **homologado localmente**: identidade, autorização do plano, catálogo de modelos, resposta completa, conversa Codex, ferramentas e análise de imagem autorizada.
- Login de colaboradores pela Suíte e cadastro administrativo de acessos locais, com senha inicial obrigatoriamente substituída e revogação de sessões. O login positivo real foi homologado pelo titular.
- Login G-Analise BI, descoberta de todos os tenants e renovação nativa da sessão homologados. Senha não é armazenada; tokens ficam criptografados.
- Formulários de criação/edição de conexões para oito tipos de banco, revisão protegida, teste de conectividade e consulta limitada de estrutura. Criação/retomada/limpeza real de conexões descartáveis homologadas na Inovadora.
- Catálogo por tenant: aplicações, Minha Mesa, dashboards, mesas BI/DW, tabelas, ETL, agendas, agentes, apresentações, variáveis protegidas e tags. Falta de permissão é diferente de lista vazia. Credenciais são exibidas apenas com dados seguros, sem valores secretos.
- Tabelas: edição de nomes, descrições e formatos numéricos por formulário, com revisão humana e leitura de confirmação; preservação de estrutura, dados e configurações fora da revisão.
- Criação/clone de rascunhos, edição YAML, revisão, sincronização, consultas e PNG real. Exclusão limitada aos rascunhos registrados como criados pelo próprio projeto. A aplicação original não é excluída.
- Gestão manual de aplicações: duplicar com nome confirmado, excluir somente rascunhos registrados e publicar com mesa nomeada e efeito explícito. Publicação como nova já homologada na conta real; substituição ainda depende do ciclo real correspondente.
- Prévia PNG com histórico por projeto/dashboard e acompanhamento de novas versões. Visualizador nativo isolado no Chrome compatível, com alternativa PNG.
- Atualização da autoria com **Atualizar do BI**, preservando edições locais e revisões pendentes. Conferência de publicações com resultado incerto por leitura remota, sem repetir a operação.
- Painel de plano/limites informa somente o que o provedor disponibiliza; o fluxo OSS testado não retornou saldo. A interface oferece acesso ao uso no ChatGPT.
- Revisão humana dentro da conversa, com cartão destacado, aviso fixo, proposta e botões Autorizar/Recusar. No fluxo assíncrono, o worker é liberado enquanto aguarda decisão; a ação aprovada executa uma vez e a IA retoma com o resultado confirmado. Publicação depende da revisão concreta e das fontes verificadas.
- Boas-vindas e tours guiados por usuário/página, com repetição manual, guias pesquisáveis e atendimento sobre o próprio produto usando o plano ChatGPT. Histórico pessoal e fila própria, sem executar ações BI na ajuda.
- Anexos privados: texto/CSV/JSON/YAML/Markdown, XLSX, PDF com texto e PNG/JPEG. Limites explícitos; PDFs digitalizados podem ter até três páginas convertidas em imagens localmente, com limite e confirmação antes do envio visual; uma resposta real conferiu valores e um código presentes apenas nessas páginas fictícias.
- Editor de agendas no catálogo: fontes por nome, agente, ordem, seis tipos de gatilho, avisos, revisão e confirmação de gravação. Criação e edição inativas homologadas; salvar não solicita carga de teste.
- Cobertura das fontes, preservação de agendas mais frequentes e identificação de carga concluída. A ativação de uma segunda agenda para uma fonte já agendada é bloqueada no servidor. A solicitação/enfileiramento não é sucesso. Nova publicação de aplicação gerenciada é bloqueada enquanto faltar a verificação das cargas.
- Imagens imutáveis PHP-FPM/Nginx, caminho `/ia-ganalise`, HTTPS/WebSocket testados localmente, backup criptografado com restauração isolada, retenção e monitoramento por heartbeat.

**O servidor central ainda depende da configuração/elegibilidade oficial da OpenAI para aplicações hospedadas remotamente.** O modo OSS local não é habilitado no servidor central. Não foi feita implantação pública. Consulte [entregas e pendências](docs/entrega-local.md), [autenticação](docs/autenticacao-chatgpt.md), [homologação](docs/homologacao.md), [arquitetura](docs/arquitetura.md) e [operação](docs/operacao.md).

## Piloto interno — 03/11/2026

A preparação prioriza instalação, autenticação, gestão BI, revisões e uso por aproximadamente dez pessoas, sem teto fixo de usuários. Ações BI, IA, documentos e ajuda têm workers separados. A imagem prepara seus diretórios privados ao iniciar; configuração nova, conferência de chaves/URLs/cookies e pacote de código versionado possuem comandos próprios em [operação](docs/operacao.md). Não transfira a pasta de execução do desenvolvedor para o servidor.

O ensaio HTTPS descartável verificou dez sessões simultâneas com perfis de autoria/consulta e isolamento de projetos/conversas. Isso não homologa APIs BI ou inferência simultânea, nem a capacidade do servidor escolhido. O candidato central mantém integrações que exigem configuração/homologação desabilitadas; a IA local não habilita automaticamente uso remoto.

## Instalação local

Requisitos: Docker Engine/Compose v2, Linux x86_64 e acesso às dependências oficiais. Não precisa de PHP ou Node no host. Ajuste `LOCAL_UID/LOCAL_GID` antes de instalar, se necessário.

```sh
git clone --branch feature/gestao-completa --single-branch https://github.com/raafaelb/g-analise-ia.git
cd g-analise-ia
./scripts/setup.sh
docker compose exec app php artisan horus:user seu@email.com --name="Seu nome" --role=owner
```

O comando solicita uma senha de pelo menos 12 caracteres, com maiúsculas, minúsculas e números, sem exibi-la. A troca é obrigatória no próximo acesso. Perfis: owner, admin, editor e viewer (`author` antigo é aceito como alias de editor). O comando preserva identidades gerenciadas pela Suíte; redefinir um acesso local encerra sessões e tarefas anteriores. Cadastro público vem desabilitado. Abra **http://127.0.0.1:8088** para testar ChatGPT local. No ambiente local OSS, páginas abertas em `localhost` redirecionam para a origem de loopback configurada no callback; cookies não são transferidos entre os endereços.

O modo de demonstração `LOCAL_PREVIEW=true` abre uma conta de exemplo somente em ambiente local. Desative para validar contas independentes. Nunca exponha esse modo publicamente.

```sh
docker compose ps
docker compose up -d app worker actions help documents scheduler reverb postgres redis
docker compose stop
```

Não use `down -v` numa atualização: os volumes contêm banco e fila. PostgreSQL/Redis não publicam portas. Para editar frontend com Vite: `docker compose --profile dev up -d frontend`.

## Conexões

G-Analise: habilite `LUMO_LOGIN_ENABLED=true`, confira o endpoint oficial e entre pelo formulário Conexões. Cada pessoa usa sua conta e 2FA quando aplicável. A permissão Uso do Lumo CLI precisa estar habilitada no BI. Não importe sessões do computador do desenvolvedor.

ChatGPT local: use `APP_ENV=local`, `OPENAI_AUTH_MODE=oss_local`, `OPENAI_SIGN_IN_ENABLED=true` e `OPENAI_LOCAL_REDIRECT_URI=http://127.0.0.1:8088/auth/openai/callback`. Autorize a conta pela página oficial. O portal preserva o cliente dinâmico por usuário e o identificador estável da instalação. Modelos e limites dependem da conta; não há acesso ilimitado garantido.

Para hospedagem central, mantenha `OPENAI_AUTH_MODE=hosted` e a integração desabilitada até receber a configuração oficial compatível com uso do plano. O [formulário e os dados necessários](docs/solicitacao-openai.md) estão documentados.

## Acesso pela Suíte e cadastros locais

A entrada no portal usa e-mail/senha local ou **Entrar com a Suíte** para colaboradores da Inovadora. O backend valida a senha diretamente na API HTTPS configurada em `SUITE_BASE_URL`, sem persistir a senha da Suíte. Uma resposta positiva e a identidade canônica criam/vinculam a conta local com perfil de administrador. O ChatGPT é conectado somente depois de entrar no portal, em **Conexões**; ele não autentica nem troca o usuário do portal. Cada pessoa continua conectando suas próprias contas ChatGPT e G-Analise BI; o perfil do portal não concede novas permissões no BI.

Em **Usuários e acessos**, administradores cadastram acessos locais, atribuem perfil, ativam/inativam e redefinem uma senha inicial. A primeira entrada exige troca da senha antes de liberar projetos e conexões. Inativação e mudanças de acesso revogam sessões locais e tarefas pendentes. O login real pela Suíte foi concluído pelo titular e confirmado no banco/auditoria com perfil administrativo. O contrato e as falhas também estão cobertos com respostas simuladas. Consulte [os detalhes da integração](docs/gestao-completa.md).

## Validação

```sh
docker compose exec app php artisan test --compact
docker compose exec app vendor/bin/pint --dirty --format agent
docker compose run --rm --no-deps frontend npm run test:frontend
docker compose run --rm frontend npm run build:local
docker compose exec app php scripts/codex-smoke.php
docker compose exec app php artisan ganalise:health
```

A revisão de 03/11/2026 passou com 1.597 testes PHP / 12.319 asserções em SQLite em memória (mais três casos exclusivos de PostgreSQL omitidos), 1.600 testes / 12.367 asserções em PostgreSQL 17 descartável no CI, 312 testes de frontend e 13 testes operacionais Python. O ensaio HTTPS verifica também o retorno de autenticação no subdiretório e a ausência de dados fictícios de login nos logs dos contêineres. A conferência isolada do Nginx cobre GET/POST, falhas de upstream e o exemplo de proxy externo. As suítes usam fakes HTTP e dados sintéticos, sem alterar o banco da instalação nem chamar contas reais. O smoke verifica o protocolo instalado sem inferência. `build:local` evita que `.env.production` altere os caminhos do frontend de desenvolvimento. A imagem de produção usa `npm run build`, com configuração de assets no build.

Codex está fixado em 0.165.0 e Lumo em 3.44.0. O build confere o checksum publicado pelo fornecedor, um SHA-256 fixado no código e a versão do Lumo; se o canal oficial mudar a versão, atualize a pinagem após homologar, em vez de aceitar uma versão diferente silenciosamente.

Licença do código: MIT. OpenAI, Lumo e dependências mantêm seus termos e licenças.

Para repetir no PostgreSQL descartável (sem portas públicas ou volume persistente):

```sh
docker compose -f compose.yaml -f compose.tests.yaml up -d --wait postgres-test
docker compose -f compose.yaml -f compose.tests.yaml run --rm --no-deps app php scripts/test-postgres.php
docker compose -f compose.yaml -f compose.tests.yaml rm -sf postgres-test
```

O runner fixa `postgres-test / ganalise_test`, força os parâmetros de teste e elimina o XML temporário. Ele não aceita uma URL de banco de produção.

O CI mantém cache das camadas de ferramentas homologadas e carrega a imagem somente no runner para executar os testes. O checksum e a versão continuam fixados; o cache não autoriza aceitar um pacote novo do canal oficial. Sem cache compatível, uma mudança do fornecedor exige nova homologação.
