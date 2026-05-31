<?php
/**
 * Módulo Ateliê Sob Medida
 * 100% MySQL - Não interage com Firebase
 */
session_start();

if (!isset($_SESSION['usuario_email'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/controllers/AtelieController.php';

// Conexão MySQL
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: 'mysql',
        getenv('DB_PORT') ?: '3306',
        getenv('DB_DATABASE') ?: 'costureira_db'
    );
    $pdo = new PDO($dsn, getenv('DB_USERNAME') ?: 'costureira_user', getenv('DB_PASSWORD') ?: 'costureira_pass', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die('Erro de conexão: ' . $e->getMessage());
}

$controller = new AtelieController($pdo);
$msgSuccess = $msgError = '';
$activeView = $_GET['view'] ?? 'pedidos';

// Processar ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'salvar_cliente':
                $controller->salvarCliente($_POST);
                $msgSuccess = 'Cliente salvo com sucesso!';
                break;
            
            case 'excluir_cliente':
                $controller->excluirCliente((int)$_POST['id']);
                $msgSuccess = 'Cliente excluído!';
                break;
            
            case 'salvar_servico':
                $controller->salvarServicoCatalogo($_POST);
                $msgSuccess = 'Serviço salvo no catálogo!';
                break;
            
            case 'excluir_servico':
                $controller->excluirServicoCatalogo((int)$_POST['id']);
                $msgSuccess = 'Serviço excluído!';
                break;
            
            case 'salvar_pedido':
                $itens = [];
                if (!empty($_POST['servico_id'])) {
                    foreach ($_POST['servico_id'] as $idx => $sid) {
                        if ($sid) {
                            $itens[] = [
                                'servico_id' => (int)$sid,
                                'quantidade' => (int)($_POST['quantidade'][$idx] ?? 1),
                                'preco_aplicado' => (float)($_POST['preco_aplicado'][$idx] ?? 0)
                            ];
                        }
                    }
                }
                $controller->salvarPedido([
                    'cliente_id' => (int)$_POST['cliente_id'],
                    'valor_pago' => (float)($_POST['valor_pago'] ?? 0),
                    'status_entrega' => $_POST['status_entrega'] ?? 'Pendente',
                    'observacoes' => $_POST['observacoes'] ?? '',
                    'data_pedido' => $_POST['data_pedido'] ?? date('Y-m-d'),
                    'itens' => $itens
                ]);
                $msgSuccess = 'Pedido registrado com sucesso!';
                break;
            
            case 'atualizar_pedido':
                $controller->atualizarPedido(
                    (int)$_POST['id'],
                    (float)$_POST['valor_pago'],
                    $_POST['status_entrega']
                );
                $msgSuccess = 'Pedido atualizado!';
                break;
            
            case 'excluir_pedido':
                $controller->excluirPedido((int)$_POST['id']);
                $msgSuccess = 'Pedido excluído!';
                break;
        }
    } catch (Throwable $e) {
        $msgError = 'Erro: ' . $e->getMessage();
    }
}

// Buscar dados
$clientes = $controller->listarClientes();
$catalogo = $controller->listarCatalogo();
$resumo = $controller->resumoFinanceiro();

$filtros = [];
if (!empty($_GET['status_entrega'])) $filtros['status_entrega'] = $_GET['status_entrega'];
if (!empty($_GET['status_pagamento'])) $filtros['status_pagamento'] = $_GET['status_pagamento'];
$pedidos = $controller->listarPedidos($filtros);

