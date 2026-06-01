<?php
/**
 * Sync Firestore data to local CSV on first request/start.
 */

function csv_upsert_loja(array $data): int
{
    $nome = $data['nome'] ?? ($data['name'] ?? 'Loja');
    $existing = csv_find('lojas', ['nome' => $nome]);
    if ($existing) {
        csv_update('lojas', ['nome' => $nome], [
            'contato_nome' => $data['contato_nome'] ?? ($data['contact_name'] ?? null),
            'telefone' => $data['telefone'] ?? ($data['phone'] ?? null),
        ]);
        return (int)$existing['id'];
    }
    return csv_insert('lojas', [
        'nome' => $nome,
        'contato_nome' => $data['contato_nome'] ?? ($data['contact_name'] ?? null),
        'telefone' => $data['telefone'] ?? ($data['phone'] ?? null),
    ]);
}

function csv_upsert_cliente(array $data): int
{
    $nome = $data['nome'] ?? ($data['name'] ?? 'Cliente');
    $email = isset($data['email']) ? strtolower(trim($data['email'])) : null;
    
    if ($email) {
        $existing = csv_find('clientes', ['email' => $email]);
        if ($existing) {
            csv_update('clientes', ['email' => $email], [
                'nome' => $nome,
                'telefone' => $data['telefone'] ?? ($data['phone'] ?? null),
            ]);
            return (int)$existing['id'];
        }
    }
    
    return csv_insert('clientes', [
        'nome' => $nome,
        'email' => $email,
        'telefone' => $data['telefone'] ?? ($data['phone'] ?? null),
    ]);
}

function csv_upsert_remessa(array $data): ?int
{
    $usuarioEmail = !empty($data['usuario_email']) ? strtolower(trim($data['usuario_email'])) : null;
    $peca = $data['peca_servico'] ?? ($data['peca'] ?? 'item');
    $data_cadastro = $data['data_cadastro'] ?? ($data['data'] ?? date('Y-m-d'));
    
    if (is_string($data_cadastro) && strpos($data_cadastro, 'T') !== false) {
        $data_cadastro = substr($data_cadastro, 0, 10);
    }
    
    $lojaId = null;
    if (!empty($data['loja_nome'])) {
        $loja = csv_find('lojas', ['nome' => $data['loja_nome']]);
        if ($loja) $lojaId = $loja['id'];
    }
    
    $preco = isset($data['preco_unitario']) ? floatval($data['preco_unitario']) : (isset($data['precoU']) ? floatval($data['precoU']) : 0.0);
    $qtd = isset($data['quantidade']) ? intval($data['quantidade']) : 1;
    
    $existing = csv_find('remessas', [
        'peca_servico' => $peca,
        'data_cadastro' => $data_cadastro,
    ]);
    
    if ($existing) {
        csv_update('remessas', ['id' => $existing['id']], [
            'loja_id' => $lojaId,
            'preco_unitario' => $preco,
            'quantidade' => $qtd,
            'tamanho' => $data['tamanho'] ?? 'outro',
            'qtd_entregue' => intval($data['qtd_entregue'] ?? $data['entregue'] ?? 0),
        ]);
        return (int)$existing['id'];
    }
    
    return csv_insert('remessas', [
        'loja_id' => $lojaId,
        'peca_servico' => $peca,
        'preco_unitario' => $preco,
        'quantidade' => $qtd,
        'tamanho' => $data['tamanho'] ?? 'outro',
        'qtd_entregue' => intval($data['qtd_entregue'] ?? $data['entregue'] ?? 0),
        'data_cadastro' => $data_cadastro,
    ]);
}

function sync_firestore_on_start(array $opts = []): void
{
    $lockDir = __DIR__ . '/tmp';
    if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);
    $lockFile = $lockDir . '/firestore_sync.lock';
    $lockTtl = $opts['lock_ttl'] ?? 300;
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
                    switch ($collection) {
                        case 'lojas':
                            csv_upsert_loja($data);
                            $count++;
                            break;
                        case 'clientes':
                            csv_upsert_cliente($data);
                            $count++;
                            break;
                        case 'remessas':
                            $result = csv_upsert_remessa($data);
                            if ($result !== null) $count++;
                            break;
                        default:
                            @file_put_contents($logFile, date('c') . " - Ignored collection {$collection}\n", FILE_APPEND);
                            continue 2;
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