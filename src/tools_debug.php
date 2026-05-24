<?php
require __DIR__ . '/firebase_helper.php';

try {
    $users = firebase_auth_list_users(10);
    echo "=== Firebase Auth Users ===\n";
    foreach ($users as $u) {
        echo ($u['email'] ?? '-') . "\n";
    }

    if (empty($users)) {
        echo "No users found.\n";
        exit(0);
    }

    $email = $users[0]['email'];
    echo "\nTesting remessas for: $email\n";

    $start = '2026-01-01';
    $end = '2026-12-31';
    $remessas = firestore_query_remessas($email, $start, $end);

    echo "\nRemessas result count: " . count($remessas) . "\n";
    echo json_encode($remessas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
