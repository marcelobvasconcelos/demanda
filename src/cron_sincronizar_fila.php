<?php
/**
 * cron_sincronizar_fila.php
 *
 * Processa a fila de lotes com sincronizado=0 no MySQL e tenta enviá-los
 * ao Firestore respeitando a regra de concorrência por timestamp:
 *
 *   - Se o atualizado_em LOCAL for mais recente → PATCH no Firestore → sincronizado=1
 *   - Se o atualizado_em do FIRESTORE for mais recente → descarta a alteração local,
 *     atualiza o MySQL com o dado do Firebase → sincronizado=1 (pró-App)
 *
 * Uso via cron (a cada 5 minutos):
 *   * /5 * * * * php /var/www/html/cron_sincronizar_fila.php >> /var/www/html/logs/cron_sync.log 2>&1
 *
 * Uso manual (para testes):
 *   php cron_sincronizar_fila.php
 */

require __DIR__ . '/firebase_helper.php';

// ── Configuração ──────────────────────────────────────────────────────────────

define('LOCK_FILE',  __DIR__ . '/tmp/cron_sync.lock');
define('LOCK_TTL',   240);   // segundos — evita execuções sobrepostas
define('BATCH_SIZE', 50);    // máximo de registros por execução
define('LOG_FILE',   __DIR__ . '/logs/cron_sync.log');

function log_cron(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents(LOG_FILE, $line, FILE_APPEND);
    // Também imprime no stdout para facilitar debug manual
    echo $line;
}

// ── Lock: impede execuções paralelas ─────────────────────────────────────────

if (!is_dir(__DIR__ . '/tmp')) @mkdir(__DIR__ . '/tmp', 0755, true);
if (!is_dir(__DIR__ . '/logs')) @mkdir(__DIR__ . '/logs', 0755, true);

if (file_exists(LOCK_FILE) && (time() - filemtime(LOCK_FILE)) < LOCK_TTL) {
    log_cron('Execução ignorada: lock ativo.');
    exit(0);
}
file_put_contents(LOCK_FILE, (string) time());

// ── Conexão MySQL ─────────────────────────────────────────────────────────────

try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST')     ?: 'mysql',
            getenv('DB_PORT')     ?: '3306',
            getenv('DB_DATABASE') ?: 'costureira_db'
        ),
        getenv('DB_USERNAME') ?: 'costureira_user',
        getenv('DB_PASSWORD') ?: 'costureira_pass',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    log_cron('ERRO: Não foi possível conectar ao MySQL — ' . $e->getMessage());
    @unlink(LOCK_FILE);
    exit(1);
}

// ── Verifica se o Firestore está acessível antes de processar a fila ─────────

try {
    firestore_get_credentials();
} catch (Throwable $e) {
    log_cron('ERRO: Credenciais do Firebase indisponíveis — ' . $e->getMessage());
    @unlink(LOCK_FILE);
    exit(1);
}

// ── Busca registros pendentes ─────────────────────────────────────────────────

$pendentes = $pdo
    ->query('SELECT * FROM lotes WHERE sincronizado = 0 LIMIT ' . BATCH_SIZE)
    ->fetchAll(PDO::FETCH_ASSOC);

if (empty($pendentes)) {
    log_cron('Nenhum registro pendente na fila.');
    @unlink(LOCK_FILE);
    exit(0);
}

log_cron(sprintf('Processando %d registro(s) pendente(s).', count($pendentes)));

$enviados  = 0;
$revertidos = 0;
$erros     = 0;

