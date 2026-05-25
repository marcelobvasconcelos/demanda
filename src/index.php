<?php
/**
 * Demanda - Sistema de Gestão de Costura e Finanças (Ateliê)
 * Interface Principal com Duas Abas: Dashboard Geral & Remessas do Mês
 */

session_start();

require __DIR__ . '/firebase_helper.php';
require __DIR__ . '/sync_firestore_on_start.php';

$firestoreEnabled = false;
$firestoreStatusMessage = '';
$serviceAccount = null;

if (function_exists('curl_init') && function_exists('openssl_sign')) {
    try {
        $serviceAccount = firestore_get_credentials();
        $firestoreEnabled = true;
    } catch (Throwable $e) {
        $firestoreStatusMessage = 'Firestore offline: ' . $e->getMessage();
    }
} else {
    $firestoreStatusMessage = 'Firestore não disponível: lib cURL ou OpenSSL ausente.';
}

// Conexão MySQL (Opcional, usado para Dashboard e Sincronização)
$dbConnected = false;
$connectionError = '';
$pdo = null;

try {
    $db_host = getenv('DB_HOST') ?: 'mysql';
    $db_port = getenv('DB_PORT') ?: '3306';
    $db_name = getenv('DB_DATABASE') ?: 'costureira_db';
    $db_user = getenv('DB_USERNAME') ?: 'costureira_user';
    $db_pass = getenv('DB_PASSWORD') ?: 'costureira_pass';
    
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 3
    ]);
    $dbConnected = true;
    
    // Sincronização automática em background (a cada 5 minutos por padrão)
    if ($firestoreEnabled) {
        sync_firestore_on_start($pdo);
    }
} catch (Throwable $e) {
    $connectionError = $e->getMessage();
}

// Lojas para select
$lojas = [];
if ($dbConnected && $pdo) {
    $lojas = $pdo->query("SELECT id, nome FROM lojas ORDER BY nome ASC")->fetchAll();
}

// Lógica de Abas e Filtros
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'remessas';

// Filtro de Mês/Ano
$filtroMes = isset($_GET['mes']) ? $_GET['mes'] : (isset($_SESSION['filtro_mes']) ? $_SESSION['filtro_mes'] : date('m'));
$filtroAno = isset($_GET['ano']) ? $_GET['ano'] : (isset($_SESSION['filtro_ano']) ? $_SESSION['filtro_ano'] : date('Y'));
$filtroGeral = ($filtroMes === 'geral');

$_SESSION['filtro_mes'] = $filtroMes;
$_SESSION['filtro_ano'] = $filtroAno;

$msgSuccess = '';
$msgError = '';

