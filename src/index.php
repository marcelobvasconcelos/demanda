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

// Lógica de Abas
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'remessas';

// Filtro de Mês/Ano
$filtroMes = isset($_GET['mes']) ? $_GET['mes'] : (isset($_SESSION['filtro_mes']) ? $_SESSION['filtro_mes'] : date('m'));
$filtroAno = isset($_GET['ano']) ? $_GET['ano'] : (isset($_SESSION['filtro_ano']) ? $_SESSION['filtro_ano'] : date('Y'));
$filtroGeral = ($filtroMes === 'geral');

$_SESSION['filtro_mes'] = $filtroMes;
$_SESSION['filtro_ano'] = $filtroAno;

$msgSuccess = '';
$msgError = '';
$loginDebugMessage = '';


// Lógica de Ações / Formulários (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $firestoreEnabled) {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'login') {
        $email = isset($_POST['email']) ? strtolower(trim($_POST['email'])) : '';
        $senha = trim($_POST['senha'] ?? '');

        if ($email === '' || $senha === '') {
            $msgError = 'E-mail e senha sao obrigatorios.';
        } else {
            try {
                $signInResult = firebase_auth_sign_in_with_password($email, $senha);
                if ($signInResult && isset($signInResult['email'])) {
                    $userEmail = $signInResult['email'];
                    $userName = $signInResult['displayName'] ?? $userEmail;
                    $_SESSION['usuario_email'] = $userEmail;
                    $_SESSION['usuario_nome'] = $userName;
                    $_SESSION['firebase_localId'] = $signInResult['localId'] ?? null;
                    $msgSuccess = 'Bem-vindo(a), ' . htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') . '!';
                } else {
                    $msgError = 'E-mail ou senha incorretos. Tente novamente.';
                }
            } catch (Throwable $e) {
                $msgError = 'Falha no login Firebase: ' . $e->getMessage();
            }
        }
    }

    elseif ($action === 'register_seamstress') {
        $msgError = 'Cadastro via app não está disponível. Use o Firebase Authentication ou crie a conta no console.';
    }

    elseif ($action === 'logout') {
        unset($_SESSION['usuario_email'], $_SESSION['usuario_nome'], $_SESSION['firebase_localId']);
        $msgSuccess = 'Você foi desconectado. Faça login para continuar.';
    }

    elseif ($action === 'add_remessa') {
        $usuarioEmail = $_SESSION['usuario_email'] ?? '';
        $usuarioNome = $_SESSION['usuario_nome'] ?? '';
        $peca = trim($_POST['peca_servico'] ?? '');
        $preco = floatval($_POST['preco_unitario'] ?? 0);
        $qtd = intval($_POST['quantidade'] ?? 0);
        $tamanho = $_POST['tamanho'] ?? 'outro';
        $dataCadastro = date("Y-m-d\TH:i:s");

        if ($usuarioEmail === '') {
            $msgError = 'Faça login para registrar remessas.';
        } elseif ($peca === '' || $preco <= 0 || $qtd <= 0) {
            $msgError = 'Preencha todos os campos obrigatórios corretamente.';
        } else {
            try {
                firestore_add_remessa([
                    'usuario_email' => $usuarioEmail,
                    'usuario_uid' => $_SESSION['firebase_localId'] ?? null,
                    'usuario_nome' => $usuarioNome,
                    'peca_servico' => $peca,
                    'preco_unitario' => $preco,
                    'quantidade' => $qtd,
                    'tamanho' => $tamanho,
                    'qtd_entregue' => 0,
                    'data_cadastro' => $dataCadastro,
                ]);
                $msgSuccess = "Lote de $qtd $peca(s) cadastrado com sucesso!";
            } catch (Throwable $e) {
                $msgError = 'Erro ao salvar remessa no Firestore: ' . $e->getMessage();
            }
        }
    }

    elseif ($action === 'update_entrega') {
        $docId = trim($_POST['id'] ?? '');
        $collection = trim($_POST['collection'] ?? '');
        $qtdAdicionar = intval($_POST['qtd_adicionar'] ?? 0);
        $qtdAtual = intval($_POST['qtd_atual'] ?? 0);
        $qtdTotal = max(0, $qtdAtual + $qtdAdicionar); // Evita total negativo
        
        $valorRecebidoAgora = floatval($_POST['valor_recebido_agora'] ?? 0);
        $valorRecebidoAtual = floatval($_POST['valor_recebido_atual'] ?? 0);
        $valorRecebidoTotal = max(0, $valorRecebidoAtual + $valorRecebidoAgora); // Evita total negativo

        $usuarioUid = $_SESSION['firebase_localId'] ?? firestore_get_user_uid_by_email($_SESSION['usuario_email'] ?? '');

        if ($docId === '' || $collection === '') {
            $msgError = 'Documento de remessa inválido.';
        } elseif (!firestore_collection_belongs_to_user($collection, $usuarioUid ?? '')) {
            $msgError = 'Esta remessa não pertence ao usuário logado.';
        } else {
            try {
                $dataUltimaEntrega = $qtdTotal > 0 ? date('Y-m-d\TH:i:s') : null;
                firestore_update_remessa_entrega($docId, $qtdTotal, $dataUltimaEntrega, $collection, $valorRecebidoTotal);
                
                if ($qtdAdicionar < 0 || $valorRecebidoAgora < 0) {
                    $msgSuccess = 'Estorno registrado com sucesso!';
                } else {
                    $msgSuccess = 'Entrega registrada com sucesso! +' . $qtdAdicionar . ' peças.';
                }
            } catch (Throwable $e) {
                $msgError = 'Erro ao atualizar remessa no Firestore: ' . $e->getMessage();
            }
        }
    }

    elseif ($action === 'update_pagamento') {
        $docId = trim($_POST['id'] ?? '');
        $collection = trim($_POST['collection'] ?? '');
        $valorRecebidoAgora = floatval($_POST['valor_recebido_agora'] ?? 0);
        $valorRecebidoAtual = floatval($_POST['valor_recebido_atual'] ?? 0);
        $valorRecebidoTotal = max(0, $valorRecebidoAtual + $valorRecebidoAgora);

        $usuarioUid = $_SESSION['firebase_localId'] ?? firestore_get_user_uid_by_email($_SESSION['usuario_email'] ?? '');

        if ($docId === '' || $collection === '') {
            $msgError = 'Documento de remessa inválido.';
        } elseif (!firestore_collection_belongs_to_user($collection, $usuarioUid ?? '')) {
            $msgError = 'Esta remessa não pertence ao usuário logado.';
        } else {
            try {
                firestore_update_remessa($docId, ['valor_recebido' => $valorRecebidoTotal], $collection);
                $msgSuccess = $valorRecebidoAgora < 0 ? 'Estorno de pagamento realizado!' : 'Pagamento registrado com sucesso! +' . formatReal($valorRecebidoAgora);
            } catch (Throwable $e) {
                $msgError = 'Erro ao atualizar pagamento no Firestore: ' . $e->getMessage();
            }
        }
    }

    elseif ($action === 'edit_remessa') {
        $docId = trim($_POST['id'] ?? '');
        $collection = trim($_POST['collection'] ?? '');
        $peca = trim($_POST['peca_servico'] ?? '');
        $preco = floatval($_POST['preco_unitario'] ?? 0);
        $qtd = intval($_POST['quantidade'] ?? 0);
        $tamanho = $_POST['tamanho'] ?? 'outro';
        $usuarioUid = $_SESSION['firebase_localId'] ?? firestore_get_user_uid_by_email($_SESSION['usuario_email'] ?? '');

        if ($docId === '' || $collection === '') {
            $msgError = 'Documento de remessa inválido.';
        } elseif ($peca === '' || $preco <= 0 || $qtd <= 0) {
            $msgError = 'Preencha todos os campos corretamente.';
        } elseif (!firestore_collection_belongs_to_user($collection, $usuarioUid ?? '')) {
            $msgError = 'Esta remessa não pertence ao usuário logado.';
        } else {
            try {
                firestore_update_remessa($docId, [
                    'peca_servico' => $peca,
                    'preco_unitario' => $preco,
                    'quantidade' => $qtd,
                    'tamanho' => $tamanho
                ], $collection);
                $msgSuccess = 'Remessa atualizada com sucesso!';
            } catch (Throwable $e) {
                $msgError = 'Erro ao atualizar remessa no Firestore: ' . $e->getMessage();
            }
        }
    }

    elseif ($action === 'delete_remessa') {
        $docId = trim($_POST['id'] ?? '');
        $collection = trim($_POST['collection'] ?? '');
        $usuarioUid = $_SESSION['firebase_localId'] ?? firestore_get_user_uid_by_email($_SESSION['usuario_email'] ?? '');
        if ($docId === '' || $collection === '') {
            $msgError = 'Documento de remessa inválido.';
        } elseif (!firestore_collection_belongs_to_user($collection, $usuarioUid ?? '')) {
            $msgError = 'Esta remessa não pertence ao usuário logado.';
        } else {
            try {
                firestore_delete_document($collection, $docId);
                $msgSuccess = 'Remessa excluída com sucesso.';
            } catch (Throwable $e) {
                $msgError = 'Erro ao excluir remessa do Firestore: ' . $e->getMessage();
            }
        }
    }

    elseif ($action === 'sync_remessas') {
        try {
            if ($dbConnected && $pdo) {
                sync_firestore_on_start($pdo, ['force' => true]);
            }
            $msgSuccess = 'Dados do Firebase recarregados com sucesso!';
            // Redireciona para evitar re-submit e limpar cache do browser se houver
            header("Location: ?tab={$activeTab}&mes={$filtroMes}&ano={$filtroAno}&synced=1");
            exit;
        } catch (Throwable $e) {
            $msgError = 'Erro ao sincronizar: ' . $e->getMessage();
        }
    }
}


