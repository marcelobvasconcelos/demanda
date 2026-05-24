<?php
require __DIR__ . '/firebase_helper.php';
try {
    $resp = firestore_request('GET', 'documents/remessas?pageSize=5');
    echo "LIST RESPONSE:\n";
    echo json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
