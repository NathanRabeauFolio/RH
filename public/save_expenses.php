<?php
require dirname(__DIR__) . '/app/lib/helpers.php';
ensure_storage();
$employee = require_login('employee');
try {
    $count = save_expenses_batch($employee);
    flash_set('success', $count . ' note(s) enregistrée(s).');
} catch (Throwable $e) {
    flash_set('error', $e->getMessage());
}
redirect('index.php');