// Lógica de Ações / Formulários (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $firestoreEnabled) {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'login') {
        $email = isset($_POST['email']) ? strtolower(trim($_POST['email'])) : '';
        $senha = trim($_POST['senha'] ?? '');
        if ($email === '' || $senha === '') {
            $msgError = 'E-mail e senha são obrigatórios.';
        } else {
            try {
                $signInResult = firebase_auth_sign_in_with_password($email, $senha);
                if ($signInResult && isset($signInResult['email'])) {
                    $_SESSION['usuario_email'] = $signInResult['email'];
                    $_SESSION['usuario_nome'] = $signInResult['displayName'] ?? $signInResult['email'];
                    $_SESSION['firebase_localId'] = $signInResult['localId'] ?? null;
                    $msgSuccess = 'Bem-vindo(a), ' . htmlspecialchars($_SESSION['usuario_nome']) . '!';
                } else {
                    $msgError = 'E-mail ou senha incorretos.';
                }
            } catch (Throwable $e) { $msgError = 'Erro no login: ' . $e->getMessage(); }
        }
    }

    elseif ($action === 'logout') {
        unset($_SESSION['usuario_email'], $_SESSION['usuario_nome'], $_SESSION['firebase_localId']);
        $msgSuccess = 'Sessão encerrada.';
    }

    elseif ($action === 'add_remessa') {
        $peca = trim($_POST['peca_servico'] ?? '');
        $preco = floatval($_POST['preco_unitario'] ?? 0);
        $qtd = intval($_POST['quantidade'] ?? 0);
        $tamanho = $_POST['tamanho'] ?? 'outro';
        $lojaId = $_POST['loja_id'] ?? '';
        $lojaNome = '';
        if ($lojaId !== '' && $dbConnected && $pdo) {
            $stmtLoja = $pdo->prepare("SELECT nome FROM lojas WHERE id = ?");
            $stmtLoja->execute([$lojaId]);
            $lojaRow = $stmtLoja->fetch();
            if ($lojaRow) $lojaNome = $lojaRow['nome'];
        }

        if ($peca === '' || $preco <= 0 || $qtd <= 0) {
            $msgError = 'Preencha os campos obrigatórios corretamente.';
        } else {
            try {
                firestore_add_remessa([
                    'usuario_email' => $_SESSION['usuario_email'],
                    'usuario_uid' => $_SESSION['firebase_localId'],
                    'usuario_nome' => $_SESSION['usuario_nome'],
                    'peca_servico' => $peca,
                    'preco_unitario' => $preco,
                    'quantidade' => $qtd,
                    'tamanho' => $tamanho,
                    'loja_id' => $lojaId,
                    'loja_nome' => $lojaNome,
                    'qtd_entregue' => 0
                ]);
                $msgSuccess = "Lote cadastrado com sucesso!";
            } catch (Throwable $e) { $msgError = 'Erro ao salvar: ' . $e->getMessage(); }
        }
    }

    elseif ($action === 'update_entrega') {
        $docId = trim($_POST['id'] ?? '');
        $collection = trim($_POST['collection'] ?? '');
        $qtdAdicionar = intval($_POST['qtd_adicionar'] ?? 0);
        $qtdAtual = intval($_POST['qtd_atual'] ?? 0);
        $qtdTotal = max(0, $qtdAtual + $qtdAdicionar);
        
        $valorRecebidoAgora = floatval($_POST['valor_recebido_agora'] ?? 0);
        $valorRecebidoAtual = floatval($_POST['valor_recebido_atual'] ?? 0);
        $valorRecebidoTotal = max(0, $valorRecebidoAtual + $valorRecebidoAgora);

        try {
            $dataEntrega = $qtdTotal > 0 ? date('Y-m-d\TH:i:s') : null;
            firestore_update_remessa_entrega($docId, $qtdTotal, $dataEntrega, $collection, $valorRecebidoTotal);
            $msgSuccess = 'Entrega registrada!';
        } catch (Throwable $e) { $msgError = 'Erro: ' . $e->getMessage(); }
    }

    elseif ($action === 'update_pagamento') {
        $docId = trim($_POST['id'] ?? '');
        $collection = trim($_POST['collection'] ?? '');
        $valorRecebidoAgora = floatval($_POST['valor_recebido_agora'] ?? 0);
        $valorRecebidoAtual = floatval($_POST['valor_recebido_atual'] ?? 0);
        $valorRecebidoTotal = max(0, $valorRecebidoAtual + $valorRecebidoAgora);

        try {
            firestore_update_remessa($docId, ['valor_recebido' => $valorRecebidoTotal], $collection);
            $msgSuccess = 'Pagamento atualizado!';
        } catch (Throwable $e) { $msgError = 'Erro: ' . $e->getMessage(); }
    }

    elseif ($action === 'edit_remessa') {
        $docId = $_POST['id'];
        $collection = $_POST['collection'];
        try {
            firestore_update_remessa($docId, [
                'peca_servico' => trim($_POST['peca_servico']),
                'preco_unitario' => floatval($_POST['preco_unitario']),
                'quantidade' => intval($_POST['quantidade']),
                'tamanho' => $_POST['tamanho']
            ], $collection);
            $msgSuccess = 'Lote atualizado!';
        } catch (Throwable $e) { $msgError = 'Erro: ' . $e->getMessage(); }
    }

    elseif ($action === 'delete_remessa') {
        try {
            firestore_delete_document($_POST['collection'], $_POST['id']);
            $msgSuccess = 'Lote excluído.';
        } catch (Throwable $e) { $msgError = 'Erro: ' . $e->getMessage(); }
    }

    elseif ($action === 'sync_remessas') {
        try {
            if ($dbConnected) sync_firestore_on_start($pdo, ['force' => true]);
            $msgSuccess = 'Dados sincronizados!';
            header("Location: ?tab={$activeTab}&mes={$filtroMes}&ano={$filtroAno}");
            exit;
        } catch (Throwable $e) { $msgError = 'Erro: ' . $e->getMessage(); }
    }
}

if (!isset($_SESSION['usuario_email'])) {
    include __DIR__ . '/login_screen.php';
    exit;
}

// ----------------------------------------------------
// Buscar Dados do Firestore
// ----------------------------------------------------
$remessas = [];
$statsRemessas = ['valor_total' => 0.0, 'pecas_totais' => 0, 'pecas_entregues' => 0, 'valor_recebido' => 0.0, 'valor_pendente' => 0.0];

