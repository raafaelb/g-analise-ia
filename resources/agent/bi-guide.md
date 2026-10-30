# Guia de autoria do G-Analise BI

Leia este guia antes de criar ou manter dashboards. As regras do usuário e do projeto continuam prioritárias.

## Ferramentas

- `horus_action` é a única forma de operar o BI. Não existe autorização para shell ou leitura de credenciais.
- Use `scaffold` com `kind: widget` e `template: Kpi`, `Barline`, `Table`, `Svelte` etc. para obter a forma exata dos widgets na versão instalada.
- Use `scaffold` com `kind: expression` e `template: contexts`, `variation`, `participation`, `yoy` ou `ytd` para obter exemplos de expressões.
- `workspace_file` lê e edita os YAMLs locais. Use o hash retornado na leitura ao salvar um arquivo existente.
- `info` de uma aplicação retorna o modelo e as expressões canônicas. `columns` confirma os IDs físicos de uma tabela. Não invente IDs.
- Se `push` ou outra ação retornar `awaiting_approval`, encerre o turno. O portal executa a revisão aprovada e retoma a conversa com o resultado. Não repita a criação ou a ação já aplicada.

## Ciclo de autoria

Uma aplicação com `deskId: null` está em Minha Mesa. Publicar é uma decisão humana. Trabalhe em rascunhos; para alterar a estrutura de uma aplicação publicada, duplique-a e mantenha a cópia privada até receber uma autorização concreta de publicação.

Crie arquivos a partir do recurso produzido por `new`/`clone_app`. Não substitua cabeçalhos canônicos e não edite `.lumo/`. `lint` valida a estrutura; não prova que métricas ou renderização estão corretas.

## Referências de medidas e dimensões

Widgets usam referências canônicas, não o texto do rótulo:

- Coluna física: `<id>:SUM`, `<id>:AVG`, `<id>:MAX`, `<id>:MIN`, `<id>:COUNT`.
- Dimensão: `<id>:AGP`; data: `<id>:DATE`, `<id>:YEARMONTH`, `<id>:YEAR` etc.
- Expressão da aplicação: `X<id>:EXPR`, com o ID retornado pelo servidor. Nunca use `[Nome da expressão]` como `primaryData.dataSource` de um KPI.

Ao criar uma expressão nova, sincronize sua definição após revisão, inspecione o ID canônico e então conecte o widget. Confirme o valor na prévia de dados. Se o renderer disser que a coluna primária não foi encontrada, a referência ainda não está válida.

Receitas são consultadas pelo scaffold; um esqueleto de KPI inclui:

```yaml
chart:
  primaryData:
    dataSource: "X<ID_REAL>:EXPR"
    title: { value: "Indicador descritivo" }
  visual:
    numberColor: { value: "#0861ee" }
    numberSize: 32
    titleSize: 16
```

## Métricas corretas

Receita e quantidades são geralmente aditivas. Estoque e saldo são semi-aditivos no tempo: use a última competência/posição ou a agregação de negócio adequada, nunca some todos os meses para representar a posição atual. Razões devem ser recompostas dos componentes agregados (por exemplo, SUM(receita)/COUNT(vendas)), sem somar ou tirar média das razões de cada linha.

Verifique granularidade e duplicações. Uma consulta de zero linhas sem colunas pode ser erro, não evidência de fonte vazia. Não entregue números fictícios como se fossem reais.

## Séries temporais e aparência

Ordene meses pelo campo de data, explicitamente. A ordem alfabética dos rótulos não é cronológica. Para Barline, além de dimensionColumns/valueColumns:

```yaml
chart:
  globalType: line
  dimensionColumns: ["<DATA_ID>:YEARMONTH"]
  valueColumns: ["<VALOR_ID>:SUM"]
  orderColumns: ["<DATA_ID>:YEARMONTH"]
  orderColumnsDir: ["ASC"]
  showDataScale: true
  showDataLabels: false
  visualType: transparent
```

Use uma cor de destaque e cinzas de contexto; escolha cor, título e unidade de cada indicador. Não dependa só de cor. Verifique contraste e legibilidade. Não inclua chamadas a serviços externos ou coleta de credenciais em widgets.

## Validação e imagens

Depois do push confirmado, execute `preview` e `export` da aplicação. Inspecione os valores, datas, ordenação e erros dos widgets. Use `download_path` retornado pelo export em links; caminhos `exports/...` são internos e não servem para download no navegador.

`project_image` retorna à IA um PNG exportado somente quando o usuário autorizou esse arquivo específico nesta conversa. Se faltar autorização, peça ao usuário que abra a prévia e use “Autorizar análise visual com IA”. A criação pode continuar com a validação de dados e a prévia no portal; não declare inspeção visual pela IA antes de receber a imagem.

## Atualização automática

Confira todas as tabelas da aplicação e seus `originFlowId`. Fontes compartilhadas são agendadas uma vez. Preserve agendas mais frequentes. Uma agenda inativa não cobre a fonte. Use America/Sao_Paulo, preferindo a madrugada para novas cargas diárias.

O contexto de carga deve corresponder ao fluxo: Total → Total; Incremental → Incremental ou Total; Temporal → Temporal:Days:N, Temporal:Future:N, Temporal:Month:M-YYYY, Temporal:Year:YYYY/Current ou Total. KeySweep é sincronização de exclusões, não substitui a carga dos dados.

Uma fonte privada exige autorização de publicação antes de agendar. Não publique implicitamente. Uma solicitação de carga enfileirada não é sucesso: confira o novo run_id, horário posterior à solicitação, finishedWhen e Success=true para todas as fontes esperadas. Um log de uma execução antiga não confirma o teste atual.

Antes de entregar ou preparar publicação, use `refresh_coverage` com `resource: app:<id>` para conferir automaticamente todas as fontes e o teste de carga. O portal bloqueia a publicação de aplicações criadas neste projeto enquanto essa conferência não estiver verificada.

Para parâmetros do tenant use `tenant_variables`: lista somente nomes e referências, nunca valores. Não tente ler variables/tenant.yaml ou obter senhas. Use a referência no recurso BI sem revelar seu valor.
