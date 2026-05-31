# Módulo Ateliê Sob Medida

## Visão Geral

Módulo independente para gestão de clientes e pedidos personalizados do ateliê. Opera 100% em MySQL local, sem interação com Firebase.

## Funcionalidades Implementadas

### 1. Gestão de Clientes
- ✅ Cadastro completo com nome e telefone
- ✅ Registro de medidas corporais (busto, cintura, quadril, comprimento, ombro, manga)
- ✅ Campo de observações sobre as medidas
- ✅ Edição e exclusão de clientes
- ✅ Armazenamento de medidas em formato JSON

### 2. Catálogo de Serviços
- ✅ Cadastro de serviços com nome e preço base
- ✅ 8 serviços pré-cadastrados no banco
- ✅ Edição e exclusão de serviços
- ✅ Preços ajustáveis por pedido

### 3. Gestão de Pedidos
- ✅ Criação de pedidos vinculados a clientes
- ✅ Seleção múltipla de serviços do catálogo
- ✅ Cálculo automático do valor total
- ✅ Registro de pagamento parcial ou total
- ✅ Status de entrega (Pendente, Em Produção, Entregue)
- ✅ Status de pagamento automático (Pendente, Parcial, Pago)
- ✅ Campo de observações
- ✅ Atualização de pagamentos e status
- ✅ Exclusão de pedidos

### 4. Dashboard Financeiro
- ✅ Total de pedidos
- ✅ Faturamento total
- ✅ Valor recebido
- ✅ Saldo pendente
- ✅ Filtros por status de entrega e pagamento

## Estrutura de Arquivos

```
src/
├── atelie_sob_medida.php          # Arquivo principal do módulo
├── controllers/
│   └── AtelieController.php       # Lógica de negócio (PDO)
└── views/
    └── atelie/
        ├── pedidos.php             # Tela de pedidos
        ├── clientes.php            # Tela de clientes
        └── catalogo.php            # Tela de catálogo
```

## Banco de Dados

### Tabelas Utilizadas

1. **atelie_clientes**
   - `id`, `nome`, `telefone`, `medidas_json`, `criado_em`

2. **atelie_servicos_catalogo**
   - `id`, `nome_servico`, `preco_base`

3. **atelie_pedidos**
   - `id`, `cliente_id`, `valor_total`, `valor_pago`, `status_entrega`, `status_pagamento`, `observacoes`, `data_pedido`

4. **atelie_itens_pedido**
   - `id`, `pedido_id`, `servico_id`, `quantidade`, `preco_aplicado`

## Acesso ao Módulo

- **Desktop**: Botão "Ateliê" no menu superior
- **Mobile**: Ícone de régua na navegação inferior

## Tecnologias

- PHP 8 puro (sem frameworks)
- MySQL com PDO
- Tailwind CSS
- Lucide Icons
- JavaScript vanilla

## Características Técnicas

- ✅ Transações SQL para integridade de dados
- ✅ Validação de dados no backend
- ✅ Interface responsiva (mobile-first)
- ✅ Cálculos automáticos de totais e status
- ✅ Modais para formulários
- ✅ Feedback visual de ações

## Próximas Melhorias Sugeridas

- [ ] Histórico de alterações de pedidos
- [ ] Impressão de fichas de clientes com medidas
- [ ] Relatórios de faturamento por período
- [ ] Integração com o dashboard principal
- [ ] Notificações de pedidos pendentes
- [ ] Upload de fotos de referência
