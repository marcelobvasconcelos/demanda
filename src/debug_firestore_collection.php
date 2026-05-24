<?php
require __DIR__ . '/firebase_helper.php';

if ($argc < 2) {
    echo "Usage: php debug_firestore_collection.php <collectionId>\n";
    exit(1);
}

$collection = $argv[1];
try {
    $res = firestore_request('GET', 'documents/' . $collection);
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
}
