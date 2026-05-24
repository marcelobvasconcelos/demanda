<?php
require __DIR__ . '/firebase_helper.php';
try {
    $creds = firestore_get_credentials();
    echo "PROJECT_ID: " . firestore_get_project_id($creds) . "\n";
    $resp = firestore_request('POST', 'documents:listCollectionIds', ['pageSize' => 200]);
    echo "COLLECTIONS:\n";
    echo json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
