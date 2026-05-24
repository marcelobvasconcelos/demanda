<?php
/**
 * Sync Firestore data to local MySQL on first request/start.
 * - Runs only when Firestore is enabled and a short-lived lock is absent.
 * - Currently syncs the `usuarios` collection using existing helper functions.
 */

function upsert_cliente(PDO $pdo, array $data)
{
    $nome = $data['nome'] ?? ($data['name'] ?? 'Cliente');
    $telefone = $data['telefone'] ?? ($data['phone'] ?? null);
    $email = isset($data['email']) ? strtolower(trim($data['email'])) : null;

    if ($email) {
        $stmt = $pdo->prepare('SELECT * FROM clientes WHERE email = ?');
        $stmt->execute([$email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $stmt = $pdo->prepare('UPDATE clientes SET nome = ?, telefone = ? WHERE id = ?');
            $stmt->execute([$nome, $telefone, $existing['id']]);
            return $existing['id'];
        }
    } else {
        $stmt = $pdo->prepare('SELECT * FROM clientes WHERE nome = ?');
        $stmt->execute([$nome]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $stmt = $pdo->prepare('UPDATE clientes SET telefone = ?, email = ? WHERE id = ?');
            $stmt->execute([$telefone, $email, $existing['id']]);
            return $existing['id'];
        }
    }

    $stmt = $pdo->prepare('INSERT INTO clientes (nome, telefone, email) VALUES (?, ?, ?)');
    $stmt->execute([$nome, $telefone, $email]);
    return $pdo->lastInsertId();
}

function upsert_loja(PDO $pdo, array $data)
{
    $nome = $data['nome'] ?? ($data['name'] ?? 'Loja');
    $contato = $data['contato_nome'] ?? ($data['contact_name'] ?? null);
    $telefone = $data['telefone'] ?? ($data['phone'] ?? null);

    $stmt = $pdo->prepare('SELECT * FROM lojas WHERE nome = ?');
    $stmt->execute([$nome]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $stmt = $pdo->prepare('UPDATE lojas SET contato_nome = ?, telefone = ? WHERE id = ?');
        $stmt->execute([$contato, $telefone, $existing['id']]);
        return $existing['id'];
    }

    $stmt = $pdo->prepare('INSERT INTO lojas (nome, contato_nome, telefone) VALUES (?, ?, ?)');
    $stmt->execute([$nome, $contato, $telefone]);
    return $pdo->lastInsertId();
}

function upsert_medida_cliente(PDO $pdo, array $data)
{
    $clienteRef = $data['cliente_email'] ?? $data['cliente'] ?? null;
    $clienteId = null;
    if ($clienteRef && filter_var($clienteRef, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare('SELECT id FROM clientes WHERE email = ?');
        $stmt->execute([strtolower(trim($clienteRef))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $clienteId = $row['id'];
    }
    if (!$clienteId && !empty($data['cliente_nome'])) {
        $stmt = $pdo->prepare('SELECT id FROM clientes WHERE nome = ?');
        $stmt->execute([$data['cliente_nome']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $clienteId = $row['id'];
    }
    if (!$clienteId) {
        $clienteId = upsert_cliente($pdo, ['nome' => $data['cliente_nome'] ?? ($data['cliente'] ?? 'Cliente'), 'email' => $clienteRef ?? null]);
    }

    $busto = isset($data['busto']) ? floatval($data['busto']) : null;
    $cintura = isset($data['cintura']) ? floatval($data['cintura']) : null;
    $quadril = isset($data['quadril']) ? floatval($data['quadril']) : null;
    $comprimento = isset($data['comprimento']) ? floatval($data['comprimento']) : null;
    $ombro = isset($data['ombro_a_ombro']) ? floatval($data['ombro_a_ombro']) : null;
    $altura_busto = isset($data['altura_busto']) ? floatval($data['altura_busto']) : null;
    $observacoes = $data['observacoes'] ?? null;
    $data_medida = $data['data_medida'] ?? ($data['data'] ?? date('Y-m-d'));

    $stmt = $pdo->prepare('INSERT INTO medidas_clientes (cliente_id, busto, cintura, quadril, comprimento, ombro_a_ombro, altura_busto, observacoes, data_medida) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$clienteId, $busto, $cintura, $quadril, $comprimento, $ombro, $altura_busto, $observacoes, $data_medida]);
    return $pdo->lastInsertId();
}

function upsert_servico(PDO $pdo, array $data)
{
    $clienteId = null;
    if (!empty($data['cliente_email']) && filter_var($data['cliente_email'], FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare('SELECT id FROM clientes WHERE email = ?');
        $stmt->execute([strtolower(trim($data['cliente_email']))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $clienteId = $row['id'];
    }
    if (!$clienteId && !empty($data['cliente_nome'])) {
        $stmt = $pdo->prepare('SELECT id FROM clientes WHERE nome = ?');
        $stmt->execute([$data['cliente_nome']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $clienteId = $row['id'];
    }
    if (!$clienteId) {
        $clienteId = upsert_cliente($pdo, ['nome' => $data['cliente_nome'] ?? 'Cliente', 'email' => $data['cliente_email'] ?? null]);
    }

    $lojaId = null;
    if (!empty($data['loja_nome'])) {
        $stmt = $pdo->prepare('SELECT id FROM lojas WHERE nome = ?');
        $stmt->execute([$data['loja_nome']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $lojaId = $row['id'];
    }
    if (!$lojaId && !empty($data['loja_id'])) {
        $lojaId = intval($data['loja_id']);
    }

    $descricao = $data['descricao'] ?? ($data['desc'] ?? 'Serviço');
    $tipo = in_array($data['tipo'] ?? '', ['conserto', 'roupa_completa']) ? $data['tipo'] : 'conserto';
    $valor_total = isset($data['valor_total']) ? floatval($data['valor_total']) : (isset($data['valor']) ? floatval($data['valor']) : 0.0);
    $valor_pago = isset($data['valor_pago']) ? floatval($data['valor_pago']) : 0.0;
    $status = $data['status_pagamento'] ?? ($data['status'] ?? 'pendente');
    $data_entrada = $data['data_entrada'] ?? date('Y-m-d');
    $data_prev = $data['data_entrega_prevista'] ?? null;

    $stmt = $pdo->prepare('SELECT id FROM servicos WHERE cliente_id = ? AND descricao = ? AND data_entrada = ?');
    $stmt->execute([$clienteId, $descricao, $data_entrada]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $stmt = $pdo->prepare('UPDATE servicos SET loja_id = ?, descricao = ?, tipo = ?, valor_total = ?, valor_pago = ?, status_pagamento = ?, data_entrega_prevista = ? WHERE id = ?');
        $stmt->execute([$lojaId, $descricao, $tipo, $valor_total, $valor_pago, $status, $data_prev, $row['id']]);
        return $row['id'];
    }

    $stmt = $pdo->prepare('INSERT INTO servicos (loja_id, cliente_id, descricao, tipo, valor_total, valor_pago, status_pagamento, data_entrada, data_entrega_prevista) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$lojaId, $clienteId, $descricao, $tipo, $valor_total, $valor_pago, $status, $data_entrada, $data_prev]);
    return $pdo->lastInsertId();
}

function upsert_remessa(PDO $pdo, array $data)
{
    $usuarioId = null;
    $usuarioEmail = !empty($data['usuario_email']) ? strtolower(trim($data['usuario_email'])) : null;
    $usuarioNome = !empty($data['usuario_nome']) ? trim($data['usuario_nome']) : null;

    if ($usuarioEmail && filter_var($usuarioEmail, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
        $stmt->execute([$usuarioEmail]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $usuarioId = $row['id'];
        }
    }

    if (!$usuarioId && $usuarioNome !== null) {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE nome = ?');
        $stmt->execute([$usuarioNome]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $usuarioId = $row['id'];
        }
    }

    if (!$usuarioId) {
        @file_put_contents(__DIR__ . '/logs/sync_firestore.txt', date('c') . " - Ignored remessa sem usuário válido: " . json_encode(['usuario_email' => $usuarioEmail, 'usuario_nome' => $usuarioNome, 'peca_servico' => $data['peca_servico'] ?? null], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        return false;
    }

    $lojaId = null;
    if (!empty($data['loja_nome'])) {
        $stmt = $pdo->prepare('SELECT id FROM lojas WHERE nome = ?');
        $stmt->execute([$data['loja_nome']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $lojaId = $row['id'];
    }

    $peca = $data['peca_servico'] ?? ($data['peca'] ?? 'item');
    $preco = isset($data['preco_unitario']) ? floatval($data['preco_unitario']) : (isset($data['preco']) ? floatval($data['preco']) : (isset($data['precoU']) ? floatval($data['precoU']) : 0.0));
    $qtd = isset($data['quantidade']) ? intval($data['quantidade']) : (isset($data['qtd']) ? intval($data['qtd']) : 1);
    $tamanho = $data['tamanho'] ?? 'outro';
    $qtd_entregue = isset($data['qtd_entregue']) ? intval($data['qtd_entregue']) : (isset($data['entregue']) ? intval($data['entregue']) : 0);
    $data_cadastro = $data['data_cadastro'] ?? ($data['data'] ?? date('Y-m-d'));

    if (is_array($data_cadastro) && isset($data_cadastro['timestampValue'])) {
        $data_cadastro = substr($data_cadastro['timestampValue'], 0, 10);
    } elseif (is_string($data_cadastro) && strpos($data_cadastro, 'T') !== false) {
        $data_cadastro = substr($data_cadastro, 0, 10);
    }

    $stmt = $pdo->prepare('SELECT id FROM remessas WHERE usuario_id = ? AND peca_servico = ? AND data_cadastro = ?');
    $stmt->execute([$usuarioId, $peca, $data_cadastro]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $stmt = $pdo->prepare('UPDATE remessas SET loja_id = ?, preco_unitario = ?, quantidade = ?, tamanho = ?, qtd_entregue = ? WHERE id = ?');
        $stmt->execute([$lojaId, $preco, $qtd, $tamanho, $qtd_entregue, $row['id']]);
        return $row['id'];
    }

    $stmt = $pdo->prepare('INSERT INTO remessas (usuario_id, loja_id, peca_servico, preco_unitario, quantidade, tamanho, qtd_entregue, data_cadastro) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$usuarioId, $lojaId, $peca, $preco, $qtd, $tamanho, $qtd_entregue, $data_cadastro]);
    return $pdo->lastInsertId();
}

function sync_unknown_document(PDO $pdo, string $collection, array $data, string $logFile)
{
    if (!empty($data['peca_servico']) || !empty($data['precoU']) || !empty($data['quantidade']) || !empty($data['entregue'])) {
        upsert_remessa($pdo, $data);
        return;
    }
    if (!empty($data['nome']) && !empty($data['email'])) {
        if (!empty($data['contato_nome']) || !empty($data['telefone'])) {
            upsert_loja($pdo, $data);
            return;
        }
        upsert_cliente($pdo, $data);
        return;
    }
    if (!empty($data['valor_total']) || !empty($data['valor_pago']) || !empty($data['status_pagamento'])) {
        upsert_servico($pdo, $data);
        return;
    }
    if (!empty($data['busto']) || !empty($data['cintura']) || !empty($data['quadril'])) {
        upsert_medida_cliente($pdo, $data);
        return;
    }
    @file_put_contents($logFile, date('c') . " - Unknown document type for collection {$collection}: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}

function sync_firestore_on_start(PDO $pdo, array $opts = []): void
{
    $lockDir = __DIR__ . '/tmp';
    if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);
    $lockFile = $lockDir . '/firestore_sync.lock';
    $lockTtl = $opts['lock_ttl'] ?? 300; // seconds
    $force = $opts['force'] ?? false;

    if (!$force && file_exists($lockFile)) {
        $age = time() - filemtime($lockFile);
        if ($age < $lockTtl) {
            return;
        }
    }

    @file_put_contents($lockFile, (string)time());

    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . '/sync_firestore.txt';

    try {
        if (!function_exists('firestore_request') || !function_exists('firestore_document_to_array')) {
            @file_put_contents($logFile, date('c') . " - Firestore helper not available\n", FILE_APPEND);
            return;
        }

        $serviceAccount = firestore_get_credentials();
        $parent = 'projects/' . firestore_get_project_id($serviceAccount) . '/databases/(default)/documents';
        $resp = firestore_request('POST', 'documents:listCollectionIds', ['parent' => $parent, 'pageSize' => 200]);
        $collections = $resp['collectionIds'] ?? [];
        if (empty($collections)) {
            @file_put_contents($logFile, date('c') . " - No collections found in Firestore\n", FILE_APPEND);
            return;
        }

        foreach ($collections as $collection) {
            @file_put_contents($logFile, date('c') . " - Starting sync for collection: {$collection}\n", FILE_APPEND);
            try {
                $resp = firestore_request('GET', "documents/{$collection}");
                $docs = $resp['documents'] ?? [];
            } catch (Throwable $e) {
                @file_put_contents($logFile, date('c') . " - Failed to list collection {$collection}: " . $e->getMessage() . "\n", FILE_APPEND);
                continue;
            }

            $count = 0;
            foreach ($docs as $doc) {
                $data = firestore_document_to_array($doc);
                try {
                    $result = null;
                    switch ($collection) {
                        case 'usuarios':
                            $result = firestore_sync_user_to_local($pdo, $data);
                            break;
                        case 'clientes':
                            $result = upsert_cliente($pdo, $data);
                            break;
                        case 'lojas':
                            $result = upsert_loja($pdo, $data);
                            break;
                        case 'servicos':
                            $result = upsert_servico($pdo, $data);
                            break;
                        case 'remessas':
                            $result = upsert_remessa($pdo, $data);
                            break;
                        case 'medidas_clientes':
                            $result = upsert_medida_cliente($pdo, $data);
                            break;
                        default:
                            @file_put_contents($logFile, date('c') . " - Ignored unknown collection {$collection} when syncing Firestore\n", FILE_APPEND);
                            continue 2;
                    }
                    if ($result !== false) {
                        $count++;
                    }
                } catch (Throwable $e) {
                    @file_put_contents($logFile, date('c') . " - Failed to sync doc: " . ($doc['name'] ?? '') . " - " . $e->getMessage() . "\n", FILE_APPEND);
                }
            }

            @file_put_contents($logFile, date('c') . " - Synced {$count} documents from {$collection}\n", FILE_APPEND);
        }
    } catch (Throwable $e) {
        @file_put_contents($logFile, date('c') . " - Sync error: " . $e->getMessage() . "\n", FILE_APPEND);
    } finally {
        @touch($lockFile);
    }
}

return;
