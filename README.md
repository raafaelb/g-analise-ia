# G-Analise IA

Repositório oficial: [raafaelb/g-analise-ia](https://github.com/raafaelb/g-analise-ia).

Produto gratuito e aberto da Inovadora, complementar ao G-Analise BI. PHP 8.5/Laravel 13, Vue 3/TypeScript/Inertia, PostgreSQL 17, Redis 7 e Reverb. O worker orquestra os clientes oficiais Codex e Lumo; **a inferência ocorre na OpenAI**, usando a autorização do plano individual. Não há modelo local, API key do usuário ou conta compartilhada de IA.

## Estado atual

- Login ChatGPT OSS **homologado localmente**: identidade, autorização do plano, catálogo de modelos, resposta completa, conversa Codex, ferramentas e análise de imagem autorizada.
- Login G-Analise BI, descoberta de todos os tenants e renovação nativa da sessão homologados. Senha não é armazenada; tokens ficam criptografados.
- Catálogo por tenant: aplicações, Minha Mesa, dashboards, mesas BI/DW, tabelas, ETL, agendas, agentes, apresentações, variáveis protegidas e tags. Falta de permissão é diferente de lista vazia. Credenciais são exibidas apenas com dados seguros, sem valores secretos.
- Criação/clone de rascunhos, edição YAML, revisão, sincronização, consultas e PNG real. Exclusão limitada aos rascunhos registrados como criados pelo próprio projeto. A aplicação original não é excluída.
- Revisão humana assíncrona: o worker é liberado enquanto aguarda decisão; a ação aprovada executa uma vez e a IA retoma com o resultado confirmado. Publicação depende da revisão concreta e das fontes verificadas.
- Anexos privados: texto/CSV/JSON/YAML/Markdown, XLSX, PDF com texto e PNG/JPEG. Limites explícitos; PDFs digitalizados ainda exigem OCR ou envio de imagem.
- Cobertura das fontes, preservação de agendas mais frequentes e identificação de carga concluída. A ativação de uma segunda agenda para uma fonte já agendada é bloqueada no servidor. A solicitação/enfileiramento não é sucesso. Nova publicação de aplicação gerenciada é bloqueada enquanto faltar a verificação das cargas.
- Imagens imutáveis PHP-FPM/Nginx, caminho `/ia-ganalise`, HTTPS/WebSocket testados localmente, backup criptografado com restauração isolada, retenção e monitoramento por heartbeat.

**O servidor central ainda depende da configuração/elegibilidade oficial da OpenAI para aplicações hospedadas remotamente.** O modo OSS local não é habilitado no servidor central. Não foi feita implantação pública. Consulte [entregas e pendências](docs/entrega-local.md), [autenticação](docs/autenticacao-chatgpt.md), [homologação](docs/homologacao.md), [arquitetura](docs/arquitetura.md) e [operação](docs/operacao.md).

## Instalação local

Requisitos: Docker Engine/Compose v2, Linux x86_64 e acesso às dependências oficiais. Não precisa de PHP ou Node no host. Ajuste `LOCAL_UID/LOCAL_GID` antes de instalar, se necessário.

```sh
git clone https://github.com/raafaelb/g-analise-ia.git
cd g-analise-ia
./scripts/setup.sh
docker compose exec app php artisan horus:user seu@email.com --name="Seu nome" --role=owner
```

O comando solicita uma senha de pelo menos 12 caracteres sem exibi-la. Perfis: owner, author e viewer. Cadastro público vem desabilitado. Abra **http://127.0.0.1:8088** para testar ChatGPT local; `localhost` também atende ao portal, mas o callback OSS exige `127.0.0.1`.

O modo de demonstração `LOCAL_PREVIEW=true` abre uma conta de exemplo somente em ambiente local. Desative para validar contas independentes. Nunca exponha esse modo publicamente.

```sh
docker compose ps
docker compose up -d app worker documents scheduler reverb postgres redis
docker compose stop
```

Não use `down -v` numa atualização: os volumes contêm banco e fila. PostgreSQL/Redis não publicam portas. Para editar frontend com Vite: `docker compose --profile dev up -d frontend`.

## Conexões

G-Analise: habilite `LUMO_LOGIN_ENABLED=true`, confira o endpoint oficial e entre pelo formulário Conexões. Cada pessoa usa sua conta e 2FA quando aplicável. A permissão Uso do Lumo CLI precisa estar habilitada no BI. Não importe sessões do computador do desenvolvedor.

ChatGPT local: use `APP_ENV=local`, `OPENAI_AUTH_MODE=oss_local`, `OPENAI_SIGN_IN_ENABLED=true` e `OPENAI_LOCAL_REDIRECT_URI=http://127.0.0.1:8088/auth/openai/callback`. Autorize a conta pela página oficial. O portal preserva o cliente dinâmico por usuário e o identificador estável da instalação. Modelos e limites dependem da conta; não há acesso ilimitado garantido.

Para hospedagem central, mantenha `OPENAI_AUTH_MODE=hosted` e a integração desabilitada até receber a configuração oficial compatível com uso do plano. O [formulário e os dados necessários](docs/solicitacao-openai.md) estão documentados.

## Validação

```sh
docker compose exec app php artisan test --compact
docker compose exec app vendor/bin/pint --test
docker compose run --rm frontend npm run build:local
docker compose exec app php scripts/codex-smoke.php
docker compose exec app php artisan ganalise:health
```

Os testes PHP usam SQLite em memória e fakes HTTP, sem alterar o PostgreSQL nem chamar contas reais. O smoke verifica o protocolo instalado sem inferência. `build:local` evita que `.env.production` altere os caminhos do frontend de desenvolvimento. A imagem de produção usa `npm run build`, com configuração de assets no build.

Codex está fixado em 0.165.0 e Lumo em 3.40.0. O build confere checksum e versão do Lumo; se o canal oficial mudar a versão, atualize a pinagem após homologar, em vez de aceitar uma versão diferente silenciosamente.

Licença do código: MIT. OpenAI, Lumo e dependências mantêm seus termos e licenças.
