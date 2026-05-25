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
    $jsonPath = '/etc/secrets/firebase_credenciais.json';

    // 1. PRIMEIRA OPÇÃO: Tenta a variável de ambiente do Render (Super segura e sem erro de permissão)
    if (getenv('FIREBASE_CREDENTIALS_JSON') && trim(getenv('FIREBASE_CREDENTIALS_JSON')) !== '') {
        $firebaseJson = getenv('FIREBASE_CREDENTIALS_JSON');
    } 
    // 2. SEGUNDA OPÇÃO: Tenta os caminhos de arquivo conhecidos
    else {
        $path = getFirestoreCredentialsPath();
        if ($path && file_exists($path) && is_readable($path)) {
            $firebaseJson = file_get_contents($path);
        }
    }

    if (empty($firebaseJson)) {
        throw new RuntimeException('Credenciais do Firebase não encontradas (verifique a variável FIREBASE_CREDENTIALS_JSON ou o arquivo firebase_credenciais.json).');
    }

    $credentials = json_decode($firebaseJson, true);
    if (!is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key']) || empty($credentials['project_id'])) {
        throw new RuntimeException('Arquivo de credenciais do Firebase inválido ou incompleto.');
    }

    return $credentials;
}

function firestore_load_service_account(string $path): array
{
    // Mantido para compatibilidade, mas prefira firestore_get_credentials()
    if (!file_exists($path) || !is_readable($path)) {
        throw new RuntimeException("Arquivo de credenciais não encontrado ou sem permissão de leitura: $path");
    }
    $content = file_get_contents($path);
    $data = json_decode($content, true);
    if (!is_array($data) || empty($data['client_email']) || empty($data['private_key']) || empty($data['project_id'])) {
        throw new RuntimeException('Arquivo de credenciais do Firebase inválido ou incompleto.');
    }
    return $data;
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

    $jwt = firestore_encode_jwt($header, $payload, $serviceAccount['private_key']);
    $response = firestore_http_post('https://oauth2.googleapis.com/token', http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]), ['Content-Type: application/x-www-form-urlencoded']);

    if (empty($response['access_token'])) {
        throw new RuntimeException('Falha ao obter token OAuth do Google: ' . ($response['error_description'] ?? json_encode($response, JSON_UNESCAPED_UNICODE)));
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

function firestore_encode_jwt(array $header, array $payload, string $privateKey): string
{
    $headerEncoded = firestore_base64url_encode(json_encode($header, JSON_UNESCAPED_UNICODE));
    $payloadEncoded = firestore_base64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $signatureInput = $headerEncoded . '.' . $payloadEncoded;

    $signed = openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if (!$signed) {
        throw new RuntimeException('Falha ao assinar JWT com a chave privada do Firebase.');
    }

    return $signatureInput . '.' . firestore_base64url_encode($signature);
}

function firestore_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function firestore_http_post(string $url, string $body, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Accept: application/json'], $headers));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($result === false) {
        throw new RuntimeException('Erro CURL ao acessar ' . $url . ': ' . $error);
    }

    return json_decode($result, true) ?: [];
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));
    }

    $result = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
        throw new RuntimeException('Erro CURL Firestore: ' . $error);
    }

    $decoded = json_decode($result, true);
    if ($decoded === null) {
        throw new RuntimeException('Resposta inválida do Firestore: ' . $result);
    }

    if ($httpCode >= 400) {
        throw new RuntimeException('Erro Firestore ' . $httpCode . ': ' . json_encode($decoded, JSON_UNESCAPED_UNICODE));
    }

    return $decoded;
}

function firebase_auth_request(string $method, string $path, array $body = null): array
{
    $serviceAccount = firestore_get_credentials();
    $token = firebase_get_access_token($serviceAccount, [
        'https://www.googleapis.com/auth/identitytoolkit',
        'https://www.googleapis.com/auth/cloud-platform',
    ]);

    $url = 'https://identitytoolkit.googleapis.com/v1/' . ltrim($path, '/');
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));
    }

    $result = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
        throw new RuntimeException('Erro CURL Firebase Auth: ' . $error);
    }

    $decoded = json_decode($result, true);
    if ($decoded === null) {
        throw new RuntimeException('Resposta inválida do Firebase Auth: ' . $result);
    }

    if ($httpCode >= 400) {
        throw new RuntimeException('Erro Firebase Auth ' . $httpCode . ': ' . json_encode($decoded, JSON_UNESCAPED_UNICODE));
    }

    return $decoded;
}

