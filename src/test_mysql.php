<?php
/**
 * Script de teste de conexão MySQL
 * Testa diferentes configurações para encontrar a correta
 */

echo "<h2>Teste de Conexão MySQL</h2>";
echo "<pre>";

$configs = [
    [
        'name' => 'Localhost padrão',
        'host' => 'localhost',
        'port' => '3306',
        'db' => 'costureira_db',
        'user' => 'costureira_user',
        'pass' => 'costureira_pass'
    ],
    [
        'name' => 'Localhost root',
        'host' => 'localhost',
        'port' => '3306',
        'db' => 'costureira_db',
        'user' => 'root',
        'pass' => ''
    ],
    [
        'name' => '127.0.0.1 padrão',
        'host' => '127.0.0.1',
        'port' => '3306',
        'db' => 'costureira_db',
        'user' => 'costureira_user',
        'pass' => 'costureira_pass'
    ],
    [
        'name' => '127.0.0.1 root',
        'host' => '127.0.0.1',
        'port' => '3306',
        'db' => 'costureira_db',
        'user' => 'root',
        'pass' => ''
    ],
    [
        'name' => 'Docker (db)',
        'host' => 'db',
        'port' => '3306',
        'db' => 'costureira_db',
        'user' => 'costureira_user',
        'pass' => 'costureira_pass'
    ]
];

foreach ($configs as $config) {
    echo "\n🔍 Testando: {$config['name']}\n";
    echo "   Host: {$config['host']}:{$config['port']}\n";
    echo "   User: {$config['user']}\n";
    echo "   DB: {$config['db']}\n";
    
    try {
        $dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2
        ]);
        
        echo "   ✅ CONEXÃO OK!\n";
        
        // Verifica se o banco existe
        $stmt = $pdo->query("SHOW DATABASES LIKE '{$config['db']}'");
        if ($stmt->rowCount() > 0) {
            echo "   ✅ Banco '{$config['db']}' EXISTE!\n";
            
            // Tenta conectar no banco
            $pdo->exec("USE {$config['db']}");
            
            // Verifica tabelas do módulo Ateliê
            $tables = ['atelie_clientes', 'atelie_servicos_catalogo', 'atelie_pedidos', 'atelie_itens_pedido'];
            $existingTables = [];
            foreach ($tables as $table) {
                $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
                if ($stmt->rowCount() > 0) {
                    $existingTables[] = $table;
                }
            }
            
            if (count($existingTables) === count($tables)) {
                echo "   ✅ TODAS as tabelas do Ateliê existem!\n";
                echo "\n   🎉 CONFIGURAÇÃO CORRETA ENCONTRADA!\n";
                echo "\n   Use estas configurações:\n";
                echo "   DB_HOST={$config['host']}\n";
                echo "   DB_PORT={$config['port']}\n";
                echo "   DB_DATABASE={$config['db']}\n";
                echo "   DB_USERNAME={$config['user']}\n";
                echo "   DB_PASSWORD={$config['pass']}\n";
                break;
            } else {
                echo "   ⚠️  Faltam tabelas: " . implode(', ', array_diff($tables, $existingTables)) . "\n";
                echo "   Execute o script init.sql para criar as tabelas\n";
            }
        } else {
            echo "   ⚠️  Banco '{$config['db']}' NÃO EXISTE\n";
            echo "   Crie o banco ou execute o script init.sql\n";
        }
        
    } catch (PDOException $e) {
        echo "   ❌ ERRO: " . $e->getMessage() . "\n";
    }
    
    echo str_repeat('-', 70) . "\n";
}

echo "\n</pre>";
?>
