<?php
/**
 * Demanda - Explorador de Estrutura do Firestore
 * 
 * Este arquivo descobre automaticamente todas as coleções e documentos
 * do seu Firebase para mapear quais dados serão importados para o MySQL.
 * 
 * INSTRUÇÕES:
 * 1. Acesse http://localhost:8080/explorar_firestore.php
 * 2. Veja a estrutura encontrada
 * 3. Depois rode o importador completo
 */

// Verificar se o autoload do Composer existe
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo renderPage('Biblioteca Não Instalada', '
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-center">
            <div class="text-6xl mb-4">📦</div>
            <h3 class="text-lg font-bold text-amber-900 mb-2">Composer não instalado ainda</h3>
            <p class="text-amber-700 text-sm mb-4">A biblioteca do Firebase ainda não foi baixada no container. <br>Execute o comando abaixo no seu PowerShell:</p>
            <div class="bg-stone-900 text-emerald-400 font-mono text-sm p-4 rounded-xl text-left">
                <p class="text-stone-400 text-xs mb-2"># Instale o Composer dentro do container PHP:</p>
                docker exec costureira_web bash -c "curl -sS https://getcomposer.org/installer | php && php composer.phar install"
            </div>
            <p class="text-amber-600 text-xs mt-3">Após o comando, volte a esta página e ela carregará automaticamente.</p>
        </div>
    ');
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;

// Verificar se o arquivo de credenciais existe
$credenciaisPath = '/var/www/html/../../../firebase_credenciais.json';
$altPath = '/firebase_credenciais.json';
$credFile = null;

// Buscar o arquivo de credenciais em múltiplos locais
$possiblePaths = [
    '/var/www/html/firebase_credenciais.json',
    __DIR__ . '/firebase_credenciais.json',
    '/var/www/firebase_credenciais.json',
    dirname(__DIR__) . '/firebase_credenciais.json',
];

foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $credFile = $path;
        break;
    }
}

