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
        $useCache = (!$filtroGeral); // Cache apenas para visão histórica pesada? Não, vamos cachear tudo por 10 min
        
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
function abreviarNome($n) { $p = explode(' ', trim($n)); return count($p) > 1 ? $p[0] . ' ' . substr(end($p), 0, 1) . '.' : $n; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demanda - Ateliê</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>tailwind.config={theme:{extend:{fontFamily:{sans:['Inter','sans-serif'],serif:['Playfair Display','serif']},colors:{atelier:{brand:'#8E7355'}}}}} </script>
</head>
<body class="font-sans text-stone-800 bg-[#FAF6F0] min-h-screen flex flex-col">
    <header class="bg-white border-b h-20 flex items-center justify-between px-4 sticky top-0 z-30 shadow-sm">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-stone-900 rounded-full flex items-center justify-center text-atelier-brand"><i data-lucide="scissors" class="w-5 h-5"></i></div>
            <span class="font-serif text-xl font-bold">Demanda</span>
        </div>
        <nav class="flex gap-2 bg-stone-100 p-1 rounded-xl">
            <a href="?tab=remessas" class="px-4 py-1.5 rounded-lg text-xs font-bold <?php echo $activeTab==='remessas'?'bg-white shadow-sm':'text-stone-500'; ?>">Remessas</a>
            <a href="?tab=dashboard" class="px-4 py-1.5 rounded-lg text-xs font-bold <?php echo $activeTab==='dashboard'?'bg-white shadow-sm':'text-stone-500'; ?>">Dashboard</a>
        </nav>
        <div class="flex items-center gap-2 bg-emerald-50 text-emerald-800 px-3 py-1 rounded-lg text-[10px] font-bold"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> ONLINE</div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6 flex-grow w-full">
        <?php if ($msgSuccess): ?><div class="mb-4 p-4 bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-xl text-sm flex items-center gap-2"><i data-lucide="check-circle" class="w-4 h-4"></i><?php echo $msgSuccess; ?></div><?php endif; ?>
        <?php if ($msgError): ?><div class="mb-4 p-4 bg-rose-50 text-rose-800 border border-rose-200 rounded-xl text-sm flex items-center gap-2"><i data-lucide="alert-circle" class="w-4 h-4"></i><?php echo $msgError; ?></div><?php endif; ?>

        <?php if ($activeTab==='remessas'): ?>
            <div class="flex justify-between items-center mb-6">
                <button onclick="toggleModal('modalCalendar')" class="bg-white border p-3 rounded-2xl flex items-center gap-3 shadow-sm active:scale-95 transition-all">
                    <i data-lucide="calendar" class="w-5 h-5 text-stone-400"></i>
                    <span class="text-sm font-bold capitalize"><?php echo getMesNome($filtroMes); ?> <?php echo !$filtroGeral?'de '.$filtroAno:''; ?></span>
                </button>
                <div class="flex gap-2">
                    <button onclick="toggleModal('modalAddRemessa')" class="bg-stone-900 text-white p-3 rounded-2xl shadow-md"><i data-lucide="plus" class="w-5 h-5"></i></button>
                    <form method="POST"><input type="hidden" name="action" value="sync_remessas"><button type="submit" class="bg-white border p-3 rounded-2xl"><i data-lucide="refresh-cw" class="w-5 h-5"></i></button></form>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
                <?php foreach ($remessas as $r): 
                    $qT=intval($r['quantidade']??$r['qtd']??0); $qE=intval($r['qtd_entregue']??$r['entregue']??0); $prU=floatval($r['preco_unitario']??$r['precoU']??0); $rec=floatval($r['valor_recebido']??0); $tot=$qT*$prU; $pend=max(0,$tot-$rec); $perc=$qT>0?round(($qE/$qT)*100):0;
                ?>
                <div class="bg-white border rounded-3xl p-5 shadow-sm">
                    <div class="flex justify-between mb-4">
                        <h3 class="font-bold capitalize"><?php echo $qT; ?> <?php echo htmlspecialchars($r['peca_servico']??'Lote'); ?></h3>
                        <div class="flex gap-1.5">
                            <?php if($pend>0): ?><form method="POST"><input type="hidden" name="action" value="mark_paid_full"><input type="hidden" name="id" value="<?php echo $r['id']; ?>"><input type="hidden" name="collection" value="<?php echo $r['__collection']; ?>"><input type="hidden" name="total_lote" value="<?php echo $tot; ?>"><button type="submit" class="w-8 h-8 rounded-full bg-emerald-500 text-white flex items-center justify-center"><i data-lucide="check-check" class="w-4 h-4"></i></button></form><?php endif; ?>
                            <button onclick="openUpdatePagamento('<?php echo $r['id']; ?>','<?php echo $rec; ?>','<?php echo $r['__collection']; ?>','<?php echo $qT; ?>','<?php echo $prU; ?>')" class="w-8 h-8 rounded-full border-2 border-emerald-200 text-emerald-600 flex items-center justify-center font-bold text-xs">$</button>
                            <button onclick="openUpdateQtd('<?php echo $r['id']; ?>','<?php echo $qE; ?>','<?php echo $qT; ?>','<?php echo $r['__collection']; ?>','<?php echo $rec; ?>','<?php echo $prU; ?>')" class="w-8 h-8 rounded-full border-2 border-stone-300 text-stone-600 flex items-center justify-center font-bold text-xs"><?php echo $perc>=100?'<i data-lucide="check" class="w-4 h-4"></i>':'+'; ?></button>
                            <button onclick='openEditRemessa(<?php echo json_encode($r); ?>)' class="p-1 text-stone-400"><i data-lucide="pencil" class="w-4 h-4"></i></button>
                            <form method="POST" onsubmit="return confirm('Apagar?');"><input type="hidden" name="action" value="delete_remessa"><input type="hidden" name="id" value="<?php echo $r['id']; ?>"><input type="hidden" name="collection" value="<?php echo $r['__collection']; ?>"><button type="submit" class="p-1 text-stone-300 hover:text-rose-600"><i data-lucide="trash-2" class="w-4 h-4"></i></button></form>
                        </div>
                    </div>
                    <div class="flex justify-between text-[10px] font-bold uppercase mb-4">
                        <div><span class="text-stone-400">Tam:</span> <span class="bg-stone-50 border px-2 py-0.5 rounded-lg"><?php echo htmlspecialchars($r['tamanho']??$r['size']??'-'); ?></span></div>
                        <div class="text-right"><div class="text-emerald-600">Rec: <?php echo formatReal($rec); ?></div><?php if($pend>0): ?><div class="text-rose-500">Falta: <?php echo formatReal($pend); ?></div><?php endif; ?></div>
                    </div>
                    <div class="flex justify-between text-[10px] text-stone-400 font-bold mb-1"><span><?php echo formatDate($r['data_cadastro']??$r['data']??null); ?></span><span><?php echo $qE; ?>/<?php echo $qT; ?></span></div>
                    <div class="w-full bg-stone-100 h-1.5 rounded-full overflow-hidden"><div class="h-full <?php echo $perc>=100?'bg-emerald-500':'bg-stone-900'; ?>" style="width:<?php echo $perc; ?>%"></div></div>
                </div>
                <?php endforeach; ?>
            </div>

            <section class="bg-stone-900 rounded-3xl p-6 text-white grid grid-cols-1 md:grid-cols-3 gap-4 shadow-lg">
                <div class="flex flex-col"> <span class="text-[9px] text-stone-400 uppercase font-bold">Total Bruto</span> <span class="text-xl font-bold"><?php echo formatReal($statsRemessas['valor_total']); ?></span> </div>
                <div class="flex flex-col"> <span class="text-[9px] text-stone-400 uppercase font-bold">Recebido</span> <span class="text-xl font-bold text-emerald-400"><?php echo formatReal($statsRemessas['valor_recebido']); ?></span> </div>
                <div class="flex flex-col"> <span class="text-[9px] text-stone-400 uppercase font-bold">Saldo</span> <span class="text-xl font-bold text-rose-400"><?php echo formatReal($statsRemessas['valor_pendente']); ?></span> </div>
            </section>

        <?php elseif ($activeTab==='dashboard'): ?>
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-2xl font-serif font-bold">Dashboard</h1>
                <button onclick="toggleModal('modalCalendar')" class="text-xs font-bold bg-white border px-4 py-2 rounded-xl flex items-center gap-2"><i data-lucide="filter" class="w-4 h-4"></i> <?php echo $filtroGeral?'Visão Geral':getMesNome($filtroMes).'/'.$filtroAno; ?></button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white p-6 rounded-3xl border shadow-sm"> <span class="text-[10px] font-bold text-stone-400 uppercase">Total Geral</span> <h3 class="text-2xl font-bold mt-1"><?php echo formatReal($statsRemessas['valor_total']); ?></h3> </div>
                <div class="bg-white p-6 rounded-3xl border shadow-sm"> <span class="text-[10px] font-bold text-emerald-600 uppercase">Total Pago</span> <h3 class="text-2xl font-bold mt-1 text-emerald-700"><?php echo formatReal($statsRemessas['valor_recebido']); ?></h3> </div>
                <div class="bg-white p-6 rounded-3xl border shadow-sm"> <span class="text-[10px] font-bold text-rose-600 uppercase">Total Pendente</span> <h3 class="text-2xl font-bold mt-1 text-rose-700"><?php echo formatReal($statsRemessas['valor_pendente']); ?></h3> </div>
            </div>

            <div class="bg-white p-6 rounded-3xl border shadow-sm mb-8">
                <h2 class="font-serif font-bold mb-6 flex items-center gap-2"><i data-lucide="history" class="w-5 h-5"></i> Faturamento por Ano</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <?php foreach ($statsPorAno as $ano => $s): ?>
                        <div class="bg-stone-50 p-4 rounded-2xl border">
                            <div class="font-bold text-lg"><?php echo $ano; ?></div>
                            <div class="text-[10px] font-bold mt-1 text-stone-500">T: <?php echo formatReal($s['total']); ?></div>
                            <div class="text-[10px] font-bold text-emerald-600">P: <?php echo formatReal($s['recebido']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-white p-6 rounded-3xl border shadow-sm overflow-hidden">
                <h2 class="font-serif font-bold mb-6 flex items-center gap-2"><i data-lucide="list-checks" class="w-5 h-5"></i> Registros Recentes</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="text-[10px] text-stone-400 font-bold uppercase border-b"> <tr><th class="pb-3">Lote</th><th class="pb-3 text-center">Progresso</th><th class="pb-3 text-right">Valor</th></tr> </thead>
                        <tbody class="divide-y text-sm">
                            <?php foreach (array_slice($remessas, 0, 10) as $r): $v=intval($r['quantidade']??$r['qtd']??0)*floatval($r['preco_unitario']??$r['precoU']??0); ?>
                            <tr>
                                <td class="py-4 font-bold capitalize"><?php echo htmlspecialchars($r['peca_servico']??'Lote'); ?><div class="text-[10px] font-normal text-stone-400"><?php echo formatDate($r['data_cadastro']??null); ?></div></td>
                                <td class="py-4 text-center text-xs font-bold text-stone-600"><?php echo intval($r['qtd_entregue']??0); ?>/<?php echo intval($r['quantidade']??0); ?></td>
                                <td class="py-4 text-right font-bold"><?php echo formatReal($v); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <div id="modalAddRemessa" class="fixed inset-0 z-50 overflow-y-auto hidden"><div class="flex items-center justify-center min-h-screen px-4"><div class="fixed inset-0 bg-stone-950/40" onclick="toggleModal('modalAddRemessa')"></div><div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-md w-full border"><div class="bg-stone-900 text-white p-6 flex justify-between items-center"><h3 class="font-serif text-lg font-bold">Nova Remessa</h3><button onclick="toggleModal('modalAddRemessa')"><i data-lucide="x" class="w-6 h-6"></i></button></div><form method="POST" class="p-6 space-y-4"><input type="hidden" name="action" value="add_remessa"><input type="text" name="peca_servico" required placeholder="Peça" class="w-full bg-stone-50 border rounded-xl p-3 outline-none"><div class="grid grid-cols-2 gap-4"><input type="number" step="0.01" name="preco_unitario" required placeholder="Preço" class="w-full bg-stone-50 border rounded-xl p-3 outline-none"><input type="number" name="quantidade" required placeholder="Qtd" class="w-full bg-stone-50 border rounded-xl p-3 outline-none"></div><div class="flex flex-wrap gap-2 justify-center"><?php foreach (['PP','P','M','G','GG','XG','outro'] as $t): ?><label class="px-3 py-2 border rounded-xl cursor-pointer text-xs font-bold"><input type="radio" name="tamanho" value="<?php echo $t; ?>" <?php echo $t==='M'?'checked':''; ?>> <?php echo $t; ?></label><?php endforeach; ?></div><button type="submit" class="w-full bg-stone-950 text-white font-bold py-4 rounded-xl">Salvar</button></form></div></div></div>
    <div id="modalUpdateQtd" class="fixed inset-0 z-50 overflow-y-auto hidden"><div class="flex items-center justify-center min-h-screen px-4"><div class="fixed inset-0 bg-stone-950/40" onclick="toggleModal('modalUpdateQtd')"></div><div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-sm w-full border"><div class="bg-stone-900 text-white p-5 flex justify-between items-center"><h3>Entregas</h3><button onclick="toggleModal('modalUpdateQtd')"><i data-lucide="x" class="w-5 h-5"></i></button></div><form method="POST" class="p-6 space-y-4"><input type="hidden" name="action" value="update_entrega"><input type="hidden" name="id" id="updateQtdId"><input type="hidden" name="collection" id="updateQtdCollection"><input type="hidden" name="qtd_atual" id="updateQtdAtualHidden"><input type="hidden" name="valor_recebido_atual" id="updateValorRecebidoAtualHidden"><p class="text-xs">Entregou <span id="updateQtdAtualLabel" class="font-bold">0</span> de <span id="updateQtdMaxLabel" class="font-bold">0</span>. Faltam: <span id="updateQtdFaltaLabel" class="font-bold text-rose-600">0</span></p><div class="flex gap-2"><input type="number" name="qtd_adicionar" id="updateQtdAdicionar" value="1" class="flex-grow border rounded-xl p-3 outline-none"><button type="button" onclick="setQtdRestante()" class="bg-stone-100 px-4 rounded-xl text-xs font-bold border">Tudo</button></div><div><label class="text-[10px] font-bold text-stone-400">RECEBER AGORA (R$)</label><input type="number" step="0.01" name="valor_recebido_agora" id="updateValorRecebidoAgora" class="w-full border rounded-xl p-3 outline-none"><p class="text-[9px] mt-1">Rec: <span id="updateValorRecebidoLabel">0</span> / Falta: <span id="updateValorPendenteLabel" class="text-rose-500">0</span></p></div><button type="submit" class="w-full bg-stone-950 text-white font-bold py-4 rounded-xl">Confirmar</button></form></div></div></div>
    <div id="modalUpdatePagamento" class="fixed inset-0 z-50 overflow-y-auto hidden"><div class="flex items-center justify-center min-h-screen px-4"><div class="fixed inset-0 bg-stone-950/40" onclick="toggleModal('modalUpdatePagamento')"></div><div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-sm w-full border"><div class="bg-emerald-900 text-white p-5 flex justify-between items-center"><h3>Pagamento</h3><button onclick="toggleModal('modalUpdatePagamento')"><i data-lucide="x" class="w-5 h-5"></i></button></div><form method="POST" class="p-6 space-y-4"><input type="hidden" name="action" value="update_pagamento"><input type="hidden" name="id" id="updatePagId"><input type="hidden" name="collection" id="updatePagCollection"><input type="hidden" name="valor_recebido_atual" id="updatePagAtualHidden"><input type="number" step="0.01" name="valor_recebido_agora" required class="w-full border rounded-xl p-3 outline-none"><p class="text-[10px]">Rec: <span id="updatePagLabel">0</span>. Falta: <span id="updatePagPendenteLabel" class="text-rose-600 font-bold">0</span></p><button type="submit" class="w-full bg-emerald-600 text-white font-bold py-4 rounded-xl">Salvar</button></form></div></div></div>
    <div id="modalCalendar" class="fixed inset-0 z-50 overflow-y-auto hidden"><div class="flex items-center justify-center min-h-screen px-4"><div class="fixed inset-0 bg-stone-950/40" onclick="toggleModal('modalCalendar')"></div><div class="bg-white rounded-3xl shadow-2xl z-10 max-w-sm w-full border"><div class="bg-stone-950 text-white p-5 flex justify-between items-center"><div><span id="calendarYearLabel" class="text-2xl font-serif font-bold"><?php echo $filtroAno; ?></span></div><div class="flex gap-2"><button onclick="adjustFilterYear(-1)" class="p-1 bg-stone-800 rounded-lg"><i data-lucide="chevron-up" class="w-4 h-4"></i></button><button onclick="adjustFilterYear(1)" class="p-1 bg-stone-800 rounded-lg"><i data-lucide="chevron-down" class="w-4 h-4"></i></button></div></div><div class="p-6"><div class="grid grid-cols-3 gap-2 mb-6"><?php $ms=['01'=>'Jan','02'=>'Fev','03'=>'Mar','04'=>'Abr','05'=>'Mai','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Set','10'=>'Out','11'=>'Nov','12'=>'Dez']; foreach ($ms as $n=>$s): ?><button onclick="setFilterPeriod('<?php echo $n; ?>')" class="py-3 rounded-xl text-xs font-bold border <?php echo $filtroMes===$n?'bg-blue-500 text-white':'bg-stone-50'; ?>"><?php echo $s; ?></button><?php endforeach; ?></div><button onclick="setFilterPeriod('geral')" class="w-full py-4 rounded-xl text-[10px] font-bold border <?php echo $filtroGeral?'bg-stone-900 text-white':'bg-white'; ?> uppercase tracking-widest">Ver Todo o Histórico</button></div></div></div></div>

    <script>
        function toggleModal(id) { document.getElementById(id).classList.toggle('hidden'); }
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
            document.getElementById('updateQtdAdicionar').value=(max-ent)>0?1:0; toggleModal('modalUpdateQtd');
        }
        function openUpdatePagamento(id, rec, col, max, pr) {
            const val=parseFloat(rec||0), tot=parseInt(max||0)*parseFloat(pr||0), pend=Math.max(0,tot-val);
            document.getElementById('updatePagId').value=id; document.getElementById('updatePagCollection').value=col;
            document.getElementById('updatePagAtualHidden').value=val;
            document.getElementById('updatePagLabel').innerText=formatRealJS(val);
            document.getElementById('updatePagPendenteLabel').innerText=formatRealJS(pend); toggleModal('modalUpdatePagamento');
        }
        function setQtdRestante() { document.getElementById('updateQtdAdicionar').value = maxLoteAtual - entAtu; }
        function openEditRemessa(r) {
            document.getElementById('editRemessaId').value=r.id; document.getElementById('editRemessaCollection').value=r.__collection;
            document.getElementById('editRemessaPeca').value=r.peca_servico || r.peca; document.getElementById('editRemessaPreco').value=r.preco_unitario || r.precoU;
            document.getElementById('editRemessaQtd').value=r.quantidade || r.qtd; toggleModal('modalEditRemessa');
        }
        function adjustFilterYear(d) { activeFilterYear+=d; document.getElementById('calendarYearLabel').innerText=activeFilterYear; }
        function setFilterPeriod(m) { window.location.href=`?tab=<?php echo $activeTab; ?>&mes=${m}&ano=${activeFilterYear}`; }
        function formatRealJS(v) { return 'R$ ' + v.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}); }
        let activeFilterYear = <?php echo $filtroAno; ?>; lucide.createIcons();
    </script>
</body>
</html>
