<?php
require dirname(__DIR__) . '/app/lib/rest_work.php';
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
        'rest_work' => count(rest_work_records()),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
