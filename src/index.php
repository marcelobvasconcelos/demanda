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
            if (!isset($statsPorAno[$anoItem])) $statsPorAno[$anoItem] = ['total' => 0, 'recebido' => 0];
            $statsPorAno[$anoItem]['total'] += $val;
            $statsPorAno[$anoItem]['recebido'] += $rec;
        }
        $statsRemessas['valor_pendente'] = max(0, $statsRemessas['valor_total'] - $statsRemessas['valor_recebido']);
        krsort($statsPorAno);
    } catch (Throwable $e) { $msgError = 'Erro: ' . $e->getMessage(); }
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
    <title>Demanda App</title>
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
        .glass { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
        .tab-active { color: #6366f1; }
        .tab-active::after { content: ''; position: absolute; bottom: -2px; left: 0; right: 0; height: 3px; background: #6366f1; border-radius: 100px; }
        .card-shadow { box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05); }
        body { background: #f8fafc; overflow-x: hidden; }
        .safe-pb { padding-bottom: calc(env(safe-area-inset-bottom) + 80px); }
    </style>
</head>
<body class="font-sans text-slate-900 min-h-full">

    <!-- Header Mobile-First -->
    <header class="glass sticky top-0 z-40 border-b border-slate-200/50 px-4 py-4">
        <div class="max-w-xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-10 h-10 bg-indigo-600 rounded-2xl flex items-center justify-center text-white shadow-lg shadow-indigo-200">
                    <i data-lucide="scissors" class="w-5 h-5"></i>
                </div>
                <h1 class="font-serif text-2xl font-bold tracking-tight text-slate-800">Demanda</h1>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-1 bg-emerald-100 text-emerald-700 px-3 py-1 rounded-full text-[10px] font-extrabold uppercase">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Online
                </div>
                <form method="POST" onsubmit="return confirm('Sair?')">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="w-10 h-10 flex items-center justify-center text-slate-400 hover:text-rose-500 transition-colors">
                        <i data-lucide="log-out" class="w-5 h-5"></i>
                    </button>
                </form>
            </div>
        </div>
    </header>

    <main class="max-w-xl mx-auto px-4 pt-6 safe-pb">
        
        <?php if ($msgSuccess): ?>
            <div class="mb-6 p-4 bg-emerald-500 text-white rounded-2xl shadow-lg shadow-emerald-100 flex items-center gap-3 animate-in fade-in slide-in-from-top-4">
                <i data-lucide="check-circle" class="w-5 h-5"></i>
                <span class="text-sm font-bold"><?php echo $msgSuccess; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($activeTab === 'remessas'): ?>
            <!-- Mini Dashboard do Mês -->
            <div class="grid grid-cols-2 gap-3 mb-8">
                <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 p-4 rounded-3xl text-white shadow-xl shadow-indigo-100">
                    <span class="text-[10px] font-extrabold uppercase opacity-80">Produzido</span>
                    <div class="text-xl font-bold mt-1"><?php echo formatReal($statsRemessas['valor_total']); ?></div>
                </div>
                <div class="bg-white p-4 rounded-3xl border border-slate-200 shadow-sm">
                    <span class="text-[10px] font-extrabold uppercase text-emerald-600">Recebido</span>
                    <div class="text-xl font-bold mt-1 text-slate-800"><?php echo formatReal($statsRemessas['valor_recebido']); ?></div>
                </div>
            </div>

            <!-- Filtro & Botão Add -->
            <div class="flex items-center gap-2 mb-6">
                <button onclick="toggleModal('modalCalendar')" class="flex-grow glass border border-slate-200 p-4 rounded-2xl flex items-center justify-between active:scale-[0.98] transition-all">
                    <div class="flex items-center gap-3">
                        <i data-lucide="calendar" class="w-5 h-5 text-indigo-500"></i>
                        <span class="text-sm font-extrabold capitalize"><?php echo getMesNome($filtroMes); ?> <?php echo !$filtroGeral ? 'de '.$filtroAno : ''; ?></span>
                    </div>
                    <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400"></i>
                </button>
                <button onclick="toggleModal('modalAddRemessa')" class="w-14 h-14 bg-slate-900 text-white rounded-2xl flex items-center justify-center shadow-lg shadow-slate-200 active:scale-90 transition-all">
                    <i data-lucide="plus" class="w-6 h-6"></i>
                </button>
            </div>

            <!-- Lista de Remessas -->
            <div class="space-y-4">
                <?php if (empty($remessas)): ?>
                    <div class="bg-white border-2 border-dashed border-slate-200 rounded-3xl p-12 text-center text-slate-400">
                        <i data-lucide="inbox" class="w-10 h-10 mx-auto mb-3 opacity-20"></i>
                        <p class="font-bold">Nenhum lote este mês</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($remessas as $r): 
                    $qT=intval($r['quantidade']??$r['qtd']??0); $qE=intval($r['qtd_entregue']??$r['entregue']??0); 
                    $prU=floatval($r['preco_unitario']??$r['precoU']??0); $rec=floatval($r['valor_recebido']??0); 
                    $tot=$qT*$prU; $pend=max(0,$tot-$rec); $perc=$qT>0?round(($qE/$qT)*100):0;
                    $isPaid = ($pend <= 0.01);
                ?>
                <div class="bg-white rounded-[2rem] p-5 card-shadow border border-slate-100 transition-all">
                    <div class="flex justify-between items-start mb-4">
                        <div class="flex-grow">
                            <h3 class="text-lg font-extrabold text-slate-800 capitalize leading-tight"><?php echo htmlspecialchars($r['peca_servico']??'Lote'); ?></h3>
                            <div class="flex items-center gap-3 mt-1">
                                <span class="text-[10px] font-extrabold px-2 py-0.5 bg-slate-100 text-slate-500 rounded-md uppercase">TAM <?php echo htmlspecialchars($r['tamanho']??$r['size']??'-'); ?></span>
                                <span class="text-[10px] font-bold text-slate-400"><?php echo formatDate($r['data_cadastro']??$r['data']??null); ?></span>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button onclick='openEditRemessa(<?php echo json_encode($r); ?>)' class="w-10 h-10 rounded-xl bg-slate-50 text-slate-400 flex items-center justify-center active:bg-slate-100">
                                <i data-lucide="edit-3" class="w-4.5 h-4.5"></i>
                            </button>
                            <form method="POST" onsubmit="return confirm('Apagar lote?')">
                                <input type="hidden" name="action" value="delete_remessa">
                                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                <input type="hidden" name="collection" value="<?php echo $r['__collection']; ?>">
                                <button type="submit" class="w-10 h-10 rounded-xl bg-rose-50 text-rose-400 flex items-center justify-center active:bg-rose-100">
                                    <i data-lucide="trash-2" class="w-4.5 h-4.5"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="bg-slate-50 rounded-2xl p-4 mb-4 grid grid-cols-2 gap-4">
                        <div>
                            <span class="text-[9px] font-extrabold uppercase text-slate-400 block mb-1">Status Entrega</span>
                            <div class="flex items-end gap-1">
                                <span class="text-xl font-black text-slate-700"><?php echo $qE; ?></span>
                                <span class="text-sm font-bold text-slate-400 mb-0.5">/ <?php echo $qT; ?></span>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="text-[9px] font-extrabold uppercase text-slate-400 block mb-1">Financeiro</span>
                            <?php if ($isPaid): ?>
                                <span class="text-emerald-500 font-extrabold text-sm uppercase flex items-center justify-end gap-1"><i data-lucide="check-circle" class="w-3 h-3"></i> Pago</span>
                            <?php else: ?>
                                <span class="text-rose-500 font-extrabold text-sm uppercase">- <?php echo formatReal($pend); ?></span>
                            <?php endif; ?>
                            <div class="text-[10px] font-bold text-slate-500 mt-0.5"><?php echo formatReal($rec); ?> recebido</div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="flex-grow flex gap-2">
                            <button onclick="openUpdateQtd('<?php echo $r['id']; ?>','<?php echo $qE; ?>','<?php echo $qT; ?>','<?php echo $r['__collection']; ?>','<?php echo $rec; ?>','<?php echo $prU; ?>')" class="flex-grow py-3 rounded-xl bg-indigo-600 text-white font-extrabold text-xs shadow-lg shadow-indigo-100 active:scale-95 transition-all">
                                + Entrega
                            </button>
                            <button onclick="openUpdatePagamento('<?php echo $r['id']; ?>','<?php echo $rec; ?>','<?php echo $r['__collection']; ?>','<?php echo $qT; ?>','<?php echo $prU; ?>')" class="w-12 h-12 rounded-xl border-2 border-emerald-500 text-emerald-600 flex items-center justify-center font-bold active:bg-emerald-50 transition-all">
                                $
                            </button>
                            <?php if(!$isPaid): ?>
                                <form method="POST" class="contents">
                                    <input type="hidden" name="action" value="mark_paid_full">
                                    <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                    <input type="hidden" name="collection" value="<?php echo $r['__collection']; ?>">
                                    <input type="hidden" name="total_lote" value="<?php echo $tot; ?>">
                                    <button type="submit" class="w-12 h-12 rounded-xl bg-emerald-500 text-white flex items-center justify-center shadow-lg shadow-emerald-100 active:scale-95 transition-all">
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
            <!-- Estilo Dashboard -->
            <div class="space-y-6">
                <div class="bg-slate-900 rounded-[2.5rem] p-8 text-white shadow-2xl relative overflow-hidden">
                    <div class="absolute -right-10 -bottom-10 opacity-10">
                        <i data-lucide="trending-up" class="w-64 h-64"></i>
                    </div>
                    <span class="text-xs font-extrabold uppercase tracking-widest text-indigo-300">Resumo Geral</span>
                    <div class="text-4xl font-black mt-2 mb-6"><?php echo formatReal($statsRemessas['valor_total']); ?></div>
                    
                    <div class="grid grid-cols-2 gap-4 border-t border-white/10 pt-6">
                        <div>
                            <span class="text-[10px] font-bold text-white/50 uppercase block mb-1">Total Recebido</span>
                            <div class="text-lg font-bold text-emerald-400"><?php echo formatReal($statsRemessas['valor_recebido']); ?></div>
                        </div>
                        <div class="text-right">
                            <span class="text-[10px] font-bold text-white/50 uppercase block mb-1">A Receber</span>
                            <div class="text-lg font-bold text-rose-400"><?php echo formatReal($statsRemessas['valor_pendente']); ?></div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-[2rem] p-6 border shadow-sm">
                    <h2 class="font-serif text-xl font-bold mb-6 flex items-center gap-2">
                        <i data-lucide="bar-chart-3" class="w-5 h-5 text-indigo-500"></i> Faturamento Anual
                    </h2>
                    <div class="space-y-3">
                        <?php foreach ($statsPorAno as $ano => $s): 
                            $aPend = $s['total'] - $s['recebido'];
                            $pPer = $s['total'] > 0 ? round(($s['recebido'] / $s['total']) * 100) : 0;
                        ?>
                            <div class="bg-slate-50 rounded-2xl p-4 border border-slate-100">
                                <div class="flex justify-between items-center mb-3">
                                    <span class="text-lg font-black text-slate-700"><?php echo $ano; ?></span>
                                    <span class="text-sm font-bold text-slate-800"><?php echo formatReal($s['total']); ?></span>
                                </div>
                                <div class="w-full bg-slate-200 h-2 rounded-full overflow-hidden mb-3">
                                    <div class="h-full bg-emerald-500 rounded-full" style="width: <?php echo $pPer; ?>%"></div>
                                </div>
                                <div class="flex justify-between text-[10px] font-extrabold uppercase">
                                    <span class="text-emerald-600">Pago: <?php echo formatReal($s['recebido']); ?></span>
                                    <span class="text-rose-500">Falta: <?php echo formatReal($aPend); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Navegação Flutuante -->
    <nav class="glass fixed bottom-6 left-6 right-6 h-20 rounded-[2rem] border border-slate-200/50 shadow-2xl flex items-center justify-around px-4 z-50">
        <a href="?tab=remessas" class="flex flex-col items-center gap-1 relative <?php echo $activeTab==='remessas'?'tab-active':'text-slate-400'; ?>">
            <i data-lucide="package" class="w-6 h-6"></i>
            <span class="text-[10px] font-extrabold uppercase tracking-tighter">Lotes</span>
        </a>
        <a href="?tab=dashboard" class="flex flex-col items-center gap-1 relative <?php echo $activeTab==='dashboard'?'tab-active':'text-slate-400'; ?>">
            <i data-lucide="pie-chart" class="w-6 h-6"></i>
            <span class="text-[10px] font-extrabold uppercase tracking-tighter">Dashboard</span>
        </a>
    </nav>

    <!-- Modais Estilizados -->
    <div id="modalAddRemessa" class="fixed inset-0 z-[60] overflow-y-auto hidden">
        <div class="flex items-end sm:items-center justify-center min-h-screen">
            <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" onclick="toggleModal('modalAddRemessa')"></div>
            <div class="bg-white rounded-t-[2.5rem] sm:rounded-[2.5rem] overflow-hidden shadow-2xl z-10 w-full max-w-lg p-8 animate-in slide-in-from-bottom-full duration-300">
                <div class="flex justify-between items-center mb-8">
                    <h3 class="font-serif text-2xl font-bold text-slate-800">Novo Lote</h3>
                    <button onclick="toggleModal('modalAddRemessa')" class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-400"><i data-lucide="x" class="w-5 h-5"></i></button>
                </div>
                <form method="POST" class="space-y-5">
                    <input type="hidden" name="action" value="add_remessa">
                    <div class="space-y-2">
                        <label class="text-[10px] font-extrabold uppercase text-slate-400 ml-1">O que foi produzido?</label>
                        <input type="text" name="peca_servico" required placeholder="Ex: Vestido Festa" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-bold focus:border-indigo-500 outline-none transition-all">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[10px] font-extrabold uppercase text-slate-400 ml-1">Preço Peça</label>
                            <input type="number" step="0.01" name="preco_unitario" required placeholder="0,00" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-bold focus:border-indigo-500 outline-none transition-all">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-extrabold uppercase text-slate-400 ml-1">Quantidade</label>
                            <input type="number" name="quantidade" required placeholder="0" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-bold focus:border-indigo-500 outline-none transition-all">
                        </div>
                    </div>
                    <div class="space-y-3">
                        <label class="text-[10px] font-extrabold uppercase text-slate-400 ml-1 block text-center">Tamanho</label>
                        <div class="flex flex-wrap gap-2 justify-center">
                            <?php foreach (['PP','P','M','G','GG','XG','-'] as $t): ?>
                                <label class="flex-1 min-w-[50px] py-3 border-2 border-slate-100 rounded-xl cursor-pointer text-center transition-all has-[:checked]:bg-indigo-600 has-[:checked]:border-indigo-600 has-[:checked]:text-white">
                                    <input type="radio" name="tamanho" value="<?php echo $t; ?>" <?php echo $t==='M'?'checked':''; ?> class="hidden">
                                    <span class="text-xs font-bold"><?php echo $t; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-indigo-600 text-white font-extrabold py-5 rounded-[1.5rem] shadow-xl shadow-indigo-100 active:scale-95 transition-all mt-4">Criar Lote</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modais de Ação Rápida -->
    <div id="modalUpdateQtd" class="fixed inset-0 z-[60] overflow-y-auto hidden">
        <div class="flex items-end sm:items-center justify-center min-h-screen">
            <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="toggleModal('modalUpdateQtd')"></div>
            <div class="bg-white rounded-t-[2.5rem] sm:rounded-[2.5rem] shadow-2xl z-10 w-full max-w-sm p-8 animate-in slide-in-from-bottom-full duration-300">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-extrabold text-slate-800">Registrar Entrega</h3>
                    <button onclick="toggleModal('modalUpdateQtd')" class="text-slate-300"><i data-lucide="x" class="w-6 h-6"></i></button>
                </div>
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="update_entrega">
                    <input type="hidden" name="id" id="updateQtdId">
                    <input type="hidden" name="collection" id="updateQtdCollection">
                    <input type="hidden" name="qtd_atual" id="updateQtdAtualHidden">
                    <input type="hidden" name="valor_recebido_atual" id="updateValorRecebidoAtualHidden">
                    
                    <div class="bg-slate-50 p-4 rounded-2xl flex justify-between items-center">
                        <div>
                            <span class="text-[10px] font-extrabold text-slate-400 uppercase block">Já Entregue</span>
                            <span id="updateQtdAtualLabel" class="text-lg font-black">0</span>
                        </div>
                        <div class="text-right">
                            <span class="text-[10px] font-extrabold text-indigo-500 uppercase block">Faltam</span>
                            <span id="updateQtdFaltaLabel" class="text-lg font-black text-indigo-600">0</span>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="text-[10px] font-extrabold uppercase text-slate-400">Quanto entregar agora?</label>
                        <div class="flex gap-2">
                            <input type="number" name="qtd_adicionar" id="updateQtdAdicionar" value="1" class="flex-grow bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-black outline-none focus:border-indigo-500">
                            <button type="button" onclick="setQtdRestante()" class="bg-indigo-50 text-indigo-600 px-4 rounded-2xl font-black text-xs">TUDO</button>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="text-[10px] font-extrabold uppercase text-slate-400">Recebeu valor? (Opcional)</label>
                        <input type="number" step="0.01" name="valor_recebido_agora" id="updateValorRecebidoAgora" placeholder="R$ 0,00" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-black outline-none focus:border-emerald-500">
                    </div>

                    <button type="submit" class="w-full bg-indigo-600 text-white font-extrabold py-5 rounded-[1.5rem] shadow-xl shadow-indigo-100">Confirmar Entrega</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Pagamento -->
    <div id="modalUpdatePagamento" class="fixed inset-0 z-[60] overflow-y-auto hidden">
        <div class="flex items-end sm:items-center justify-center min-h-screen">
            <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="toggleModal('modalUpdatePagamento')"></div>
            <div class="bg-white rounded-t-[2.5rem] sm:rounded-[2.5rem] shadow-2xl z-10 w-full max-w-sm p-8">
                <h3 class="text-xl font-extrabold text-slate-800 mb-6 flex items-center gap-2">
                    <i data-lucide="banknote" class="w-6 h-6 text-emerald-500"></i> Registrar Valor
                </h3>
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="update_pagamento">
                    <input type="hidden" name="id" id="updatePagId">
                    <input type="hidden" name="collection" id="updatePagCollection">
                    <input type="hidden" name="valor_recebido_atual" id="updatePagAtualHidden">
                    
                    <div class="space-y-2">
                        <label class="text-[10px] font-extrabold uppercase text-slate-400">Quanto recebeu agora?</label>
                        <input type="number" step="0.01" name="valor_recebido_agora" required placeholder="0,00" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-black outline-none focus:border-emerald-500 text-xl">
                    </div>
                    
                    <div class="bg-emerald-50 p-4 rounded-2xl flex justify-between text-xs">
                        <span class="font-bold text-emerald-700">Já recebido:</span>
                        <span id="updatePagLabel" class="font-black text-emerald-800">R$ 0,00</span>
                    </div>

                    <button type="submit" class="w-full bg-emerald-600 text-white font-extrabold py-5 rounded-[1.5rem] shadow-xl shadow-emerald-100">Salvar Pagamento</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Calendário -->
    <div id="modalCalendar" class="fixed inset-0 z-[60] overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="toggleModal('modalCalendar')"></div>
            <div class="glass border border-white/20 rounded-[2.5rem] shadow-2xl z-10 w-full max-w-sm overflow-hidden">
                <div class="bg-slate-900 text-white p-6 flex justify-between items-center">
                    <div>
                        <span class="text-[10px] font-black uppercase text-white/40 block mb-1">Selecionar Ano</span>
                        <span id="calendarYearLabel" class="text-3xl font-serif font-bold"><?php echo $filtroAno; ?></span>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="adjustFilterYear(-1)" class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center"><i data-lucide="chevron-up" class="w-5 h-5"></i></button>
                        <button onclick="adjustFilterYear(1)" class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center"><i data-lucide="chevron-down" class="w-5 h-5"></i></button>
                    </div>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-3 gap-2 mb-6">
                        <?php $ms=['01'=>'Jan','02'=>'Fev','03'=>'Mar','04'=>'Abr','05'=>'Mai','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Set','10'=>'Out','11'=>'Nov','12'=>'Dez']; 
                        foreach ($ms as $n=>$s): ?>
                            <button onclick="setFilterPeriod('<?php echo $n; ?>')" class="py-4 rounded-2xl text-xs font-black transition-all <?php echo $filtroMes===$n?'bg-indigo-600 text-white shadow-lg shadow-indigo-100':'bg-white text-slate-400 border border-slate-100'; ?>">
                                <?php echo $s; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <button onclick="setFilterPeriod('geral')" class="w-full py-5 rounded-2xl text-xs font-black transition-all <?php echo $filtroGeral?'bg-slate-900 text-white':'bg-slate-100 text-slate-500'; ?> uppercase tracking-widest">Ver Todo o Histórico</button>
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
            document.getElementById('updateQtdAtualLabel').innerText=ent; document.getElementById('updateQtdMaxLabel').innerText=max;
            document.getElementById('updateQtdFaltaLabel').innerText=max-ent;
            document.getElementById('updateValorRecebidoLabel').innerText=formatRealJS(valRecAtu);
            document.getElementById('updateValorPendenteLabel').innerText=formatRealJS(Math.max(0, (max*parseFloat(pr||0))-valRecAtu));
            toggleModal('modalUpdateQtd');
        }
        function openUpdatePagamento(id, rec, col, max, pr) {
            const val=parseFloat(rec||0), tot=parseInt(max||0)*parseFloat(pr||0);
            document.getElementById('updatePagId').value=id; document.getElementById('updatePagCollection').value=col;
            document.getElementById('updatePagAtualHidden').value=val;
            document.getElementById('updatePagLabel').innerText=formatRealJS(val);
            toggleModal('modalUpdatePagamento');
        }
        function setQtdRestante() { document.getElementById('updateQtdAdicionar').value = maxLoteAtual - entAtu; }
        function openEditRemessa(r) {
            document.getElementById('editRemessaId').value=r.id; document.getElementById('editRemessaCollection').value=r.__collection;
            // ... (Populate edit fields if needed, currently redirected to simple update)
            toggleModal('modalAddRemessa'); 
        }
        function adjustFilterYear(d) { activeFilterYear+=d; document.getElementById('calendarYearLabel').innerText=activeFilterYear; }
        function setFilterPeriod(m) { window.location.href=`?tab=<?php echo $activeTab; ?>&mes=${m}&ano=${activeFilterYear}`; }
        function formatRealJS(v) { return 'R$ ' + v.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}); }
        let activeFilterYear = <?php echo $filtroAno; ?>; lucide.createIcons();
    </script>
</body>
</html>