if ($firestoreEnabled) {
    try {
        if ($filtroGeral) {
            $remessas = firestore_get_all_user_remessas($_SESSION['usuario_email']);
        } else {
            $startDate = sprintf('%s-%s-01', $filtroAno, str_pad($filtroMes, 2, '0', STR_PAD_LEFT));
            $endDate = sprintf('%s-%s-%s', $filtroAno, str_pad($filtroMes, 2, '0', STR_PAD_LEFT), date('t', strtotime($startDate)));
            $remessas = firestore_query_remessas($_SESSION['usuario_email'], $startDate, $endDate);
        }

        foreach ($remessas as $r) {
            $qtd = max(0, intval($r['quantidade'] ?? 0));
            $ent = max(0, min(intval($r['qtd_entregue'] ?? 0), $qtd));
            $val = $qtd * floatval($r['preco_unitario'] ?? 0);
            $rec = floatval($r['valor_recebido'] ?? 0);
            $statsRemessas['valor_total'] += $val;
            $statsRemessas['pecas_totais'] += $qtd;
            $statsRemessas['pecas_entregues'] += $ent;
            $statsRemessas['valor_recebido'] += $rec;
        }
        $statsRemessas['valor_pendente'] = max(0, $statsRemessas['valor_total'] - $statsRemessas['valor_recebido']);
    } catch (Throwable $e) { $msgError = 'Falha ao carregar: ' . $e->getMessage(); }
}

