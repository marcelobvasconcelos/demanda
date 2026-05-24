<?php
/**
 * Importador Firestore → MySQL (CLI)
 *
 * Uso:
 *  php import_firestore.php          # modo de simulação (dry-run)
 *  php import_firestore.php --run    # executa a importação
 *
 * Pré-requisitos:
 *  - Colocar o arquivo de credenciais do service account em firebase_credenciais.json
 *    na raiz do projeto (`c:\\demanda\\firebase_credenciais.json`) ou em `src/firebase_credenciais.json`.
 *  - Ter as dependências do Google Cloud PHP instaladas (composer require google/cloud-firestore)
 */

require __DIR__ . '/vendor/autoload.php';

use Google\Cloud\Firestore\FirestoreClient;

// Localiza arquivo de credenciais
$possible = [
    __DIR__ . '/../firebase_credenciais.json',
    __DIR__ . '/firebase_credenciais.json',
    __DIR__ . '/..//firebase_credenciais.json'
];
$cred = null;
foreach ($possible as $p) {
    if (file_exists($p)) { $cred = $p; break; }
}

if (!$cred) {
    fwrite(STDERR, "Arquivo de credenciais firebase_credenciais.json não encontrado.\nColoque-o em c: \\\\demanda ou em src/.\n");
    exit(1);
}

$dryRun = !in_array('--run', $argv, true);

// Conexão PDO (mesma configuração do `src/index.php`)
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_DATABASE') ?: 'costureira_db';
$username = getenv('DB_USERNAME') ?: 'costureira_user';
$password = getenv('DB_PASSWORD') ?: 'costureira_pass';

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Falha ao conectar MySQL: " . $e->getMessage() . "\n");
    exit(1);
}

// Conectar Firestore
try {
    $fs = new FirestoreClient([
        'keyFilePath' => $cred
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "Falha ao iniciar Firestore: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Firestore cred: {$cred}\n";
echo ($dryRun ? "MODO: Simulação (dry-run). Use --run para executar.\n" : "MODO: EXECUÇÃO REAL.\n");

// Lista coleções de topo
$collections = iterator_to_array($fs->collections());
if (count($collections) === 0) {
    echo "Nenhuma coleção encontrada no Firestore.\n";
    exit(0);
}

echo "Coleções encontradas:\n";
foreach ($collections as $col) {
    echo " - " . $col->id() . "\n";
}

// Mapeamentos básicos por nome de coleção
$mappings = [
    'clientes' => 'clientes',
    'clientes_v1' => 'clientes',
    'users' => 'usuarios',
    'usuarios' => 'usuarios',
    'medidas' => 'medidas_clientes',
    'medidas_clientes' => 'medidas_clientes',
    'lojas' => 'lojas',
    'servicos' => 'servicos',
    'remessas' => 'remessas'
];

// Função auxiliar para inserir array em tabela via PDO (só campos existentes)
function insertArrayIntoTable(PDO $pdo, string $table, array $data, bool $dryRun) {
    // Buscar colunas da tabela
    $colsStmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
    $insert = [];
    foreach ($data as $k => $v) {
        if (in_array($k, $cols, true)) $insert[$k] = $v;
    }
    if (empty($insert)) return false;

    if ($dryRun) {
        echo "[DRY] Inserir em {$table}: " . json_encode($insert, JSON_UNESCAPED_UNICODE) . "\n";
        return true;
    }

    $fields = implode('`,`', array_keys($insert));
    $placeholders = implode(',', array_fill(0, count($insert), '?'));
    $sql = "INSERT INTO `{$table}` (`{$fields}`) VALUES ({$placeholders})";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($insert));
    return $pdo->lastInsertId() ?: true;
}

// Percorre coleções e importa documentos com heurística simples
foreach ($collections as $col) {
    $colId = $col->id();
    $targetTable = $mappings[$colId] ?? null;
    echo "\nProcessando coleção: {$colId}" . ($targetTable ? " → tabela `{$targetTable}`" : " → sem mapeamento automático") . "\n";

    $docs = $col->documents();
    $count = 0;
    foreach ($docs as $doc) {
        if (!$doc->exists()) continue;
        $data = $doc->data();

        // Heurísticas: transformar campos de timestamp/datetime para string compatível
        array_walk_recursive($data, function (&$v) {
            if (is_object($v) && method_exists($v, 'format')) {
                $v = $v->format('Y-m-d H:i:s');
            }
        });

        if ($targetTable) {
            $res = insertArrayIntoTable($pdo, $targetTable, $data, $dryRun);
            if ($res !== false) $count++;
        } else {
            // Caso sem mapeamento, apenas mostrar resumo
            if ($dryRun) echo "  [DRY] Documento {$doc->id()} => " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }

    echo "Coleção {$colId}: documentos processados: {$count}\n";
}

echo "\nImportação concluída (ou simulada). Revise os logs acima.\n";

if ($dryRun) {
    echo "Para executar de fato, rode: php import_firestore.php --run\n";
} else {
    echo "Execução real concluída com sucesso.\n";
}
