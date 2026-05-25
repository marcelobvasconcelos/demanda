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
        throw new RuntimeException('Erro CURL Firestore.');
    }

    $decoded = json_decode($result, true);
    if ($httpCode >= 400) {
        throw new RuntimeException('Erro Firestore ' . $httpCode . ': ' . ($decoded['error']['message'] ?? $result));
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
        throw new RuntimeException('Erro Auth ' . $httpCode . ': ' . ($decoded['error']['message'] ?? $result));
    }

    return $decoded ?: [];
}

function firebase_auth_sign_in_with_password(string $email, string $password): ?array
{
    $response = firebase_auth_request('POST', 'accounts:signInWithPassword', [
        'email' => trim(strtolower($email)),
        'password' => $password,
        'returnSecureToken' => true,
    ]);
    return isset($response['localId']) ? $response : null;
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
        // Suporte explícito para Timestamp
        if (is_array($value) && isset($value['__timestamp'])) {
            try {
                $dt = new DateTime($value['__timestamp']);
                $dt->setTimezone(new DateTimeZone('UTC'));
                $fields[$key] = ['timestampValue' => $dt->format('Y-m-d\TH:i:s\Z')];
            } catch (Throwable $e) { $fields[$key] = ['stringValue' => (string)$value['__timestamp']]; }
            continue;
        }

        if (is_int($value)) {
            // Firestore REST exige string para int64
            $fields[$key] = ['integerValue' => (string)$value];
        } elseif (is_float($value)) {
            $fields[$key] = ['doubleValue' => (float)$value];
        } elseif (is_bool($value)) {
            $fields[$key] = ['booleanValue' => (bool)$value];
        } elseif ($value === null) {
            $fields[$key] = ['nullValue' => null];
        } else {
            $fields[$key] = ['stringValue' => (string)$value];
        }
    }
    return $fields;
}

function firestore_build_monthly_collection_name(string $userUid, string $date): string
{
    $meses = ['01'=>'janeiro','02'=>'fevereiro','03'=>'março','04'=>'abril','05'=>'maio','06'=>'junho','07'=>'julho','08'=>'agosto','09'=>'setembro','10'=>'outubro','11'=>'novembro','12'=>'dezembro'];
    $dt = new DateTime($date);
    return ($meses[$dt->format('m')] ?? 'janeiro') . '-' . $dt->format('Y') . $userUid;
}

function firestore_get_user_uid_by_email(string $email): ?string
{
    try {
        $response = firebase_auth_request('POST', 'accounts:lookup', ['email' => [trim(strtolower($email))]]);
        return $response['users'][0]['localId'] ?? null;
    } catch (Throwable $e) { return null; }
}

function firestore_query_remessas(string $email, string $startDate, string $endDate): array
{
    $uid = firestore_get_user_uid_by_email($email);
    if (!$uid) return [];

    $dt = new DateTime($startDate);
    $col = firestore_build_monthly_collection_name($uid, $dt->format('Y-m-d'));
    
    try {
        $resp = firestore_request('GET', 'documents/' . rawurlencode($col) . '?pageSize=1000');
        $results = [];
        foreach (($resp['documents'] ?? []) as $d) {
            $doc = firestore_document_to_array($d);
            $nameParts = explode('/', $d['name']);
            $doc['id'] = end($nameParts);
            $doc['__collection'] = $col;
            
            // Normalização para o Dashboard
            if (empty($doc['data_cadastro'])) {
                $cand = $doc['data'] ?? $doc['data_entrada'] ?? $doc['data_entrega'] ?? null;
                if ($cand) $doc['data_cadastro'] = substr($cand, 0, 10);
            }
            $results[] = $doc;
        }
        usort($results, function($a, $b) { return strcmp($b['data_cadastro'] ?? '', $a['data_cadastro'] ?? ''); });
        return $results;
    } catch (Throwable $e) { return []; }
}

