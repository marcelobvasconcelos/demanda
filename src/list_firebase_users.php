<?php
/**
 * Lista usuários do Firebase Authentication via REST API de administração.
 *
 * Uso CLI:
 *   php src/list_firebase_users.php
 *
 * Uso via browser:
 *   http://<host>/src/list_firebase_users.php
 */

require __DIR__ . '/firebase_helper.php';

$users = [];
$error = null;

try {
    $users = firebase_auth_list_users(1000);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if (php_sapi_name() === 'cli') {
    if ($error) {
        fwrite(STDERR, 'ERROR: ' . $error . PHP_EOL);
        exit(1);
    }

    echo sprintf("%-4s %-40s %-30s %-20s\n", 'ID', 'EMAIL', 'NOME', 'PROVEDOR');
    echo str_repeat('-', 110) . PHP_EOL;
    foreach ($users as $user) {
        $provider = implode(', ', array_column($user['providerUserInfo'] ?? [], 'providerId'));
        echo sprintf(
            "%-4s %-40s %-30s %-20s\n",
            $user['localId'] ?? '',
            $user['email'] ?? '',
            $user['displayName'] ?? ($user['email'] ?? ''),
            $provider
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
    <title>Usuários Firebase Authentication</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #fafafa; }
        table { border-collapse: collapse; width: 100%; max-width: 100%; background: #fff; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #f3f4f6; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { margin-bottom: 18px; }
        .error { background: #fee2e2; color: #991b1b; padding: 16px; border-radius: 12px; margin-bottom: 18px; }
        .info { margin-top: 12px; color: #374151; font-size: 0.95rem; }
        a { color: #1f2937; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Usuários do Firebase Authentication</h1>
            <p class="info">Esta lista consulta o serviço de Autenticação do Firebase, não o banco de dados MySQL local.</p>
            <p class="info"><a href="login_screen.php">Voltar ao login</a></p>
        </div>

        <?php if ($error): ?>
            <div class="error">Erro: <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>E-mail</th>
                    <th>Nome</th>
                    <th>Provedor</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="4">Nenhum usuário encontrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['localId'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($user['displayName'] ?? ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(implode(', ', array_column($user['providerUserInfo'] ?? [], 'providerId')), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
