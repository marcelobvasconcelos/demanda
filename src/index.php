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
    // Detecta automaticamente se está no Docker ou local
    $defaultHost = (getenv('DOCKER_ENV') === 'true' || file_exists('/.dockerenv')) ? 'db' : 'localhost';
    
    $db_host = getenv('DB_HOST') ?: $defaultHost;
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
} catch (Throwable $e) {
    $connectionError = $e->getMessage();
}

// Lojas para select
$lojas = [];
if ($dbConnected && $pdo) {
    $lojas = $pdo->query("SELECT id, nome FROM lojas ORDER BY nome ASC")->fetchAll();
}

// Lógica de Abas e Filtros (Atualizado com correções de campos)
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
        $senha = trim($_POST['password'] ?? '');
        if ($email === '' || $senha === '') {
            $msgError = 'E-mail e senha são obrigatórios.';
        } else {
            try {
                $loginResult = firebase_auth_sign_in_with_password($email, $senha);
                if ($loginResult['success']) {
                    $userData = $loginResult['data'];
                    $_SESSION['usuario_email'] = $userData['email'];
                    $_SESSION['usuario_nome'] = $userData['displayName'] ?? $userData['email'];
                    $_SESSION['firebase_localId'] = $userData['localId'] ?? null;
                    $msgSuccess = 'Bem-vindo(a), ' . htmlspecialchars($_SESSION['usuario_nome']) . '!';
                } else {
                    $msgError = $loginResult['error'] ?: 'E-mail ou senha incorretos.';
                }
            } catch (Throwable $e) { $msgError = 'Erro no login: ' . $e->getMessage(); }
        }
    }

    elseif ($action === 'forgot_password') {
        $email = isset($_POST['email']) ? strtolower(trim($_POST['email'])) : '';
        if ($email === '') {
            $msgError = 'Digite seu e-mail para recuperar a senha.';
        } else {
            if (firebase_auth_send_password_reset_email($email)) {
                $msgSuccess = 'E-mail de recuperação enviado! Verifique sua caixa de entrada.';
            } else {
                $msgError = 'Falha ao enviar e-mail. Verifique se o endereço está correto.';
            }
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
                    'loja_id' => $_POST['loja_id'] ?? '',
                    'loja_nome' => '',
                    'qtd_entregue' => 0
                ]);
                $msgSuccess = "Lote cadastrado com sucesso!";
                @unlink(__DIR__ . '/tmp/dashboard_cache_' . md5($_SESSION['usuario_email']) . '.json');
            } catch (Throwable $e) { $msgError = 'Erro ao salvar: ' . $e->getMessage(); }
        }
    }

    elseif ($action === 'update_entrega') {
        try {
            firestore_update_remessa_entrega($_POST['id'], intval($_POST['qtd_atual']) + intval($_POST['qtd_adicionar']), date('Y-m-d\TH:i:s'), $_POST['collection'], floatval($_POST['valor_recebido_atual']) + floatval($_POST['valor_recebido_agora']));
            $msgSuccess = 'Entrega registrada!';
            @unlink(__DIR__ . '/tmp/dashboard_cache_' . md5($_SESSION['usuario_email']) . '.json');
        } catch (Throwable $e) { $msgError = 'Erro: ' . $e->getMessage(); }
    }

    elseif ($action === 'update_pagamento' || $action === 'mark_paid_full') {
        try {
            $val = ($action === 'mark_paid_full') ? floatval($_POST['total_lote']) : (floatval($_POST['valor_recebido_atual']) + floatval($_POST['valor_recebido_agora']));
            firestore_update_remessa($_POST['id'], ['valor_recebido' => $val], $_POST['collection']);
            $msgSuccess = 'Pagamento atualizado!';
            @unlink(__DIR__ . '/tmp/dashboard_cache_' . md5($_SESSION['usuario_email']) . '.json');
        } catch (Throwable $e) { $msgError = 'Erro: ' . $e->getMessage(); }
    }

    elseif ($action === 'edit_remessa') {
        try {
            firestore_update_remessa($_POST['id'], [
                'peca_servico' => trim($_POST['peca_servico']),
                'preco_unitario' => floatval($_POST['preco_unitario']),
                'quantidade' => intval($_POST['quantidade']),
                'tamanho' => $_POST['tamanho']
            ], $_POST['collection']);
            $msgSuccess = 'Lote atualizado!';
            @unlink(__DIR__ . '/tmp/dashboard_cache_' . md5($_SESSION['usuario_email']) . '.json');
        } catch (Throwable $e) { $msgError = 'Erro: ' . $e->getMessage(); }
    }

    elseif ($action === 'delete_remessa') {
        try {
            firestore_delete_document($_POST['collection'], $_POST['id']);
            $msgSuccess = 'Lote excluído.';
            @unlink(__DIR__ . '/tmp/dashboard_cache_' . md5($_SESSION['usuario_email']) . '.json');
        } catch (Throwable $e) { $msgError = 'Erro: ' . $e->getMessage(); }
    }

    elseif ($action === 'sync_remessas') {
        @unlink(__DIR__ . '/tmp/dashboard_cache_' . md5($_SESSION['usuario_email']) . '.json');
        $msgSuccess = 'Dados recarregados!';
    }
}

if (!isset($_SESSION['usuario_email'])) {
    include __DIR__ . '/login_screen.php';
    exit;
}

// ----------------------------------------------------
// Buscar Dados com Cache para não estourar Quota
// ----------------------------------------------------
$remessas = [];
$statsRemessas = ['valor_total' => 0.0, 'pecas_totais' => 0, 'pecas_entregues' => 0, 'valor_recebido' => 0.0, 'valor_pendente' => 0.0];
$statsPorAno = [];

