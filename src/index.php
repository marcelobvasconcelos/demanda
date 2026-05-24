<?php
/**
 * Demanda - Sistema de Gestão de Costura e Finanças (Ateliê)
 * Interface Principal com Duas Abas: Dashboard Geral & Remessas do Mês
 */

session_start();

require __DIR__ . '/firebase_helper.php';

$firestoreCredentialsPath = getFirestoreCredentialsPath();
$firestoreEnabled = false;
$firestoreStatusMessage = '';
$serviceAccount = null;

if ($firestoreCredentialsPath && function_exists('curl_init') && function_exists('openssl_sign')) {
    try {
        $serviceAccount = firestore_load_service_account($firestoreCredentialsPath);
        $firestoreEnabled = true;
    } catch (Throwable $e) {
        $firestoreStatusMessage = 'Firestore offline: ' . $e->getMessage();
    }
} elseif ($firestoreCredentialsPath) {
    $firestoreStatusMessage = 'Firestore não disponível: lib cURL ou OpenSSL ausente.';
} else {
    $firestoreStatusMessage = 'Arquivo firebase_credenciais.json não encontrado.';
}

// A aplicação agora usa diretamente o Firebase / Firestore.
$dbConnected = false;
$connectionError = '';
$pdo = null;

// Lógica de Abas
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'remessas';

// Filtro de Mês/Ano
$filtroMes = isset($_GET['mes']) ? $_GET['mes'] : (isset($_SESSION['filtro_mes']) ? $_SESSION['filtro_mes'] : '05');
$filtroAno = isset($_GET['ano']) ? $_GET['ano'] : (isset($_SESSION['filtro_ano']) ? $_SESSION['filtro_ano'] : '2026');

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
        $dataCadastro = date("Y-m-d");

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
        $qtdEntregue = intval($_POST['qtd_entregue'] ?? 0);
        $usuarioUid = $_SESSION['firebase_localId'] ?? firestore_get_user_uid_by_email($_SESSION['usuario_email'] ?? '');

        if ($docId === '' || $collection === '') {
            $msgError = 'Documento de remessa inválido.';
        } elseif (!firestore_collection_belongs_to_user($collection, $usuarioUid ?? '')) {
            $msgError = 'Esta remessa nÃ£o pertence ao usuÃ¡rio logado.';
        } else {
            try {
                $dataUltimaEntrega = $qtdEntregue > 0 ? date('Y-m-d') : null;
                firestore_update_remessa_entrega($docId, $qtdEntregue, $dataUltimaEntrega, $collection);
                $msgSuccess = 'Status de entrega atualizado!';
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
            $msgError = 'Esta remessa nÃ£o pertence ao usuÃ¡rio logado.';
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
        $msgSuccess = 'Atualização do Firestore concluída. A página foi recarregada com os dados mais recentes.';
    }
}


if (!isset($_SESSION['usuario_email'])) {
    include __DIR__ . '/login_screen.php';
    exit;
}

// Carregar Dados das Lojas para selects (se o MySQL estiver disponível)
$lojas = [];
if ($dbConnected && $pdo) {
    $lojas = $pdo->query("SELECT id, nome FROM lojas ORDER BY nome ASC")->fetchAll();
}

// ----------------------------------------------------
// ABA 1: Buscar Dados do Dashboard Geral
// ----------------------------------------------------
$generalStats = ['faturamento_total' => 0, 'total_recebido' => 0, 'total_pendente' => 0];
$ultimosServicos = [];
$ultimasMedidas = [];

