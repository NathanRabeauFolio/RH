<?php
require dirname(__DIR__) . '/app/lib/helpers.php';
ensure_storage();
$employee = require_login('employee');
try {
    save_ticket($employee);
    flash_set('success', 'Déclaration enregistrée.');
} catch (Throwable $e) {
    flash_set('error', $e->getMessage());
}
redirect('index.php');