function formatReal($val) { return 'R$ ' . number_format($val, 2, ',', '.'); }
function formatDate($d) { 
    if (!$d) return '-'; 
    try { return (new DateTime($d))->format('d/m/Y'); } 
    catch (Throwable $e) { return substr($d, 0, 10); } 
}
function getMesNome($m) {
    if ($m === 'geral') return 'todo o período';
    $mn = ['01'=>'janeiro','02'=>'fevereiro','03'=>'março','04'=>'abril','05'=>'maio','06'=>'junho','07'=>'julho','08'=>'agosto','09'=>'setembro','10'=>'outubro','11'=>'novembro','12'=>'dezembro'];
    return $mn[$m] ?? 'mês';
}
function abreviarNome($n) {
    $p = explode(' ', trim($n));
    return count($p) > 1 ? $p[0] . ' ' . substr(end($p), 0, 1) . '.' : $n;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demanda - Gestão de Ateliê Profissional</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'], serif: ['Playfair Display', 'serif'] }, colors: { atelier: { brand: '#8E7355' } } } } }
    </script>
    <style> body { background-color: #FAF6F0; } </style>
</head>
<body class="font-sans text-stone-800 antialiased min-h-screen flex flex-col">

    <header class="bg-white border-b border-stone-200 sticky top-0 z-30 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 h-20 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-stone-900 rounded-full flex items-center justify-center text-atelier-brand shadow-inner"><i data-lucide="scissors" class="w-6 h-6 transform -rotate-45"></i></div>
                <div><span class="font-serif text-2xl font-bold text-stone-900 block leading-tight">Demanda</span><span class="text-[10px] uppercase tracking-wider text-stone-400 font-bold">Gestão & Finanças</span></div>
            </div>
            <nav class="hidden md:flex gap-2 bg-stone-100 p-1.5 rounded-2xl border border-stone-200">
                <a href="?tab=remessas" class="px-4 py-2 rounded-xl text-xs font-bold transition-all <?php echo $activeTab === 'remessas' ? 'bg-white text-stone-950 shadow-sm' : 'text-stone-500'; ?>">Remessas</a>
                <a href="?tab=dashboard" class="px-4 py-2 rounded-xl text-xs font-bold transition-all <?php echo $activeTab === 'dashboard' ? 'bg-white text-stone-950 shadow-sm' : 'text-stone-500'; ?>">Dashboard</a>
            </nav>
            <div class="flex items-center gap-2">
                <div class="flex items-center gap-1.5 bg-emerald-50 text-emerald-800 px-3 py-1.5 rounded-xl border border-emerald-100 text-[10px] font-bold"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> ONLINE</div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8 flex-grow w-full">
        <?php if ($msgSuccess): ?><div class="mb-6 p-4 bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-2xl flex items-center gap-3 text-sm font-medium shadow-sm"><i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-600"></i><span><?php echo $msgSuccess; ?></span></div><?php endif; ?>
        <?php if ($msgError): ?><div class="mb-6 p-4 bg-rose-50 text-rose-800 border border-rose-200 rounded-2xl flex items-center gap-3 text-sm font-medium shadow-sm"><i data-lucide="alert-circle" class="w-5 h-5 text-rose-600"></i><span><?php echo $msgError; ?></span></div><?php endif; ?>

        <?php if ($activeTab === 'remessas'): ?>
            <section class="bg-white border border-stone-200 rounded-3xl p-5 shadow-sm mb-8 flex flex-col md:flex-row items-center justify-between gap-4">
                <button onclick="toggleModal('modalCalendar')" class="flex items-center gap-3 bg-stone-50 border border-stone-200 p-3 rounded-2xl transition-all shadow-sm cursor-pointer active:scale-95">
                    <div class="p-2 bg-stone-900 text-white rounded-xl"><i data-lucide="calendar" class="w-5 h-5"></i></div>
                    <div><span class="block text-[10px] uppercase font-bold text-stone-400 tracking-wider">Período</span><span class="block font-bold text-stone-900 text-sm capitalize leading-tight"><?php echo getMesNome($filtroMes); ?> <?php echo !$filtroGeral ? 'de ' . $filtroAno : ''; ?></span></div>
                </button>
                <div class="flex items-center gap-2">
                    <button onclick="toggleModal('modalAddRemessa')" class="bg-stone-950 hover:bg-stone-850 text-white font-bold p-3 px-5 rounded-2xl text-sm transition-all flex items-center gap-2 active:scale-95 shadow-md cursor-pointer"><i data-lucide="plus" class="w-4 h-4"></i> Nova Remessa</button>
                    <form method="POST" class="inline"><input type="hidden" name="action" value="sync_remessas"><button type="submit" class="bg-atelier-accent text-stone-900 p-3 rounded-2xl transition-all cursor-pointer active:scale-95 shadow-sm" title="Recarregar"><i data-lucide="refresh-cw" class="w-4 h-4"></i></button></form>
                    <form method="POST" class="inline"><input type="hidden" name="action" value="logout"><button type="submit" class="bg-rose-50 text-rose-600 p-3 rounded-2xl transition-all cursor-pointer active:scale-95 shadow-sm" title="Sair"><i data-lucide="log-out" class="w-4 h-4"></i></button></form>
                </div>
            </section>

            <section class="bg-stone-900 rounded-3xl p-6 mb-8 text-white relative overflow-hidden flex items-center gap-5 shadow-lg">
                <div class="w-16 h-16 bg-stone-800 rounded-2xl flex items-center justify-center border border-stone-750 text-atelier-brand"><i data-lucide="printer" class="w-9 h-9"></i></div>
                <div><h2 class="text-3xl font-bold font-serif tracking-tight uppercase"><?php echo htmlspecialchars(abreviarNome($_SESSION['usuario_nome'])); ?></h2><p class="text-stone-400 text-[10px] tracking-widest uppercase font-bold">Lotes em Produção</p></div>
            </section>

            <section class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
                <?php if (empty($remessas)): ?><div class="col-span-full bg-white border border-stone-200 rounded-3xl p-12 text-center text-stone-500 font-medium">Nenhum lote encontrado para este período.</div><?php endif; ?>
                <?php foreach ($remessas as $r): 
                    $qtd = intval($r['quantidade'] ?? 0); $ent = intval($r['qtd_entregue'] ?? 0);
                    $perc = $qtd > 0 ? round(($ent / $qtd) * 100) : 0;
                    $prUnit = floatval($r['preco_unitario'] ?? 0);
                    $total = $qtd * $prUnit;
                    $rec = floatval($r['valor_recebido'] ?? 0);
                    $pend = max(0, $total - $rec);
                ?>
                    <div class="bg-white border border-stone-200 hover:border-stone-300 rounded-2xl p-5 shadow-sm transition-all flex flex-col">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-lg font-bold text-stone-900 capitalize"><?php echo $qtd; ?> <?php echo htmlspecialchars($r['peca_servico'] ?? 'Lote'); ?>(s)</span>
                            <div class="flex items-center gap-1.5">
                                <button onclick="openUpdatePagamento('<?php echo $r['id']; ?>', <?php echo $rec; ?>, '<?php echo $r['__collection'] ?? 'remessas'; ?>', <?php echo $qtd; ?>, <?php echo $prUnit; ?>)" class="w-8 h-8 rounded-full border-2 border-emerald-200 text-emerald-500 flex items-center justify-center font-bold text-xs hover:bg-emerald-50 cursor-pointer">$</button>
                                <button onclick="openUpdateQtd('<?php echo $r['id']; ?>', <?php echo $ent; ?>, <?php echo $qtd; ?>, '<?php echo $r['__collection'] ?? 'remessas'; ?>', <?php echo $rec; ?>, <?php echo $prUnit; ?>)" class="w-8 h-8 rounded-full border-2 border-stone-300 text-stone-600 flex items-center justify-center font-bold text-xs hover:bg-stone-50 cursor-pointer"><?php echo $perc >= 100 ? '<i data-lucide="check" class="w-4 h-4"></i>' : '+'; ?></button>
                                <button onclick='openEditRemessa(<?php echo json_encode($r); ?>)' class="p-1.5 text-stone-400 hover:text-stone-900"><i data-lucide="pencil" class="w-4 h-4"></i></button>
                                <form method="POST" onsubmit="return confirm('Deseja realmente excluir este lote?');" class="inline">
                                    <input type="hidden" name="action" value="delete_remessa">
                                    <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                    <input type="hidden" name="collection" value="<?php echo $r['__collection'] ?? 'remessas'; ?>">
                                    <button type="submit" class="p-1.5 text-stone-300 hover:text-rose-600 cursor-pointer transition-colors" title="Excluir"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                                </form>
                            </div>
                        </div>
                        <div class="flex items-center justify-between mb-4 text-[10px] font-bold uppercase">
                            <div class="flex items-center gap-2"><span class="text-stone-400">Tam:</span><span class="w-6 h-6 rounded-full border border-stone-200 flex items-center justify-center bg-stone-50"><?php echo htmlspecialchars($r['tamanho'] ?? '-'); ?></span></div>
                            <div class="text-right">
                                <div class="text-emerald-600">Rec: <?php echo formatReal($rec); ?></div>
                                <?php if ($pend > 0): ?><div class="text-rose-500">Falta: <?php echo formatReal($pend); ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="flex justify-between text-[10px] text-stone-400 font-bold mb-1"><span><?php echo formatDate($r['data_cadastro'] ?? null); ?></span><span><?php echo $ent; ?>/<?php echo $qtd; ?></span></div>
                        <div class="w-full bg-stone-100 rounded-full h-1.5 overflow-hidden"><div class="h-full <?php echo $perc >= 100 ? 'bg-emerald-500' : 'bg-stone-900'; ?>" style="width: <?php echo $perc; ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="bg-stone-900 rounded-3xl p-6 text-white grid grid-cols-1 md:grid-cols-3 gap-6 shadow-md border border-stone-950">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 bg-stone-800 text-stone-400 rounded-xl flex items-center justify-center"><i data-lucide="circle-dollar-sign" class="w-5 h-5"></i></div>
                    <div><span class="text-[9px] text-stone-400 tracking-wider uppercase font-bold">Total Bruto</span><h3 class="text-xl font-serif font-bold text-white"><?php echo formatReal($statsRemessas['valor_total']); ?></h3></div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 bg-stone-800 text-emerald-500 rounded-xl flex items-center justify-center"><i data-lucide="wallet" class="w-5 h-5"></i></div>
                    <div><span class="text-[9px] text-stone-400 tracking-wider uppercase font-bold">Recebido</span><h3 class="text-xl font-serif font-bold text-emerald-400"><?php echo formatReal($statsRemessas['valor_recebido']); ?></h3></div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 bg-stone-800 text-rose-500 rounded-xl flex items-center justify-center"><i data-lucide="hand-coins" class="w-5 h-5"></i></div>
                    <div><span class="text-[9px] text-stone-400 tracking-wider uppercase font-bold">A Receber</span><h3 class="text-xl font-serif font-bold text-rose-400"><?php echo formatReal($statsRemessas['valor_pendente']); ?></h3></div>
                </div>
            </section>

        <?php elseif ($activeTab === 'dashboard'): ?>
            <section class="mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div><h1 class="text-3xl font-serif font-bold text-stone-900 tracking-tight">Dashboard de Demanda</h1><p class="text-stone-500 mt-1 text-sm">Faturamento consolidado puxado do Firebase.</p></div>
                <button onclick="toggleModal('modalCalendar')" class="px-4 py-2 bg-white border border-stone-200 rounded-xl text-xs font-bold text-stone-700 flex items-center gap-2 shadow-sm transition-all"><i data-lucide="filter" class="w-3.5 h-3.5"></i> <?php echo $filtroGeral ? 'Visão Geral' : getMesNome($filtroMes) . '/' . $filtroAno; ?></button>
            </section>

            <section class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
                <div class="bg-white border border-stone-200 rounded-3xl p-6 shadow-sm"><div class="flex justify-between"><div class="p-3 bg-stone-100 text-stone-900 rounded-2xl"><i data-lucide="trending-up" class="w-6 h-6"></i></div><span class="text-[9px] font-bold text-stone-400 uppercase tracking-widest">Total</span></div><p class="text-stone-400 font-bold text-[10px] mt-4 uppercase tracking-wider">Faturamento Bruto</p><h3 class="text-3xl font-bold text-stone-900 font-serif mt-1"><?php echo formatReal($statsRemessas['valor_total']); ?></h3></div>
                <div class="bg-white border border-stone-200 rounded-3xl p-6 shadow-sm"><div class="flex justify-between"><div class="p-3 bg-emerald-50 text-emerald-600 rounded-2xl"><i data-lucide="wallet" class="w-6 h-6"></i></div><span class="text-[9px] font-bold text-emerald-700 uppercase tracking-widest">Pago</span></div><p class="text-stone-400 font-bold text-[10px] mt-4 uppercase tracking-wider">Total Recebido</p><h3 class="text-3xl font-bold text-stone-900 font-serif mt-1"><?php echo formatReal($statsRemessas['valor_recebido']); ?></h3></div>
                <div class="bg-white border border-stone-200 rounded-3xl p-6 shadow-sm"><div class="flex justify-between"><div class="p-3 bg-rose-50 text-rose-600 rounded-2xl"><i data-lucide="hand-coins" class="w-6 h-6"></i></div><span class="text-[9px] font-bold text-rose-700 uppercase tracking-widest">Pendente</span></div><p class="text-stone-400 font-bold text-[10px] mt-4 uppercase tracking-wider">A Receber</p><h3 class="text-3xl font-bold text-stone-900 font-serif mt-1"><?php echo formatReal($statsRemessas['valor_pendente']); ?></h3></div>
            </section>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="bg-white border border-stone-200 rounded-3xl p-6 shadow-sm lg:col-span-2">
                    <h2 class="text-xl font-bold text-stone-900 font-serif mb-6 flex items-center gap-2"><i data-lucide="list-checks" class="w-5 h-5 text-stone-500"></i> Detalhamento</h2>
                    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-stone-150">
                        <thead class="text-[10px] font-bold text-stone-400 uppercase tracking-wider"><tr><th class="py-3 text-left">Peça / Data</th><th class="py-3 text-center">Progresso</th><th class="py-3 text-center">Status</th><th class="py-3 text-right">Valor</th></tr></thead>
                        <tbody class="divide-y divide-stone-100 bg-white">
                        <?php foreach (array_slice($remessas, 0, 10) as $r): 
                            $qT = intval($r['quantidade'] ?? 0);
                            $pU = floatval($r['preco_unitario'] ?? 0);
                            $vT = $qT * $pU; 
                            $rT = floatval($r['valor_recebido'] ?? 0); 
                            $pT = max(0, $vT - $rT); 
                            $qE = intval($r['qtd_entregue'] ?? 0);
                        ?>
                            <tr class="hover:bg-stone-50/50 transition-colors">
                                <td class="py-4"><div class="font-bold text-stone-900 text-sm capitalize"><?php echo htmlspecialchars($r['peca_servico'] ?? 'Lote'); ?></div><div class="text-stone-400 text-[9px]"><?php echo formatDate($r['data_cadastro'] ?? null); ?></div></td>
                                <td class="py-4 text-center"><div class="text-[9px] font-bold text-stone-500"><?php echo $qE; ?>/<?php echo $qT; ?></div><div class="w-20 bg-stone-100 h-1.5 rounded-full mx-auto"><div class="h-full bg-stone-900 rounded-full" style="width:<?php echo $qT > 0 ? ($qE / $qT * 100) : 0; ?>%"></div></div></td>
                                <td class="py-4 text-center"><span class="px-2 py-0.5 rounded-full font-bold text-[9px] uppercase border <?php echo $pT <= 0 ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($rT > 0 ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-rose-50 text-rose-700 border-rose-200'); ?>"><?php echo $pT <= 0 ? 'Pago' : ($rT > 0 ? 'Parcial' : 'Pendente'); ?></span></td>
                                <td class="py-4 text-right font-bold text-sm"><?php echo formatReal($vT); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                </div>
                <div class="bg-white border border-stone-200 rounded-3xl p-6 shadow-sm"><h2 class="text-xl font-bold text-stone-900 font-serif mb-6 flex items-center gap-2"><i data-lucide="tape-measure" class="w-5 h-5 text-stone-500"></i> Métricas</h2>
                    <div class="bg-stone-50 rounded-2xl p-6 text-center border border-dashed border-stone-300"><h4 class="text-3xl font-bold text-stone-900"><?php echo count($remessas); ?></h4><p class="text-stone-400 text-[10px] uppercase font-bold tracking-widest mt-1">Lotes Totais</p></div>
                    <div class="mt-6 space-y-3 text-xs font-medium"><div class="flex justify-between"><span>Peças Totais:</span><span class="font-bold"><?php echo $statsRemessas['pecas_totais']; ?></span></div><div class="flex justify-between"><span>Concluídas:</span><span class="font-bold text-emerald-600"><?php echo $statsRemessas['pecas_entregues']; ?></span></div></div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Modais -->
    <div id="modalAddRemessa" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-stone-950/40" onclick="toggleModal('modalAddRemessa')"></div>
            <div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-md w-full border border-stone-200">
                <div class="bg-stone-900 text-white p-6 flex justify-between items-center">
                    <h3 class="font-serif text-xl font-bold flex items-center gap-2"><i data-lucide="plus-circle" class="w-5 h-5 text-atelier-brand"></i> Cadastro</h3>
                    <button onclick="toggleModal('modalAddRemessa')" class="text-stone-400 hover:text-white"><i data-lucide="x" class="w-6 h-6"></i></button>
                </div>
                <form method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="add_remessa">
                    <input type="text" name="peca_servico" required placeholder="Peça/Serviço" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:border-stone-900 outline-none">
                    <div class="grid grid-cols-2 gap-4">
                        <input type="number" step="0.01" name="preco_unitario" required placeholder="Preço (R$)" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:border-stone-900 outline-none">
                        <input type="number" name="quantidade" required placeholder="Qtd" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:border-stone-900 outline-none">
                    </div>
                    <div class="flex flex-wrap gap-2 justify-center py-2 bg-stone-50 rounded-2xl border border-stone-200">
                        <?php foreach (['PP','P','M','G','GG','XG','outro'] as $t): ?>
                            <label class="flex flex-col items-center gap-1 px-3 py-2 border border-stone-200 rounded-xl cursor-pointer hover:bg-white transition-all text-xs font-bold">
                                <input type="radio" name="tamanho" value="<?php echo $t; ?>" <?php echo $t==='M'?'checked':''; ?>>
                                <span><?php echo $t; ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <select name="loja_id" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:border-stone-900 outline-none">
                        <option value="">Particular</option>
                        <?php foreach ($lojas as $l): ?>
                            <option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars($l['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="w-full bg-stone-950 text-white font-bold py-3.5 rounded-xl text-sm transition-all active:scale-95 cursor-pointer">Salvar</button>
                </form>
            </div>
        </div>
    </div>

    <div id="modalUpdateQtd" class="fixed inset-0 z-50 overflow-y-auto hidden"><div class="flex items-center justify-center min-h-screen px-4"><div class="fixed inset-0 bg-stone-950/40" onclick="toggleModal('modalUpdateQtd')"></div><div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-sm w-full border border-stone-200"><div class="bg-stone-900 text-white p-5 flex justify-between items-center"><h3 class="font-serif text-lg font-bold">Registrar Entregas</h3><button onclick="toggleModal('modalUpdateQtd')"><i data-lucide="x" class="w-5 h-5"></i></button></div><form method="POST" class="p-6 space-y-4"><input type="hidden" name="action" value="update_entrega"><input type="hidden" name="id" id="updateQtdId"><input type="hidden" name="collection" id="updateQtdCollection"><input type="hidden" name="qtd_atual" id="updateQtdAtualHidden"><input type="hidden" name="valor_recebido_atual" id="updateValorRecebidoAtualHidden"><p class="text-stone-500 text-xs">Entregou <span id="updateQtdAtualLabel" class="font-bold text-stone-900">0</span> de <span id="updateQtdMaxLabel" class="font-bold text-stone-900">0</span>. Faltam: <span id="updateQtdFaltaLabel" class="font-bold text-rose-600">0</span>.</p><div class="flex items-center gap-3"><input type="number" name="qtd_adicionar" id="updateQtdAdicionar" required value="1" class="flex-grow bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:border-stone-900 outline-none"><button type="button" onclick="setQtdRestante()" class="bg-stone-100 px-3 py-3 rounded-xl text-xs font-bold border border-stone-300 cursor-pointer">Tudo</button></div><div class="space-y-1"><label class="text-[10px] font-bold text-stone-400 uppercase">Receber Agora (R$)</label><input type="number" step="0.01" name="valor_recebido_agora" id="updateValorRecebidoAgora" placeholder="0,00" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:border-stone-900 outline-none"><p class="text-[10px] text-stone-400 mt-1">Rec: <span id="updateValorRecebidoLabel" class="font-bold">R$ 0,00</span> / Pend: <span id="updateValorPendenteLabel" class="font-bold text-rose-500">R$ 0,00</span></p></div><button type="submit" class="w-full bg-stone-950 text-white font-bold py-3.5 rounded-xl text-sm transition-all active:scale-95 cursor-pointer">Confirmar</button></form></div></div></div>

    <div id="modalUpdatePagamento" class="fixed inset-0 z-50 overflow-y-auto hidden"><div class="flex items-center justify-center min-h-screen px-4"><div class="fixed inset-0 bg-stone-950/40" onclick="toggleModal('modalUpdatePagamento')"></div><div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-sm w-full border border-stone-200"><div class="bg-emerald-900 text-white p-5 flex justify-between items-center"><h3 class="font-serif text-lg font-bold">Pagamento</h3><button onclick="toggleModal('modalUpdatePagamento')"><i data-lucide="x" class="w-5 h-5"></i></button></div><form method="POST" class="p-6 space-y-4"><input type="hidden" name="action" value="update_pagamento"><input type="hidden" name="id" id="updatePagId"><input type="hidden" name="collection" id="updatePagCollection"><input type="hidden" name="valor_recebido_atual" id="updatePagAtualHidden"><input type="number" step="0.01" name="valor_recebido_agora" required placeholder="Valor (R$)" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:border-stone-900 outline-none"><p class="text-[10px] text-stone-400">Rec: <span id="updatePagLabel" class="font-bold">R$ 0,00</span>. Falta: <span id="updatePagPendenteLabel" class="font-bold text-rose-600">R$ 0,00</span></p><button type="submit" class="w-full bg-emerald-600 text-white font-bold py-3.5 rounded-xl text-sm transition-all active:scale-95 cursor-pointer">Salvar</button></form></div></div></div>

    <div id="modalCalendar" class="fixed inset-0 z-50 overflow-y-auto hidden"><div class="flex items-center justify-center min-h-screen px-4"><div class="fixed inset-0 bg-stone-950/40" onclick="toggleModal('modalCalendar')"></div><div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-sm w-full border border-stone-200"><div class="bg-stone-950 text-white p-5 flex justify-between items-center"><div><span class="text-[10px] uppercase font-bold text-stone-400">Ano</span><span id="calendarYearLabel" class="text-2xl font-serif font-bold block"><?php echo $filtroAno; ?></span></div><div class="flex gap-2"><button onclick="adjustFilterYear(-1)" class="p-1 bg-stone-800 rounded-lg"><i data-lucide="chevron-up" class="w-4 h-4"></i></button><button onclick="adjustFilterYear(1)" class="p-1 bg-stone-800 rounded-lg"><i data-lucide="chevron-down" class="w-4 h-4"></i></button></div></div><div class="p-6"><div class="grid grid-cols-3 gap-2 mb-6"><?php $ms=['01'=>'Jan','02'=>'Fev','03'=>'Mar','04'=>'Abr','05'=>'Mai','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Set','10'=>'Out','11'=>'Nov','12'=>'Dez']; foreach ($ms as $n=>$s): ?><button onclick="setFilterPeriod('<?php echo $n; ?>')" class="py-3 rounded-xl text-xs font-bold border transition-all <?php echo $filtroMes===$n?'bg-blue-500 border-blue-600 text-white':'bg-stone-50 border-stone-200'; ?>"><?php echo $s; ?></button><?php endforeach; ?></div><button onclick="setFilterPeriod('geral')" class="w-full py-4 rounded-xl text-[10px] font-bold border transition-all <?php echo $filtroGeral?'bg-stone-900 text-white':'bg-white border-stone-200'; ?> uppercase tracking-widest">Ver Todo o Histórico</button></div></div></div></div>

    <script>
        function toggleModal(id) { const m = document.getElementById(id); m.classList.toggle('hidden'); }
        function toggleDetails(id) { document.getElementById('details-'+id).classList.toggle('hidden'); }
        let maxLoteAtual=0, entAtu=0, valRecAtu=0;
        function openUpdateQtd(id, ent, max, col, rec, pr) {
            maxLoteAtual=max; entAtu=ent; valRecAtu=parseFloat(rec||0); const pend = Math.max(0, (max*parseFloat(pr||0))-valRecAtu);
            document.getElementById('updateQtdId').value=id; document.getElementById('updateQtdCollection').value=col;
            document.getElementById('updateQtdAtualHidden').value=ent; document.getElementById('updateValorRecebidoAtualHidden').value=valRecAtu;
            document.getElementById('updateQtdAtualLabel').innerText=ent; document.getElementById('updateQtdMaxLabel').innerText=max;
            document.getElementById('updateQtdFaltaLabel').innerText=max-ent;
            document.getElementById('updateValorRecebidoLabel').innerText=formatRealJS(valRecAtu);
            document.getElementById('updateValorPendenteLabel').innerText=formatRealJS(pend);
            document.getElementById('updateQtdAdicionar').value=(max-ent)>0?1:0;
            toggleModal('modalUpdateQtd');
        }
        function openUpdatePagamento(id, rec, col, max, pr) {
            const val=parseFloat(rec||0), tot=parseInt(max||0)*parseFloat(pr||0), pend=Math.max(0,tot-val);
            document.getElementById('updatePagId').value=id; document.getElementById('updatePagCollection').value=col;
            document.getElementById('updatePagAtualHidden').value=val;
            document.getElementById('updatePagLabel').innerText=formatRealJS(val);
            document.getElementById('updatePagPendenteLabel').innerText=formatRealJS(pend);
            toggleModal('modalUpdatePagamento');
        }
        function setQtdRestante() { document.getElementById('updateQtdAdicionar').value = maxLoteAtual - entAtu; }
        function openEditRemessa(r) {
            document.getElementById('editRemessaId').value=r.id; document.getElementById('editRemessaCollection').value=r.__collection;
            document.getElementById('editRemessaPeca').value=r.peca_servico; document.getElementById('editRemessaPreco').value=r.preco_unitario;
            document.getElementById('editRemessaQtd').value=r.quantidade;
            const rad=document.getElementById('editRemessaTamanho_'+(r.tamanho||'outro')); if(rad) rad.checked=true;
            toggleModal('modalEditRemessa');
        }
        function adjustFilterYear(d) { activeFilterYear+=d; document.getElementById('calendarYearLabel').innerText=activeFilterYear; }
        function setFilterPeriod(m) { window.location.href=`?tab=<?php echo $activeTab; ?>&mes=${m}&ano=${activeFilterYear}`; }
        function formatRealJS(v) { return 'R$ ' + v.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}); }
        let activeFilterYear = <?php echo $filtroAno; ?>;
        lucide.createIcons();
    </script>
</body>
</html>
