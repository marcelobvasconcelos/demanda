<?php
/**
 * Script para verificar variáveis de ambiente do banco de dados
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificar Configuração do Banco</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            margin-top: 0;
        }
        .env-var {
            background: #f8f9fa;
            border-left: 4px solid #6366f1;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
        }
        .env-var strong {
            color: #6366f1;
            display: block;
            margin-bottom: 5px;
        }
        .env-var code {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
        .status {
            padding: 10px 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .status.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .status.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .status.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #6366f1;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
        }
        .btn:hover {
            background: #4f46e5;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>🔍 Verificação de Configuração do Banco de Dados</h1>
        
        <h2>Variáveis de Ambiente Configuradas:</h2>
        
        <?php
        $dbVars = [
            'DB_HOST' => getenv('DB_HOST'),
            'DB_PORT' => getenv('DB_PORT'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
            'DB_USERNAME' => getenv('DB_USERNAME'),
            'DB_PASSWORD' => getenv('DB_PASSWORD') ? '***' . substr(getenv('DB_PASSWORD'), -4) : false,
            'DATABASE_URL' => getenv('DATABASE_URL') ? 'Configurado (PostgreSQL?)' : false,
            'MYSQL_URL' => getenv('MYSQL_URL') ? 'Configurado' : false,
        ];
        
        $configured = 0;
        foreach ($dbVars as $key => $value) {
            if ($value !== false && $value !== '') {
                $configured++;
                echo '<div class="env-var">';
                echo '<strong>' . htmlspecialchars($key) . '</strong>';
                echo '<code>' . htmlspecialchars($value) . '</code>';
                echo '</div>';
            }
        }
        
        if ($configured === 0) {
            echo '<div class="status error">';
            echo '❌ <strong>Nenhuma variável de ambiente de banco de dados configurada!</strong>';
            echo '</div>';
        }
        ?>
        
        <h2>Teste de Conexão:</h2>
        
        <?php
        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '3306';
        $db = getenv('DB_DATABASE') ?: 'costureira_db';
        $user = getenv('DB_USERNAME') ?: 'costureira_user';
        $pass = getenv('DB_PASSWORD') ?: 'costureira_pass';
        
        try {
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5
            ]);
            
            echo '<div class="status success">';
            echo '✅ <strong>Conexão com MySQL bem-sucedida!</strong><br>';
            echo 'Host: ' . htmlspecialchars($host) . ':' . htmlspecialchars($port);
            echo '</div>';
            
            // Verifica se o banco existe
            $stmt = $pdo->query("SHOW DATABASES LIKE '{$db}'");
            if ($stmt->rowCount() > 0) {
                echo '<div class="status success">';
                echo '✅ Banco de dados <strong>' . htmlspecialchars($db) . '</strong> existe!';
                echo '</div>';
                
                // Conecta no banco e verifica tabelas
                $pdo->exec("USE {$db}");
                $tables = ['atelie_clientes', 'atelie_servicos_catalogo', 'atelie_pedidos', 'atelie_itens_pedido'];
                $existingTables = [];
                
                foreach ($tables as $table) {
                    $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
                    if ($stmt->rowCount() > 0) {
                        $existingTables[] = $table;
                    }
                }
                
                if (count($existingTables) === count($tables)) {
                    echo '<div class="status success">';
                    echo '✅ <strong>Todas as tabelas do módulo Ateliê existem!</strong><br>';
                    echo 'Tabelas encontradas: ' . implode(', ', $existingTables);
                    echo '</div>';
                    
                    echo '<div class="status success">';
                    echo '🎉 <strong>TUDO CONFIGURADO CORRETAMENTE!</strong><br>';
                    echo 'O módulo Ateliê Sob Medida está pronto para uso.';
                    echo '</div>';
                    
                    echo '<a href="atelie_sob_medida.php" class="btn">Acessar Módulo Ateliê →</a>';
                } else {
                    $missing = array_diff($tables, $existingTables);
                    echo '<div class="status warning">';
                    echo '⚠️ <strong>Faltam tabelas do módulo Ateliê:</strong><br>';
                    echo implode(', ', $missing);
                    echo '<br><br>Execute o script <code>docker/mysql/init.sql</code> no banco de dados.';
                    echo '</div>';
                }
            } else {
                echo '<div class="status warning">';
                echo '⚠️ Banco de dados <strong>' . htmlspecialchars($db) . '</strong> não existe!<br>';
                echo 'Crie o banco ou execute o script <code>docker/mysql/init.sql</code>';
                echo '</div>';
            }
            
        } catch (PDOException $e) {
            echo '<div class="status error">';
            echo '❌ <strong>Erro ao conectar:</strong><br>';
            echo htmlspecialchars($e->getMessage());
            echo '</div>';
            
            echo '<div class="status warning">';
            echo '<strong>Possíveis soluções:</strong><br>';
            echo '1. Verifique se as variáveis de ambiente estão corretas no Render<br>';
            echo '2. Verifique se o serviço MySQL está rodando<br>';
            echo '3. Verifique se o firewall permite conexões do Render<br>';
            echo '4. Consulte o guia: <code>GUIA_MYSQL_EXTERNO.md</code>';
            echo '</div>';
        }
        ?>
        
        <a href="index.php" class="btn">← Voltar ao Sistema</a>
    </div>
</body>
</html>