if ($firestoreEnabled) {
    try {
        $cacheFile = __DIR__ . '/tmp/dashboard_cache_' . md5($_SESSION['usuario_email']) . '.json';
        
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 600)) {
            $cacheData = json_decode(file_get_contents($cacheFile), true);
            $allRemessas = $cacheData['all'] ?? [];
        } else {
            $allRemessas = firestore_get_all_user_remessas($_SESSION['usuario_email'], $_SESSION['firebase_localId']);
            if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp', 0755, true);
            @file_put_contents($cacheFile, json_encode(['all' => $allRemessas]));
        }

        // Filtra para a tela atual
        if ($filtroGeral) {
            $remessas = $allRemessas;
        } else {
            $targetPrefix = getMesNome($filtroMes) . '-' . $filtroAno;
            $remessas = array_values(array_filter($allRemessas, function($r) use ($targetPrefix) {
                return strpos($r['__collection'] ?? '', $targetPrefix) === 0;
            }));
        }

        foreach ($remessas as $r) {
            $qT = intval($r['quantidade'] ?? $r['qtd'] ?? 0);
            $qE = intval($r['qtd_entregue'] ?? $r['entregue'] ?? 0);
            $prU = floatval($r['preco_unitario'] ?? $r['precoU'] ?? 0);
            $val = floatval($r['total'] ?? ($qT * $prU));
            $rec = floatval($r['valor_recebido'] ?? 0);
            
            $statsRemessas['valor_total'] += $val;
            $statsRemessas['pecas_totais'] += $qT;
            $statsRemessas['pecas_entregues'] += $qE;
            $statsRemessas['valor_recebido'] += $rec;

            $dataStr = $r['data_cadastro'] ?? $r['data'] ?? null;
            $anoItem = $dataStr ? substr($dataStr, 0, 4) : 'Outros';
            if (!isset($statsPorAno[$anoItem])) $statsPorAno[$anoItem] = ['total' => 0, 'recebido' => 0, 'pecas_total' => 0, 'pecas_entregues' => 0];
            $statsPorAno[$anoItem]['total'] += $val;
            $statsPorAno[$anoItem]['recebido'] += $rec;
            $statsPorAno[$anoItem]['pecas_total'] += $qT;
            $statsPorAno[$anoItem]['pecas_entregues'] += $qE;
        }
        $statsRemessas['valor_pendente'] = max(0, $statsRemessas['valor_total'] - $statsRemessas['valor_recebido']);
        $statsRemessas['pecas_faltam'] = max(0, $statsRemessas['pecas_totais'] - $statsRemessas['pecas_entregues']);
        krsort($statsPorAno);

        // Pendente dos últimos 6 meses (sem novas chamadas ao Firebase, usa cache)
        $pendente6Meses = 0.0;
        $pendente6MesesPorMes = [];
        $mesesNomes = ['01'=>'janeiro','02'=>'fevereiro','03'=>'março','04'=>'abril','05'=>'maio','06'=>'junho','07'=>'julho','08'=>'agosto','09'=>'setembro','10'=>'outubro','11'=>'novembro','12'=>'dezembro'];
        for ($i = 0; $i < 6; $i++) {
            $dt = new DateTime('first day of -' . $i . ' month');
            $prefix = ($mesesNomes[$dt->format('m')] ?? '') . '-' . $dt->format('Y');
            $label = ucfirst($mesesNomes[$dt->format('m')] ?? '') . '/' . $dt->format('Y');
            $totalMes = 0.0; $recMes = 0.0; $pecasTotalMes = 0; $pecasEntreguesMes = 0;
            foreach ($allRemessas as $r) {
                if (strpos($r['__collection'] ?? '', $prefix) === 0) {
                    $qT = intval($r['quantidade'] ?? $r['qtd'] ?? 0);
                    $prU = floatval($r['preco_unitario'] ?? $r['precoU'] ?? 0);
                    $totalMes += floatval($r['total'] ?? ($qT * $prU));
                    $recMes += floatval($r['valor_recebido'] ?? 0);
                    $pecasTotalMes += $qT;
                    $pecasEntreguesMes += intval($r['qtd_entregue'] ?? $r['entregue'] ?? 0);
                }
            }
            $pendMes = max(0, $totalMes - $recMes);
            $pendente6Meses += $pendMes;
            if ($totalMes > 0) $pendente6MesesPorMes[] = ['label' => $label, 'pendente' => $pendMes, 'total' => $totalMes, 'pecas_faltam' => max(0, $pecasTotalMes - $pecasEntreguesMes)];
        }
    } catch (Throwable $e) { $msgError = 'Erro: ' . $e->getMessage(); }
}

// ----------------------------------------------------
// Métricas de Sincronismo (alimentam o painel de saúde)
// ----------------------------------------------------

// 1. Fonte dos dados: veio do Firestore ou do espelho MySQL?
//    O cache JSON local é considerado "firestore" pois foi populado por ele.
//    Só será "mysql" se lotes_buscar() tiver ativado o fallback de cota.
$syncContingencia = false; // true = cota esgotada, exibindo espelho local

// 2. Fila de pendentes: lotes gravados offline aguardando envio ao Firebase
$syncPendentesNaFila = 0;
if ($dbConnected && $pdo) {
    try {
        $syncPendentesNaFila = (int) $pdo
            ->query('SELECT COUNT(*) FROM lotes WHERE sincronizado = 0')
            ->fetchColumn();
    } catch (Throwable) {}
}

// 3. Carimbo do último sync: usa o mtime do arquivo de cache JSON como referência.
//    Se o cache não existir, tenta pegar o atualizado_em mais recente do MySQL.
$syncUltimaVerificacao = null;
$cacheFileRef = __DIR__ . '/tmp/dashboard_cache_' . md5($_SESSION['usuario_email'] ?? '') . '.json';
if (file_exists($cacheFileRef)) {
    $syncUltimaVerificacao = date('d/m/Y \à\s H:i', filemtime($cacheFileRef));
} elseif ($dbConnected && $pdo) {
    try {
        $tsRow = $pdo
            ->query('SELECT MAX(atualizado_em) FROM lotes WHERE sincronizado = 1')
            ->fetchColumn();
        if ($tsRow) {
            $syncUltimaVerificacao = (new DateTime($tsRow))->format('d/m/Y \à\s H:i');
        }
    } catch (Throwable) {}
}

