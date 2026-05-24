<?php
require __DIR__ . '/firebase_helper.php';
try {
    $serviceAccount = firestore_get_credentials();
    $parent = 'projects/' . $serviceAccount['project_id'] . '/databases/(default)/documents';
    $res = firestore_request('POST', 'documents:listCollectionIds', ['parent' => $parent, 'pageSize' => 100]);
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
}