function firestore_get_all_user_remessas(string $email): array
{
    $uid = firestore_get_user_uid_by_email($email);
    if (!$uid) return [];
    
    $all = [];
    try {
        $serviceAccount = firestore_get_credentials();
        $parent = 'projects/' . $serviceAccount['project_id'] . '/databases/(default)/documents';
        $resp = firestore_request('POST', 'documents:listCollectionIds', ['parent' => $parent, 'pageSize' => 500]);
        
        foreach (($resp['collectionIds'] ?? []) as $col) {
            if (str_ends_with($col, $uid)) {
                try {
                    $dresp = firestore_request('GET', 'documents/' . rawurlencode($col) . '?pageSize=1000');
                    foreach (($dresp['documents'] ?? []) as $d) {
                        $doc = firestore_document_to_array($d);
                        $nameParts = explode('/', $d['name']);
            $doc['id'] = end($nameParts);
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
    } catch (Throwable $e) {}
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

    $fields = firestore_build_fields([
        'id' => $docId,
        'usuario_uid' => $uid,
        'usuario_id' => $uid,
        'usuario_email' => $data['usuario_email'],
        'usuario_nome' => $data['usuario_nome'],
        'peca_servico' => $data['peca_servico'],
        'peca' => $data['peca_servico'],
        'preco_unitario' => floatval($data['preco_unitario']),
        'quantidade' => intval($data['quantidade']),
        'qtd' => intval($data['quantidade']),
        'tamanho' => $data['tamanho'],
        'size' => $data['tamanho'],
        'qtd_entregue' => intval($data['qtd_entregue'] ?? 0),
        'entregue' => intval($data['qtd_entregue'] ?? 0),
        'valor_recebido' => floatval($data['valor_recebido'] ?? 0),
        'data_cadastro' => $dataCad,
        'data' => $dataCad,
        'timestamp' => ['__timestamp' => $dataCad],
        'loja_id' => $data['loja_id'] ?? '',
        'loja_nome' => $data['loja_nome'] ?? '',
    ]);

    firestore_request('POST', 'documents/' . rawurlencode($col) . '?documentId=' . $docId, ['fields' => $fields]);
}

function firestore_update_remessa(string $docId, array $data, string $col): void
{
    $fields = [];
    $mask = [];
    $map = [
        'peca_servico' => 'peca',
        'quantidade' => 'qtd',
        'tamanho' => 'size',
        'qtd_entregue' => 'entregue',
        'data_ultima_entrega' => 'data_entrega'
    ];

    foreach ($data as $k => $v) {
        $fields[$k] = $v;
        $mask[] = "updateMask.fieldPaths=" . $k;
        if (isset($map[$k])) {
            $fields[$map[$k]] = $v;
            $mask[] = "updateMask.fieldPaths=" . $map[$k];
        }
        // Se for entrega, adiciona o timestamp
        if ($k === 'data_ultima_entrega' && $v) {
            $fields['entrega_timestamp'] = ['__timestamp' => $v];
            $mask[] = "updateMask.fieldPaths=entrega_timestamp";
        }
    }

    if (empty($fields)) return;
    $url = 'documents/' . rawurlencode($col) . '/' . $docId . '?' . implode('&', $mask);
    firestore_request('PATCH', $url, ['fields' => firestore_build_fields($fields)]);
}

function firestore_delete_document(string $col, string $docId): void
{
    firestore_request('DELETE', 'documents/' . rawurlencode($col) . '/' . $docId);
}

function firestore_update_remessa_entrega(string $docId, int $qtd, ?string $data, string $col, float $valRec): void
{
    firestore_update_remessa($docId, [
        'qtd_entregue' => $qtd,
        'data_ultima_entrega' => $data,
        'valor_recebido' => $valRec
    ], $col);
}

function firestore_upsert_user(array $userData): bool { return true; }
function firestore_sync_user_to_local(PDO $pdo, array $userData): ?array { return null; }

try { $credentials = firestore_get_credentials(); } catch (Throwable $e) { $credentials = null; }
