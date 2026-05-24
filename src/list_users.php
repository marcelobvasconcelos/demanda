<?php
/**
 * Lista todos os usuários locais do banco de dados.
 *
 * Uso CLI:
 *   php src/list_users.php
 *
 * Uso via browser:
 *   http://<host>/src/list_users.php
 */

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$database = getenv('DB_DATABASE') ?: 'costureira_db';
$username = getenv('DB_USERNAME') ?: 'costureira_user';
$password = getenv('DB_PASSWORD') ?: 'costureira_pass';

$dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 5,
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
    $stmt = $pdo->query('SELECT id, nome, email, data_cadastro FROM usuarios ORDER BY id');
    $users = $stmt->fetchAll();
} catch (Throwable $e) {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    } else {
        echo '<pre>ERROR: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    exit(1);
}

if (php_sapi_name() === 'cli') {
    echo sprintf("%-4s %-30s %-40s %-20s\n", 'ID', 'NOME', 'EMAIL', 'DATA CADASTRO');
    echo str_repeat('-', 100) . PHP_EOL;
    foreach ($users as $user) {
        echo sprintf(
            "%-4s %-30s %-40s %-20s\n",
            $user['id'], 
            $user['nome'], 
            $user['email'], 
            $user['data_cadastro'] ?? ''
        );
    }
    echo PHP_EOL;
    echo 'Total: ' . count($users) . ' usuário(s)' . PHP_EOL;
    exit(0);
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Lista de Usuários</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; max-width: 100%; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #f4f4f4; }
    </style>
</head>
<body>
    <h1>Usuários</h1>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nome</th>
                <th>E-mail</th>
                <th>Data de Cadastro</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo htmlspecialchars($user['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($user['nome'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($user['data_cadastro'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p>Total: <?php echo count($users); ?> usuário(s).</p>
</body>
</html>