if (!isset($_SESSION['usuario_email'])) {
    include __DIR__ . '/login_screen.php';
    exit;
}

// ----------------------------------------------------
// Buscar Dados Gerais/Mensais do Firestore
// ----------------------------------------------------
$remessas = [];
$statsRemessas = ['valor_total' => 0.0, 'pecas_totais' => 0, 'pecas_entregues' => 0, 'valor_recebido' => 0.0, 'valor_pendente' => 0.0];

if ($firestoreEnabled) {
    $usuarioEmail = $_SESSION['usuario_email'] ?? '';
    if ($usuarioEmail !== '') {
        try {
            if ($filtroGeral) {
                $remessas = firestore_get_all_user_remessas($usuarioEmail);
            } else {
                $startDate = sprintf('%s-%s-01', $filtroAno, str_pad($filtroMes, 2, '0', STR_PAD_LEFT));
                $endDate = sprintf('%s-%s-%s', $filtroAno, str_pad($filtroMes, 2, '0', STR_PAD_LEFT), date('t', strtotime($startDate)));
                $remessas = firestore_query_remessas($usuarioEmail, $startDate, $endDate);
            }
        } catch (Throwable $e) {
            $msgError = 'Falha ao carregar dados do Firestore: ' . $e->getMessage();
            $remessas = [];
        }
    }

    foreach ($remessas as $remessa) {
        $quantidade = max(0, intval($remessa['quantidade'] ?? 0));
        $qtdEntregue = max(0, min(intval($remessa['qtd_entregue'] ?? 0), $quantidade));
        $valorLote = $quantidade * floatval($remessa['preco_unitario'] ?? 0);
        $valorRecebido = floatval($remessa['valor_recebido'] ?? 0);

        $statsRemessas['valor_total'] += $valorLote;
        $statsRemessas['pecas_totais'] += $quantidade;
        $statsRemessas['pecas_entregues'] += $qtdEntregue;
        $statsRemessas['valor_recebido'] += $valorRecebido;
    }
    $statsRemessas['valor_pendente'] = max(0, $statsRemessas['valor_total'] - $statsRemessas['valor_recebido']);
}

// Utilitários de Data e Nomes
function formatReal($val) {
    return 'R$ ' . number_format($val, 2, ',', '.');
}

function formatDate($dateStr) {
    if (!$dateStr) return '-';
    try {
        $date = new DateTime($dateStr);
        return $date->format('d/m/Y');
    } catch (Throwable $e) {
        return substr($dateStr, 0, 10);
    }
}

function getMesNome($mesNum) {
    if ($mesNum === 'geral') return 'todo o período';
    $meses = [
        '01' => 'janeiro', '02' => 'fevereiro', '03' => 'março', '04' => 'abril',
        '05' => 'maio', '06' => 'junho', '07' => 'julho', '08' => 'agosto',
        '09' => 'setembro', '10' => 'outubro', '11' => 'novembro', '12' => 'dezembro'
    ];
    return $meses[$mesNum] ?? 'maio';
}

// Abreviar o nome da costureira no banner
function abreviarNome($nomeCompleto) {
    $partes = explode(' ', trim($nomeCompleto));
    if (count($partes) > 1) {
        return $partes[0] . ' ' . substr($partes[count($partes)-1], 0, 1) . '.';
    }
    return $nomeCompleto;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demanda - Sistema de Gestão de Atendimento</title>
    
    <!-- Google Fonts: Inter & Playfair Display -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS v4 CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        serif: ['Playfair Display', 'serif'],
                    },
                    colors: {
                        atelier: {
                            brand: '#8E7355',   // Ouro fosco/Linha de Linho
                            dark: '#1E1E1E',    // Preto texturizado
                            cream: '#FCF9F5',   // Creme suave de fundo
                            accent: '#F3ECE0',  // Contraste suave de algodão
                        }
                    }
                }
            }
        }
    </script>
    
    <style>
        body {
            background-color: #FAF6F0;
        }
    </style>
