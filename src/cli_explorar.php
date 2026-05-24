<?php
/**
 * Demanda - Script CLI para Explorar o Firestore
 * Roda dentro do container Docker via: docker exec costureira_web php /var/www/html/cli_explorar.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;

echo "\n========================================\n";
echo "  DEMANDA — EXPLORADOR DO FIRESTORE\n";
echo "========================================\n\n";

$credFile = __DIR__ . '/firebase_credenciais.json';

if (!file_exists($credFile)) {
    echo "[ERRO] Arquivo firebase_credenciais.json nao encontrado em: $credFile\n";
    exit(1);
}

echo "[OK] Credenciais encontradas: $credFile\n";
echo "[...] Conectando ao Firestore...\n\n";

try {
    $firebase = (new Factory)->withServiceAccount($credFile);
    $firestore = $firebase->createFirestore()->database();

    echo "[OK] Conexao com o Firestore estabelecida!\n\n";

    $collections = $firestore->collections();
    $collCount = 0;

    foreach ($collections as $collection) {
        $collName = $collection->id();
        $collCount++;

        $docCount = 0;
        $sampleData = null;
        $allDocs = [];

        foreach ($collection->documents() as $doc) {
            if ($doc->exists()) {
                $docCount++;
                $data = $doc->data();
                if ($sampleData === null) {
                    $sampleData = $data;
                }
                // Guardar até 3 amostras
                if (count($allDocs) < 3) {
                    $allDocs[] = ['id' => $doc->id(), 'data' => $data];
                }
            }
        }

        echo "--- COLECAO: $collName ($docCount documentos) ---\n";

        if ($sampleData) {
            echo "  Campos encontrados:\n";
            foreach ($sampleData as $key => $value) {
                $type = gettype($value);
                if (is_object($value)) {
                    $type = get_class($value);
                    $display = "[$type]";
                } elseif (is_array($value)) {
                    $display = "[array com " . count($value) . " itens]";
                } elseif (is_bool($value)) {
                    $display = $value ? 'true' : 'false';
                } else {
                    $display = substr((string)$value, 0, 80);
                }
                echo "    - $key ($type): $display\n";
            }
        }

        // Mostrar IDs dos primeiros documentos
        echo "  Primeiros doc IDs: ";
        $ids = [];
        foreach ($allDocs as $d) {
            $ids[] = $d['id'];
        }
        echo implode(', ', $ids) . "\n";

        echo "\n";
    }

    if ($collCount === 0) {
        echo "[AVISO] Nenhuma colecao encontrada no Firestore.\n";
    } else {
        echo "========================================\n";
        echo "  TOTAL: $collCount colecao(oes) encontrada(s)\n";
        echo "========================================\n";
    }

    // Salvar a estrutura completa como JSON para uso pelo importador
    echo "\n[...] Exportando dados completos para JSON...\n";

    $exportData = [];
    $totalDocs = 0;

    foreach ($firestore->collections() as $collection) {
        $collName = $collection->id();
        $exportData[$collName] = [];

        foreach ($collection->documents() as $doc) {
            if ($doc->exists()) {
                $docData = $doc->data();
                // Converter objetos Timestamp para strings
                array_walk_recursive($docData, function (&$val) {
                    if (is_object($val)) {
                        if (method_exists($val, 'formatForApi')) {
                            $val = $val->formatForApi();
                        } elseif (method_exists($val, '__toString')) {
                            $val = (string)$val;
                        } else {
                            $val = json_encode($val);
                        }
                    }
                });

                $exportData[$collName][$doc->id()] = $docData;
                $totalDocs++;
            }
        }
    }

    $jsonPath = __DIR__ . '/firestore_export.json';
    file_put_contents($jsonPath, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo "[OK] Exportacao concluida! $totalDocs documentos salvos em: $jsonPath\n";
    echo "[PROXIMO PASSO] Os dados foram exportados. Execute o importador para migrar para o MySQL.\n\n";

} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
    exit(1);
}