if ($dbConnected && $pdo && $activeTab === 'dashboard') {
    $stmtStats = $pdo->query("
        SELECT 
            COALESCE(SUM(valor_total), 0) as faturamento_total,
            COALESCE(SUM(valor_pago), 0) as total_recebido,
            COALESCE(SUM(valor_total) - SUM(valor_pago), 0) as total_pendente
        FROM servicos
    ");
    $generalStats = $stmtStats->fetch();

    $stmtServicos = $pdo->query("
        SELECT s.*, c.nome as cliente_nome, l.nome as loja_nome
        FROM servicos s
        JOIN clientes c ON s.cliente_id = c.id
        LEFT JOIN lojas l ON s.loja_id = l.id
        ORDER BY s.data_entrada DESC, s.id DESC LIMIT 5
    ");
    $ultimosServicos = $stmtServicos->fetchAll();

    $stmtMedidas = $pdo->query("
        SELECT m.*, c.nome as cliente_nome
        FROM medidas_clientes m
        JOIN clientes c ON m.cliente_id = c.id
        ORDER BY m.data_medida DESC, m.id DESC LIMIT 4
    ");
    $ultimasMedidas = $stmtMedidas->fetchAll();
}

// ----------------------------------------------------
// ABA 2: Buscar Dados das Remessas do Mês
// ----------------------------------------------------
$remessas = [];
$statsRemessas = ['valor_total' => 0.0, 'pecas_totais' => 0, 'pecas_entregues' => 0];

if ($activeTab === 'remessas' && $firestoreEnabled) {
    $usuarioEmail = $_SESSION['usuario_email'] ?? '';
    if ($usuarioEmail !== '') {
        try {
            $startDate = sprintf('%s-%s-01', $filtroAno, str_pad($filtroMes, 2, '0', STR_PAD_LEFT));
            $endDate = sprintf('%s-%s-%s', $filtroAno, str_pad($filtroMes, 2, '0', STR_PAD_LEFT), date('t', strtotime($startDate)));
            $remessas = firestore_query_remessas($usuarioEmail, $startDate, $endDate);
        } catch (Throwable $e) {
            $msgError = 'Falha ao carregar remessas do Firestore: ' . $e->getMessage();
            $remessas = [];
        }
    }

    foreach ($remessas as $remessa) {
        $quantidade = max(0, intval($remessa['quantidade'] ?? 0));
        $qtdEntregue = max(0, min(intval($remessa['qtd_entregue'] ?? 0), $quantidade));
        $statsRemessas['valor_total'] += isset($remessa['total'])
            ? floatval($remessa['total'])
            : $quantidade * floatval($remessa['preco_unitario'] ?? 0);
        $statsRemessas['pecas_totais'] += $quantidade;
        $statsRemessas['pecas_entregues'] += $qtdEntregue;
    }
}

// Utilitários de Data e Nomes
function formatReal($val) {
    return 'R$ ' . number_format($val, 2, ',', '.');
}

function formatDate($dateStr) {
    if (!$dateStr) return '-';
    $date = new DateTime($dateStr);
    return $date->format('d/m/Y');
}

function getMesNome($mesNum) {
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
                    <?php if (!$dbConnected): ?>
                        <div class="flex items-center gap-1.5 bg-amber-50 text-amber-800 px-3 py-1.5 rounded-xl border border-amber-200 text-xs font-medium" title="<?php echo htmlspecialchars($connectionError); ?>">
                            <i data-lucide="database-backup" class="w-3.5 h-3.5 text-amber-600 animate-pulse"></i>
                            <span class="hidden sm:inline">Modo Simulação</span>
                        </div>
                    <?php else: ?>
                        <div class="flex items-center gap-1.5 bg-emerald-50 text-emerald-800 px-3 py-1.5 rounded-xl border border-emerald-100 text-xs font-medium">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            <span class="hidden sm:inline">MySQL Conectado</span>
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
                            <span class="block font-bold text-stone-900 text-sm capitalize leading-tight"><?php echo getMesNome($filtroMes); ?> de <?php echo $filtroAno; ?></span>
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
                                Nenhum lote registrado para o mês de <?php echo getMesNome($filtroMes); ?> de <?php echo $filtroAno; ?>. Use "Nova Remessa" para adicionar um registro.
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
                                $isConcluido = $remessa['qtd_entregue'] == $remessa['quantidade'];
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
                                            <!-- Checkbox/Círculo de Conclusão Rápida -->
                                            <?php if ($isConcluido): ?>
                                                <button class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center border border-emerald-200" title="Lote Completado!">
                                                    <i data-lucide="check" class="w-4 h-4 font-bold"></i>
                                                </button>
                                            <?php else: ?>
                                                <!-- Abre atualização de quantidade entregue -->
                                                <button onclick="openUpdateQtd(<?php echo json_encode($remessa['id']); ?>, <?php echo $remessa['qtd_entregue']; ?>, <?php echo $remessa['quantidade']; ?>, <?php echo json_encode($remessa['__collection'] ?? 'remessas'); ?>)" class="w-8 h-8 rounded-full border-2 border-stone-300 hover:border-stone-900 bg-white flex items-center justify-center text-stone-500 hover:text-stone-900 transition-colors cursor-pointer" title="Registrar Entregas">
                                                    <span class="text-xs font-bold">+</span>
                                                </button>
                                            <?php endif; ?>

                                            <!-- Notepad de Ajuste Rápido -->
                                            <button onclick="openUpdateQtd(<?php echo json_encode($remessa['id']); ?>, <?php echo $remessa['qtd_entregue']; ?>, <?php echo $remessa['quantidade']; ?>, <?php echo json_encode($remessa['__collection'] ?? 'remessas'); ?>)" class="p-1.5 text-stone-400 hover:text-stone-850 hover:bg-stone-50 rounded-lg transition-colors cursor-pointer" title="Atualizar entregas">
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

                                    <!-- Linha Intermediária: Tamanho -->
                                    <div class="flex items-center gap-2 mb-4">
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

                                    <!-- Informações Adicionais (VÊ MAIS escondido por padrão) -->
                                    <div id="details-<?php echo $remessa['id']; ?>" class="hidden bg-stone-50/70 p-3 rounded-xl border border-stone-150 text-xs text-stone-500 mb-4 space-y-1.5">
                                        <div class="flex justify-between">
                                            <span>Preço Unitário da Peça:</span>
                                            <span class="font-bold text-stone-800"><?php echo formatReal($remessa['preco_unitario']); ?></span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span>Valor Total do Lote:</span>
                                            <span class="font-bold text-stone-800"><?php echo formatReal($remessa['quantidade'] * $remessa['preco_unitario']); ?></span>
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
            <section class="bg-stone-900 border border-stone-950 rounded-3xl p-6 text-white grid grid-cols-1 md:grid-cols-2 gap-6 shadow-md">
                <!-- Total Faturado -->
                <div class="flex items-center gap-4 border-b md:border-b-0 md:border-r border-stone-800 pb-4 md:pb-0 pr-4">
                    <div class="w-12 h-12 bg-stone-800 text-atelier-brand rounded-2xl flex items-center justify-center">
                        <i data-lucide="circle-dollar-sign" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <span class="text-[10px] text-stone-400 tracking-wider uppercase font-bold">Valor Total das Remessas</span>
                        <h3 class="text-3xl font-serif font-bold text-white tracking-tight mt-0.5"><?php echo formatReal($statsRemessas['valor_total']); ?></h3>
                    </div>
                </div>

                <!-- Peças Entregues / Consolidado -->
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-stone-800 text-atelier-brand rounded-2xl flex items-center justify-center">
                        <i data-lucide="package" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <span class="text-[10px] text-stone-400 tracking-wider uppercase font-bold">Consolidado de Entregas</span>
                        <h3 class="text-3xl font-serif font-bold text-white tracking-tight mt-0.5">
                            <?php echo $statsRemessas['pecas_entregues']; ?> <span class="text-sm font-normal text-stone-400">de <?php echo $statsRemessas['pecas_totais']; ?> peças</span>
                        </h3>
                    </div>
                </div>
            </section>

        <!-- ================================================================= -->
        <!-- ABA 2: DASHBOARD GERAL & FINANCEIRO (BÁSICO APROVADO)            -->
        <!-- ================================================================= -->
        <?php elseif ($activeTab === 'dashboard'): ?>
            
            <section class="mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <h1 class="text-3xl font-serif font-bold text-stone-900 tracking-tight">Dashboard de Demanda Geral</h1>
                    <p class="text-stone-500 mt-1 text-sm">Faturamento consolidado geral de serviços particulares e de lojas parcerias.</p>
                </div>
            </section>

            <!-- Três Cards Financeiros Principais -->
            <section class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
                <div class="bg-white border border-stone-200 rounded-3xl p-6 shadow-sm">
                    <div class="flex justify-between items-start">
                        <div class="p-3 bg-stone-100 text-stone-900 rounded-2xl">
                            <i data-lucide="trending-up" class="w-6 h-6"></i>
                        </div>
                        <span class="text-xs font-semibold text-stone-400 bg-stone-50 px-2.5 py-1 rounded-lg border border-stone-150">Geral</span>
                    </div>
                    <p class="text-stone-400 font-medium text-xs mt-4 uppercase tracking-wider">Faturamento Geral</p>
                    <h3 class="text-3xl font-bold text-stone-900 font-serif tracking-tight mt-1">
                        <?php echo formatReal($generalStats['faturamento_total']); ?>
                    </h3>
                </div>

                <div class="bg-white border border-stone-200 rounded-3xl p-6 shadow-sm">
                    <div class="flex justify-between items-start">
                        <div class="p-3 bg-emerald-50 text-emerald-600 rounded-2xl">
                            <i data-lucide="wallet" class="w-6 h-6"></i>
                        </div>
                        <span class="text-xs font-semibold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-100">Dinheiro em Caixa</span>
                    </div>
                    <p class="text-stone-400 font-medium text-xs mt-4 uppercase tracking-wider">Total Recebido</p>
                    <h3 class="text-3xl font-bold text-stone-900 font-serif tracking-tight mt-1">
                        <?php echo formatReal($generalStats['total_recebido']); ?>
                    </h3>
                </div>

                <div class="bg-white border border-stone-200 rounded-3xl p-6 shadow-sm">
                    <div class="flex justify-between items-start">
                        <div class="p-3 bg-amber-50 text-amber-600 rounded-2xl">
                            <i data-lucide="hand-coins" class="w-6 h-6"></i>
                        </div>
                        <span class="text-xs font-semibold text-amber-700 bg-amber-50 px-2.5 py-1 rounded-lg border border-amber-100">A Receber</span>
                    </div>
                    <p class="text-stone-400 font-medium text-xs mt-4 uppercase tracking-wider">Total Pendente</p>
                    <h3 class="text-3xl font-bold text-stone-900 font-serif tracking-tight mt-1">
                        <?php echo formatReal($generalStats['total_pendente']); ?>
                    </h3>
                </div>
            </section>

            <!-- Grade de Tabelas: Serviços & Medidas -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <!-- Serviços Gerais (Ocupa 2 colunas) -->
                <div class="bg-white border border-stone-200 rounded-3xl p-6 shadow-sm lg:col-span-2">
                    <div class="flex items-center gap-2 mb-6">
                        <i data-lucide="needle" class="w-5 h-5 text-stone-500"></i>
                        <h2 class="text-xl font-bold text-stone-900 font-serif">Últimos Serviços Gerais</h2>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-stone-150">
                            <thead>
                                <tr>
                                    <th class="py-3 text-left text-xs font-semibold text-stone-400 uppercase">Serviço/Cliente</th>
                                    <th class="py-3 text-center text-xs font-semibold text-stone-400 uppercase">Loja</th>
                                    <th class="py-3 text-center text-xs font-semibold text-stone-400 uppercase">Status</th>
                                    <th class="py-3 text-right text-xs font-semibold text-stone-400 uppercase">Valor</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100 bg-white">
                                <?php foreach ($ultimosServicos as $s): ?>
                                    <tr class="hover:bg-stone-50/50">
                                        <td class="py-4">
                                            <div class="font-medium text-stone-900 text-sm"><?php echo htmlspecialchars($s['descricao']); ?></div>
                                            <div class="text-stone-400 text-xs flex items-center gap-1.5 mt-0.5">
                                                <i data-lucide="user" class="w-3 h-3"></i> 
                                                <span><?php echo htmlspecialchars($s['cliente_nome']); ?></span>
                                            </div>
                                        </td>
                                        <td class="py-4 text-center text-xs text-stone-600 font-semibold">
                                            <?php echo $s['loja_nome'] ? htmlspecialchars($s['loja_nome']) : '<span class="text-stone-300 italic">Particular</span>'; ?>
                                        </td>
                                        <td class="py-4 text-center text-xs">
                                            <span class="px-2 py-0.5 rounded-full font-bold border 
                                                <?php echo $s['status_pagamento'] === 'pago' ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : ($s['status_pagamento'] === 'pago_parcial' ? 'bg-amber-50 text-amber-800 border-amber-200' : 'bg-rose-50 text-rose-800 border-rose-200'); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $s['status_pagamento'])); ?>
                                            </span>
                                        </td>
                                        <td class="py-4 text-right font-bold text-stone-900 text-sm">
                                            <?php echo formatReal($s['valor_total']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Últimas Medidas -->
                <div class="bg-white border border-stone-200 rounded-3xl p-6 shadow-sm">
                    <div class="flex items-center gap-2 mb-6">
                        <i data-lucide="tape-measure" class="w-5 h-5 text-stone-500"></i>
                        <h2 class="text-xl font-bold text-stone-900 font-serif">Medidas de Clientes</h2>
                    </div>

                    <div class="space-y-4">
                        <?php foreach ($ultimasMedidas as $m): ?>
                            <div class="p-4 bg-stone-50 rounded-2xl border border-stone-150">
                                <h4 class="font-bold text-stone-900 text-sm"><?php echo htmlspecialchars($m['cliente_nome']); ?></h4>
                                <span class="text-[9px] text-stone-400">Data: <?php echo formatDate($m['data_medida']); ?></span>
                                
                                <div class="grid grid-cols-4 gap-1 text-center bg-white p-2 rounded-lg border border-stone-100 mt-2 text-[10px] font-bold">
                                    <div><span class="block text-[8px] text-stone-400 uppercase font-medium">Busto</span><?php echo floatval($m['busto']); ?></div>
                                    <div><span class="block text-[8px] text-stone-400 uppercase font-medium">Cint</span><?php echo floatval($m['cintura']); ?></div>
                                    <div><span class="block text-[8px] text-stone-400 uppercase font-medium">Quad</span><?php echo floatval($m['quadril']); ?></div>
                                    <div><span class="block text-[8px] text-stone-400 uppercase font-medium">Comp</span><?php echo floatval($m['comprimento']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>

        <?php endif; ?>

    </main>

    <!-- Footer Geral -->
    <footer class="mt-20 border-t border-stone-200 bg-white py-8 text-center text-xs text-stone-400 font-medium">
        <div class="max-w-7xl mx-auto px-4">
            <p>© 2026 Demanda. Sistema inteligente para costureiras autônomas e parceiras.</p>
            <p class="mt-1 text-[9px] text-stone-300">PHP 8.2 • MySQL 8.0 • Docker Local</p>
        </div>
    </footer>

    <!-- ================================================================= -->
    <!-- MODAIS DE DIÁLOGO E CONFIGURAÇÕES                                 -->
    <!-- ================================================================= -->

    <!-- 1. Modal: Cadastro de Nova Remessa (Baseado na quinta tela) -->
    <div id="modalAddRemessa" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-stone-950/40 transition-opacity" onclick="toggleModal('modalAddRemessa')"></div>
            
            <div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-md w-full border border-stone-200">
                <!-- Header do Modal -->
                <div class="bg-stone-900 text-white p-6 flex justify-between items-center">
                    <h3 class="font-serif text-xl font-bold flex items-center gap-2">
                        <i data-lucide="plus-circle" class="w-5 h-5 text-atelier-brand"></i>
                        Cadastro de Remessa
                    </h3>
                    <button onclick="toggleModal('modalAddRemessa')" class="text-stone-400 hover:text-white cursor-pointer transition-colors">
                        <i data-lucide="x" class="w-6 h-6"></i>
                    </button>
                </div>

                <!-- Formulário -->
                <form method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="add_remessa">

                    <!-- Peça/Serviço -->
                    <div>
                        <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                            <i data-lucide="hanger" class="w-3.5 h-3.5 text-stone-400"></i> Peça ou Serviço *
                        </label>
                        <input type="text" name="peca_servico" required placeholder="Ex: vestido, camisa, blazer" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                    </div>

                    <!-- Preço Unitário & Quantidade -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                                <i data-lucide="circle-dollar-sign" class="w-3.5 h-3.5 text-stone-400"></i> Preço Unitário (R$) *
                            </label>
                            <input type="number" step="0.01" name="preco_unitario" required placeholder="0.00" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                                <i data-lucide="users" class="w-3.5 h-3.5 text-stone-400"></i> Quantidade *
                            </label>
                            <input type="number" name="quantidade" required placeholder="Ex: 10" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                        </div>
                    </div>

                    <!-- Tamanhos (Grade Baseada na Foto) -->
                    <div>
                        <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">Selecione o Tamanho *</label>
                        <div class="flex flex-wrap gap-2 justify-center py-2 bg-stone-50 rounded-2xl border border-stone-200">
                            <?php foreach (['PP', 'P', 'M', 'G', 'GG', 'XG', 'outro'] as $t): ?>
                                <label class="flex flex-col items-center gap-1 px-3 py-2 border border-stone-200 rounded-xl cursor-pointer hover:bg-white active:scale-95 transition-all text-xs font-bold">
                                    <input type="radio" name="tamanho" value="<?php echo $t; ?>" <?php echo $t === 'M' ? 'checked' : ''; ?> class="accent-stone-900">
                                    <span><?php echo $t; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Loja Associada (Opcional) -->
                    <div>
                        <label class="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-1 flex items-center gap-1.5">
                            <i data-lucide="store" class="w-3.5 h-3.5 text-stone-400"></i> Loja Parceira (Opcional)
                        </label>
                        <select name="loja_id" class="w-full bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                            <option value="">Particular / Direto com Cliente</option>
                            <?php foreach ($lojas as $l): ?>
                                <option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars($l['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Botão Salvar -->
                    <button type="submit" class="w-full bg-stone-950 hover:bg-stone-850 text-white font-bold py-3.5 rounded-xl text-sm transition-all shadow-md active:scale-95 cursor-pointer">
                        Salvar Remessa
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- 2. Modal: Filtro de Calendário de Mês/Ano (Baseado na primeira tela) -->
    <div id="modalCalendar" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-stone-950/40 transition-opacity" onclick="toggleModal('modalCalendar')"></div>
            
            <div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-sm w-full border border-stone-200">
                <!-- Header -->
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

                <!-- Grid de Meses (Inspirado no popup real) -->
                <div class="p-6">
                    <div class="grid grid-cols-3 gap-3">
                        <?php 
                        $mesesConfig = [
                            '01' => 'Jan', '02' => 'Fev', '03' => 'Mar', '04' => 'Abr',
                            '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago',
                            '09' => 'Set', '10' => 'Out', '11' => 'Nov', '12' => 'Dez'
                        ];
                        foreach ($mesesConfig as $num => $sigla): 
                            $isSelected = $filtroMes === $num;
                        ?>
                            <button onclick="setFilterPeriod('<?php echo $num; ?>')" class="py-3 px-2 rounded-2xl text-sm font-bold border transition-all active:scale-95 cursor-pointer 
                                <?php echo $isSelected ? 'bg-blue-500 border-blue-600 text-white shadow-md' : 'bg-stone-50 border-stone-200 text-stone-700 hover:bg-stone-100'; ?>">
                                <?php echo $sigla; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-stone-150">
                        <button onclick="toggleModal('modalCalendar')" class="px-4 py-2 border border-stone-300 rounded-xl text-xs font-bold text-stone-600 hover:bg-stone-50 cursor-pointer">CANCEL</button>
                        <button onclick="toggleModal('modalCalendar')" class="px-4 py-2 bg-stone-900 text-white rounded-xl text-xs font-bold hover:bg-stone-800 cursor-pointer">OK</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Modal: Trocar Perfil / Login (Baseado na segunda e terceira tela) -->
    <div id="modalLogin" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-stone-950/40 transition-opacity" onclick="toggleModal('modalLogin')"></div>
            
            <div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-md w-full border border-stone-200">
                <!-- Banner superior com máquina de costura -->
                <div class="bg-stone-100 py-8 text-center border-b border-stone-200">
                    <div class="w-20 h-20 bg-stone-950 text-atelier-brand rounded-full flex items-center justify-center mx-auto shadow-inner mb-3">
                        <i data-lucide="printer" class="w-10 h-10"></i>
                    </div>
                    <h3 class="font-serif text-xl font-bold text-stone-900">Trocar Perfil / Cadastro</h3>
                    <p class="text-stone-400 text-xs">Costureira logada: <?php echo htmlspecialchars($_SESSION['usuario_nome']); ?></p>
                </div>

                <!-- Tabs Login / Cadastro -->
                <div class="flex border-b border-stone-150 bg-stone-50/50">
                    <button onclick="switchLoginTab('tabLoginBox')" id="btnTabLogin" class="flex-1 py-3 text-xs font-bold border-b-2 border-stone-900 text-stone-900 uppercase">Login</button>
                    <button onclick="switchLoginTab('tabRegisterBox')" id="btnTabRegister" class="flex-1 py-3 text-xs font-bold border-b-2 border-transparent text-stone-400 uppercase">Criar Perfil</button>
                </div>

                <!-- Bloco de Login (Acesso Rápido) -->
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

                    <div class="pt-2 text-stone-400 text-[10px] flex justify-between items-center font-medium">
                        <span>Padrão: maiara@demanda.com (Senha: 123)</span>
                        <a href="#" class="text-blue-600 font-semibold hover:underline">Recuperar Senha</a>
                    </div>

                    <button type="submit" class="w-full bg-stone-950 hover:bg-stone-850 text-white font-bold py-3.5 rounded-xl text-sm transition-all shadow-md active:scale-95 flex items-center justify-center gap-2 cursor-pointer">
                        <i data-lucide="check" class="w-4 h-4 text-emerald-400"></i> Acessar Perfil
                    </button>
                </form>

            </div>
        </div>
    </div>

    <!-- 4. Modal: Atualizar Peças Entregues no Lote (Notepad Click) -->
    <div id="modalUpdateQtd" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-stone-950/40 transition-opacity" onclick="toggleModal('modalUpdateQtd')"></div>
            
            <div class="bg-white rounded-3xl overflow-hidden shadow-2xl z-10 max-w-sm w-full border border-stone-200">
                <div class="bg-stone-900 text-white p-5 flex justify-between items-center">
                    <h3 class="font-serif text-lg font-bold flex items-center gap-2">
                        <i data-lucide="pencil-line" class="w-4.5 h-4.5 text-atelier-brand"></i>
                        Registrar Entregas
                    </h3>
                    <button onclick="toggleModal('modalUpdateQtd')" class="text-stone-400 hover:text-white cursor-pointer"><i data-lucide="x" class="w-5 h-5"></i></button>
                </div>

                <form method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="update_entrega">
                    <input type="hidden" name="id" id="updateQtdId">
                    <input type="hidden" name="collection" id="updateQtdCollection">
                    
                    <p class="text-stone-500 text-xs">
                        Informe o total de peças já finalizadas e entregues para este lote.
                        (Máximo: <span id="updateQtdMaxLabel" class="font-bold text-stone-900">0</span>)
                    </p>

                    <div>
                        <label class="block text-xs font-bold text-stone-400 uppercase mb-1">Peças Entregues</label>
                        <div class="flex items-center gap-3">
                            <input type="number" name="qtd_entregue" id="updateQtdValue" required min="0" value="0" class="flex-grow bg-stone-50 border border-stone-300 rounded-xl p-3 text-sm focus:outline-none focus:border-stone-900">
                            <!-- Botão rápido para bater o total do lote -->
                            <button type="button" onclick="setTotalEntregasMax()" class="bg-stone-100 hover:bg-stone-200 text-stone-800 border border-stone-300 px-3 py-3 rounded-xl text-xs font-bold transition-colors cursor-pointer">
                                Total Lote
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-stone-950 hover:bg-stone-850 text-white font-bold py-3.5 rounded-xl text-sm transition-all shadow-md active:scale-95 cursor-pointer">
                        Enviar Alterações
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Script de Modais e Interações Client-Side -->
    <script>
        // Função geral de abre/fecha modal
        function toggleModal(id) {
            const modal = document.getElementById(id);
            if (modal.classList.contains('hidden')) {
                modal.classList.remove('hidden');
            } else {
                modal.classList.add('hidden');
            }
        }

        // VÊ MAIS - Esconde/Mostra dados extras da remessa
        function toggleDetails(id) {
            const div = document.getElementById(`details-${id}`);
            if (div.classList.contains('hidden')) {
                div.classList.remove('hidden');
            } else {
                div.classList.add('hidden');
            }
        }

        // Abre o modal de registrar entrega e define os limites
        let maxLoteAtual = 0;
        function openUpdateQtd(id, currentQtd, maxQtd, collection) {
            maxLoteAtual = maxQtd;
            document.getElementById('updateQtdId').value = id;
            document.getElementById('updateQtdCollection').value = collection || 'remessas';
            document.getElementById('updateQtdValue').value = currentQtd;
            document.getElementById('updateQtdValue').setAttribute('max', maxQtd);
            document.getElementById('updateQtdMaxLabel').innerText = maxQtd;
            toggleModal('modalUpdateQtd');
        }

        // Preenche o campo de entregas com a capacidade máxima do lote
        function setTotalEntregasMax() {
            document.getElementById('updateQtdValue').value = maxLoteAtual;
        }

        // Troca de abas no modal de Perfil (Login vs Cadastro)
        function switchLoginTab(boxId) {
            const tabLogin = document.getElementById('tabLoginBox');
            const tabRegister = document.getElementById('tabRegisterBox');
            const btnLogin = document.getElementById('btnTabLogin');
            const btnRegister = document.getElementById('btnTabRegister');

            if (boxId === 'tabLoginBox') {
                tabLogin.classList.remove('hidden');
                tabRegister.classList.add('hidden');
                btnLogin.classList.add('border-stone-900', 'text-stone-900');
                btnLogin.classList.remove('border-transparent', 'text-stone-400');
                btnRegister.classList.remove('border-stone-900', 'text-stone-900');
                btnRegister.classList.add('border-transparent', 'text-stone-400');
            } else {
                tabRegister.classList.remove('hidden');
                tabLogin.classList.add('hidden');
                btnRegister.classList.add('border-stone-900', 'text-stone-900');
                btnRegister.classList.remove('border-transparent', 'text-stone-400');
                btnLogin.classList.remove('border-stone-900', 'text-stone-900');
                btnLogin.classList.add('border-transparent', 'text-stone-400');
            }
        }

        // Lógica do Calendário de Mês/Ano
        let activeFilterYear = <?php echo $filtroAno; ?>;
        function adjustFilterYear(direction) {
            activeFilterYear += direction;
            document.getElementById('calendarYearLabel').innerText = activeFilterYear;
        }

        function setFilterPeriod(monthStr) {
            // Mapeamento dinâmico via URL
            window.location.href = `?tab=remessas&mes=${monthStr}&ano=${activeFilterYear}`;
        }

        // Inicializar Lucide Icons
        lucide.createIcons();
    </script>
</body>
</html>