</head>
<body class="font-sans text-stone-800 antialiased min-h-screen flex flex-col justify-between">

    <!-- Header / Navbar Principal -->
    <header class="bg-white border-b border-stone-200 sticky top-0 z-30 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <!-- Branding -->
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-stone-900 rounded-full flex items-center justify-center text-atelier-brand shadow-inner">
                        <i data-lucide="scissors" class="w-6 h-6 transform -rotate-45"></i>
                    </div>
                    <div>
                        <span class="font-serif text-2xl font-bold tracking-tight text-stone-900 block leading-tight">Demanda</span>
                        <span class="text-xs uppercase tracking-wider text-stone-400 font-semibold">Gestão de Costura &amp; Finanças</span>
                    </div>
                </div>

                <!-- Abas de Navegação Principal -->
                <nav class="hidden md:flex gap-1.5 bg-stone-100 p-1.5 rounded-2xl border border-stone-200">
                    <a href="?tab=remessas" class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2 <?php echo $activeTab === 'remessas' ? 'bg-white text-stone-950 shadow-sm' : 'text-stone-500 hover:text-stone-900'; ?>">
                        <i data-lucide="needle" class="w-3.5 h-3.5"></i> Remessas do Mês
                    </a>
                    <a href="?tab=dashboard" class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2 <?php echo $activeTab === 'dashboard' ? 'bg-white text-stone-950 shadow-sm' : 'text-stone-500 hover:text-stone-900'; ?>">
                        <i data-lucide="layout-dashboard" class="w-3.5 h-3.5"></i> Dashboard Geral
                    </a>
                </nav>
                
                <!-- Status da Conexão / Perfil Rápido -->
                <div class="flex items-center gap-2.5">
                    <?php if (!$firestoreEnabled): ?>
                        <div class="flex items-center gap-1.5 bg-amber-50 text-amber-800 px-3 py-1.5 rounded-xl border border-amber-200 text-xs font-medium">
                            <i data-lucide="database-backup" class="w-3.5 h-3.5 text-amber-600 animate-pulse"></i>
                            <span class="hidden sm:inline">Firestore Offline</span>
                        </div>
                    <?php else: ?>
                        <div class="flex items-center gap-1.5 bg-emerald-50 text-emerald-800 px-3 py-1.5 rounded-xl border border-emerald-100 text-xs font-medium">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            <span class="hidden sm:inline">Firebase Online</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Menu de Abas Mobile -->
    <div class="md:hidden bg-white border-b border-stone-200 px-4 py-2.5 flex justify-center gap-2">
        <a href="?tab=remessas" class="flex-1 py-2 text-center rounded-xl text-xs font-bold transition-all <?php echo $activeTab === 'remessas' ? 'bg-stone-900 text-white' : 'bg-stone-100 text-stone-600'; ?>">
            Remessas do Mês
        </a>
        <a href="?tab=dashboard" class="flex-1 py-2 text-center rounded-xl text-xs font-bold transition-all <?php echo $activeTab === 'dashboard' ? 'bg-stone-900 text-white' : 'bg-stone-100 text-stone-600'; ?>">
            Dashboard Geral
        </a>
    </div>

    <!-- Container do Conteúdo Principal -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-grow w-full">

        <!-- Mensagens de Feedback -->
        <?php if (!empty($msgSuccess)): ?>
            <div class="mb-6 p-4 bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-2xl flex items-center gap-3 text-sm font-medium shadow-sm">
                <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-600"></i>
                <span><?php echo htmlspecialchars($msgSuccess); ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($msgError)): ?>
            <div class="mb-6 p-4 bg-rose-50 text-rose-800 border border-rose-200 rounded-2xl flex items-center gap-3 text-sm font-medium shadow-sm">
                <i data-lucide="alert-circle" class="w-5 h-5 text-rose-600"></i>
                <span><?php echo htmlspecialchars($msgError); ?></span>
            </div>
        <?php endif; ?>

        <!-- ================================================================= -->
        <!-- ABA: REMESSAS DO MÊS (INSPIRADA NO APP ANTIGO)                    -->
        <!-- ================================================================= -->
        <?php if ($activeTab === 'remessas'): ?>
            
            <!-- Controle Superior de Perfil & Filtros -->
            <section class="bg-white border border-stone-200 rounded-3xl p-5 shadow-sm mb-8 flex flex-col md:flex-row items-center justify-between gap-6">
                <!-- Data & Costureira Logada (Esquerda) -->
                <div class="flex items-center gap-4">
                    <!-- Botão Filtro Calendário -->
                    <button onclick="toggleModal('modalCalendar')" class="flex items-center gap-3 bg-stone-50 border border-stone-200 hover:bg-stone-100 p-3 rounded-2xl transition-all text-left shadow-sm cursor-pointer active:scale-95">
                        <div class="p-2 bg-stone-900 text-white rounded-xl">
                            <i data-lucide="calendar" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <span class="block text-[10px] uppercase font-bold text-stone-400 tracking-wider">Período Selecionado</span>
                            <span class="block font-bold text-stone-900 text-sm capitalize leading-tight"><?php echo getMesNome($filtroMes); ?> <?php echo !$filtroGeral ? 'de ' . $filtroAno : ''; ?></span>
                        </div>
                    </button>

                    <!-- Usuária Ativa -->
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-stone-400 font-medium">Costureira:</span>
                        <span class="bg-atelier-accent text-stone-800 px-3 py-1 rounded-xl text-xs font-bold border border-stone-200/60"><?php echo htmlspecialchars($_SESSION['usuario_nome']); ?></span>
                    </div>
                </div>

                <!-- Ações do Header (Direita, inspiradas na barra superior do App) -->
                <div class="flex items-center gap-2">
                    <!-- Botão Nova Remessa (+) -->
                    <button onclick="toggleModal('modalAddRemessa')" class="bg-stone-950 hover:bg-stone-850 text-white font-bold p-3 px-5 rounded-2xl text-sm transition-all flex items-center gap-2 active:scale-95 shadow-md cursor-pointer">
                        <i data-lucide="plus" class="w-4 h-4"></i> Nova Remessa
                    </button>

                    <!-- Botão Trocar Senha/Perfil (Chave) -->
                    <button onclick="toggleModal('modalLogin')" class="bg-white hover:bg-stone-100 text-stone-700 border border-stone-300 p-3 rounded-2xl transition-all shadow-sm cursor-pointer active:scale-95" title="Trocar Perfil">
                        <i data-lucide="key-round" class="w-4 h-4"></i>
                    </button>

                        <!-- Botão Sincronizar do Firestore -->
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="sync_remessas">
                        <button type="submit" class="bg-atelier-accent hover:bg-atelier-accent/90 text-stone-900 border border-atelier-accent p-3 rounded-2xl transition-all cursor-pointer active:scale-95" title="Recarregar dados do Firestore">
                            <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                        </button>
                    </form>

                    <!-- Botão Desconectar (Sair) -->
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 p-3 rounded-2xl transition-all cursor-pointer active:scale-95" title="Sair do Perfil">
                            <i data-lucide="log-out" class="w-4 h-4"></i>
                        </button>
                    </form>
                </div>
            </section>

            <!-- Banner do Perfil da Seamstress (Maiara V., com ícone de máquina) -->
            <section class="bg-stone-900 border border-stone-950 rounded-3xl p-6 mb-8 text-white relative overflow-hidden flex items-center gap-5 shadow-lg">
                <div class="absolute -right-12 -bottom-12 text-stone-800">
                    <i data-lucide="scissors" class="w-48 h-48 opacity-10"></i>
                </div>
                <div class="w-16 h-16 bg-stone-800 rounded-2xl flex items-center justify-center border border-stone-750 text-atelier-brand shadow-inner">
                    <!-- Ícone customizado de Máquina de Costura usando Lucide (Cpu + Needle + Shield) -->
                    <i data-lucide="printer" class="w-9 h-9"></i>
                </div>
                <div>
                    <h2 class="text-3xl sm:text-4xl font-bold font-serif tracking-tight text-white uppercase"><?php echo htmlspecialchars(abreviarNome($_SESSION['usuario_nome'])); ?></h2>
                    <p class="text-stone-400 text-xs mt-0.5 tracking-widest uppercase font-semibold">Tabela de Lotes Ativa no Mês</p>
                </div>
            </section>

            <!-- Lista de Remessas em Lotes -->
            <section class="space-y-4 mb-8">
                <?php if (empty($remessas)): ?>
                    <div class="bg-white border border-stone-200 rounded-3xl p-12 text-center">
                        <div class="w-16 h-16 bg-stone-100 text-stone-400 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i data-lucide="package-open" class="w-8 h-8"></i>
                        </div>
                        <h3 class="font-bold text-stone-900 text-lg">Nenhuma remessa registrada</h3>
                        <p class="text-stone-500 text-sm mt-1 max-w-sm mx-auto">
                            <?php if (!$firestoreEnabled): ?>
                                Firestore não está disponível no momento. Verifique as credenciais e tente novamente.
                            <?php elseif (empty($_SESSION['usuario_email'])): ?>
                                Faça login para visualizar suas remessas.
                            <?php else: ?>
                                Nenhum lote registrado para o período de <?php echo getMesNome($filtroMes); ?> <?php echo !$filtroGeral ? 'de ' . $filtroAno : ''; ?>.
                            <?php endif; ?>
                        </p>
                        <button onclick="toggleModal('modalAddRemessa')" class="mt-4 bg-stone-950 text-white px-5 py-2.5 rounded-xl font-bold text-sm hover:bg-stone-800 transition-all active:scale-95">
                            Criar Novo Lote
                        </button>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($remessas as $remessa): ?>
                            <?php 
                                $percentEntregue = $remessa['quantidade'] > 0 ? round(($remessa['qtd_entregue'] / $remessa['quantidade']) * 100) : 0;
                                $isConcluido = $remessa['qtd_entregue'] >= $remessa['quantidade'];
                            ?>
                            <div class="bg-white border border-stone-200 hover:border-stone-300 rounded-2xl p-5 shadow-sm hover:shadow-md transition-all relative flex flex-col justify-between">
                                <div>
                                    <!-- Primeira Linha: Peça & Botões -->
                                    <div class="flex items-center justify-between mb-3.5">
                                        <div class="flex items-center gap-2">
                                            <span class="text-lg font-bold text-stone-900 tracking-tight capitalize leading-none">
                                                <?php echo htmlspecialchars($remessa['quantidade']); ?> <?php echo htmlspecialchars($remessa['peca_servico']); ?>(s)
                                            </span>
                                            <!-- VÊ MAIS trigger -->
                                            <button onclick="toggleDetails(<?php echo json_encode($remessa['id']); ?>)" class="text-[10px] font-bold text-atelier-brand hover:text-stone-950 uppercase tracking-wider bg-stone-100 hover:bg-stone-200 px-2 py-0.5 rounded-md cursor-pointer transition-all active:scale-95">
                                                Vê Mais
                                            </button>
                                        </div>

                                        <!-- Ações rápidas -->
                                        <div class="flex items-center gap-1.5">
                                            <!-- Registrar Pagamento (Símbolo $) -->
                                            <button onclick="openUpdatePagamento(<?php echo htmlspecialchars(json_encode($remessa['id']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo $remessa['valor_recebido']; ?>, <?php echo htmlspecialchars(json_encode($remessa['__collection'] ?? 'remessas'), ENT_QUOTES, 'UTF-8'); ?>, <?php echo $remessa['quantidade']; ?>, <?php echo $remessa['preco_unitario']; ?>)" class="w-8 h-8 rounded-full border-2 border-emerald-200 hover:border-emerald-600 bg-white flex items-center justify-center text-emerald-500 hover:text-emerald-700 transition-colors cursor-pointer" title="Registrar Pagamento">
                                                <span class="text-xs font-bold">$</span>
                                            </button>

                                            <!-- Abre atualização de quantidade entregue -->
                                            <?php if ($isConcluido): ?>
                                                <button onclick="openUpdateQtd(<?php echo htmlspecialchars(json_encode($remessa['id']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo $remessa['qtd_entregue']; ?>, <?php echo $remessa['quantidade']; ?>, <?php echo htmlspecialchars(json_encode($remessa['__collection'] ?? 'remessas'), ENT_QUOTES, 'UTF-8'); ?>, <?php echo $remessa['valor_recebido']; ?>, <?php echo $remessa['preco_unitario']; ?>)" class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center border border-emerald-200 hover:border-emerald-400 transition-colors cursor-pointer" title="Lote Concluído (Clique para alterar)">
                                                    <i data-lucide="check" class="w-4 h-4 font-bold"></i>
                                                </button>
                                            <?php else: ?>
                                                <button onclick="openUpdateQtd(<?php echo htmlspecialchars(json_encode($remessa['id']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo $remessa['qtd_entregue']; ?>, <?php echo $remessa['quantidade']; ?>, <?php echo htmlspecialchars(json_encode($remessa['__collection'] ?? 'remessas'), ENT_QUOTES, 'UTF-8'); ?>, <?php echo $remessa['valor_recebido']; ?>, <?php echo $remessa['preco_unitario']; ?>)" class="w-8 h-8 rounded-full border-2 border-stone-300 hover:border-stone-900 bg-white flex items-center justify-center text-stone-500 hover:text-stone-900 transition-colors cursor-pointer" title="Registrar Entregas">
                                                    <span class="text-xs font-bold">+</span>
                                                </button>
                                            <?php endif; ?>

                                            <!-- Notepad de Ajuste Rápido -->
                                            <button onclick="openEditRemessa(<?php echo htmlspecialchars(json_encode($remessa), ENT_QUOTES, 'UTF-8'); ?>)" class="p-1.5 text-stone-400 hover:text-stone-850 hover:bg-stone-50 rounded-lg transition-colors cursor-pointer" title="Editar lote completo">
                                                <i data-lucide="pencil" class="w-4.5 h-4.5"></i>
                                            </button>

                                            <!-- Lixeira de Exclusão -->
                                            <form method="POST" onsubmit="return confirm('Deseja realmente excluir este lote de remessa?');" class="inline">
                                                <input type="hidden" name="action" value="delete_remessa">
                                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($remessa['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="collection" value="<?php echo htmlspecialchars($remessa['__collection'] ?? 'remessas', ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="p-1.5 text-stone-300 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors cursor-pointer" title="Deletar Lote">
                                                    <i data-lucide="trash-2" class="w-4.5 h-4.5"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>

                                    <!-- Linha Intermediária: Tamanho & Financeiro Rápido -->
                                    <div class="flex items-center justify-between mb-4">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-semibold text-stone-400 uppercase">Tamanho:</span>
                                            <span class="w-6.5 h-6.5 rounded-full border border-stone-300 flex items-center justify-center text-xs font-bold text-stone-800 bg-stone-50">
                                                <?php echo htmlspecialchars($remessa['tamanho']); ?>
                                            </span>
                                            
                                            <?php if (!empty($remessa['loja_nome'])): ?>
                                                <span class="inline-flex items-center gap-1 bg-stone-100 text-stone-700 px-2 py-0.5 rounded-md text-[10px] font-medium border border-stone-200/50">
                                                    <i data-lucide="store" class="w-2.5 h-2.5"></i>
                                                    <?php echo htmlspecialchars($remessa['loja_nome']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Resumo Financeiro no Card -->
                                        <div class="text-right">
                                            <?php 
                                                $totalLote = $remessa['quantidade'] * $remessa['preco_unitario'];
                                                $recebido = $remessa['valor_recebido'] ?? 0;
                                                $pendente = max(0, $totalLote - $recebido);
                                            ?>
                                            <div class="text-[10px] font-bold text-emerald-600 uppercase leading-none">Recebido: <?php echo formatReal($recebido); ?></div>
                                            <?php if ($pendente > 0): ?>
                                                <div class="text-[10px] font-bold text-rose-500 uppercase mt-0.5">Falta: <?php echo formatReal($pendente); ?></div>
                                            <?php else: ?>
                                                <div class="text-[10px] font-bold text-emerald-500 uppercase mt-0.5 flex items-center justify-end gap-1">
                                                    <i data-lucide="check" class="w-2.5 h-2.5"></i> Pago
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Informações Adicionais (VÊ MAIS escondido por padrão) -->
                                    <div id="details-<?php echo $remessa['id']; ?>" class="hidden bg-stone-50/70 p-3 rounded-xl border border-stone-150 text-xs text-stone-500 mb-4 space-y-1.5">
                                        <div class="flex justify-between">
                                            <span>Preço Unitário da Peça:</span>
                                            <span class="font-bold text-stone-800"><?php echo formatReal($remessa['preco_unitario']); ?></span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span>Valor Total do Lote:</span>
                                            <span class="font-bold text-stone-800"><?php echo formatReal($totalLote); ?></span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span>Data de Lançamento:</span>
                                            <span class="font-semibold text-stone-700"><?php echo formatDate($remessa['data_cadastro']); ?></span>
                                        </div>
                                    </div>

                                    <!-- Status Text -->
                                    <div class="flex justify-between text-xs text-stone-500 font-medium mb-1.5">
                                        <span>
                                            <?php if (!empty($remessa['data_ultima_entrega'])): ?>
                                                <span class="text-emerald-700 flex items-center gap-1">
                                                    <i data-lucide="check" class="w-3.5 h-3.5"></i>
                                                    Última entrega: <?php echo formatDate($remessa['data_ultima_entrega']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-stone-400 italic">Nenhuma entrega registrada</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="font-bold text-stone-850">
                                            <?php echo $remessa['qtd_entregue']; ?> de <?php echo $remessa['quantidade']; ?> Entregues
                                        </span>
                                    </div>
                                </div>

                                <!-- Barra de progresso visual de entregas -->
                                <div class="w-full bg-stone-100 rounded-full h-2 overflow-hidden border border-stone-200/50">
                                    <div class="h-full rounded-full transition-all duration-500 <?php echo $isConcluido ? 'bg-emerald-500' : 'bg-stone-900'; ?>" style="width: <?php echo $percentEntregue; ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Rodapé Financeiro e Operacional Consolidado -->
            <section class="bg-stone-900 border border-stone-950 rounded-3xl p-6 text-white grid grid-cols-1 md:grid-cols-3 gap-6 shadow-md">
                <!-- Total das Remessas -->
                <div class="flex items-center gap-4 border-b md:border-b-0 md:border-r border-stone-800 pb-4 md:pb-0 pr-4">
                    <div class="w-10 h-10 bg-stone-800 text-stone-400 rounded-xl flex items-center justify-center">
                        <i data-lucide="circle-dollar-sign" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <span class="text-[9px] text-stone-400 tracking-wider uppercase font-bold">Valor Total Bruto</span>
                        <h3 class="text-xl font-serif font-bold text-white tracking-tight"><?php echo formatReal($statsRemessas['valor_total']); ?></h3>
                    </div>
                </div>

                <!-- Total Recebido -->
                <div class="flex items-center gap-4 border-b md:border-b-0 md:border-r border-stone-800 pb-4 md:pb-0 pr-4">
                    <div class="w-10 h-10 bg-stone-800 text-emerald-500 rounded-xl flex items-center justify-center">
                        <i data-lucide="wallet" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <span class="text-[9px] text-stone-400 tracking-wider uppercase font-bold">Total Recebido</span>
                        <h3 class="text-xl font-serif font-bold text-emerald-400 tracking-tight"><?php echo formatReal($statsRemessas['valor_recebido']); ?></h3>
                    </div>
                </div>

                <!-- Total Pendente -->
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 bg-stone-800 text-rose-500 rounded-xl flex items-center justify-center">
                        <i data-lucide="hand-coins" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <span class="text-[9px] text-stone-400 tracking-wider uppercase font-bold">Saldo a Receber</span>
                        <h3 class="text-xl font-serif font-bold text-rose-400 tracking-tight"><?php echo formatReal($statsRemessas['valor_pendente']); ?></h3>
                    </div>
                </div>
            </section>

            <!-- Consolidado de Peças (Mini Badge abaixo do financeiro) -->
            <div class="mt-4 flex justify-center">
                <div class="bg-stone-200 text-stone-700 px-4 py-1.5 rounded-full text-[10px] font-bold uppercase tracking-widest flex items-center gap-2">
                    <i data-lucide="package" class="w-3 h-3"></i>
                    Entregas: <?php echo $statsRemessas['pecas_entregues']; ?> de <?php echo $statsRemessas['pecas_totais']; ?> peças concluídas
                </div>
            </div>

        <!-- ================================================================= -->
        <!-- ABA 2: DASHBOARD GERAL & FINANCEIRO (FIRESTORE DINÂMICO)          -->
        <!-- ================================================================= -->
        <?php elseif ($activeTab === 'dashboard'): ?>
            
            <section class="mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <h1 class="text-3xl font-serif font-bold text-stone-900 tracking-tight">Dashboard de Demanda Geral</h1>
                    <p class="text-stone-500 mt-1 text-sm">Faturamento consolidado puxado diretamente do Firebase Firestore.</p>
                </div>

                <!-- Filtro de Período Rápido -->
                <div class="flex items-center gap-2 bg-white p-1.5 rounded-2xl border border-stone-200 shadow-sm">
                    <button onclick="toggleModal('modalCalendar')" class="px-4 py-2 bg-stone-50 hover:bg-stone-100 rounded-xl text-xs font-bold text-stone-700 flex items-center gap-2 transition-all">
                        <i data-lucide="filter" class="w-3.5 h-3.5"></i>
                        Filtrar: <span class="text-stone-900"><?php echo $filtroGeral ? 'Visão Geral' : getMesNome($filtroMes) . '/' . $filtroAno; ?></span>
                    </button>
                </div>
            </section>

            <!-- Três Cards Financeiros Dinâmicos -->
            <section class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
                <div class="bg-white border border-stone-200 rounded-3xl p-6 shadow-sm relative overflow-hidden">
                    <div class="absolute -right-4 -top-4 text-stone-50 opacity-50">
                        <i data-lucide="trending-up" class="w-24 h-24"></i>
                    </div>
                    <div class="flex justify-between items-start relative z-10">
                        <div class="p-3 bg-stone-100 text-stone-900 rounded-2xl">
                            <i data-lucide="trending-up" class="w-6 h-6"></i>
                        </div>
                        <span class="text-[10px] font-bold text-stone-400 bg-stone-50 px-2.5 py-1 rounded-lg border border-stone-150 uppercase tracking-widest"><?php echo $filtroGeral ? 'Histórico Total' : 'Este Mês'; ?></span>
                    </div>
                    <p class="text-stone-400 font-medium text-xs mt-4 uppercase tracking-wider relative z-10">Faturamento Bruto</p>
                    <h3 class="text-3xl font-bold text-stone-900 font-serif tracking-tight mt-1 relative z-10">
                        <?php echo formatReal($statsRemessas['valor_total']); ?>
                    </h3>
                </div>

                <div class="bg-white border border-stone-200 rounded-3xl p-6 shadow-sm relative overflow-hidden">
                    <div class="absolute -right-4 -top-4 text-emerald-50 opacity-50">
                        <i data-lucide="wallet" class="w-24 h-24"></i>
                    </div>
                    <div class="flex justify-between items-start relative z-10">
                        <div class="p-3 bg-emerald-50 text-emerald-600 rounded-2xl">
                            <i data-lucide="wallet" class="w-6 h-6"></i>
                        </div>
                        <span class="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-100 uppercase tracking-widest">Dinheiro em Caixa</span>
                    </div>
                    <p class="text-stone-400 font-medium text-xs mt-4 uppercase tracking-wider relative z-10">Total Recebido</p>
                    <h3 class="text-3xl font-bold text-stone-900 font-serif tracking-tight mt-1 relative z-10">
                        <?php echo formatReal($statsRemessas['valor_recebido']); ?>
                    </h3>
                </div>

                <div class="bg-white border border-stone-200 rounded-3xl p-6 shadow-sm relative overflow-hidden">
                    <div class="absolute -right-4 -top-4 text-rose-50 opacity-50">
                        <i data-lucide="hand-coins" class="w-24 h-24"></i>
                    </div>
                    <div class="flex justify-between items-start relative z-10">
                        <div class="p-3 bg-rose-50 text-rose-600 rounded-2xl">
                            <i data-lucide="hand-coins" class="w-6 h-6"></i>
                        </div>
                        <span class="text-[10px] font-bold text-rose-700 bg-rose-50 px-2.5 py-1 rounded-lg border border-rose-100 uppercase tracking-widest">A Receber</span>
                    </div>
                    <p class="text-stone-400 font-medium text-xs mt-4 uppercase tracking-wider relative z-10">Total Pendente</p>
                    <h3 class="text-3xl font-bold text-stone-900 font-serif tracking-tight mt-1 relative z-10">
                        <?php echo formatReal($statsRemessas['valor_pendente']); ?>
                    </h3>
                </div>
            </section>

            <!-- Grade de Tabelas: Últimos Lotes & Medidas -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <!-- Últimos Lotes do Firebase (Ocupa 2 colunas) -->
                <div class="bg-white border border-stone-200 rounded-3xl p-6 shadow-sm lg:col-span-2">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center gap-2">
                            <i data-lucide="list-checks" class="w-5 h-5 text-stone-500"></i>
                            <h2 class="text-xl font-bold text-stone-900 font-serif">Detalhamento de Lotes</h2>
                        </div>
                        <span class="text-[10px] text-stone-400 font-bold uppercase tracking-widest"><?php echo count($remessas); ?> registros encontrados</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-stone-150">
                            <thead>
                                <tr>
                                    <th class="py-3 text-left text-xs font-semibold text-stone-400 uppercase tracking-wider">Peça / Data</th>
                                    <th class="py-3 text-center text-xs font-semibold text-stone-400 uppercase tracking-wider">Progresso</th>
                                    <th class="py-3 text-center text-xs font-semibold text-stone-400 uppercase tracking-wider">Status Pgto</th>
                                    <th class="py-3 text-right text-xs font-semibold text-stone-400 uppercase tracking-wider">Valor Lote</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100 bg-white">
                                <?php 
                                    // Pega os últimos 10 ou todos se for visão mensal
                                    $lotesExibicao = $filtroGeral ? array_slice($remessas, 0, 10) : $remessas;
                                    foreach ($lotesExibicao as $r): 
                                        $valLote = $r['quantidade'] * $r['preco_unitario'];
                                        $pago = $r['valor_recebido'] ?? 0;
                                        $pend = max(0, $valLote - $pago);
                                        $prog = $r['quantidade'] > 0 ? round(($r['qtd_entregue'] / $r['quantidade']) * 100) : 0;
                                ?>
                                    <tr class="hover:bg-stone-50/50 transition-colors">
                                        <td class="py-4">
                                            <div class="font-bold text-stone-900 text-sm capitalize"><?php echo htmlspecialchars($r['peca_servico']); ?> <span class="text-[10px] text-stone-400 font-medium">(<?php echo htmlspecialchars($r['tamanho']); ?>)</span></div>
                                            <div class="text-stone-400 text-[10px] flex items-center gap-1 mt-0.5">
                                                <i data-lucide="calendar" class="w-3 h-3"></i> 
                                                <span><?php echo formatDate($r['data_cadastro']); ?></span>
                                            </div>
                                        </td>
                                        <td class="py-4 text-center">
                                            <div class="flex flex-col items-center gap-1">
                                                <div class="text-[9px] font-bold text-stone-500 uppercase"><?php echo $r['qtd_entregue']; ?>/<?php echo $r['quantidade']; ?></div>
                                                <div class="w-20 bg-stone-100 h-1.5 rounded-full overflow-hidden border border-stone-200/50">
                                                    <div class="h-full bg-stone-900 rounded-full" style="width: <?php echo $prog; ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-4 text-center text-xs">
                                            <?php if ($pend <= 0): ?>
                                                <span class="px-2 py-0.5 rounded-full font-bold border bg-emerald-50 text-emerald-700 border-emerald-200 uppercase text-[9px]">Pago</span>
                                            <?php elseif ($pago > 0): ?>
                                                <span class="px-2 py-0.5 rounded-full font-bold border bg-amber-50 text-amber-700 border-amber-200 uppercase text-[9px]">Parcial</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded-full font-bold border bg-rose-50 text-rose-700 border-rose-200 uppercase text-[9px]">Pendente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-4 text-right">
                                            <div class="font-bold text-stone-900 text-sm"><?php echo formatReal($valLote); ?></div>
                                            <?php if ($pend > 0): ?>
                                                <div class="text-rose-500 text-[9px] font-medium">- <?php echo formatReal($pend); ?></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ($filtroGeral && count($remessas) > 10): ?>
                            <div class="mt-4 text-center">
                                <a href="?tab=remessas&mes=geral" class="text-[10px] font-bold text-atelier-brand hover:underline uppercase tracking-widest">Ver Todo o Histórico (<?php echo count($remessas); ?> itens)</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Medidas de Clientes (Contagem Baseada no Firestore) -->
                <div class="bg-white border border-stone-200 rounded-3xl p-6 shadow-sm relative overflow-hidden">
                    <div class="flex items-center gap-2 mb-6">
                        <i data-lucide="tape-measure" class="w-5 h-5 text-stone-500"></i>
                        <h2 class="text-xl font-bold text-stone-900 font-serif">Medidas</h2>
                    </div>

                    <div class="bg-stone-50 rounded-2xl p-8 text-center border border-dashed border-stone-300">
                        <div class="w-12 h-12 bg-white rounded-full flex items-center justify-center mx-auto mb-3 shadow-sm text-stone-300">
                             <i data-lucide="database" class="w-6 h-6"></i>
                        </div>
                        <h4 class="text-2xl font-bold text-stone-900"><?php echo count($remessas); ?></h4>
                        <p class="text-stone-400 text-[10px] uppercase font-bold tracking-widest mt-1">Lotes em Atendimento</p>
                        <p class="text-stone-400 text-[9px] mt-4 leading-relaxed">As medidas individuais de clientes estão sendo migradas para o Firebase. No momento, o controle de medidas é feito por lote.</p>
                    </div>
                    
                    <div class="mt-6 space-y-3">
                        <div class="flex justify-between text-xs border-b border-stone-100 pb-2">
                            <span class="text-stone-500">Total de Peças:</span>
                            <span class="font-bold text-stone-900"><?php echo $statsRemessas['pecas_totais']; ?></span>
                        </div>
                        <div class="flex justify-between text-xs border-b border-stone-100 pb-2">
                            <span class="text-stone-500">Já Entregues:</span>
                            <span class="font-bold text-emerald-600"><?php echo $statsRemessas['pecas_entregues']; ?></span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-stone-500">Taxa de Conclusão:</span>
                            <span class="font-bold text-stone-900"><?php echo $statsRemessas['pecas_totais'] > 0 ? round(($statsRemessas['pecas_entregues']/$statsRemessas['pecas_totais'])*100) : 0; ?>%</span>
                        </div>
                    </div>
                </div>

            </div>

        <?php endif; ?>

    </main>

    <!-- Footer Geral -->
    <footer class="mt-20 border-t border-stone-200 bg-white py-8 text-center text-xs text-stone-400 font-medium">
        <div class="max-w-7xl mx-auto px-4">
            <p>© 2026 Demanda. Sistema inteligente para costureiras autônomas e parceiras.</p>
            <p class="mt-1 text-[9px] text-stone-300">PHP 8.2 • Firebase Firestore • Render Cloud</p>
        </div>
    </footer>

    <!-- ================================================================= -->
    <!-- MODAIS DE DIÁLOGO E CONFIGURAÇÕES                                 -->
    <!-- ================================================================= -->

    <!-- 1. Modal: Cadastro de Nova Remessa -->
    <div id="modalAddRemessa" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-stone-950/40 transition-opacity" onclick="toggleModal('modalAddRemessa')"></div>
            <div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-md w-full border border-stone-200">
                <div class="bg-stone-900 text-white p-6 flex justify-between items-center">
                    <h3 class="font-serif text-xl font-bold flex items-center gap-2">
                        <i data-lucide="plus-circle" class="w-5 h-5 text-atelier-brand"></i>
                        Cadastro de Remessa
                    </h3>
                    <button onclick="toggleModal('modalAddRemessa')" class="text-stone-400 hover:text-white cursor-pointer transition-colors"><i data-lucide="x" class="w-6 h-6"></i></button>
                </div>
                <form method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="add_remessa">
                    <div>
                        <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-1 flex items-center gap-1.5"><i data-lucide="hanger" class="w-3.5 h-3.5 text-stone-400"></i> Peça ou Serviço *</label>
                        <input type="text" name="peca_servico" required placeholder="Ex: vestido, camisa, blazer" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-1 flex items-center gap-1.5"><i data-lucide="circle-dollar-sign" class="w-3.5 h-3.5 text-stone-400"></i> Preço Unitário (R$) *</label>
                            <input type="number" step="0.01" name="preco_unitario" required placeholder="0.00" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-1 flex items-center gap-1.5"><i data-lucide="users" class="w-3.5 h-3.5 text-stone-400"></i> Quantidade *</label>
                            <input type="number" name="quantidade" required placeholder="Ex: 10" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">Tamanho *</label>
                        <div class="flex flex-wrap gap-2 justify-center py-2 bg-stone-50 rounded-2xl border border-stone-200">
                            <?php foreach (['PP', 'P', 'M', 'G', 'GG', 'XG', 'outro'] as $t): ?>
                                <label class="flex flex-col items-center gap-1 px-3 py-2 border border-stone-200 rounded-xl cursor-pointer hover:bg-white active:scale-95 transition-all text-xs font-bold">
                                    <input type="radio" name="tamanho" value="<?php echo $t; ?>" <?php echo $t === 'M' ? 'checked' : ''; ?> class="accent-stone-900">
                                    <span><?php echo $t; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-stone-950 hover:bg-stone-850 text-white font-bold py-3.5 rounded-xl text-sm transition-all shadow-md active:scale-95 cursor-pointer">Salvar Remessa</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 2. Modal: Filtro de Calendário de Mês/Ano -->
    <div id="modalCalendar" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-stone-950/40 transition-opacity" onclick="toggleModal('modalCalendar')"></div>
            <div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-sm w-full border border-stone-200">
                <div class="bg-stone-950 text-white p-5 flex justify-between items-center">
                    <div>
                        <h4 class="text-xs uppercase font-bold text-stone-400 tracking-wider">Período de Referência</h4>
                        <span id="calendarYearLabel" class="text-2xl font-serif font-bold text-white"><?php echo $filtroAno; ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button onclick="adjustFilterYear(-1)" class="p-1 bg-stone-800 hover:bg-stone-700 text-white rounded-lg"><i data-lucide="chevron-up" class="w-4 h-4"></i></button>
                        <button onclick="adjustFilterYear(1)" class="p-1 bg-stone-800 hover:bg-stone-700 text-white rounded-lg"><i data-lucide="chevron-down" class="w-4 h-4"></i></button>
                    </div>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-3 gap-3 mb-6">
                        <?php 
                        $mesesConfig = ['01' => 'Jan', '02' => 'Fev', '03' => 'Mar', '04' => 'Abr', '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago', '09' => 'Set', '10' => 'Out', '11' => 'Nov', '12' => 'Dez'];
                        foreach ($mesesConfig as $num => $sigla): 
                            $isSelected = $filtroMes === $num;
                        ?>
                            <button onclick="setFilterPeriod('<?php echo $num; ?>')" class="py-3 px-2 rounded-2xl text-sm font-bold border transition-all active:scale-95 cursor-pointer <?php echo $isSelected ? 'bg-blue-500 border-blue-600 text-white shadow-md' : 'bg-stone-50 border-stone-200 text-stone-700 hover:bg-stone-100'; ?>"><?php echo $sigla; ?></button>
                        <?php endforeach; ?>
                    </div>
                    <button onclick="setFilterPeriod('geral')" class="w-full py-4 rounded-2xl text-xs font-bold border transition-all active:scale-95 cursor-pointer flex items-center justify-center gap-2 <?php echo $filtroGeral ? 'bg-stone-900 border-stone-950 text-white shadow-md' : 'bg-white border-stone-200 text-stone-600 hover:bg-stone-50'; ?>"><i data-lucide="globe" class="w-4 h-4"></i> VER TODO O HISTÓRICO (VISÃO GERAL)</button>
                    <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-stone-150">
                        <button onclick="toggleModal('modalCalendar')" class="px-4 py-2 border border-stone-300 rounded-xl text-xs font-bold text-stone-600 hover:bg-stone-50 cursor-pointer">CANCEL</button>
                        <button onclick="toggleModal('modalCalendar')" class="px-4 py-2 bg-stone-900 text-white rounded-xl text-xs font-bold hover:bg-stone-800 cursor-pointer">OK</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Modal: Trocar Perfil / Login -->
    <div id="modalLogin" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-stone-950/40 transition-opacity" onclick="toggleModal('modalLogin')"></div>
            <div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-md w-full border border-stone-200">
                <div class="bg-stone-100 py-8 text-center border-b border-stone-200">
                    <div class="w-20 h-20 bg-stone-950 text-atelier-brand rounded-full flex items-center justify-center mx-auto shadow-inner mb-3"><i data-lucide="printer" class="w-10 h-10"></i></div>
                    <h3 class="font-serif text-xl font-bold text-stone-900">Trocar Perfil / Cadastro</h3>
                    <p class="text-stone-400 text-xs">Costureira logada: <?php echo htmlspecialchars($_SESSION['usuario_nome']); ?></p>
                </div>
                <div class="flex border-b border-stone-150 bg-stone-50/50">
                    <button onclick="switchLoginTab('tabLoginBox')" id="btnTabLogin" class="flex-1 py-3 text-xs font-bold border-b-2 border-stone-900 text-stone-900 uppercase">Login</button>
                    <button onclick="switchLoginTab('tabRegisterBox')" id="btnTabRegister" class="flex-1 py-3 text-xs font-bold border-b-2 border-transparent text-stone-400 uppercase">Criar Perfil</button>
                </div>
                <form method="POST" id="tabLoginBox" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="login">
                    <div>
                        <label class="block text-xs font-bold text-stone-400 uppercase mb-1">E-mail de Acesso</label>
                        <input type="email" name="email" required placeholder="ex: maiara@demanda.com" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-stone-400 uppercase mb-1">Senha</label>
                        <input type="password" name="senha" required placeholder="Digite a senha" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                    </div>
                    <button type="submit" class="w-full bg-stone-950 hover:bg-stone-850 text-white font-bold py-3.5 rounded-xl text-sm transition-all shadow-md active:scale-95 flex items-center justify-center gap-2 cursor-pointer"><i data-lucide="check" class="w-4 h-4 text-emerald-400"></i> Acessar Perfil</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 4. Modal: Editar Lote de Remessa -->
    <div id="modalEditRemessa" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-stone-950/40 transition-opacity" onclick="toggleModal('modalEditRemessa')"></div>
            <div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-md w-full border border-stone-200">
                <div class="bg-stone-900 text-white p-6 flex justify-between items-center">
                    <h3 class="font-serif text-xl font-bold flex items-center gap-2">
                        <i data-lucide="pencil-line" class="w-5 h-5 text-atelier-brand"></i>
                        Editar Remessa
                    </h3>
                    <button onclick="toggleModal('modalEditRemessa')" class="text-stone-400 hover:text-white cursor-pointer transition-colors"><i data-lucide="x" class="w-6 h-6"></i></button>
                </div>
                <form method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="edit_remessa">
                    <input type="hidden" name="id" id="editRemessaId">
                    <input type="hidden" name="collection" id="editRemessaCollection">
                    <div>
                        <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-1">Peça ou Serviço</label>
                        <input type="text" name="peca_servico" id="editRemessaPeca" required class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-1">Preço Unitário</label>
                            <input type="number" step="0.01" name="preco_unitario" id="editRemessaPreco" required class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-1">Quantidade</label>
                            <input type="number" name="quantidade" id="editRemessaQtd" required class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">Tamanho</label>
                        <div class="flex flex-wrap gap-2 justify-center py-2 bg-stone-50 rounded-2xl border border-stone-200">
                            <?php foreach (['PP', 'P', 'M', 'G', 'GG', 'XG', 'outro'] as $t): ?>
                                <label class="flex flex-col items-center gap-1 px-3 py-2 border border-stone-200 rounded-xl cursor-pointer hover:bg-white active:scale-95 transition-all text-xs font-bold">
                                    <input type="radio" name="tamanho" value="<?php echo $t; ?>" id="editRemessaTamanho_<?php echo $t; ?>" class="accent-stone-900">
                                    <span><?php echo $t; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-stone-950 hover:bg-stone-850 text-white font-bold py-3.5 rounded-xl text-sm transition-all shadow-md active:scale-95 cursor-pointer">Salvar Alterações</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 5. Modal: Registrar Entregas -->
    <div id="modalUpdateQtd" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-stone-950/40 transition-opacity" onclick="toggleModal('modalUpdateQtd')"></div>
            <div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-sm w-full border border-stone-200">
                <div class="bg-stone-900 text-white p-5 flex justify-between items-center">
                    <h3 class="font-serif text-lg font-bold flex items-center gap-2"><i data-lucide="plus-circle" class="w-4.5 h-4.5 text-atelier-brand"></i> Registrar Entregas</h3>
                    <button onclick="toggleModal('modalUpdateQtd')" class="text-stone-400 hover:text-white cursor-pointer transition-colors"><i data-lucide="x" class="w-5 h-5"></i></button>
                </div>
                <form method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="update_entrega">
                    <input type="hidden" name="id" id="updateQtdId">
                    <input type="hidden" name="collection" id="updateQtdCollection">
                    <input type="hidden" name="qtd_atual" id="updateQtdAtualHidden">
                    <input type="hidden" name="valor_recebido_atual" id="updateValorRecebidoAtualHidden">
                    <p class="text-stone-500 text-xs">
                        Você já entregou <span id="updateQtdAtualLabel" class="font-bold text-stone-900">0</span> de <span id="updateQtdMaxLabel" class="font-bold text-stone-900">0</span> peças.
                        <br>Faltam: <span id="updateQtdFaltaLabel" class="font-bold text-rose-600">0</span> peças.
                    </p>
                    <div>
                        <label class="block text-xs font-bold text-stone-400 uppercase mb-1">Quantas peças entregar agora?</label>
                        <div class="flex items-center gap-3">
                            <input type="number" name="qtd_adicionar" id="updateQtdAdicionar" required value="1" class="flex-grow bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                            <button type="button" onclick="setQtdRestante()" class="bg-stone-100 hover:bg-stone-200 text-stone-800 border border-stone-300 px-3 py-3 rounded-xl text-xs font-bold transition-colors cursor-pointer">Restante</button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-stone-400 uppercase mb-1">Valor recebido agora (R$)</label>
                        <input type="number" step="0.01" name="valor_recebido_agora" id="updateValorRecebidoAgora" placeholder="0,00" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                        <p class="text-[10px] text-stone-400 mt-1">Recebido: <span id="updateValorRecebidoLabel" class="font-bold">R$ 0,00</span> / Falta: <span id="updateValorPendenteLabel" class="font-bold text-rose-500">R$ 0,00</span></p>
                    </div>
                    <button type="submit" class="w-full bg-stone-950 hover:bg-stone-850 text-white font-bold py-3.5 rounded-xl text-sm transition-all shadow-md active:scale-95 cursor-pointer">Confirmar Entrega</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 6. Modal: Registrar Pagamento Apenas -->
    <div id="modalUpdatePagamento" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-stone-950/40 transition-opacity" onclick="toggleModal('modalUpdatePagamento')"></div>
            <div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-sm w-full border border-stone-200">
                <div class="bg-emerald-900 text-white p-5 flex justify-between items-center">
                    <h3 class="font-serif text-lg font-bold flex items-center gap-2"><i data-lucide="banknote" class="w-4.5 h-4.5 text-emerald-400"></i> Registrar Pagamento</h3>
                    <button onclick="toggleModal('modalUpdatePagamento')" class="text-stone-300 hover:text-white cursor-pointer transition-colors"><i data-lucide="x" class="w-5 h-5"></i></button>
                </div>
                <form method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="update_pagamento">
                    <input type="hidden" name="id" id="updatePagId">
                    <input type="hidden" name="collection" id="updatePagCollection">
                    <input type="hidden" name="valor_recebido_atual" id="updatePagAtualHidden">
                    <div>
                        <label class="block text-xs font-bold text-stone-400 uppercase mb-1">Valor recebido (R$)</label>
                        <input type="number" step="0.01" name="valor_recebido_agora" required placeholder="0,00" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                        <p class="text-[10px] text-stone-400 mt-1">Já recebido: <span id="updatePagLabel" class="font-bold text-stone-600">R$ 0,00</span></p>
                    </div>
                    <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3.5 rounded-xl text-sm transition-all shadow-md active:scale-95 cursor-pointer">Salvar Pagamento</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Script de Modais e Interações Client-Side -->
    <script>
        function toggleModal(id) {
            const modal = document.getElementById(id);
            if (modal.classList.contains('hidden')) { modal.classList.remove('hidden'); } else { modal.classList.add('hidden'); }
        }
        function toggleDetails(id) {
            const div = document.getElementById(`details-${id}`);
            if (div.classList.contains('hidden')) { div.classList.remove('hidden'); } else { div.classList.add('hidden'); }
        }
        let maxLoteAtual = 0;
        let entreguesAtualmente = 0;
        let valorRecebidoAtualmente = 0;
        function openUpdateQtd(id, currentQtd, maxQtd, collection, valorRecebido, precoUnitario) {
            maxLoteAtual = maxQtd;
            entreguesAtualmente = currentQtd;
            valorRecebidoAtualmente = parseFloat(valorRecebido || 0);
            const preco = parseFloat(precoUnitario || 0);
            const valorTotalLote = maxQtd * preco;
            const valorPendente = Math.max(0, valorTotalLote - valorRecebidoAtualmente);
            document.getElementById('updateQtdId').value = id;
            document.getElementById('updateQtdCollection').value = collection || 'remessas';
            document.getElementById('updateQtdAtualHidden').value = currentQtd;
            document.getElementById('updateValorRecebidoAtualHidden').value = valorRecebidoAtualmente;
            document.getElementById('updateQtdAtualLabel').innerText = currentQtd;
            document.getElementById('updateQtdMaxLabel').innerText = maxQtd;
            const faltaPecas = maxQtd - currentQtd;
            document.getElementById('updateQtdFaltaLabel').innerText = faltaPecas;
            document.getElementById('updateValorRecebidoLabel').innerText = 'R$ ' + valorRecebidoAtualmente.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('updateValorPendenteLabel').innerText = 'R$ ' + valorPendente.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('updateQtdAdicionar').value = faltaPecas > 0 ? 1 : 0;
            document.getElementById('updateValorRecebidoAgora').value = ''; 
            toggleModal('modalUpdateQtd');
        }
        function openUpdatePagamento(id, valorRecebido, collection, maxQtd, precoUnitario) {
            const val = parseFloat(valorRecebido || 0);
            const preco = parseFloat(precoUnitario || 0);
            const total = parseInt(maxQtd || 0) * preco;
            const pendente = Math.max(0, total - val);
            document.getElementById('updatePagId').value = id;
            document.getElementById('updatePagCollection').value = collection || 'remessas';
            document.getElementById('updatePagAtualHidden').value = val;
            document.getElementById('updatePagLabel').innerText = 'R$ ' + val.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            let pendenteEl = document.getElementById('updatePagPendenteLabel');
            if (!pendenteEl) {
                const p = document.createElement('p');
                p.className = 'text-[10px] text-stone-400 mt-0.5';
                p.innerHTML = 'Falta: <span id="updatePagPendenteLabel" class="font-bold text-rose-500">R$ 0,00</span> (Total: ' + formatReal(total) + ')';
                document.getElementById('updatePagLabel').parentNode.appendChild(p);
                pendenteEl = document.getElementById('updatePagPendenteLabel');
            }
            pendenteEl.innerText = 'R$ ' + pendente.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            toggleModal('modalUpdatePagamento');
        }
        function setQtdRestante() {
            const falta = maxLoteAtual - entreguesAtualmente;
            document.getElementById('updateQtdAdicionar').value = falta;
        }
        function openEditRemessa(remessa) {
            document.getElementById('editRemessaId').value = remessa.id;
            document.getElementById('editRemessaCollection').value = remessa.__collection || 'remessas';
            document.getElementById('editRemessaPeca').value = remessa.peca_servico;
            document.getElementById('editRemessaPreco').value = remessa.preco_unitario;
            document.getElementById('editRemessaQtd').value = remessa.quantidade;
            const tamanho = remessa.tamanho || 'outro';
            const radio = document.getElementById('editRemessaTamanho_' + tamanho);
            if (radio) radio.checked = true;
            toggleModal('modalEditRemessa');
        }
        function switchLoginTab(boxId) {
            const tabLogin = document.getElementById('tabLoginBox');
            const tabRegister = document.getElementById('tabRegisterBox');
            const btnLogin = document.getElementById('btnTabLogin');
            const btnRegister = document.getElementById('btnTabRegister');
            if (boxId === 'tabLoginBox') {
                tabLogin.classList.remove('hidden'); tabRegister.classList.add('hidden');
                btnLogin.classList.add('border-stone-900', 'text-stone-900'); btnLogin.classList.remove('border-transparent', 'text-stone-400');
                btnRegister.classList.remove('border-stone-900', 'text-stone-900'); btnRegister.classList.add('border-transparent', 'text-stone-400');
            } else {
                tabRegister.classList.remove('hidden'); tabLogin.classList.add('hidden');
                btnRegister.classList.add('border-stone-900', 'text-stone-900'); btnRegister.classList.remove('border-transparent', 'text-stone-400');
                btnLogin.classList.remove('border-stone-900', 'text-stone-900'); btnLogin.classList.add('border-transparent', 'text-stone-400');
            }
        }
        let activeFilterYear = <?php echo $filtroAno; ?>;
        function adjustFilterYear(direction) {
            activeFilterYear += direction;
            document.getElementById('calendarYearLabel').innerText = activeFilterYear;
        }
        function setFilterPeriod(monthStr) {
            window.location.href = `?tab=<?php echo $activeTab; ?>&mes=${monthStr}&ano=${activeFilterYear}`;
        }
        lucide.createIcons();
    </script>
</body>
</html>
