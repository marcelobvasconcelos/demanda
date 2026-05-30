<?php
/**
 * Firestore helper leve para PHP puro.
 * Suporta autenticação via service account e operações básicas de usuário.
 */

function getFirestoreCredentialsPath(): ?string
{
    $paths = [
        getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: null,
        '/etc/secrets/firebase_credenciais.json',
        __DIR__ . '/../firebase_credenciais.json',
        __DIR__ . '/firebase_credenciais.json',
    ];

    foreach ($paths as $path) {
        if ($path && file_exists($path) && is_readable($path)) {
            return $path;
        }
    }

    return null;
}

function firestore_get_credentials(): array
{
    static $credentials = null;
    if ($credentials !== null) {
        return $credentials;
    }

    $firebaseJson = '';
    if (getenv('FIREBASE_CREDENTIALS_JSON') && trim(getenv('FIREBASE_CREDENTIALS_JSON')) !== '') {
        $firebaseJson = getenv('FIREBASE_CREDENTIALS_JSON');
    } else {
        $path = getFirestoreCredentialsPath();
        if ($path) {
            $firebaseJson = file_get_contents($path);
        }
    }

    if (empty($firebaseJson)) {
        throw new RuntimeException('Credenciais do Firebase não encontradas.');
    }

    $credentials = json_decode($firebaseJson, true);
    if (!is_array($credentials) || empty($credentials['project_id'])) {
        throw new RuntimeException('Arquivo de credenciais do Firebase inválido.');
    }

    return $credentials;
}

function firestore_get_project_id(array $serviceAccount): string
{
    return $serviceAccount['project_id'];
}

function firebase_get_access_token(array $serviceAccount, array $scopes): string
{
    static $cache = [];
    $cacheKey = $serviceAccount['client_email'] . '|' . implode(' ', $scopes);
    if (isset($cache[$cacheKey]) && $cache[$cacheKey]['expires_at'] > time() + 60) {
        return $cache[$cacheKey]['access_token'];
    }

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $now = time();
    $payload = [
        'iss' => $serviceAccount['client_email'],
        'scope' => implode(' ', $scopes),
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
        'sub' => $serviceAccount['client_email'],
    ];

    $headerEncoded = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
    $payloadEncoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $signatureInput = $headerEncoded . '.' . $payloadEncoded;

    openssl_sign($signatureInput, $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256);
    $signatureEncoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    $jwt = $signatureInput . '.' . $signatureEncoded;

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $result = curl_exec($ch);
    curl_close($ch);

    $response = json_decode($result, true);
    if (empty($response['access_token'])) {
        throw new RuntimeException('Falha ao obter token OAuth: ' . ($response['error_description'] ?? $result));
    }

    $cache[$cacheKey] = [
        'access_token' => $response['access_token'],
        'expires_at' => time() + intval($response['expires_in'] ?? 3600),
    ];

    return $cache[$cacheKey]['access_token'];
}

function firestore_get_access_token(array $serviceAccount): string
{
    return firebase_get_access_token($serviceAccount, [
        'https://www.googleapis.com/auth/datastore',
        'https://www.googleapis.com/auth/userinfo.email',
    ]);
}

function firestore_request(string $method, string $path, array $body = null): array
{
    $serviceAccount = firestore_get_credentials();
    $projectId = firestore_get_project_id($serviceAccount);
    $baseUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/";
    $token = firestore_get_access_token($serviceAccount);

    $url = $baseUrl . ltrim($path, '/');
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));
    }

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
        throw new RuntimeException('Erro de rede ao acessar o Firestore.');
    }

    $decoded = json_decode($result, true);
    if ($httpCode >= 400) {
        $msg = $decoded['error']['message'] ?? $result;
        if (strpos($msg, 'quota') !== false) $msg = 'Limite de acesso ao Firebase atingido. Tente novamente em alguns minutos.';
        throw new RuntimeException($msg);
    }

    return $decoded ?: [];
}