function firebase_auth_user_by_email(string $email): ?array
{
    $email = trim(strtolower($email));
    if ($email === '') {
        return null;
    }

    $response = firebase_auth_request('POST', 'accounts:lookup', [
        'email' => [$email],
    ]);

    $users = $response['users'] ?? [];
    if (!empty($users) && isset($users[0])) {
        $user = $users[0];
        return [
            'uid' => $user['localId'] ?? null,
            'email' => $user['email'] ?? $email,
            'nome' => $user['displayName'] ?? ($user['email'] ?? 'Usuário'),
            'password' => '',
            'providerUserInfo' => $user['providerUserInfo'] ?? [],
        ];
    }

    return null;
}

function firebase_auth_sign_in_with_password(string $email, string $password): ?array
{
    $email = trim(strtolower($email));
    if ($email === '' || $password === '') {
        return null;
    }

    $response = firebase_auth_request('POST', 'accounts:signInWithPassword', [
        'email' => $email,
        'password' => $password,
        'returnSecureToken' => true,
    ]);

    if (isset($response['localId'])) {
        return $response;
    }

    return null;
}

function firebase_auth_list_users(int $maxResults = 1000): array
{
    $serviceAccount = firestore_get_credentials();
    $projectId = firestore_get_project_id($serviceAccount);
    $path = "projects/{$projectId}/accounts:batchGet?maxResults=" . intval($maxResults);

    $response = firebase_auth_request('GET', $path);
    return $response['users'] ?? [];
}

function firestore_parse_value(array $value)
{
    if (isset($value['stringValue'])) {
        return $value['stringValue'];
    }
    if (isset($value['integerValue'])) {
        return intval($value['integerValue']);
    }
    if (isset($value['doubleValue'])) {
        return floatval($value['doubleValue']);
    }
    if (isset($value['booleanValue'])) {
        return (bool) $value['booleanValue'];
    }
    if (isset($value['timestampValue'])) {
        return $value['timestampValue'];
    }
    if (isset($value['mapValue'])) {
        $fields = $value['mapValue']['fields'] ?? [];
        $result = [];
        foreach ($fields as $key => $inner) {
            $result[$key] = firestore_parse_value($inner);
        }
        return $result;
    }
    if (isset($value['arrayValue']['values'])) {
        return array_map('firestore_parse_value', $value['arrayValue']['values']);
    }
    return null;
}

function firestore_document_to_array(array $document): array
{
    $fields = $document['fields'] ?? [];
    $result = [];
    foreach ($fields as $key => $value) {
        $result[$key] = firestore_parse_value($value);
    }
    return $result;
}

function firestore_build_fields(array $data): array
{
    $fields = [];
    foreach ($data as $key => $value) {
        if (is_int($value)) {
            $fields[$key] = ['integerValue' => $value];
        } elseif (is_float($value)) {
            $fields[$key] = ['doubleValue' => $value];
        } elseif (is_bool($value)) {
            $fields[$key] = ['booleanValue' => $value];
        } elseif ($value === null) {
            $fields[$key] = ['nullValue' => null];
        } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2})?/', $value)) {
            // Se for uma string de data (ISO 8601), converte para Timestamp real do Firestore
            // O Firestore espera o formato: 2024-05-24T15:30:00Z ou 2024-05-24T15:30:00.000Z
            $dt = new DateTime($value);
            $fields[$key] = ['timestampValue' => $dt->format('Y-m-d\TH:i:s\Z')];
        } else {
            $fields[$key] = ['stringValue' => (string) $value];
        }
    }
    return $fields;
}

function firestore_document_id_from_name(string $name): string
{
    $parts = explode('/', $name);
    return end($parts);
}

function firestore_build_monthly_collection_name(string $userUid, string $date): string
{
    $meses = [
        '01' => 'janeiro', '02' => 'fevereiro', '03' => 'março', '04' => 'abril',
        '05' => 'maio', '06' => 'junho', '07' => 'julho', '08' => 'agosto',
        '09' => 'setembro', '10' => 'outubro', '11' => 'novembro', '12' => 'dezembro',
    ];
    $dt = new DateTime($date);
    $mes = $dt->format('m');
    $ano = $dt->format('Y');
    $monthName = $meses[$mes] ?? strtolower($dt->format('F'));
    return $monthName . '-' . $ano . $userUid;
}

