# Demanda — Sistema de Gestão de Ateliê

## O que é

Demanda é uma aplicação web desenvolvida em PHP puro para gestão financeira e operacional de um ateliê de costura. Ela funciona como painel de controle complementar a um aplicativo Flutter já em produção, consumindo os mesmos dados do Firebase Firestore sem interferir no funcionamento do app mobile.

---

## Objetivo

Permitir que a costureira gerencie, de forma simples e visual, todos os lotes de peças recebidos para costura — controlando produção, entregas e pagamentos — com visão financeira consolidada por mês e por ano.

---

## Funcionalidades

### Autenticação
- Login com e-mail e senha via Firebase Authentication
- Recuperação de senha por e-mail
- Sessão por usuário, com logout seguro

### Remessas (Lotes de Costura)
- Cadastro de lotes com: peça/serviço, quantidade, tamanho e preço unitário
- Registro de entregas parciais ou totais
- Controle de pagamento por lote (parcial ou quitação total)
- Edição e exclusão de lotes
- Filtro por mês/ano de referência

### Dashboard Financeiro
- **Faturamento bruto** do período selecionado
- **Total recebido** e **saldo pendente** do período
- **Pendente consolidado dos últimos 6 meses** — calculado sem chamadas extras ao Firebase, usando o cache local
- Detalhamento do pendente mês a mês
- **Histórico anual** com barra de progresso de recebimento por ano
- Lista dos lotes mais recentes

---

## Arquitetura

| Camada | Tecnologia |
|---|---|
| Frontend | PHP + Tailwind CSS + Lucide Icons |
| Backend | PHP 8 puro (sem framework) |
| Banco principal | Firebase Firestore (via REST API + JWT) |
| Banco auxiliar | MySQL (opcional, usado para sincronização local) |
| Infraestrutura | Docker + Render (deploy em nuvem) |
| Autenticação | Firebase Authentication (Identity Toolkit API) |

### Estratégia de dados
Os documentos no Firestore são organizados em coleções mensais no formato `{mes}-{ano}{uid}` (ex: `janeiro-2025abc123`). Isso permite buscar apenas o período necessário, reduzindo o consumo de leitura do Firebase.

Campos financeiros (`valor_recebido`, `qtd_entregue`, etc.) são adicionados aos documentos existentes sem remover nenhum campo original, garantindo compatibilidade total com o app Flutter em produção.

### Cache
Os dados do Firestore são armazenados em cache local (arquivo JSON em `/tmp`) por 10 minutos, evitando excesso de requisições e estourar a cota gratuita do Firebase.

### Dados Locais (CSV)
O sistema também mantém um espelho local dos dados em arquivos CSV na pasta `data/`:
- `lotes.csv` - Lotes de produção
- `lojas.csv` - Lojas/parceiras
- `clientes.csv` - Clientes

Isso permite funcionamento offline parcial e reduz chamadas ao Firestore.

---

## Estrutura de arquivos relevantes

```
src/
├── index.php                  # Aplicação principal (login, dashboard, remessas)
├── login_screen.php           # Tela de login (HTML)
├── firebase_helper.php        # Integração com Firestore e Firebase Auth via REST
├── csv_helper.php             # Armazenamento local em CSV
├── sync_firestore_on_start.php # Sincronização Firestore → CSV local
└── firebase_credenciais.json  # Credenciais da service account (não versionado)

data/                          # Dados locais (CSV) - persistidos no Render
├── lotes.csv
├── lojas.csv
└── clientes.csv
```

---

## Variáveis de ambiente

| Variável | Descrição |
|---|---|
| `GOOGLE_APPLICATION_CREDENTIALS` | Caminho para o arquivo de credenciais JSON |
| `FIREBASE_CREDENTIALS_JSON` | Conteúdo JSON das credenciais (alternativa ao arquivo) |