function firebase_auth_request(string $method, string $path, array $body = null): array
{
    $serviceAccount = firestore_get_credentials();
    $token = firebase_get_access_token($serviceAccount, [
        'https://www.googleapis.com/auth/identitytoolkit',
        'https://www.googleapis.com/auth/cloud-platform',
    ]);

    $url = 'https://identitytoolkit.googleapis.com/v1/' . ltrim($path, '/');
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));
    }

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($result, true);
    if ($httpCode >= 400) {
        $msg = $decoded['error']['message'] ?? $result;
        throw new RuntimeException($msg);
    }

    return $decoded ?: [];
}

function firebase_auth_sign_in_with_password(string $email, string $password): array
{
    try {
        $response = firebase_auth_request('POST', 'accounts:signInWithPassword', [
            'email' => trim(strtolower($email)),
            'password' => $password,
            'returnSecureToken' => true,
        ]);
        return [
            'success' => isset($response['localId']),
            'data' => $response,
            'error' => null
        ];
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $errorMsg = 'Erro ao realizar login.';
        if (strpos($message, 'INVALID_PASSWORD') !== false) $errorMsg = 'Senha incorreta.';
        elseif (strpos($message, 'EMAIL_NOT_FOUND') !== false) $errorMsg = 'E-mail não cadastrado.';
        elseif (strpos($message, 'TOO_MANY_ATTEMPTS') !== false) $errorMsg = 'Muitas tentativas bloqueadas. Tente mais tarde.';
        return ['success' => false, 'data' => null, 'error' => $errorMsg];
    }
}

function firebase_auth_send_password_reset_email(string $email): bool
{
    try {
        firebase_auth_request('POST', 'accounts:sendOobCode', [
            'requestType' => 'PASSWORD_RESET',
            'email' => trim(strtolower($email))
        ]);
        return true;
    } catch (Throwable $e) { return false; }
}

function firestore_parse_value(array $value)
{
    if (isset($value['stringValue'])) return $value['stringValue'];
    if (isset($value['integerValue'])) return intval($value['integerValue']);
    if (isset($value['doubleValue'])) return floatval($value['doubleValue']);
    if (isset($value['booleanValue'])) return (bool)$value['booleanValue'];
    if (isset($value['timestampValue'])) return $value['timestampValue'];
    if (isset($value['mapValue']['fields'])) {
        $res = [];
        foreach ($value['mapValue']['fields'] as $k => $v) $res[$k] = firestore_parse_value($v);
        return $res;
    }
    if (isset($value['arrayValue']['values'])) return array_map('firestore_parse_value', $value['arrayValue']['values']);
    return null;
}

function firestore_document_to_array(array $document): array
{
    $res = [];
    foreach (($document['fields'] ?? []) as $k => $v) $res[$k] = firestore_parse_value($v);
    return $res;
}

function firestore_build_fields(array $data): array
{
    $fields = [];
    foreach ($data as $key => $value) {
        if (is_array($value) && isset($value['__timestamp'])) {
            try {
                $dt = new DateTime($value['__timestamp']);
                $dt->setTimezone(new DateTimeZone('UTC'));
                $fields[$key] = ['timestampValue' => $dt->format('Y-m-d\TH:i:s.v\Z')];
            } catch (Throwable $e) { $fields[$key] = ['stringValue' => (string)$value['__timestamp']]; }
            continue;
        }
        if (is_int($value)) $fields[$key] = ['integerValue' => (string)$value];
        elseif (is_float($value)) $fields[$key] = ['doubleValue' => (float)$value];
        elseif (is_bool($value)) $fields[$key] = ['booleanValue' => (bool)$value];
        elseif ($value === null) $fields[$key] = ['nullValue' => null];
        else $fields[$key] = ['stringValue' => (string)$value];
    }
    return $fields;
}