function firestore_build_monthly_collection_names_for_range(string $userUid, string $startDate, string $endDate): array
{
    $start = new DateTime(substr($startDate, 0, 7) . '-01');
    $end = new DateTime(substr($endDate, 0, 7) . '-01');
    $collections = [];

    while ($start <= $end) {
        $collections[] = firestore_build_monthly_collection_name($userUid, $start->format('Y-m-d'));
        $start->modify('+1 month');
    }

    return array_values(array_unique($collections));
}

function firestore_collection_belongs_to_user(string $collection, string $userUid): bool
{
    if ($collection === '' || $userUid === '') {
        return false;
    }

    return str_ends_with($collection, $userUid);
}

function firestore_get_user_uid_by_email(string $email): ?string
{
    $user = firebase_auth_user_by_email($email);
    return $user['uid'] ?? null;
}

function firestore_query_remessas(string $usuarioEmail, string $startDate, string $endDate): array
{
    $usuarioEmail = trim(strtolower($usuarioEmail));
    if ($usuarioEmail === '') {
        return [];
    }

    $response = [];
    $userUid = firestore_get_user_uid_by_email($usuarioEmail);
    if (!$userUid) {
        return [];
    }

    $userCollections = firestore_build_monthly_collection_names_for_range(
        $userUid,
        $startDate,
        $endDate
    );

    foreach ($userCollections as $col) {
        try {
            $docs = firestore_request('GET', 'documents/' . rawurlencode($col) . '?pageSize=1000');
            foreach (($docs['documents'] ?? []) as $d) {
                $doc = firestore_document_to_array($d);
                $docUid = $doc['usuario_uid'] ?? null;
                $docEmail = isset($doc['usuario_email']) ? strtolower(trim($doc['usuario_email'])) : null;

                if (($docUid && $docUid !== $userUid) || ($docEmail && $docEmail !== $usuarioEmail)) {
                    continue;
                }

                $response[] = ['document' => $d, 'collection' => $col];
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    // Debug: salvar resposta bruta do Firestore (temporário)
    try {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        @file_put_contents($logDir . '/firestore_query_raw.json', json_encode(['ts' => date('c'), 'email' => $usuarioEmail, 'start' => $startDate, 'end' => $endDate, 'response' => $response], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
    } catch (Throwable $e) {}

    $results = [];
    $seenDocuments = [];
    foreach ($response as $item) {
        if (!empty($item['document'])) {
            $doc = firestore_document_to_array($item['document']);
            $doc['id'] = firestore_document_id_from_name($item['document']['name'] ?? '');
            $doc['__collection'] = $item['collection'] ?? null;
            $docKey = ($doc['__collection'] ?? '') . '/' . ($doc['id'] ?? '');
            if (isset($seenDocuments[$docKey])) {
                continue;
            }
            $seenDocuments[$docKey] = true;
            $results[] = $doc;
        }
    }

    // Normalizar campos comuns entre diferentes formatos de documento
    foreach ($results as &$r) {
        // data_cadastro: aceita 'data', 'data_cadastro', 'data_entrada', 'data_entrega'
        if (empty($r['data_cadastro'])) {
            // Priorizamos 'data' e 'data_entrada' (registro) sobre 'data_entrega' (conclusão)
            $candidates = [$r['data'] ?? null, $r['data_entrada'] ?? null, $r['data_entrega'] ?? null, $r['data_ultima_entrega'] ?? null];
            foreach ($candidates as $cand) {
                if (is_string($cand) && $cand !== '') {
                    // Se tiver 'T', pega só a data. Se não tiver, assume que já é a data ou tenta extrair
                    $r['data_cadastro'] = (strpos($cand, 'T') !== false) ? substr($cand, 0, 10) : substr($cand, 0, 10);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $r['data_cadastro'])) {
                        break;
                    }
                }
            }
        }

        // quantidade
        if (empty($r['quantidade']) && isset($r['qtd'])) $r['quantidade'] = intval($r['qtd']);
        if (empty($r['quantidade']) && isset($r['quantidade'])) $r['quantidade'] = intval($r['quantidade']);

        // qtd_entregue
        if (!isset($r['qtd_entregue'])) {
            if (isset($r['entregue']) && is_bool($r['entregue'])) $r['qtd_entregue'] = $r['entregue'] ? intval($r['quantidade'] ?? 0) : 0;
            elseif (isset($r['entregue'])) $r['qtd_entregue'] = intval($r['entregue']);
            elseif (isset($r['qtd'])) $r['qtd_entregue'] = intval($r['qtd']);
            else $r['qtd_entregue'] = 0;
        }

        $r['quantidade'] = max(0, intval($r['quantidade'] ?? 0));
        $r['qtd_entregue'] = max(0, min(intval($r['qtd_entregue'] ?? 0), $r['quantidade']));

        if (empty($r['data_ultima_entrega']) && !empty($r['data_entrega'])) {
            $r['data_ultima_entrega'] = is_string($r['data_entrega']) && strpos($r['data_entrega'], 'T') !== false
                ? substr($r['data_entrega'], 0, 10)
                : $r['data_entrega'];
        }

        // preco_unitario
        if (!isset($r['preco_unitario'])) {
            if (isset($r['precoU'])) $r['preco_unitario'] = floatval($r['precoU']);
            elseif (isset($r['preco'])) $r['preco_unitario'] = floatval($r['preco']);
            elseif (isset($r['total']) && isset($r['quantidade']) && intval($r['quantidade'])>0) $r['preco_unitario'] = floatval($r['total']) / intval($r['quantidade']);
            else $r['preco_unitario'] = 0.0;
        }

        // pecas/tamanho
        if (empty($r['peca_servico']) && !empty($r['peca'])) $r['peca_servico'] = $r['peca'];
        if (empty($r['tamanho']) && !empty($r['size'])) $r['tamanho'] = $r['size'];

        // valor_recebido
        $r['valor_recebido'] = isset($r['valor_recebido']) ? floatval($r['valor_recebido']) : 0.0;
    }
    unset($r);

    try {
        @file_put_contents(__DIR__ . '/logs/firestore_query_docs.json', json_encode(['ts' => date('c'), 'docs' => $results], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
    } catch (Throwable $e) {}

    // Ordenar por data desc (mesmo que a data seja de outro mês, mantemos na lista do mês da coleção)
    usort($results, function ($a, $b) {
        return strcmp($b['data_cadastro'] ?? '', $a['data_cadastro'] ?? '');
    });

    try {
        @file_put_contents(__DIR__ . '/logs/firestore_query_filtered.json', json_encode(['ts' => date('c'), 'results_count' => count($results), 'results' => array_values($results)], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
    } catch (Throwable $e) {}

    return array_values($results);
}

function firestore_add_remessa(array $data): array
{
    $docId = bin2hex(random_bytes(8));
    $usuarioUid = $data['usuario_uid'] ?? null;
    $dataCadastro = $data['data_cadastro'] ?? date('Y-m-d');
    if (!$usuarioUid) {
        $usuarioUid = firestore_get_user_uid_by_email(strtolower(trim($data['usuario_email'] ?? '')));
    }

    if ($usuarioUid) {
        $collection = firestore_build_monthly_collection_name($usuarioUid, $dataCadastro);
    } else {
        $collection = 'remessas';
    }

    $fields = firestore_build_fields([
        'usuario_uid' => $usuarioUid,
        'usuario_email' => strtolower(trim($data['usuario_email'] ?? '')),
        'usuario_nome' => $data['usuario_nome'] ?? '',
        'peca_servico' => $data['peca_servico'] ?? '',
        'peca' => $data['peca_servico'] ?? '', // Compatibilidade
        'preco_unitario' => isset($data['preco_unitario']) ? floatval($data['preco_unitario']) : 0.0,
        'quantidade' => isset($data['quantidade']) ? intval($data['quantidade']) : 1,
        'qtd' => isset($data['quantidade']) ? intval($data['quantidade']) : 1, // Compatibilidade (Total)
        'tamanho' => $data['tamanho'] ?? 'outro',
        'size' => $data['tamanho'] ?? 'outro', // Compatibilidade
        'qtd_entregue' => isset($data['qtd_entregue']) ? intval($data['qtd_entregue']) : 0,
        'entregue' => isset($data['qtd_entregue']) ? intval($data['qtd_entregue']) : 0, // Compatibilidade
        'valor_recebido' => isset($data['valor_recebido']) ? floatval($data['valor_recebido']) : 0.0,
        'data_cadastro' => $dataCadastro,
        'data' => $dataCadastro, // Compatibilidade
        'data_ultima_entrega' => $data['data_ultima_entrega'] ?? null,
        'data_entrega' => $data['data_ultima_entrega'] ?? null, // Compatibilidade
    ]);

    firestore_request('POST', 'documents/' . rawurlencode($collection) . '?documentId=' . rawurlencode($docId), ['fields' => $fields]);
    return ['id' => $docId, '__collection' => $collection] + $data;
}

function firestore_update_remessa(string $docId, array $data, ?string $collection = null): bool
{
    if (!$collection) {
        $collection = 'remessas';
    }

    $updateFields = [];
    $mask = [];

    if (isset($data['peca_servico'])) {
        $updateFields['peca_servico'] = $data['peca_servico'];
        $updateFields['peca'] = $data['peca_servico']; // Compatibilidade
        $mask[] = 'updateMask.fieldPaths=peca_servico';
        $mask[] = 'updateMask.fieldPaths=peca';
    }
    if (isset($data['preco_unitario'])) {
        $updateFields['preco_unitario'] = floatval($data['preco_unitario']);
        $mask[] = 'updateMask.fieldPaths=preco_unitario';
    }
    if (isset($data['quantidade'])) {
        $updateFields['quantidade'] = intval($data['quantidade']);
        $updateFields['qtd'] = intval($data['quantidade']); // Compatibilidade (Total)
        $mask[] = 'updateMask.fieldPaths=quantidade';
        $mask[] = 'updateMask.fieldPaths=qtd';
    }
    if (isset($data['tamanho'])) {
        $updateFields['tamanho'] = $data['tamanho'];
        $updateFields['size'] = $data['tamanho']; // Compatibilidade
        $mask[] = 'updateMask.fieldPaths=tamanho';
        $mask[] = 'updateMask.fieldPaths=size';
    }
    if (isset($data['qtd_entregue'])) {
        $updateFields['qtd_entregue'] = intval($data['qtd_entregue']);
        $updateFields['entregue'] = intval($data['qtd_entregue']); // Compatibilidade
        $mask[] = 'updateMask.fieldPaths=qtd_entregue';
        $mask[] = 'updateMask.fieldPaths=entregue';
    }
    if (isset($data['valor_recebido'])) {
        $updateFields['valor_recebido'] = floatval($data['valor_recebido']);
        $mask[] = 'updateMask.fieldPaths=valor_recebido';
    }
    if (array_key_exists('data_ultima_entrega', $data)) {
        $updateFields['data_ultima_entrega'] = $data['data_ultima_entrega'];
        $updateFields['data_entrega'] = $data['data_ultima_entrega']; // Compatibilidade
        $mask[] = 'updateMask.fieldPaths=data_ultima_entrega';
        $mask[] = 'updateMask.fieldPaths=data_entrega';
    }

    if (empty($updateFields)) {
        return true;
    }

    $fields = firestore_build_fields($updateFields);
    $maskStr = implode('&', $mask);

    firestore_request('PATCH', 'documents/' . rawurlencode($collection) . '/' . rawurlencode($docId) . '?' . $maskStr, ['fields' => $fields]);
    return true;
}

function firestore_update_remessa_entrega(string $docId, int $qtdEntregue, ?string $dataUltimaEntrega, ?string $collection = null, float $valorRecebido = null): bool
{
    $data = [
        'qtd_entregue' => $qtdEntregue,
        'data_ultima_entrega' => $dataUltimaEntrega
    ];
    if ($valorRecebido !== null) {
        $data['valor_recebido'] = $valorRecebido;
    }
    return firestore_update_remessa($docId, $data, $collection);
}

function firestore_get_all_user_remessas(string $usuarioEmail): array
{
    $usuarioEmail = trim(strtolower($usuarioEmail));
    if ($usuarioEmail === '') {
        return [];
    }

    $userUid = firestore_get_user_uid_by_email($usuarioEmail);
    if (!$userUid) {
        return [];
    }

    $allRemessas = [];
    $serviceAccount = firestore_get_credentials();
    $parent = 'projects/' . $serviceAccount['project_id'] . '/databases/(default)/documents';
    
    try {
        // Lista todas as coleções do banco
        $resp = firestore_request('POST', 'documents:listCollectionIds', ['parent' => $parent, 'pageSize' => 500]);
        $collections = $resp['collectionIds'] ?? [];
        
        foreach ($collections as $col) {
            // Filtra coleções que pertencem a este usuário (padrão mes-anoUID)
            if (str_ends_with($col, $userUid)) {
                try {
                    $docs = firestore_request('GET', 'documents/' . rawurlencode($col) . '?pageSize=1000');
                    foreach (($docs['documents'] ?? []) as $d) {
                        $doc = firestore_document_to_array($d);
                        $docUid = $doc['usuario_uid'] ?? null;
                        $docEmail = isset($doc['usuario_email']) ? strtolower(trim($doc['usuario_email'])) : null;

                        if (($docUid && $docUid !== $userUid) || ($docEmail && $docEmail !== $usuarioEmail)) {
                            continue;
                        }

                        $doc['id'] = firestore_document_id_from_name($d['name'] ?? '');
                        $doc['__collection'] = $col;
                        
                        // Normalização básica para listagem
                        if (empty($doc['data_cadastro'])) {
                            $candidates = [$doc['data'] ?? null, $doc['data_entrada'] ?? null, $doc['data_entrega'] ?? null];
                            foreach ($candidates as $cand) {
                                if (is_string($cand) && $cand !== '') {
                                    $doc['data_cadastro'] = substr($cand, 0, 10);
                                    break;
                                }
                            }
                        }
                        
                        $allRemessas[] = $doc;
                    }
                } catch (Throwable $e) { continue; }
            }
        }
    } catch (Throwable $e) { return []; }

    // Ordenar por data desc
    usort($allRemessas, function ($a, $b) {
        return strcmp($b['data_cadastro'] ?? '', $a['data_cadastro'] ?? '');
    });

    return $allRemessas;
}

function firestore_delete_document(string $collection, string $docId): bool
{
    firestore_request('DELETE', 'documents/' . rawurlencode($collection) . '/' . rawurlencode($docId));
    return true;
}

function firestore_query_user_by_email(string $email): ?array
{
    $collections = ['usuarios', 'users', 'costureiras', 'profiles'];
    $email = trim(strtolower($email));
    foreach ($collections as $collection) {
        try {
            $response = firestore_request('POST', 'documents:runQuery', [
                'structuredQuery' => [
                    'from' => [['collectionId' => $collection]],
                    'where' => [
                        'fieldFilter' => [
                            'field' => ['fieldPath' => 'email'],
                            'op' => 'EQUAL',
                            'value' => ['stringValue' => $email],
                        ],
                    ],
                    'limit' => 1,
                ],
            ]);
        } catch (Throwable $e) {
            continue;
        }

        foreach ($response as $item) {
            if (isset($item['document'])) {
                $doc = firestore_document_to_array($item['document']);
                $doc['__firestore_collection'] = $collection;
                $doc['__firestore_name'] = $item['document']['name'] ?? null;
                return $doc;
            }
        }
    }
    return null;
}

function firestore_upsert_user(array $userData): bool
{
    $collection = 'usuarios';
    $docId = md5(strtolower(trim($userData['email'])));

    $fields = firestore_build_fields([
        'nome' => $userData['nome'],
        'email' => strtolower(trim($userData['email'])),
        'senha' => $userData['senha'],
    ]);

    try {
        firestore_request('POST', "documents/{$collection}?documentId={$docId}", ['fields' => $fields]);
        return true;
    } catch (Throwable $e) {
        // Se já existir, atualiza com PATCH
        if (strpos($e->getMessage(), 'already exists') !== false || strpos($e->getMessage(), '409') !== false) {
            try {
                firestore_request('PATCH', "documents/{$collection}/{$docId}?updateMask.fieldPaths=nome&updateMask.fieldPaths=email&updateMask.fieldPaths=senha", ['fields' => $fields]);
                return true;
            } catch (Throwable $inner) {
                return false;
            }
        }
        return false;
    }
}

function firestore_sync_user_to_local(PDO $pdo, array $userData): ?array
{
    $email = strtolower(trim($userData['email'] ?? ''));
    if (empty($email)) {
        return null;
    }

    $nome = $userData['nome'] ?? ($userData['name'] ?? 'Usuário');
    $senha = $userData['senha'] ?? ($userData['password'] ?? '');

    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($senha === '') {
            $senha = $existing['senha'];
        }
        $stmt = $pdo->prepare('UPDATE usuarios SET nome = ?, senha = ? WHERE id = ?');
        $stmt->execute([$nome, $senha, $existing['id']]);
        $existing['nome'] = $nome;
        $existing['senha'] = $senha;
        return $existing;
    }

    $stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, senha) VALUES (?, ?, ?)');
    $stmt->execute([$nome, $email, $senha]);
    return ['id' => $pdo->lastInsertId(), 'nome' => $nome, 'email' => $email, 'senha' => $senha];
}

// Inicializa credenciais globais para compatibilidade
try {
    $credentials = firestore_get_credentials();
} catch (Throwable $e) {
    $credentials = null;
}