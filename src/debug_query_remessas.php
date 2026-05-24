<?php
require __DIR__ . '/firebase_helper.php';
$email = $argv[1] ?? 'eliane1971lima@gmail.com';
$start = $argv[2] ?? '2020-01-01';
$end = $argv[3] ?? date('Y-m-d');
try {
    $res = firestore_query_remessas($email, $start, $end);
    echo "FOUND: " . count($res) . "\n";
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