function firestore_build_monthly_collection_name(string $userUid, string $date): string
{
    $meses = ['01'=>'janeiro','02'=>'fevereiro','03'=>'março','04'=>'abril','05'=>'maio','06'=>'junho','07'=>'julho','08'=>'agosto','09'=>'setembro','10'=>'outubro','11'=>'novembro','12'=>'dezembro'];
    $dt = new DateTime($date);
    return ($meses[$dt->format('m')] ?? 'janeiro') . '-' . $dt->format('Y') . $userUid;
}

function firestore_get_user_uid_by_email(string $email): string
{
    $response = firebase_auth_request('POST', 'accounts:lookup', ['email' => [trim(strtolower($email))]]);
    if (empty($response['users'][0]['localId'])) throw new RuntimeException('Usuário não encontrado no Firebase.');
    return $response['users'][0]['localId'];
}

function firestore_query_remessas(string $email, string $startDate, string $endDate, ?string $uid = null): array
{
    if (!$uid) $uid = firestore_get_user_uid_by_email($email);
    $dt = new DateTime($startDate);
    $col = firestore_build_monthly_collection_name($uid, $dt->format('Y-m-d'));
    
    try {
        $resp = firestore_request('GET', 'documents/' . rawurlencode($col) . '?pageSize=1000');
        $results = [];
        foreach (($resp['documents'] ?? []) as $d) {
            $doc = firestore_document_to_array($d);
            $parts = explode('/', $d['name']);
            $doc['id'] = end($parts);
            $doc['__collection'] = $col;
            if (empty($doc['data_cadastro'])) {
                $cand = $doc['data'] ?? $doc['data_entrada'] ?? $doc['data_entrega'] ?? null;
                if ($cand) $doc['data_cadastro'] = substr($cand, 0, 10);
            }
            $results[] = $doc;
        }
        usort($results, function($a, $b) { return strcmp($b['data_cadastro'] ?? '', $a['data_cadastro'] ?? ''); });
        return $results;
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), '404') !== false) return [];
        throw $e;
    }
}

function firestore_get_all_user_remessas(string $email, ?string $uid = null): array
{
    if (!$uid) $uid = firestore_get_user_uid_by_email($email);
    $all = [];
    $serviceAccount = firestore_get_credentials();
    $parent = 'projects/' . $serviceAccount['project_id'] . '/databases/(default)/documents';
    
    // Lista coleções com suporte a paginação para não perder dados
    $collections = [];
    $pageToken = null;
    do {
        $params = ['parent' => $parent, 'pageSize' => 500];
        if ($pageToken) $params['pageToken'] = $pageToken;
        $resp = firestore_request('POST', 'documents:listCollectionIds', $params);
        $collections = array_merge($collections, $resp['collectionIds'] ?? []);
        $pageToken = $resp['nextPageToken'] ?? null;
    } while ($pageToken);
    
    foreach ($collections as $col) {
        if (str_ends_with($col, $uid)) {
            try {
                $dresp = firestore_request('GET', 'documents/' . rawurlencode($col) . '?pageSize=1000');
                foreach (($dresp['documents'] ?? []) as $d) {
                    $doc = firestore_document_to_array($d);
                    $parts = explode('/', $d['name']);
                    $doc['id'] = end($parts);
                    $doc['__collection'] = $col;
                    if (empty($doc['data_cadastro'])) {
                        $cand = $doc['data'] ?? $doc['data_entrada'] ?? $doc['data_entrega'] ?? null;
                        if ($cand) $doc['data_cadastro'] = substr($cand, 0, 10);
                    }
                    $all[] = $doc;
                }
            } catch (Throwable $e) {}
        }
    }
    usort($all, function($a, $b) { return strcmp($b['data_cadastro'] ?? '', $a['data_cadastro'] ?? ''); });
    return $all;
}