if (!$credFile) {
    echo renderPage('Credenciais Não Encontradas', '
        <div class="bg-rose-50 border border-rose-200 rounded-2xl p-6 text-center">
            <div class="text-6xl mb-4">🔑</div>
            <h3 class="text-lg font-bold text-rose-900 mb-2">Arquivo de Credenciais Não Encontrado</h3>
            <p class="text-rose-700 text-sm mb-4">O arquivo <strong>firebase_credenciais.json</strong> não foi encontrado.</p>
            <p class="text-stone-600 text-sm">Verifique se o arquivo está na pasta <code class="bg-stone-100 px-1 rounded">c:\\demanda</code> e que o volume do Docker está mapeado corretamente.</p>
        </div>
    ');
    exit;
}

// Inicializar o Firebase
try {
    $firebase = (new Factory)
        ->withServiceAccount($credFile);
    
    $firestore = $firebase->createFirestore()->database();
    
    // Descobrir todas as coleções na raiz
    $collections = $firestore->collections();
    
    $colData = [];
    foreach ($collections as $collection) {
        $collName = $collection->id();
        $docs = [];
        $count = 0;
        
        // Pegar os 3 primeiros documentos como amostra
        foreach ($collection->limit(3)->documents() as $doc) {
            if ($doc->exists()) {
                $docs[] = $doc->data();
                $count++;
            }
        }
        
        // Contar total de documentos da coleção
        $totalCount = 0;
        foreach ($collection->documents() as $doc) {
            if ($doc->exists()) $totalCount++;
        }
        
        $colData[$collName] = [
            'total' => $totalCount,
            'sample' => $docs
        ];
    }
    
} catch (Exception $e) {
    echo renderPage('Erro na Conexão com o Firebase', '
        <div class="bg-rose-50 border border-rose-200 rounded-2xl p-6">
            <h3 class="text-lg font-bold text-rose-900 mb-2">Erro ao conectar no Firestore</h3>
            <p class="text-rose-700 text-sm font-mono bg-rose-100 p-3 rounded-xl">' . htmlspecialchars($e->getMessage()) . '</p>
        </div>
    ');
    exit;
}

// Construir HTML do relatório
$collectionCards = '';
if (empty($colData)) {
    $collectionCards = '<div class="bg-stone-50 border border-stone-200 rounded-2xl p-8 text-center text-stone-500">Nenhuma coleção encontrada no Firestore.</div>';
} else {
    foreach ($colData as $collName => $data) {
        $sample = $data['sample'];
        $total = $data['total'];
        
        // Extrair os campos do primeiro documento como exemplo
        $fields = '';
        if (!empty($sample)) {
            foreach ($sample[0] as $key => $value) {
                $type = gettype($value);
                if (is_array($value) || is_object($value)) {
                    $type = 'objeto/array';
                    $value = '[complexo]';
                } elseif ($value instanceof \Google\Cloud\Core\Timestamp) {
                    $type = 'data/hora';
                    $value = $value->formatForApi();
                } else {
                    $value = htmlspecialchars(substr((string)$value, 0, 60));
                }
                $fields .= "<div class='flex justify-between items-center py-1.5 border-b border-stone-100 last:border-0 text-xs'>
                    <span class='font-mono text-blue-700 font-bold'>$key</span>
                    <span class='text-stone-400 bg-stone-50 px-2 py-0.5 rounded text-[10px]'>$type</span>
                </div>";
            }
        }

        $collectionCards .= "
        <div class='bg-white border border-stone-200 rounded-2xl p-5 shadow-sm'>
            <div class='flex items-center justify-between mb-4'>
                <div class='flex items-center gap-3'>
                    <div class='w-10 h-10 bg-orange-100 text-orange-600 rounded-xl flex items-center justify-center font-bold text-sm'>
                        " . strtoupper(substr($collName, 0, 2)) . "
                    </div>
                    <div>
                        <h3 class='font-bold text-stone-900 font-mono text-lg'>$collName</h3>
                        <span class='text-xs text-stone-400'>$total documento(s) encontrado(s)</span>
                    </div>
                </div>
                <span class='bg-emerald-100 text-emerald-800 text-xs font-bold px-3 py-1 rounded-full border border-emerald-200'>✓ Encontrada</span>
            </div>
            
            <div class='bg-stone-50 rounded-xl p-4 border border-stone-100'>
                <h4 class='text-[10px] uppercase font-bold text-stone-400 tracking-wider mb-2'>Campos Identificados (amostra do 1° documento):</h4>
                $fields
            </div>
        </div>";
    }
}

$collectionCount = count($colData);
$totalDocs = array_sum(array_column($colData, 'total'));

echo renderPage("Estrutura do Firestore Descoberta — $collectionCount Coleções", "
    <div class='mb-8 p-5 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-center gap-4'>
        <div class='text-4xl'>🔥</div>
        <div>
            <h3 class='font-bold text-emerald-900 text-base'>Conexão com Firebase bem-sucedida!</h3>
            <p class='text-emerald-700 text-sm mt-0.5'>Encontramos <strong>$collectionCount coleção(ões)</strong> com um total estimado de <strong>$totalDocs documentos</strong>.</p>
            <p class='text-emerald-600 text-xs mt-1'>Verifique a estrutura abaixo e depois acesse <a href='importar_firebase.php' class='font-bold underline'>importar_firebase.php</a> para executar a importação!</p>
        </div>
    </div>
    
    <div class='grid grid-cols-1 md:grid-cols-2 gap-4'>
        $collectionCards
    </div>
    
    <div class='mt-8 text-center'>
        <a href='importar_firebase.php' class='inline-flex items-center gap-2 bg-stone-950 hover:bg-stone-800 text-white font-bold px-8 py-4 rounded-2xl text-sm transition-all active:scale-95 shadow-lg'>
            ✨ Prosseguir para Importação Completa →
        </a>
    </div>
");

// Função utilitária de template HTML
function renderPage(string $title, string $content): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demanda — Explorador Firebase</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body { background-color: #FAF6F0; font-family: 'Inter', sans-serif; }</style>
</head>
<body class="p-8 max-w-5xl mx-auto">
    <div class="mb-8 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-12 h-12 bg-stone-900 rounded-full flex items-center justify-center text-yellow-400 text-xl">✂</div>
            <div>
                <h1 class="text-2xl font-bold text-stone-900" style="font-family: 'Playfair Display', serif;">Demanda</h1>
                <p class="text-xs text-stone-400 uppercase tracking-widest">Explorador do Firebase Firestore</p>
            </div>
        </div>
        <a href="index.php" class="text-xs font-bold text-stone-500 hover:text-stone-900 border border-stone-200 px-4 py-2 rounded-xl transition-colors">← Voltar ao Sistema</a>
    </div>
    
    <div class="bg-stone-900 text-white rounded-3xl p-6 mb-8">
        <h2 class="text-xl font-bold mb-1" style="font-family: 'Playfair Display', serif;">$title</h2>
        <p class="text-stone-400 text-xs uppercase tracking-wider">Análise de estrutura e pré-visualização dos dados a importar</p>
    </div>

    $content
    
    <p class="text-center text-stone-300 text-[10px] mt-12">Demanda · Importador Firebase → MySQL · Apague este arquivo após a importação.</p>
</body>
</html>
HTML;
}
