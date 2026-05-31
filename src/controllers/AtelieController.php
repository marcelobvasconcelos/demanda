<?php
/**
 * AtelieController — Lógica de negócio do módulo "Ateliê Sob Medida".
 * 100% MySQL via PDO. Nenhuma chamada ao Firebase Firestore.
 */
class AtelieController
{
    public function __construct(private PDO $pdo) {}

    // =========================================================================
    // CLIENTES
    // =========================================================================

    public function listarClientes(): array
    {
        return $this->pdo
            ->query('SELECT * FROM atelie_clientes ORDER BY nome ASC')
            ->fetchAll();
    }

    public function buscarCliente(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM atelie_clientes WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
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

        if (isset($dados['id']) && $dados['id']) {
            $this->pdo->prepare(
                'UPDATE atelie_clientes SET nome=?, telefone=?, medidas_json=? WHERE id=?'
            )->execute([
                trim($dados['nome']),
                trim($dados['telefone'] ?? ''),
                json_encode($medidas, JSON_UNESCAPED_UNICODE),
                (int) $dados['id'],
            ]);
            return (int) $dados['id'];
        }

        $this->pdo->prepare(
            'INSERT INTO atelie_clientes (nome, telefone, medidas_json) VALUES (?, ?, ?)'
        )->execute([
            trim($dados['nome']),
            trim($dados['telefone'] ?? ''),
            json_encode($medidas, JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function excluirCliente(int $id): void
    {
        $this->pdo->prepare('DELETE FROM atelie_clientes WHERE id = ?')->execute([$id]);
    }

    // =========================================================================
    // CATÁLOGO DE SERVIÇOS
    // =========================================================================

    public function listarCatalogo(): array
    {
        return $this->pdo
            ->query('SELECT * FROM atelie_servicos_catalogo ORDER BY nome_servico ASC')
            ->fetchAll();
    }

    public function salvarServicoCatalogo(array $dados): void
    {
        if (isset($dados['id']) && $dados['id']) {
            $this->pdo->prepare(
                'UPDATE atelie_servicos_catalogo SET nome_servico=?, preco_base=? WHERE id=?'
            )->execute([trim($dados['nome_servico']), floatval($dados['preco_base']), (int) $dados['id']]);
        } else {
            $this->pdo->prepare(
                'INSERT INTO atelie_servicos_catalogo (nome_servico, preco_base) VALUES (?, ?)'
            )->execute([trim($dados['nome_servico']), floatval($dados['preco_base'])]);
        }
    }

    public function excluirServicoCatalogo(int $id): void
    {
        $this->pdo->prepare('DELETE FROM atelie_servicos_catalogo WHERE id = ?')->execute([$id]);
    }

    // =========================================================================
    // PEDIDOS
    // =========================================================================

    /**
     * Registra um novo pedido com seus itens dentro de uma transação.
     * Calcula o valor_total somando (quantidade × preco_aplicado) de cada item.
     * Determina status_pagamento automaticamente com base no valor_pago informado.
     *
     * @param array $dados  ['cliente_id', 'valor_pago', 'status_entrega', 'observacoes',
     *                       'data_pedido', 'itens' => [['servico_id', 'quantidade', 'preco_aplicado'], ...]]
     */
    public function salvarPedido(array $dados): int
    {
        $itens = $dados['itens'] ?? [];
        if (empty($itens)) {
            throw new InvalidArgumentException('O pedido deve ter ao menos um item.');
        }

        // Calcula total somando os itens
        $valorTotal = array_reduce($itens, function (float $carry, array $item): float {
            return $carry + (floatval($item['preco_aplicado']) * intval($item['quantidade']));
        }, 0.0);

        $valorPago = floatval($dados['valor_pago'] ?? 0);
        $statusPag = match (true) {
            $valorPago <= 0              => 'Pendente',
            $valorPago >= $valorTotal    => 'Pago',
            default                      => 'Parcial',
        };

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO atelie_pedidos
                    (cliente_id, valor_total, valor_pago, status_entrega, status_pagamento, observacoes, data_pedido)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                (int)   $dados['cliente_id'],
                        $valorTotal,
                        $valorPago,
                        $dados['status_entrega'] ?? 'Pendente',
                        $statusPag,
                trim(   $dados['observacoes'] ?? ''),
                        $dados['data_pedido'] ?? date('Y-m-d'),
            ]);

            $pedidoId = (int) $this->pdo->lastInsertId();

            $stmtItem = $this->pdo->prepare(
                'INSERT INTO atelie_itens_pedido (pedido_id, servico_id, quantidade, preco_aplicado)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($itens as $item) {
                $stmtItem->execute([
                    $pedidoId,
                    (int)   $item['servico_id'],
                    (int)   $item['quantidade'],
                    floatval($item['preco_aplicado']),
                ]);
            }

            $this->pdo->commit();
            return $pedidoId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Atualiza pagamento e/ou status de entrega de um pedido existente.
     * Recalcula status_pagamento automaticamente.
     */
    public function atualizarPedido(int $id, float $valorPagoNovo, string $statusEntrega): void
    {
        $stmt = $this->pdo->prepare('SELECT valor_total FROM atelie_pedidos WHERE id = ?');
        $stmt->execute([$id]);
        $pedido = $stmt->fetch();
        if (!$pedido) return;

        $statusPag = match (true) {
            $valorPagoNovo <= 0                          => 'Pendente',
            $valorPagoNovo >= floatval($pedido['valor_total']) => 'Pago',
            default                                      => 'Parcial',
        };

        $this->pdo->prepare(
            'UPDATE atelie_pedidos
             SET valor_pago=?, status_pagamento=?, status_entrega=?
             WHERE id=?'
        )->execute([$valorPagoNovo, $statusPag, $statusEntrega, $id]);
    }

    public function excluirPedido(int $id): void
    {
        $this->pdo->prepare('DELETE FROM atelie_pedidos WHERE id = ?')->execute([$id]);
    }

    /**
     * Lista pedidos com dados do cliente e saldo devedor calculado.
     * Suporta filtro por status_entrega e/ou status_pagamento.
     */
    public function listarPedidos(array $filtros = []): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filtros['status_entrega'])) {
            $where[] = 'p.status_entrega = ?';
            $params[] = $filtros['status_entrega'];
        }
        if (!empty($filtros['status_pagamento'])) {
            $where[] = 'p.status_pagamento = ?';
            $params[] = $filtros['status_pagamento'];
        }
        if (!empty($filtros['cliente_id'])) {
            $where[] = 'p.cliente_id = ?';
            $params[] = (int) $filtros['cliente_id'];
        }

        $sql = 'SELECT p.*,
                       c.nome        AS cliente_nome,
                       c.telefone    AS cliente_telefone,
                       (p.valor_total - p.valor_pago) AS saldo_devedor
                FROM atelie_pedidos p
                JOIN atelie_clientes c ON c.id = p.cliente_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY p.data_pedido DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function buscarItensPedido(int $pedidoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, s.nome_servico
             FROM atelie_itens_pedido i
             JOIN atelie_servicos_catalogo s ON s.id = i.servico_id
             WHERE i.pedido_id = ?'
        );
        $stmt->execute([$pedidoId]);
        return $stmt->fetchAll();
    }

    // =========================================================================
    // RESUMO FINANCEIRO (usado nos cards do módulo)
    // =========================================================================

    public function resumoFinanceiro(): array
    {
        $row = $this->pdo->query(
            'SELECT
                COUNT(*)                                    AS total_pedidos,
                COALESCE(SUM(valor_total), 0)               AS faturamento,
                COALESCE(SUM(valor_pago), 0)                AS recebido,
                COALESCE(SUM(valor_total - valor_pago), 0)  AS pendente,
                SUM(status_entrega = "Pendente")            AS entrega_pendente,
                SUM(status_entrega = "Em Produção")         AS em_producao,
                SUM(status_entrega = "Entregue")            AS entregues
             FROM atelie_pedidos'
        )->fetch();

        return $row ?: [];
    }
}