function firestore_add_remessa(array $data): void
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $docId = '';
    for ($i = 0; $i < 20; $i++) $docId .= $chars[random_int(0, strlen($chars) - 1)];

    $uid = $data['usuario_uid'] ?? firestore_get_user_uid_by_email($data['usuario_email']);
    $dataCad = $data['data_cadastro'] ?? date('Y-m-d\TH:i:s');
    $col = firestore_build_monthly_collection_name($uid, $dataCad);

    $prU = floatval($data['preco_unitario'] ?? 0);
    $qtd = intval($data['quantidade'] ?? 0);

    $fields = firestore_build_fields([
        'peca_servico' => $data['peca_servico'],
        'quantidade' => $qtd,
        'entregue' => 0,
        'precoU' => $prU,
        'total' => $prU * $qtd,
        'tamanho' => $data['tamanho'] ?? '',
        'marcado' => false,
        'data' => ['__timestamp' => $dataCad],
        'preco_unitario' => $prU,
        'qtd_entregue' => 0,
        'valor_recebido' => 0.0,
        'usuario_email' => $data['usuario_email'],
        'usuario_nome' => $data['usuario_nome'],
        'usuario_uid' => $uid,
        'usuario_id' => $uid,
        'data_cadastro' => $dataCad
    ]);
    firestore_request('POST', 'documents/' . rawurlencode($col) . '?documentId=' . $docId, ['fields' => $fields]);
}

function firestore_update_remessa(string $docId, array $data, string $col): void
{
    $fields = []; $mask = [];
    $map = ['peca_servico'=>'peca_servico','quantidade'=>'quantidade','qtd'=>'quantidade','tamanho'=>'tamanho','size'=>'tamanho','qtd_entregue'=>'entregue','entregue'=>'entregue','data_ultima_entrega'=>'data_entrega','data_entrega'=>'data_entrega','preco_unitario'=>'precoU','precoU'=>'precoU'];
    foreach ($data as $k => $v) {
        if (($k === 'data_entrega' || $k === 'data_ultima_entrega' || $k === 'data') && $v) {
            $fields[$k] = ['__timestamp' => $v];
            $mask[] = "updateMask.fieldPaths=" . $k;
            continue;
        }
        $fields[$k] = $v;
        $mask[] = "updateMask.fieldPaths=" . $k;
        if (isset($map[$k]) && $map[$k] !== $k) {
            $fields[$map[$k]] = $v;
            $mask[] = "updateMask.fieldPaths=" . $map[$k];
        }
    }
    if (empty($fields)) return;
    firestore_request('PATCH', 'documents/' . rawurlencode($col) . '/' . $docId . '?' . implode('&', $mask), ['fields' => firestore_build_fields($fields)]);
}

function firestore_delete_document(string $col, string $docId): void
{
    firestore_request('DELETE', 'documents/' . rawurlencode($col) . '/' . $docId);
}

function firestore_update_remessa_entrega(string $docId, int $qtd, ?string $data, string $col, float $valRec): void
{
    $up = ['entregue' => $qtd, 'qtd_entregue' => $qtd, 'valor_recebido' => $valRec];
    if ($data) { $up['data_entrega'] = $data; $up['data_ultima_entrega'] = $data; }
    firestore_update_remessa($docId, $up, $col);
}

function firestore_upsert_user(array $userData): bool { return true; }
function firestore_sync_user_to_local(PDO $pdo, array $userData): ?array { return null; }
try { $credentials = firestore_get_credentials(); } catch (Throwable $e) { $credentials = null; }

// =============================================================================
// SINCRONISMO LOCAL (MySQL) — Failover + Fila de Escrita
// =============================================================================

/**
 * Verifica se um erro do Firestore é causado por cota esgotada.
 * Cobre HTTP 429 (Too Many Requests) e 403 com mensagem de quota.
 */
function firestore_is_quota_error(string $message): bool
{
    return stripos($message, 'quota') !== false
        || stripos($message, 'RESOURCE_EXHAUSTED') !== false
        || stripos($message, '429') !== false
        || stripos($message, 'rateLimitExceeded') !== false;
}

