<?php
require dirname(__DIR__) . '/app/lib/helpers.php';
ensure_storage();

$user = require_login();
$type = (string) ($_GET['type'] ?? '');
$id = (string) ($_GET['id'] ?? '');
if (!in_array($type, ['expenses', 'tickets', 'navigo', 'leaves'], true) || $id === '') {
    redirect(($user['role'] ?? '') === 'admin' ? 'admin.php' : 'index.php');
}
try {
    $record = find_record($type, $id);
    if (!$record) {
        throw new RuntimeException('Élément introuvable.');
    }
    assert_can_access_record($user, $type, $record);
    delete_record($type, $id);
    flash_set('success', 'Élément supprimé.');
} catch (Throwable $e) {
    flash_set('error', $e->getMessage());
}
redirect(($user['role'] ?? '') === 'admin' ? 'admin.php' : 'index.php');