function formatReal($v) { return 'R$ ' . number_format($v, 2, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ateliê Sob Medida - Demanda</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'], serif: ['Playfair Display', 'serif'] }
                }
            }
        }
    </script>
    <style>
        .glass { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(16px); }
        body { background: #fdfbf7; }
    </style>
</head>
<body class="font-sans text-slate-900">

    <!-- Header -->
    <header class="glass sticky top-0 z-40 border-b border-slate-200/60 px-4 py-5">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="index.php" class="w-12 h-12 bg-purple-600 rounded-2xl flex items-center justify-center text-white shadow-xl rotate-[-5deg] hover:rotate-0 transition-transform">
                    <i data-lucide="ruler" class="w-6 h-6"></i>
                </a>
                <div>
                    <h1 class="font-serif text-2xl font-black text-slate-900">Ateliê Sob Medida</h1>
                    <p class="text-[9px] uppercase tracking-widest font-black text-slate-400">Gestão de Clientes e Pedidos</p>
                </div>
            </div>
            <a href="index.php" class="px-6 py-3 bg-slate-100 text-slate-600 rounded-2xl font-bold text-sm hover:bg-slate-200 transition-all flex items-center gap-2">
                <i data-lucide="arrow-left" class="w-4 h-4"></i> Voltar
            </a>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 pt-10 pb-20">
        
        <?php if ($msgSuccess): ?>
            <div class="mb-8 p-5 bg-emerald-500 text-white rounded-3xl shadow-xl flex items-center gap-4">
                <i data-lucide="check" class="w-6 h-6"></i>
                <span class="font-bold"><?= htmlspecialchars($msgSuccess) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($msgError): ?>
            <div class="mb-8 p-5 bg-rose-500 text-white rounded-3xl shadow-xl flex items-center gap-4">
                <i data-lucide="alert-circle" class="w-6 h-6"></i>
                <span class="font-bold"><?= htmlspecialchars($msgError) ?></span>
            </div>
        <?php endif; ?>

        <!-- Cards de Resumo -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
            <div class="bg-purple-600 p-6 rounded-3xl text-white shadow-2xl">
                <span class="text-[10px] font-black uppercase opacity-70 block mb-1">Total de Pedidos</span>
                <div class="text-4xl font-black"><?= $resumo['total_pedidos'] ?? 0 ?></div>
            </div>
            <div class="bg-indigo-600 p-6 rounded-3xl text-white shadow-2xl">
                <span class="text-[10px] font-black uppercase opacity-70 block mb-1">Faturamento</span>
                <div class="text-3xl font-black"><?= formatReal($resumo['faturamento'] ?? 0) ?></div>
            </div>
            <div class="bg-emerald-500 p-6 rounded-3xl text-white shadow-2xl">
                <span class="text-[10px] font-black uppercase opacity-70 block mb-1">Recebido</span>
                <div class="text-3xl font-black"><?= formatReal($resumo['recebido'] ?? 0) ?></div>
            </div>
            <div class="bg-rose-500 p-6 rounded-3xl text-white shadow-2xl">
                <span class="text-[10px] font-black uppercase opacity-70 block mb-1">Pendente</span>
                <div class="text-3xl font-black"><?= formatReal($resumo['pendente'] ?? 0) ?></div>
            </div>
        </div>

        <!-- Navegação de Abas -->
        <div class="flex gap-2 mb-8 bg-slate-100 p-2 rounded-2xl w-fit">
            <a href="?view=pedidos" class="px-6 py-3 rounded-xl font-bold text-sm transition-all <?= $activeView === 'pedidos' ? 'bg-white text-purple-600 shadow-md' : 'text-slate-500 hover:text-slate-800' ?>">
                <i data-lucide="shopping-bag" class="w-4 h-4 inline mr-2"></i>Pedidos
            </a>
            <a href="?view=clientes" class="px-6 py-3 rounded-xl font-bold text-sm transition-all <?= $activeView === 'clientes' ? 'bg-white text-purple-600 shadow-md' : 'text-slate-500 hover:text-slate-800' ?>">
                <i data-lucide="users" class="w-4 h-4 inline mr-2"></i>Clientes
            </a>
            <a href="?view=catalogo" class="px-6 py-3 rounded-xl font-bold text-sm transition-all <?= $activeView === 'catalogo' ? 'bg-white text-purple-600 shadow-md' : 'text-slate-500 hover:text-slate-800' ?>">
                <i data-lucide="package" class="w-4 h-4 inline mr-2"></i>Catálogo
            </a>
        </div>

        <?php if ($activeView === 'pedidos'): ?>
            <?php include __DIR__ . '/views/atelie/pedidos.php'; ?>
        <?php elseif ($activeView === 'clientes'): ?>
            <?php include __DIR__ . '/views/atelie/clientes.php'; ?>
        <?php elseif ($activeView === 'catalogo'): ?>
            <?php include __DIR__ . '/views/atelie/catalogo.php'; ?>
        <?php endif; ?>

    </main>

    <script>
        function toggleModal(id) { document.getElementById(id).classList.toggle('hidden'); }
        lucide.createIcons();
    </script>
</body>
</html>