/**
 * Espelha um array de lotes no MySQL local usando INSERT ... ON DUPLICATE KEY UPDATE.
 * Nunca sobrescreve registros com sincronizado=0 (alterações locais pendentes)
 * a menos que o atualizado_em do Firestore seja mais recente.
 */
function lotes_espelhar_no_mysql(PDO $pdo, array $lotes, string $mesAnoRef): void
{
    $sql = <<<SQL
        INSERT INTO lotes (
            id, mes_ano_referencia, usuario_uid, usuario_email,
            peca_servico, quantidade, qtd_entregue, preco_unitario,
            tamanho, valor_recebido, data_cadastro, data_entrega,
            sincronizado, atualizado_em
        ) VALUES (
            :id, :mes_ano, :uid, :email,
            :peca, :qtd, :qtd_e, :preco,
            :tamanho, :val_rec, :data_cad, :data_ent,
            1, :atualizado_em
        )
        ON DUPLICATE KEY UPDATE
            -- Só atualiza se o dado do Firestore for mais recente que o local
            peca_servico    = IF(sincronizado = 0 AND atualizado_em > VALUES(atualizado_em), peca_servico,    VALUES(peca_servico)),
            quantidade      = IF(sincronizado = 0 AND atualizado_em > VALUES(atualizado_em), quantidade,      VALUES(quantidade)),
            qtd_entregue    = IF(sincronizado = 0 AND atualizado_em > VALUES(atualizado_em), qtd_entregue,    VALUES(qtd_entregue)),
            preco_unitario  = IF(sincronizado = 0 AND atualizado_em > VALUES(atualizado_em), preco_unitario,  VALUES(preco_unitario)),
            tamanho         = IF(sincronizado = 0 AND atualizado_em > VALUES(atualizado_em), tamanho,         VALUES(tamanho)),
            valor_recebido  = IF(sincronizado = 0 AND atualizado_em > VALUES(atualizado_em), valor_recebido,  VALUES(valor_recebido)),
            data_entrega    = IF(sincronizado = 0 AND atualizado_em > VALUES(atualizado_em), data_entrega,    VALUES(data_entrega)),
            sincronizado    = IF(sincronizado = 0 AND atualizado_em > VALUES(atualizado_em), 0,               1),
            atualizado_em   = IF(sincronizado = 0 AND atualizado_em > VALUES(atualizado_em), atualizado_em,   VALUES(atualizado_em))
    SQL;

    $stmt = $pdo->prepare($sql);

    foreach ($lotes as $r) {
        // Normaliza o timestamp: usa atualizado_em do Firestore se existir,
        // senão usa data_cadastro como fallback para não inserir NULL.
        $tsFirestore = $r['atualizado_em'] ?? $r['data_cadastro'] ?? date('Y-m-d H:i:s');
        if (strlen($tsFirestore) > 19) {
            // Converte ISO 8601 com fuso (ex: 2025-01-15T10:30:00Z) para datetime MySQL
            try {
                $dt = new DateTime($tsFirestore);
                $dt->setTimezone(new DateTimeZone('UTC'));
                $tsFirestore = $dt->format('Y-m-d H:i:s.v');
            } catch (Throwable) {
                $tsFirestore = substr($tsFirestore, 0, 19);
            }
        }

        $stmt->execute([
            ':id'           => $r['id'],
            ':mes_ano'      => $mesAnoRef,
            ':uid'          => $r['usuario_uid'] ?? $r['usuario_id'] ?? '',
            ':email'        => $r['usuario_email'] ?? '',
            ':peca'         => $r['peca_servico'] ?? '',
            ':qtd'          => intval($r['quantidade'] ?? $r['qtd'] ?? 0),
            ':qtd_e'        => intval($r['qtd_entregue'] ?? $r['entregue'] ?? 0),
            ':preco'        => floatval($r['preco_unitario'] ?? $r['precoU'] ?? 0),
            ':tamanho'      => $r['tamanho'] ?? '-',
            ':val_rec'      => floatval($r['valor_recebido'] ?? 0),
            ':data_cad'     => $r['data_cadastro'] ?? null,
            ':data_ent'     => $r['data_entrega'] ?? $r['data_ultima_entrega'] ?? null,
            ':atualizado_em'=> $tsFirestore,
        ]);
    }
}

