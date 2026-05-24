<?php
require __DIR__ . '/firebase_helper.php';
$email = $argv[1] ?? 'eliane1971lima@gmail.com';
try {
    $u = firebase_auth_user_by_email($email);
    echo "USER:\n" . json_encode($u, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    $cols = firestore_request('POST', 'documents:listCollectionIds', ['pageSize' => 500]);
    echo "COLS:\n" . json_encode($cols, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    if (!empty($u['uid'])) {
        echo "UID: " . $u['uid'] . "\n";
        $found = array_values(array_filter($cols['collectionIds'] ?? [], function($c) use ($u) { return strpos($c, $u['uid']) !== false; }));
        echo "MATCHING COLLECTIONS:\n" . json_encode($found, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
