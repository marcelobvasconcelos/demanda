<?php
/**
 * Script utilitário para marcar ABSOLUTAMENTE TODAS as remessas anteriores a 2026 como pagas.
 * Varre todas as coleções do banco, independente do usuário.
 */

require __DIR__ . '/firebase_helper.php';

set_time_limit(1800); // 30 minutos

function full_batch_pay_migration() {
    $serviceAccount = firestore_get_credentials();
    $parent = 'projects/' . $serviceAccount['project_id'] . '/databases/(default)/documents';
    
    echo "Iniciando Varredura Global de Coleções Históricas...\n";

    try {
        $collections = [];
        $pageToken = null;
        do {
            $params = ['parent' => $parent, 'pageSize' => 500];
            if ($pageToken) $params['pageToken'] = $pageToken;
            $resp = firestore_request('POST', 'documents:listCollectionIds', $params);
            $newCols = $resp['collectionIds'] ?? [];
            $collections = array_merge($collections, $newCols);
            $pageToken = $resp['nextPageToken'] ?? null;
        } while ($pageToken);

        echo "Total de coleções encontradas: " . count($collections) . "\n";

        $totalAfetados = 0;

        foreach ($collections as $col) {
            // Padrão: nome-anoUID. Ex: janeiro-2023UID ou remessas
            // Filtramos apenas anos anteriores a 2026
            $isHistorico = false;
            foreach (['2022', '2023', '2024', '2025'] as $ano) {
                if (strpos($col, '-' . $ano) !== false) {
                    $isHistorico = true;
                    break;
                }
            }

            if ($isHistorico) {
                echo "Processando Histórico: $col\n";
                try {
                    $dresp = firestore_request('GET', 'documents/' . rawurlencode($col) . '?pageSize=1000');
                    $documents = $dresp['documents'] ?? [];

                    foreach ($documents as $d) {
                        $doc = firestore_document_to_array($d);
                        $parts = explode('/', $d['name']);
                        $docId = end($parts);

                        // Calcula valor total
                        $qtd = intval($doc['quantidade'] ?? $doc['qtd'] ?? 0);
                        $prU = floatval($doc['preco_unitario'] ?? $doc['precoU'] ?? 0);
                        $total = floatval($doc['total'] ?? ($qtd * $prU));
                        $jaRec = floatval($doc['valor_recebido'] ?? 0);

                        if ($total > 0 && abs($total - $jaRec) > 0.01) {
                            // Atualiza sem mudar outros campos
                            firestore_update_remessa($docId, ['valor_recebido' => $total], $col);
                            $totalAfetados++;
                            echo "  [PAGO] $docId ($total)\n";
                            usleep(50000); // 0.05s
                        }
                    }
                } catch (Throwable $e) {
                    echo "  [ERRO] Falha na coleção $col: " . $e->getMessage() . "\n";
                }
            }
        }

        echo "\nSUCESSO! Total de documentos quitados: $totalAfetados\n";
        
        // Limpa todos os caches de dashboard
        echo "Limpando caches...\n";
        $files = glob(__DIR__ . '/tmp/dashboard_cache_*.json');
        foreach ($files as $f) @unlink($f);

    } catch (Throwable $e) {
        echo "ERRO FATAL: " . $e->getMessage() . "\n";
    }
}

if (php_sapi_name() === 'cli') {
    full_batch_pay_migration();
}
