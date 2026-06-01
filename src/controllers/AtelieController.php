<?php
/**
 * AtelieController — Lógica de negócio do módulo "Ateliê Sob Medida".
 * Usa CSV em vez de MySQL.
 */
require_once __DIR__ . '/../csv_helper.php';

class AtelieController
{
    public function __construct() {}

    // =========================================================================
    // CLIENTES
    // =========================================================================

    public function listarClientes(): array
    {
        return csv_find_all('atelie_clientes');
    }

    public function buscarCliente(int $id): ?array
    {
        return csv_find('atelie_clientes', ['id' => (string)$id]);
    }

    public function salvarCliente(array $dados): int
    {
        $medidas = [
            'busto'       => $dados['busto']       ?? null,
            'cintura'     => $dados['cintura']      ?? null,
            'quadril'     => $dados['quadril']      ?? null,
            'comprimento' => $dados['comprimento']  ?? null,
            'ombro'       => $dados['ombro']        ?? null,
            'manga'       => $dados['manga']        ?? null,
            'observacoes' => trim($dados['obs_medidas'] ?? ''),
        ];

        $id = isset($dados['id']) && $dados['id'] ? (int)$dados['id'] : null;

        if ($id) {
            csv_update('atelie_clientes', ['id' => (string)$id], [
                'nome' => trim($dados['nome']),
                'telefone' => trim($dados['telefone'] ?? ''),
                'medidas_json' => json_encode($medidas, JSON_UNESCAPED_UNICODE),
            ]);
            return $id;
        }

        return csv_insert('atelie_clientes', [
            'nome' => trim($dados['nome']),
            'telefone' => trim($dados['telefone'] ?? ''),
            'medidas_json' => json_encode($medidas, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function excluirCliente(int $id): void
    {
        csv_delete('atelie_clientes', ['id' => (string)$id]);
    }

    // =========================================================================
    // CATÁLOGO DE SERVIÇOS
    // =========================================================================

    public function listarCatalogo(): array
    {
        return csv_find_all('atelie_servicos_catalogo');
    }

    public function salvarServicoCatalogo(array $dados): void
    {
        $id = isset($dados['id']) && $dados['id'] ? (int)$dados['id'] : null;

        if ($id) {
            csv_update('atelie_servicos_catalogo', ['id' => (string)$id], [
                'nome_servico' => trim($dados['nome_servico']),
                'preco_base' => floatval($dados['preco_base']),
            ]);
        } else {
            csv_insert('atelie_servicos_catalogo', [
                'nome_servico' => trim($dados['nome_servico']),
                'preco_base' => floatval($dados['preco_base']),
            ]);
        }
    }

    public function excluirServicoCatalogo(int $id): void
    {
        csv_delete('atelie_servicos_catalogo', ['id' => (string)$id]);
    }

    // =========================================================================
    // PEDIDOS
    // =========================================================================

    public function salvarPedido(array $dados): int
    {
        $itens = $dados['itens'] ?? [];
        if (empty($itens)) {
            throw new InvalidArgumentException('O pedido deve ter ao menos um item.');
        }

        $valorTotal = array_reduce($itens, function (float $carry, array $item): float {
            return $carry + (floatval($item['preco_aplicado']) * intval($item['quantidade']));
        }, 0.0);

        $valorPago = floatval($dados['valor_pago'] ?? 0);
        $statusPag = match (true) {
            $valorPago <= 0              => 'Pendente',
            $valorPago >= $valorTotal    => 'Pago',
            default                      => 'Parcial',
        };

        $pedidoId = csv_insert('atelie_pedidos', [
            'cliente_id' => (int)$dados['cliente_id'],
            'valor_total' => $valorTotal,
            'valor_pago' => $valorPago,
            'status_entrega' => $dados['status_entrega'] ?? 'Pendente',
            'status_pagamento' => $statusPag,
            'observacoes' => trim($dados['observacoes'] ?? ''),
            'data_pedido' => $dados['data_pedido'] ?? date('Y-m-d'),
        ]);

        foreach ($itens as $item) {
            csv_insert('atelie_itens_pedido', [
                'pedido_id' => $pedidoId,
                'servico_id' => (int)$item['servico_id'],
                'quantidade' => (int)$item['quantidade'],
                'preco_aplicado' => floatval($item['preco_aplicado']),
            ]);
        }

        return $pedidoId;
    }

    public function atualizarPedido(int $id, float $valorPagoNovo, string $statusEntrega): void
    {
        $pedido = csv_find('atelie_pedidos', ['id' => (string)$id]);
        if (!$pedido) return;

        $valorTotal = floatval($pedido['valor_total']);
        $statusPag = match (true) {
            $valorPagoNovo <= 0                          => 'Pendente',
            $valorPagoNovo >= $valorTotal                => 'Pago',
            default                                      => 'Parcial',
        };

        csv_update('atelie_pedidos', ['id' => (string)$id], [
            'valor_pago' => $valorPagoNovo,
            'status_pagamento' => $statusPag,
            'status_entrega' => $statusEntrega,
        ]);
    }

    public function excluirPedido(int $id): void
    {
        csv_delete('atelie_pedidos', ['id' => (string)$id]);
        // Remove itens do pedido
        $itens = csv_find_all('atelie_itens_pedido', ['pedido_id' => $id]);
        foreach ($itens as $item) {
            csv_delete('atelie_itens_pedido', ['id' => $item['id']]);
        }
    }

    public function listarPedidos(array $filtros = []): array
    {
        $pedidos = csv_find_all('atelie_pedidos');

        // Aplica filtros
        if (!empty($filtros['status_entrega'])) {
            $pedidos = array_filter($pedidos, fn($p) => $p['status_entrega'] === $filtros['status_entrega']);
        }
        if (!empty($filtros['status_pagamento'])) {
            $pedidos = array_filter($pedidos, fn($p) => $p['status_pagamento'] === $filtros['status_pagamento']);
        }
        if (!empty($filtros['cliente_id'])) {
            $pedidos = array_filter($pedidos, fn($p) => (int)$p['cliente_id'] === (int)$filtros['cliente_id']);
        }

        // Ordena por data_pedido DESC
        usort($pedidos, fn($a, $b) => strcmp($b['data_pedido'] ?? '', $a['data_pedido'] ?? ''));

        // Adiciona dados do cliente e saldo devedor
        return array_map(function ($p) {
            $cliente = csv_find('atelie_clientes', ['id' => $p['cliente_id']]);
            return [
                'id' => $p['id'],
                'cliente_id' => $p['cliente_id'],
                'cliente_nome' => $cliente['nome'] ?? 'Cliente não encontrado',
                'cliente_telefone' => $cliente['telefone'] ?? '',
                'valor_total' => $p['valor_total'],
                'valor_pago' => $p['valor_pago'],
                'status_entrega' => $p['status_entrega'],
                'status_pagamento' => $p['status_pagamento'],
                'observacoes' => $p['observacoes'],
                'data_pedido' => $p['data_pedido'],
                'saldo_devedor' => floatval($p['valor_total']) - floatval($p['valor_pago']),
            ];
        }, $pedidos);
    }

    public function buscarItensPedido(int $pedidoId): array
    {
        $itens = csv_find_all('atelie_itens_pedido', ['pedido_id' => $pedidoId]);
        return array_map(function ($i) {
            $servico = csv_find('atelie_servicos_catalogo', ['id' => $i['servico_id']]);
            return [
                'id' => $i['id'],
                'pedido_id' => $i['pedido_id'],
                'servico_id' => $i['servico_id'],
                'nome_servico' => $servico['nome_servico'] ?? 'Serviço não encontrado',
                'quantidade' => $i['quantidade'],
                'preco_aplicado' => $i['preco_aplicado'],
            ];
        }, $itens);
    }

    // =========================================================================
    // RESUMO FINANCEIRO
    // =========================================================================

    public function resumoFinanceiro(): array
    {
        $pedidos = csv_find_all('atelie_pedidos');

        $totalPedidos = count($pedidos);
        $faturamento = array_sum(array_map(fn($p) => floatval($p['valor_total']), $pedidos));
        $recebido = array_sum(array_map(fn($p) => floatval($p['valor_pago']), $pedidos));
        $pendente = $faturamento - $recebido;
        $entregaPendente = count(array_filter($pedidos, fn($p) => $p['status_entrega'] === 'Pendente'));
        $emProducao = count(array_filter($pedidos, fn($p) => $p['status_entrega'] === 'Em Produção'));
        $entregues = count(array_filter($pedidos, fn($p) => $p['status_entrega'] === 'Entregue'));

        return [
            'total_pedidos' => $totalPedidos,
            'faturamento' => $faturamento,
            'recebido' => $recebido,
            'pendente' => $pendente,
            'entrega_pendente' => $entregaPendente,
            'em_producao' => $emProducao,
            'entregues' => $entregues,
        ];
    }
}