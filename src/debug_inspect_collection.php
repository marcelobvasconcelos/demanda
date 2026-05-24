<?php
require __DIR__ . '/firebase_helper.php';
$col = $argv[1] ?? null;
if (!$col) { echo "Usage: php debug_inspect_collection.php <collectionId>\n"; exit(1); }
try {
    $resp = firestore_request('GET', 'documents/' . $col . '?pageSize=1');
    echo json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
