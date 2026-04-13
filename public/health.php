<?php
require dirname(__DIR__) . '/app/lib/helpers.php';
ensure_storage();
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'ok',
    'time' => date(DATE_ATOM),
    'users' => count(users()),
    'files' => [
        'expenses' => count(all_expenses()),
        'tickets' => count(all_tickets()),
        'navigo' => count(all_navigo()),
        'leaves' => count(all_leaves()),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
