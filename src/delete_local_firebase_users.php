<?php
/**
 * Remove usuários locais do MySQL quando o mesmo e-mail existe no Firebase Authentication.
 *
 * Uso CLI:
 *   php src/delete_local_firebase_users.php
 *
 * Uso via browser:
 *   http://<host>/src/delete_local_firebase_users.php
 */

require __DIR__ . '/firebase_helper.php';

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

$deleted = [];
$errors = [];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
    $rows = $pdo->query('SELECT id, nome, email FROM usuarios ORDER BY id')->fetchAll();

    foreach ($rows as $row) {
        try {
            $remoteUser = firebase_auth_user_by_email($row['email']);
            if ($remoteUser) {
                $stmt = $pdo->prepare('DELETE FROM usuarios WHERE id = ?');
                $stmt->execute([$row['id']]);
                $deleted[] = $row;
            }
        } catch (Throwable $e) {
            $errors[] = ['email' => $row['email'], 'error' => $e->getMessage()];
        }
    }
} catch (Throwable $e) {
    $errorMessage = 'Falha ao conectar ou consultar localmente: ' . $e->getMessage();
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $errorMessage . PHP_EOL);
    } else {
        echo '<pre>' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    exit(1);
}

if (php_sapi_name() === 'cli') {
    echo 'Usuários locais removidos com conflito no Firebase:' . PHP_EOL;
    foreach ($deleted as $row) {
        echo sprintf("- %s <%s>\n", $row['nome'], $row['email']);
    }
    echo PHP_EOL . 'Total removido: ' . count($deleted) . PHP_EOL;
    if (!empty($errors)) {
        echo PHP_EOL . 'Erros encontrados:' . PHP_EOL;
        foreach ($errors as $err) {
            echo sprintf("- %s: %s\n", $err['email'], $err['error']);
        }
    }
    exit(0);
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Remover Usuários Locais</title>
    <style>body{font-family:Arial,Helvetica,sans-serif;margin:20px;background:#f9fafb;color:#111;}table{border-collapse:collapse;width:100%;}th,td{border:1px solid #d1d5db;padding:10px;text-align:left;}th{background:#f3f4f6;}</style>
</head>
<body>
    <h1>Remoção de Usuários Locais</h1>
    <p>Foram removidos os usuários locais cujo e-mail existe também no Firebase Authentication.</p>
    <p>Total removido: <?php echo count($deleted); ?></p>
    <table>
        <thead>
            <tr><th>ID</th><th>Nome</th><th>E-mail</th></tr>
        </thead>
        <tbody>
            <?php foreach ($deleted as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['nome'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (!empty($errors)): ?>
        <h2>Erros</h2>
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?php echo htmlspecialchars($err['email'], ENT_QUOTES, 'UTF-8'); ?>: <?php echo htmlspecialchars($err['error'], ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</body>
</html>
