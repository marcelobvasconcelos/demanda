### OBJETIVO
O sistema "Demanda" já está publicado no Render em ambiente Docker e a autenticação com o Firebase Firestore via variável de ambiente está 100% funcional. Agora, preciso que você implemente a lógica de Back-end (PHP) e ajuste o Front-end para alimentar o "Dashboard de Demanda Geral" conforme a imagem anexada.

### ESTRATÉGIA DE DADOS (PRESERVAR O APP FLUTTER)
- O aplicativo Flutter já está em produção e usa o Firestore. Não podemos alterar ou remover nenhum campo existente para não quebrar o app.
- Para as informações financeiras e de controle que faltam no dashboard (valores, lojistas, status de pagamento), você deve salvá-las no próprio Firestore, adicionando novos campos nos documentos ou criando uma coleção complementar lá dentro. O app Flutter vai ignorar esses campos novos automaticamente, o que é perfeito.

### REQUISITOS DO DASHBOARD (GERAL E MENSAL)
Quero que o faturamento consolidado seja exibido de duas formas: **Geral (Todo o histórico)** e **Mensal (Filtrado por mês/ano)**.

1. **Campos de Controle (Filtro):**
   - Adicione no topo do dashboard um seletor (Select/Dropdown) para o usuário alternar o período (Ex: "Visão Geral", "Janeiro/2026", "Fevereiro/2026", etc.). O padrão ao carregar deve ser o mês atual.

2. **Métricas dos Cards Dinâmicos:**
   - **Faturamento Geral/Mensal:** A soma de tudo (Recebido + Pendente) do período selecionado.
   - **Total Recebido (Dinheiro em Caixa):** Soma dos serviços com status de pago/concluído no período.
   - **Total Pendente (A Receber):** Soma dos serviços com status pendente/a receber no período.

3. **Listagens Inferiores:**
   - **Últimos Serviços Gerais:** Tabela com as colunas: Serviço/Cliente, Loja, Status (com badge colorido) e Valor.
   - **Medidas de Clientes:** Componente para listar ou contar dados rápidos de medidas associados às demandas.

### O QUE VOCÊ DEVE ME ENTREGAR
Sabendo que o arquivo `firebase_helper.php` já está corrigido e pronto na nuvem, me forneça:
1. **A estrutura dos novos campos** que serão lidos/gravados no Firestore sem quebrar o Flutter.
2. **O código de consulta em PHP** usando o SDK do Firebase para buscar os dados aplicando filtros de data (mês/ano) de forma eficiente.
3. **O código do arquivo do Dashboard** atualizado para substituir os valores zerados ("R$ 0,00") pelas variáveis dinâmicas do PHP já formatadas em moeda brasileira.