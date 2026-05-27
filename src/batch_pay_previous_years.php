<?php
/**
 * Script utilitário para marcar todas as remessas anteriores a 2026 como pagas.
 * Executa apenas uma vez via CLI ou gatilho temporário.
 */

require __DIR__ . '/firebase_helper.php';

// Aumenta limite de tempo para execução longa
set_time_limit(600);

function batch_pay_previous_years() {
    $email = 'marce7o77@gmail.com'; // Usuário principal informado
    $userUid = firestore_get_user_uid_by_email($email);
    
    if (!$userUid) {
        echo "ERRO: UID não encontrado para $email\n";
        return;
    }

    $serviceAccount = firestore_get_credentials();
    $parent = 'projects/' . $serviceAccount['project_id'] . '/databases/(default)/documents';
    
    echo "Iniciando migração de pagamentos para anos anteriores a 2026...\n";

    try {
        $resp = firestore_request('POST', 'documents:listCollectionIds', ['parent' => $parent, 'pageSize' => 500]);
        $collections = $resp['collectionIds'] ?? [];
        
        $countLotes = 0;
        $countDocs = 0;

        foreach ($collections as $col) {
            // Verifica se a coleção é do usuário e NÃO é de 2026
            if (str_ends_with($col, $userUid) && strpos($col, '-2026') === false) {
                echo "Processando coleção: $col\n";
                $countLotes++;

                try {
                    $dresp = firestore_request('GET', 'documents/' . rawurlencode($col) . '?pageSize=1000');
                    $documents = $dresp['documents'] ?? [];

                    foreach ($documents as $d) {
                        $doc = firestore_document_to_array($d);
                        $nameParts = explode('/', $d['name']);
                        $docId = end($nameParts);

                        // Calcula valor total para setar como recebido
                        $qtd = intval($doc['quantidade'] ?? $doc['qtd'] ?? 0);
                        $prU = floatval($doc['preco_unitario'] ?? $doc['precoU'] ?? 0);
                        $total = floatval($doc['total'] ?? ($qtd * $prU));
                        $jaRecebido = floatval($doc['valor_recebido'] ?? 0);

                        // Só atualiza se ainda faltar pagar algo significativo (> 0.01)
                        if ($total > 0 && abs($total - $jaRecebido) > 0.01) {
                            // Atualiza usando a função oficial para garantir espelhamento de campos
                            firestore_update_remessa($docId, [
                                'valor_recebido' => $total
                            ], $col);
                            $countDocs++;
                            echo "  [OK] Doc $docId marcado como pago (Total: $total)\n";
                            
                            // Pequena pausa para evitar estourar quota de escrita
                            usleep(100000); // 0.1s
                        }
                    }
                } catch (Throwable $e) {
                    echo "  [ERRO] Falha ao ler documentos da coleção $col: " . $e->getMessage() . "\n";
                }
            }
        }

        echo "\nCONCLUÍDO!\n";
        echo "Lotes (meses) afetados: $countLotes\n";
        echo "Documentos individuais atualizados: $countDocs\n";

    } catch (Throwable $e) {
        echo "ERRO GERAL: " . $e->getMessage() . "\n";
    }
}

// Se rodar via CLI, executa. Se for via include, ignora (evita rodar no Render sem querer)
if (php_sapi_name() === 'cli') {
    batch_pay_previous_years();
}