foreach ($pendentes as $lote) {
    $docId   = $lote['id'];
    $colecao = $lote['mes_ano_referencia'];

    try {
        // ── Passo 1: GET no Firestore para comparar timestamps ────────────────
        $docFirestore = null;
        try {
            $resp = firestore_request('GET', 'documents/' . rawurlencode($colecao) . '/' . $docId);
            $docFirestore = firestore_document_to_array($resp);
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), '404') !== false) {
                // Documento não existe no Firestore — pode ser novo, segue para PATCH/POST
                $docFirestore = null;
            } else {
                throw $e; // Erro real (rede, cota) — pula este registro
            }
        }

        // ── Passo 2: Comparação de timestamps ────────────────────────────────
        $tsLocal     = new DateTime($lote['atualizado_em']);
        $tsFirestore = null;

        if ($docFirestore !== null && !empty($docFirestore['atualizado_em'])) {
            try {
                $tsFirestore = new DateTime($docFirestore['atualizado_em']);
            } catch (Throwable) {
                $tsFirestore = null;
            }
        }

        // Se o Firestore tem um timestamp mais recente → resolução pró-App
        if ($tsFirestore !== null && $tsFirestore > $tsLocal) {
            log_cron(sprintf(
                'CONFLITO pró-App [%s/%s]: Firestore (%s) > Local (%s). Revertendo MySQL.',
                $colecao, $docId,
                $tsFirestore->format('Y-m-d H:i:s'),
                $tsLocal->format('Y-m-d H:i:s')
            ));

            // Atualiza o MySQL com o dado mais recente do Firestore
            $pdo->prepare(
                'UPDATE lotes SET
                    peca_servico   = :peca,
                    quantidade     = :qtd,
                    qtd_entregue   = :qtd_e,
                    preco_unitario = :preco,
                    tamanho        = :tamanho,
                    valor_recebido = :val_rec,
                    data_entrega   = :data_ent,
                    sincronizado   = 1,
                    atualizado_em  = :atualizado_em
                WHERE id = :id'
            )->execute([
                ':peca'         => $docFirestore['peca_servico'] ?? $lote['peca_servico'],
                ':qtd'          => intval($docFirestore['quantidade'] ?? $docFirestore['qtd'] ?? $lote['quantidade']),
                ':qtd_e'        => intval($docFirestore['qtd_entregue'] ?? $docFirestore['entregue'] ?? $lote['qtd_entregue']),
                ':preco'        => floatval($docFirestore['preco_unitario'] ?? $docFirestore['precoU'] ?? $lote['preco_unitario']),
                ':tamanho'      => $docFirestore['tamanho'] ?? $lote['tamanho'],
                ':val_rec'      => floatval($docFirestore['valor_recebido'] ?? $lote['valor_recebido']),
                ':data_ent'     => $docFirestore['data_entrega'] ?? $docFirestore['data_ultima_entrega'] ?? $lote['data_entrega'],
                ':atualizado_em'=> $tsFirestore->format('Y-m-d H:i:s.v'),
                ':id'           => $docId,
            ]);

            $revertidos++;
            continue;
        }

        // ── Passo 3: Local é mais recente → PATCH no Firestore ───────────────
        $payload = [
            'peca_servico'   => $lote['peca_servico'],
            'quantidade'     => intval($lote['quantidade']),
            'qtd_entregue'   => intval($lote['qtd_entregue']),
            'entregue'       => intval($lote['qtd_entregue']),  // campo legado do Flutter
            'preco_unitario' => floatval($lote['preco_unitario']),
            'precoU'         => floatval($lote['preco_unitario']), // campo legado do Flutter
            'tamanho'        => $lote['tamanho'],
            'valor_recebido' => floatval($lote['valor_recebido']),
            'atualizado_em'  => ['__timestamp' => (new DateTime($lote['atualizado_em']))->format('Y-m-d\\TH:i:s.v\\Z')],
        ];

        if ($docFirestore === null) {
            // Documento novo: cria via POST
            firestore_request(
                'POST',
                'documents/' . rawurlencode($colecao) . '?documentId=' . $docId,
                ['fields' => firestore_build_fields($payload)]
            );
        } else {
            // Atualização: PATCH com máscara — nunca apaga campos do Flutter
            firestore_update_remessa($docId, $payload, $colecao);
        }

        // Marca como sincronizado no MySQL
        $pdo->prepare('UPDATE lotes SET sincronizado = 1 WHERE id = :id')
            ->execute([':id' => $docId]);

        log_cron(sprintf('OK [%s/%s]: enviado ao Firestore.', $colecao, $docId));
        $enviados++;

    } catch (Throwable $e) {
        log_cron(sprintf('ERRO [%s/%s]: %s', $colecao, $docId, $e->getMessage()));
        $erros++;
        // Não altera sincronizado — permanece 0 para tentar na próxima execução
    }
}

log_cron(sprintf(
    'Concluído. Enviados: %d | Revertidos (pró-App): %d | Erros: %d',
    $enviados, $revertidos, $erros
));

@unlink(LOCK_FILE);
exit($erros > 0 ? 1 : 0);