/**
 * Busca lotes de uma coleção mensal com failover automático para MySQL.
 *
 * Retorna um array com:
 *   'lotes'      => array de lotes normalizados
 *   'fonte'      => 'firestore' | 'mysql'
 *   'contingencia' => bool (true quando usando espelho local)
 */
function lotes_buscar(string $colecao, PDO $pdo = null): array
{
    // --- Tentativa 1: Firestore ---
    try {
        $resp  = firestore_request('GET', 'documents/' . rawurlencode($colecao) . '?pageSize=1000');
        $lotes = [];

        foreach (($resp['documents'] ?? []) as $d) {
            $doc           = firestore_document_to_array($d);
            $parts         = explode('/', $d['name']);
            $doc['id']     = end($parts);
            $doc['__collection'] = $colecao;

            // Normaliza data_cadastro para exibição
            if (empty($doc['data_cadastro'])) {
                $cand = $doc['data'] ?? $doc['data_entrada'] ?? null;
                if ($cand) $doc['data_cadastro'] = substr($cand, 0, 10);
            }
            $lotes[] = $doc;
        }

        // Espelha no MySQL em background (não bloqueia a resposta)
        if ($pdo && !empty($lotes)) {
            try {
                lotes_espelhar_no_mysql($pdo, $lotes, $colecao);
            } catch (Throwable) {
                // Falha no espelhamento não deve derrubar a leitura
            }
        }

        usort($lotes, fn($a, $b) => strcmp($b['data_cadastro'] ?? '', $a['data_cadastro'] ?? ''));
        return ['lotes' => $lotes, 'fonte' => 'firestore', 'contingencia' => false];

    } catch (Throwable $e) {
        // --- Failover: só ativa para erros de cota; outros erros são relancados ---
        if (!firestore_is_quota_error($e->getMessage())) {
            throw $e;
        }
    }

    // --- Tentativa 2: MySQL local (modo de contingência) ---
    if (!$pdo) {
        // Sem banco local disponível, não há fallback possível
        return ['lotes' => [], 'fonte' => 'mysql', 'contingencia' => true];
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM lotes WHERE mes_ano_referencia = :col ORDER BY data_cadastro DESC'
    );
    $stmt->execute([':col' => $colecao]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normaliza para o mesmo formato usado pelo restante da aplicação
    $lotes = array_map(function (array $row) use ($colecao): array {
        return [
            'id'             => $row['id'],
            '__collection'   => $colecao,
            'peca_servico'   => $row['peca_servico'],
            'quantidade'     => intval($row['quantidade']),
            'qtd_entregue'   => intval($row['qtd_entregue']),
            'preco_unitario' => floatval($row['preco_unitario']),
            'tamanho'        => $row['tamanho'],
            'valor_recebido' => floatval($row['valor_recebido']),
            'data_cadastro'  => $row['data_cadastro'],
            'data_entrega'   => $row['data_entrega'],
            'usuario_uid'    => $row['usuario_uid'],
            'usuario_email'  => $row['usuario_email'],
            // Indica origem local para a UI poder exibir badge de contingência
            '__local'        => true,
        ];
    }, $rows);

    return ['lotes' => $lotes, 'fonte' => 'mysql', 'contingencia' => true];
}

/**
 * Grava um lote no Firestore (PATCH com máscara) e espelha no MySQL.
 *
 * Se o Firestore falhar por cota, salva localmente com sincronizado=0
 * e retorna ['sucesso' => true, 'contingencia' => true] para que a UI
 * exiba a mensagem de "salvo localmente".
 *
 * Nunca usa PUT — sempre PATCH com updateMask para preservar campos do Flutter.
 */
function lotes_salvar(
    string $docId,
    array  $dados,
    string $colecao,
    PDO    $pdo = null,
    bool   $isNovo = false
): array {
    $agora     = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    $agoraMysql = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

    // Inclui atualizado_em no payload para o Firestore registrar o timestamp
    $dados['atualizado_em'] = ['__timestamp' => $agora];

    $contingencia = false;

    // --- Tentativa: enviar para o Firestore via PATCH ---
    try {
        if ($isNovo) {
            // Novo documento: POST cria com ID definido
            firestore_request(
                'POST',
                'documents/' . rawurlencode($colecao) . '?documentId=' . $docId,
                ['fields' => firestore_build_fields($dados)]
            );
        } else {
            // Atualização: PATCH com máscara de campos (nunca apaga campos do Flutter)
            firestore_update_remessa($docId, $dados, $colecao);
        }
    } catch (Throwable $e) {
        if (!firestore_is_quota_error($e->getMessage())) {
            throw $e; // Erro real — relanca para o chamador tratar
        }
        $contingencia = true; // Cota esgotada — enfileira localmente
    }

    // --- Espelha no MySQL (sincronizado=1 se foi ao Firebase, 0 se ficou na fila) ---
    if ($pdo) {
        try {
            $sql = <<<SQL
                INSERT INTO lotes (
                    id, mes_ano_referencia, usuario_uid, usuario_email,
                    peca_servico, quantidade, qtd_entregue, preco_unitario,
                    tamanho, valor_recebido, data_cadastro, data_entrega,
                    sincronizado, atualizado_em
                ) VALUES (
                    :id, :mes_ano, :uid, :email,
                    :peca, :qtd, :qtd_e, :preco,
                    :tamanho, :val_rec, :data_cad, :data_ent,
                    :sync, :atualizado_em
                )
                ON DUPLICATE KEY UPDATE
                    peca_servico   = VALUES(peca_servico),
                    quantidade     = VALUES(quantidade),
                    qtd_entregue   = VALUES(qtd_entregue),
                    preco_unitario = VALUES(preco_unitario),
                    tamanho        = VALUES(tamanho),
                    valor_recebido = VALUES(valor_recebido),
                    data_entrega   = VALUES(data_entrega),
                    sincronizado   = VALUES(sincronizado),
                    atualizado_em  = VALUES(atualizado_em)
            SQL;

            $pdo->prepare($sql)->execute([
                ':id'           => $docId,
                ':mes_ano'      => $colecao,
                ':uid'          => $dados['usuario_uid'] ?? $dados['usuario_id'] ?? '',
                ':email'        => $dados['usuario_email'] ?? '',
                ':peca'         => $dados['peca_servico'] ?? '',
                ':qtd'          => intval($dados['quantidade'] ?? 0),
                ':qtd_e'        => intval($dados['qtd_entregue'] ?? $dados['entregue'] ?? 0),
                ':preco'        => floatval($dados['preco_unitario'] ?? $dados['precoU'] ?? 0),
                ':tamanho'      => $dados['tamanho'] ?? '-',
                ':val_rec'      => floatval($dados['valor_recebido'] ?? 0),
                ':data_cad'     => $dados['data_cadastro'] ?? null,
                ':data_ent'     => $dados['data_entrega'] ?? $dados['data_ultima_entrega'] ?? null,
                ':sync'         => $contingencia ? 0 : 1,
                ':atualizado_em'=> $agoraMysql,
            ]);
        } catch (Throwable) {
            // Falha no MySQL não deve impedir o retorno ao usuário
        }
    }

    return ['sucesso' => true, 'contingencia' => $contingencia];
}