function formatReal($val) { return 'R$ ' . number_format($val, 2, ',', '.'); }
function formatDate($d) { if (!$d) return '-'; try { return (new DateTime($d))->format('d/m/Y'); } catch (Throwable $e) { return substr($d, 0, 10); } }
function getMesNome($m) { $mn = ['01'=>'janeiro','02'=>'fevereiro','03'=>'março','04'=>'abril','05'=>'maio','06'=>'junho','07'=>'julho','08'=>'agosto','09'=>'setembro','10'=>'outubro','11'=>'novembro','12'=>'dezembro']; return $mn[$m] ?? 'todo o período'; }
?>
<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Demanda - Gestão de Ateliê</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'], serif: ['Playfair Display', 'serif'] },
                    colors: {
                        brand: {
                            50: '#f0fdf4', 100: '#dcfce7', 500: '#22c55e', 600: '#16a34a', 700: '#15803d',
                            primary: '#6366f1', secondary: '#ec4899', accent: '#f59e0b'
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .glass { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); }
        .tab-active { color: #6366f1; font-weight: 800; }
        .tab-active::after { content: ''; position: absolute; bottom: -4px; left: 20%; right: 20%; height: 4px; background: #6366f1; border-radius: 100px; }
        .card-shadow { box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.04), 0 8px 15px -6px rgba(0, 0, 0, 0.04); }
        body { background: #fdfbf7; overflow-x: hidden; }
        .mobile-nav-pb { padding-bottom: calc(env(safe-area-inset-bottom) + 90px); }
    </style>
</head>
<body class="font-sans text-slate-900 min-h-full">

    <!-- Header Responsivo -->
    <header class="glass sticky top-0 z-40 border-b border-slate-200/60 px-4 py-4 md:py-5">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 md:w-12 md:h-12 bg-indigo-600 rounded-2xl flex items-center justify-center text-white shadow-xl shadow-indigo-100 rotate-[-5deg]">
                    <i data-lucide="scissors" class="w-5 h-5 md:w-6 md:h-6"></i>
                </div>
                <div class="hidden sm:block">
                    <h1 class="font-serif text-xl md:text-2xl font-black tracking-tight text-slate-900">Demanda</h1>
                    <p class="text-[9px] uppercase tracking-widest font-black text-slate-400">Ateliê Profissional</p>
                </div>
            </div>

            <!-- Navegação Desktop -->
            <nav class="hidden md:flex items-center gap-1 bg-slate-100/80 p-1 rounded-2xl border border-slate-200">
                <a href="?tab=remessas" class="px-6 py-2 rounded-xl text-sm font-extrabold transition-all <?php echo $activeTab==='remessas'?'bg-white text-indigo-600 shadow-md shadow-indigo-50':'text-slate-500 hover:text-slate-800'; ?>">Remessas</a>
                <a href="?tab=dashboard" class="px-6 py-2 rounded-xl text-sm font-extrabold transition-all <?php echo $activeTab==='dashboard'?'bg-white text-indigo-600 shadow-md shadow-indigo-50':'text-slate-500 hover:text-slate-800'; ?>">Dashboard</a>
                <a href="atelie_sob_medida.php" class="px-6 py-2 rounded-xl text-sm font-extrabold transition-all text-purple-600 hover:bg-purple-50 flex items-center gap-2">
                    <i data-lucide="ruler" class="w-4 h-4"></i> Ateliê
                </a>
            </nav>

            <div class="flex items-center gap-2 md:gap-4">
                <?php if ($syncContingencia): ?>
                    <!-- Badge Âmbar: modo de contingência (cota Firebase esgotada) -->
                    <div class="flex items-center gap-1.5 bg-amber-100 text-amber-700 px-3 py-1.5 rounded-full text-[10px] font-black uppercase border border-amber-200">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                        <span class="hidden sm:inline">Contingência</span>
                    </div>
                <?php else: ?>
                    <!-- Badge Verde: conectado ao Firestore em tempo real -->
                    <div class="flex items-center gap-1.5 bg-emerald-100 text-emerald-700 px-3 py-1.5 rounded-full text-[10px] font-black uppercase">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="hidden sm:inline">Online</span>
                    </div>
                <?php endif; ?>
                <form method="POST" onsubmit="return confirm('Deseja sair?')">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="w-10 h-10 md:w-11 md:h-11 rounded-2xl flex items-center justify-center text-slate-400 hover:text-rose-500 hover:bg-rose-50 transition-all">
                        <i data-lucide="log-out" class="w-5 h-5 md:w-6 md:h-6"></i>
                    </button>
                </form>
            </div>
        </div>
    </header>

    <!-- Conteúdo Principal -->
    <main class="max-w-7xl mx-auto px-4 pt-6 md:pt-10 mobile-nav-pb">
        
        <?php if ($msgSuccess): ?>
            <div class="mb-8 p-5 bg-gradient-to-r from-emerald-500 to-emerald-600 text-white rounded-[2rem] shadow-xl shadow-emerald-100 flex items-center gap-4 animate-in fade-in slide-in-from-top-6">
                <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center"><i data-lucide="check" class="w-6 h-6"></i></div>
                <span class="font-bold"><?php echo $msgSuccess; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($activeTab === 'remessas'): ?>
            <!-- Cards de Resumo: 4 colunas quando há peças faltando -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-10">
                <div class="bg-indigo-600 p-6 rounded-[2.5rem] text-white shadow-2xl shadow-indigo-100 relative overflow-hidden">
                    <i data-lucide="trending-up" class="absolute -right-6 -bottom-6 w-32 h-32 opacity-10"></i>
                    <span class="text-[10px] font-black uppercase tracking-widest opacity-70 block mb-1">Faturamento Bruto</span>
                    <div class="text-3xl font-black"><?php echo formatReal($statsRemessas['valor_total']); ?></div>
                </div>
                <div class="bg-emerald-500 p-6 rounded-[2.5rem] text-white shadow-2xl shadow-emerald-100 relative overflow-hidden">
                    <i data-lucide="wallet" class="absolute -right-6 -bottom-6 w-32 h-32 opacity-10"></i>
                    <span class="text-[10px] font-black uppercase tracking-widest opacity-70 block mb-1">Total Recebido</span>
                    <div class="text-3xl font-black"><?php echo formatReal($statsRemessas['valor_recebido']); ?></div>
                </div>
                <div class="bg-white border-2 border-rose-100 p-6 rounded-[2.5rem] shadow-xl shadow-rose-50/50 relative overflow-hidden">
                    <span class="text-[10px] font-black uppercase tracking-widest text-rose-400 block mb-1">Saldo Pendente</span>
                    <div class="text-3xl font-black text-rose-500"><?php echo formatReal($statsRemessas['valor_pendente']); ?></div>
                </div>
                <div class="bg-white border-2 border-violet-100 p-6 rounded-[2.5rem] shadow-xl shadow-violet-50/50 relative overflow-hidden">
                    <i data-lucide="scissors" class="absolute -right-4 -bottom-4 w-24 h-24 opacity-5"></i>
                    <span class="text-[10px] font-black uppercase tracking-widest text-violet-400 block mb-1">Peças a Produzir</span>
                    <div class="text-3xl font-black text-violet-600"><?php echo $statsRemessas['pecas_faltam']; ?></div>
                    <div class="text-[9px] font-bold text-slate-400 mt-1"><?php echo $statsRemessas['pecas_entregues']; ?> / <?php echo $statsRemessas['pecas_totais']; ?> entregues</div>
                </div>
            </div>

            <!-- Cabeçalho de Ação -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
                <div class="flex items-center gap-2">
                    <button onclick="toggleModal('modalCalendar')" class="flex-grow md:flex-initial glass border border-slate-200 px-6 py-4 rounded-[1.5rem] flex items-center gap-4 hover:border-indigo-300 transition-all group">
                        <div class="w-10 h-10 bg-indigo-50 rounded-xl flex items-center justify-center text-indigo-600 group-hover:bg-indigo-600 group-hover:text-white transition-all">
                            <i data-lucide="calendar" class="w-5 h-5"></i>
                        </div>
                        <div class="text-left">
                            <span class="text-[9px] font-black text-slate-400 uppercase tracking-tighter block leading-none">Mês de Referência</span>
                            <span class="text-sm font-black text-slate-800 capitalize leading-tight"><?php echo getMesNome($filtroMes); ?> <?php echo !$filtroGeral ? 'de '.$filtroAno : ''; ?></span>
                        </div>
                    </button>
                    <form method="POST" class="contents">
                        <input type="hidden" name="action" value="sync_remessas">
                        <button type="submit" class="w-14 h-14 md:w-16 md:h-16 bg-white border border-slate-200 rounded-[1.5rem] flex items-center justify-center text-slate-400 hover:text-indigo-600 hover:border-indigo-200 transition-all active:scale-90">
                            <i data-lucide="refresh-cw" class="w-6 h-6"></i>
                        </button>
                    </form>
                </div>
                <button onclick="toggleModal('modalAddRemessa')" class="w-full md:w-auto bg-slate-900 text-white px-8 py-5 rounded-[1.5rem] font-black flex items-center justify-center gap-3 shadow-2xl shadow-slate-200 hover:bg-indigo-600 transition-all active:scale-95">
                    <i data-lucide="plus-circle" class="w-6 h-6 text-indigo-400"></i> Nova Remessa
                </button>
            </div>

            <!-- Grade de Remessas Responsiva -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php if (empty($remessas)): ?>
                    <div class="col-span-full bg-white border-2 border-dashed border-slate-200 rounded-[3rem] p-20 text-center text-slate-400">
                        <div class="w-24 h-24 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i data-lucide="package-open" class="w-10 h-10 opacity-20"></i>
                        </div>
                        <h3 class="text-xl font-black text-slate-800 mb-2">Nenhum lote por aqui</h3>
                        <p class="text-sm font-medium">Tudo limpo para o período selecionado.</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($remessas as $r): 
                    $qT=intval($r['quantidade']??$r['qtd']??0); $qE=intval($r['qtd_entregue']??$r['entregue']??0); 
                    $prU=floatval($r['preco_unitario']??$r['precoU']??0); $rec=floatval($r['valor_recebido']??0); 
                    $tot=$qT*$prU; $pend=max(0,$tot-$rec); $perc=$qT>0?round(($qE/$qT)*100):0;
                    $isPaid = ($pend <= 0.01);
                ?>
                <div class="bg-white rounded-[2.5rem] p-6 card-shadow border border-slate-100 flex flex-col justify-between group hover:border-indigo-200 hover:shadow-2xl transition-all duration-300">
                    <div>
                        <div class="flex justify-between items-start mb-6">
                            <div class="flex-grow">
                                <h3 class="text-xl font-black text-slate-800 capitalize leading-none mb-2"><?php echo htmlspecialchars($r['peca_servico']??'Lote'); ?></h3>
                                <div class="flex flex-wrap gap-2">
                                    <span class="text-[9px] font-black px-2 py-1 bg-slate-100 text-slate-500 rounded-lg border border-slate-200 uppercase">TAM <?php echo htmlspecialchars($r['tamanho']??$r['size']??'-'); ?></span>
                                    <span class="text-[9px] font-black px-2 py-1 bg-indigo-50 text-indigo-500 rounded-lg border border-indigo-100 uppercase"><?php echo formatDate($r['data_cadastro']??$r['data']??null); ?></span>
                                </div>
                            </div>
                            <div class="flex gap-1">
                                <button onclick='openEditRemessa(<?php echo json_encode($r); ?>)' class="w-9 h-9 rounded-xl bg-slate-50 text-slate-300 hover:bg-indigo-50 hover:text-indigo-600 transition-all flex items-center justify-center">
                                    <i data-lucide="edit-3" class="w-4 h-4"></i>
                                </button>
                                <form method="POST" onsubmit="return confirm('Excluir este lote?')">
                                    <input type="hidden" name="action" value="delete_remessa">
                                    <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                    <input type="hidden" name="collection" value="<?php echo $r['__collection']; ?>">
                                    <button type="submit" class="w-9 h-9 rounded-xl bg-slate-50 text-slate-300 hover:bg-rose-50 hover:text-rose-500 transition-all flex items-center justify-center">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="bg-slate-50/50 rounded-3xl p-5 mb-6 space-y-4">
                            <div class="flex justify-between items-end">
                                <div>
                                    <span class="text-[10px] font-black text-slate-400 uppercase block mb-1">Produção</span>
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-2xl font-black text-slate-800"><?php echo $qE; ?></span>
                                        <span class="text-sm font-bold text-slate-400">/ <?php echo $qT; ?></span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <span class="text-[10px] font-black text-slate-400 uppercase block mb-1">Financeiro</span>
                                    <?php if ($isPaid): ?>
                                        <span class="text-emerald-500 font-black text-base uppercase">PAGO</span>
                                    <?php else: ?>
                                        <span class="text-rose-500 font-black text-base uppercase"><?php echo formatReal($pend); ?></span>
                                        <div class="text-[8px] font-bold text-slate-400 mt-0.5">PENDENTE</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="w-full bg-slate-200/50 h-2.5 rounded-full overflow-hidden">
                                <div class="h-full <?php echo $perc>=100?'bg-emerald-500':'bg-indigo-600'; ?> transition-all duration-700" style="width: <?php echo $perc; ?>%"></div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <button onclick="openUpdateQtd('<?php echo $r['id']; ?>','<?php echo $qE; ?>','<?php echo $qT; ?>','<?php echo $r['__collection']; ?>','<?php echo $rec; ?>','<?php echo $prU; ?>')" class="py-4 rounded-2xl bg-indigo-600 text-white font-black text-xs shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition-all active:scale-95">
                            + Entrega
                        </button>
                        <div class="flex gap-2">
                            <button onclick="openUpdatePagamento('<?php echo $r['id']; ?>','<?php echo $rec; ?>','<?php echo $r['__collection']; ?>','<?php echo $qT; ?>','<?php echo $prU; ?>')" class="flex-grow py-4 rounded-2xl border-2 border-emerald-500 text-emerald-600 font-black text-xs hover:bg-emerald-50 transition-all active:scale-95">
                                $
                            </button>
                            <?php if(!$isPaid): ?>
                                <form method="POST" class="contents">
                                    <input type="hidden" name="action" value="mark_paid_full">
                                    <input type="hidden" name="id" value="<?php echo $r['id']; ?>"><input type="hidden" name="collection" value="<?php echo $r['__collection']; ?>"><input type="hidden" name="total_lote" value="<?php echo $tot; ?>">
                                    <button type="submit" class="w-12 h-12 md:w-14 md:h-full rounded-2xl bg-emerald-500 text-white flex items-center justify-center shadow-lg shadow-emerald-100 hover:bg-emerald-600 transition-all active:scale-90">
                                        <i data-lucide="check-check" class="w-5 h-5"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($activeTab === 'dashboard'): ?>
            <!-- ============================================================
                 BARRA DE SAÚDE DO ECOSSISTEMA
                 Exibida apenas na aba Dashboard, acima dos cards financeiros.
                 Não interfere em nenhum cálculo existente.
            ============================================================ -->
            <div class="mb-8 grid grid-cols-1 sm:grid-cols-3 gap-3">

                <!-- Card 1: Status da conexão -->
                <?php if ($syncContingencia): ?>
                <div class="flex items-center gap-4 bg-amber-50 border border-amber-200 rounded-[1.5rem] px-5 py-4">
                    <div class="w-10 h-10 bg-amber-100 text-amber-600 rounded-xl flex items-center justify-center shrink-0">
                        <i data-lucide="wifi-off" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-black uppercase text-amber-500 tracking-widest leading-none mb-0.5">Contingência</p>
                        <p class="text-xs font-bold text-amber-800 leading-tight">Cota Firebase excedida. Exibindo espelho local (MySQL)</p>
                    </div>
                </div>
                <?php else: ?>
                <div class="flex items-center gap-4 bg-emerald-50 border border-emerald-100 rounded-[1.5rem] px-5 py-4">
                    <div class="w-10 h-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center shrink-0">
                        <i data-lucide="database-zap" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-black uppercase text-emerald-500 tracking-widest leading-none mb-0.5">Conectado ao Firestore</p>
                        <p class="text-xs font-bold text-emerald-800 leading-tight">Dados em tempo real</p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Card 2: Fila de sincronismo -->
                <div class="flex items-center gap-4 <?php echo $syncPendentesNaFila > 0 ? 'bg-indigo-50 border-indigo-100' : 'bg-slate-50 border-slate-100'; ?> border rounded-[1.5rem] px-5 py-4">
                    <div class="w-10 h-10 <?php echo $syncPendentesNaFila > 0 ? 'bg-indigo-100 text-indigo-600' : 'bg-slate-100 text-slate-400'; ?> rounded-xl flex items-center justify-center shrink-0">
                        <i data-lucide="refresh-cw" class="w-5 h-5 <?php echo $syncPendentesNaFila > 0 ? 'animate-spin' : ''; ?>"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-black uppercase <?php echo $syncPendentesNaFila > 0 ? 'text-indigo-500' : 'text-slate-400'; ?> tracking-widest leading-none mb-0.5">Fila de Sincronismo</p>
                        <?php if ($syncPendentesNaFila > 0): ?>
                            <p class="text-xs font-bold text-indigo-800 leading-tight"><?php echo $syncPendentesNaFila; ?> lote<?php echo $syncPendentesNaFila > 1 ? 's' : ''; ?> aguardando envio ao app</p>
                        <?php else: ?>
                            <p class="text-xs font-bold text-slate-500 leading-tight">Todos os dados sincronizados com o app mobile</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Card 3: Carimbo do último sync -->
                <div class="flex items-center gap-4 bg-slate-50 border border-slate-100 rounded-[1.5rem] px-5 py-4">
                    <div class="w-10 h-10 bg-slate-100 text-slate-400 rounded-xl flex items-center justify-center shrink-0">
                        <i data-lucide="clock" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-black uppercase text-slate-400 tracking-widest leading-none mb-0.5">Última Verificação</p>
                        <p class="text-xs font-bold text-slate-600 leading-tight">
                            <?php echo $syncUltimaVerificacao ? 'Firebase: ' . $syncUltimaVerificacao : 'Nenhum registro ainda'; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Dashboard Estendido Desktop -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">
                
                <!-- Coluna Principal (Dashboard) -->
                <div class="lg:col-span-2 space-y-8">

                    <!-- Card Pendente 6 Meses -->
                    <div class="bg-gradient-to-br from-rose-500 to-rose-600 rounded-[3rem] p-8 text-white shadow-2xl shadow-rose-100 relative overflow-hidden">
                        <i data-lucide="clock" class="absolute -right-8 -bottom-8 w-48 h-48 opacity-10"></i>
                        <div class="flex items-start justify-between mb-6">
                            <div>
                                <span class="text-[10px] font-black uppercase tracking-[0.2em] opacity-70 block mb-1">A Receber — Últimos 6 Meses</span>
                                <div class="text-5xl font-black tracking-tighter"><?php echo formatReal($pendente6Meses); ?></div>
                            </div>
                            <div class="w-14 h-14 bg-white/20 rounded-2xl flex items-center justify-center">
                                <i data-lucide="hand-coins" class="w-7 h-7"></i>
                            </div>
                        </div>
                        <?php if (!empty($pendente6MesesPorMes)): ?>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 border-t border-white/20 pt-6">
                            <?php foreach ($pendente6MesesPorMes as $pm): ?>
                                <div class="bg-white/10 rounded-2xl px-4 py-3">
                                    <span class="text-[9px] font-black uppercase opacity-60 block mb-0.5"><?php echo $pm['label']; ?></span>
                                    <span class="text-sm font-black block"><?php echo formatReal($pm['pendente']); ?></span>
                                    <?php if ($pm['pecas_faltam'] > 0): ?>
                                        <span class="text-[9px] font-bold opacity-70 mt-0.5 block"><?php echo $pm['pecas_faltam']; ?> peça<?php echo $pm['pecas_faltam'] > 1 ? 's' : ''; ?> a produzir</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="bg-slate-900 rounded-[3rem] p-10 text-white shadow-2xl relative overflow-hidden group">
                        <i data-lucide="coins" class="absolute -right-10 -bottom-10 w-72 h-72 opacity-5 group-hover:rotate-12 transition-transform duration-1000"></i>
                        <h2 class="text-indigo-400 font-black uppercase tracking-[0.2em] text-xs mb-4">Consolidado Geral</h2>
                        <div class="text-5xl md:text-6xl font-black tracking-tighter mb-10"><?php echo formatReal($statsRemessas['valor_total']); ?></div>
                        
                        <div class="grid grid-cols-2 gap-8 border-t border-white/10 pt-10">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 bg-emerald-500/20 text-emerald-400 rounded-2xl flex items-center justify-center"><i data-lucide="wallet" class="w-6 h-6"></i></div>
                                <div>
                                    <span class="text-[10px] font-black text-white/40 uppercase block mb-1">Já Recebido</span>
                                    <div class="text-2xl font-black text-emerald-400"><?php echo formatReal($statsRemessas['valor_recebido']); ?></div>
                                </div>
                            </div>
                            <div class="flex items-center gap-4 border-l border-white/10 pl-8 text-right justify-end">
                                <div>
                                    <span class="text-[10px] font-black text-white/40 uppercase block mb-1 text-right">A Receber</span>
                                    <div class="text-2xl font-black text-rose-400"><?php echo formatReal($statsRemessas['valor_pendente']); ?></div>
                                </div>
                                <div class="w-12 h-12 bg-rose-500/20 text-rose-400 rounded-2xl flex items-center justify-center"><i data-lucide="hand-coins" class="w-6 h-6"></i></div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-[2.5rem] p-8 border-2 border-slate-100">
                        <h2 class="font-serif text-2xl font-black text-slate-800 mb-8 flex items-center gap-3">
                            <i data-lucide="history" class="w-6 h-6 text-indigo-600"></i> Histórico Anual
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <?php foreach ($statsPorAno as $ano => $s): 
                                $aPend = max(0, $s['total'] - $s['recebido']);
                                $pPer = $s['total'] > 0 ? round(($s['recebido'] / $s['total']) * 100) : 0;
                                $pecasFaltamAno = max(0, ($s['pecas_total'] ?? 0) - ($s['pecas_entregues'] ?? 0));
                            ?>
                                <div class="bg-slate-50/50 rounded-3xl p-6 border border-slate-100 hover:border-indigo-200 transition-all group">
                                    <div class="flex justify-between items-center mb-4">
                                        <span class="text-2xl font-black text-slate-700"><?php echo $ano; ?></span>
                                        <span class="text-base font-black text-slate-900"><?php echo formatReal($s['total']); ?></span>
                                    </div>
                                    <div class="w-full bg-slate-200 h-3 rounded-full overflow-hidden mb-4">
                                        <div class="h-full bg-emerald-500 rounded-full transition-all duration-1000" style="width: <?php echo $pPer; ?>%"></div>
                                    </div>
                                    <div class="flex justify-between text-[11px] font-black uppercase tracking-tight mb-3">
                                        <div class="flex items-center gap-1.5 text-emerald-600">
                                            <div class="w-2 h-2 rounded-full bg-emerald-500"></div> PAGO: <?php echo formatReal($s['recebido']); ?>
                                        </div>
                                        <div class="flex items-center gap-1.5 text-rose-500">
                                            FALTA: <?php echo formatReal($aPend); ?> <div class="w-2 h-2 rounded-full bg-rose-500"></div>
                                        </div>
                                    </div>
                                    <?php if (($s['pecas_total'] ?? 0) > 0): ?>
                                    <div class="flex items-center justify-between bg-violet-50 rounded-2xl px-4 py-2.5 border border-violet-100">
                                        <div class="flex items-center gap-2 text-violet-600">
                                            <i data-lucide="scissors" class="w-3.5 h-3.5"></i>
                                            <span class="text-[10px] font-black uppercase">Produção</span>
                                        </div>
                                        <div class="text-right">
                                            <?php if ($pecasFaltamAno > 0): ?>
                                                <span class="text-[11px] font-black text-violet-700"><?php echo $pecasFaltamAno; ?> peça<?php echo $pecasFaltamAno > 1 ? 's' : ''; ?> a produzir</span>
                                            <?php else: ?>
                                                <span class="text-[11px] font-black text-emerald-600">Tudo entregue ✓</span>
                                            <?php endif; ?>
                                            <span class="text-[9px] font-bold text-slate-400 block"><?php echo $s['pecas_entregues'] ?? 0; ?>/<?php echo $s['pecas_total'] ?? 0; ?> peças</span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Coluna Lateral (Registros Recentes) -->
                <div class="bg-white rounded-[2.5rem] p-8 border-2 border-slate-100 shadow-sm sticky top-32">
                    <div class="flex justify-between items-center mb-8">
                        <h2 class="font-serif text-xl font-black text-slate-800">Recentes</h2>
                        <span class="text-[10px] font-black bg-indigo-50 text-indigo-600 px-3 py-1 rounded-full uppercase"><?php echo count($remessas); ?> Lotes</span>
                    </div>
                    <div class="space-y-6">
                        <?php foreach (array_slice($remessas, 0, 8) as $r): 
                            $v=intval($r['quantidade']??$r['qtd']??0)*floatval($r['preco_unitario']??$r['precoU']??0); 
                            $qT=intval($r['quantidade']??$r['qtd']??0); $qE=intval($r['qtd_entregue']??$r['entregue']??0);
                        ?>
                            <div class="flex items-center justify-between group">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 bg-slate-50 text-slate-400 rounded-xl flex items-center justify-center group-hover:bg-indigo-600 group-hover:text-white transition-all"><i data-lucide="package" class="w-5 h-5"></i></div>
                                    <div>
                                        <div class="text-sm font-black text-slate-800 capitalize group-hover:text-indigo-600 transition-colors leading-tight"><?php echo htmlspecialchars($r['peca_servico']??'Lote'); ?></div>
                                        <div class="text-[10px] font-bold text-slate-400 uppercase mt-0.5"><?php echo formatDate($r['data_cadastro']??null); ?> • <?php echo $qE; ?>/<?php echo $qT; ?> uni</div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-black text-slate-900"><?php echo formatReal($v); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button onclick="location.href='?tab=remessas&mes=geral'" class="w-full mt-8 py-4 bg-slate-50 text-slate-500 text-xs font-black uppercase rounded-2xl hover:bg-slate-100 transition-all">Ver Histórico Completo</button>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Navegação Flutuante Mobile -->
    <nav class="glass md:hidden fixed bottom-6 left-6 right-6 h-20 rounded-[2rem] border border-slate-200 shadow-2xl flex items-center justify-around px-4 z-50">
        <a href="?tab=remessas" class="flex flex-col items-center gap-1 relative <?php echo $activeTab==='remessas'?'tab-active':'text-slate-400'; ?>">
            <i data-lucide="package" class="w-6 h-6"></i>
            <span class="text-[10px] font-black uppercase tracking-tighter">Lotes</span>
        </a>
        <a href="?tab=dashboard" class="flex flex-col items-center gap-1 relative <?php echo $activeTab==='dashboard'?'tab-active':'text-slate-400'; ?>">
            <i data-lucide="layout-grid" class="w-6 h-6"></i>
            <span class="text-[10px] font-black uppercase tracking-tighter">Dashboard</span>
        </a>
        <a href="atelie_sob_medida.php" class="flex flex-col items-center gap-1 relative text-purple-600">
            <i data-lucide="ruler" class="w-6 h-6"></i>
            <span class="text-[10px] font-black uppercase tracking-tighter">Ateliê</span>
        </a>
    </nav>

    <!-- Modais (Mesma lógica estilizada) -->
    <!-- [MODAIS SIMILAR AO ANTERIOR COM ESTILIZAÇÃO MELHORADA] -->
    <div id="modalAddRemessa" class="fixed inset-0 z-[60] overflow-y-auto hidden">
        <div class="flex items-end sm:items-center justify-center min-h-screen">
            <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="toggleModal('modalAddRemessa')"></div>
            <div class="bg-white rounded-t-[3rem] sm:rounded-[3rem] shadow-2xl z-10 w-full max-w-lg p-10 animate-in slide-in-from-bottom-full duration-300">
                <div class="flex justify-between items-center mb-8">
                    <h3 class="font-serif text-2xl font-black text-slate-800">Novo Registro</h3>
                    <button onclick="toggleModal('modalAddRemessa')" class="w-10 h-10 rounded-full bg-slate-50 flex items-center justify-center text-slate-400 hover:text-rose-500 transition-colors"><i data-lucide="x" class="w-5 h-5"></i></button>
                </div>
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="add_remessa">
                    <div class="space-y-2">
                        <label class="text-[11px] font-black uppercase text-slate-400 ml-2">Nome da Peça ou Serviço</label>
                        <input type="text" name="peca_servico" required placeholder="Ex: Vestido Longo Seda" class="w-full bg-slate-50 border-2 border-slate-50 rounded-3xl p-5 font-bold focus:border-indigo-500 focus:bg-white outline-none transition-all">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[11px] font-black uppercase text-slate-400 ml-2">Preço Unitário</label>
                            <input type="number" step="0.01" name="preco_unitario" required placeholder="0,00" class="w-full bg-slate-50 border-2 border-slate-50 rounded-3xl p-5 font-bold focus:border-indigo-500 focus:bg-white outline-none transition-all">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[11px] font-black uppercase text-slate-400 ml-2">Quantidade</label>
                            <input type="number" name="quantidade" required placeholder="0" class="w-full bg-slate-50 border-2 border-slate-50 rounded-3xl p-5 font-bold focus:border-indigo-500 focus:bg-white outline-none transition-all">
                        </div>
                    </div>
                    <div class="space-y-4">
                        <label class="text-[11px] font-black uppercase text-slate-400 text-center block">Selecione o Tamanho</label>
                        <div class="grid grid-cols-4 gap-2">
                            <?php foreach (['PP','P','M','G','GG','XG','G1','-'] as $t): ?>
                                <label class="py-3 border-2 border-slate-50 rounded-2xl cursor-pointer text-center font-black text-xs transition-all has-[:checked]:bg-indigo-600 has-[:checked]:border-indigo-600 has-[:checked]:text-white hover:border-indigo-100">
                                    <input type="radio" name="tamanho" value="<?php echo $t; ?>" <?php echo $t==='M'?'checked':''; ?> class="hidden"> <?php echo $t; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-slate-900 text-white font-black py-5 rounded-[2rem] shadow-2xl shadow-indigo-100 hover:bg-indigo-600 transition-all mt-4 active:scale-95">Salvar Lote</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Entrega Otimizado -->
    <div id="modalUpdateQtd" class="fixed inset-0 z-[60] overflow-y-auto hidden">
        <div class="flex items-end sm:items-center justify-center min-h-screen">
            <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="toggleModal('modalUpdateQtd')"></div>
            <div class="bg-white rounded-t-[3rem] sm:rounded-[3rem] shadow-2xl z-10 w-full max-w-md p-10 animate-in slide-in-from-bottom-full duration-300">
                <h3 class="text-2xl font-black text-slate-800 mb-8">Registrar Entrega</h3>
                <form method="POST" class="space-y-8">
                    <input type="hidden" name="action" value="update_entrega"><input type="hidden" name="id" id="updateQtdId"><input type="hidden" name="collection" id="updateQtdCollection"><input type="hidden" name="qtd_atual" id="updateQtdAtualHidden"><input type="hidden" name="valor_recebido_atual" id="updateValorRecebidoAtualHidden">
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-slate-50 p-6 rounded-[2rem] text-center border-2 border-slate-100">
                            <span class="text-[10px] font-black text-slate-400 uppercase block mb-1">JÁ ENTREGUE</span>
                            <span id="updateQtdAtualLabel" class="text-3xl font-black text-slate-800">0</span>
                        </div>
                        <div class="bg-indigo-50 p-6 rounded-[2rem] text-center border-2 border-indigo-100">
                            <span class="text-[10px] font-black text-indigo-400 uppercase block mb-1">FALTAM AGORA</span>
                            <span id="updateQtdFaltaLabel" class="text-3xl font-black text-indigo-600">0</span>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <label class="text-[11px] font-black uppercase text-slate-400 ml-2">Quantas peças prontas agora?</label>
                        <div class="flex gap-2">
                            <input type="number" name="qtd_adicionar" id="updateQtdAdicionar" value="1" class="flex-grow bg-slate-50 border-2 border-slate-50 rounded-[1.5rem] p-5 font-black text-2xl outline-none focus:border-indigo-500 focus:bg-white transition-all text-center">
                            <button type="button" onclick="setQtdRestante()" class="bg-slate-900 text-white px-6 rounded-[1.5rem] font-black text-xs hover:bg-indigo-600 transition-all">TUDO</button>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <label class="text-[11px] font-black uppercase text-slate-400 ml-2">Recebeu valor deste lote? (R$)</label>
                        <input type="number" step="0.01" name="valor_recebido_agora" id="updateValorRecebidoAgora" placeholder="0,00" class="w-full bg-slate-50 border-2 border-slate-50 rounded-[1.5rem] p-5 font-black text-2xl outline-none focus:border-emerald-500 focus:bg-white transition-all text-center">
                        <p class="text-[10px] font-bold text-center text-slate-400 uppercase">Recebido: <span id="updateValorRecebidoLabel" class="text-emerald-600">R$ 0,00</span> • Falta: <span id="updateValorPendenteLabel" class="text-rose-500">R$ 0,00</span></p>
                    </div>

                    <button type="submit" class="w-full bg-slate-900 text-white font-black py-6 rounded-[2rem] shadow-2xl shadow-indigo-100 hover:bg-indigo-600 transition-all active:scale-95">Confirmar Registro</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Pagamento Otimizado -->
    <div id="modalUpdatePagamento" class="fixed inset-0 z-[60] overflow-y-auto hidden">
        <div class="flex items-end sm:items-center justify-center min-h-screen">
            <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="toggleModal('modalUpdatePagamento')"></div>
            <div class="bg-white rounded-t-[3rem] sm:rounded-[3rem] shadow-2xl z-10 w-full max-w-sm p-10 animate-in slide-in-from-bottom-full duration-300">
                <h3 class="text-2xl font-black text-slate-800 mb-8 flex items-center gap-3">
                    <i data-lucide="banknote" class="w-8 h-8 text-emerald-500"></i> Registrar Recebimento
                </h3>
                <form method="POST" class="space-y-8">
                    <input type="hidden" name="action" value="update_pagamento"><input type="hidden" name="id" id="updatePagId"><input type="hidden" name="collection" id="updatePagCollection"><input type="hidden" name="valor_recebido_atual" id="updatePagAtualHidden">
                    
                    <div class="space-y-3">
                        <label class="text-[11px] font-black uppercase text-slate-400 ml-2">Qual valor foi pago agora?</label>
                        <input type="number" step="0.01" name="valor_recebido_agora" required placeholder="0,00" class="w-full bg-slate-50 border-2 border-slate-50 rounded-[1.5rem] p-6 font-black text-3xl outline-none focus:border-emerald-500 focus:bg-white transition-all text-center">
                    </div>
                    
                    <div class="bg-emerald-50 p-6 rounded-[2rem] text-center border-2 border-emerald-100">
                        <span class="text-[10px] font-black text-emerald-400 uppercase block mb-1">Total Já Recebido Anteriormente</span>
                        <span id="updatePagLabel" class="text-2xl font-black text-emerald-800">R$ 0,00</span>
                    </div>

                    <button type="submit" class="w-full bg-emerald-600 text-white font-black py-6 rounded-[2.5rem] shadow-2xl shadow-emerald-100 hover:bg-emerald-700 transition-all active:scale-95 uppercase tracking-widest">Salvar Pagamento</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Calendário Otimizado -->
    <div id="modalCalendar" class="fixed inset-0 z-[60] overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm" onclick="toggleModal('modalCalendar')"></div>
            <div class="glass border border-white/40 rounded-[3rem] shadow-2xl z-10 w-full max-w-sm overflow-hidden animate-in zoom-in-95 duration-200">
                <div class="bg-slate-900 text-white p-8 flex justify-between items-center">
                    <div>
                        <span class="text-[10px] font-black uppercase text-white/40 block mb-1">Filtrar por Ano</span>
                        <span id="calendarYearLabel" class="text-4xl font-serif font-black"><?php echo $filtroAno; ?></span>
                    </div>
                    <div class="flex flex-col gap-2">
                        <button onclick="adjustFilterYear(-1)" class="w-12 h-10 rounded-xl bg-white/10 flex items-center justify-center hover:bg-white/20 transition-all"><i data-lucide="chevron-up" class="w-6 h-6"></i></button>
                        <button onclick="adjustFilterYear(1)" class="w-12 h-10 rounded-xl bg-white/10 flex items-center justify-center hover:bg-white/20 transition-all"><i data-lucide="chevron-down" class="w-6 h-6"></i></button>
                    </div>
                </div>
                <div class="p-8">
                    <div class="grid grid-cols-3 gap-2 mb-8">
                        <?php $ms=['01'=>'Jan','02'=>'Fev','03'=>'Mar','04'=>'Abr','05'=>'Mai','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Set','10'=>'Out','11'=>'Nov','12'=>'Dez']; 
                        foreach ($ms as $n=>$s): ?>
                            <button onclick="setFilterPeriod('<?php echo $n; ?>')" class="py-5 rounded-[1.5rem] text-xs font-black transition-all <?php echo $filtroMes===$n?'bg-indigo-600 text-white shadow-xl shadow-indigo-100':'bg-white text-slate-400 border border-slate-100 hover:border-indigo-100 hover:text-indigo-400'; ?>">
                                <?php echo $s; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <button onclick="setFilterPeriod('geral')" class="w-full py-6 rounded-[1.5rem] text-xs font-black transition-all <?php echo $filtroGeral?'bg-slate-900 text-white shadow-2xl':'bg-slate-100 text-slate-500 hover:bg-slate-200'; ?> uppercase tracking-widest">VISÃO HISTÓRICA COMPLETA</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleModal(id) { document.getElementById(id).classList.toggle('hidden'); }
        let maxLoteAtual=0, entAtu=0, valRecAtu=0;
        function openUpdateQtd(id, ent, max, col, rec, pr) {
            maxLoteAtual=max; entAtu=ent; valRecAtu=parseFloat(rec||0);
            document.getElementById('updateQtdId').value=id; document.getElementById('updateQtdCollection').value=col;
            document.getElementById('updateQtdAtualHidden').value=ent; document.getElementById('updateValorRecebidoAtualHidden').value=valRecAtu;
            document.getElementById('updateQtdAtualLabel').innerText=ent;
            document.getElementById('updateQtdFaltaLabel').innerText=max-ent;
            document.getElementById('updateValorRecebidoLabel').innerText=formatRealJS(valRecAtu);
            document.getElementById('updateValorPendenteLabel').innerText=formatRealJS(Math.max(0, (max*parseFloat(pr||0))-valRecAtu));
            toggleModal('modalUpdateQtd');
        }
        function openUpdatePagamento(id, rec, col, max, pr) {
            const val=parseFloat(rec||0);
            document.getElementById('updatePagId').value=id; document.getElementById('updatePagCollection').value=col;
            document.getElementById('updatePagAtualHidden').value=val;
            document.getElementById('updatePagLabel').innerText=formatRealJS(val);
            toggleModal('modalUpdatePagamento');
        }
        function setQtdRestante() { document.getElementById('updateQtdAdicionar').value = maxLoteAtual - entAtu; }
        function openEditRemessa(r) {
            document.getElementById('editRemessaId').value=r.id; 
            document.getElementById('editRemessaCollection').value=r.__collection;
            toggleModal('modalAddRemessa'); 
        }
        function adjustFilterYear(d) { activeFilterYear+=d; document.getElementById('calendarYearLabel').innerText=activeFilterYear; }
        function setFilterPeriod(m) { window.location.href=`?tab=<?php echo $activeTab; ?>&mes=${m}&ano=${activeFilterYear}`; }
        function formatRealJS(v) { return 'R$ ' + v.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}); }
        let activeFilterYear = <?php echo $filtroAno; ?>; lucide.createIcons();
    </script>
</body>
</html>
